<?php
// Google Gemini for the live chat — used ONLY for administrators and technicians.
// Regular users never reach this code: chat_send.php gives them the built-in default
// replies instead. If Gemini isn't configured or a request fails, the caller also falls
// back to the default replies, so chat never just breaks.

function geminiConfigured(): bool {
    return GEMINI_API_KEY !== '' && GEMINI_API_KEY !== 'PASTE_YOUR_GEMINI_API_KEY_HERE';
}

function clouddeskFaqKnowledge(): string {
    $out = '';
    for ($i = 1; $i <= 6; $i++) {
        $out .= '- ' . tEn("faq_{$i}_title") . ': ' . tEn("faq_{$i}_body") . "\n";
    }
    return $out;
}

function clouddeskSystemPrompt(): string {
    return <<<PROMPT
You are "CloudDesk Support", a helpful assistant embedded in the CloudDesk IT helpdesk
ticketing system's live chat. You are talking to a member of the IT support staff
(a technician or administrator).

Guidelines:
- Answer clearly and briefly — 2 to 4 short sentences, no long essays.
- Help with troubleshooting guidance (login issues, slow performance, common software,
  network and hardware problems) and with how to use CloudDesk itself.
- You do NOT have access to real accounts, ticket history or live system status — never
  invent ticket numbers, statuses or account details.
- Stay on topic: IT support and CloudDesk. Politely redirect anything unrelated.
- Reply in the same language the person writes in.

Help-centre knowledge you can rely on:
PROMPT
    . "\n" . clouddeskFaqKnowledge();
}

/**
 * @param array  $history Prior chat_messages rows (from_support, message), oldest first.
 * @return string|null    The reply, or null when it could not be obtained.
 */
function callGeminiForReply(array $history, string $newUserMessage): ?string {
    if (!geminiConfigured() || !function_exists('curl_init')) return null;

    $contents = [];
    foreach (array_slice($history, -10) as $m) {
        $contents[] = ['role' => $m['from_support'] ? 'model' : 'user', 'parts' => [['text' => $m['message']]]];
    }
    $contents[] = ['role' => 'user', 'parts' => [['text' => $newUserMessage]]];

    $payload = [
        'system_instruction' => ['parts' => [['text' => clouddeskSystemPrompt()]]],
        'contents' => $contents,
        'generationConfig' => ['maxOutputTokens' => 400, 'temperature' => 0.6],
    ];

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode(GEMINI_MODEL) . ':generateContent');
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-goog-api-key: ' . GEMINI_API_KEY],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 20,
    ];
    if (CURL_CA_BUNDLE !== '' && file_exists(CURL_CA_BUNDLE)) {
        $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200 || !$response) {
        error_log("Gemini call failed (HTTP $code): $err " . substr((string)$response, 0, 300));
        return null;
    }
    $data = json_decode($response, true);
    $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return $text ? trim($text) : null;
}
