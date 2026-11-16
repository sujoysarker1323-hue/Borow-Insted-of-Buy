<?php
/**
 * register.php — account creation with email verification
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

if (is_logged_in()) {
    redirect(APP_URL . '/dashboard.php');
}

$errors = [];
$old    = ['full_name' => '', 'username' => '', 'email' => '', 'city' => '', 'area' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $old['full_name'] = post('full_name');
    $old['username']  = strtolower(post('username'));
    $old['email']     = strtolower(post('email'));
    $old['city']      = post('city');
    $old['area']      = post('area');
    $password         = post('password');
    $confirm          = post('password_confirm');
    $role             = post('role') === 'lender' ? 'lender' : 'borrower';
    $phone            = post('phone');

    if ($old['full_name'] === '' || str_len($old['full_name']) < 3) {
        $errors[] = 'Please enter your full name.';
    }
    if (!valid_username($old['username'])) {
        $errors[] = 'Username must be 3–30 characters: lowercase letters, numbers and underscores only.';
    }
    if (!valid_email($old['email'])) {
        $errors[] = 'Please enter a valid email address.';
    }
    if ($p = password_problem($password)) {
        $errors[] = $p;
    }
    if ($password !== $confirm) {
        $errors[] = 'The two passwords do not match.';
    }
    if (!post('terms')) {
        $errors[] = 'You must accept the community rules to join.';
    }

    if (!$errors) {
        $stmt = db()->prepare('SELECT user_id FROM users WHERE email = ?');
        $stmt->execute([$old['email']]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'That email address is already registered.';
        }
        $stmt = db()->prepare('SELECT user_id FROM profiles WHERE username = ?');
        $stmt->execute([$old['username']]);
        if ($stmt->fetchColumn()) {
            $errors[] = 'That username is already taken.';
        }
    }

    if (!$errors) {
        $pdo  = db();
        $token = bin2hex(random_bytes(32));
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO users (email, password_hash, role, status, verify_token) VALUES (?,?,?,?,?)'
            )->execute([
                $old['email'],
                password_hash($password, PASSWORD_BCRYPT),
                $role,
                REQUIRE_EMAIL_VERIFICATION ? 'unverified' : 'active',
                $token,
            ]);
            $userId = (int) $pdo->lastInsertId();

            $pdo->prepare(
                'INSERT INTO profiles (user_id, username, full_name, phone, city, area) VALUES (?,?,?,?,?,?)'
            )->execute([$userId, $old['username'], $old['full_name'], $phone, $old['city'], $old['area']]);

            notify($userId, 'system', 'Welcome to BIB', 'List your first item or browse what your neighbours are sharing.', 'index.php');
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not create the account. Please try again.';
        }

        if (!$errors) {
            if (REQUIRE_EMAIL_VERIFICATION) {
                $link = APP_URL . '/verify_email.php?token=' . $token;
                // On a real host: mail($email, 'Verify your BIB account', "Confirm: $link");
                $_SESSION['pending_verify_link'] = SHOW_VERIFICATION_LINK ? $link : null;
                flash('success', 'Account created. Check your email to verify your address.');
                redirect(APP_URL . '/verify_email.php?sent=1');
            }
            login_user($userId);
            flash('success', 'Welcome to BIB! Your account is ready.');
            redirect(APP_URL . '/dashboard.php');
        }
    }
}

$pageTitle = 'Create your account — BIB';
$activeNav = '';
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
      <p>Join 4,820 neighbours sharing 1,946 items across Dhaka. Free to join, no subscription, no catch.</p>
      <div class="quote">"I've saved about ৳40,000 in two years by borrowing instead of buying. And I've met half my street."</div>
    </div>
    <div class="small" style="color:rgba(255,255,255,.5)">&copy; <?= date('Y') ?> BIB</div>
  </div>

  <div class="auth-form">
    <div class="auth-card">
      <h1>Create your account</h1>
      <p>It takes about a minute.</p>

      <?php if ($errors): ?>
        <div class="panel" style="border-color:var(--danger);margin-bottom:18px">
          <div class="panel-body" style="padding:14px 16px">
            <?php foreach ($errors as $err): ?>
              <div class="small" style="color:var(--danger)">• <?= e($err) ?></div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <form method="post" novalidate>
        <?= csrf_field() ?>
        <div class="field">
          <label for="full_name">Full name</label>
          <input class="input" id="full_name" name="full_name" value="<?= e($old['full_name']) ?>" required>
        </div>
        <div class="grid grid-2" style="gap:12px">
          <div class="field">
            <label for="username">Username</label>
            <input class="input" id="username" name="username" value="<?= e($old['username']) ?>" placeholder="lowercase" required>
          </div>
          <div class="field">
            <label for="phone">Phone (optional)</label>
            <input class="input" id="phone" name="phone" value="<?= e(post('phone')) ?>" placeholder="+8801…">
          </div>
        </div>
        <div class="field">
          <label for="email">Email address</label>
          <input class="input" id="email" name="email" type="email" value="<?= e($old['email']) ?>" required>
        </div>
        <div class="grid grid-2" style="gap:12px">
          <div class="field">
            <label for="city">City</label>
            <input class="input" id="city" name="city" value="<?= e($old['city'] ?: 'Dhaka') ?>">
          </div>
          <div class="field">
            <label for="area">Area</label>
            <input class="input" id="area" name="area" value="<?= e($old['area']) ?>" placeholder="Dhanmondi">
          </div>
        </div>
        <div class="field">
          <label for="role">I mainly want to…</label>
          <select class="select" id="role" name="role">
            <option value="borrower">Borrow things</option>
            <option value="lender">Lend things out</option>
          </select>
        </div>
        <div class="grid grid-2" style="gap:12px">
          <div class="field">
            <label for="password">Password</label>
            <input class="input" id="password" name="password" type="password" required>
          </div>
          <div class="field">
            <label for="password_confirm">Confirm password</label>
            <input class="input" id="password_confirm" name="password_confirm" type="password" required>
          </div>
        </div>
        <label class="check" style="margin-bottom:16px">
          <input type="checkbox" name="terms" value="1"> I agree to the community rules and to return items on time.
        </label>
        <button class="btn btn-primary btn-block btn-lg" type="submit">Create account</button>
      </form>

      <p class="small" style="text-align:center;margin-top:18px">
        Already have an account? <a href="<?= APP_URL ?>/login.php" style="color:var(--green-700);font-weight:600">Log in</a>
      </p>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
