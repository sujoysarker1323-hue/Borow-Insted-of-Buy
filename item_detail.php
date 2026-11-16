<?php
/**
 * item_detail.php — public item page: gallery, owner reputation, availability
 * calendar, and the Request to Borrow / Save / Share / Contact / Report actions.
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = current_user();

$itemId = (int) get('id');
$stmt = db()->prepare(
    'SELECT i.*, c.name AS category_name, c.slug AS category_slug,
            p.user_id AS owner_id, p.username, p.full_name, p.avatar_path, p.city AS owner_city,
            p.area AS owner_area, p.reputation_score, p.avg_rating, p.is_id_verified,
            p.total_lends, p.response_minutes, p.bio AS owner_bio
       FROM items i
       JOIN categories c ON c.category_id = i.category_id
       JOIN profiles p ON p.user_id = i.owner_id
      WHERE i.item_id = ? AND i.status IN ("active","unavailable")'
);
$stmt->execute([$itemId]);
$item = $stmt->fetch();

if (!$item) {
    http_response_code(404);
    $pageTitle = 'Item not found — BIB';
    require __DIR__ . '/includes/header.php';
    echo '<div class="container" style="padding:80px 24px;text-align:center"><h1>Item not found</h1><p>This listing may have been removed.</p><a class="btn btn-primary" href="' . APP_URL . '/search.php">Browse other items</a></div>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$stmt = db()->prepare('SELECT * FROM item_photos WHERE item_id = ? ORDER BY is_primary DESC, sort_order');
$stmt->execute([$itemId]);
$photos = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM availability WHERE item_id = ? ORDER BY start_date');
$stmt->execute([$itemId]);
$availability = $stmt->fetchAll();

// Dates already committed to an active borrow
$stmt = db()->prepare(
    "SELECT start_date, due_date FROM transactions
      WHERE item_id = ? AND status IN ('accepted','pickup','borrowed','return_requested')"
);
$stmt->execute([$itemId]);
$booked = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM reviews WHERE reviewee_id = ? AND is_visible = 1 ORDER BY created_at DESC LIMIT 3');
$stmt->execute([$item['owner_id']]);
$ownerReviews = $stmt->fetchAll();

$stmt = db()->prepare('SELECT b.name, b.icon, b.tier FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id WHERE ub.user_id = ? LIMIT 4');
$stmt->execute([$item['owner_id']]);
$ownerBadges = $stmt->fetchAll();

$isOwner = $me && (int) $me['user_id'] === (int) $item['owner_id'];
$saved = false;
if ($me) {
    $stmt = db()->prepare('SELECT 1 FROM wishlist WHERE user_id = ? AND item_id = ?');
    $stmt->execute([$me['user_id'], $itemId]);
    $saved = (bool) $stmt->fetchColumn();
}

$similar = db()->prepare(
    "SELECT i.item_id, i.title, i.deposit_amount, i.daily_fee, i.area,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC LIMIT 1) AS photo
       FROM items i
      WHERE i.category_id = ? AND i.item_id <> ? AND i.status = 'active'
      ORDER BY i.borrow_count DESC LIMIT 3"
);
$similar->execute([$item['category_id'], $itemId]);
$similarItems = $similar->fetchAll();

$pageTitle = $item['title'] . ' — BIB';
$activeNav = 'browse';
require __DIR__ . '/includes/header.php';
?>
<div class="container" style="padding:24px 24px 0">
  <div class="breadcrumb">
    <a href="<?= APP_URL ?>/index.php">Home</a> <span>/</span>
    <a href="<?= APP_URL ?>/search.php?category=<?= e($item['category_slug']) ?>"><?= e($item['category_name']) ?></a> <span>/</span>
    <span><?= e($item['title']) ?></span>
  </div>
</div>

<div class="container" style="padding:8px 24px 80px">
  <div class="detail-grid">
    <div>
      <div class="gallery">
        <div class="gallery-main">
          <img id="galleryMain" src="<?= $photos ? UPLOAD_URL . '/' . e($photos[0]['file_path']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="<?= e($item['title']) ?>">
          <div class="item-badges" style="top:14px;left:14px">
            <span class="badge <?= $item['status'] === 'active' ? 'badge-green' : 'badge-warn' ?>"><?= $item['status'] === 'active' ? 'Available' : 'Currently unavailable' ?></span>
            <span class="badge badge-amber"><?= ucwords(str_replace('_', ' ', $item['item_condition'])) ?></span>
          </div>
        </div>
        <?php if (count($photos) > 1): ?>
          <div class="gallery-thumbs">
            <?php foreach ($photos as $i => $p): ?>
              <button class="thumb<?= $i === 0 ? ' active' : '' ?>" data-gallery-thumb data-src="<?= UPLOAD_URL . '/' . e($p['file_path']) ?>">
                <img src="<?= UPLOAD_URL . '/' . e($p['file_path']) ?>" alt="Photo <?= $i + 1 ?>">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="panel">
        <div class="panel-body">
          <div class="flex between center wrap gap-12" style="margin-bottom:6px">
            <span class="item-cat"><?= e($item['category_name']) ?></span>
            <span class="small muted">Listed <?= time_ago($item['created_at']) ?> · <?= (int) $item['view_count'] ?> views</span>
          </div>
          <h1 style="font-size:2rem;margin-bottom:10px"><?= e($item['title']) ?></h1>
          <div class="flex gap-16 wrap small muted" style="margin-bottom:18px">
            <?php if ($item['brand']): ?><span><b style="color:var(--ink)">Brand</b> <?= e($item['brand']) ?></span><?php endif; ?>
            <?php if ($item['model']): ?><span><b style="color:var(--ink)">Model</b> <?= e($item['model']) ?></span><?php endif; ?>
            <span><b style="color:var(--ink)">Location</b> <?= e($item['area']) ?>, <?= e($item['city']) ?></span>
            <span><b style="color:var(--ink)">Max period</b> <?= (int) $item['max_borrow_days'] ?> days</span>
          </div>
          <p style="white-space:pre-line"><?= e($item['description']) ?></p>

          <?php if ($item['rules']): ?>
            <h3 style="font-size:1.05rem;margin:24px 0 8px">Rules for borrowers</h3>
            <p class="small" style="white-space:pre-line;color:var(--ink-2)"><?= e($item['rules']) ?></p>
          <?php endif; ?>

          <?php if ($item['pickup_instructions']): ?>
            <h3 style="font-size:1.05rem;margin:24px 0 8px">Pickup &amp; return</h3>
            <p class="small" style="white-space:pre-line;color:var(--ink-2)"><?= e($item['pickup_instructions']) ?></p>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head">
          <h3>Availability</h3>
          <span class="small muted">Next 60 days</span>
        </div>
        <div class="panel-body">
          <div class="cal-legend">
            <span><i class="dot free"></i> Available</span>
            <span><i class="dot booked"></i> Booked</span>
            <span><i class="dot blocked"></i> Blocked</span>
          </div>
          <div class="calendar" id="availabilityCalendar"
               data-blocked='<?= e(json_encode(array_map(fn($a) => ['start' => $a['start_date'], 'end' => $a['end_date']], array_filter($availability, fn($a) => (int) $a['is_blocked'] === 1)))) ?>'
               data-booked='<?= e(json_encode(array_map(fn($b) => ['start' => $b['start_date'], 'end' => $b['due_date']], $booked))) ?>'>
          </div>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Reviews of <?= e($item['full_name']) ?></h3><a href="<?= APP_URL ?>/profile.php?u=<?= (int) $item['owner_id'] ?>">See all</a></div>
        <div class="panel-body">
          <?php if (!$ownerReviews): ?>
            <p class="small muted" style="margin:0">No reviews yet — this lender is new to BIB.</p>
          <?php else: ?>
            <?php foreach ($ownerReviews as $r): ?>
              <div class="review">
                <div class="review-head">
                  <span class="avatar"><?= e(strtoupper(substr($r['comment'] ?: 'B', 0, 2))) ?></span>
                  <div><b>Verified borrower</b><br><span><?= nice_date($r['created_at']) ?></span></div>
                  <span class="rating" style="margin-left:auto">
                    <svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg>
                    <?= number_format((float) $r['overall'], 1) ?>
                  </span>
                </div>
                <?php if ($r['comment']): ?><p><?= e($r['comment']) ?></p><?php endif; ?>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <aside>
      <div class="panel sticky">
        <div class="panel-body">
          <div class="price-block">
            <div>
              <span class="price-lg"><?= (float) $item['daily_fee'] > 0 ? money($item['daily_fee']) : 'Free' ?></span>
              <?php if ((float) $item['daily_fee'] > 0): ?><span class="small muted">/ day</span><?php endif; ?>
            </div>
            <div class="small muted" style="text-align:right">
              Deposit<br><b style="color:var(--ink);font-size:1rem"><?= (float) $item['deposit_amount'] > 0 ? money($item['deposit_amount']) : 'None' ?></b>
            </div>
          </div>

          <div class="owner-card">
            <?php if ($item['avatar_path']): ?>
              <img class="avatar avatar-lg" src="<?= UPLOAD_URL . '/' . e($item['avatar_path']) ?>" alt="">
            <?php else: ?>
              <span class="avatar avatar-lg" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($item['full_name'], 0, 2))) ?></span>
            <?php endif; ?>
            <div style="flex:1">
              <b><?= e($item['full_name']) ?> <?php if ($item['is_id_verified']): ?><span class="badge badge-ok" style="font-size:.58rem">✓</span><?php endif; ?></b>
              <div class="small muted">@<?= e($item['username']) ?> · <?= e($item['owner_area']) ?></div>
              <div class="flex center gap-8" style="margin-top:4px">
                <span class="rating"><svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg> <?= number_format((float) $item['avg_rating'], 1) ?></span>
                <span class="small muted"><?= (int) $item['total_lends'] ?> lends</span>
              </div>
            </div>
          </div>

          <div class="rep-mini">
            <div class="rep-ring" style="--pct:<?= (int) round((float) $item['reputation_score']) ?>">
              <span><?= (int) round((float) $item['reputation_score']) ?></span>
            </div>
            <div>
              <b class="small">BIB reputation</b>
              <div class="small muted">Replies in ~<?= (int) $item['response_minutes'] ?> min</div>
            </div>
          </div>

          <?php if ($ownerBadges): ?>
            <div class="pill-row" style="margin-bottom:16px">
              <?php foreach ($ownerBadges as $b): ?>
                <span class="badge badge-amber" style="font-size:.62rem"><?= e($b['icon']) ?> <?= e($b['name']) ?></span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <?php if ($isOwner): ?>
            <a class="btn btn-primary btn-block btn-lg" href="<?= APP_URL ?>/edit_item.php?id=<?= $itemId ?>">Edit this listing</a>
            <a class="btn btn-outline btn-block" href="<?= APP_URL ?>/my_listings.php" style="margin-top:8px">Manage listings</a>
          <?php elseif ($item['status'] !== 'active'): ?>
            <button class="btn btn-primary btn-block btn-lg" disabled>Currently unavailable</button>
            <p class="small muted" style="text-align:center;margin-top:10px">The owner has paused this listing.</p>
          <?php else: ?>
            <button class="btn btn-primary btn-block btn-lg" data-open-modal="borrowModal">Request to borrow</button>
            <div class="action-row">
              <button class="btn btn-outline" data-wishlist="<?= $itemId ?>" data-saved="<?= $saved ? '1' : '0' ?>">
                <svg viewBox="0 0 24 24" fill="<?= $saved ? 'currentColor' : 'none' ?>" stroke="currentColor" stroke-width="1.9"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>
                <?= $saved ? 'Saved' : 'Save' ?>
              </button>
              <button class="btn btn-outline" data-share data-share-title="<?= e($item['title']) ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
                Share
              </button>
            </div>
            <a class="btn btn-ghost btn-block" href="<?= APP_URL ?>/messages.php?to=<?= (int) $item['owner_id'] ?>&item=<?= $itemId ?>">Contact owner</a>
            <a class="btn btn-ghost btn-block small" href="<?= APP_URL ?>/report.php?item=<?= $itemId ?>" style="color:var(--muted)">Report this listing</a>
          <?php endif; ?>

          <div class="trust-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2l8 4v6c0 5-3.5 8.5-8 10-4.5-1.5-8-5-8-10V6z"/><path d="M9 12l2 2 4-4"/></svg>
            <span>Deposits are held by BIB and released automatically once both parties confirm the return.</span>
          </div>
        </div>
      </div>
    </aside>
  </div>

  <?php if ($similarItems): ?>
    <section style="margin-top:48px">
      <div class="section-head"><div><h2>Similar items nearby</h2></div><a class="btn btn-ghost" href="<?= APP_URL ?>/search.php?category=<?= e($item['category_slug']) ?>">See all</a></div>
      <div class="grid grid-3">
        <?php foreach ($similarItems as $s): ?>
          <article class="item-card">
            <div class="item-media">
              <img src="<?= $s['photo'] ? UPLOAD_URL . '/' . e($s['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="<?= e($s['title']) ?>" loading="lazy">
            </div>
            <div class="item-body">
              <h3 class="item-title"><a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $s['item_id'] ?>"><?= e($s['title']) ?></a></h3>
              <div class="item-foot">
                <span class="price"><?= (float) $s['daily_fee'] > 0 ? money($s['daily_fee']) . ' <small>/ day</small>' : 'Free' ?></span>
                <span class="owner-mini"><?= e($s['area']) ?></span>
              </div>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<!-- Borrow request modal -->
<div class="modal" id="borrowModal" aria-hidden="true">
  <div class="modal-card">
    <div class="modal-head">
      <h3>Request to borrow</h3>
      <button class="icon-btn" data-close-modal aria-label="Close">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 6L6 18M6 6l12 12"/></svg>
      </button>
    </div>
    <form method="post" action="<?= APP_URL ?>/request_borrow.php">
      <?= csrf_field() ?>
      <input type="hidden" name="item_id" value="<?= $itemId ?>">
      <div class="modal-body">
        <div class="flex center gap-12" style="margin-bottom:18px">
          <img src="<?= $photos ? UPLOAD_URL . '/' . e($photos[0]['file_path']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="" style="width:56px;height:56px;border-radius:10px;object-fit:cover">
          <div>
            <b><?= e($item['title']) ?></b>
            <div class="small muted"><?= (float) $item['deposit_amount'] > 0 ? money($item['deposit_amount']) . ' deposit' : 'No deposit' ?> · max <?= (int) $item['max_borrow_days'] ?> days</div>
          </div>
        </div>
        <div class="grid grid-2" style="gap:12px">
          <div class="field"><label for="start_date">From</label><input class="input" id="start_date" name="start_date" type="date" min="<?= date('Y-m-d') ?>" required></div>
          <div class="field"><label for="end_date">Until</label><input class="input" id="end_date" name="end_date" type="date" min="<?= date('Y-m-d') ?>" required></div>
        </div>
        <div class="field">
          <label for="reason">What do you need it for?</label>
          <textarea class="textarea" id="reason" name="reason" style="min-height:70px" placeholder="A short note helps the owner decide." required></textarea>
        </div>
        <div class="field">
          <label for="message">Message to the owner (optional)</label>
          <textarea class="textarea" id="message" name="message" style="min-height:60px" placeholder="Pickup time, questions…"></textarea>
        </div>
        <div class="panel" style="background:var(--surface-2);border-color:transparent">
          <div class="panel-body" style="padding:12px 14px">
            <div class="flex between small"><span class="muted">Deposit (refundable)</span><b><?= money($item['deposit_amount']) ?></b></div>
            <div class="flex between small" style="margin-top:4px"><span class="muted">Fee</span><b data-fee-preview>—</b></div>
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-ghost" type="button" data-close-modal>Cancel</button>
        <button class="btn btn-primary" type="submit">Send request</button>
      </div>
    </form>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
