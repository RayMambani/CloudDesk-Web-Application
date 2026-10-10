<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/upload.php';

$user = requireLogin();
if ($user['role'] !== 'user') redirect('dashboard.php');
$db = getDb();
$error = '';
$created = null; // set after a successful submit
$f = ['title' => '', 'description' => '', 'category' => 'software', 'priority' => 'medium'];

if (isPost()) {
    requireCsrf();
    $f['title'] = postStr('title');
    $f['description'] = postStr('description');
    $f['category'] = in_array(postStr('category'), CATEGORY_KEYS, true) ? postStr('category') : 'other';
    $f['priority'] = in_array(postStr('priority'), PRIORITY_KEYS, true) ? postStr('priority') : 'medium';

    if ($f['title'] === '' || $f['description'] === '') {
        $error = t('toast_fill_all_fields');
    } else {
        try {
            // Unique, readable ticket id.
            do {
                $id = 'TCK-' . random_int(1000, 99999);
                $chk = $db->prepare('SELECT 1 FROM tickets WHERE id = ?');
                $chk->execute([$id]);
            } while ($chk->fetchColumn());

            $att = storeAttachment($_FILES['attachment'] ?? [], $id);

            $db->prepare("INSERT INTO tickets (id,title,description,status,priority,category,created_at,user_id,attachment_path,attachment_name)
                          VALUES (?,?,?,'open',?,?,?,?,?,?)")
               ->execute([$id, mb_substr($f['title'], 0, 255), $f['description'], $f['priority'], $f['category'], nowStr(),
                          (int)$user['id'], $att['path'] ?? null, $att['name'] ?? null]);

            logHistory($db, $id, fullName($user), 'created');

            // Let every administrator know a new ticket arrived.
            foreach ($db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll() as $adm) {
                if ((int)$adm['id'] !== (int)$user['id']) {
                    addNotification($db, (int)$adm['id'], 'notif_new_ticket_title', $id . ' — ' . $f['title'], ticketLink($id));
                }
            }

            $body = "Hi {$user['first_name']},\n\nYour ticket $id ('{$f['title']}') has been submitted with "
                . priorityLabel($f['priority']) . " priority. We'll follow up as it's worked on.\n\n— " . APP_NAME . ' Support';
            if (!empty($_POST['email_me'])) {
                sendNotificationEmail($user['email'], "Ticket $id submitted", $body);
            }
            $created = ['id' => $id, 'attachment' => $att !== null, 'sms' => smsNotifyLink($user['phone'], $body)];
        } catch (RuntimeException $e) {
            $error = $e->getMessage();
        }
    }
}

$pageTitle = t('submit_ticket_title');
require __DIR__ . '/includes/header.php';
?>

<a href="dashboard.php" class="small">← <?= h(t('nav_dashboard')) ?></a>
<h2 class="mt"><?= h(t('submit_ticket_title')) ?></h2>
<p class="muted"><?= h(t('submit_ticket_subtitle')) ?></p>

<?php if ($created): ?>
    <div class="card">
        <div class="alert alert-success"><?= h(t('toast_ticket_submitted', $created['attachment'] ? t('toast_ticket_submitted_with_attachment') : '')) ?></div>
        <p><strong>#<?= h($created['id']) ?></strong></p>
        <p class="muted"><?= h(t('ticket_submitted_dialog_message')) ?></p>
        <div class="row">
            <a class="btn" href="ticket_detail.php?id=<?= h(rawurlencode($created['id'])) ?>"><?= h(t('dashboard_recent_tickets')) ?></a>
            <?php if ($created['sms']): ?>
                <a class="btn btn-secondary" href="<?= h($created['sms']) ?>"><?= h(t('action_text_me')) ?></a>
            <?php else: ?>
                <span class="muted small"><?= h(t('toast_add_phone_first')) ?> · <a href="settings.php"><?= h(t('nav_settings')) ?></a></span>
            <?php endif; ?>
            <a class="btn btn-secondary" href="dashboard.php"><?= h(t('nav_dashboard')) ?></a>
        </div>
    </div>
<?php else: ?>
<div class="card">
    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" enctype="multipart/form-data" id="ticketForm">
        <?= csrfField() ?>
        <label for="title"><?= h(t('submit_ticket_issue_summary_hint')) ?></label>
        <input type="text" id="title" name="title" maxlength="255" value="<?= h($f['title']) ?>" required>

        <label for="category"><?= h(t('submit_ticket_category_hint')) ?></label>
        <select id="category" name="category">
            <?php foreach (CATEGORY_KEYS as $c): ?>
                <option value="<?= h($c) ?>" <?= $f['category'] === $c ? 'selected' : '' ?>><?= h(categoryLabel($c)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="description"><?= h(t('submit_ticket_description_hint')) ?></label>
        <textarea id="description" name="description" rows="6" required><?= h($f['description']) ?></textarea>

        <div class="ai-box" id="aiBox" hidden>
            <strong><?= h(t('ai_priority_suggestion_title')) ?></strong>
            <p id="aiText" class="small" style="margin:6px 0 8px"></p>
            <button type="button" class="btn btn-sm btn-secondary" id="aiApply"><?= h(t('ai_apply_suggestion')) ?></button>
        </div>

        <label for="priority"><?= h(t('submit_ticket_priority_hint')) ?></label>
        <select id="priority" name="priority">
            <?php foreach (PRIORITY_KEYS as $p): ?>
                <option value="<?= h($p) ?>" <?= $f['priority'] === $p ? 'selected' : '' ?>><?= h(priorityLabel($p)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="attachment"><?= h(t('submit_ticket_attachments')) ?></label>
        <input type="file" id="attachment" name="attachment">
        <p class="muted small" style="margin-top:-8px"><?= h(implode(', ', UPLOAD_ALLOWED_EXT)) ?> · max <?= (int)(UPLOAD_MAX_BYTES / 1048576) ?> MB</p>

        <label class="check"><input type="checkbox" name="email_me" value="1"> <?= h(t('submit_ticket_email_confirmation_checkbox')) ?></label>

        <button type="submit" class="btn btn-block"><?= h(t('submit_ticket_button')) ?></button>
    </form>
</div>

<script>
// Live AI priority suggestion — same keyword scoring as includes/ai_priority.php
// (the term lists come from PHP, so the two can never drift apart).
(function () {
    var L = <?= json_encode(aiTermLists()) ?>;
    var labels = <?= json_encode(array_combine(PRIORITY_KEYS, array_map('priorityLabel', PRIORITY_KEYS))) ?>;
    var fmt = <?= json_encode(t('ai_priority_suggested_format', '{p}', 0)) ?>;       // "...{p} (0% confidence)"
    var basedOn = <?= json_encode(t('ai_priority_based_on_format', '{m}')) ?>;
    var title = document.getElementById('title'), desc = document.getElementById('description'),
        cat = document.getElementById('category'), prio = document.getElementById('priority'),
        box = document.getElementById('aiBox'), text = document.getElementById('aiText'),
        apply = document.getElementById('aiApply');
    var suggested = null;

    function run() {
        var tt = title.value, dd = desc.value;
        if (tt.length < 3 && dd.length < 5) { box.hidden = true; return; }
        var s = (tt + ' ' + dd).toLowerCase(), score = 0, matched = [];
        L.critical.forEach(function (w) { if (s.indexOf(w) > -1) { score += 4; matched.push(w); } });
        L.high.forEach(function (w) { if (s.indexOf(w) > -1) { score += 2; matched.push(w); } });
        L.medium.forEach(function (w) { if (s.indexOf(w) > -1) { score += 1; matched.push(w); } });
        L.low.forEach(function (w) { if (s.indexOf(w) > -1) { score -= 2; matched.push(w); } });
        if (L.urgentCategories.indexOf(cat.value) > -1) score += 2;
        if ((s.match(/!/g) || []).length >= 2) { score += 1; matched.push('! ! (urgent tone)'); }
        var up = tt.replace(/[^A-Z]/g, '').length;
        if (tt.length > 6 && up / Math.max(tt.length, 1) > 0.6) { score += 1; matched.push('ALL CAPS TITLE'); }

        suggested = score >= 6 ? 'critical' : score >= 3 ? 'high' : score >= 1 ? 'medium' : 'low';
        var uniq = matched.filter(function (v, i, a) { return a.indexOf(v) === i; });
        var conf = Math.min(55 + uniq.length * 8, 96);
        var line = fmt.replace('{p}', labels[suggested]).replace(/\(\d+%/, '(' + conf + '%');
        if (uniq.length) line += basedOn.replace('{m}', uniq.slice(0, 3).join(', '));
        text.textContent = line;
        box.hidden = false;
    }
    [title, desc].forEach(function (el) { el.addEventListener('input', run); });
    cat.addEventListener('change', run);
    apply.addEventListener('click', function () { if (suggested) prio.value = suggested; });
    run();
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>