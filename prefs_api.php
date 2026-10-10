<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
$user = currentUser();
if (!$user || !isPost()) { http_response_code(403); echo json_encode(['ok' => false]); exit; }
requireCsrf(true);

$theme = postStr('theme');
if (in_array($theme, ['dark', 'light'], true)) {
    $st = getDb()->prepare('UPDATE users SET theme = ? WHERE id = ?');
    $st->execute([$theme, (int)$user['id']]);
    setThemeCookie($theme);
}
echo json_encode(['ok' => true]);
