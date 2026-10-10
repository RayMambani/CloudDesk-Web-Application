<?php
// Email, SMS links and in-app notifications.
//
// sendNotificationEmail() uses PHP's mail(). A stock XAMPP/WAMP install has no mail
// server, so it returns false until you configure one (or swap this single function for
// PHPMailer + an SMTP provider). SMS: smsNotifyLink() builds an sms: link that opens the
// staff member's own phone; a real gateway (Twilio etc.) would replace it.
//
// In-app notifications (the bell in the top bar) are stored in the notifications table:
//   - a technician is notified when a ticket is assigned to them
//   - a ticket owner is notified when their ticket is updated by staff
//   - the assigned technician is notified when the owner comments

function sendNotificationEmail(?string $to, string $subject, string $body): bool {
    if (empty($to)) return false;
    $headers = 'From: ' . MAIL_FROM . "\r\n" . "Content-Type: text/plain; charset=UTF-8\r\n";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, $headers);
}

function smsNotifyLink(?string $phone, string $message): ?string {
    if (empty($phone)) return null;
    return 'sms:' . rawurlencode($phone) . '?body=' . rawurlencode($message);
}

/** Turn 073 123 4561 into +27731234561 (international format, needed by SMS gateways). */
function normalisePhone(?string $phone): ?string {
    if ($phone === null) return null;
    $p = preg_replace('/[^\d+]/', '', $phone);
    if ($p === '' || $p === null) return null;
    if (str_starts_with($p, '+')) return $p;
    if (str_starts_with($p, '00')) return '+' . substr($p, 2);
    if (str_starts_with($p, '0')) return '+' . SMS_COUNTRY_CODE . substr($p, 1);
    return '+' . $p;
}

function smsGatewayConfigured(): bool {
    return TWILIO_ACCOUNT_SID !== '' && TWILIO_AUTH_TOKEN !== '' && TWILIO_FROM_NUMBER !== ''
        && function_exists('curl_init');
}

