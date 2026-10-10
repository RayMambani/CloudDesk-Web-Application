<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gemini_client.php';
header('Content-Type: application/json');

$user = currentUser();
if (!$user) { http_response_code(401); echo json_encode(['ok' => false]); exit; }
if (!isPost()) { http_response_code(405); echo json_encode(['ok' => false]); exit; }
requireCsrf(true);

$db = getDb();
$text = mb_substr(postStr('message'), 0, 1000);
if ($text === '') { echo json_encode(['ok' => false, 'error' => 'empty']); exit; }

$hist = $db->prepare('SELECT message, from_support FROM chat_messages WHERE user_id = ? ORDER BY id ASC');
$hist->execute([(int)$user['id']]);
$history = $hist->fetchAll();

$userMsgId = insertAndGetId($db,
    'INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,0,?)',
    [(int)$user['id'], fullName($user), $text, nowStr()]);

/** Built-in default replies (keyword based). Regular users ALWAYS get these. */
function defaultSupportReply(string $userText): string {
    $x = mb_strtolower($userText);
    $has = fn(string ...$w) => array_reduce($w, fn($c, $s) => $c || str_contains($x, $s), false);
    if ($has('password', 'log in', 'login')) return t('chat_reply_login');
    if ($has('urgent', 'asap', 'critical', 'down')) return t('chat_reply_urgent');
    if ($has('ticket') && $has('status', 'update')) return t('chat_reply_ticket_status');
    if ($has('thank')) return t('chat_reply_thanks');
    if (trim($x) === 'hi' || $has('hello', 'hey')) return t('chat_reply_greeting');
    return t('chat_reply_default');
}

// Gemini answers are for administrators and technicians only. For everyone else the
// Gemini API is never called.
$reply = null;
if (isStaff($user)) {
    $reply = callGeminiForReply($history, $text);
}
$reply = $reply ?? defaultSupportReply($text);

$replyId = insertAndGetId($db,
    'INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,1,?)',
    [(int)$user['id'], t('chat_support_name'), $reply, nowStr()]);

echo json_encode([
    'ok' => true,
    'user_message' => ['id' => $userMsgId, 'message' => $text, 'from_support' => 0, 'time' => date('H:i')],
    'support_reply' => ['id' => $replyId, 'message' => $reply, 'from_support' => 1, 'time' => date('H:i')],
]);
