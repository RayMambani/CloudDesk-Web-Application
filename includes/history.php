<?php
// Ticket history: every status change, assignment, priority change and rating is logged.
// Stored with field KEYS (created/status/assignee/priority/rating) and rendered in the
// viewer's language.

function logHistory(PDO $db, string $ticketId, string $actorName, string $field, ?string $old = null, ?string $new = null): void {
    $st = $db->prepare('INSERT INTO ticket_history (ticket_id, actor_name, field, old_value, new_value, created_at) VALUES (?,?,?,?,?,?)');
    $st->execute([$ticketId, $actorName, $field, $old, $new, nowStr()]);
}

function describeHistory(array $e): string {
    switch ($e['field']) {
        case 'created':
            return t('history_created');
        case 'status':
            return t('history_status_format', statusLabel((string)$e['old_value']), statusLabel((string)$e['new_value']));
        case 'assignee':
            return ($e['new_value'] === null || $e['new_value'] === '')
                ? t('history_unassigned')
                : t('history_assigned_format', $e['new_value']);
        case 'priority':
            return t('history_priority_format', priorityLabel((string)$e['old_value']), priorityLabel((string)$e['new_value']));
        case 'rating':
            return t('history_rated_format', $e['new_value']);
        default:
            return (string)$e['field'];
    }
}
