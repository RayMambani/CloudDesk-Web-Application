<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireLogin();
$db = getDb();
$uid = (int)$user['id'];
$errors = [];

if (isPost()) {
    requireCsrf();
    $action = postStr('action');

    if ($action === 'profile') {
        $first = postStr('first_name'); $last = postStr('last_name');
        $phone = postStr('phone');
        $lang = postStr('language', currentLanguage());
        $theme = in_array(postStr('theme'), ['dark', 'light'], true) ? postStr('theme') : 'dark';
        if ($first === '' || $last === '') {
            flash('error', t('toast_name_cant_be_empty'));
        } else {
            $db->prepare('UPDATE users SET first_name=?, last_name=?, phone=?, email_notifications=?, sms_notifications=?, theme=? WHERE id=?')
               ->execute([$first, $last, $phone !== '' ? mb_substr($phone, 0, 30) : null, isset($_POST['email_notifications']) ? 1 : 0, isset($_POST['sms_notifications']) ? 1 : 0, $theme, $uid]);
            setThemeCookie($theme);
            setLanguage($lang, $uid);
            flash('success', t('toast_settings_saved'));
        }
    } elseif ($action === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';
        if (!password_verify((string)$current, $user['password'])) {
            flash('error', t('toast_current_password_incorrect'));
        } elseif ($new === '') {
            flash('error', t('toast_enter_new_password'));
        } elseif ($new !== $confirm) {
            flash('error', t('toast_passwords_dont_match'));
        } elseif (($problem = passwordProblem((string)$new)) !== null) {
            flash('error', $problem);
        } else {
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([hashPassword((string)$new), $uid]);
            flash('success', t('toast_settings_saved'));
        }
    } elseif ($action === 'security') {
        $q = postStr('security_question'); $a = postStr('security_answer');
        if (!in_array($q, SECURITY_QUESTION_KEYS, true) || $a === '') {
            flash('error', t('toast_security_required'));
        } else {
            $db->prepare('UPDATE users SET security_question = ?, security_answer_hash = ? WHERE id = ?')->execute([$q, hashSecurityAnswer($a), $uid]);
            flash('success', t('toast_settings_saved'));
        }
    } elseif ($action === 'passkey_delete') {
        $db->prepare('DELETE FROM passkeys WHERE id = ? AND user_id = ?')->execute([(int)postStr('passkey_id'), $uid]);
        flash('success', t('toast_settings_saved'));
    }
    redirect('settings.php');
}

$pk = $db->prepare('SELECT * FROM passkeys WHERE user_id = ? ORDER BY id');
$pk->execute([$uid]);
$passkeys = $pk->fetchAll();

$pageTitle = t('settings_title');
require __DIR__ . '/includes/header.php';
?>
<h2><?= h(t('settings_title')) ?></h2>

<form method="post">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="profile">

    <div class="card">
        <h3><?= h(t('settings_profile_section')) ?></h3>
        <p class="muted small"><?= h($user['email']) ?> · <?= h(roleLabel($user['role'])) ?></p>
        <div class="field-row">
            <div><label for="first_name"><?= h(t('settings_first_name_hint')) ?></label><input type="text" id="first_name" name="first_name" value="<?= h($user['first_name']) ?>" required></div>
            <div><label for="last_name"><?= h(t('settings_last_name_hint')) ?></label><input type="text" id="last_name" name="last_name" value="<?= h($user['last_name']) ?>" required></div>
        </div>
        <label for="phone"><?= h(t('settings_phone_hint')) ?></label>
        <input type="tel" id="phone" name="phone" value="<?= h($user['phone'] ?? '') ?>" autocomplete="tel">
    </div>

    <div class="card">
        <h3><?= h(t('settings_notification_section')) ?></h3>
        <label class="switch-row"><span><?= h(t('settings_email_notif')) ?></span><input type="checkbox" name="email_notifications" <?= $user['email_notifications'] ? 'checked' : '' ?>></label>
        <label class="switch-row"><span><?= h(t('settings_sms_notif')) ?></span><input type="checkbox" name="sms_notifications" <?= $user['sms_notifications'] ? 'checked' : '' ?>></label>
    </div>

    <div class="card">
        <h3><?= h(t('settings_language_section')) ?></h3>
        <?php foreach (supportedLanguages() as $code => $label): ?>
            <div class="radio-row"><input type="radio" name="language" id="lang_<?= h($code) ?>" value="<?= h($code) ?>" <?= currentLanguage() === $code ? 'checked' : '' ?>><label for="lang_<?= h($code) ?>"><?= h($label) ?></label></div>
        <?php endforeach; ?>
    </div>

    <div class="card">
        <h3><?= h(t('settings_appearance_section')) ?></h3>
        <div class="radio-row"><input type="radio" name="theme" id="theme_dark" value="dark" data-live-theme <?= currentTheme() === 'dark' ? 'checked' : '' ?>><label for="theme_dark"><?= h(t('theme_dark')) ?></label></div>
        <div class="radio-row"><input type="radio" name="theme" id="theme_light" value="light" data-live-theme <?= currentTheme() === 'light' ? 'checked' : '' ?>><label for="theme_light"><?= h(t('theme_light')) ?></label></div>
    </div>

    <button type="submit" class="btn btn-block mb"><?= h(t('settings_save')) ?></button>
