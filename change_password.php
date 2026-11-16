<?php
/**
 * change_password.php — verify the current password, then set a new one
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $current = post('current_password');
    $new     = post('password');
    $confirm = post('password_confirm');

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify($current, $hash)) {
        $errors[] = 'Your current password is not correct.';
    }
    if ($p = password_problem($new)) {
        $errors[] = $p;
    }
    if ($new !== $confirm) {
        $errors[] = 'The two new passwords do not match.';
    }
    if ($current !== '' && $current === $new) {
        $errors[] = 'The new password must be different from the current one.';
    }

    if (!$errors) {
        db()->prepare('UPDATE users SET password_hash = ? WHERE user_id = ?')
            ->execute([password_hash($new, PASSWORD_BCRYPT), $me['user_id']]);
        notify((int) $me['user_id'], 'system', 'Password changed', 'Your password was updated successfully.', 'profile.php');
        flash('success', 'Password changed.');
        redirect(APP_URL . '/profile.php?u=' . (int) $me['user_id']);
    }
}

$pageTitle = 'Change password — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a> <span>/</span> <span>Change password</span></div>
  <h1 style="font-size:1.9rem">Change password</h1>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="panel">
    <?= csrf_field() ?>
    <div class="panel-body">
      <div class="field"><label for="current_password">Current password</label><input class="input" id="current_password" name="current_password" type="password" required></div>
      <div class="field"><label for="password">New password</label><input class="input" id="password" name="password" type="password" required></div>
      <div class="field"><label for="password_confirm">Confirm new password</label><input class="input" id="password_confirm" name="password_confirm" type="password" required></div>
      <div class="flex gap-8">
        <button class="btn btn-primary" type="submit">Update password</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/dashboard.php">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
