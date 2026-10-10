<?php
// CloudDesk Web — settings.
//
// Put your own secrets in config/config.local.php (copy config.local.example.php).
// That file is loaded FIRST, so anything defined there wins, and it is meant to
// stay out of GitHub (see .gitignore). Never commit real API keys.

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}

function cfgDefine(string $name, $value): void {
    if (!defined($name)) {
        define($name, $value);
    }
}

// ---- Database (local XAMPP/WAMP MySQL defaults) -----------------------------
cfgDefine('DB_HOST', '127.0.0.1'); // 127.0.0.1 avoids Windows resolving "localhost" to IPv6
cfgDefine('DB_PORT', '3307');      // Must match the MySQL port shown in the XAMPP Control Panel
cfgDefine('DB_NAME', 'clouddesk');
cfgDefine('DB_USER', 'root');
cfgDefine('DB_PASS', ''); // XAMPP's root password is blank by default

// ---- Gemini AI (live chat, staff only) ---------------------------------------
// Free key: https://aistudio.google.com/apikey
cfgDefine('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: 'AQ.Ab8RN6Is_PJtVw7I8MYLXRHb8_zCGB2RRUvoSM2ZpxL8dDYKBg');
// If you get "model not found", check https://ai.google.dev/gemini-api/docs/models
cfgDefine('GEMINI_MODEL', getenv('GEMINI_MODEL') ?: 'gemini-3.8-flash');
// On XAMPP/Windows PHP often has no CA bundle, which makes HTTPS calls fail with
// "SSL certificate problem". The proper fix is to download cacert.pem from
// https://curl.se/ca/cacert.pem and set curl.cainfo in php.ini, or set the path here:
cfgDefine('CURL_CA_BUNDLE', getenv('CURL_CA_BUNDLE') ?: '');

// ---- Google single sign-on (Sign in with Google) ----------------------------
// Create a "Web application" OAuth client in Google Cloud Console, add
// http://localhost as an Authorized JavaScript origin, and paste its client ID here.
cfgDefine('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '350970218855-j2m3i3t22u40nr1isrlfmapde2g3om7v.apps.googleusercontent.com');

// ---- SMS (optional, via Twilio) ---------------------------------------------
// Used to text people their new password and to tell them their account was
// deactivated. Leave these blank and the admin gets a "send from my phone" link instead.
// Free trial account + number: https://www.twilio.com/try-twilio
cfgDefine('TWILIO_ACCOUNT_SID', getenv('TWILIO_ACCOUNT_SID') ?: '');
cfgDefine('TWILIO_AUTH_TOKEN', getenv('TWILIO_AUTH_TOKEN') ?: '');
cfgDefine('TWILIO_FROM_NUMBER', getenv('TWILIO_FROM_NUMBER') ?: ''); // e.g. +1415XXXXXXX
// Numbers saved like 073 123 4561 are converted to +27731234561 using this code.
cfgDefine('SMS_COUNTRY_CODE', '27');

// ---- Email ------------------------------------------------------------------
cfgDefine('MAIL_FROM', 'CloudDesk Support <no-reply@clouddesk.local>');

// ---- Uploads ----------------------------------------------------------------
cfgDefine('UPLOAD_MAX_BYTES', 5 * 1024 * 1024);
cfgDefine('UPLOAD_ALLOWED_EXT', ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'zip', 'log']);

// ---- Misc -------------------------------------------------------------------
cfgDefine('APP_NAME', 'CloudDesk');
cfgDefine('SESSION_REMEMBER_DAYS', 30);
cfgDefine('SUPPORTED_LANGUAGE_LIST', ['en' => 'English', 'nso' => 'Sepedi', 've' => 'Tshivenda', 'ts' => 'Xitsonga']);