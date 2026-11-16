<?php
/**
 * list_item.php — create a listing with multiple photos and availability
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$categories = db()->query('SELECT * FROM categories WHERE is_active = 1 ORDER BY sort_order')->fetchAll();
$errors = [];
$old = [
    'title' => '', 'category_id' => '', 'description' => '', 'brand' => '', 'model' => '',
    'item_condition' => 'good', 'area' => $me['area'] ?? '', 'city' => $me['city'] ?? 'Dhaka',
    'deposit_amount' => '0', 'daily_fee' => '0', 'max_borrow_days' => '7',
    'rules' => '', 'pickup_instructions' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    foreach ($old as $k => $_) {
        $old[$k] = post($k, (string) $old[$k]);
    }

    if (str_len($old['title']) < 5) {
        $errors[] = 'Give the item a name of at least 5 characters.';
    }
    if ((int) $old['category_id'] <= 0) {
        $errors[] = 'Choose a category.';
    }
    if (str_len($old['description']) < 20) {
        $errors[] = 'Describe the item in at least 20 characters — borrowers need to know what they are getting.';
    }
    if ((float) $old['deposit_amount'] < 0 || (float) $old['daily_fee'] < 0) {
        $errors[] = 'Deposit and fee cannot be negative.';
    }
    if ((int) $old['max_borrow_days'] < 1 || (int) $old['max_borrow_days'] > 90) {
        $errors[] = 'Maximum borrowing period must be between 1 and 90 days.';
    }

    $photos = [];
    if (!$errors && !empty($_FILES['photos']['name'][0])) {
        $count = count($_FILES['photos']['name']);
        if ($count > 6) {
            $errors[] = 'You can upload at most 6 photos.';
        } else {
            for ($i = 0; $i < $count; $i++) {
                $file = [
                    'name'     => $_FILES['photos']['name'][$i],
                    'type'     => $_FILES['photos']['type'][$i],
                    'tmp_name' => $_FILES['photos']['tmp_name'][$i],
                    'error'    => $_FILES['photos']['error'][$i],
                    'size'     => $_FILES['photos']['size'][$i],
                ];
                try {
                    $saved = save_upload($file, 'items');
                    if ($saved) {
                        $photos[] = $saved;
                    }
                } catch (RuntimeException $ex) {
                    $errors[] = $ex->getMessage();
                }
            }
        }
    }

    if (!$errors) {
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'INSERT INTO items (owner_id, category_id, title, description, brand, model, item_condition,
                                    city, area, deposit_amount, daily_fee, max_borrow_days, rules,
                                    pickup_instructions, status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)'
            )->execute([
                $me['user_id'], (int) $old['category_id'], $old['title'], $old['description'],
                $old['brand'], $old['model'], $old['item_condition'], $old['city'], $old['area'],
                (float) $old['deposit_amount'], (float) $old['daily_fee'], (int) $old['max_borrow_days'],
                $old['rules'], $old['pickup_instructions'],
                is_admin() ? 'active' : 'pending',
            ]);
            $itemId = (int) $pdo->lastInsertId();

            foreach ($photos as $i => $path) {
                $pdo->prepare('INSERT INTO item_photos (item_id, file_path, is_primary, sort_order) VALUES (?,?,?,?)')
                    ->execute([$itemId, $path, $i === 0 ? 1 : 0, $i]);
            }

            // Default availability: open for the next 60 days.
            $pdo->prepare('INSERT INTO availability (item_id, start_date, end_date, is_blocked, note) VALUES (?,?,?,0,?)')
                ->execute([$itemId, date('Y-m-d'), date('Y-m-d', strtotime('+60 days')), 'Default open window']);

            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            $errors[] = 'Could not save the listing. Please try again.';
        }

        if (!$errors) {
            notify((int) $me['user_id'], 'listing', 'Listing submitted', 'Your listing is awaiting review. It usually goes live within a few hours.', 'my_listings.php');
            flash('success', 'Listing submitted. An admin will review it shortly.');
            redirect(APP_URL . '/my_listings.php');
        }
    }
}

$pageTitle = 'List an item — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/dashboard.php">Dashboard</a> <span>/</span> <span>List an item</span></div>
  <h1 style="font-size:1.9rem">List an item</h1>
  <p class="muted">The more detail you give, the more likely someone borrows it — and the fewer questions you answer later.</p>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data" class="panel">
    <?= csrf_field() ?>
    <div class="panel-body">
      <div class="field"><label for="title">Item name</label><input class="input" id="title" name="title" value="<?= e($old['title']) ?>" placeholder="e.g. Bosch GSB 18V Cordless Drill" required></div>

      <div class="grid grid-2" style="gap:14px">
        <div class="field">
          <label for="category_id">Category</label>
          <select class="select" id="category_id" name="category_id" required>
            <option value="">Choose…</option>
            <?php foreach ($categories as $c): ?>
              <option value="<?= (int) $c['category_id'] ?>"<?= (string) $c['category_id'] === $old['category_id'] ? ' selected' : '' ?>><?= e($c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label for="item_condition">Condition</label>
          <select class="select" id="item_condition" name="item_condition">
            <?php foreach (['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'] as $v => $l): ?>
              <option value="<?= $v ?>"<?= $old['item_condition'] === $v ? ' selected' : '' ?>><?= $l ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="field"><label for="description">Description</label><textarea class="textarea" id="description" name="description" placeholder="What is it, what's included, anything a borrower should know…" required><?= e($old['description']) ?></textarea></div>

      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="brand">Brand (optional)</label><input class="input" id="brand" name="brand" value="<?= e($old['brand']) ?>"></div>
        <div class="field"><label for="model">Model (optional)</label><input class="input" id="model" name="model" value="<?= e($old['model']) ?>"></div>
      </div>

      <div class="field">
        <label for="photos">Photos (up to 6)</label>
        <input class="input" id="photos" name="photos[]" type="file" accept="image/jpeg,image/png,image/webp" multiple>
        <div class="small muted" style="margin-top:4px">JPG, PNG or WebP · max 4 MB each. The first photo becomes the cover.</div>
      </div>

      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="deposit_amount">Refundable deposit (<?= APP_CURRENCY ?>)</label><input class="input" id="deposit_amount" name="deposit_amount" type="number" min="0" step="50" value="<?= e($old['deposit_amount']) ?>"></div>
        <div class="field"><label for="daily_fee">Daily fee (<?= APP_CURRENCY ?>, optional)</label><input class="input" id="daily_fee" name="daily_fee" type="number" min="0" step="10" value="<?= e($old['daily_fee']) ?>"></div>
      </div>

      <div class="grid grid-2" style="gap:14px">
        <div class="field"><label for="max_borrow_days">Max borrowing period (days)</label><input class="input" id="max_borrow_days" name="max_borrow_days" type="number" min="1" max="90" value="<?= e($old['max_borrow_days']) ?>"></div>
        <div class="field"><label for="area">Pickup area</label><input class="input" id="area" name="area" value="<?= e($old['area']) ?>"></div>
      </div>

      <div class="field"><label for="city">City</label><input class="input" id="city" name="city" value="<?= e($old['city']) ?>"></div>
      <div class="field"><label for="rules">Rules for borrowers</label><textarea class="textarea" id="rules" name="rules" style="min-height:80px" placeholder="Return by the agreed date, wipe it down, no third-party lending…"><?= e($old['rules']) ?></textarea></div>
      <div class="field"><label for="pickup_instructions">Pickup &amp; return instructions</label><textarea class="textarea" id="pickup_instructions" name="pickup_instructions" style="min-height:80px" placeholder="Where and when should the borrower collect it?"><?= e($old['pickup_instructions']) ?></textarea></div>

      <div class="flex gap-8">
        <button class="btn btn-primary" type="submit">Submit for review</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/my_listings.php">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
