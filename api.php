<?php
// CloudDesk mobile API — the online backend for the Android app.
//
//   POST  api.php   JSON body {"action": "...", ...}     header  X-Auth-Token: <token>
//
// Every request/response is JSON. Passwords are verified here (bcrypt) and never leave the
// server. After sign-in the app receives a random token; only its SHA-256 hash is stored
// in the api_tokens table. Almost every action returns the caller's up-to-date data
// ("sync" payload) so the app can refresh its local cache in one step.

define('CD_API', true);
ini_set('display_errors', '0');
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/gemini_client.php';
require_once __DIR__ . '/includes/google_sso.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function apiOut(array $data, int $code = 200): never {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function apiFail(string $error, int $code = 400, array $extra = []): never {
    apiOut(['ok' => false, 'error' => $error] + $extra, $code);
}
set_exception_handler(function (Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    apiFail('server_error', 500);
});

$db = getDb();
foreach (apiSchemaStatements(dbDriver()) as $sql) { $db->exec($sql); }

// ---- request ------------------------------------------------------------------
$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = [];
$action = (string)($in['action'] ?? ($_GET['action'] ?? ''));
$lang = (string)($in['lang'] ?? '');
if (isset(SUPPORTED_LANGUAGE_LIST[$lang])) $_COOKIE['cd_lang'] = $lang; // replies come back in the app's language

function inStr(string $key, int $max = 1000): string {
    global $in;
    $v = $in[$key] ?? '';
    return is_string($v) ? mb_substr(trim($v), 0, $max) : '';
}
function apiTime(?string $v): ?string { return $v ? substr($v, 0, 16) : null; }

// ---- tokens & throttling ------------------------------------------------------
function apiIssueToken(PDO $db, int $uid, string $kind = 'session'): string {
    $token = bin2hex(random_bytes(32));
    $db->prepare('INSERT INTO api_tokens (user_id, token_hash, kind, created_at) VALUES (?,?,?,?)')
       ->execute([$uid, hash('sha256', $token), $kind, nowStr()]);
    return $token;
}
function apiTokenFromRequest(bool $allowQuery = false): string {
    $t = $_SERVER['HTTP_X_AUTH_TOKEN'] ?? '';
    if ($t === '' && $allowQuery) $t = (string)($_GET['token'] ?? '');
    return is_string($t) ? $t : '';
}
function apiAuthUser(PDO $db, bool $allowQuery = false): ?array {
    $t = apiTokenFromRequest($allowQuery);
    if ($t === '') return null;
    $st = $db->prepare("SELECT u.* FROM api_tokens a JOIN users u ON u.id = a.user_id WHERE a.token_hash = ? AND a.kind = 'session'");
    $st->execute([hash('sha256', $t)]);
    $u = $st->fetch();
    if (!$u || (int)$u['active'] !== 1) return null;
    $db->prepare('UPDATE api_tokens SET last_used_at = ? WHERE token_hash = ?')->execute([nowStr(), hash('sha256', $t)]);
    return $u;
}
function apiRequireUser(PDO $db, bool $allowQuery = false): array {
    $u = apiAuthUser($db, $allowQuery);
    if (!$u) apiFail('unauthorized', 401);
    return $u;
}
function apiRequireRole(array $user, array $roles): void {
    if (!in_array($user['role'], $roles, true)) apiFail('forbidden', 403);
}
function apiRevokeUserTokens(PDO $db, int $uid): void {
    $db->prepare('DELETE FROM api_tokens WHERE user_id = ?')->execute([$uid]);
}
function apiThrottleBlocked(PDO $db, string $key): int {
    $st = $db->prepare('SELECT until_ts FROM api_throttle WHERE k = ?');
    $st->execute([$key]);
    $until = (int)$st->fetchColumn();
    return $until > time() ? $until - time() : 0;
}
function apiThrottleFail(PDO $db, string $key): void {
    $st = $db->prepare('SELECT fails FROM api_throttle WHERE k = ?');
    $st->execute([$key]);
    $row = $st->fetch();
    $fails = $row ? (int)$row['fails'] + 1 : 1;
    $until = 0;
    if ($fails >= 5) { $until = time() + 60; $fails = 0; }
    if ($row) $db->prepare('UPDATE api_throttle SET fails = ?, until_ts = ? WHERE k = ?')->execute([$fails, $until, $key]);
    else $db->prepare('INSERT INTO api_throttle (k, fails, until_ts) VALUES (?,?,?)')->execute([$key, $fails, $until]);
}
function apiThrottleClear(PDO $db, string $key): void {
    $db->prepare('DELETE FROM api_throttle WHERE k = ?')->execute([$key]);
}

