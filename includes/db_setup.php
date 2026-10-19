<?php
// Creates the tables (if missing) and fills them with demo data (if the users table
// is empty). Runs automatically from getDb(); the same DDL is exported to schema.sql /
// schema_postgres.sql by tools/export_schema.php so you can also import it by hand.

require_once __DIR__ . '/security_questions.php';

/** @return string[] CREATE TABLE / INDEX statements for the given driver. */
function schemaStatements(string $driver): array {
    $pk = match ($driver) {
        'pgsql' => 'SERIAL PRIMARY KEY',
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        default => 'INT AUTO_INCREMENT PRIMARY KEY',
    };
    $dt = $driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
    $big = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';

    $s = [];
    $s[] = "CREATE TABLE IF NOT EXISTS users (
        id $pk,
        first_name VARCHAR(100) NOT NULL,
        last_name VARCHAR(100) NOT NULL,
        email VARCHAR(191) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        department VARCHAR(100) NOT NULL DEFAULT 'General',
        role VARCHAR(20) NOT NULL DEFAULT 'user',
        phone VARCHAR(30) NULL,
        email_notifications SMALLINT NOT NULL DEFAULT 1,
        sms_notifications SMALLINT NOT NULL DEFAULT 0,
        active SMALLINT NOT NULL DEFAULT 1,
        security_question VARCHAR(30) NULL,
        security_answer_hash VARCHAR(255) NULL,
        theme VARCHAR(10) NOT NULL DEFAULT 'dark',
        language VARCHAR(10) NOT NULL DEFAULT 'en',
        created_at $dt NOT NULL
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS tickets (
        id VARCHAR(64) PRIMARY KEY,
        title VARCHAR(255) NOT NULL,
        description TEXT NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'open',
        priority VARCHAR(20) NOT NULL DEFAULT 'medium',
        category VARCHAR(100) NOT NULL,
        created_at $dt NOT NULL,
        user_id INT NOT NULL,
        assigned_to VARCHAR(150) NULL,
        attachment_path VARCHAR(255) NULL,
        attachment_name VARCHAR(255) NULL,
        resolved_at $dt NULL,
        rating SMALLINT NULL,
        rating_comment TEXT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS comments (
        id $pk,
        ticket_id VARCHAR(64) NOT NULL,
        user_id INT NULL,
        user_name VARCHAR(150) NOT NULL,
        content TEXT NOT NULL,
        created_at $dt NOT NULL,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS ticket_history (
        id $pk,
        ticket_id VARCHAR(64) NOT NULL,
        actor_name VARCHAR(150) NOT NULL,
        field VARCHAR(30) NOT NULL,
        old_value VARCHAR(150) NULL,
        new_value VARCHAR(150) NULL,
        created_at $dt NOT NULL,
        FOREIGN KEY (ticket_id) REFERENCES tickets(id) ON DELETE CASCADE
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS chat_messages (
        id $pk,
        user_id INT NOT NULL,
        sender_name VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        from_support SMALLINT NOT NULL DEFAULT 0,
        created_at $dt NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS notifications (
        id $pk,
        user_id INT NOT NULL,
        title VARCHAR(150) NOT NULL,
        body VARCHAR(500) NOT NULL,
        link VARCHAR(255) NULL,
        is_read SMALLINT NOT NULL DEFAULT 0,
        created_at $dt NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$tail";

    $s[] = "CREATE TABLE IF NOT EXISTS passkeys (
        id $pk,
        user_id INT NOT NULL,
        credential_id VARCHAR(255) NOT NULL UNIQUE,
        public_key TEXT NOT NULL,
        sign_count $big NOT NULL DEFAULT 0,
        label VARCHAR(100) NOT NULL DEFAULT 'This device',
        created_at $dt NOT NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )$tail";

    $s[] = 'CREATE INDEX idx_tickets_user ON tickets(user_id)';
    $s[] = 'CREATE INDEX idx_tickets_status ON tickets(status)';
    $s[] = 'CREATE INDEX idx_comments_ticket ON comments(ticket_id)';
    $s[] = 'CREATE INDEX idx_history_ticket ON ticket_history(ticket_id)';
    $s[] = 'CREATE INDEX idx_chat_user ON chat_messages(user_id)';
    $s[] = 'CREATE INDEX idx_notif_user ON notifications(user_id, is_read)';
    return $s;
}

function ensureSchemaAndSeed(PDO $db): void {
    static $done = false;
    if ($done) return;
    $done = true;

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);
    $exists = true;
    try {
        $db->query('SELECT 1 FROM passkeys LIMIT 1');
        $db->query('SELECT 1 FROM users LIMIT 1');
    } catch (PDOException $e) {
        $exists = false;
    }

    if (!$exists) {
        foreach (schemaStatements($driver) as $sql) {
            try {
                $db->exec($sql);
            } catch (PDOException $e) {
                // CREATE INDEX has no IF NOT EXISTS on MySQL; an "already exists" error is fine.
                if (stripos($sql, 'CREATE INDEX') !== 0) throw $e;
            }
        }
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($count === 0) {
        seedDemoData($db);
    }
}

function seedAgo(int $days, string $time = '09:00'): string {
    return date('Y-m-d', strtotime("-$days days")) . ' ' . $time . ':00';
}

/**
 * Demo data (12+ rows in every table that holds records). Every demo account uses the
 * password "password123" and the security answer "blue" (question: favourite colour).
 */
function seedDemoData(PDO $db): void {
    $hash = password_hash('password123', PASSWORD_BCRYPT);
    $answerHash = hashSecurityAnswer('blue');
    $now = date('Y-m-d H:i:s');

    $users = [
        ['Shantel', 'Mshabi', 'admin@clouddesk.com', 'IT', 'admin', '0821234567'],
        ['Ompha', 'Khumalo', 'ompha.admin@clouddesk.com', 'IT', 'admin', '0821234568'],
        ['John', 'Mokoena', 'john.tech@clouddesk.com', 'IT Support', 'technician', '0731234561'],
        ['Lisa', 'Mabatha', 'lisa.tech@clouddesk.com', 'IT Support', 'technician', '0731234562'],
        ['Michael', 'Dlamini', 'michael.tech@clouddesk.com', 'Network Ops', 'technician', '0731234563'],
        ['Grace', 'Mahlangu', 'grace@company.com', 'Finance', 'user', '0611112222'],
        ['Thabo', 'Sithole', 'thabo@company.com', 'Sales', 'user', '0611112223'],
        ['Amanda', 'Botha', 'amanda@company.com', 'HR', 'user', null],
        ['Sipho', 'Ngcobo', 'sipho@company.com', 'Marketing', 'user', '0611112225'],
        ['Emma', 'Msane', 'emma@company.com', 'Operations', 'user', null],
        ['Kagiso', 'Molefe', 'kagiso@company.com', 'Legal', 'user', '0611112227'],
        ['Zanele', 'Cele', 'zanele@company.com', 'Design', 'user', null],
    ];
    $ins = $db->prepare('INSERT INTO users (first_name,last_name,email,password,department,role,phone,security_question,security_answer_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $ids = [];
    $names = [];
    foreach ($users as $u) {
        $ins->execute([$u[0], $u[1], $u[2], $hash, $u[3], $u[4], $u[5], 'colour', $answerHash, seedAgo(40)]);
        $ids[] = (int)$db->lastInsertId();
        $names[] = $u[0] . ' ' . $u[1];
    }
    // Postgres has no lastInsertId without a sequence name — resolve ids by email instead.
    $idByEmail = [];
    foreach ($db->query('SELECT id, email FROM users')->fetchAll() as $r) { $idByEmail[$r['email']] = (int)$r['id']; }
    $ids = array_map(fn($u) => $idByEmail[$u[2]], $users);

    [$admin1, , $techId1, $techId2, $techId3, $grace, $thabo, $amanda, $sipho, $emma, $kagiso, $zanele] = $ids;
    $tech1 = $names[2]; $tech2 = $names[3]; $tech3 = $names[4];

    $tickets = [
        ['TCK-1001', 'Cannot access shared drive', "I can't open the Finance shared drive since this morning, getting an access denied error.", 'open', 'high', 'network', seedAgo(1, '08:30'), $grace, null, null],
        ['TCK-1002', "Laptop won't turn on", 'My laptop is completely dead, tried charging overnight and still nothing happens when I press power.', 'open', 'critical', 'hardware', seedAgo(0, '07:15'), $thabo, null, null],
        ['TCK-1003', 'Outlook keeps crashing', 'Outlook closes itself every few minutes, losing unsent emails. Started after the last update.', 'progress', 'high', 'software', seedAgo(6, '10:00'), $amanda, $tech1, null],
        ['TCK-1004', 'Request for new monitor', 'Could I please get a second monitor for my desk to improve productivity.', 'open', 'low', 'hardware', seedAgo(2, '13:20'), $sipho, null, null],
        ['TCK-1005', 'VPN disconnects randomly', 'The VPN drops every 10-15 minutes while working from home, have to keep reconnecting.', 'progress', 'medium', 'network', seedAgo(4, '09:45'), $emma, $tech3, null],
        ['TCK-1006', 'Password reset not working', 'The forgot password email never arrives, checked spam folder already.', 'resolved', 'high', 'account/access', seedAgo(9, '08:00'), $kagiso, $tech2, seedAgo(8, '10:15')],
        ['TCK-1007', 'Printer offline in Marketing', 'The floor printer shows offline for the whole team since yesterday.', 'resolved', 'medium', 'hardware', seedAgo(11, '11:30'), $zanele, $tech1, seedAgo(10, '14:30')],
        ['TCK-1008', 'Server down urgent - all users affected', 'Production server appears to be down, nobody in the department can log in, this is urgent.', 'closed', 'critical', 'other', seedAgo(19, '15:00'), $grace, $tech3, seedAgo(19, '16:45')],
        ['TCK-1009', 'Software license expired', 'Adobe license shows expired, cannot export any client files today.', 'closed', 'medium', 'software', seedAgo(24, '10:10'), $thabo, $tech2, seedAgo(23, '09:00')],
        ['TCK-1010', 'How do I submit expense reports', 'New employee here, not sure how to submit my travel expense claim through the system.', 'closed', 'low', 'other', seedAgo(30, '14:00'), $amanda, $tech1, seedAgo(30, '15:20')],
        ['TCK-1011', 'Two-factor authentication locked me out', "Lost my phone so I can't approve the 2FA prompt, completely locked out of my account.", 'open', 'critical', 'account/access', seedAgo(0, '06:30'), $sipho, null, null],
        ['TCK-1012', 'Slow internet connection all week', 'Internet has been extremely slow all week, pages take a long time to load across the whole office.', 'progress', 'medium', 'network', seedAgo(1, '08:30'), $emma, $tech3, null],
    ];
    $ratings = [
        'TCK-1006' => [5, 'Quick and friendly, thank you!'],
        'TCK-1007' => [4, 'Fixed the same day.'],
        'TCK-1008' => [5, 'Handled very professionally under pressure.'],
        'TCK-1009' => [3, 'Worked in the end but took a while.'],
        'TCK-1010' => [4, 'Clear explanation.'],
    ];
    $tIns = $db->prepare('INSERT INTO tickets (id,title,description,status,priority,category,created_at,user_id,assigned_to,resolved_at,rating,rating_comment) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    foreach ($tickets as $t) {
        $r = $ratings[$t[0]] ?? [null, null];
        $tIns->execute([$t[0], $t[1], $t[2], $t[3], $t[4], $t[5], $t[6], $t[7], $t[8], $t[9], $r[0], $r[1]]);
    }

    $comments = [
        ['TCK-1003', $techId1, $tech1, "Looking into this now — can you tell me which Outlook version you're on?", seedAgo(5, '14:10')],
        ['TCK-1003', $amanda, 'Amanda Botha', "It's the latest one from the Office 365 update this week.", seedAgo(5, '14:25')],
        ['TCK-1005', $techId3, $tech3, 'Can you share your router model? Might be a firmware issue on your end.', seedAgo(3, '11:00')],
        ['TCK-1005', $emma, 'Emma Msane', "It's a TP-Link Archer, bought about a year ago.", seedAgo(3, '11:20')],
        ['TCK-1006', $techId2, $tech2, 'Reset the account manually on our end — please try logging in again.', seedAgo(8, '10:10')],
        ['TCK-1006', $kagiso, 'Kagiso Molefe', 'That worked, thank you so much!', seedAgo(8, '10:15')],
        ['TCK-1007', $techId1, $tech1, 'Replaced the network cable on the printer, should be back online now.', seedAgo(10, '14:25')],
        ['TCK-1007', $zanele, 'Zanele Cele', "Confirmed, it's printing again. Thanks!", seedAgo(10, '14:30')],
        ['TCK-1008', $techId3, $tech3, 'Identified a failed disk on the primary server, switching over to backup now.', seedAgo(19, '16:10')],
        ['TCK-1008', $admin1, 'Shantel Mshabi', 'Please prioritise this, whole department is blocked.', seedAgo(19, '16:20')],
        ['TCK-1008', $techId3, $tech3, 'Failover complete, all users should have access again.', seedAgo(19, '16:45')],
        ['TCK-1009', $techId2, $tech2, 'Renewed the license, should reflect within a few minutes.', seedAgo(23, '09:00')],
    ];
    $cIns = $db->prepare('INSERT INTO comments (ticket_id,user_id,user_name,content,created_at) VALUES (?,?,?,?,?)');
    foreach ($comments as $c) { $cIns->execute($c); }

    $nameById = array_combine($ids, $names);
    $hIns = $db->prepare('INSERT INTO ticket_history (ticket_id,actor_name,field,old_value,new_value,created_at) VALUES (?,?,?,?,?,?)');
    foreach ($tickets as $t) {
        $hIns->execute([$t[0], $nameById[$t[7]], 'created', null, null, $t[6]]);
        if ($t[8]) {
            $hIns->execute([$t[0], $names[0], 'assignee', null, $t[8], $t[6]]);
            if ($t[3] !== 'open') $hIns->execute([$t[0], $t[8], 'status', 'open', 'progress', $t[6]]);
            if (in_array($t[3], ['resolved', 'closed'], true)) $hIns->execute([$t[0], $t[8], 'status', 'progress', $t[3], $t[9] ?? $t[6]]);
        }
        if (isset($ratings[$t[0]])) $hIns->execute([$t[0], $nameById[$t[7]], 'rating', null, (string)$ratings[$t[0]][0], $t[9] ?? $t[6]]);
    }

    $chat = [
        [$grace, 'CloudDesk Support', "Hi Grace! 👋 I'm your CloudDesk support assistant. Tell me what you're running into and I'll help.", 1, '09:00'],
        [$grace, 'Grace Mahlangu', "Hi, my password isn't working today", 0, '09:01'],
        [$grace, 'CloudDesk Support', "For login issues, try the 'Forgot Password' link on the sign-in screen first. If that doesn't work, I can raise a ticket for our Auth team — want me to do that?", 1, '09:01'],
        [$grace, 'Grace Mahlangu', 'Yes please, that would help', 0, '09:02'],
        [$grace, 'CloudDesk Support', 'Got it, thanks for the details. A technician will follow up shortly. In the meantime, feel free to submit a ticket from the dashboard so we can track this properly.', 1, '09:02'],
        [$grace, 'Grace Mahlangu', 'Thank you!', 0, '09:03'],
        [$thabo, 'CloudDesk Support', "Hi Thabo! 👋 I'm your CloudDesk support assistant. Tell me what you're running into and I'll help.", 1, '13:40'],
        [$thabo, 'Thabo Sithole', "my laptop won't switch on at all", 0, '13:41'],
        [$thabo, 'CloudDesk Support', "That sounds urgent — I'd recommend submitting a ticket right away so it gets flagged as high priority automatically. I'm also looping in a technician now.", 1, '13:41'],
        [$thabo, 'Thabo Sithole', 'already did, ticket TCK-1002', 0, '13:42'],
        [$thabo, 'CloudDesk Support', 'Perfect, I can see it in the queue. A technician will be in touch shortly.', 1, '13:42'],
        [$thabo, 'Thabo Sithole', 'thanks for the quick help', 0, '13:43'],
    ];
    $chIns = $db->prepare('INSERT INTO chat_messages (user_id,sender_name,message,from_support,created_at) VALUES (?,?,?,?,?)');
    foreach ($chat as $m) { $chIns->execute([$m[0], $m[1], $m[2], $m[3], seedAgo(1, $m[4])]); }

    $notes = [
        [$techId1, 'notif_new_assignment_title', 'TCK-1003 — Outlook keeps crashing', 'ticket_detail.php?id=TCK-1003', 0, seedAgo(6, '10:05')],
        [$techId3, 'notif_new_assignment_title', 'TCK-1005 — VPN disconnects randomly', 'ticket_detail.php?id=TCK-1005', 0, seedAgo(4, '09:50')],
        [$techId3, 'notif_new_assignment_title', 'TCK-1012 — Slow internet connection all week', 'ticket_detail.php?id=TCK-1012', 0, seedAgo(1, '08:35')],
        [$techId2, 'notif_new_assignment_title', 'TCK-1006 — Password reset not working', 'ticket_detail.php?id=TCK-1006', 1, seedAgo(9, '08:05')],
        [$techId1, 'notif_new_assignment_title', 'TCK-1007 — Printer offline in Marketing', 'ticket_detail.php?id=TCK-1007', 1, seedAgo(11, '11:35')],
        [$kagiso, 'notif_ticket_update_title', 'TCK-1006 — Password reset not working', 'ticket_detail.php?id=TCK-1006', 1, seedAgo(8, '10:15')],
        [$zanele, 'notif_ticket_update_title', 'TCK-1007 — Printer offline in Marketing', 'ticket_detail.php?id=TCK-1007', 1, seedAgo(10, '14:30')],
        [$amanda, 'notif_ticket_update_title', 'TCK-1003 — Outlook keeps crashing', 'ticket_detail.php?id=TCK-1003', 0, seedAgo(6, '10:10')],
        [$emma, 'notif_ticket_update_title', 'TCK-1005 — VPN disconnects randomly', 'ticket_detail.php?id=TCK-1005', 0, seedAgo(4, '09:55')],
        [$emma, 'notif_ticket_update_title', 'TCK-1012 — Slow internet connection all week', 'ticket_detail.php?id=TCK-1012', 0, seedAgo(1, '08:40')],
        [$admin1, 'notif_new_ticket_title', 'TCK-1011 — Two-factor authentication locked me out', 'ticket_detail.php?id=TCK-1011', 0, seedAgo(0, '06:35')],
        [$admin1, 'notif_new_ticket_title', 'TCK-1002 — Laptop won\'t turn on', 'ticket_detail.php?id=TCK-1002', 0, seedAgo(0, '07:20')],
    ];
    $nIns = $db->prepare('INSERT INTO notifications (user_id,title,body,link,is_read,created_at) VALUES (?,?,?,?,?,?)');
    foreach ($notes as $n) { $nIns->execute($n); }
}

/** Tables used by the mobile-app API (api.php): login tokens and login throttling. */
function apiSchemaStatements(string $driver): array {
    $pk = match ($driver) {
        'pgsql' => 'SERIAL PRIMARY KEY',
        'sqlite' => 'INTEGER PRIMARY KEY AUTOINCREMENT',
        default => 'INT AUTO_INCREMENT PRIMARY KEY',
    };
    $dt = $driver === 'pgsql' ? 'TIMESTAMP' : 'DATETIME';
    $big = $driver === 'sqlite' ? 'INTEGER' : 'BIGINT';
    $tail = $driver === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
    return [
        "CREATE TABLE IF NOT EXISTS api_tokens (
            id $pk,
            user_id INT NOT NULL,
            token_hash VARCHAR(64) NOT NULL UNIQUE,
            kind VARCHAR(10) NOT NULL DEFAULT 'session',
            created_at $dt NOT NULL,
            last_used_at $dt NULL,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        )$tail",
        "CREATE TABLE IF NOT EXISTS api_throttle (
            k VARCHAR(191) PRIMARY KEY,
            fails INT NOT NULL DEFAULT 0,
            until_ts $big NOT NULL DEFAULT 0
        )$tail",
    ];
}