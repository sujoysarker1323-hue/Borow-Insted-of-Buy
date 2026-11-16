<?php
/**
 * receipt.php — printable / downloadable transaction receipt
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$txId = (int) get('id');
$stmt = db()->prepare(
    'SELECT t.*, i.title, i.brand, i.model, i.item_condition,
            lp.full_name AS lender_name, lp.username AS lender_username, lp.area AS lender_area,
            bp.full_name AS borrower_name, bp.username AS borrower_username, bp.area AS borrower_area,
            d.status AS deposit_status
       FROM transactions t
       JOIN items i ON i.item_id = t.item_id
       JOIN profiles lp ON lp.user_id = t.lender_id
       JOIN profiles bp ON bp.user_id = t.borrower_id
       LEFT JOIN deposits d ON d.transaction_id = t.transaction_id
      WHERE t.transaction_id = ?'
);
$stmt->execute([$txId]);
$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    exit('Receipt not found.');
}
if ((int) $tx['lender_id'] !== (int) $me['user_id']
    && (int) $tx['borrower_id'] !== (int) $me['user_id']
    && !is_admin()) {
    http_response_code(403);
    exit('You cannot view this receipt.');
}

$total = (float) $tx['fee_amount'] + (float) $tx['penalty_amount'];

$pageTitle = 'Receipt ' . $tx['reference'] . ' — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:32px 24px 80px">
  <div class="flex between center wrap gap-12 no-print" style="margin-bottom:20px">
    <div class="breadcrumb" style="margin:0"><a href="<?= APP_URL ?>/transactions.php">Transactions</a> <span>/</span> <span>Receipt</span></div>
    <div class="flex gap-8">
      <button class="btn btn-outline btn-sm" onclick="window.print()">Print</button>
      <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/receipt.php?id=<?= $txId ?>&download=1">Download</a>
    </div>
  </div>

  <div class="receipt" id="receipt">
    <div class="receipt-head">
      <div>
        <div class="brand" style="margin-bottom:6px">
          <span class="brand-mark">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round"><path d="M4 7.5A2.5 2.5 0 0 1 6.5 5H11v14H6.5A2.5 2.5 0 0 1 4 16.5z"/><path d="M20 7.5A2.5 2.5 0 0 0 17.5 5H13v14h4.5A2.5 2.5 0 0 0 20 16.5z"/></svg>
          </span>
          <span>BIB<small>Borrow Instead of Buy</small></span>
        </div>
        <div class="small muted"><?= e(APP_TAGLINE) ?></div>
      </div>
      <div style="text-align:right">
        <div class="small muted">Receipt</div>
        <div style="font-family:var(--font-display);font-size:1.3rem"><?= e($tx['reference']) ?></div>
        <div class="small muted">Issued <?= nice_date($tx['created_at']) ?></div>
      </div>
    </div>

    <div class="receipt-grid">
      <div>
        <h5>Lender</h5>
        <b><?= e($tx['lender_name']) ?></b>
        <div class="small muted">@<?= e($tx['lender_username']) ?><br><?= e($tx['lender_area']) ?></div>
      </div>
      <div>
        <h5>Borrower</h5>
        <b><?= e($tx['borrower_name']) ?></b>
        <div class="small muted">@<?= e($tx['borrower_username']) ?><br><?= e($tx['borrower_area']) ?></div>
      </div>
      <div>
        <h5>Borrow period</h5>
        <b><?= nice_date($tx['start_date']) ?> → <?= nice_date($tx['due_date']) ?></b>
        <div class="small muted"><?= (int) $tx['days'] ?> days</div>
      </div>
      <div>
        <h5>Status</h5>
        <b><?= e(transaction_statuses()[$tx['status']] ?? $tx['status']) ?></b>
        <div class="small muted">Deposit: <?= e($tx['deposit_status'] ?: 'none') ?></div>
      </div>
    </div>

    <table class="receipt-table">
      <thead><tr><th>Description</th><th class="r">Amount</th></tr></thead>
      <tbody>
        <tr>
          <td>
            <b><?= e($tx['title']) ?></b>
            <div class="small muted"><?= e(trim(($tx['brand'] ?? '') . ' ' . ($tx['model'] ?? ''))) ?: 'Item' ?> · <?= ucwords(str_replace('_', ' ', $tx['item_condition'])) ?></div>
          </td>
          <td class="r"><?= money($tx['fee_amount']) ?></td>
        </tr>
        <tr>
          <td>Borrowing fee (<?= (int) $tx['days'] ?> days × <?= money($tx['daily_fee']) ?>)</td>
          <td class="r"><?= money($tx['fee_amount']) ?></td>
        </tr>
        <?php if ((float) $tx['penalty_amount'] > 0): ?>
          <tr>
            <td>Late return penalty (<?= (int) $tx['overdue_days'] ?> days)</td>
            <td class="r"><?= money($tx['penalty_amount']) ?></td>
          </tr>
        <?php endif; ?>
        <tr>
          <td>Refundable deposit <span class="small muted">(<?= e($tx['deposit_status'] ?: 'not held') ?>)</span></td>
          <td class="r"><?= money($tx['deposit_amount']) ?></td>
        </tr>
      </tbody>
      <tfoot>
        <tr><td>Total charged</td><td class="r"><?= money($total) ?></td></tr>
      </tfoot>
    </table>

    <div class="receipt-foot">
      <p class="small muted">This receipt was generated by BIB on <?= date('j M Y, H:i') ?>. Deposits are released automatically once both parties confirm the return. Keep this document for your records.</p>
      <div class="small muted"><?= e(APP_URL) ?> · <?= e($tx['reference']) ?></div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
