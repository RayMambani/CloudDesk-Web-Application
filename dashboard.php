<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();
$myName = fullName($user);
$staff = isStaff($user);

// ---- Load the tickets this person may see ----
if ($staff) {
    $all = $db->query('SELECT * FROM tickets')->fetchAll();
} else {
    $st = $db->prepare('SELECT * FROM tickets WHERE user_id = ?');
    $st->execute([(int)$user['id']]);
    $all = $st->fetchAll();
}

// ---- Summary numbers (over everything visible, before filtering) ----
$total = count($all);
$openCount = count(array_filter($all, fn($t) => $t['status'] === 'open'));
$resolvedCount = count(array_filter($all, fn($t) => isResolvedStatus($t['status'])));
$overdueCount = $staff ? count(array_filter($all, fn($t) => isOverdue($t))) : 0;
$assignedToMe = $user['role'] === 'technician'
    ? count(array_filter($all, fn($t) => $t['assigned_to'] === $myName && in_array($t['status'], ['open', 'progress'], true)))
    : 0;

// ---- Filters (search + status + priority) ----
$q = getStr('q');
$statusFilter = in_array(getStr('status'), STATUS_KEYS, true) ? getStr('status') : '';
$priorityFilter = in_array(getStr('priority'), PRIORITY_KEYS, true) ? getStr('priority') : '';

$tickets = array_values(array_filter($all, function ($t) use ($q, $statusFilter, $priorityFilter) {
    if ($statusFilter !== '' && $t['status'] !== $statusFilter) return false;
    if ($priorityFilter !== '' && $t['priority'] !== $priorityFilter) return false;
    if ($q !== '') {
        $hay = mb_strtolower($t['title'] . ' ' . $t['description'] . ' ' . $t['id'] . ' ' . $t['category'] . ' ' . ($t['assigned_to'] ?? ''));
        if (!str_contains($hay, mb_strtolower($q))) return false;
    }
    return true;
}));

