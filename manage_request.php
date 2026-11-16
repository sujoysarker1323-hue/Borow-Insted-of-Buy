<?php
/**
 * manage_request.php — owner accepts, rejects or asks a question
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();

$requestId = (int) (get('id') ?: post('id'));
$stmt = db()->prepare(
    'SELECT r.*, i.title, i.deposit_amount, i.daily_fee, i.max_borrow_days,
            p.full_name AS borrower_name, p.username AS borrower_username, p.avatar_path,
            p.reputation_score, p.avg_rating, p.total_borrows
       FROM borrow_requests r
       JOIN items i ON i.item_id = r.item_id
       JOIN profiles p ON p.user_id = r.borrower_id
      WHERE r.request_id = ?'
);
$stmt->execute([$requestId]);
$req = $stmt->fetch();

if (!$req) {
    http_response_code(404);
    exit('Request not found.');
}
if ((int) $req['owner_id'] !== (int) $me['user_id'] && !is_admin()) {
    http_response_code(403);
    exit('Only the item owner can manage this request.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = post('action');
    $note   = post('response_note');

    $pdo = db();
    $stmt = $pdo->prepare('SELECT transaction_id FROM transactions WHERE request_id = ?');
    $stmt->execute([$requestId]);
    $txId = (int) $stmt->fetchColumn();

    if ($action === 'accept') {
        $pdo->prepare("UPDATE borrow_requests SET status = 'accepted', response_note = ?, responded_at = NOW() WHERE request_id = ?")
            ->execute([$note, $requestId]);
        $pdo->prepare("UPDATE transactions SET status = 'accepted' WHERE transaction_id = ?")
            ->execute([$txId]);
        notify((int) $req['borrower_id'], 'approval', 'Request accepted',
            'Your request for “' . $req['title'] . '” was accepted. Arrange pickup with the owner.', 'transaction.php?id=' . $txId);
        flash('success', 'Request accepted. The borrower has been notified.');
        redirect(APP_URL . '/transaction.php?id=' . $txId);
    }

    if ($action === 'reject') {
        $pdo->prepare("UPDATE borrow_requests SET status = 'rejected', response_note = ?, responded_at = NOW() WHERE request_id = ?")
            ->execute([$note, $requestId]);
        $pdo->prepare("UPDATE transactions SET status = 'cancelled' WHERE transaction_id = ?")->execute([$txId]);
        notify((int) $req['borrower_id'], 'request', 'Request declined',
            'Your request for “' . $req['title'] . '” was declined.' . ($note ? ' Note: ' . $note : ''), 'item_detail.php?id=' . (int) $req['item_id']);
        flash('success', 'Request declined.');
        redirect(APP_URL . '/borrow_requests.php');
    }

    if ($action === 'question') {
        if ($note === '') {
            flash('error', 'Write your question first.');
            redirect(APP_URL . '/manage_request.php?id=' . $requestId);
        }
        $pdo->prepare("UPDATE borrow_requests SET status = 'question', response_note = ? WHERE request_id = ?")
            ->execute([$note, $requestId]);
        $convId = find_or_create_conversation(
            (int) $req['borrower_id'], (int) $me['user_id'], (int) $req['item_id'], $requestId, $txId,
            'Borrow request — ' . $req['title']
        );
        $pdo->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)')
            ->execute([$convId, $me['user_id'], $note]);
        notify((int) $req['borrower_id'], 'message', 'Question about your request', $note, 'messages.php?c=' . $convId);
        flash('success', 'Question sent.');
        redirect(APP_URL . '/manage_request.php?id=' . $requestId);
    }
}

$pageTitle = 'Manage request — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="breadcrumb"><a href="<?= APP_URL ?>/borrow_requests.php">Borrow requests</a> <span>/</span> <span>Request #<?= $requestId ?></span></div>
    <div class="app-head">
      <div>
        <h1>Borrow request</h1>
        <p>For “<?= e($req['title']) ?>” · <?= nice_date($req['start_date']) ?> → <?= nice_date($req['end_date']) ?> (<?= (int) $req['days'] ?> days)</p>
      </div>
      <span class="badge <?= $req['status'] === 'pending' ? 'badge-warn' : ($req['status'] === 'accepted' ? 'badge-ok' : 'badge') ?>"><?= ucfirst($req['status']) ?></span>
    </div>

    <div class="two-col">
      <div>
        <div class="panel">
          <div class="panel-head"><h3>Request details</h3></div>
          <div class="panel-body">
            <div class="flex center gap-12" style="margin-bottom:18px">
              <?php if ($req['avatar_path']): ?>
                <img class="avatar avatar-lg" src="<?= UPLOAD_URL . '/' . e($req['avatar_path']) ?>" alt="">
              <?php else: ?>
                <span class="avatar avatar-lg" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr($req['borrower_name'], 0, 2))) ?></span>
              <?php endif; ?>
              <div>
                <b><?= e($req['borrower_name']) ?></b>
                <div class="small muted">@<?= e($req['borrower_username']) ?> · <?= (int) $req['total_borrows'] ?> borrows · rep <?= (int) round((float) $req['reputation_score']) ?>/100</div>
              </div>
              <a class="btn btn-outline btn-sm" style="margin-left:auto" href="<?= APP_URL ?>/profile.php?u=<?= (int) $req['borrower_id'] ?>">View profile</a>
            </div>

            <div class="kv"><span>Dates</span><b><?= nice_date($req['start_date']) ?> → <?= nice_date($req['end_date']) ?></b></div>
            <div class="kv"><span>Duration</span><b><?= (int) $req['days'] ?> days</b></div>
            <div class="kv"><span>Deposit</span><b><?= money($req['deposit_amount']) ?></b></div>
            <div class="kv"><span>Fee</span><b><?= money((float) $req['daily_fee'] * (int) $req['days']) ?></b></div>

            <h4 style="margin:20px 0 6px;font-size:.95rem">Reason</h4>
            <p class="small" style="white-space:pre-line;color:var(--ink-2)"><?= e($req['reason']) ?></p>

            <?php if ($req['message']): ?>
              <h4 style="margin:16px 0 6px;font-size:.95rem">Message</h4>
              <p class="small" style="white-space:pre-line;color:var(--ink-2)"><?= e($req['message']) ?></p>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($req['status'] === 'pending' || $req['status'] === 'question'): ?>
          <div class="panel">
            <div class="panel-head"><h3>Respond</h3></div>
            <div class="panel-body">
              <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $requestId ?>">
                <div class="field">
                  <label for="response_note">Note to the borrower (optional, or your question)</label>
                  <textarea class="textarea" id="response_note" name="response_note" style="min-height:80px" placeholder="Pickup is from 6pm at the gate…"><?= e($req['response_note']) ?></textarea>
                </div>
                <div class="flex gap-8 wrap">
                  <button class="btn btn-primary" name="action" value="accept" type="submit">Accept request</button>
                  <button class="btn btn-outline" name="action" value="question" type="submit">Ask a question</button>
                  <button class="btn btn-ghost" name="action" value="reject" type="submit" style="color:var(--danger)">Decline</button>
                </div>
              </form>
            </div>
          </div>
        <?php else: ?>
          <div class="panel">
            <div class="panel-head"><h3>Your response</h3></div>
            <div class="panel-body">
              <p class="small" style="margin:0"><?= $req['response_note'] ? nl2br(e($req['response_note'])) : 'No note was left.' ?></p>
              <p class="small muted" style="margin:8px 0 0">Responded <?= time_ago($req['responded_at']) ?></p>
            </div>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <div class="panel">
          <div class="panel-head"><h3>Borrower reputation</h3></div>
          <div class="panel-body">
            <div style="text-align:center;margin-bottom:14px">
              <div style="font-family:var(--font-display);font-size:2.6rem;line-height:1;color:var(--green-700)"><?= (int) round((float) $req['reputation_score']) ?></div>
              <div class="small muted">out of 100</div>
            </div>
            <div class="bar-row">
              <div class="bl"><span>Average rating</span><b><?= number_format((float) $req['avg_rating'], 1) ?></b></div>
              <div class="bar"><i style="width:<?= (int) round((float) $req['avg_rating'] / 5 * 100) ?>%"></i></div>
            </div>
            <p class="small muted" style="margin:12px 0 0">Borrowers with a score above 80 have a 97% on-time return rate on BIB.</p>
          </div>
        </div>
        <div class="panel">
          <div class="panel-head"><h3>Safety</h3></div>
          <div class="panel-body" style="display:grid;gap:8px">
            <a class="btn btn-outline btn-block btn-sm" href="<?= APP_URL ?>/messages.php?to=<?= (int) $req['borrower_id'] ?>&item=<?= (int) $req['item_id'] ?>">Message borrower</a>
            <a class="btn btn-ghost btn-block btn-sm" href="<?= APP_URL ?>/report.php?user=<?= (int) $req['borrower_id'] ?>">Report borrower</a>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
