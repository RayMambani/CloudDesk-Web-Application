<?php
// Service-level targets: how long each priority may stay unresolved.
// Critical 4h, High 24h, Medium 72h, Low 120h (same as the Android app).

function slaHours(string $priority): int {
    return match (strtolower($priority)) {
        'critical' => 4,
        'high' => 24,
        'medium' => 72,
        default => 120,
    };
}

function slaDueTs(array $ticket): ?int {
    $created = strtotime((string)$ticket['created_at']);
    if ($created === false) return null;
    return $created + slaHours($ticket['priority']) * 3600;
}

function isOverdue(array $ticket, ?int $now = null): bool {
    if (!in_array($ticket['status'], ['open', 'progress'], true)) return false;
    $due = slaDueTs($ticket);
    return $due !== null && ($now ?? time()) > $due;
}

function slaDueLabel(array $ticket): ?string {
    $due = slaDueTs($ticket);
    return $due === null ? null : date('Y-m-d H:i', $due);
}
