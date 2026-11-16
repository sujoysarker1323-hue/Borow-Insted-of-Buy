<?php
/**
 * delete_account.php — soft-deletes the account after password confirmation.
 * Records are kept (anonymised) so transaction history stays intact for the
 * other party; the profile is hidden and the email is released.
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $stmt = db()->prepare('SELECT password_hash FROM users WHERE user_id = ?');
    $stmt->execute([$me['user_id']]);
    $hash = (string) $stmt->fetchColumn();

    if (!password_verify(post('password'), $hash)) {
        $errors[] = 'Password is not correct.';
    }
    if (post('confirm') !== 'DELETE') {
        $errors[] = 'Type DELETE in the box to confirm.';
    }

    // Block deletion while an item is still out on loan.
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM transactions
          WHERE (lender_id = ? OR borrower_id = ?)
            AND status IN ('accepted','pickup','borrowed','return_requested')"
    );
    $stmt->execute([$me['user_id'], $me['user_id']]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'You still have an active borrow. Complete or cancel it before deleting your account.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE users SET status = 'deleted', email = CONCAT('deleted+', user_id, '@bib.invalid') WHERE user_id = ?")
                ->execute([$me['user_id']]);
            $pdo->prepare("UPDATE items SET status = 'removed' WHERE owner_id = ?")->execute([$me['user_id']]);
            $pdo->prepare("UPDATE profiles SET bio = NULL, phone = NULL, avatar_path = NULL WHERE user_id = ?")->execute([$me['user_id']]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not delete the account. Please contact support.';
        }

        if (!$errors) {
            logout_user();
            boot_session();
            flash('success', 'Your account has been deleted.');
            redirect(APP_URL . '/index.php');
        }
    }
}

$pageTitle = 'Delete account — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a> <span>/</span> <span>Delete account</span></div>
  <h1 style="font-size:1.9rem">Delete your account</h1>
  <p>This hides your profile and removes your listings. Your past transactions stay on record for the other party, but your personal details are cleared.</p>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="panel" style="border-color:var(--danger)">
    <?= csrf_field() ?>
    <div class="panel-body">
      <div class="field"><label for="password">Confirm your password</label><input class="input" id="password" name="password" type="password" required></div>
      <div class="field"><label for="confirm">Type DELETE to confirm</label><input class="input" id="confirm" name="confirm" placeholder="DELETE" required></div>
      <div class="flex gap-8">
        <button class="btn btn-danger" type="submit">Delete my account</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/dashboard.php">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
