<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();
$uid = (int)$user['id'];

// Open one notification: mark it read and jump to the ticket.
if (isset($_GET['open'])) {
    $st = $db->prepare('SELECT * FROM notifications WHERE id = ? AND user_id = ?');
    $st->execute([(int)$_GET['open'], $uid]);
    if ($n = $st->fetch()) {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE id = ?')->execute([(int)$n['id']]);
        $link = (string)$n['link'];
        if (str_starts_with($link, 'ticket_detail.php?id=')) redirect($link);
    }
    redirect('notifications.php');
}

if (isPost()) {
    requireCsrf();
    if (postStr('action') === 'read_all') {
        $db->prepare('UPDATE notifications SET is_read = 1 WHERE user_id = ?')->execute([$uid]);
    }
    redirect('notifications.php');
}

$st = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 100');
$st->execute([$uid]);
$items = $st->fetchAll();

$pageTitle = t('nav_notifications');
require __DIR__ . '/includes/header.php';
?>
<div class="row between mb">
    <h2 style="margin:0"><?= h(t('nav_notifications')) ?></h2>
    <div class="row">
        <button class="btn btn-secondary btn-sm" type="button" onclick="cdEnableDesktopAlerts()">🔔 <?= h(t('notif_enable_desktop')) ?></button>
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="read_all">
            <button class="btn btn-secondary btn-sm" type="submit"><?= h(t('notif_mark_all_read')) ?></button></form>
    </div>
</div>

<div class="card" style="padding:0">
    <?php foreach ($items as $n): ?>
        <a class="note <?= $n['is_read'] ? '' : 'unread' ?>" href="notifications.php?open=<?= (int)$n['id'] ?>" style="padding:12px 18px">
            <span class="note-title"><?= h(notificationTitle($n['title'])) ?></span>
            <span class="note-body"><?= h($n['body']) ?> · <?= h(formatDateTime($n['created_at'])) ?></span>
        </a>
    <?php endforeach; ?>
    <?php if (!$items): ?><p class="muted pad"><?= h(t('notif_none')) ?></p><?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
