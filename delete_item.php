<?php
/**
 * delete_item.php — owner deletes a listing (blocked while a borrow is active)
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$itemId = (int) (get('id') ?: post('id'));
$stmt = db()->prepare('SELECT * FROM items WHERE item_id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    exit('Item not found.');
}
if ((int) $item['owner_id'] !== (int) $me['user_id'] && !is_admin()) {
    http_response_code(403);
    exit('You can only delete your own listings.');
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM transactions
          WHERE item_id = ? AND status IN ('accepted','pickup','borrowed','return_requested')"
    );
    $stmt->execute([$itemId]);
    if ((int) $stmt->fetchColumn() > 0) {
        $errors[] = 'This item is currently on loan. Wait until it is returned before deleting it.';
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare('SELECT file_path FROM item_photos WHERE item_id = ?');
            $stmt->execute([$itemId]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
                $file = UPLOAD_DIR . '/' . $path;
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            $pdo->prepare('DELETE FROM items WHERE item_id = ?')->execute([$itemId]);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not delete the listing.';
        }

        if (!$errors) {
            flash('success', 'Listing deleted.');
            redirect(APP_URL . '/my_listings.php');
        }
    }
}

$pageTitle = 'Delete listing — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/my_listings.php">My listings</a> <span>/</span> <span>Delete</span></div>
  <h1 style="font-size:1.9rem">Delete this listing?</h1>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-body">
      <div class="flex center gap-16" style="margin-bottom:18px">
        <img src="<?= APP_URL ?>/assets/img/placeholder.svg" alt="" style="width:64px;height:64px;border-radius:10px;object-fit:cover;display:none">
        <div>
          <b><?= e($item['title']) ?></b>
          <div class="small muted"><?= (int) $item['borrow_count'] ?> borrows · listed <?= nice_date($item['created_at']) ?></div>
        </div>
      </div>
      <p class="small">This removes the listing and its photos permanently. Past transactions and reviews stay on record.</p>
      <form method="post" class="flex gap-8">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $itemId ?>">
        <button class="btn btn-danger" type="submit">Yes, delete it</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/my_listings.php">Cancel</a>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
