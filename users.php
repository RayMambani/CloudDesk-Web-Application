<?php
require_once __DIR__ . '/includes/bootstrap.php';

$user = requireRole(['admin']);
$db = getDb();
$meId = (int)$user['id'];

function loadUserById(PDO $db, int $id): ?array {
    $st = $db->prepare('SELECT * FROM users WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

if (isPost()) {
    requireCsrf();
    $action = postStr('action');
    $targetId = (int)postStr('user_id');

    if ($action === 'add_user') {
        $first = postStr('first_name'); $last = postStr('last_name'); $email = strtolower(postStr('email'));
        $dept = postStr('department') ?: 'General';
        $role = in_array(postStr('role'), ROLE_KEYS, true) ? postStr('role') : 'user';
        $pass = $_POST['password'] ?? '';
        if ($first === '' || $last === '' || $email === '' || $pass === '') {
            flash('error', t('toast_fill_all_fields'));
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', t('toast_registration_failed'));
        } elseif (findUserByEmail($email)) {
            flash('error', t('toast_user_exists'));
        } elseif (($problem = passwordProblem((string)$pass)) !== null) {
            flash('error', $problem);
        } else {
            $db->prepare('INSERT INTO users (first_name,last_name,email,password,department,role,created_at) VALUES (?,?,?,?,?,?,?)')
               ->execute([$first, $last, $email, hashPassword((string)$pass), $dept, $role, nowStr()]);
            flash('success', t('toast_user_updated'));
        }
        redirect('users.php');
    }

    $target = $targetId ? loadUserById($db, $targetId) : null;
    if (!$target) { flash('error', t('toast_account_not_found')); redirect('users.php'); }

    if (in_array($action, ['set_role', 'toggle_active'], true) && $targetId === $meId) {
        flash('error', t('toast_cant_change_self'));
        redirect('users.php');
    }

    if ($action === 'set_role') {
        $role = postStr('role');
        if (in_array($role, ROLE_KEYS, true)) {
            $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute([$role, $targetId]);
            flash('success', t('toast_user_updated'));
        }
    } elseif ($action === 'toggle_active') {
        $newActive = (int)$target['active'] === 1 ? 0 : 1;
        $db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([$newActive, $targetId]);
        flash('success', t('toast_user_updated'));
        $_SESSION['delivery'] = ['name' => fullName($target), 'kind' => $newActive === 1 ? 'activated' : 'deactivated']
            + notifyAccountStatus($target, $newActive === 1);
    } elseif ($action === 'reset_password') {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
        $temp = '';
        for ($i = 0; $i < 10; $i++) { $temp .= $alphabet[random_int(0, strlen($alphabet) - 1)]; }
        $db->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([hashPassword($temp), $targetId]);
        $_SESSION['temp_password'] = ['name' => fullName($target), 'password' => $temp];
        $_SESSION['delivery'] = ['name' => fullName($target), 'kind' => 'password'] + notifyPasswordReset($target, $temp);
    }
    redirect('users.php');
}

$q = getStr('q');
$users = $db->query('SELECT * FROM users ORDER BY CASE role WHEN \'admin\' THEN 0 WHEN \'technician\' THEN 1 ELSE 2 END, first_name, last_name')->fetchAll();
if ($q !== '') {
    $users = array_values(array_filter($users, fn($u) => str_contains(mb_strtolower(fullName($u) . ' ' . $u['email'] . ' ' . $u['department']), mb_strtolower($q))));
}
$temp = $_SESSION['temp_password'] ?? null;
unset($_SESSION['temp_password']);
$delivery = $_SESSION['delivery'] ?? null;
unset($_SESSION['delivery']);

$pageTitle = t('users_title');
require __DIR__ . '/includes/header.php';
?>
<div class="row between mb">
    <div><h2 style="margin:0"><?= h(t('users_title')) ?></h2><p class="muted small" style="margin:0"><?= h(t('users_hint')) ?></p></div>
</div>

<?php if ($temp): ?>
<div class="card" style="border-color:var(--warning)">
    <h3><?= h(t('user_temp_password_title')) ?></h3>
    <p class="muted small"><?= h($temp['name']) ?></p>
    <div class="temp-pass"><?= h($temp['password']) ?></div>
    <p class="muted small mt"><?= nl2br(h(t('user_temp_password_message_format', $temp['name'], $temp['password']))) ?></p>
</div>
<?php endif; ?>

<?php if ($delivery): ?>
<div class="card">
    <h3>
        <?php if ($delivery['kind'] === 'password'): ?>New password: notifying <?= h($delivery['name']) ?>
        <?php elseif ($delivery['kind'] === 'deactivated'): ?>Account deactivated: notifying <?= h($delivery['name']) ?>
        <?php else: ?>Account reactivated: notifying <?= h($delivery['name']) ?><?php endif; ?>
    </h3>
    <p style="margin:6px 0">
        ✉️ Email:
        <?php if ($delivery['email']): ?><strong>sent</strong>
        <?php else: ?><strong>not sent</strong> <span class="muted small">(no mail server configured, or no email address)</span><?php endif; ?>
    </p>
    <p style="margin:6px 0">
        💬 SMS:
        <?php if ($delivery['sms']): ?><strong>sent</strong>
        <?php elseif ($delivery['sms_link']): ?><strong>not sent automatically</strong>
            <a class="btn btn-sm" href="<?= h($delivery['sms_link']) ?>">Send it from my phone</a>
        <?php else: ?><strong>not sent</strong> <span class="muted small">(this person has no phone number saved)</span><?php endif; ?>
    </p>
    <?php if (!$delivery['email'] && !$delivery['sms']): ?>
        <p class="muted small mt">Please tell them yourself<?= $delivery['kind'] === 'password' ? ' using the temporary password above' : '' ?>.</p>
    <?php endif; ?>
</div>
<?php endif; ?>

<details class="card">
    <summary style="cursor:pointer;font-weight:700">+ <?= h(t('users_add')) ?></summary>
    <form method="post" class="mt">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="add_user">
        <div class="field-row">
            <div><label><?= h(t('register_first_name_hint')) ?></label><input type="text" name="first_name" required></div>
            <div><label><?= h(t('register_last_name_hint')) ?></label><input type="text" name="last_name" required></div>
        </div>
        <label><?= h(t('register_email_hint')) ?></label><input type="email" name="email" required>
        <div class="field-row">
            <div><label><?= h(t('register_department_hint')) ?></label><input type="text" name="department" placeholder="General"></div>
            <div><label><?= h(t('register_account_type_hint')) ?></label>
                <select name="role"><?php foreach (ROLE_KEYS as $r): ?><option value="<?= h($r) ?>"><?= h(roleLabel($r)) ?></option><?php endforeach; ?></select></div>
        </div>
        <label><?= h(t('register_password_hint')) ?></label><input type="password" name="password" minlength="8" required autocomplete="new-password">
        <button class="btn" type="submit"><?= h(t('users_create')) ?></button>
    </form>
</details>

<div class="card">
    <form method="get" class="row mb" style="gap:8px">
        <input type="search" name="q" value="<?= h($q) ?>" placeholder="<?= h(t('search_hint')) ?>" class="grow" style="margin:0">
        <button class="btn btn-secondary" type="submit">🔍</button>
    </form>
    <div class="table-wrap">
    <table>
        <thead><tr><th><?= h(t('register_first_name_hint')) ?></th><th><?= h(t('register_account_type_hint')) ?></th><th>Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): $isMe = (int)$u['id'] === $meId; $active = (int)$u['active'] === 1; ?>
            <tr>
                <td><strong><?= h(fullName($u)) ?></strong><br><span class="muted small"><?= h($u['email']) ?> · <?= h($u['department']) ?></span></td>
                <td>
                    <form method="post" class="row" style="gap:6px;flex-wrap:nowrap">
                        <?= csrfField() ?><input type="hidden" name="action" value="set_role"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                        <select name="role" style="margin:0;padding:6px 8px" <?= $isMe ? 'disabled' : '' ?> aria-label="<?= h(t('user_action_change_role')) ?>">
                            <?php foreach (ROLE_KEYS as $r): ?><option value="<?= h($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>><?= h(roleLabel($r)) ?></option><?php endforeach; ?>
                        </select>
                        <?php if (!$isMe): ?><button class="btn btn-secondary btn-sm" type="submit">✓</button><?php endif; ?>
                    </form>
                </td>
                <td><span class="badge badge-<?= $active ? 'active' : 'inactive' ?>"><?= h($active ? t('user_status_active') : t('user_status_inactive')) ?></span></td>
                <td>
                    <div class="row" style="gap:6px;flex-wrap:nowrap">
                        <?php if (!$isMe): ?>
                        <form method="post"><?= csrfField() ?><input type="hidden" name="action" value="toggle_active"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-sm <?= $active ? 'btn-secondary' : '' ?>" type="submit"><?= h($active ? t('user_action_deactivate') : t('user_action_activate')) ?></button></form>
                        <?php endif; ?>
                        <form method="post" onsubmit="return confirm('<?= h(t('user_action_reset_password')) ?>?')"><?= csrfField() ?><input type="hidden" name="action" value="reset_password"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                            <button class="btn btn-secondary btn-sm" type="submit">🔑 <?= h(t('user_action_reset_password')) ?></button></form>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>