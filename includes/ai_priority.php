<?php
// On-device-style "AI" ticket triage — a transparent keyword/heuristic scorer,
// ported directly from the Android app's TicketPriorityAI.kt so both the app
// and the website suggest the same priority for the same wording.
// No backend/API key needed; swap in a real model call here later if desired.

/** Keyword lists shared by the PHP scorer and the live JavaScript suggestion. */
function aiTermLists(): array {
    $critical = ['outage', 'down', 'cannot access', "can't access", 'not working at all',
        'data loss', 'lost data', 'breach', 'hacked', 'security', 'leak',
        'all users', 'entire team', 'production', 'urgent', 'asap', 'emergency',
        'crashed', 'crashing', 'unusable', 'locked out'];

    $high = ['error', 'crash', 'broken', 'failing', 'fails', 'cannot log in', "can't log in",
        'server error', '500', 'timeout', 'slow', 'blocked', 'unable to', 'not able to',
        'important', 'deadline'];

    $medium = ['issue', 'bug', 'problem', 'glitch', 'unexpected', 'incorrect', 'wrong',
        'missing', 'not showing', 'delay'];

    $low = ['question', 'request', 'suggestion', 'feedback', 'cosmetic', 'typo',
        'minor', 'when convenient', 'no rush', 'nice to have', 'how do i', 'how to'];

    $urgentCategories = ['network', 'account/access', 'auth', 'backend'];

    return ['critical' => $critical, 'high' => $high, 'medium' => $medium, 'low' => $low, 'urgentCategories' => $urgentCategories];
}

function aiAnalyzePriority(string $title, string $description, string $category): array {
    $text = strtolower($title . ' ' . $description);
    $L = aiTermLists();
    $criticalTerms = $L['critical']; $highTerms = $L['high']; $mediumTerms = $L['medium']; $lowTerms = $L['low']; $urgentCategories = $L['urgentCategories'];

    $score = 0;
    $matched = [];

    foreach ($criticalTerms as $term) { if (str_contains($text, $term)) { $score += 4; $matched[] = $term; } }
    foreach ($highTerms as $term) { if (str_contains($text, $term)) { $score += 2; $matched[] = $term; } }
    foreach ($mediumTerms as $term) { if (str_contains($text, $term)) { $score += 1; $matched[] = $term; } }
    foreach ($lowTerms as $term) { if (str_contains($text, $term)) { $score -= 2; $matched[] = $term; } }

    if (in_array(strtolower($category), $urgentCategories, true)) { $score += 2; }

    $exclamations = substr_count($text, '!');
    if ($exclamations >= 2) { $score += 1; $matched[] = '! ! (urgent tone)'; }

    $upperCount = strlen(preg_replace('/[^A-Z]/', '', $title));
    if (strlen($title) > 6 && $upperCount / max(strlen($title), 1) > 0.6) {
        $score += 1;
        $matched[] = 'ALL CAPS TITLE';
    }

    if ($score >= 6) $priority = 'critical';
    elseif ($score >= 3) $priority = 'high';
    elseif ($score >= 1) $priority = 'medium';
    else $priority = 'low';

    $confidence = min(55 + count(array_unique($matched)) * 8, 96);

    return [
        'priority' => $priority,
        'confidence' => $confidence,
        'matched' => array_slice(array_unique($matched), 0, 3),
        'raw_score' => $score,
    ];
}

function priorityRank(string $priority): int {
    return match ($priority) {
        'critical' => 3,
        'high' => 2,
        'medium' => 1,
        'low' => 0,
        default => 1,
    };
}
