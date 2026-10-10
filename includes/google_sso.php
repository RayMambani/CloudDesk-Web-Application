<?php
// Google single sign-on (Sign in with Google), web version.
//
// The browser asks Google for an access token with prompt=select_account, so the
// account chooser is shown EVERY time. This server then verifies the token with Google
// (it must have been issued to OUR client ID) and reads the verified email address.

function googleConfigured(): bool {
    return GOOGLE_CLIENT_ID !== '' && !str_starts_with(GOOGLE_CLIENT_ID, 'YOUR_WEB_CLIENT_ID');
}

/** Pure check on Google's tokeninfo response (unit-testable without the network). */
function googleTokenInfoValid(array $info, string $clientId): bool {
    $aud = $info['aud'] ?? ($info['azp'] ?? '');
    if (!hash_equals($clientId, (string)$aud)) return false;
    if (empty($info['email'])) return false;
    $verified = $info['email_verified'] ?? false;
    if ($verified !== true && $verified !== 'true') return false;
    if (isset($info['expires_in']) && (int)$info['expires_in'] <= 0) return false;
    return true;
}

function googleHttpGet(string $url, array $headers = []): ?array {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPHEADER => $headers];
    if (CURL_CA_BUNDLE !== '' && file_exists(CURL_CA_BUNDLE)) $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err || $code !== 200 || !$body) {
        error_log("Google SSO request failed (HTTP $code): $err");
        return null;
    }
    $data = json_decode($body, true);
    return is_array($data) ? $data : null;
}

/** @return array{email:string, first:string, last:string}|null */
function googleVerifyAccessToken(string $accessToken): ?array {
    if (!googleConfigured() || $accessToken === '') return null;
    $info = googleHttpGet('https://oauth2.googleapis.com/tokeninfo?access_token=' . rawurlencode($accessToken));
    if (!$info || !googleTokenInfoValid($info, GOOGLE_CLIENT_ID)) return null;

    $profile = googleHttpGet('https://www.googleapis.com/oauth2/v3/userinfo', ['Authorization: Bearer ' . $accessToken]) ?? [];
    return [
        'email' => strtolower((string)$info['email']),
        'first' => (string)($profile['given_name'] ?? 'New'),
        'last' => (string)($profile['family_name'] ?? 'User'),
    ];
}

/** Find the account for a verified Google email, creating a standard user the first time. */
function googleFindOrCreateUser(string $email, string $first, string $last): array {
    $db = getDb();
    $user = findUserByEmail($email);
    if ($user) return $user;

    $id = insertAndGetId(
        $db,
        'INSERT INTO users (first_name, last_name, email, password, department, role, created_at) VALUES (?,?,?,?,?,?,?)',
        [$first ?: 'New', $last ?: 'User', $email, hashPassword(bin2hex(random_bytes(24))), 'General', 'user', nowStr()]
    );
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch();
}