// ---- JSON shapes (field names match the Android Room entities) ----------------
function apiUserJson(array $u): array {
    return [
        'id' => (int)$u['id'], 'firstName' => $u['first_name'], 'lastName' => $u['last_name'],
        'email' => $u['email'], 'department' => $u['department'], 'role' => $u['role'],
        'phone' => $u['phone'], 'active' => (int)$u['active'] === 1,
        'securityQuestion' => $u['security_question'],
    ];
}
function apiTicketJson(array $t): array {
    return [
        'id' => $t['id'], 'title' => $t['title'], 'description' => $t['description'],
        'status' => $t['status'], 'priority' => $t['priority'], 'category' => $t['category'],
        'created_at' => apiTime($t['created_at']), 'userId' => (int)$t['user_id'],
        'assignedTo' => $t['assigned_to'], 'attachmentName' => $t['attachment_name'],
        'hasAttachment' => !empty($t['attachment_path']),
        'resolvedAt' => apiTime($t['resolved_at']),
        'rating' => $t['rating'] === null ? null : (int)$t['rating'], 'ratingComment' => $t['rating_comment'],
    ];
}

/** Everything this user is allowed to see. */
function apiSyncPayload(PDO $db, array $me): array {
    $staff = isStaff($me);
    $uid = (int)$me['id'];

    if ($staff) {
        $users = $db->query('SELECT * FROM users ORDER BY role, first_name')->fetchAll();
        $tickets = $db->query('SELECT * FROM tickets ORDER BY created_at DESC')->fetchAll();
        $comments = $db->query('SELECT * FROM comments ORDER BY id')->fetchAll();
        $history = $db->query('SELECT * FROM ticket_history ORDER BY id')->fetchAll();
    } else {
        $users = [$me];
        $st = $db->prepare('SELECT * FROM tickets WHERE user_id = ? ORDER BY created_at DESC'); $st->execute([$uid]); $tickets = $st->fetchAll();
        $st = $db->prepare('SELECT c.* FROM comments c JOIN tickets t ON t.id = c.ticket_id WHERE t.user_id = ? ORDER BY c.id'); $st->execute([$uid]); $comments = $st->fetchAll();
        $st = $db->prepare('SELECT h.* FROM ticket_history h JOIN tickets t ON t.id = h.ticket_id WHERE t.user_id = ? ORDER BY h.id'); $st->execute([$uid]); $history = $st->fetchAll();
    }
    $st = $db->prepare('SELECT * FROM chat_messages WHERE user_id = ? ORDER BY id'); $st->execute([$uid]); $chat = $st->fetchAll();

    return [
        'me' => apiUserJson($me),
        'users' => array_map('apiUserJson', $users),
        'tickets' => array_map('apiTicketJson', $tickets),
        'comments' => array_map(fn($c) => [
            'id' => (int)$c['id'], 'ticketId' => $c['ticket_id'], 'userId' => (int)($c['user_id'] ?? 0),
            'userName' => $c['user_name'], 'content' => $c['content'], 'created_at' => apiTime($c['created_at']),
        ], $comments),
        'history' => array_map(fn($h) => [
            'id' => (int)$h['id'], 'ticketId' => $h['ticket_id'], 'actorName' => $h['actor_name'], 'field' => $h['field'],
            'oldValue' => $h['old_value'], 'newValue' => $h['new_value'], 'createdAt' => apiTime($h['created_at']),
        ], $history),
        'chat' => array_map(fn($m) => [
            'id' => (int)$m['id'], 'userId' => (int)$m['user_id'], 'senderName' => $m['sender_name'],
            'message' => $m['message'], 'fromSupport' => (int)$m['from_support'] === 1,
            'timestamp' => date('H:i', strtotime($m['created_at'])),
        ], $chat),
        'serverTime' => nowStr(),
    ];
}
function apiReload(PDO $db, int $uid): array {
    $st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$uid]);
    return $st->fetch();
}
function apiRespond(PDO $db, array $me, array $extra = []): never {
    apiOut(['ok' => true] + $extra + apiSyncPayload($db, apiReload($db, (int)$me['id'])));
}
function apiTempPassword(): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}
function apiLoadTicket(PDO $db, string $id): ?array {
    $st = $db->prepare('SELECT * FROM tickets WHERE id = ?');
    $st->execute([cleanTicketId($id)]);
    return $st->fetch() ?: null;
}
function apiDefaultReply(string $text): string {
    $x = mb_strtolower($text);
    $has = function (string ...$w) use ($x) { foreach ($w as $s) if (str_contains($x, $s)) return true; return false; };
    if ($has('password', 'log in', 'login')) return t('chat_reply_login');
    if ($has('urgent', 'asap', 'critical', 'down')) return t('chat_reply_urgent');
    if ($has('ticket') && $has('status', 'update')) return t('chat_reply_ticket_status');
    if ($has('thank')) return t('chat_reply_thanks');
    if (trim($x) === 'hi' || $has('hello', 'hey')) return t('chat_reply_greeting');
    return t('chat_reply_default');
}
function apiLoginResponse(PDO $db, array $user, array $extra = []): never {
    $token = apiIssueToken($db, (int)$user['id']);
    apiOut(['ok' => true, 'token' => $token] + $extra + apiSyncPayload($db, $user));
}

