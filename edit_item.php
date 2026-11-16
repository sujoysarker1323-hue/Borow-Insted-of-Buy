<?php
/**
 * edit_item.php — owner edits a listing, manages photos and availability
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$itemId = (int) get('id');
$stmt = db()->prepare('SELECT * FROM items WHERE item_id = ?');
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    exit('Item not found.');
}
if ((int) $item['owner_id'] !== (int) $me['user_id'] && !is_admin()) {
    http_response_code(403);
    exit('You can only edit your own listings.');
}

$categories = db()->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action', 'save');

    if ($action === 'delete_photo') {
        $photoId = (int) post('photo_id');
        $stmt = db()->prepare('SELECT file_path FROM item_photos WHERE photo_id = ? AND item_id = ?');
        $stmt->execute([$photoId, $itemId]);
        $path = $stmt->fetchColumn();
        if ($path) {
            db()->prepare('DELETE FROM item_photos WHERE photo_id = ?')->execute([$photoId]);
            $file = UPLOAD_DIR . '/' . $path;
            if (is_file($file)) {
                @unlink($file);
            }
            flash('success', 'Photo removed.');
        }
        redirect(APP_URL . '/edit_item.php?id=' . $itemId);
    }

    if ($action === 'block_dates') {
        $start = post('block_start');
        $end   = post('block_end') ?: $start;
        if ($start === '' || strtotime($end) < strtotime($start)) {
            $errors[] = 'Choose a valid date range to block.';
        } else {
            db()->prepare('INSERT INTO availability (item_id, start_date, end_date, is_blocked, note) VALUES (?,?,?,1,?)')
                ->execute([$itemId, $start, $end, post('block_note')]);
            flash('success', 'Dates blocked.');
            redirect(APP_URL . '/edit_item.php?id=' . $itemId);
        }
    }

    if ($action === 'save') {
        $title = post('title');
        $desc  = post('description');
        if (str_len($title) < 5) {
            $errors[] = 'Item name must be at least 5 characters.';
        }
        if (str_len($desc) < 20) {
            $errors[] = 'Description must be at least 20 characters.';
        }

        $newPhotos = [];
        if (!$errors && !empty($_FILES['photos']['name'][0])) {
            $count = count($_FILES['photos']['name']);
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name' => $_FILES['photos']['name'][$i], 'type' => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i], 'error' => $_FILES['photos']['error'][$i],
                    'size' => $_FILES['photos']['size'][$i],
                ];
                try {
                    $saved = save_upload($file, 'items');
                    if ($saved) {
                        $newPhotos[] = $saved;
                    }
                } catch (RuntimeException $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
        }

        if (!$errors) {
            db()->prepare(
                'UPDATE items SET category_id = ?, title = ?, description = ?, brand = ?, model = ?,
                        item_condition = ?, city = ?, area = ?, deposit_amount = ?, daily_fee = ?,
                        max_borrow_days = ?, rules = ?, pickup_instructions = ?, status = ?
                  WHERE item_id = ?'
            )->execute([
                (int) post('category_id'), $title, $desc, post('brand'), post('model'),
                post('item_condition'), post('city'), post('area'),
                (float) post('deposit_amount'), (float) post('daily_fee'),
                (int) post('max_borrow_days'), post('rules'), post('pickup_instructions'),
                post('status', $item['status']), $itemId,
            ]);

            foreach ($newPhotos as $i => $path) {
                db()->prepare('INSERT INTO item_photos (item_id, file_path, is_primary, sort_order) VALUES (?,?,0,?)')
                    ->execute([$itemId, $path, 10 + $i]);
            }

            flash('success', 'Listing updated.');
            redirect(APP_URL . '/edit_item.php?id=' . $itemId);
        }
    }
}

$stmt = db()->prepare('SELECT * FROM item_photos WHERE item_id = ? ORDER BY is_primary DESC, sort_order');
$stmt->execute([$itemId]);
$photos = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM availability WHERE item_id = ? ORDER BY start_date');
$stmt->execute([$itemId]);
$availability = $stmt->fetchAll();

$pageTitle = 'Edit ' . $item['title'] . ' — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:36px 24px 80px;max-width:900px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/my_listings.php">My listings</a> <span>/</span> <span>Edit</span></div>
  <div class="flex between center wrap gap-12" style="margin-bottom:20px">
    <h1 style="font-size:1.9rem;margin:0">Edit listing</h1>
    <div class="flex gap-8">
      <a class="btn btn-outline btn-sm" href="<?= APP_URL ?>/item_detail.php?id=<?= $itemId ?>">View public page</a>
      <a class="btn btn-ghost btn-sm" href="<?= APP_URL ?>/delete_item.php?id=<?= $itemId ?>" style="color:var(--danger)">Delete</a>
    </div>
  </div>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="panel">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save">
    <div class="panel-body">
      <div class="field"><label for="title">Item name</label><input class="input" id="title" name="title" value="<?= e($item['title']) ?>" required></div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field">
          <label for="category_id">Category</label>
          <select class="select" id="category_id" name="category_id">
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['category_id'] ?>"<?= (int) $c['category_id'] === (int) $item['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="item_condition">Condition</label>
          <select class="select" id="item_condition" name="item_condition">
            <?php foreach (['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= $item['item_condition'] === $v ? ' selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="field"><label for="description">Description</label><textarea class="textarea" id="description" name="description" required><?= e($item['description']) ?></textarea></div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="brand">Brand</label><input class="input" id="brand" name="brand" value="<?= e($item['brand']) ?>"></div>
        <div class="field"><label for="model">Model</label><input class="input" id="model" name="model" value="<?= e($item['model']) ?>"></div>
      </div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="deposit_amount">Deposit (<?= APP_CURRENCY ?>)</label><input class="input" id="deposit_amount" name="deposit_amount" type="number" min="0" value="<?= e((string) (int) $item['deposit_amount']) ?>"></div>
        <div class="field"><label for="daily_fee">Daily fee (<?= APP_CURRENCY ?>)</label><input class="input" id="daily_fee" name="daily_fee" type="number" min="0" value="<?= e((string) (int) $item['daily_fee']) ?>"></div>
      </div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="max_borrow_days">Max days</label><input class="input" id="max_borrow_days" name="max_borrow_days" type="number" min="1" max="90" value="<?= (int) $item['max_borrow_days'] ?>"></div>
        <div class="field">
          <label for="status">Listing status</label>
          <select class="select" id="status" name="status">
            <?php foreach (['active' => 'Active (visible)', 'unavailable' => 'Unavailable (hidden from search)', 'pending' => 'Pending review'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= $item['status'] === $v ? ' selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="area">Area</label><input class="input" id="area" name="area" value="<?= e($item['area']) ?>"></div>
        <div class="field"><label for="city">City</label><input class="input" id="city" name="city" value="<?= e($item['city']) ?>"></div>
      </div>
      <div class="field"><label for="rules">Rules</label><textarea class="textarea" id="rules" name="rules" style="min-height:80px"><?= e($item['rules']) ?></textarea></div>
      <div class="field"><label for="pickup_instructions">Pickup &amp; return instructions</label><textarea class="textarea" id="pickup_instructions" name="pickup_instructions" style="min-height:80px"><?= e($item['pickup_instructions']) ?></textarea></div>
      <div class="field"><label for="photos">Add photos</label><input class="input" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple></div>
      <button class="btn btn-primary" type="submit">Save changes</button>
    </div>
  </form>

  <div class="panel">
    <div class="panel-head"><h3>Photos (<?= count($photos) ?>)</h3></div>
    <div class="panel-body">
      <?php if (!$photos): ?>
        <p class="small muted" style="margin:0">No photos yet. Listings with photos get roughly three times more requests.</p>
      <?php else: ?>
        <div class="grid grid-4" style="gap:12px">
          <?php foreach ($photos as $p): ?>
            <div style="position:relative">
              <img src="<?= UPLOAD_URL . '/' . e($p['file_path']) ?>" alt="" style="width:100%;aspect-ratio:1;object-fit:cover;border-radius:10px">
              <?php if ($p['is_primary']): ?><span class="badge badge-green" style="position:absolute;top:6px;left:6px;font-size:.6rem">Cover</span><?php endif; ?>
              <form method="post" style="position:absolute;top:6px;right:6px">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_photo">
                <input type="hidden" name="photo_id" value="<?= (int) $p['photo_id'] ?>">
                <button class="icon-btn" style="width:26px;height:26px;background:var(--paper)" title="Remove photo" aria-label="Remove photo">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" width="13" height="13"><path d="M18 6L6 18M6 6l12 12"/></svg>
                </button>
              </form>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>Availability</h3></div>
    <div class="panel-body">
      <div class="table-wrap" style="margin-bottom:16px">
        <table class="data">
          <thead><tr><th>From</th><th>To</th><th>Status</th><th>Note</th></tr></thead>
          <tbody>
            <?php foreach ($availability as $a): ?>
              <tr>
                <td><?= nice_date($a['start_date']) ?></td>
                <td><?= nice_date($a['end_date']) ?></td>
                <td><span class="badge <?= $a['is_blocked'] ? 'badge-danger' : 'badge-ok' ?>"><?= $a['is_blocked'] ? 'Blocked' : 'Open' ?></span></td>
                <td class="small muted"><?= e($a['note']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <form method="post" class="flex gap-8 wrap" style="align-items:flex-end">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="block_dates">
        <div class="field" style="margin:0"><label for="block_start">Block from</label><input class="input" id="block_start" name="block_start" type="date" required></div>
        <div class="field" style="margin:0"><label for="block_end">To</label><input class="input" id="block_end" name="block_end" type="date"></div>
        <div class="field" style="margin:0;flex:1;min-width:160px"><label for="block_note">Note</label><input class="input" id="block_note" name="block_note" placeholder="e.g. using it myself"></div>
        <button class="btn btn-outline" type="submit">Block dates</button>
      </form>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
