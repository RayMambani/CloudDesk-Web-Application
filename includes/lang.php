<?php
// Multi-language support: English, Sepedi, Tshivenda and Xitsonga.
// The translation files in lang/ use the SAME key names as the Android app's strings.xml.
// A key missing from a language falls back to English, then to the key itself.

function supportedLanguages(): array { return SUPPORTED_LANGUAGE_LIST; }

function currentLanguage(): string {
    $code = $_SESSION['language'] ?? ($_COOKIE['cd_lang'] ?? 'en');
    return (is_string($code) && isset(SUPPORTED_LANGUAGE_LIST[$code])) ? $code : 'en';
}

function setLanguage(string $code, ?int $userId = null): void {
    if (!isset(SUPPORTED_LANGUAGE_LIST[$code])) return;
    $_SESSION['language'] = $code;
    if (!headers_sent()) {
        setcookie('cd_lang', $code, ['expires' => time() + 365 * 86400, 'path' => '/', 'samesite' => 'Lax']);
    }
    $_COOKIE['cd_lang'] = $code;
    if ($userId) {
        $st = getDb()->prepare('UPDATE users SET language = ? WHERE id = ?');
        $st->execute([$code, $userId]);
    }
}

function loadLangFile(string $code): array {
    static $cache = [];
    if (!isset($cache[$code])) {
        $file = __DIR__ . '/../lang/' . $code . '.php';
        $cache[$code] = preg_match('/^[a-z]{2,3}$/', $code) && file_exists($file) ? (require $file) : [];
    }
    return $cache[$code];
}

/**
 * t('key') or t('key', $arg1, $arg2) — translate a key. Format placeholders use the
 * same %1$s / %2$d syntax as Android, which PHP's sprintf understands natively.
 */
function t(string $key, ...$args): string {
    $text = loadLangFile(currentLanguage())[$key] ?? loadLangFile('en')[$key] ?? $key;
    if ($args || str_contains($text, '%%')) {
        try {
            return vsprintf($text, $args);
        } catch (\Throwable $e) {
            return $text;
        }
    }
    return $text;
}

/** English text of a key, regardless of the user's language (used for the AI prompt). */
function tEn(string $key): string {
    return loadLangFile('en')[$key] ?? $key;
}
