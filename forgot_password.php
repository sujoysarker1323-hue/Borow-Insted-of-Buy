<?php
/**
 * forgot_password.php — issues a single-use reset token
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

$sent  = false;
$error = '';
$link  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email = strtolower(post('email'));

    if (!valid_email($email)) {
        $error = 'Please enter a valid email address.';
    } else {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE email = ? AND status IN ("active","unverified")');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();

        if ($userId) {
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 3600);
            db()->prepare('UPDATE users SET reset_token = ?, reset_expires_at = ? WHERE user_id = ?')
                ->execute([$token, $expires, $userId]);
            $link = APP_URL . '/reset_password.php?token=' . $token;
            // mail($email, 'Reset your BIB password', "Reset link (valid 1 hour): $link");
        }
        // Always report success so the form cannot be used to enumerate accounts.
        $sent = true;
    }
}

$pageTitle = 'Forgot password — BIB';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:64px 24px 96px">
  <div class="panel">
    <div class="panel-body" style="padding:36px">
      <h1 style="font-size:1.8rem">Reset your password</h1>
      <p>Enter the email address on your account and we'll send you a link to set a new password.</p>

      <?php if ($error): ?>
        <div class="small" style="color:var(--danger);margin-bottom:14px"><?= e($error) ?></div>
      <?php endif; ?>

      <?php if ($sent): ?>
        <div class="panel" style="background:var(--ok-bg);border-color:transparent">
          <div class="panel-body" style="padding:16px 18px">
            <b class="small" style="color:var(--ok)">If that address is registered, a reset link is on its way.</b>
            <p class="small" style="margin:6px 0 0">The link is valid for one hour and can only be used once.</p>
          </div>
        </div>
        <?php if ($link): ?>
          <div class="demo-box" style="margin-top:16px">
            <b>Local development</b>
            <p class="small" style="margin:0 0 8px">Mail is not configured, so here is the reset link:</p>
            <a href="<?= e($link) ?>" style="word-break:break-all;font-size:.8rem;color:var(--green-700)"><?= e($link) ?></a>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <form method="post" novalidate>
          <?= csrf_field() ?>
          <div class="field">
            <label for="email">Email address</label>
            <input class="input" id="email" name="email" type="email" required>
          </div>
          <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
        </form>
      <?php endif; ?>

      <p class="small" style="margin-top:18px"><a href="<?= APP_URL ?>/login.php" style="color:var(--green-700);font-weight:600">← Back to login</a></p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
