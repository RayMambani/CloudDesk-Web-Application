<?php
// Session + auth helpers. Every protected page starts with requireLogin().
// The user row is re-read from the database on every request, so a role change or a
// deactivation by an administrator takes effect immediately.

function currentUser(): ?array {
    static $cached = false;
    if ($cached !== false) return $cached;
    $cached = null;

    $uid = $_SESSION['uid'] ?? null;
    if (!$uid) return null;

    $st = getDb()->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$uid]);
    $row = $st->fetch();
    if (!$row || (int)$row['active'] !== 1) {
        unset($_SESSION['uid']);
        // Tell someone who is logged in when an admin deactivates them, instead of
        // silently dropping them on the login page.
        if ($row) flash('error', t('toast_account_deactivated'));
        return null;
    }
    return $cached = $row;
}

function requireLogin(): array {
    $user = currentUser();
    if (!$user) redirect('login.php');
    return $user;
}

function requireRole(array $allowedRoles): array {
    $user = requireLogin();
    if (!in_array($user['role'], $allowedRoles, true)) {
        http_response_code(403);
        die(h(t('toast_admin_only')));
    }
    return $user;
}

function loginUser(array $user, bool $remember = false): void {
    session_regenerate_id(true);
    $_SESSION['uid'] = (int)$user['id'];
    unset($_SESSION['csrf']);
    $_SESSION['language'] = $user['language'] ?: 'en';
    setLanguage($_SESSION['language']);
    setThemeCookie($user['theme'] ?: 'dark');

    if ($remember && !headers_sent()) {
        // Keep the session cookie for N days instead of until the browser closes.
        setcookie(session_name(), session_id(), [
            'expires' => time() + SESSION_REMEMBER_DAYS * 86400,
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

function logoutUser(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'] ?? '', $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function fullName(array $user): string {
    return trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''));
}

function findUserByEmail(string $email): ?array {
    $st = getDb()->prepare('SELECT * FROM users WHERE lower(email) = lower(?) LIMIT 1');
    $st->execute([trim($email)]);
    return $st->fetch() ?: null;
}

// ---- Theme (dark is the default, like the Android app) ------------------------

function setThemeCookie(string $theme): void {
    if (!in_array($theme, ['dark', 'light'], true)) $theme = 'dark';
    if (!headers_sent()) {
        setcookie('cd_theme', $theme, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    }
    $_COOKIE['cd_theme'] = $theme;
}

function currentTheme(): string {
    $user = currentUser();
    $t = $user['theme'] ?? ($_COOKIE['cd_theme'] ?? 'dark');
    return in_array($t, ['dark', 'light'], true) ? $t : 'dark';
}

// ---- Login throttling: 5 wrong attempts => 60 second lock (per browser session + email) ----

function throttleKey(string $scope, string $id): string {
    return $scope . ':' . strtolower($id);
}

function throttleBlockedSeconds(string $scope, string $id): int {
    $rec = $_SESSION['throttle'][throttleKey($scope, $id)] ?? null;
    if (!$rec) return 0;
    if (($rec['until'] ?? 0) > time()) return $rec['until'] - time();
    return 0;
}

function throttleFail(string $scope, string $id): void {
    $k = throttleKey($scope, $id);
    $rec = $_SESSION['throttle'][$k] ?? ['count' => 0, 'until' => 0];
    $rec['count']++;
    if ($rec['count'] >= 5) {
        $rec['until'] = time() + 60;
        $rec['count'] = 0;
    }
    $_SESSION['throttle'][$k] = $rec;
}

function throttleClear(string $scope, string $id): void {
    unset($_SESSION['throttle'][throttleKey($scope, $id)]);
}

/** Password rules used everywhere a new password is chosen. Returns an error string or null. */
function passwordProblem(string $password): ?string {
    if (strlen($password) < 8) return 'Password must be at least 8 characters.';
    return null;
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT);
}