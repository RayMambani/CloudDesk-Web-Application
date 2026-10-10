<?php
// Shared by performance_reports.php and export.php.

function avgResolutionHours(array $tickets): ?float {
    $d = [];
    foreach ($tickets as $t) {
        if (!isResolvedStatus($t['status']) || empty($t['resolved_at'])) continue;
        $s = strtotime($t['created_at']); $e = strtotime($t['resolved_at']);
        if ($s === false || $e === false || $e < $s) continue;
        $d[] = ($e - $s) / 3600;
    }
    return $d ? array_sum($d) / count($d) : null;
}

function avgRating(array $tickets): ?float {
    $r = array_values(array_filter(array_map(fn($t) => $t['rating'], $tickets), fn($v) => $v !== null));
    return $r ? array_sum($r) / count($r) : null;
}

/** @return array{total:int,resolved:int,pct:int,avg:?float,rating:?float,rows:array} */
function buildPerformanceReport(PDO $db): array {
    $tickets = $db->query('SELECT * FROM tickets')->fetchAll();
    $techs = $db->query("SELECT * FROM users WHERE role = 'technician' ORDER BY first_name, last_name")->fetchAll();

    $stats = function (string $name, array $set): array {
        $res = count(array_filter($set, fn($t) => isResolvedStatus($t['status'])));
        return [
            'name' => $name, 'assigned' => count($set), 'resolved' => $res,
            'rate' => count($set) ? (int)round($res * 100 / count($set)) : 0,
            'avg' => avgResolutionHours($set), 'rating' => avgRating($set),
        ];
    };

    $rows = [];
    foreach ($techs as $tech) {
        $n = fullName($tech);
        $rows[] = $stats($n, array_values(array_filter($tickets, fn($t) => $t['assigned_to'] === $n)));
    }
    $un = array_values(array_filter($tickets, fn($t) => empty($t['assigned_to'])));
    if ($un) $rows[] = $stats(t('performance_unassigned'), $un);

    $resolved = count(array_filter($tickets, fn($t) => isResolvedStatus($t['status'])));
    return [
        'total' => count($tickets), 'resolved' => $resolved,
        'pct' => $tickets ? (int)round($resolved * 100 / count($tickets)) : 0,
        'avg' => avgResolutionHours($tickets), 'rating' => avgRating($tickets), 'rows' => $rows,
    ];
}

function ratingText(?float $r): string { return $r === null ? t('time_not_available') : number_format($r, 1) . ' / 5'; }

function performanceReportText(array $rep): string {
    $L = [];
    $L[] = t('performance_email_title');
    $L[] = t('performance_generated_format', date('Y-m-d H:i'));
    $L[] = '';
    $L[] = t('performance_overall_caps');
    $L[] = t('performance_total_tickets_format', $rep['total']);
    $L[] = t('performance_resolved_format', $rep['resolved'], $rep['pct']);
    $L[] = t('performance_avg_resolution_format', formatHours($rep['avg']));
    $L[] = t('performance_avg_rating_format', ratingText($rep['rating']));
    $L[] = '';
    $L[] = t('performance_by_technician_caps');
    foreach ($rep['rows'] as $r) {
        $L[] = t('performance_technician_line_format', $r['name'], $r['assigned'], $r['resolved'], $r['rate'], formatHours($r['avg']))
            . ' · ' . ratingText($r['rating']);
    }
    return implode("\n", $L);
}

/** Make a value safe for CSV opened in Excel (prevents formula injection). */
function csvSafe($v): string {
    $s = (string)($v ?? '');
    return preg_match('/^[=+\-@\t\r]/', $s) ? "'" . $s : $s;
}

function csvLine(array $cells): string {
    $out = [];
    foreach ($cells as $c) { $out[] = '"' . str_replace('"', '""', csvSafe($c)) . '"'; }
    return implode(',', $out) . "\r\n";
}
