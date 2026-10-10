<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/google_sso.php';
header('Content-Type: application/json');

if (!isPost()) { http_response_code(405); echo json_encode(['ok' => false]); exit; }
requireCsrf(true);

$raw = json_decode(file_get_contents('php://input') ?: '', true);
$token = is_array($raw) ? (string)($raw['access_token'] ?? '') : '';
$remember = is_array($raw) && !empty($raw['remember']);

$claims = googleVerifyAccessToken($token);
if (!$claims) {
    echo json_encode(['ok' => false, 'error' => t('toast_google_no_email')]);
    exit;
}

$user = googleFindOrCreateUser($claims['email'], $claims['first'], $claims['last']);
if ((int)$user['active'] !== 1) {
    echo json_encode(['ok' => false, 'error' => t('toast_account_deactivated')]);
    exit;
}
loginUser($user, $remember);
flash('success', t('toast_signed_in_as', $user['email']));
echo json_encode(['ok' => true, 'redirect' => 'dashboard.php']);
