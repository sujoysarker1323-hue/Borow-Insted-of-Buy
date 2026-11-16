<?php
/**
 * submit_review.php — multi-criteria review that feeds the reputation score
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

$txId = (int) (get('tx') ?: post('tx'));
$stmt = db()->prepare(
    'SELECT t.*, i.title, lp.full_name AS lender_name, bp.full_name AS borrower_name
       FROM transactions t
       JOIN items i ON i.item_id = t.item_id
       JOIN profiles lp ON lp.user_id = t.lender_id
       JOIN profiles bp ON bp.user_id = t.borrower_id
      WHERE t.transaction_id = ?'
);
$stmt->execute([$txId]);
$tx = $stmt->fetch();

if (!$tx) {
    http_response_code(404);
    exit('Transaction not found.');
}
$isLender = (int) $tx['lender_id'] === $myId;
$isBorrower = (int) $tx['borrower_id'] === $myId;
if (!$isLender && !$isBorrower) {
    http_response_code(403);
    exit('You are not part of this transaction.');
}
if ($tx['status'] !== 'completed') {
    flash('error', 'You can review once the transaction is completed.');
    redirect(APP_URL . '/transaction.php?id=' . $txId);
}

$revieweeId = $isLender ? (int) $tx['borrower_id'] : (int) $tx['lender_id'];
$revieweeName = $isLender ? $tx['borrower_name'] : $tx['lender_name'];

$stmt = db()->prepare('SELECT review_id FROM reviews WHERE transaction_id = ? AND reviewer_id = ?');
$stmt->execute([$txId, $myId]);
if ($stmt->fetchColumn()) {
    flash('error', 'You have already reviewed this transaction.');
    redirect(APP_URL . '/transaction.php?id=' . $txId);
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $criteria = [];
    foreach (['reliability', 'communication', 'item_condition', 'timely_return', 'overall'] as $k) {
        $v = (int) post($k);
        if ($v < 1 || $v > 5) {
            $errors[] = 'Please rate every criterion from 1 to 5.';
            break;
        }
        $criteria[$k] = $v;
    }
    $comment = post('comment');
    if (str_len($comment) > 1000) {
        $errors[] = 'Keep the comment under 1000 characters.';
    }

    if (!$errors) {
        db()->prepare(
            'INSERT INTO reviews (transaction_id, item_id, reviewer_id, reviewee_id, reliability,
                                  communication, item_condition, timely_return, overall, comment)
             VALUES (?,?,?,?,?,?,?,?,?,?)'
        )->execute([
            $txId, (int) $tx['item_id'], $myId, $revieweeId,
            $criteria['reliability'], $criteria['communication'], $criteria['item_condition'],
            $criteria['timely_return'], $criteria['overall'], $comment,
        ]);

        refresh_reputation($revieweeId);
        evaluate_badges($revieweeId);

        notify($revieweeId, 'review', 'New review from ' . $me['full_name'],
            'You received a ' . $criteria['overall'] . '/5 review.', 'profile.php?u=' . $revieweeId);

        flash('success', 'Thanks — your review is live.');
        redirect(APP_URL . '/transaction.php?id=' . $txId);
    }
}

$pageTitle = 'Leave a review — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="container narrow" style="padding:36px 24px 80px">
  <div class="breadcrumb"><a href="<?= APP_URL ?>/transaction.php?id=<?= $txId ?>">Transaction</a> <span>/</span> <span>Review</span></div>
  <h1 style="font-size:1.9rem">Review <?= e($revieweeName) ?></h1>
  <p class="muted">Reviews are what make BIB safe. Be honest and specific — future borrowers rely on this.</p>

  <?php if ($errors): ?>
    <div class="panel" style="border-color:var(--danger)">
      <div class="panel-body" style="padding:14px 16px">
        <?php foreach ($errors as $err): ?><div class="small" style="color:var(--danger)">• <?= e($err) ?></div><?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <form method="post" class="panel">
    <?= csrf_field() ?>
    <input type="hidden" name="tx" value="<?= $txId ?>">
    <div class="panel-body">
      <div class="panel" style="background:var(--surface-2);border-color:transparent;margin-bottom:20px">
        <div class="panel-body" style="padding:14px 16px">
          <b class="small"><?= e($tx['title']) ?></b>
          <div class="small muted"><?= nice_date($tx['start_date']) ?> → <?= nice_date($tx['due_date']) ?> · <?= e($tx['reference']) ?></div>
        </div>
      </div>

      <?php
      $labels = [
          'reliability'    => 'Reliability — did they do what they said?',
          'communication'  => 'Communication — how easy were they to reach?',
          'item_condition' => 'Item condition — as described?',
          'timely_return'  => 'Timely return / handover',
          'overall'        => 'Overall experience',
      ];
      foreach ($labels as $key => $label): ?>
        <div class="field">
          <label><?= e($label) ?></label>
          <div class="star-input" data-star-input>
            <?php for ($i = 1; $i <= 5; $i++): ?>
              <button type="button" class="star" data-value="<?= $i ?>" aria-label="<?= $i ?> stars">
                <svg viewBox="0 0 24 24"><path d="M12 2l3 6.5 7 .9-5 4.9 1.2 7L12 18l-6.2 3.3L7 14.3 2 9.4l7-.9z"/></svg>
              </button>
            <?php endfor; ?>
            <input type="hidden" name="<?= $key ?>" value="0" required>
          </div>
        </div>
      <?php endforeach; ?>

      <div class="field">
        <label for="comment">Your review</label>
        <textarea class="textarea" id="comment" name="comment" maxlength="1000" placeholder="What went well? Anything the next person should know?"></textarea>
      </div>

      <div class="flex gap-8">
        <button class="btn btn-primary" type="submit">Publish review</button>
        <a class="btn btn-ghost" href="<?= APP_URL ?>/transaction.php?id=<?= $txId ?>">Cancel</a>
      </div>
    </div>
  </form>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
