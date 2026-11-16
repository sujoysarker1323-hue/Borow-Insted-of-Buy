<?php
/**
 * verify_email.php — confirms an address from the emailed token
 * Also shows the verification link on screen when SHOW_VERIFICATION_LINK is on
 * (XAMPP usually has no working mail()).
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

$token  = get('token');
$status = 'idle';
$link   = $_SESSION['pending_verify_link'] ?? null;

if ($token !== '') {
    $stmt = db()->prepare('SELECT user_id, status FROM users WHERE verify_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $row = $stmt->fetch();

    if (!$row) {
        $status = 'invalid';
    } elseif ($row['status'] === 'active') {
        $status = 'already';
    } else {
        db()->prepare(
            "UPDATE users SET status = 'active', email_verified_at = NOW(), verify_token = NULL WHERE user_id = ?"
        )->execute([$row['user_id']]);
        unset($_SESSION['pending_verify_link']);
        $status = 'verified';
        notify((int) $row['user_id'], 'system', 'Email verified', 'Your account is now fully active.', 'dashboard.php');
    }
} elseif (get('sent') === '1') {
    $status = 'sent';
}

$pageTitle = 'Verify your email — BIB';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:64px 24px 96px">
  <div class="panel">
    <div class="panel-body" style="padding:36px;text-align:center">
      <?php if ($status === 'verified'): ?>
        <div style="width:56px;height:56px;border-radius:50%;background:var(--ok-bg);color:var(--ok);display:grid;place-items:center;margin:0 auto 18px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" width="26" height="26"><path d="M20 6L9 17l-5-5"/></svg>
        </div>
        <h1 style="font-size:1.8rem">Email verified</h1>
        <p>Your account is now fully active. You can log in and start borrowing.</p>
        <a class="btn btn-primary" href="<?= APP_URL ?>/login.php">Log in</a>

      <?php elseif ($status === 'already'): ?>
        <h1 style="font-size:1.8rem">Already verified</h1>
        <p>This address has already been confirmed. You can log in.</p>
        <a class="btn btn-primary" href="<?= APP_URL ?>/login.php">Log in</a>

      <?php elseif ($status === 'invalid'): ?>
        <h1 style="font-size:1.8rem">Link not valid</h1>
        <p>This verification link is invalid or has already been used. Try logging in — if your account is unverified, register again or contact support.</p>
        <a class="btn btn-outline" href="<?= APP_URL ?>/login.php">Go to login</a>

      <?php else: ?>
        <div style="width:56px;height:56px;border-radius:50%;background:var(--amber-100);color:var(--amber-600);display:grid;place-items:center;margin:0 auto 18px">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="26" height="26"><path d="M4 4h16v16H4z"/><path d="M4 7l8 6 8-6"/></svg>
        </div>
        <h1 style="font-size:1.8rem">Check your email</h1>
        <p>We sent a verification link to your address. Click it to activate your account.</p>

        <?php if ($link): ?>
          <div class="demo-box" style="text-align:left;margin-top:20px">
            <b>Local development</b>
            <p class="small" style="margin:0 0 8px">Mail is not configured on this server, so here is your verification link:</p>
            <a href="<?= e($link) ?>" style="word-break:break-all;font-size:.8rem;color:var(--green-700)"><?= e($link) ?></a>
          </div>
        <?php endif; ?>

        <p class="small muted" style="margin-top:20px">Didn't get it? Check your spam folder, or <a href="<?= APP_URL ?>/register.php" style="color:var(--green-700)">register again</a>.</p>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
