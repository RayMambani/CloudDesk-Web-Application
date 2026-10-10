<?php
// JSON API for passkeys (fingerprint / screen-lock sign-in).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/webauthn.php';
header('Content-Type: application/json');

function pkFail(string $msg, int $code = 400): never {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $msg]);
    exit;
}

if (!isPost()) pkFail('POST only', 405);
requireCsrf(true);

$in = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($in)) pkFail('Bad request');
$action = (string)($in['action'] ?? '');
$db = getDb();

try {
    switch ($action) {
        // ---- Sign in (no session yet) ----
        case 'login_options':
            $challenge = b64u_enc(random_bytes(32));
            $_SESSION['pk_login_challenge'] = $challenge;
            echo json_encode(['ok' => true, 'options' => ['challenge' => $challenge, 'rpId' => webauthnRpId()]]);
            break;

        case 'login_verify':
            $challenge = $_SESSION['pk_login_challenge'] ?? '';
            unset($_SESSION['pk_login_challenge']);
            if ($challenge === '') pkFail('Start again.');

            $credId = (string)($in['id'] ?? '');
            $st = $db->prepare('SELECT * FROM passkeys WHERE credential_id = ?');
            $st->execute([$credId]);
            $pk = $st->fetch();
            if (!$pk) pkFail(t('toast_biometric_unavailable'));

            $newCount = webauthnVerifyAssertion($in, $pk['public_key'], (int)$pk['sign_count'], $challenge);

            $u = $db->prepare('SELECT * FROM users WHERE id = ?');
            $u->execute([(int)$pk['user_id']]);
            $user = $u->fetch();
            if (!$user) pkFail(t('toast_account_not_found'));
            if ((int)$user['active'] !== 1) pkFail(t('toast_account_deactivated'));

            $db->prepare('UPDATE passkeys SET sign_count = ? WHERE id = ?')->execute([$newCount, (int)$pk['id']]);
            loginUser($user, true);
            flash('success', t('toast_signed_in_as', $user['email']));
            echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
            break;

        // ---- Register (must be signed in; enabled from Settings) ----
        case 'reg_options':
            $user = currentUser() ?: pkFail('Not signed in', 401);
            $challenge = b64u_enc(random_bytes(32));
            $_SESSION['pk_reg_challenge'] = $challenge;
            $ex = $db->prepare('SELECT credential_id FROM passkeys WHERE user_id = ?');
            $ex->execute([(int)$user['id']]);
            echo json_encode(['ok' => true, 'options' => [
                'challenge' => $challenge,
                'rpId' => webauthnRpId(),
                'rpName' => APP_NAME,
                'userId' => b64u_enc('u' . $user['id']),
                'userName' => $user['email'],
                'displayName' => fullName($user),
                'exclude' => array_column($ex->fetchAll(), 'credential_id'),
            ]]);
            break;

        case 'reg_verify':
            $user = currentUser() ?: pkFail('Not signed in', 401);
            $challenge = $_SESSION['pk_reg_challenge'] ?? '';
            unset($_SESSION['pk_reg_challenge']);
            if ($challenge === '') pkFail('Start again.');

            $res = webauthnVerifyRegistration((string)($in['clientDataJSON'] ?? ''), (string)($in['attestationObject'] ?? ''), $challenge);
            $label = mb_substr(trim((string)($in['label'] ?? '')) ?: 'This device', 0, 100);
            $db->prepare('INSERT INTO passkeys (user_id, credential_id, public_key, sign_count, label, created_at) VALUES (?,?,?,?,?,?)')
               ->execute([(int)$user['id'], b64u_enc($res['credId']), $res['pem'], $res['signCount'], $label, nowStr()]);
            echo json_encode(['ok' => true, 'message' => t('toast_biometric_enabled')]);
            break;

        default:
            pkFail('Unknown action');
    }
} catch (PDOException $e) {
    error_log('passkey_api DB error: ' . $e->getMessage());
    pkFail('Could not save the passkey (it may already be registered).', 500);
} catch (RuntimeException $e) {
    pkFail($e->getMessage());
}
