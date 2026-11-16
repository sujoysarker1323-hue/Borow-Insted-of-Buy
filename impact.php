<?php
/**
 * impact.php — sustainability impact dashboard
 *
 * The admin view v_platform_stats intentionally exposes admin-oriented
 * metrics (active_items, completed_borrows, etc.).  This page needs a
 * different set of sustainability fields, so they are calculated here
 * directly from the transaction/item/category data instead of reading
 * non-existent keys from v_platform_stats.
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();

$pdo = db();

// ---------------------------------------------------------------------------
// Sustainability KPIs
// ---------------------------------------------------------------------------
// Retail-price estimates are category averages used only for the impact
// estimate.  They are deliberately kept here so the dashboard can work for
// the existing schema, which does not store a retail_price per item.
$statsStmt = $pdo->query(
    "SELECT
        (SELECT COUNT(*)
           FROM items
          WHERE status = 'active') AS total_items,

        (SELECT COUNT(*)
           FROM transactions
          WHERE status = 'completed') AS total_borrows,

        (SELECT COALESCE(SUM(
            CASE c.slug
                WHEN 'power-tools'  THEN  8000
                WHEN 'camping'      THEN 12000
                WHEN 'cameras'      THEN 40000
                WHEN 'books-study'  THEN  5000
                WHEN 'garden'       THEN  4000
                WHEN 'electronics'  THEN 25000
                WHEN 'party-events' THEN 15000
                WHEN 'home-diy'     THEN 10000
                WHEN 'sports'       THEN 30000
                WHEN 'music'        THEN 18000
                WHEN 'furniture'    THEN 12000
                WHEN 'kids-baby'    THEN 10000
                ELSE 5000
            END * 0.65
        ), 0)
           FROM transactions t
           JOIN items i ON i.item_id = t.item_id
           JOIN categories c ON c.category_id = i.category_id
          WHERE t.status = 'completed') AS money_saved,

        (SELECT COALESCE(SUM(
            CASE c.slug
                WHEN 'power-tools'  THEN 12
                WHEN 'camping'      THEN 18
                WHEN 'cameras'      THEN  5
                WHEN 'books-study'  THEN  2
                WHEN 'garden'       THEN  4
                WHEN 'electronics'  THEN  8
                WHEN 'party-events' THEN 10
                WHEN 'home-diy'     THEN 10
                WHEN 'sports'       THEN 12
                WHEN 'music'        THEN  8
                WHEN 'furniture'    THEN 15
                WHEN 'kids-baby'    THEN  6
                ELSE 5
            END
        ), 0)
           FROM transactions t
           JOIN items i ON i.item_id = t.item_id
           JOIN categories c ON c.category_id = i.category_id
          WHERE t.status = 'completed') AS waste_avoided_kg"
);
$stats = $statsStmt->fetch() ?: [
    'total_items'       => 0,
    'total_borrows'     => 0,
    'money_saved'       => 0,
    'waste_avoided_kg'  => 0,
];

// ---------------------------------------------------------------------------
// Completed borrows by category
// ---------------------------------------------------------------------------
$byCategory = $pdo->query(
    "SELECT
        c.name,
        c.icon,
        COUNT(t.transaction_id) AS borrows
       FROM categories c
       LEFT JOIN items i
         ON i.category_id = c.category_id
       LEFT JOIN transactions t
         ON t.item_id = i.item_id
        AND t.status = 'completed'
      GROUP BY c.category_id, c.name, c.icon
      ORDER BY borrows DESC, c.sort_order ASC
      LIMIT 8"
)->fetchAll();

// ---------------------------------------------------------------------------
// Completed borrows by month
// ---------------------------------------------------------------------------
$monthly = $pdo->query(
    "SELECT
        DATE_FORMAT(created_at, '%Y-%m') AS ym,
        COUNT(*) AS n
       FROM transactions
      WHERE status = 'completed'
      GROUP BY ym
      ORDER BY ym DESC
      LIMIT 6"
)->fetchAll();
$monthly = array_reverse($monthly);
$maxMonthly = max(1, ...array_map(
    fn($m) => (int) $m['n'],
    $monthly ?: [['n' => 1]]
));

$pageTitle = 'Sustainability impact — BIB';
$activeNav = 'impact';
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
  <div class="container">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/index.php">Home</a> <span>/</span> <span>Impact</span></div>
    <h1 style="font-size:2rem;margin-bottom:6px">Sustainability impact</h1>
    <p style="margin:0">Every borrow is a purchase that did not happen. Here is what the BIB community has avoided so far.</p>
  </div>
</div>

<div class="container" style="padding:28px 24px 80px">
  <div class="kpi-grid">
    <div class="kpi">
      <span class="kpi-label">Items shared</span>
      <b class="kpi-value"><?= number_format((int) $stats['total_items']) ?></b>
      <span class="kpi-foot">listed in the library</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Borrows completed</span>
      <b class="kpi-value"><?= number_format((int) $stats['total_borrows']) ?></b>
      <span class="kpi-foot">successful handovers</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Money saved</span>
      <b class="kpi-value"><?= money($stats['money_saved']) ?></b>
      <span class="kpi-foot">vs. buying new</span>
    </div>
    <div class="kpi">
      <span class="kpi-label">Waste avoided</span>
      <b class="kpi-value"><?= number_format((float) $stats['waste_avoided_kg'], 0) ?> kg</b>
      <span class="kpi-foot">estimated embodied material</span>
    </div>
  </div>

  <div class="two-col">
    <div class="panel">
      <div class="panel-head"><h3>Borrows by category</h3></div>
      <div class="panel-body">
        <?php foreach ($byCategory as $c): ?>
          <?php
            $totalBorrows = (int) $stats['total_borrows'];
            $categoryBorrows = (int) $c['borrows'];
            $pct = $totalBorrows > 0 ? round($categoryBorrows / $totalBorrows * 100) : 0;
          ?>
          <div class="bar-row">
            <div class="bl"><span><?= e($c['icon']) ?> <?= e($c['name']) ?></span><b><?= $categoryBorrows ?></b></div>
            <div class="bar"><i style="width:<?= $categoryBorrows > 0 ? max(2, $pct) : 0 ?>%"></i></div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel">
      <div class="panel-head"><h3>Completed borrows by month</h3></div>
      <div class="panel-body">
        <div class="chart" style="height:220px">
          <?php if ($monthly): ?>
            <?php foreach ($monthly as $m): ?>
              <div class="col">
                <div class="bars">
                  <i style="height:<?= max(6, (int) round((int) $m['n'] / $maxMonthly * 150)) ?>px"></i>
                </div>
                <span><?= date('M', strtotime($m['ym'] . '-01')) ?></span>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="small muted" style="width:100%;text-align:center;padding-top:70px">No completed borrows yet.</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="panel">
    <div class="panel-head"><h3>How we calculate this</h3></div>
    <div class="panel-body">
      <div class="grid grid-3" style="gap:20px">
        <div>
          <b class="small">Money saved</b>
          <p class="small muted" style="margin:4px 0 0">Each completed borrow uses a category-based typical retail value and counts 65% of that estimate as the avoided purchase cost.</p>
        </div>
        <div>
          <b class="small">Waste avoided</b>
          <p class="small muted" style="margin:4px 0 0">Each completed borrow contributes a category-based embodied-material estimate in kilograms.</p>
        </div>
        <div>
          <b class="small">Why it matters</b>
          <p class="small muted" style="margin:4px 0 0">Sharing products that are used only occasionally can reduce unnecessary purchases and the material associated with making new items.</p>
        </div>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
