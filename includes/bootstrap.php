<?php
// Every page starts with: require_once __DIR__ . '/includes/bootstrap.php';
// Loads config, database, session, translations, auth and all helpers.

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/db.php';

// ---- Session (HttpOnly, SameSite=Lax; "Remember me" extends it) -------------
if (session_status() === PHP_SESSION_NONE && !defined('CD_API')) {
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', (string)(SESSION_REMEMBER_DAYS * 86400));
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once __DIR__ . '/security_questions.php';
require_once __DIR__ . '/lang.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/ai_priority.php';
require_once __DIR__ . '/sla.php';
require_once __DIR__ . '/history.php';
require_once __DIR__ . '/notify.php';

// ---- Small helpers ----------------------------------------------------------

/** Escape for HTML output. */
function h($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function nowStr(): string { return date('Y-m-d H:i:s'); }

function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function isPost(): bool { return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST'; }

function postStr(string $key, string $default = ''): string {
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

function getStr(string $key, string $default = ''): string {
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $default;
}

// ---- CSRF -------------------------------------------------------------------

function csrfToken(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf" value="' . h(csrfToken()) . '">';
}

/** Call at the top of any POST handler. Stops the request if the token is wrong. */
function requireCsrf(bool $json = false): void {
    $sent = $_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($sent) || !hash_equals(csrfToken(), $sent)) {
        http_response_code(419);
        if ($json) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Session expired. Reload the page and try again.']);
        } else {
            echo 'Your session expired or the form was invalid. Please go back, reload the page and try again.';
        }
        exit;
    }
}

// ---- Flash messages ---------------------------------------------------------

function flash(string $type, string $message): void {
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function takeFlashes(): array {
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

// ---- Ticket vocabulary (stored as English keys, shown translated) ------------

const STATUS_KEYS = ['open', 'progress', 'resolved', 'closed'];
const PRIORITY_KEYS = ['low', 'medium', 'high', 'critical'];
const CATEGORY_KEYS = ['hardware', 'software', 'network', 'account/access', 'other'];
const ROLE_KEYS = ['user', 'technician', 'admin'];

function statusLabel(string $key): string {
    return in_array($key, STATUS_KEYS, true) ? t('status_' . $key) : $key;
}

function priorityLabel(string $key): string {
    $key = strtolower($key);
    return in_array($key, PRIORITY_KEYS, true) ? t('priority_' . $key) : $key;
}

function categoryLabel(string $key): string {
    $k = strtolower($key);
    if (in_array($k, CATEGORY_KEYS, true)) return t('category_' . str_replace('/', '_', $k));
    return $key;
}

function roleLabel(string $key): string {
    return match ($key) {
        'admin' => t('role_admin'),
        'technician' => t('role_technician'),
        default => t('role_user'),
    };
}

function isStaff(array $user): bool {
    return in_array($user['role'], ['admin', 'technician'], true);
}

function isResolvedStatus(string $status): bool {
    return in_array($status, ['resolved', 'closed'], true);
}

function formatHours(?float $hours): string {
    if ($hours === null) return t('time_not_available');
    return $hours < 24 ? t('time_hours_format', $hours) : t('time_days_format', $hours / 24);
}

function formatDateTime(?string $value): string {
    if (!$value) return '';
    $ts = strtotime($value);
    return $ts ? date('Y-m-d H:i', $ts) : $value;
}

/** Ids never contain anything unusual, but always validate before using one in a path. */
function cleanTicketId(string $id): string {
    return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id) ? $id : '';
}

// Connect now so a brand-new install creates its database, tables and demo data on the
// very first visit (and a connection problem is reported clearly right away).
getDb();
