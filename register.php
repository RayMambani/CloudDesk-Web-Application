<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (currentUser()) redirect('dashboard.php');

$error = '';
$f = ['first_name' => '', 'last_name' => '', 'email' => '', 'department' => '', 'role' => 'user', 'security_question' => 'colour'];

if (isPost()) {
    requireCsrf();
    foreach ($f as $k => $_) { $f[$k] = postStr($k, $f[$k]); }
    $answer = postStr('security_answer');
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    $dept = $f['department'] !== '' ? $f['department'] : 'General';

    if ($f['first_name'] === '' || $f['last_name'] === '' || $f['email'] === '' || $password === '') {
        $error = t('toast_fill_all_fields');
    } elseif (!filter_var($f['email'], FILTER_VALIDATE_EMAIL)) {
        $error = t('toast_registration_failed') . ' (' . t('register_email_hint') . ')';
    } elseif (!in_array($f['role'], ROLE_KEYS, true)) {
        $error = t('toast_fill_all_fields');
    } elseif (!in_array($f['security_question'], SECURITY_QUESTION_KEYS, true) || $answer === '') {
        $error = t('toast_security_required');
    } elseif ($password !== $confirm) {
        $error = t('toast_passwords_dont_match');
    } elseif (($problem = passwordProblem((string)$password)) !== null) {
        $error = $problem;
    } elseif (findUserByEmail($f['email'])) {
        $error = t('toast_user_exists');
    } else {
        getDb()->prepare('INSERT INTO users (first_name,last_name,email,password,department,role,security_question,security_answer_hash,created_at) VALUES (?,?,?,?,?,?,?,?,?)')
            ->execute([$f['first_name'], $f['last_name'], strtolower($f['email']), hashPassword((string)$password), $dept, $f['role'],
                $f['security_question'], hashSecurityAnswer($answer), nowStr()]);
        flash('success', t('toast_registration_successful'));
        redirect('login.php');
    }
}

$user = null; $guest = true; $pageTitle = t('register_title');
require __DIR__ . '/includes/header.php';
?>
<div class="card auth-card" style="max-width:480px">
    <div class="logo">☁️</div>
    <h2><?= h(t('register_title')) ?></h2>
    <p class="muted center small"><?= h(t('register_subtitle')) ?></p>

    <?php if ($error): ?><div class="alert alert-error"><?= h($error) ?></div><?php endif; ?>

    <form method="post">
        <?= csrfField() ?>
        <div class="field-row">
            <div><label for="first_name"><?= h(t('register_first_name_hint')) ?></label>
                <input type="text" id="first_name" name="first_name" value="<?= h($f['first_name']) ?>" required autocomplete="given-name"></div>
            <div><label for="last_name"><?= h(t('register_last_name_hint')) ?></label>
                <input type="text" id="last_name" name="last_name" value="<?= h($f['last_name']) ?>" required autocomplete="family-name"></div>
        </div>

        <label for="email"><?= h(t('register_email_hint')) ?></label>
        <input type="email" id="email" name="email" value="<?= h($f['email']) ?>" required autocomplete="email">

        <label for="department"><?= h(t('register_department_hint')) ?></label>
        <input type="text" id="department" name="department" value="<?= h($f['department']) ?>" placeholder="IT, Sales, Finance, General…">

        <label for="role"><?= h(t('register_account_type_hint')) ?></label>
        <select id="role" name="role">
            <?php foreach (ROLE_KEYS as $r): ?>
                <option value="<?= h($r) ?>" <?= $f['role'] === $r ? 'selected' : '' ?>><?= h(roleLabel($r)) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="security_question"><?= h(t('register_security_question_hint')) ?></label>
        <select id="security_question" name="security_question">
            <?php foreach (SECURITY_QUESTION_KEYS as $k): ?>
                <option value="<?= h($k) ?>" <?= $f['security_question'] === $k ? 'selected' : '' ?>><?= h(t(securityQuestionLabelKey($k))) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="security_answer"><?= h(t('register_security_answer_hint')) ?></label>
        <input type="text" id="security_answer" name="security_answer" required autocomplete="off">

        <div class="field-row">
            <div><label for="password"><?= h(t('register_password_hint')) ?></label>
                <input type="password" id="password" name="password" required minlength="8" autocomplete="new-password"></div>
            <div><label for="confirm_password"><?= h(t('settings_confirm_password_hint')) ?></label>
                <input type="password" id="confirm_password" name="confirm_password" required minlength="8" autocomplete="new-password"></div>
        </div>

        <button type="submit" class="btn btn-block"><?= h(t('register_title')) ?></button>
    </form>

    <p class="center small mt"><a href="login.php"><?= h(t('register_already_have_account')) ?></a></p>
    <div class="langs">
        <?php foreach (supportedLanguages() as $code => $label): ?>
            <a href="login.php?lang=<?= h($code) ?>" class="<?= $code === currentLanguage() ? 'on' : '' ?>"><?= h($label) ?></a>
        <?php endforeach; ?>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>