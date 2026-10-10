<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();
$staff = isStaff($user);
$ticketId = cleanTicketId(getStr('id'));

function loadTicket(PDO $db, string $id): ?array {
    $st = $db->prepare('SELECT * FROM tickets WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

$ticket = loadTicket($db, $ticketId);
if (!$ticket) { http_response_code(404); $pageTitle = t('toast_invalid_ticket_id'); require __DIR__ . '/includes/header.php'; echo '<div class="alert alert-error">' . h(t('toast_invalid_ticket_id')) . '</div><a href="dashboard.php">← ' . h(t('nav_dashboard')) . '</a>'; require __DIR__ . '/includes/footer.php'; exit; }
if (!$staff && (int)$ticket['user_id'] !== (int)$user['id']) { http_response_code(403); $pageTitle = t('toast_invalid_ticket_id'); require __DIR__ . '/includes/header.php'; echo '<div class="alert alert-error">' . h(t('toast_invalid_ticket_id')) . '</div><a href="dashboard.php">← ' . h(t('nav_dashboard')) . '</a>'; require __DIR__ . '/includes/footer.php'; exit; }

$isOwner = (int)$ticket['user_id'] === (int)$user['id'];
$ownerSt = $db->prepare('SELECT * FROM users WHERE id = ?');
$ownerSt->execute([(int)$ticket['user_id']]);
$owner = $ownerSt->fetch() ?: ['first_name' => '?', 'last_name' => '', 'email' => '', 'phone' => null];
$actor = fullName($user);
$self = 'ticket_detail.php?id=' . rawurlencode($ticketId);

if (isPost()) {
    requireCsrf();
    $action = postStr('action');

    if ($action === 'update_ticket' && $staff) {
        $newStatus = in_array(postStr('status'), STATUS_KEYS, true) ? postStr('status') : $ticket['status'];
        $newPriority = in_array(postStr('priority'), PRIORITY_KEYS, true) ? postStr('priority') : $ticket['priority'];
        // Only administrators assign tickets; a technician's update keeps the current assignee.
        $newAssignee = $ticket['assigned_to'];
        if ($user['role'] === 'admin') {
            $wanted = postStr('assigned_to');
            $newAssignee = null;
            if ($wanted !== '') {
                $chk = $db->prepare("SELECT first_name, last_name FROM users WHERE role = 'technician' AND active = 1");
                $chk->execute();
                foreach ($chk->fetchAll() as $tech) { if (fullName($tech) === $wanted) $newAssignee = $wanted; }
            }
        }

        $resolvedAt = $ticket['resolved_at'];
        if (isResolvedStatus($newStatus)) { $resolvedAt = $resolvedAt ?: nowStr(); } else { $resolvedAt = null; }

        $db->prepare('UPDATE tickets SET status=?, priority=?, assigned_to=?, resolved_at=? WHERE id=?')
           ->execute([$newStatus, $newPriority, $newAssignee, $resolvedAt, $ticketId]);

        $lines = [];
        if ($newStatus !== $ticket['status']) { logHistory($db, $ticketId, $actor, 'status', $ticket['status'], $newStatus); $lines[] = 'Status: ' . $newStatus; }
        if ($newPriority !== $ticket['priority']) { logHistory($db, $ticketId, $actor, 'priority', $ticket['priority'], $newPriority); $lines[] = 'Priority: ' . $newPriority; }
        if (($newAssignee ?? '') !== ($ticket['assigned_to'] ?? '')) {
            logHistory($db, $ticketId, $actor, 'assignee', $ticket['assigned_to'], $newAssignee);
            $lines[] = 'Assigned to: ' . ($newAssignee ?? '—');
        }

        $updated = loadTicket($db, $ticketId);
        if ($lines) {
            if ($newAssignee && $newAssignee !== $ticket['assigned_to']) notifyAssigned($db, $updated, $newAssignee);
            notifyOwnerUpdate($db, $updated, (int)$user['id'], implode("\n", $lines));
        }
        flash('success', t('toast_ticket_updated'));
        redirect($self);
    }

    if ($action === 'add_comment') {
        $text = postStr('comment');
        if ($text !== '') {
            $db->prepare('INSERT INTO comments (ticket_id, user_id, user_name, content, created_at) VALUES (?,?,?,?,?)')
               ->execute([$ticketId, (int)$user['id'], $actor, $text, nowStr()]);
            if ($staff) {
                notifyOwnerUpdate($db, $ticket, (int)$user['id'], "$actor commented: $text");
            } elseif ($ticket['assigned_to']) {
                // The owner replied — tell the assigned technician.
                $techs = $db->query("SELECT * FROM users WHERE role = 'technician' AND active = 1")->fetchAll();
                foreach ($techs as $tech) {
                    if (fullName($tech) === $ticket['assigned_to']) {
                        addNotification($db, (int)$tech['id'], 'notif_ticket_update_title', $ticketId . ' — ' . $ticket['title'], ticketLink($ticketId));
                    }
                }
            }
            flash('success', t('toast_comment_added'));
        }
        redirect($self . '#comments');
    }

    if ($action === 'rate' && $isOwner && isResolvedStatus($ticket['status']) && $ticket['rating'] === null) {
        $stars = (int)postStr('rating');
        if ($stars < 1 || $stars > 5) {
            flash('error', t('toast_pick_rating'));
        } else {
            $db->prepare('UPDATE tickets SET rating = ?, rating_comment = ? WHERE id = ?')
               ->execute([$stars, mb_substr(postStr('rating_comment'), 0, 1000) ?: null, $ticketId]);
            logHistory($db, $ticketId, $actor, 'rating', null, (string)$stars);
            flash('success', t('toast_rating_saved'));
        }
        redirect($self);
    }

    if ($action === 'notify_email' && $staff) {
        $body = "Hi {$owner['first_name']},\n\nAn update on your ticket $ticketId ('{$ticket['title']}'):\nStatus: " . statusLabel($ticket['status']) . "\n\n— " . APP_NAME . ' Support';
        $sent = sendNotificationEmail($owner['email'], "Update on ticket $ticketId", $body);
        flash($sent ? 'success' : 'error', $sent ? 'Email sent.' : t('toast_no_email_app') . ' (no mail server configured on this machine)');
        redirect($self);
    }
}

// ---- Load everything for display ----
$comments = $db->prepare('SELECT * FROM comments WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
$comments->execute([$ticketId]);
$comments = $comments->fetchAll();

$history = $db->prepare('SELECT * FROM ticket_history WHERE ticket_id = ? ORDER BY created_at ASC, id ASC');
$history->execute([$ticketId]);
$history = $history->fetchAll();

$technicians = $db->query("SELECT * FROM users WHERE role = 'technician' AND active = 1 ORDER BY first_name")->fetchAll();
$smsLink = smsNotifyLink($owner['phone'] ?? null, "CloudDesk: update on ticket $ticketId ('{$ticket['title']}') — " . statusLabel($ticket['status']));
$overdue = isOverdue($ticket);
$dueLabel = slaDueLabel($ticket);
$canRate = $isOwner && isResolvedStatus($ticket['status']) && $ticket['rating'] === null;

$pageTitle = $ticket['title'];
require __DIR__ . '/includes/header.php';
?>

<a href="dashboard.php" class="small">← <?= h(t('nav_dashboard')) ?></a>
<h2 class="mt"><?= h($ticket['title']) ?></h2>
<p class="muted small">#<?= h($ticket['id']) ?> · <?= h(fullName($owner)) ?> · <?= h(t('ticket_detail_created_on', formatDateTime($ticket['created_at']))) ?></p>

<div class="card">
    <div class="row">
        <span class="badge badge-<?= h($ticket['status']) ?>"><?= h(statusLabel($ticket['status'])) ?></span>
        <span class="badge badge-<?= h($ticket['priority']) ?>"><?= h(priorityLabel($ticket['priority'])) ?></span>
        <span class="muted small"><?= h(categoryLabel($ticket['category'])) ?></span>
        <?php if ($ticket['assigned_to']): ?><span class="muted small">· <?= h($ticket['assigned_to']) ?></span><?php endif; ?>
    </div>

    <?php if ($dueLabel && in_array($ticket['status'], ['open', 'progress'], true)): ?>
        <div class="sla-line <?= $overdue ? 'over' : '' ?>"><?= h($overdue ? t('sla_overdue_format', $dueLabel) : t('sla_due_format', $dueLabel)) ?></div>
    <?php endif; ?>

    <h3 class="mt"><?= h(t('ticket_detail_description_label')) ?></h3>
    <p><?= nl2br(h($ticket['description'])) ?></p>

    <?php if ($ticket['attachment_path']): ?>
        <a class="attach" href="attachment.php?id=<?= h(rawurlencode($ticket['id'])) ?>" target="_blank" rel="noopener">📎 <?= h($ticket['attachment_name']) ?></a>
    <?php endif; ?>
</div>

<?php if ($ticket['rating'] !== null): ?>
<div class="card">
    <h3><?= h(t('rating_title')) ?></h3>
    <div class="star-static" aria-label="<?= (int)$ticket['rating'] ?>/5"><?= str_repeat('★', (int)$ticket['rating']) . str_repeat('☆', 5 - (int)$ticket['rating']) ?></div>
    <?php if ($ticket['rating_comment']): ?><p class="muted"><?= nl2br(h($ticket['rating_comment'])) ?></p><?php endif; ?>
</div>
<?php elseif ($canRate): ?>
<div class="card">
    <h3><?= h(t('rating_title')) ?></h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="rate">
        <div class="stars stars-rtl" role="radiogroup" aria-label="<?= h(t('rating_title')) ?>">
            <?php for ($i = 5; $i >= 1; $i--): ?>
                <input type="radio" name="rating" id="star<?= $i ?>" value="<?= $i ?>"><label for="star<?= $i ?>" title="<?= $i ?>">★</label>
            <?php endfor; ?>
        </div>
        <textarea name="rating_comment" rows="2" class="mt" placeholder="<?= h(t('rating_comment_hint')) ?>"></textarea>
        <button class="btn" type="submit"><?= h(t('rating_submit')) ?></button>
    </form>
</div>
<?php endif; ?>

<?php if ($staff): ?>
<div class="card">
    <h3><?= h(t('ticket_detail_actions_label')) ?></h3>
    <form method="post">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="update_ticket">
        <div class="field-row">
            <div>
                <label for="status"><?= h(t('ticket_detail_status_hint')) ?></label>
                <select id="status" name="status">
                    <?php foreach (STATUS_KEYS as $s): ?><option value="<?= h($s) ?>" <?= $ticket['status'] === $s ? 'selected' : '' ?>><?= h(statusLabel($s)) ?></option><?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="priority"><?= h(t('submit_ticket_priority_hint')) ?></label>
                <select id="priority" name="priority">
                    <?php foreach (PRIORITY_KEYS as $p): ?><option value="<?= h($p) ?>" <?= $ticket['priority'] === $p ? 'selected' : '' ?>><?= h(priorityLabel($p)) ?></option><?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if ($user['role'] === 'admin'): ?>
            <label for="assigned_to"><?= h(t('ticket_detail_assign_hint')) ?></label>
            <select id="assigned_to" name="assigned_to">
                <option value="">— <?= h(t('performance_unassigned')) ?> —</option>
                <?php foreach ($technicians as $tech): $nm = fullName($tech); ?>
                    <option value="<?= h($nm) ?>" <?= $ticket['assigned_to'] === $nm ? 'selected' : '' ?>><?= h($nm) ?></option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
        <button class="btn btn-block" type="submit"><?= h(t('ticket_detail_update_button')) ?></button>
    </form>

    <div class="row mt">
        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="notify_email">
            <button class="btn btn-secondary btn-sm" type="submit"><?= h(t('ticket_detail_notify_email')) ?></button></form>
        <?php if ($smsLink): ?>
            <a class="btn btn-secondary btn-sm" href="<?= h($smsLink) ?>"><?= h(t('ticket_detail_notify_sms')) ?></a>
        <?php else: ?>
            <button class="btn btn-secondary btn-sm" type="button" disabled title="<?= h(t('toast_no_phone_on_file')) ?>"><?= h(t('ticket_detail_notify_sms')) ?></button>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<div class="card">
    <h3><?= h(t('history_title')) ?></h3>
    <?php if (!$history): ?><p class="muted"><?= h(t('history_empty')) ?></p><?php else: ?>
    <ul class="history">
        <?php foreach ($history as $e): ?>
            <li><?= h(describeHistory($e)) ?>
                <div class="muted small"><?= h($e['actor_name']) ?> · <?= h(formatDateTime($e['created_at'])) ?></div></li>
        <?php endforeach; ?>
    </ul>
    <?php endif; ?>
</div>

<div class="card" id="comments">
    <h3><?= h(t('ticket_detail_comments_label')) ?></h3>
    <?php foreach ($comments as $c): ?>
        <div class="comment">
            <strong class="small"><?= h($c['user_name']) ?></strong>
            <span class="muted small"> · <?= h(formatDateTime($c['created_at'])) ?></span>
            <div><?= nl2br(h($c['content'])) ?></div>
        </div>
    <?php endforeach; ?>

    <form method="post" class="mt">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_comment">
        <?php if ($staff): ?>
            <label for="canned"><?= h(t('canned_replies_button')) ?></label>
            <select id="canned">
                <option value=""><?= h(t('canned_replies_title')) ?></option>
                <?php for ($i = 1; $i <= 6; $i++): ?><option value="<?= h(t("canned_$i")) ?>"><?= h(t("canned_$i")) ?></option><?php endfor; ?>
            </select>
        <?php endif; ?>
        <textarea name="comment" id="commentText" rows="3" placeholder="<?= h(t('ticket_detail_comment_hint')) ?>" required></textarea>
        <button class="btn" type="submit"><?= h(t('ticket_detail_post_comment')) ?></button>
    </form>
</div>

<script>
var canned = document.getElementById('canned');
if (canned) canned.addEventListener('change', function () {
    if (!canned.value) return;
    var box = document.getElementById('commentText');
    box.value = canned.value;
    box.focus();
    canned.selectedIndex = 0;
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
