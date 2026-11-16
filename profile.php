<?php
/**
 * profile.php — public profile with reputation, badges, listings and reviews
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

$me   = current_user();
$uid  = (int) (get('u') ?: ($me['user_id'] ?? 0));
if ($uid <= 0) {
    redirect(APP_URL . '/login.php');
}

$stmt = db()->prepare(
    'SELECT u.user_id, u.role, u.status, u.created_at, p.*
       FROM users u JOIN profiles p ON p.user_id = u.user_id
      WHERE u.user_id = ?'
);
$stmt->execute([$uid]);
$profile = $stmt->fetch();
if (!$profile) {
    http_response_code(404);
    exit('Profile not found.');
}

$isSelf = $me && (int) $me['user_id'] === $uid;

// Listings
$stmt = db()->prepare(
    "SELECT i.*, c.name AS category_name,
            (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC, sort_order LIMIT 1) AS photo
       FROM items i JOIN categories c ON c.category_id = i.category_id
      WHERE i.owner_id = ? AND i.status IN ('active','unavailable')
      ORDER BY i.created_at DESC LIMIT 8"
);
$stmt->execute([$uid]);
$listings = $stmt->fetchAll();

// Reviews received
$stmt = db()->prepare(
    'SELECT r.*, p.full_name AS reviewer_name, p.username AS reviewer_username, i.title AS item_title
       FROM reviews r
       JOIN profiles p ON p.user_id = r.reviewer_id
       LEFT JOIN items i ON i.item_id = r.item_id
      WHERE r.reviewee_id = ? AND r.is_visible = 1
      ORDER BY r.created_at DESC LIMIT 10'
);
$stmt->execute([$uid]);
$reviews = $stmt->fetchAll();

// Badges
$stmt = db()->prepare(
    'SELECT b.* FROM user_badges ub JOIN badges b ON b.badge_id = ub.badge_id
      WHERE ub.user_id = ? ORDER BY b.tier DESC, b.badge_id'
);
$stmt->execute([$uid]);
$badges = $stmt->fetchAll();

// Criteria averages
$stmt = db()->prepare(
    'SELECT AVG(reliability) rel, AVG(communication) com, AVG(item_condition) cond,
            AVG(timely_return) tim, AVG(overall) ov, COUNT(*) n
       FROM reviews WHERE reviewee_id = ? AND is_visible = 1'
);
$stmt->execute([$uid]);
$crit = $stmt->fetch();

$pageTitle = $profile['full_name'] . ' — BIB';
$activeNav = '';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div class="container">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/index.php">Home</a> <span>/</span> <span>Profile</span></div>
    <div class="flex center gap-16 wrap">
      <?php if ($profile['avatar_path']): ?>
        <img class="avatar avatar-xl" src="<?= UPLOAD_URL . '/' . e($profile['avatar_path']) ?>" alt="">
      <?php else: ?>
        <span class="avatar avatar-xl" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($profile['full_name'], 0, 2))) ?></span>
      <?php endif; ?>
      <div style="flex:1;min-width:220px">
        <h1 style="margin-bottom:4px"><?= e($profile['full_name']) ?>
          <?php if ($profile['is_id_verified']): ?><span class="badge badge-ok" style="font-size:.62rem;vertical-align:6px">✓ Verified</span><?php endif; ?>
        </h1>
        <p style="margin:0">@<?= e($profile['username']) ?> · <?= e($profile['area'] ?: 'Dhaka') ?><?= $profile['city'] ? ', ' . e($profile['city']) : '' ?> · Member since <?= date('M Y', strtotime($profile['created_at'])) ?></p>
      </div>
      <div class="flex gap-8">
        <?php if ($isSelf): ?>
          <a class="btn btn-outline" href="<?= APP_URL ?>/edit_profile.php">Edit profile</a>
          <a class="btn btn-primary" href="<?= APP_URL ?>/list_item.php">List an item</a>
        <?php elseif ($me): ?>
          <a class="btn btn-outline" href="<?= APP_URL ?>/messages.php?to=<?= $uid ?>">Message</a>
          <a class="btn btn-ghost" href="<?= APP_URL ?>/report.php?user=<?= $uid ?>">Report</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="container" style="padding:32px 24px 80px">
  <div class="two-col">
    <div>
      <?php if ($profile['bio']): ?>
        <div class="panel">
          <div class="panel-head"><h3>About</h3></div>
          <div class="panel-body"><p style="margin:0"><?= nl2br(e($profile['bio'])) ?></p></div>
        </div>
      <?php endif; ?>

      <div class="panel">
        <div class="panel-head">
          <h3>Listings (<?= count($listings) ?>)</h3>
          <?php if ($isSelf): ?><a href="<?= APP_URL ?>/my_listings.php">Manage all</a><?php endif; ?>
        </div>
        <div class="panel-body">
          <?php if (!$listings): ?>
            <div class="empty">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg>
              <p>No active listings yet.</p>
            </div>
          <?php else: ?>
            <div class="grid grid-3">
              <?php foreach ($listings as $item): ?>
                <article class="item-card">
                  <div class="item-media">
                    <img src="<?= $item['photo'] ? UPLOAD_URL . '/' . e($item['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="<?= e($item['title']) ?>" loading="lazy">
                    <div class="item-badges">
                      <span class="badge <?= $item['status'] === 'active' ? 'badge-green' : 'badge-warn' ?>"><?= $item['status'] === 'active' ? 'Available' : 'Unavailable' ?></span>
                    </div>
                  </div>
                  <div class="item-body">
                    <span class="item-cat"><?= e($item['category_name']) ?></span>
                    <h3 class="item-title"><a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $item['item_id'] ?>"><?= e($item['title']) ?></a></h3>
                    <div class="item-foot">
                      <span class="price"><?= (float) $item['daily_fee'] > 0 ? money($item['daily_fee']) . ' <small>/ day</small>' : 'Free' ?></span>
                      <span class="owner-mini"><?= (int) $item['borrow_count'] ?> borrows</span>
                    </div>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Reviews (<?= (int) ($crit['n'] ?? 0) ?>)</h3></div>
        <div class="panel-body">
          <?php if (!$reviews): ?>
            <div class="empty"><p>No reviews yet.</p></div>
          <?php else: ?>
            <?php foreach ($reviews as $r): ?>
              <div class="review">
                <div class="review-head">
                  <span class="avatar"><?= e(strtoupper(substr($r['reviewer_name'], 0, 2))) ?></span>
                  <div>
                    <b><?= e($r['reviewer_name']) ?></b><br>
                    <span><?= e($r['item_title'] ?: 'Borrow') ?> · <?= nice_date($r['created_at']) ?></span>
                  </div>
                  <span class="rating" style="margin-left:auto">
                    <svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg>
                    <?= number_format((float) $r['overall'], 1) ?>
                  </span>
                </div>
                <?php if ($r['comment']): ?><p><?= nl2br(e($r['comment'])) ?></p><?php endif; ?>
                <div class="criteria">
                  <span>Reliability <b><?= (int) $r['reliability'] ?></b></span>
                  <span>Communication <b><?= (int) $r['communication'] ?></b></span>
                  <span>Condition <b><?= (int) $r['item_condition'] ?></b></span>
                  <span>Timely return <b><?= (int) $r['timely_return'] ?></b></span>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div>
      <div class="panel">
        <div class="panel-head"><h3>Reputation</h3></div>
        <div class="panel-body">
          <div style="text-align:center;margin-bottom:16px">
            <div style="font-family:var(--font-display);font-size:3rem;line-height:1;color:var(--green-700)"><?= (int) round((float) $profile['reputation_score']) ?></div>
            <div class="small muted">out of 100</div>
          </div>
          <?php
          $bars = [
              ['Reliability', (float) ($crit['rel'] ?? 0)],
              ['Communication', (float) ($crit['com'] ?? 0)],
              ['Item condition', (float) ($crit['cond'] ?? 0)],
              ['Timely return', (float) ($crit['tim'] ?? 0)],
              ['Overall', (float) ($crit['ov'] ?? 0)],
          ];
          foreach ($bars as [$label, $val]): ?>
            <div class="bar-row">
              <div class="bl"><span><?= e($label) ?></span><b><?= number_format($val, 1) ?></b></div>
              <div class="bar"><i style="width:<?= (int) round($val / 5 * 100) ?>%"></i></div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Badges</h3></div>
        <div class="panel-body">
          <?php if (!$badges): ?>
            <p class="small muted" style="margin:0">No badges yet — complete your first borrow to earn one.</p>
          <?php else: ?>
            <div class="pill-row">
              <?php foreach ($badges as $b): ?>
                <span class="badge <?= $b['tier'] === 'gold' || $b['tier'] === 'platinum' ? 'badge-amber' : ($b['tier'] === 'silver' ? 'badge-info' : 'badge-ok') ?>" title="<?= e($b['description']) ?>">
                  <?= e($b['icon']) ?> <?= e($b['name']) ?>
                </span>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div class="panel">
        <div class="panel-head"><h3>Activity</h3></div>
        <div class="panel-body">
          <div class="flex between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span class="small muted">Items listed</span><b><?= (int) $profile['total_lends'] ?></b></div>
          <div class="flex between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span class="small muted">Completed lends</span><b><?= (int) $profile['total_lends'] ?></b></div>
          <div class="flex between" style="padding:8px 0;border-bottom:1px solid var(--line)"><span class="small muted">Completed borrows</span><b><?= (int) $profile['total_borrows'] ?></b></div>
          <div class="flex between" style="padding:8px 0"><span class="small muted">Avg. reply time</span><b><?= (int) $profile['response_minutes'] ?> min</b></div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