</form>

<form method="post" class="card">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="password">
    <h3><?= h(t('settings_change_password_section')) ?></h3>
    <label for="current_password"><?= h(t('settings_current_password_hint')) ?></label>
    <input type="password" id="current_password" name="current_password" autocomplete="current-password" required>
    <div class="field-row">
        <div><label for="new_password"><?= h(t('settings_new_password_hint')) ?></label><input type="password" id="new_password" name="new_password" minlength="8" autocomplete="new-password" required></div>
        <div><label for="confirm_password"><?= h(t('settings_confirm_password_hint')) ?></label><input type="password" id="confirm_password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
    </div>
    <button type="submit" class="btn"><?= h(t('settings_save')) ?></button>
</form>

<div class="card">
    <h3><?= h(t('settings_security_section')) ?></h3>

    <form method="post" class="mb">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="security">
        <label for="security_question"><?= h(t('register_security_question_hint')) ?></label>
        <select id="security_question" name="security_question">
            <?php foreach (SECURITY_QUESTION_KEYS as $k): ?>
                <option value="<?= h($k) ?>" <?= ($user['security_question'] ?? '') === $k ? 'selected' : '' ?>><?= h(t(securityQuestionLabelKey($k))) ?></option>
            <?php endforeach; ?>
        </select>
        <label for="security_answer"><?= h(t('register_security_answer_hint')) ?></label>
        <input type="text" id="security_answer" name="security_answer" autocomplete="off" required>
        <button type="submit" class="btn btn-secondary"><?= h(t('settings_save')) ?></button>
    </form>

    <h3><?= h(t('settings_biometric_login')) ?></h3>
    <?php foreach ($passkeys as $p): ?>
        <form method="post" class="row between" style="padding:6px 0;border-bottom:1px solid var(--border)">
            <?= csrfField() ?><input type="hidden" name="action" value="passkey_delete"><input type="hidden" name="passkey_id" value="<?= (int)$p['id'] ?>">
            <span>🔐 <?= h($p['label']) ?> <span class="muted small">· <?= h(formatDateTime($p['created_at'])) ?></span></span>
            <button class="btn btn-secondary btn-sm" type="submit">✕</button>
        </form>
    <?php endforeach; ?>
    <div class="row mt">
        <button type="button" class="btn btn-secondary" id="enablePasskey" hidden><?= h(t('settings_biometric_login')) ?> +</button>
        <span class="muted small" id="passkeyStatus" role="status"></span>
    </div>
</div>

<script src="js/passkey.js"></script>
<script>
(function () {
    var btn = document.getElementById('enablePasskey'), status = document.getElementById('passkeyStatus');
    if (!(window.cdPasskey && window.cdPasskey.supported())) { status.textContent = <?= json_encode(t('toast_biometric_unavailable')) ?>; return; }
    btn.hidden = false;
    btn.addEventListener('click', function () {
        status.textContent = '';
        window.cdPasskey.register(navigator.platform || '').then(function (res) {
            if (res && res.ok) { window.location.reload(); }
            else { status.textContent = (res && res.error) || <?= json_encode(t('toast_biometric_unavailable')) ?>; }
        }).catch(function () { status.textContent = <?= json_encode(t('toast_biometric_unavailable')) ?>; });
    });
})();
</script>
<?php require __DIR__ . '/includes/footer.php'; ?>
