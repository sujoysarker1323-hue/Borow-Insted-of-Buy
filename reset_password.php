<?php
/**
 * reset_password.php — consumes a reset token and sets a new password
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

$token  = get('token') ?: post('token');
$errors = [];
$done   = false;
$valid  = false;
$userId = null;

if ($token !== '') {
    $stmt = db()->prepare(
        'SELECT user_id FROM users WHERE reset_token = ? AND reset_expires_at > NOW() LIMIT 1'
    );
    $stmt->execute([$token]);
    $userId = $stmt->fetchColumn();
    $valid  = (bool) $userId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $valid) {
    verify_csrf();
    $password = post('password');
    $confirm  = post('password_confirm');

    if ($p = password_problem($password)) {
        $errors[] = $p;
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }

    if (!$errors) {
        db()->prepare(
            'UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires_at = NULL WHERE user_id = ?'
        )->execute([password_hash($password, PASSWORD_BCRYPT), $userId]);
        $done = true;
        notify((int) $userId, 'system', 'Password changed', 'Your password was reset. If this was not you, contact support immediately.', 'login.php');
    }
}

$pageTitle = 'Set a new password — BIB';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:64px 24px 96px">
  <div class="panel">
    <div class="panel-body" style="padding:36px">
      <?php if ($done): ?>
        <h1 style="font-size:1.8rem">Password updated</h1>
        <p>You can now log in with your new password.</p>
        <a class="btn btn-primary" href="<?= APP_URL ?>/login.php">Log in</a>

      <?php elseif (!$valid): ?>
        <h1 style="font-size:1.8rem">Link expired</h1>
        <p>This reset link is invalid or has expired. Request a new one.</p>
        <a class="btn btn-primary" href="<?= APP_URL ?>/forgot_password.php">Request a new link</a>

      <?php else: ?>
        <h1 style="font-size:1.8rem">Set a new password</h1>
        <p>Choose something at least 8 characters long with a letter and a number.</p>

        <?php if ($errors): ?>
          <div class="small" style="color:var(--danger);margin-bottom:14px">
            <?php foreach ($errors as $err): ?><div>• <?= e($err) ?></div><?php endforeach; ?>
          </div>
        <?php endif; ?>

        <form method="post" novalidate>
          <?= csrf_field() ?>
          <input type="hidden" name="token" value="<?= e($token) ?>">
          <div class="field">
            <label for="password">New password</label>
            <input class="input" id="password" name="password" type="password" required>
          </div>
          <div class="field">
            <label for="password_confirm">Confirm new password</label>
            <input class="input" id="password_confirm" name="password_confirm" type="password" required>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Update password</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
