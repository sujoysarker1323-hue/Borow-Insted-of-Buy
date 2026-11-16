<?php
/**
 * search.php — keyword + faceted search over active listings
 * Filters: category, location, distance, availability, condition, deposit/fee, rating
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = current_user();

$q          = get('q');
$category   = get('category');
$location   = get('location');
$distance   = (int) (get('distance') ?: 0);
$condition  = get('condition');
$maxDeposit = get('max_deposit');
$maxFee     = get('max_fee');
$minRating  = (float) (get('min_rating') ?: 0);
$available  = get('available') === '1';
$sort       = get('sort', 'relevance');
$page       = max(1, (int) (get('page') ?: 1));

$where  = ["i.status = 'active'"];
$params = [];

if ($q !== '') {
    $where[] = '(i.title LIKE ? OR i.description LIKE ? OR i.brand LIKE ? OR i.model LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($category !== '') {
    $where[] = 'c.slug = ?';
    $params[] = $category;
}
if ($location !== '') {
    $where[] = '(i.area LIKE ? OR i.city LIKE ?)';
    $params[] = '%' . $location . '%';
    $params[] = '%' . $location . '%';
}
if ($condition !== '' && in_array($condition, ['new', 'like_new', 'good', 'fair'], true)) {
    $where[] = 'i.item_condition = ?';
    $params[] = $condition;
}
if ($maxDeposit !== '') {
    $where[] = 'i.deposit_amount <= ?';
    $params[] = (float) $maxDeposit;
}
if ($maxFee !== '') {
    $where[] = 'i.daily_fee <= ?';
    $params[] = (float) $maxFee;
}
if ($minRating > 0) {
    $where[] = 'p.avg_rating >= ?';
    $params[] = $minRating;
}
if ($available) {
    $where[] = "NOT EXISTS (
        SELECT 1 FROM transactions t
         WHERE t.item_id = i.item_id
           AND t.status IN ('accepted','pickup','borrowed','return_requested')
           AND t.due_date >= CURDATE()
    )";
}

$orderBy = match ($sort) {
    'newest'    => 'i.created_at DESC',
    'price_low' => 'i.daily_fee ASC, i.deposit_amount ASC',
    'rating'    => 'p.avg_rating DESC, p.reputation_score DESC',
    'popular'   => 'i.borrow_count DESC, i.view_count DESC',
    default     => 'i.is_featured DESC, p.reputation_score DESC, i.created_at DESC',
};

$whereSql = implode(' AND ', $where);

$countStmt = db()->prepare(
    "SELECT COUNT(*) FROM items i
       JOIN categories c ON c.category_id = i.category_id
       JOIN profiles p ON p.user_id = i.owner_id
      WHERE $whereSql"
);
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$pg = paginate($total, MAX_ITEMS_PER_PAGE, $page);

$sql = "SELECT i.*, c.name AS category_name, c.slug AS category_slug,
               p.full_name, p.username, p.area AS owner_area, p.avg_rating,
               p.reputation_score, p.is_id_verified,
               (SELECT file_path FROM item_photos WHERE item_id = i.item_id ORDER BY is_primary DESC, sort_order LIMIT 1) AS photo
          FROM items i
          JOIN categories c ON c.category_id = i.category_id
          JOIN profiles p ON p.user_id = i.owner_id
         WHERE $whereSql
         ORDER BY $orderBy
         LIMIT {$pg['per']} OFFSET {$pg['offset']}";
$stmt = db()->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$categories = db()->query(
    'SELECT c.*, (SELECT COUNT(*) FROM items i WHERE i.category_id = c.category_id AND i.status = "active") AS item_count
       FROM categories c WHERE c.is_active = 1 ORDER BY c.sort_order'
)->fetchAll();

$pageTitle = ($q !== '' ? '“' . $q . '” — ' : '') . 'Browse items — BIB';
$activeNav = 'browse';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div class="container">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/index.php">Home</a> <span>/</span> <span>Browse</span></div>
    <h1 style="font-size:2rem;margin-bottom:6px"><?= $q !== '' ? 'Results for “' . e($q) . '”' : 'Browse the neighbourhood' ?></h1>
    <p style="margin:0"><?= number_format($total) ?> item<?= $total === 1 ? '' : 's' ?> available to borrow<?= $location !== '' ? ' near ' . e($location) : ' in Dhaka' ?>.</p>

    <form class="search-bar" method="get" action="<?= APP_URL ?>/search.php" style="margin-top:20px;max-width:720px">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
      <input type="search" name="q" value="<?= e($q) ?>" placeholder="Search drills, tents, projectors…" aria-label="Search items">
      <input type="hidden" name="category" value="<?= e($category) ?>">
      <button class="btn btn-primary" type="submit">Search</button>
    </form>
  </div>
</div>

<div class="container" style="padding:28px 24px 80px">
  <div class="search-layout">
    <aside class="filters" id="filters">
      <form method="get" action="<?= APP_URL ?>/search.php">
        <input type="hidden" name="q" value="<?= e($q) ?>">

        <div class="filter-group">
          <h4>Category</h4>
          <label class="check"><input type="radio" name="category" value=""<?= $category === '' ? ' checked' : '' ?>> All categories</label>
          <?php foreach ($categories as $c): ?>
            <label class="check">
              <input type="radio" name="category" value="<?= e($c['slug']) ?>"<?= $category === $c['slug'] ? ' checked' : '' ?>>
              <?= e($c['name']) ?> <span class="muted" style="margin-left:auto;font-size:.75rem"><?= (int) $c['item_count'] ?></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="filter-group">
          <h4>Location</h4>
          <input class="input" name="location" value="<?= e($location) ?>" placeholder="Area or city">
          <div class="field" style="margin-top:10px">
            <label for="distance" class="small muted">Within</label>
            <select class="select" id="distance" name="distance">
              <option value="0">Any distance</option>
              <?php foreach ([1, 3, 5, 10, 20] as $km): ?>
                <option value="<?= $km ?>"<?= $distance === $km ? ' selected' : '' ?>><?= $km ?> km</option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="filter-group">
          <h4>Availability</h4>
          <label class="check"><input type="checkbox" name="available" value="1"<?= $available ? ' checked' : '' ?>> Available now</label>
        </div>

        <div class="filter-group">
          <h4>Condition</h4>
          <?php foreach (['new' => 'New', 'like_new' => 'Like new', 'good' => 'Good', 'fair' => 'Fair'] as $v => $l): ?>
            <label class="check"><input type="radio" name="condition" value="<?= $v ?>"<?= $condition === $v ? ' checked' : '' ?>> <?= $l ?></label>
          <?php endforeach; ?>
          <label class="check"><input type="radio" name="condition" value=""<?= $condition === '' ? ' checked' : '' ?>> Any condition</label>
        </div>

        <div class="filter-group">
          <h4>Deposit &amp; fee</h4>
          <div class="field"><label for="max_deposit" class="small muted">Max deposit (<?= APP_CURRENCY ?>)</label><input class="input" id="max_deposit" name="max_deposit" type="number" min="0" step="100" value="<?= e($maxDeposit) ?>" placeholder="Any"></div>
          <div class="field"><label for="max_fee" class="small muted">Max daily fee (<?= APP_CURRENCY ?>)</label><input class="input" id="max_fee" name="max_fee" type="number" min="0" step="10" value="<?= e($maxFee) ?>" placeholder="Any"></div>
        </div>

        <div class="filter-group">
          <h4>Owner rating</h4>
          <?php foreach ([0 => 'Any rating', 4 => '4.0 and up', 4.5 => '4.5 and up'] as $v => $l): ?>
            <label class="check"><input type="radio" name="min_rating" value="<?= $v ?>"<?= (float) $minRating === (float) $v ? ' checked' : '' ?>> <?= $l ?></label>
          <?php endforeach; ?>
        </div>

        <input type="hidden" name="sort" value="<?= e($sort) ?>">
        <button class="btn btn-primary btn-block" type="submit">Apply filters</button>
        <a class="btn btn-ghost btn-block" href="<?= APP_URL ?>/search.php" style="margin-top:8px">Clear all</a>
      </form>
    </aside>

    <div>
      <div class="flex between center wrap gap-12" style="margin-bottom:18px">
        <button class="btn btn-outline btn-sm filter-toggle" data-filter-toggle>Filters</button>
        <div class="flex center gap-8" style="margin-left:auto">
          <span class="small muted">Sort</span>
          <form method="get" action="<?= APP_URL ?>/search.php" id="sortForm">
            <?php foreach (['q' => $q, 'category' => $category, 'location' => $location, 'condition' => $condition, 'max_deposit' => $maxDeposit, 'max_fee' => $maxFee, 'min_rating' => $minRating ?: '', 'available' => $available ? '1' : '', 'distance' => $distance ?: ''] as $k => $v): ?>
              <?php if ($v !== '' && $v !== null): ?><input type="hidden" name="<?= $k ?>" value="<?= e((string) $v) ?>"><?php endif; ?>
            <?php endforeach; ?>
            <select class="select" name="sort" onchange="this.form.submit()" style="min-width:170px">
              <option value="relevance"<?= $sort === 'relevance' ? ' selected' : '' ?>>Best match</option>
              <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>Newest first</option>
              <option value="price_low"<?= $sort === 'price_low' ? ' selected' : '' ?>>Lowest fee</option>
              <option value="rating"<?= $sort === 'rating' ? ' selected' : '' ?>>Highest rated owner</option>
              <option value="popular"<?= $sort === 'popular' ? ' selected' : '' ?>>Most borrowed</option>
            </select>
          </form>
        </div>
      </div>

      <?php if (!$items): ?>
        <div class="panel">
          <div class="empty">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>
            <h3>No items match those filters</h3>
            <p>Try widening the distance, raising the deposit limit, or clearing the category.</p>
            <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/search.php">Clear filters</a>
          </div>
        </div>
      <?php else: ?>
        <div class="grid grid-3">
          <?php foreach ($items as $it): ?>
            <article class="item-card">
              <div class="item-media">
                <a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>">
                  <img src="<?= $it['photo'] ? UPLOAD_URL . '/' . e($it['photo']) : APP_URL . '/assets/img/placeholder.svg' ?>" alt="<?= e($it['title']) ?>" loading="lazy">
                </a>
                <div class="item-badges">
                  <span class="badge badge-green">Available</span>
                  <?php if ($it['is_featured']): ?><span class="badge badge-amber">Featured</span><?php endif; ?>
                </div>
                <button class="fav-btn" data-wishlist="<?= (int) $it['item_id'] ?>" data-saved="0" aria-label="Save to wishlist">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8z"/></svg>
                </button>
              </div>
              <div class="item-body">
                <span class="item-cat"><?= e($it['category_name']) ?></span>
                <h3 class="item-title"><a href="<?= APP_URL ?>/item_detail.php?id=<?= (int) $it['item_id'] ?>"><?= e($it['title']) ?></a></h3>
                <div class="item-meta">
                  <span class="rating"><svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg> <?= number_format((float) $it['avg_rating'], 1) ?></span>
                  <span>·</span>
                  <span><?= e($it['owner_area']) ?></span>
                  <span>·</span>
                  <span><?= ucwords(str_replace('_', ' ', $it['item_condition'])) ?></span>
                </div>
                <div class="item-foot">
                  <span class="price"><?= (float) $it['daily_fee'] > 0 ? money($it['daily_fee']) . ' <small>/ day</small>' : 'Free' ?></span>
                  <span class="owner-mini"><?= (float) $it['deposit_amount'] > 0 ? money($it['deposit_amount']) . ' deposit' : 'No deposit' ?></span>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>

        <?php if ($pg['pages'] > 1): ?>
          <nav class="pagination" aria-label="Pagination">
            <?php
            $qs = $_GET;
            for ($p = 1; $p <= $pg['pages']; $p++):
                $qs['page'] = $p;
            ?>
              <a href="?<?= e(http_build_query($qs)) ?>"<?= $p === $pg['current'] ? ' class="active"' : '' ?>><?= $p ?></a>
            <?php endfor; ?>
          </nav>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
