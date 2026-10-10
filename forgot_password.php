<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (currentUser()) redirect('dashboard.php');

$error = '';
$step = 1;
$account = null;

if (isPost()) {
    requireCsrf();
    $action = postStr('action');

    if ($action === 'find') {
        $email = postStr('email');
        $u = $email !== '' ? findUserByEmail($email) : null;
        if (!$u) {
            $error = t('toast_account_not_found');
        } elseif (empty($u['security_question']) || empty($u['security_answer_hash'])) {
            $error = t('toast_no_security_question');
        } else {
            $_SESSION['fp_email'] = $u['email'];
        }
    } elseif ($action === 'reset' && !empty($_SESSION['fp_email'])) {
        $u = findUserByEmail($_SESSION['fp_email']);
        $answer = postStr('security_answer');
        $new = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$u) {
            unset($_SESSION['fp_email']);
            $error = t('toast_account_not_found');
        } elseif (($wait = throttleBlockedSeconds('forgot', $u['email'])) > 0) {
            $error = "Too many attempts. Please wait $wait seconds and try again.";
        } elseif (!verifySecurityAnswer($answer, $u['security_answer_hash'])) {
            throttleFail('forgot', $u['email']);
            $error = t('toast_wrong_security_answer');
        } elseif ($new === '') {
            $error = t('toast_enter_new_password');
        } elseif ($new !== $confirm) {
            $error = t('toast_passwords_dont_match');
        } elseif (($problem = passwordProblem((string)$new)) !== null) {
            $error = $problem;
        } else {
            getDb()->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([hashPassword((string)$new), (int)$u['id']]);
            throttleClear('forgot', $u['email']);
            unset($_SESSION['fp_email']);
            flash('success', t('toast_password_reset_done'));
            redirect('login.php');
        }
    }
}

if (!empty($_SESSION['fp_email'])) {
    $account = findUserByEmail($_SESSION['fp_email']);
    if ($account) $step = 2; else unset($_SESSION['fp_email']);
}
if (isset($_GET['restart'])) { unset($_SESSION['fp_email']); redirect('forgot_password.php'); }

$user = null; $guest = true; $pageTitle = t('forgot_title');
require __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <div class="logo">🔑</div>
    <h2><?= h(t('forgot_title')) ?></h2>
    <p class="muted center small"><?= h(t('forgot_subtitle')) ?></p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <?php if ($step === 1): ?>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="find">
            <label for="email"><?= h(t('login_email_label')) ?></label>
            <input type="email" id="email" name="email" required autofocus placeholder="<?= h(t('login_email_hint')) ?>">
            <button class="btn btn-block" type="submit"><?= h(t('forgot_find_account')) ?></button>
        </form>
    <?php else: ?>
        <p class="small muted"><?= h($account['email']) ?> · <a href="?restart=1">↺</a></p>
        <form method="post">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="reset">
            <label for="security_answer"><?= h(t(securityQuestionLabelKey((string)$account['security_question']))) ?></label>
            <input type="text" id="security_answer" name="security_answer" required autofocus autocomplete="off">
            <label for="new_password"><?= h(t('settings_new_password_hint')) ?></label>
            <input type="password" id="new_password" name="new_password" required minlength="8" autocomplete="new-password">
            <label for="confirm_password"><?= h(t('settings_confirm_password_hint')) ?></label>
            <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password">
            <button class="btn btn-block" type="submit"><?= h(t('forgot_reset_button')) ?></button>
        </form>
    <?php endif; ?>

    <p class="center small mt"><a href="login.php"><?= h(t('register_already_have_account')) ?></a></p>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
