<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reports.php';

$user = requireRole(['admin']);
$db = getDb();
$rep = buildPerformanceReport($db);
$text = performanceReportText($rep);
$subject = t('performance_email_subject_format', date('Y-m-d'));

if (isPost()) {
    requireCsrf();
    $to = postStr('recipient');
    if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
        $ok = sendNotificationEmail($to, $subject, $text);
        flash($ok ? 'success' : 'error', $ok ? "Report sent to $to." : t('toast_no_email_app') . ' (no mail server configured on this machine) — use "Open in my email app" instead.');
    } else {
        flash('error', t('toast_fill_all_fields'));
    }
    redirect('performance_reports.php');
}

$mailto = 'mailto:?subject=' . rawurlencode($subject) . '&body=' . rawurlencode($text);
$pageTitle = t('performance_reports_title');
require __DIR__ . '/includes/header.php';
?>
<div class="row between mb">
    <h2 style="margin:0"><?= h(t('performance_reports_title')) ?></h2>
    <div class="row no-print">
        <a class="btn btn-secondary btn-sm" href="export.php?type=performance"><?= h(t('export_csv')) ?></a>
        <button class="btn btn-secondary btn-sm" type="button" onclick="window.print()"><?= h(t('export_pdf')) ?></button>
        <a class="btn btn-secondary btn-sm" href="export.php?type=tickets">CSV · <?= h(t('nav_dashboard')) ?></a>
    </div>
</div>
<p class="muted small"><?= h(t('performance_generated_format', date('Y-m-d H:i'))) ?></p>

<div class="stat-grid">
    <div class="stat"><div class="label"><?= h(t('analytics_stat_total')) ?></div><div class="value"><?= $rep['total'] ?></div></div>
    <div class="stat"><div class="label"><?= h(t('dashboard_stat_resolved')) ?></div><div class="value" style="color:var(--success)"><?= $rep['resolved'] ?> <span class="muted small">(<?= $rep['pct'] ?>%)</span></div></div>
    <div class="stat"><div class="label"><?= h(t('performance_avg_resolution_format', '')) ?></div><div class="value" style="font-size:1.3rem"><?= h(formatHours($rep['avg'])) ?></div></div>
    <div class="stat"><div class="label"><?= h(t('performance_avg_rating_format', '')) ?></div><div class="value" style="font-size:1.3rem">★ <?= h(ratingText($rep['rating'])) ?></div></div>
</div>

<div class="card">
    <h3><?= h(t('performance_by_technician')) ?></h3>
    <div class="table-wrap">
    <table>
        <thead><tr><th></th><th>#</th><th><?= h(t('dashboard_stat_resolved')) ?></th><th>%</th><th>⏱</th><th>★</th></tr></thead>
        <tbody>
        <?php foreach ($rep['rows'] as $r): ?>
            <tr><td><strong><?= h($r['name']) ?></strong></td><td><?= $r['assigned'] ?></td><td><?= $r['resolved'] ?></td>
                <td><?= $r['rate'] ?>%</td><td><?= h(formatHours($r['avg'])) ?></td><td><?= h(ratingText($r['rating'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$rep['rows']): ?><tr><td colspan="6" class="muted"><?= h(t('performance_no_technicians')) ?></td></tr><?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<div class="card no-print">
    <h3><?= h(t('performance_share_report')) ?></h3>
    <form method="post" class="row" style="align-items:flex-start">
        <?= csrfField() ?>
        <input type="email" name="recipient" placeholder="recipient@example.com" required class="grow" style="margin:0">
        <button class="btn" type="submit">📧</button>
        <a class="btn btn-secondary" href="<?= h($mailto) ?>">✉</a>
    </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
