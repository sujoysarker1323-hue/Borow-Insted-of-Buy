<?php
/**
 * login.php — session login with hashed password verification
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

if (is_logged_in()) {
    redirect(APP_URL . '/dashboard.php');
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $email    = strtolower(post('email'));
    $password = post('password');

    if (!valid_email($email) || $password === '') {
        $error = 'Please enter your email and password.';
    } else {
        $stmt = db()->prepare('SELECT user_id, password_hash, status FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $row = $stmt->fetch();

        // Always run a hash comparison so timing does not reveal whether the email exists.
        $hash = $row['password_hash'] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidin';
        if (password_verify($password, $hash) && $row) {
            if ($row['status'] === 'suspended') {
                $error = 'This account is suspended. Contact the BIB team if you think this is a mistake.';
            } elseif ($row['status'] === 'banned') {
                $error = 'This account has been banned.';
            } elseif ($row['status'] === 'unverified') {
                $error = 'Please verify your email address before logging in.';
            } else {
                login_user((int) $row['user_id']);
                $intended = $_SESSION['intended'] ?? null;
                unset($_SESSION['intended']);
                flash('success', 'Welcome back.');
                redirect($intended ?: APP_URL . '/dashboard.php');
            }
        } else {
            $error = 'Those credentials do not match our records.';
        }
    }
}

$pageTitle = 'Log in — BIB';
require __DIR__ . '/includes/header.php';
?>
<div class="auth-wrap">
  <div class="auth-side">
    <div>
      <a class="brand" href="<?= APP_URL ?>/index.php" style="color:#fff">
        <span class="brand-mark" style="background:rgba(255,255,255,.14)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H11v14H6.5A2.5 2.5 0 0 1 4 16.5z"/><path d="M20 7.5A2.5 2.5 0 0 0 17.5 5H13v14h4.5A2.5 2.5 0 0 0 20 16.5z"/></svg>
        </span>
        <span style="color:#fff">BIB<small style="color:rgba(255,255,255,.6)">Borrow Instead of Buy</small></span>
      </a>
    </div>
    <div>
      <h2>Borrow what you need. Share what you have. Waste less.</h2>
      <p>4,820 neighbours in Dhaka are already sharing 1,946 items. The first thing you borrow usually pays for itself.</p>
      <div class="quote">"The deposit and the two-sided return confirmation are what sold me. It feels safe in a way a Facebook group never did."</div>
    </div>
    <div class="small" style="color:rgba(255,255,255,.5)">&copy; <?= date('Y') ?> BIB</div>
  </div>

  <div class="auth-form">
    <div class="auth-card">
      <h1>Welcome back</h1>
      <p>Log in to borrow, lend and manage your listings.</p>

      <?php if ($error): ?>
        <div class="panel" style="border-color:var(--danger);margin-bottom:18px">
          <div class="panel-body" style="padding:14px 16px">
            <div class="small" style="color:var(--danger)"><?= e($error) ?></div>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="email">Email address</label>
          <input class="input" id="email" name="email" type="email" value="<?= e($email) ?>" autocomplete="email" required>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input class="input" id="password" name="password" type="password" autocomplete="current-password" required>
        </div>
        <div class="flex between center" style="margin-bottom:18px">
          <label class="check" style="padding:0"><input type="checkbox" name="remember" value="1" checked> Remember me</label>
          <a href="<?= APP_URL ?>/forgot_password.php" class="small" style="color:var(--green-700);font-weight:600">Forgot password?</a>
        </div>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Log in</button>
      </form>

      <div class="divider">or</div>
      <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/search.php">Continue browsing as a guest</a>

      <p class="small" style="text-align:center;margin-top:20px">
        New to BIB? <a href="<?= APP_URL ?>/register.php" style="color:var(--green-700);font-weight:600">Create a free account</a>
      </p>

      <div class="demo-box">
        <b>Demo accounts</b>
        <div class="demo-row"><span>Borrower</span><code>rafi@bib.demo / password123</code></div>
        <div class="demo-row"><span>Lender</span><code>arif@bib.demo / password123</code></div>
        <div class="demo-row"><span>Admin</span><code>admin@bib.demo / password123</code></div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
