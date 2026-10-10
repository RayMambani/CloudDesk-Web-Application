<?php
// Security questions for "Forgot password". Questions are stored as KEYS (never the
// translated text); the answer is stored hashed, normalised to lowercase/trimmed so
// "Blue", " blue " and "BLUE" all match.

const SECURITY_QUESTION_KEYS = ['colour', 'pet', 'city', 'school'];

function securityQuestionLabelKey(string $key): string {
    return 'sq_' . $key;
}

function normalizeSecurityAnswer(string $answer): string {
    return mb_strtolower(trim($answer), 'UTF-8');
}

function hashSecurityAnswer(string $answer): string {
    return password_hash(normalizeSecurityAnswer($answer), PASSWORD_BCRYPT);
}

function verifySecurityAnswer(string $answer, ?string $hash): bool {
    if (!$hash) return false;
    return password_verify(normalizeSecurityAnswer($answer), $hash);
}