// ====================================================================================
// Public actions (no token needed)
// ====================================================================================
switch ($action) {
    case 'ping':
        apiOut(['ok' => true, 'app' => APP_NAME, 'time' => nowStr()]);

    case 'login': {
        $email = strtolower(inStr('email', 191));
        $password = (string)($in['password'] ?? '');
        if ($email === '' || $password === '') apiFail('missing_fields');
        $key = 'login:' . $email;
        if ($wait = apiThrottleBlocked($db, $key)) apiFail('throttled', 429, ['retryAfter' => $wait]);
        $user = findUserByEmail($email);
        if (!$user || !password_verify($password, $user['password'])) {
            apiThrottleFail($db, $key);
            apiFail('invalid_credentials', 401);
        }
        apiThrottleClear($db, $key);
        if ((int)$user['active'] !== 1) apiFail('deactivated', 403);
        apiLoginResponse($db, $user);
    }

    case 'google_login': {
        // The app sends the ID token Google gave it. We verify it with Google and check it
        // was issued for OUR web client ID. Never trust an email address sent by the app.
        $idToken = inStr('idToken', 4000);
        if (!googleConfigured() || $idToken === '') apiFail('google_not_configured', 400);
        $info = googleHttpGet('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken));
        if (!$info || !googleTokenInfoValid($info, GOOGLE_CLIENT_ID)) apiFail('google_invalid', 401);
        $user = googleFindOrCreateUser(strtolower((string)$info['email']), (string)($info['given_name'] ?? 'New'), (string)($info['family_name'] ?? 'User'));
        if ((int)$user['active'] !== 1) apiFail('deactivated', 403);
        apiLoginResponse($db, $user);
    }

    case 'biometric_login': {
        $device = inStr('deviceToken', 200);
        if ($device === '') apiFail('unauthorized', 401);
        $st = $db->prepare("SELECT u.* FROM api_tokens a JOIN users u ON u.id = a.user_id WHERE a.token_hash = ? AND a.kind = 'device'");
        $st->execute([hash('sha256', $device)]);
        $user = $st->fetch();
        if (!$user) apiFail('unauthorized', 401);
        if ((int)$user['active'] !== 1) apiFail('deactivated', 403);
        apiLoginResponse($db, $user);
    }

    case 'register': {
        $first = inStr('firstName', 100); $last = inStr('lastName', 100);
        $email = strtolower(inStr('email', 191)); $password = (string)($in['password'] ?? '');
        $dept = inStr('department', 100) ?: 'General';
        $question = inStr('securityQuestion', 30); $answer = inStr('securityAnswer', 200);
        if ($first === '' || $last === '' || $email === '' || $password === '') apiFail('missing_fields');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) apiFail('invalid_email');
        if (passwordProblem($password)) apiFail('weak_password');
        if (!in_array($question, SECURITY_QUESTION_KEYS, true) || $answer === '') apiFail('security_required');
        if (findUserByEmail($email)) apiFail('exists', 409);
        // Self-registration ALWAYS creates a normal user; only an admin can promote someone.
        insertAndGetId($db,
            'INSERT INTO users (first_name, last_name, email, password, department, role, security_question, security_answer_hash, created_at) VALUES (?,?,?,?,?,?,?,?,?)',
            [$first, $last, $email, hashPassword($password), $dept, 'user', $question, hashSecurityAnswer($answer), nowStr()]);
        apiOut(['ok' => true]);
    }

    case 'forgot_question': {
        $user = findUserByEmail(inStr('email', 191));
        if (!$user) apiFail('not_found', 404);
        if (empty($user['security_question']) || empty($user['security_answer_hash'])) apiFail('no_security_question');
        apiOut(['ok' => true, 'question' => $user['security_question']]);
    }

    case 'forgot_reset': {
        $email = strtolower(inStr('email', 191));
        $key = 'reset:' . $email;
        if ($wait = apiThrottleBlocked($db, $key)) apiFail('throttled', 429, ['retryAfter' => $wait]);
        $user = findUserByEmail($email);
        if (!$user || empty($user['security_answer_hash'])) apiFail('not_found', 404);
        if (!verifySecurityAnswer((string)($in['answer'] ?? ''), $user['security_answer_hash'])) {
            apiThrottleFail($db, $key);
            apiFail('wrong_answer', 401);
        }
        $new = (string)($in['newPassword'] ?? '');
        if (passwordProblem($new)) apiFail('weak_password');
        apiThrottleClear($db, $key);
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([hashPassword($new), (int)$user['id']]);
        apiRevokeUserTokens($db, (int)$user['id']);
        apiOut(['ok' => true]);
    }
}

