<?php
/**
 * messages.php — one-to-one chat tied to borrow conversations, with read state
 */
require_once __DIR__ . '/includes/functions.php';
boot_session();
$me = require_login();
$myId = (int) $me['user_id'];

// Start (or resume) a conversation with someone
$to = (int) get('to');
if ($to > 0 && $to !== $myId) {
    $itemId = (int) get('item') ?: null;
    $convId = find_or_create_conversation($myId, $to, $itemId, null, null, 'Direct message');
    redirect(APP_URL . '/messages.php?c=' . $convId);
}

$convId = (int) get('c');

// Send a message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $body = post('body');
    $cid  = (int) post('conversation_id');

    $stmt = db()->prepare('SELECT * FROM conversations WHERE conversation_id = ? AND (user_a_id = ? OR user_b_id = ?)');
    $stmt->execute([$cid, $myId, $myId]);
    $conv = $stmt->fetch();

    if ($conv && $body !== '') {
        db()->prepare('INSERT INTO messages (conversation_id, sender_id, body) VALUES (?,?,?)')
            ->execute([$cid, $myId, $body]);
        db()->prepare('UPDATE conversations SET last_message_at = NOW() WHERE conversation_id = ?')->execute([$cid]);

        $other = (int) $conv['user_a_id'] === $myId ? (int) $conv['user_b_id'] : (int) $conv['user_a_id'];
        notify($other, 'message', 'New message from ' . $me['full_name'],
            str_shorten($body, 90), 'messages.php?c=' . $cid);
    }
    redirect(APP_URL . '/messages.php?c=' . $cid);
}

// Conversation list
$stmt = db()->prepare(
    'SELECT c.*,
            CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END AS other_id,
            (SELECT full_name FROM profiles WHERE user_id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END) AS other_name,
            (SELECT avatar_path FROM profiles WHERE user_id = CASE WHEN c.user_a_id = ? THEN c.user_b_id ELSE c.user_a_id END) AS other_avatar,
            (SELECT body FROM messages WHERE conversation_id = c.conversation_id ORDER BY message_id DESC LIMIT 1) AS last_body,
            (SELECT COUNT(*) FROM messages WHERE conversation_id = c.conversation_id AND is_read = 0 AND sender_id <> ?) AS unread,
            i.title AS item_title
       FROM conversations c
       LEFT JOIN items i ON i.item_id = c.item_id
      WHERE c.user_a_id = ? OR c.user_b_id = ?
      ORDER BY c.last_message_at DESC'
);
$stmt->execute([$myId, $myId, $myId, $myId, $myId, $myId]);
$conversations = $stmt->fetchAll();

$active = null;
$messages = [];
if ($convId > 0) {
    foreach ($conversations as $c) {
        if ((int) $c['conversation_id'] === $convId) {
            $active = $c;
            break;
        }
    }
    if ($active) {
        db()->prepare('UPDATE messages SET is_read = 1, read_at = NOW() WHERE conversation_id = ? AND sender_id <> ?')
            ->execute([$convId, $myId]);
        $stmt = db()->prepare(
            'SELECT m.*, p.full_name, p.avatar_path FROM messages m
               JOIN profiles p ON p.user_id = m.sender_id
              WHERE m.conversation_id = ? ORDER BY m.message_id ASC'
        );
        $stmt->execute([$convId]);
        $messages = $stmt->fetchAll();
    }
}

$pageTitle = 'Messages — BIB';
$activeNav = 'dashboard';
require __DIR__ . '/includes/header.php';
?>
<div class="app-shell">
  <?php require __DIR__ . '/includes/sidebar.php'; ?>
  <main class="app-main">
    <div class="app-head">
      <div><h1>Messages</h1><p><?= count($conversations) ?> conversation<?= count($conversations) === 1 ? '' : 's' ?></p></div>
    </div>

    <div class="chat-layout">
      <div class="chat-list">
        <?php if (!$conversations): ?>
          <div class="empty" style="padding:40px 20px">
            <p>No conversations yet.</p>
            <a class="btn btn-primary btn-sm" href="<?= APP_URL ?>/search.php">Find something to borrow</a>
          </div>
        <?php else: ?>
          <?php foreach ($conversations as $c): ?>
            <a class="chat-item<?= $active && (int) $active['conversation_id'] === (int) $c['conversation_id'] ? ' active' : '' ?>"
               href="<?= APP_URL ?>/messages.php?c=<?= (int) $c['conversation_id'] ?>">
              <?php if ($c['other_avatar']): ?>
                <img class="avatar" src="<?= UPLOAD_URL . '/' . e($c['other_avatar']) ?>" alt="">
              <?php else: ?>
                <span class="avatar" style="background:var(--green-700);color:#fff"><?= e(strtoupper(substr((string) $c['other_name'], 0, 2))) ?></span>
              <?php endif; ?>
              <div style="flex:1;min-width:0">
                <div class="flex between center">
                  <b class="small"><?= e($c['other_name']) ?></b>
                  <span class="small muted"><?= time_ago($c['last_message_at']) ?></span>
                </div>
                <div class="small muted" style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                  <?= e(str_shorten((string) $c['last_body'], 46)) ?>
                </div>
                <?php if ($c['item_title']): ?><div class="small" style="color:var(--green-700);font-size:.7rem"><?= e($c['item_title']) ?></div><?php endif; ?>
              </div>
              <?php if ((int) $c['unread'] > 0): ?><span class="badge badge-green" style="font-size:.6rem"><?= (int) $c['unread'] ?></span><?php endif; ?>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="chat-panel">
        <?php if (!$active): ?>
          <div class="empty" style="height:100%;display:grid;place-content:center">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
            <p>Select a conversation to start chatting.</p>
          </div>
        <?php else: ?>
          <div class="chat-head">
            <b><?= e($active['other_name']) ?></b>
            <?php if ($active['item_title']): ?><span class="small muted">· <?= e($active['item_title']) ?></span><?php endif; ?>
            <a class="btn btn-ghost btn-sm" style="margin-left:auto" href="<?= APP_URL ?>/profile.php?u=<?= (int) $active['other_id'] ?>">Profile</a>
          </div>
          <div class="chat-body" id="chatBody">
            <?php if (!$messages): ?>
              <p class="small muted" style="text-align:center;margin:auto">No messages yet — say hello.</p>
            <?php endif; ?>
            <?php foreach ($messages as $m): ?>
              <div class="bubble<?= (int) $m['sender_id'] === $myId ? ' mine' : '' ?>">
                <div class="bubble-body"><?= nl2br(e($m['body'])) ?></div>
                <div class="bubble-time"><?= date('j M, H:i', strtotime($m['created_at'])) ?><?= (int) $m['sender_id'] === $myId ? ((int) $m['is_read'] ? ' · Read' : ' · Sent') : '' ?></div>
              </div>
            <?php endforeach; ?>
          </div>
          <form class="chat-form" method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="conversation_id" value="<?= (int) $active['conversation_id'] ?>">
            <input class="input" name="body" placeholder="Write a message…" autocomplete="off" required>
            <button class="btn btn-primary" type="submit">Send</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </main>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>
