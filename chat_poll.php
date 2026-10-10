<?php
require_once __DIR__ . '/includes/bootstrap.php';
header('Content-Type: application/json');
$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['messages' => []]); exit; }

$st = getDb()->prepare('SELECT * FROM chat_messages WHERE user_id = ? AND id > ? ORDER BY id ASC');
$st->execute([(int)$user['id'], (int)getStr('since', '0')]);
echo json_encode(['messages' => array_map(fn($m) => [
    'id' => (int)$m['id'], 'message' => $m['message'], 'from_support' => (int)$m['from_support'],
    'time' => date('H:i', strtotime($m['created_at'])),
], $st->fetchAll())]);
