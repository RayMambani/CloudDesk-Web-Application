<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/google_sso.php';

if (isset($_GET['lang'])) { setLanguage((string)$_GET['lang']); redirect('login.php'); }
if (currentUser()) redirect('dashboard.php');

$error = '';
$email = '';
if (isPost()) {
    requireCsrf();
    $email = postStr('email');
    $password = $_POST['password'] ?? '';
    $remember = !empty($_POST['remember']);

    if ($email === '' || $password === '') {
        $error = t('toast_enter_credentials');
    } elseif (($wait = throttleBlockedSeconds('login', $email)) > 0) {
        $error = "Too many attempts. Please wait $wait seconds and try again.";
    } else {
        $user = findUserByEmail($email);
        if ($user && password_verify((string)$password, $user['password'])) {
            if ((int)$user['active'] !== 1) {
                $error = t('toast_account_deactivated');
            } else {
                throttleClear('login', $email);
                loginUser($user, $remember);
                flash('success', t('toast_login_successful'));
                redirect('dashboard.php');
            }
        } else {
            throttleFail('login', $email);
            $error = t('toast_invalid_credentials');
        }
    }
}

$user = null; $guest = true; $pageTitle = t('login_sign_in');
require __DIR__ . '/includes/header.php';
?>
<div class="card auth-card">
    <div class="logo">☁️</div>
    <h2><?= h(t('login_welcome')) ?></h2>
    <p class="muted center small"><?= h(t('login_subtitle')) ?></p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post" autocomplete="on">
        <?= csrfField() ?>
        <label for="email"><?= h(t('login_email_label')) ?></label>
        <input type="email" id="email" name="email" value="<?= h($email) ?>" placeholder="<?= h(t('login_email_hint')) ?>" required autofocus autocomplete="username">

        <label for="password"><?= h(t('login_password_label')) ?></label>
        <input type="password" id="password" name="password" placeholder="<?= h(t('login_password_hint')) ?>" required autocomplete="current-password">

        <div class="row between mb">
            <label class="check" style="margin:0"><input type="checkbox" name="remember" id="remember" value="1"> <?= h(t('login_remember_me')) ?></label>
            <a href="forgot_password.php" class="small"><?= h(t('login_forgot_password')) ?></a>
        </div>

        <button type="submit" class="btn btn-block"><?= h(t('login_sign_in')) ?></button>
    </form>

    <div class="divider"><?= h(t('login_or')) ?></div>

    <button type="button" class="btn btn-secondary btn-block mb" id="googleBtn"
        <?= googleConfigured() ? '' : 'disabled title="Set GOOGLE_CLIENT_ID in config/config.php to enable Google sign-in"' ?>>
        <svg width="18" height="18" viewBox="0 0 48 48" aria-hidden="true"><path fill="#EA4335" d="M24 9.5c3.5 0 6.6 1.2 9.1 3.6l6.8-6.8C35.8 2.4 30.3 0 24 0 14.6 0 6.5 5.4 2.6 13.2l7.9 6.1C12.4 13.6 17.7 9.5 24 9.5z"/><path fill="#4285F4" d="M46.1 24.6c0-1.6-.1-3.1-.4-4.6H24v9h12.4c-.5 2.9-2.2 5.3-4.6 6.9l7.4 5.7c4.3-4 6.9-9.9 6.9-17z"/><path fill="#FBBC05" d="M10.5 28.7a14.5 14.5 0 0 1 0-9.4l-7.9-6.1a24 24 0 0 0 0 21.6l7.9-6.1z"/><path fill="#34A853" d="M24 48c6.5 0 11.9-2.1 15.9-5.8l-7.4-5.7c-2.1 1.4-4.9 2.3-8.5 2.3-6.3 0-11.6-4.1-13.5-9.8l-7.9 6.1C6.5 42.6 14.6 48 24 48z"/></svg>
        <?= h(t('login_google_sign_in')) ?>
    </button>

    <button type="button" class="btn btn-secondary btn-block" id="passkeyBtn" hidden><?= h(t('login_biometric')) ?></button>
    <p class="small muted center" id="authStatus" role="status"></p>

    <p class="center small mt"><a href="register.php"><?= h(t('login_no_account')) ?></a></p>

    <div class="langs">
        <?php foreach (supportedLanguages() as $code => $label): ?>
            <a href="?lang=<?= h($code) ?>" class="<?= $code === currentLanguage() ? 'on' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>

    <p class="center small muted mt">Demo: admin@clouddesk.com / password123</p>
</div>

<script src="js/passkey.js"></script>
<?php if (googleConfigured()): ?><script src="https://accounts.google.com/gsi/client" async defer></script><?php endif; ?>
<script>
window.CD_LOGIN = {
    googleClientId: <?= json_encode(googleConfigured() ? GOOGLE_CLIENT_ID : '') ?>,
    msgNoPasskey: <?= json_encode(t('toast_biometric_unavailable')) ?>,
    msgFailed: <?= json_encode(t('toast_invalid_credentials')) ?>
};
</script>
<script src="js/login.js"></script>
<?php require __DIR__ . '/includes/footer.php'; ?>
