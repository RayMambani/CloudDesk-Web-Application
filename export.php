<?php
// CSV downloads: ?type=performance (admins) or ?type=tickets (staff: all, users: their own).
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/reports.php';

$user = requireLogin();
$db = getDb();
$type = getStr('type', 'tickets');

header('Content-Type: text/csv; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($type === 'performance') {
    if ($user['role'] !== 'admin') { http_response_code(403); exit(h(t('toast_reports_admin_only'))); }
    header('Content-Disposition: attachment; filename="clouddesk_performance_' . date('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel shows accents correctly
    $rep = buildPerformanceReport($db);
    echo csvLine([t('performance_email_title'), date('Y-m-d H:i')]);
    echo csvLine([t('analytics_stat_total'), $rep['total']]);
    echo csvLine([t('dashboard_stat_resolved'), $rep['resolved'], $rep['pct'] . '%']);
    echo csvLine([t('performance_avg_resolution_format', ''), formatHours($rep['avg'])]);
    echo csvLine([t('performance_avg_rating_format', ''), ratingText($rep['rating'])]);
    echo csvLine([]);
    echo csvLine([t('performance_by_technician'), 'Assigned', 'Resolved', 'Rate %', 'Avg resolution', 'Avg rating']);
    foreach ($rep['rows'] as $r) {
        echo csvLine([$r['name'], $r['assigned'], $r['resolved'], $r['rate'], formatHours($r['avg']), ratingText($r['rating'])]);
    }
    exit;
}

header('Content-Disposition: attachment; filename="clouddesk_tickets_' . date('Ymd') . '.csv"');
echo "\xEF\xBB\xBF";
if (isStaff($user)) {
    $rows = $db->query('SELECT t.*, u.first_name, u.last_name FROM tickets t JOIN users u ON u.id = t.user_id ORDER BY t.created_at DESC')->fetchAll();
} else {
    $st = $db->prepare('SELECT t.*, u.first_name, u.last_name FROM tickets t JOIN users u ON u.id = t.user_id WHERE t.user_id = ? ORDER BY t.created_at DESC');
    $st->execute([(int)$user['id']]);
    $rows = $st->fetchAll();
}
echo csvLine(['ID', 'Title', 'Status', 'Priority', 'Category', 'Created', 'Submitted by', 'Assigned to', 'Resolved', 'Rating', 'Overdue']);
foreach ($rows as $r) {
    echo csvLine([$r['id'], $r['title'], statusLabel($r['status']), priorityLabel($r['priority']), categoryLabel($r['category']),
        formatDateTime($r['created_at']), trim($r['first_name'] . ' ' . $r['last_name']), $r['assigned_to'],
        formatDateTime($r['resolved_at']), $r['rating'], isOverdue($r) ? 'yes' : 'no']);
}