// ---- Order: staff see an AI-scored queue (urgent open work first); users see newest first ----
if ($staff) {
    $score = function ($t) use ($myName, $user) {
        $s = aiAnalyzePriority($t['title'], $t['description'], $t['category'])['raw_score'];
        if (in_array($t['status'], ['open', 'progress'], true)) $s += 100;
        if (isOverdue($t)) $s += 50;
        if ($user['role'] === 'technician' && $t['assigned_to'] === $myName) $s += 200;
        return $s;
    };
    usort($tickets, fn($a, $b) => $score($b) <=> $score($a));
} else {
    usort($tickets, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
}

// ---- Pagination ----
$perPage = 15;
$pages = max(1, (int)ceil(count($tickets) / $perPage));
$page = min($pages, max(1, (int)getStr('page', '1')));
$shown = array_slice($tickets, ($page - 1) * $perPage, $perPage);

function dashUrl(array $over = []): string {
    $params = array_merge(['q' => getStr('q'), 'status' => getStr('status'), 'priority' => getStr('priority')], $over);
    $params = array_filter($params, fn($v) => $v !== '' && $v !== null);
    return 'dashboard.php' . ($params ? '?' . http_build_query($params) : '');
}

$titleKey = match ($user['role']) { 'admin' => 'dashboard_admin', 'technician' => 'dashboard_technician', default => 'dashboard_user' };
$pageTitle = t($titleKey);
require __DIR__ . '/includes/header.php';
?>

<div class="row between mb">
    <div>
        <h2 style="margin:0"><?= h(t($titleKey)) ?></h2>
        <p class="muted" style="margin:0"><?= h(t('welcome_message', $myName)) ?></p>
    </div>
    <?php if ($user['role'] === 'user'): ?>
        <a href="submit_ticket.php" class="btn">+ <?= h(t('submit_ticket_title')) ?></a>
    <?php endif; ?>
</div>

<?php if ($overdueCount > 0): ?><span class="pill-banner pill-overdue"><?= h(t('overdue_banner_format', $overdueCount)) ?></span><?php endif; ?>
<?php if ($assignedToMe > 0): ?><span class="pill-banner pill-assigned"><?= h(t('assigned_banner_format', $assignedToMe)) ?></span><?php endif; ?>

<div class="stat-grid">
    <div class="stat"><div class="label"><?= h(t('dashboard_stat_total')) ?></div><div class="value"><?= $total ?></div></div>
    <div class="stat"><div class="label"><?= h(t('dashboard_stat_open')) ?></div><div class="value" style="color:var(--info)"><?= $openCount ?></div></div>
    <div class="stat"><div class="label"><?= h(t('dashboard_stat_resolved')) ?></div><div class="value" style="color:var(--success)"><?= $resolvedCount ?></div></div>
    <?php if ($staff): ?>
    <div class="stat"><div class="label"><?= h(t('overdue_indicator')) ?></div><div class="value" style="color:var(--danger)"><?= $overdueCount ?></div></div>
    <?php endif; ?>
</div>

<div class="card">
    <form method="get" class="row" style="gap:8px">
        <input type="hidden" name="status" value="<?= h($statusFilter) ?>">
        <input type="hidden" name="priority" value="<?= h($priorityFilter) ?>">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h(t('search_hint')) ?>" class="grow" style="margin:0" aria-label="<?= h(t('search_hint')) ?>">
        <button class="btn btn-secondary" type="submit">🔍</button>
        <?php if ($q !== '' || $statusFilter !== '' || $priorityFilter !== ''): ?>
            <a class="btn btn-secondary" href="dashboard.php">✕</a>
        <?php endif; ?>
    </form>

    <div class="chips" role="group" aria-label="<?= h(t('label_status', '')) ?>">
        <a class="chip <?= $statusFilter === '' ? 'active' : '' ?>" href="<?= h(dashUrl(['status' => '', 'page' => ''])) ?>"><?= h(t('filter_all_status')) ?></a>
        <?php foreach (STATUS_KEYS as $s): ?>
            <a class="chip <?= $statusFilter === $s ? 'active' : '' ?>" href="<?= h(dashUrl(['status' => $s, 'page' => ''])) ?>"><?= h(statusLabel($s)) ?></a>
        <?php endforeach; ?>
    </div>
    <div class="chips" role="group" aria-label="<?= h(t('label_priority', '')) ?>">
        <a class="chip <?= $priorityFilter === '' ? 'active' : '' ?>" href="<?= h(dashUrl(['priority' => '', 'page' => ''])) ?>"><?= h(t('filter_all_priority')) ?></a>
        <?php foreach (PRIORITY_KEYS as $p): ?>
            <a class="chip <?= $priorityFilter === $p ? 'active' : '' ?>" href="<?= h(dashUrl(['priority' => $p, 'page' => ''])) ?>"><?= h(priorityLabel($p)) ?></a>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <h3><?= h($staff ? t('dashboard_recent_tickets') . ' · AI' : t('dashboard_recent_tickets')) ?> <span class="muted small">(<?= count($tickets) ?>)</span></h3>

    <?php if (!$shown): ?>
        <p class="muted"><?= h(t('analytics_no_tickets_yet')) ?></p>
    <?php endif; ?>

    <?php foreach ($shown as $tk): ?>
        <div class="ticket-row">
            <div class="grow">
                <a class="title" href="ticket_detail.php?id=<?= h(rawurlencode($tk['id'])) ?>"><?= h($tk['title']) ?></a>
                <div class="meta">#<?= h($tk['id']) ?> · <?= h(categoryLabel($tk['category'])) ?> · <?= h(formatDateTime($tk['created_at'])) ?>
                    <?php if ($tk['assigned_to']): ?> · <?= h($tk['assigned_to']) ?><?php endif; ?></div>
                <div class="flags">
                    <?php if ($tk['attachment_path']): ?><span><?= h(t('item_ticket_attachment_indicator')) ?></span><?php endif; ?>
                    <?php if ($staff && isOverdue($tk)): ?><span class="flag-overdue"><?= h(t('overdue_indicator')) ?></span><?php endif; ?>
                    <?php if ($tk['assigned_to'] && $tk['assigned_to'] === $myName): ?><span class="flag-assigned"><?= h(t('assigned_to_you_indicator')) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="tags">
                <span class="badge badge-<?= h($tk['status']) ?>"><?= h(statusLabel($tk['status'])) ?></span>
                <span class="badge badge-<?= h($tk['priority']) ?>"><?= h(priorityLabel($tk['priority'])) ?></span>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?><a class="btn btn-sm btn-secondary" href="<?= h(dashUrl(['page' => $page - 1])) ?>">‹</a><?php endif; ?>
            <span class="muted small" style="align-self:center"><?= $page ?> / <?= $pages ?></span>
            <?php if ($page < $pages): ?><a class="btn btn-sm btn-secondary" href="<?= h(dashUrl(['page' => $page + 1])) ?>">›</a><?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>