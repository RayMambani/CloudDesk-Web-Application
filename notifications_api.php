<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false]); exit; }

$db = getDb();
$uid = (int)$user['id'];
$latest = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 1');
$latest->execute([$uid]);
$row = $latest->fetch();

echo json_encode([
    'ok' => true,
    'unread' => unreadNotificationCount($db, $uid),
    'latest_id' => $row ? (int)$row['id'] : 0,
    'latest' => $row ? ['title' => notificationTitle($row['title']), 'body' => $row['body']] : null,
]);
