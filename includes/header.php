<?php
/**
 * Shared page header. Set these before including:
 *   $user      logged-in user row, or null for guest pages (login/register/forgot)
 *   $pageTitle optional page title
 *   $guest     true for centred guest pages
 */
$user = $user ?? null;
$guest = $guest ?? ($user === null);
$pageTitle = $pageTitle ?? '';
$unread = $user ? unreadNotificationCount(getDb(), (int)$user['id']) : 0;
$navItems = [];
if ($user) {
    $navItems[] = ['dashboard.php', t('nav_dashboard'), ['dashboard.php', 'ticket_detail.php', 'submit_ticket.php']];
    $navItems[] = ['chat.php', t('nav_chat'), ['chat.php']];
    $navItems[] = ['analytics.php', t('nav_analytics'), ['analytics.php']];
    if ($user['role'] === 'admin') {
        $navItems[] = ['performance_reports.php', t('nav_performance'), ['performance_reports.php']];
        $navItems[] = ['users.php', t('nav_users'), ['users.php']];
    }
    $navItems[] = ['faq.php', t('nav_faq'), ['faq.php']];
}
$current = basename($_SERVER['SCRIPT_NAME'] ?? '');
?><!DOCTYPE html>
<html lang="<?= h(currentLanguage()) ?>" data-theme="<?= h(currentTheme()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="color-scheme" content="dark light">
    <title><?= $pageTitle ? h($pageTitle) . ' · ' : '' ?><?= h(APP_NAME) ?></title>
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'%3E%3Ctext y='.9em' font-size='90'%3E%E2%98%81%EF%B8%8F%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="css/style.css">
</head>
<body class="<?= $guest ? 'guest' : 'app' ?>">
<div id="offlineBanner" class="banner banner-warn" hidden><?= h(t('offline_banner')) ?></div>

<?php if ($user): ?>
<header class="topbar">
    <a class="brand" href="dashboard.php">☁️ <?= h(APP_NAME) ?></a>
    <button class="icon-btn nav-toggle" id="navToggle" aria-label="Menu" aria-expanded="false">☰</button>
    <nav class="mainnav" id="mainnav" aria-label="Main">
        <?php foreach ($navItems as [$href, $label, $matches]): ?>
            <a href="<?= h($href) ?>" class="<?= in_array($current, $matches, true) ? 'active' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </nav>
    <div class="nav-right">
        <?php if ($user && $user['role'] === 'user'): ?>
            <a class="btn btn-sm hide-sm" href="submit_ticket.php">+ <?= h(t('submit_ticket_title')) ?></a>
        <?php endif; ?>

        <div class="menu-wrap">
            <button class="icon-btn" id="bellBtn" aria-label="<?= h(t('nav_notifications')) ?>" aria-haspopup="true">
                🔔<span class="dot" id="bellCount" <?= $unread ? '' : 'hidden' ?>><?= $unread > 99 ? '99+' : $unread ?></span>
            </button>
            <div class="dropdown dropdown-wide" id="bellMenu" hidden>
                <div class="dropdown-head"><strong><?= h(t('nav_notifications')) ?></strong>
                    <a href="notifications.php"><?= h(t('notif_view_all')) ?></a></div>
                <div id="bellList">
                    <?php $recent = recentNotifications(getDb(), (int)$user['id'], 6); ?>
                    <?php foreach ($recent as $n): ?>
                        <a class="note <?= $n['is_read'] ? '' : 'unread' ?>" href="notifications.php?open=<?= (int)$n['id'] ?>">
                            <span class="note-title"><?= h(notificationTitle($n['title'])) ?></span>
                            <span class="note-body"><?= h($n['body']) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$recent): ?><p class="muted pad"><?= h(t('notif_none')) ?></p><?php endif; ?>
                </div>
            </div>
        </div>

        <button class="icon-btn" id="themeBtn" aria-label="Theme" title="<?= h(t('settings_appearance_section')) ?>">◐</button>

        <div class="menu-wrap">
            <button class="avatar" id="avatarBtn" aria-haspopup="true" aria-label="<?= h(fullName($user)) ?>"><?= h(strtoupper(mb_substr($user['first_name'], 0, 1))) ?></button>
            <div class="dropdown" id="avatarMenu" hidden>
                <div class="dropdown-head stack">
                    <strong><?= h(fullName($user)) ?></strong>
                    <span class="muted small"><?= h(roleLabel($user['role'])) ?></span>
                </div>
                <a href="settings.php"><?= h(t('nav_settings')) ?></a>
                <a href="notifications.php"><?= h(t('nav_notifications')) ?></a>
                <a href="faq.php"><?= h(t('nav_faq')) ?></a>
                <a href="logout.php" id="logoutLink" data-title="<?= h(t('logout_dialog_title')) ?>" data-message="<?= h(t('logout_dialog_message')) ?>"><?= h(t('nav_logout')) ?></a>
            </div>
        </div>
    </div>
</header>
<?php endif; ?>

<main class="<?= $guest ? 'guest-wrap' : 'container' ?>">
<?php foreach (takeFlashes() as $f): ?>
    <div class="alert alert-<?= h($f['type']) ?>" role="status"><?= h($f['message']) ?></div>
<?php endforeach; ?>