// ====================================================================================
// Signed-in actions
// ====================================================================================
$me = apiRequireUser($db, $action === 'attachment');
$myName = fullName($me);
$staff = isStaff($me);

switch ($action) {
    case 'logout': {
        $db->prepare("DELETE FROM api_tokens WHERE token_hash = ? AND kind = 'session'")->execute([hash('sha256', apiTokenFromRequest())]);
        apiOut(['ok' => true]);
    }

    case 'sync':
        apiRespond($db, $me);

    case 'device_token': { // used for fingerprint sign-in on this phone
        $db->prepare("DELETE FROM api_tokens WHERE user_id = ? AND kind = 'device'")->execute([(int)$me['id']]);
        apiOut(['ok' => true, 'deviceToken' => apiIssueToken($db, (int)$me['id'], 'device')]);
    }
    case 'revoke_device_tokens': {
        $db->prepare("DELETE FROM api_tokens WHERE user_id = ? AND kind = 'device'")->execute([(int)$me['id']]);
        apiOut(['ok' => true]);
    }

    // ---------------------------------------------------------------- tickets
    case 'ticket_create': {
        $title = inStr('title', 255); $desc = inStr('description', 5000);
        if ($title === '' || $desc === '') apiFail('missing_fields');
        $category = strtolower(inStr('category', 50)); if (!in_array($category, CATEGORY_KEYS, true)) $category = 'other';
        $priority = strtolower(inStr('priority', 20)); if (!in_array($priority, PRIORITY_KEYS, true)) $priority = 'medium';

        do {
            $id = 'TCK-' . random_int(1000, 99999);
            $chk = $db->prepare('SELECT 1 FROM tickets WHERE id = ?'); $chk->execute([$id]);
        } while ($chk->fetchColumn());

        $attPath = null; $attName = null;
        $b64 = $in['attachmentBase64'] ?? null;
        if (is_string($b64) && $b64 !== '') {
            $bytes = base64_decode($b64, true);
            $orig = basename(inStr('attachmentName', 200) ?: 'file');
            $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
            if ($bytes === false) apiFail('bad_attachment');
            if (strlen($bytes) > UPLOAD_MAX_BYTES) apiFail('attachment_too_large');
            if (!in_array($ext, UPLOAD_ALLOWED_EXT, true)) apiFail('attachment_type_not_allowed');
            $dir = __DIR__ . '/uploads';
            if (!is_dir($dir) && !mkdir($dir, 0755, true)) apiFail('server_error', 500);
            $stored = bin2hex(random_bytes(12)) . '.' . $ext;
            if (file_put_contents($dir . '/' . $stored, $bytes) === false) apiFail('server_error', 500);
            $attPath = 'uploads/' . $stored;
            $attName = mb_substr(preg_replace('/[^\p{L}\p{N}._ -]/u', '_', $orig), 0, 200);
        }

        $db->prepare("INSERT INTO tickets (id,title,description,status,priority,category,created_at,user_id,attachment_path,attachment_name)
                      VALUES (?,?,?,'open',?,?,?,?,?,?)")
           ->execute([$id, $title, $desc, $priority, $category, nowStr(), (int)$me['id'], $attPath, $attName]);
        logHistory($db, $id, $myName, 'created');
        foreach ($db->query("SELECT id FROM users WHERE role = 'admin' AND active = 1")->fetchAll() as $adm) {
            if ((int)$adm['id'] !== (int)$me['id']) addNotification($db, (int)$adm['id'], 'notif_new_ticket_title', $id . ' — ' . $title, ticketLink($id));
        }
        apiRespond($db, $me, ['ticketId' => $id]);
    }

    case 'ticket_update': {
        apiRequireRole($me, ['admin', 'technician']);
        $ticket = apiLoadTicket($db, inStr('id', 64));
        if (!$ticket) apiFail('not_found', 404);

        $newStatus = inStr('status', 20); if (!in_array($newStatus, STATUS_KEYS, true)) $newStatus = $ticket['status'];
        $newPriority = strtolower(inStr('priority', 20)); if (!in_array($newPriority, PRIORITY_KEYS, true)) $newPriority = $ticket['priority'];
        $newAssignee = $ticket['assigned_to'];
        if ($me['role'] === 'admin' && array_key_exists('assignee', $in)) { // only admins assign
            $wanted = inStr('assignee', 150);
            $newAssignee = null;
            if ($wanted !== '') {
                foreach ($db->query("SELECT first_name, last_name FROM users WHERE role = 'technician' AND active = 1")->fetchAll() as $tech) {
                    if (fullName($tech) === $wanted) $newAssignee = $wanted;
                }
            }
        }
        $resolvedAt = $ticket['resolved_at'];
        if (isResolvedStatus($newStatus)) $resolvedAt = $resolvedAt ?: nowStr(); else $resolvedAt = null;

        $db->prepare('UPDATE tickets SET status=?, priority=?, assigned_to=?, resolved_at=? WHERE id=?')
           ->execute([$newStatus, $newPriority, $newAssignee, $resolvedAt, $ticket['id']]);

        $lines = [];
        if ($newStatus !== $ticket['status']) { logHistory($db, $ticket['id'], $myName, 'status', $ticket['status'], $newStatus); $lines[] = 'Status: ' . $newStatus; }
        if ($newPriority !== $ticket['priority']) { logHistory($db, $ticket['id'], $myName, 'priority', $ticket['priority'], $newPriority); $lines[] = 'Priority: ' . $newPriority; }
        if (($newAssignee ?? '') !== ($ticket['assigned_to'] ?? '')) {
            logHistory($db, $ticket['id'], $myName, 'assignee', $ticket['assigned_to'], $newAssignee);
            $lines[] = 'Assigned to: ' . ($newAssignee ?? '—');
        }
        $updated = apiLoadTicket($db, $ticket['id']);
        if ($lines) {
            if ($newAssignee && $newAssignee !== $ticket['assigned_to']) notifyAssigned($db, $updated, $newAssignee);
            notifyOwnerUpdate($db, $updated, (int)$me['id'], implode("\n", $lines));
        }
        apiRespond($db, $me);
    }

    case 'ticket_rate': {
        $ticket = apiLoadTicket($db, inStr('id', 64));
        if (!$ticket) apiFail('not_found', 404);
        if ((int)$ticket['user_id'] !== (int)$me['id']) apiFail('forbidden', 403);
        if (!isResolvedStatus($ticket['status']) || $ticket['rating'] !== null) apiFail('not_allowed', 409);
        $stars = (int)($in['rating'] ?? 0);
        if ($stars < 1 || $stars > 5) apiFail('bad_rating');
        $db->prepare('UPDATE tickets SET rating = ?, rating_comment = ? WHERE id = ?')
           ->execute([$stars, inStr('comment', 1000) ?: null, $ticket['id']]);
        logHistory($db, $ticket['id'], $myName, 'rating', null, (string)$stars);
        apiRespond($db, $me);
    }

    case 'comment_add': {
        $ticket = apiLoadTicket($db, inStr('ticketId', 64));
        if (!$ticket) apiFail('not_found', 404);
        if (!$staff && (int)$ticket['user_id'] !== (int)$me['id']) apiFail('forbidden', 403);
        $text = inStr('content', 2000);
        if ($text === '') apiFail('missing_fields');
        $db->prepare('INSERT INTO comments (ticket_id, user_id, user_name, content, created_at) VALUES (?,?,?,?,?)')
           ->execute([$ticket['id'], (int)$me['id'], $myName, $text, nowStr()]);
        if ($staff) {
            notifyOwnerUpdate($db, $ticket, (int)$me['id'], "$myName commented: $text");
        } elseif ($ticket['assigned_to']) {
            foreach ($db->query("SELECT * FROM users WHERE role = 'technician' AND active = 1")->fetchAll() as $tech) {
                if (fullName($tech) === $ticket['assigned_to']) {
                    addNotification($db, (int)$tech['id'], 'notif_ticket_update_title', $ticket['id'] . ' — ' . $ticket['title'], ticketLink($ticket['id']));
                }
            }
        }
        apiRespond($db, $me);
    }

    case 'attachment': { // GET api.php?action=attachment&id=TCK-1001  (token in header or ?token=)
        $ticket = apiLoadTicket($db, (string)($_GET['id'] ?? ''));
        if (!$ticket || empty($ticket['attachment_path'])) apiFail('not_found', 404);
        if (!$staff && (int)$ticket['user_id'] !== (int)$me['id']) apiFail('forbidden', 403);
        $file = __DIR__ . '/' . $ticket['attachment_path'];
        if (!is_file($file)) apiFail('not_found', 404);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $types = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp', 'pdf' => 'application/pdf', 'txt' => 'text/plain', 'csv' => 'text/csv', 'log' => 'text/plain'];
        header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
        header('Content-Disposition: attachment; filename="' . addslashes((string)$ticket['attachment_name']) . '"');
        header('Content-Length: ' . filesize($file));
        header('X-Content-Type-Options: nosniff');
        readfile($file);
        exit;
    }

    // ---------------------------------------------------------------- chat
    case 'server_info': {
        $driver = dbDriver();
        $host = '(local file)';
        $dbUrl = getenv('DATABASE_URL');
        if ($dbUrl) $host = parse_url($dbUrl, PHP_URL_HOST) . ' / ' . ltrim((string)parse_url($dbUrl, PHP_URL_PATH), '/');
        elseif ($driver === 'mysql') $host = DB_HOST . ' / ' . DB_NAME;
        $engine = ['pgsql' => 'PostgreSQL', 'mysql' => 'MySQL', 'sqlite' => 'SQLite'][$driver] ?? $driver;
        $isAdmin = $me['role'] === 'admin';
        $order = ['users' => 'id', 'tickets' => 'created_at', 'comments' => 'id', 'ticket_history' => 'id',
                  'chat_messages' => 'id', 'notifications' => 'id', 'passkeys' => 'id', 'api_tokens' => 'id', 'api_throttle' => 'k'];
        $secret = ['password', 'security_answer_hash', 'token_hash', 'public_key', 'credential_id'];
        $publicCols = ['id','status','priority','category','role','active','kind','created_at','resolved_at','ticket_id','user_id','field','from_support','is_read','rating','fails','until_ts','sign_count','last_used_at'];
        $ast = $db->prepare('SELECT kind, COUNT(*) AS c, MAX(last_used_at) AS lu FROM api_tokens WHERE user_id = ? GROUP BY kind');
        $ast->execute([(int)$me['id']]);
        $sess = 0; $dev = 0; $lastUsed = '';
        foreach ($ast->fetchAll() as $row) {
            if ($row['kind'] === 'device') $dev = (int)$row['c']; else { $sess = (int)$row['c']; $lastUsed = (string)($row['lu'] ?? ''); }
        }
        $auth = [
            'service' => 'CloudDesk authentication (api.php)',
            'signedInAs' => $me['email'], 'role' => $me['role'],
            'passwordStorage' => 'bcrypt hash', 'tokenStorage' => 'SHA-256 hash only',
            'activeSessions' => $sess, 'fingerprintDevices' => $dev, 'lastUsed' => $lastUsed,
            'googleSignIn' => googleConfigured(), 'lockout' => '5 failed logins = 60 second lock',
        ];
        $tables = [];
        foreach ($order as $name => $by) {
            try {
                $count = (int)$db->query("SELECT COUNT(*) FROM $name")->fetchColumn();
                $rows = $db->query("SELECT * FROM $name ORDER BY $by DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            } catch (Throwable $e) { $count = 0; $rows = []; }
            $out = [];
            foreach ($rows as $r) {
                $line = [];
                foreach ($r as $c => $v) {
                    $v = (string)($v ?? '');
                    if (!$isAdmin && !in_array($c, $publicCols, true)) $v = $v === '' ? '' : '••• hidden';
                    elseif (in_array($c, $secret, true) && $v !== '') $v = substr($v, 0, 12) . '… (hidden)';
                    elseif (mb_strlen($v) > 40) $v = mb_substr($v, 0, 40) . '…';
                    $line[$c] = $v;
                }
                $out[] = $line;
            }
            $tables[] = ['name' => $name, 'count' => $count, 'rows' => $out];
        }
        apiOut(['ok' => true, 'engine' => $engine, 'host' => $host, 'serverTime' => nowStr(), 'canSeeAll' => $isAdmin, 'auth' => $auth, 'tables' => $tables]);
    }
    case 'chat_send': {
        $text = inStr('message', 1000);
        if ($text === '') apiFail('missing_fields');
        $uid = (int)$me['id'];
        $cnt = $db->prepare('SELECT COUNT(*) FROM chat_messages WHERE user_id = ?'); $cnt->execute([$uid]);
        if ((int)$cnt->fetchColumn() === 0) {
            $db->prepare('INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,1,?)')
               ->execute([$uid, t('chat_support_name'), t('chat_greeting_format', $me['first_name'] . ' ' . $me['last_name']), nowStr()]);
        }
        $h = $db->prepare('SELECT message, from_support FROM chat_messages WHERE user_id = ? ORDER BY id ASC'); $h->execute([$uid]);
        $history = $h->fetchAll();
        $db->prepare('INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,0,?)')
           ->execute([$uid, $myName, $text, nowStr()]);
        // Gemini answers admins and technicians only; everyone else gets the built-in replies.
        $reply = $staff ? callGeminiForReply($history, $text) : null;
        $reply = $reply ?? apiDefaultReply($text);
        $db->prepare('INSERT INTO chat_messages (user_id, sender_name, message, from_support, created_at) VALUES (?,?,?,1,?)')
           ->execute([$uid, t('chat_support_name'), $reply, nowStr()]);
        apiRespond($db, $me);
    }

    // ---------------------------------------------------------------- my profile
    case 'profile_update': {
        $first = inStr('firstName', 100); $last = inStr('lastName', 100);
        if ($first === '' || $last === '') apiFail('missing_fields');
        $phone = inStr('phone', 30) ?: null;
        $newPw = (string)($in['newPassword'] ?? ''); $curPw = (string)($in['currentPassword'] ?? '');
        $pwHash = $me['password'];
        if ($newPw !== '' || $curPw !== '') {
            if (!password_verify($curPw, $me['password'])) apiFail('wrong_current_password', 401);
            if (passwordProblem($newPw)) apiFail('weak_password');
            $pwHash = hashPassword($newPw);
        }
        $db->prepare('UPDATE users SET first_name = ?, last_name = ?, phone = ?, password = ? WHERE id = ?')
           ->execute([$first, $last, $phone, $pwHash, (int)$me['id']]);
        apiRespond($db, $me);
    }

    // ---------------------------------------------------------------- admin: user management
    case 'admin_user_add': {
        apiRequireRole($me, ['admin']);
        $first = inStr('firstName', 100); $last = inStr('lastName', 100); $email = strtolower(inStr('email', 191));
        $role = inStr('role', 20);
        if ($first === '' || $last === '' || $email === '') apiFail('missing_fields');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) apiFail('invalid_email');
        if (!in_array($role, ROLE_KEYS, true)) apiFail('bad_role');
        if (findUserByEmail($email)) apiFail('exists', 409);
        $temp = apiTempPassword();
        insertAndGetId($db,
            'INSERT INTO users (first_name, last_name, email, password, department, role, created_at) VALUES (?,?,?,?,?,?,?)',
            [$first, $last, $email, hashPassword($temp), 'General', $role, nowStr()]);
        apiRespond($db, $me, ['tempPassword' => $temp]);
    }

    case 'admin_user_role':
    case 'admin_user_active':
    case 'admin_user_reset': {
        apiRequireRole($me, ['admin']);
        $target = (int)($in['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM users WHERE id = ?'); $st->execute([$target]);
        $tu = $st->fetch();
        if (!$tu) apiFail('not_found', 404);

        if ($action === 'admin_user_reset') {
            $temp = apiTempPassword();
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([hashPassword($temp), $target]);
            apiRevokeUserTokens($db, $target);
            apiRespond($db, $me, ['tempPassword' => $temp]);
        }
        if ($target === (int)$me['id']) apiFail('cannot_change_self', 409); // an admin can't demote/deactivate themselves
        if ($action === 'admin_user_role') {
            $role = inStr('role', 20);
            if (!in_array($role, ROLE_KEYS, true)) apiFail('bad_role');
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $target]);
        } else {
            $active = !empty($in['active']) ? 1 : 0;
            $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$active, $target]);
            if (!$active) apiRevokeUserTokens($db, $target); // signed out everywhere immediately
        }
        apiRespond($db, $me);
    }

    default:
        apiFail('unknown_action', 404);
}