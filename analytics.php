<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();

if (isStaff($user)) {
    $tickets = $db->query('SELECT * FROM tickets')->fetchAll();
} else {
    $st = $db->prepare('SELECT * FROM tickets WHERE user_id = ?');
    $st->execute([(int)$user['id']]);
    $tickets = $st->fetchAll();
}

$total = count($tickets);
$statusCounts = array_fill_keys(STATUS_KEYS, 0);
$priorityCounts = array_fill_keys(PRIORITY_KEYS, 0);
$categoryCounts = [];
$urgent = 0; $resolved = 0;

foreach ($tickets as $t) {
    $statusCounts[$t['status']] = ($statusCounts[$t['status']] ?? 0) + 1;
    if (isResolvedStatus($t['status'])) $resolved++;
    $ai = aiAnalyzePriority($t['title'], $t['description'], $t['category']);
    $priorityCounts[$ai['priority']]++;
    if (in_array($ai['priority'], ['critical', 'high'], true)) $urgent++;
    $categoryCounts[$t['category'] ?: 'other'] = ($categoryCounts[$t['category'] ?: 'other'] ?? 0) + 1;
}
arsort($categoryCounts);
$resolvedPct = $total > 0 ? (int)round($resolved * 100 / $total) : 0;

$statusLabels = array_map('statusLabel', STATUS_KEYS);
$priorityLabels = array_map('priorityLabel', PRIORITY_KEYS);
$catLabels = array_map('categoryLabel', array_keys($categoryCounts));

$pageTitle = t('analytics_title');
require __DIR__ . '/includes/header.php';
?>
<h2><?= h(t('analytics_title')) ?></h2>
<p class="muted"><?= h(isStaff($user) ? t('analytics_all_tickets_count_format', $total) : t('analytics_your_tickets')) ?></p>

<?php if ($total === 0): ?>
    <div class="card"><p class="muted"><?= h(t('analytics_no_tickets_yet')) ?></p></div>
<?php else: ?>
<div class="stat-grid">
    <div class="stat"><div class="label"><?= h(t('analytics_stat_total')) ?></div><div class="value"><?= $total ?></div></div>
    <div class="stat"><div class="label"><?= h(t('analytics_stat_resolved_pct')) ?></div><div class="value" style="color:var(--success)"><?= $resolvedPct ?>%</div></div>
    <div class="stat"><div class="label"><?= h(t('analytics_stat_critical_high')) ?></div><div class="value" style="color:var(--danger)"><?= $urgent ?></div></div>
</div>

<div class="chart-grid">
    <div class="card"><h3><?= h(t('analytics_by_status')) ?></h3><div class="chart-box"><canvas id="cStatus"></canvas></div></div>
    <div class="card"><h3><?= h(t('analytics_by_priority')) ?></h3><div class="chart-box"><canvas id="cPriority"></canvas></div></div>
</div>
<div class="card"><h3><?= h(t('analytics_by_category')) ?></h3><div class="chart-box"><canvas id="cCategory"></canvas></div></div>

<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
(function () {
    var data = {
        status:   { labels: <?= json_encode($statusLabels) ?>, values: <?= json_encode(array_values($statusCounts)) ?>, colors: ['#3b82f6', '#f59e0b', '#10b981', '#94a3b8'] },
        priority: { labels: <?= json_encode($priorityLabels) ?>, values: <?= json_encode(array_values($priorityCounts)) ?>, colors: ['#10b981', '#3b82f6', '#f59e0b', '#ef4444'] },
        category: { labels: <?= json_encode($catLabels) ?>, values: <?= json_encode(array_values($categoryCounts)) ?>, colors: ['#6366f1'] }
    };
    var charts = [];

    function css(name) { return getComputedStyle(document.documentElement).getPropertyValue(name).trim(); }

    function draw() {
        if (typeof Chart === 'undefined') { fallback(); return; }
        charts.forEach(function (c) { c.destroy(); });
        charts = [];
        var text = css('--muted'), grid = css('--border');
        Chart.defaults.color = text;
        Chart.defaults.borderColor = grid;
        var opts = { responsive: true, maintainAspectRatio: false };
        var barOpts = Object.assign({}, opts, { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } });

        charts.push(new Chart(document.getElementById('cStatus'), { type: 'doughnut',
            data: { labels: data.status.labels, datasets: [{ data: data.status.values, backgroundColor: data.status.colors, borderWidth: 0 }] },
            options: Object.assign({}, opts, { plugins: { legend: { position: 'bottom' } } }) }));
        charts.push(new Chart(document.getElementById('cPriority'), { type: 'bar',
            data: { labels: data.priority.labels, datasets: [{ data: data.priority.values, backgroundColor: data.priority.colors, borderRadius: 6 }] }, options: barOpts }));
        charts.push(new Chart(document.getElementById('cCategory'), { type: 'bar',
            data: { labels: data.category.labels, datasets: [{ data: data.category.values, backgroundColor: data.category.colors[0], borderRadius: 6 }] }, options: barOpts }));
    }

    // If the chart library can't load (offline), show the numbers instead of a blank box.
    function fallback() {
        ['cStatus', 'cPriority', 'cCategory'].forEach(function (id, i) {
            var d = [data.status, data.priority, data.category][i], el = document.getElementById(id);
            if (!el || !el.parentNode) return;
            var list = document.createElement('div');
            d.labels.forEach(function (l, k) {
                var row = document.createElement('div'); row.className = 'row between';
                var a = document.createElement('span'); a.textContent = l;
                var b = document.createElement('strong'); b.textContent = d.values[k];
                row.appendChild(a); row.appendChild(b); list.appendChild(row);
            });
            el.parentNode.replaceChild(list, el);
        });
    }

    window.cdRedrawCharts = function () { if (typeof Chart !== 'undefined') draw(); };
    window.addEventListener('load', draw);
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