/** Send a real SMS through Twilio. Returns false if not configured, no phone, or the send failed. */
function sendSms(?string $phone, string $message): bool {
    $to = normalisePhone($phone);
    if ($to === null || !smsGatewayConfigured()) return false;
    $ch = curl_init('https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode(TWILIO_ACCOUNT_SID) . '/Messages.json');
    $opts = [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query(['To' => $to, 'From' => TWILIO_FROM_NUMBER, 'Body' => $message]),
        CURLOPT_USERPWD => TWILIO_ACCOUNT_SID . ':' . TWILIO_AUTH_TOKEN,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ];
    if (CURL_CA_BUNDLE !== '' && file_exists(CURL_CA_BUNDLE)) {
        $opts[CURLOPT_CAINFO] = CURL_CA_BUNDLE;
    }
    curl_setopt_array($ch, $opts);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code >= 200 && $code < 300;
}

/**
 * Email + SMS one person. Security messages (password reset, deactivation) are sent
 * regardless of the person's notification preferences.
 * @return array{email:bool, sms:bool, sms_link:?string}
 */
function deliverToUser(array $target, string $subject, string $emailBody, string $smsBody): array {
    $emailOk = sendNotificationEmail($target['email'] ?? null, $subject, $emailBody);
    $smsOk = sendSms($target['phone'] ?? null, $smsBody);
    // No gateway? Give the admin an sms: link that opens their own phone's messaging app.
    $link = $smsOk ? null : smsNotifyLink(normalisePhone($target['phone'] ?? null), $smsBody);
    return ['email' => $emailOk, 'sms' => $smsOk, 'sms_link' => $link];
}

/** Tell someone an administrator reset their password and what the new one is. */
function notifyPasswordReset(array $target, string $tempPassword): array {
    $name = $target['first_name'];
    $email = "Hi $name,\n\nAn administrator has reset your " . APP_NAME . " password.\n\n"
        . "Your new temporary password: $tempPassword\n\n"
        . "Log in with it and change it straight away under Settings. "
        . "If you were not expecting this, contact your administrator.\n\n— " . APP_NAME;
    $sms = APP_NAME . ": an admin reset your password. Temporary password: $tempPassword  "
        . "Log in and change it under Settings.";
    return deliverToUser($target, APP_NAME . ': your password has been reset', $email, $sms);
}

/** Tell someone their account was deactivated (or turned back on). */
function notifyAccountStatus(array $target, bool $activated): array {
    $name = $target['first_name'];
    if ($activated) {
        $email = "Hi $name,\n\nYour " . APP_NAME . " account has been reactivated. You can log in again.\n\n— " . APP_NAME;
        $sms = APP_NAME . ': your account has been reactivated. You can log in again.';
        $subject = APP_NAME . ': your account has been reactivated';
    } else {
        $email = "Hi $name,\n\nYour " . APP_NAME . " account has been DEACTIVATED by an administrator. "
            . "You will not be able to log in until it is reactivated.\n\n"
            . "If you think this is a mistake, please contact your administrator.\n\n— " . APP_NAME;
        $sms = APP_NAME . ': your account has been DEACTIVATED by an administrator. '
            . 'You cannot log in until it is reactivated. Contact your administrator if this is a mistake.';
        $subject = APP_NAME . ': your account has been deactivated';
    }
    return deliverToUser($target, $subject, $email, $sms);
}

function addNotification(PDO $db, int $userId, string $titleKey, string $body, ?string $link = null): void {
    $st = $db->prepare('INSERT INTO notifications (user_id, title, body, link, is_read, created_at) VALUES (?,?,?,?,0,?)');
    $st->execute([$userId, $titleKey, mb_substr($body, 0, 500), $link, nowStr()]);
}

function notificationTitle(string $stored): string {
    return str_starts_with($stored, 'notif_') ? t($stored) : $stored;
}

function ticketLink(string $ticketId): string {
    return 'ticket_detail.php?id=' . rawurlencode($ticketId);
}

/** Tell a technician (looked up by full name) that a ticket was assigned to them. */
function notifyAssigned(PDO $db, array $ticket, string $assigneeName): void {
    $st = $db->prepare("SELECT * FROM users WHERE role = 'technician' AND active = 1");
    $st->execute();
    foreach ($st->fetchAll() as $tech) {
        if (fullName($tech) === $assigneeName) {
            addNotification($db, (int)$tech['id'], 'notif_new_assignment_title', $ticket['id'] . ' — ' . $ticket['title'], ticketLink($ticket['id']));
            if ((int)$tech['email_notifications'] === 1) {
                sendNotificationEmail($tech['email'], 'Ticket ' . $ticket['id'] . ' assigned to you',
                    "Hi {$tech['first_name']},\n\nTicket {$ticket['id']} ('{$ticket['title']}') has been assigned to you.\n\n— " . APP_NAME);
            }
            return;
        }
    }
}

/** Tell the ticket's owner that staff changed their ticket. */
function notifyOwnerUpdate(PDO $db, array $ticket, int $actorId, string $emailLine): void {
    if ((int)$ticket['user_id'] === $actorId) return;
    addNotification($db, (int)$ticket['user_id'], 'notif_ticket_update_title', $ticket['id'] . ' — ' . $ticket['title'], ticketLink($ticket['id']));
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([(int)$ticket['user_id']]);
    $owner = $st->fetch();
    if ($owner && (int)$owner['email_notifications'] === 1) {
        sendNotificationEmail($owner['email'], 'Update on ticket ' . $ticket['id'],
            "Hi {$owner['first_name']},\n\nAn update on your ticket {$ticket['id']} ('{$ticket['title']}'):\n$emailLine\n\n— " . APP_NAME);
    }
}

function unreadNotificationCount(PDO $db, int $userId): int {
    $st = $db->prepare('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0');
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

function recentNotifications(PDO $db, int $userId, int $limit = 8): array {
    $st = $db->prepare('SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT ' . (int)$limit);
    $st->execute([$userId]);
    return $st->fetchAll();
}