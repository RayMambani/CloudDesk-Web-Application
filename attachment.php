<?php
// Streams a ticket attachment after checking the viewer may see that ticket.
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$id = cleanTicketId(getStr('id'));
$st = getDb()->prepare('SELECT * FROM tickets WHERE id = ?');
$st->execute([$id]);
$ticket = $st->fetch();

if (!$ticket || empty($ticket['attachment_path'])) { http_response_code(404); exit('Not found'); }
if (!isStaff($user) && (int)$ticket['user_id'] !== (int)$user['id']) { http_response_code(403); exit('Forbidden'); }

$path = realpath(__DIR__ . '/' . $ticket['attachment_path']);
$root = realpath(__DIR__ . '/uploads');
if (!$path || !$root || !str_starts_with($path, $root . DIRECTORY_SEPARATOR) || !is_file($path)) {
    http_response_code(404);
    exit(h(t('toast_couldnt_open_attachment', '')));
}

$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$inline = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain; charset=utf-8'];
$type = $inline[$ext] ?? 'application/octet-stream';
$disp = isset($inline[$ext]) ? 'inline' : 'attachment';

header('Content-Type: ' . $type);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'");
header('Content-Disposition: ' . $disp . '; filename="' . rawurlencode((string)$ticket['attachment_name']) . '"');
readfile($path);
