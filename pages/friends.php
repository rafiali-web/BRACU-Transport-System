<?php
require_once '../includes/config.php';
ensureFriendsSchema($pdo);

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = (int) $_SESSION['id'];
$MAX_FRIENDS = 20;
$error = $_SESSION['friends_error'] ?? '';
$success = $_SESSION['friends_success'] ?? '';
unset($_SESSION['friends_error'], $_SESSION['friends_success']);

function friendshipPair($a, $b) {
    $a = (int) $a;
    $b = (int) $b;
    return $a < $b ? [$a, $b] : [$b, $a];
}

function friendCount(PDO $pdo, $uid) {
    $q = $pdo->prepare("SELECT COUNT(*) FROM friendships WHERE user_one_id = ? OR user_two_id = ?");
    $q->execute([(int) $uid, (int) $uid]);
    return (int) $q->fetchColumn();
}

function areFriends(PDO $pdo, $a, $b) {
    [$u1, $u2] = friendshipPair($a, $b);
    $q = $pdo->prepare("SELECT id FROM friendships WHERE user_one_id = ? AND user_two_id = ?");
    $q->execute([$u1, $u2]);
    return (bool) $q->fetch();
}

function createNotification(PDO $pdo, $userId, $actorUserId, $type, $payload = null) {
    $pdo->prepare("INSERT INTO friend_notifications (user_id, actor_user_id, type, payload) VALUES (?, ?, ?, ?)")
        ->execute([(int) $userId, $actorUserId !== null ? (int) $actorUserId : null, (string) $type, $payload]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $redirect_friend = isset($_POST['friend_id']) ? (int) $_POST['friend_id'] : (isset($_GET['friend']) ? (int) $_GET['friend'] : 0);
    try {
        if (isset($_POST['send_request'])) {
            $raw = trim((string) ($_POST['friend_public_id'] ?? ''));
            if ($raw === '') {
                throw new RuntimeException('Please enter a friend user ID.');
            }
            if ($raw[0] !== '#') {
                $raw = '#' . $raw;
            }

            $targetStmt = $pdo->prepare("SELECT id FROM users WHERE public_user_id = ?");
            $targetStmt->execute([$raw]);
            $target = $targetStmt->fetch();
            if (!$target) {
                throw new RuntimeException('User ID not found.');
            }
            $target_id = (int) $target['id'];
            if ($target_id === $user_id) {
                throw new RuntimeException('You cannot send a friend request to yourself.');
            }
            if (areFriends($pdo, $user_id, $target_id)) {
                throw new RuntimeException('You are already friends.');
            }
            if (friendCount($pdo, $user_id) >= $MAX_FRIENDS) {
                throw new RuntimeException('You already reached the 20 friends limit.');
            }
            if (friendCount($pdo, $target_id) >= $MAX_FRIENDS) {
                throw new RuntimeException('This user already reached the 20 friends limit.');
            }

            $pdo->prepare("DELETE FROM friend_requests WHERE sender_id = ? AND receiver_id = ? AND status = 'pending'")
                ->execute([$target_id, $user_id]);
            $pdo->prepare("INSERT OR IGNORE INTO friend_requests (sender_id, receiver_id, status) VALUES (?, ?, 'pending')")
                ->execute([$user_id, $target_id]);
            createNotification($pdo, $target_id, $user_id, 'friend_request');
            $_SESSION['friends_success'] = 'Friend request sent.';
        }

        if (isset($_POST['accept_request'])) {
            $request_id = (int) $_POST['request_id'];
            $q = $pdo->prepare("SELECT * FROM friend_requests WHERE id = ? AND receiver_id = ? AND status = 'pending'");
            $q->execute([$request_id, $user_id]);
            $req = $q->fetch();
            if (!$req) {
                throw new RuntimeException('Friend request not found.');
            }
            if (friendCount($pdo, $user_id) >= $MAX_FRIENDS || friendCount($pdo, (int) $req['sender_id']) >= $MAX_FRIENDS) {
                throw new RuntimeException('Cannot accept because one side reached 20 friends.');
            }
            [$u1, $u2] = friendshipPair($req['sender_id'], $req['receiver_id']);
            $pdo->beginTransaction();
            $pdo->prepare("INSERT OR IGNORE INTO friendships (user_one_id, user_two_id) VALUES (?, ?)")->execute([$u1, $u2]);
            $pdo->prepare("UPDATE friend_requests SET status = 'accepted', responded_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$request_id]);
            createNotification($pdo, (int) $req['sender_id'], $user_id, 'friend_accept');
            $pdo->commit();
            $_SESSION['friends_success'] = 'Friend request accepted.';
        }

        if (isset($_POST['reject_request'])) {
            $request_id = (int) $_POST['request_id'];
            $pdo->prepare("UPDATE friend_requests SET status = 'rejected', responded_at = CURRENT_TIMESTAMP WHERE id = ? AND receiver_id = ?")
                ->execute([$request_id, $user_id]);
            $_SESSION['friends_success'] = 'Friend request rejected.';
        }

        if (isset($_POST['remove_friend'])) {
            $friend_id = (int) $_POST['friend_id'];
            [$u1, $u2] = friendshipPair($user_id, $friend_id);
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM friendships WHERE user_one_id = ? AND user_two_id = ?")->execute([$u1, $u2]);
            $pdo->prepare("DELETE FROM friend_nicknames WHERE (owner_user_id = ? AND friend_user_id = ?) OR (owner_user_id = ? AND friend_user_id = ?)")
                ->execute([$user_id, $friend_id, $friend_id, $user_id]);
            $pdo->commit();
            $_SESSION['friends_success'] = 'Friend removed.';
            $redirect_friend = 0;
        }

        if (isset($_POST['mark_notifications_read'])) {
            $pdo->prepare("UPDATE friend_notifications SET is_read = 1 WHERE user_id = ?")->execute([$user_id]);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['friends_error'] = $e->getMessage();
    }

    $location = 'friends.php';
    if ($redirect_friend > 0) {
        $location .= '?friend=' . $redirect_friend;
    }
    header('Location: ' . $location);
    exit;
}

$active_friend_id = isset($_GET['friend']) ? (int) $_GET['friend'] : 0;

$friendListStmt = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.public_user_id,
           fn.nickname,
           (
                SELECT COUNT(*)
                FROM friend_messages fm
                WHERE ((fm.sender_id = ? AND fm.receiver_id = u.id) OR (fm.sender_id = u.id AND fm.receiver_id = ?))
           ) AS message_count,
           (
                SELECT COUNT(*)
                FROM friend_messages fm
                WHERE fm.sender_id = u.id AND fm.receiver_id = ? AND COALESCE(fm.is_seen, 0) = 0
           ) AS unread_count,
           (
                SELECT COALESCE(fm.message_text, CASE
                    WHEN fm.message_type = 'image' THEN '[Image]'
                    WHEN fm.message_type = 'video' THEN '[Video]'
                    WHEN fm.message_type = 'audio' THEN '[Audio]'
                    WHEN fm.message_type = 'invite' THEN '[Trip Invite]'
                    ELSE '[Message]'
                END)
                FROM friend_messages fm
                WHERE ((fm.sender_id = ? AND fm.receiver_id = u.id) OR (fm.sender_id = u.id AND fm.receiver_id = ?))
                ORDER BY fm.id DESC
                LIMIT 1
           ) AS last_message,
           (
                SELECT fm.created_at
                FROM friend_messages fm
                WHERE ((fm.sender_id = ? AND fm.receiver_id = u.id) OR (fm.sender_id = u.id AND fm.receiver_id = ?))
                ORDER BY fm.id DESC
                LIMIT 1
           ) AS last_message_at,
           (
                SELECT wl.rank_position
                FROM weekly_leaderboard wl
                WHERE wl.user_id = u.id AND wl.week_number = ? AND wl.year = ?
                LIMIT 1
           ) AS friend_rank
    FROM friendships f
    JOIN users u ON u.id = CASE WHEN f.user_one_id = ? THEN f.user_two_id ELSE f.user_one_id END
    LEFT JOIN friend_nicknames fn ON fn.owner_user_id = ? AND fn.friend_user_id = u.id
    WHERE f.user_one_id = ? OR f.user_two_id = ?
    ORDER BY u.first_name, u.last_name
");
$week = (int) date('W');
$year = (int) date('Y');
$friendListStmt->execute([$user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $user_id, $week, $year, $user_id, $user_id, $user_id, $user_id]);
$friends = $friendListStmt->fetchAll();
$friend_count = count($friends);

if ($active_friend_id === 0 && $friend_count > 0) {
    $active_friend_id = (int) $friends[0]['id'];
}

$incomingReqStmt = $pdo->prepare("
    SELECT fr.id, fr.created_at, u.id AS sender_id, u.first_name, u.last_name, u.public_user_id
    FROM friend_requests fr
    JOIN users u ON u.id = fr.sender_id
    WHERE fr.receiver_id = ? AND fr.status = 'pending'
    ORDER BY fr.created_at DESC
");
$incomingReqStmt->execute([$user_id]);
$incoming_requests = $incomingReqStmt->fetchAll();

$notificationsStmt = $pdo->prepare("
    SELECT n.*, u.first_name, u.last_name
    FROM friend_notifications n
    LEFT JOIN users u ON u.id = n.actor_user_id
    WHERE n.user_id = ?
    ORDER BY n.created_at DESC
    LIMIT 8
");
$notificationsStmt->execute([$user_id]);
$notifications = $notificationsStmt->fetchAll();

require_once '../includes/header.php';
?>
<style>
.friends-layout { display: grid; grid-template-columns: 340px 1fr; gap: 24px; }
.friends-sidebar, .friends-main {
    background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
    border-radius: 18px;
    padding: 18px;
    border: 1px solid #e8ecf8;
    box-shadow: 0 10px 28px rgba(30, 41, 59, .08);
}
.friend-item {
    display:block; width:100%; text-align:left; background:#fff; color:inherit;
    padding:12px; border-radius:12px; margin-bottom:10px; border:1px solid #e9edf7; cursor:pointer;
    transition: all .25s ease;
}
.friend-item.active, .friend-item:hover {
    background: linear-gradient(135deg, #eef3ff 0%, #f8f5ff 100%);
    border-color:#b7c8ff;
    transform: translateY(-2px);
    box-shadow: 0 8px 18px rgba(88, 101, 242, .15);
}
.friend-top { display:flex; justify-content:space-between; align-items:center; gap:8px; }
.friend-avatar {
    width:34px; height:34px; border-radius:50%;
    background: linear-gradient(135deg, #5865f2 0%, #7c3aed 100%);
    color:#fff; display:flex; align-items:center; justify-content:center; font-size:.8rem; font-weight:700;
    box-shadow: 0 6px 16px rgba(88, 101, 242, .32);
}
.friend-name { font-weight:700; }
.unread-dot { min-width:20px; height:20px; border-radius:999px; background:#0a7cff; color:#fff; display:inline-flex; align-items:center; justify-content:center; font-size:.75rem; padding:0 6px; box-shadow: 0 0 0 4px rgba(10,124,255,.14); }
.friend-code { color:#666; font-size:.9rem; }
.top-actions { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; gap:10px; flex-wrap:wrap; }
.notif {
    border:1px solid #e8ebf3;
    border-radius:12px;
    padding:10px;
    margin-bottom:10px;
    font-size:.9rem;
    background: #fff;
    transition: all .2s ease;
}
.notif:hover {
    border-color: #cfdbff;
    transform: translateY(-1px);
}
.friends-main { min-height: 560px; }
.chat-launch-hint { color:#666; padding: 12px; border:1px dashed #ddd; border-radius:10px; background:#fafafa; display:none; }

.floating-chat {
    position: relative; right: auto; bottom: auto; width: 100%; height: 620px; z-index: 1;
    display: none; flex-direction: column;
    background: radial-gradient(circle at top right, #eef2ff 0%, #ffffff 34%, #f6f8ff 100%);
    border: 1px solid #dfe6fb;
    border-radius: 20px;
    box-shadow: 0 18px 40px rgba(30, 41, 59, .14), 0 0 0 1px rgba(124, 58, 237, .05) inset;
    overflow: hidden;
}
.fc-head {
    padding: 14px 16px;
    border-bottom:1px solid #ecf0f6;
    display:flex;
    justify-content:space-between;
    align-items:center;
    background: linear-gradient(120deg, #4f46e5 0%, #6366f1 30%, #7c3aed 100%);
    border-radius: 20px 20px 0 0;
}
.fc-title {
    font-weight: 700;
    font-size: .96rem;
    color: #fff;
    letter-spacing: .2px;
    text-shadow: 0 2px 8px rgba(0,0,0,.22);
}
.fc-actions { display:flex; gap:6px; }
.fc-body {
    flex:1;
    overflow-y:auto;
    padding: 14px;
    background:
        radial-gradient(circle at 12% 12%, rgba(99,102,241,.08), transparent 38%),
        radial-gradient(circle at 88% 8%, rgba(236,72,153,.07), transparent 34%),
        linear-gradient(180deg, #f7f9ff 0%, #f2f6ff 100%);
}
.fc-body::-webkit-scrollbar { width: 9px; }
.fc-body::-webkit-scrollbar-thumb {
    background: linear-gradient(180deg, #b4c5ff 0%, #8aa4ff 100%);
    border-radius: 999px;
}
.fc-body::-webkit-scrollbar-track { background: rgba(255,255,255,.7); }
.fc-msg {
    max-width: 78%;
    margin-bottom: 12px;
    padding: 10px 12px;
    border-radius: 16px;
    font-size: .92rem;
    line-height: 1.38;
    animation: chatPop .22s ease;
}
.fc-msg.me {
    margin-left:auto;
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 45%, #7c3aed 100%);
    color:#fff;
    border-bottom-right-radius:8px;
    box-shadow: 0 10px 22px rgba(79, 70, 229, .32);
}
.fc-msg.them {
    margin-right:auto;
    background: linear-gradient(180deg, #ffffff 0%, #f7f9ff 100%);
    color:#1f2937;
    border:1px solid #dfe5f2;
    border-bottom-left-radius:8px;
    box-shadow: 0 5px 12px rgba(31,41,55,.08);
}
.fc-meta { font-size:.72rem; opacity:.8; margin-top:5px; }
.fc-status { font-size:.68rem; opacity:.9; margin-top:3px; text-align:right; }
.fc-form {
    border-top: 1px solid #e6ebf8;
    padding: 12px;
    background: linear-gradient(180deg, #ffffff 0%, #f8faff 100%);
    border-radius: 0 0 20px 20px;
}
.fc-row { display:flex; gap:6px; margin-bottom:6px; }
.fc-input {
    flex:1;
    border:1px solid #d9dde3;
    border-radius: 999px;
    padding: 11px 14px;
    background:#fff;
    box-shadow: 0 2px 8px rgba(15,23,42,.04) inset;
}
.fc-input:focus {
    outline: none;
    border-color: #6b8dff;
    box-shadow: 0 0 0 4px rgba(107, 141, 255, .18), 0 8px 20px rgba(79, 70, 229, .12);
}
.fc-file { width: 100%; }
.fc-mini {
    font-size: .82rem;
    padding: 6px 10px;
    border-radius: 999px;
    border:1px solid #d1d5db;
    background:#fff;
    cursor:pointer;
    transition: all .2s ease;
}
.fc-mini:hover {
    transform: translateY(-1px);
    border-color:#9db4ff;
    box-shadow: 0 6px 14px rgba(79,70,229,.16);
}
.fc-close { color:#fff; background:#ef4444; border-color:#ef4444; }
.fc-invite { background: #fff3cd; color:#8a5b00; border:1px solid #f5d18a; display:inline-block; padding:2px 8px; border-radius:999px; font-size:.72rem; margin-bottom:5px; }

.friends-sidebar h3, .friends-sidebar h4 {
    margin-bottom: 10px;
    color: #1e293b;
}

.friends-sidebar hr {
    border: none;
    border-top: 1px solid #edf1f7;
    margin: 14px 0;
}

#fcSendBtn {
    background: linear-gradient(135deg, #2563eb 0%, #4f46e5 50%, #7c3aed 100%);
    border: none;
    color: #fff;
    border-radius: 999px;
    padding: 10px 16px;
    font-weight: 600;
    box-shadow: 0 10px 22px rgba(79, 70, 229, .25);
}
#fcSendBtn:hover {
    transform: translateY(-1px);
    box-shadow: 0 12px 26px rgba(79, 70, 229, .32);
}

@keyframes chatPop {
    from { opacity: 0; transform: translateY(6px) scale(.98); }
    to { opacity: 1; transform: translateY(0) scale(1); }
}

.top-actions strong {
    color: #111827;
}

@media (max-width: 900px) {
    .friends-layout { grid-template-columns: 1fr; }
    .floating-chat { width: 100%; height: 70vh; }
}
</style>

<div class="container">
    <h1 class="page-title"><i class="fas fa-user-friends"></i> Friends</h1>

    <?php if ($success): ?><div class="notification success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="notification error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card">
        <div class="top-actions">
            <div>
                <strong>Your User ID:</strong> <?php echo htmlspecialchars($_SESSION['public_user_id'] ?? formatPublicUserId($user_id)); ?>
                <span style="margin-left:10px; color:#666;">Friends: <?php echo $friend_count; ?>/<?php echo $MAX_FRIENDS; ?></span>
            </div>
            <button class="btn btn-primary" onclick="document.getElementById('addFriendModal').style.display='flex'">
                <i class="fas fa-user-plus"></i> Add Friends
            </button>
        </div>
    </div>

    <div class="friends-layout">
        <div class="friends-sidebar">
            <h3 style="margin-top:0;">My Friends</h3>
            <?php if (empty($friends)): ?>
                <p style="color:#666;">No friends yet.</p>
            <?php else: ?>
                <?php foreach ($friends as $f): ?>
                    <?php $displayName = !empty($f['nickname']) ? $f['nickname'] : ($f['first_name'] . ' ' . $f['last_name']); ?>
                    <button type="button" class="friend-item <?php echo ((int)$f['id'] === $active_friend_id) ? 'active' : ''; ?>" data-friend-id="<?php echo (int) $f['id']; ?>">
                        <div class="friend-top">
                            <div style="display:flex; align-items:center; gap:8px;">
                                <span class="friend-avatar"><?php echo htmlspecialchars(strtoupper(substr($f['first_name'], 0, 1) . substr($f['last_name'], 0, 1))); ?></span>
                                <span class="friend-name"><?php echo htmlspecialchars($displayName); ?></span>
                            </div>
                            <?php if ((int)($f['unread_count'] ?? 0) > 0): ?>
                                <span class="unread-dot"><?php echo (int) $f['unread_count']; ?></span>
                            <?php endif; ?>
                        </div>
                        <span class="friend-code"><?php echo htmlspecialchars($f['public_user_id'] ?: formatPublicUserId($f['id'])); ?></span>
                        <?php if (!empty($f['last_message'])): ?>
                            <div class="friend-code" style="margin-top:4px;">
                                <?php echo htmlspecialchars(mb_strimwidth($f['last_message'], 0, 28, '...')); ?>
                            </div>
                            <div class="friend-code">
                                <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $f['last_message_at']))); ?>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($f['friend_rank'])): ?>
                            <div class="friend-code">Leaderboard rank: #<?php echo (int) $f['friend_rank']; ?></div>
                        <?php endif; ?>
                    </button>
                <?php endforeach; ?>
            <?php endif; ?>

            <hr>
            <h4>Incoming Requests</h4>
            <?php if (empty($incoming_requests)): ?>
                <p style="color:#666;">No pending requests.</p>
            <?php else: ?>
                <?php foreach ($incoming_requests as $r): ?>
                    <div class="notif">
                        <strong><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></strong>
                        <div class="friend-code"><?php echo htmlspecialchars($r['public_user_id'] ?: formatPublicUserId($r['sender_id'])); ?></div>
                        <div style="margin-top:6px; display:flex; gap:6px;">
                            <form method="post">
                                <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-success btn-sm" type="submit" name="accept_request">Accept</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="request_id" value="<?php echo (int) $r['id']; ?>">
                                <button class="btn btn-danger btn-sm" type="submit" name="reject_request">Reject</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <hr>
            <h4>Notifications</h4>
            <form method="post" style="margin-bottom:8px;">
                <button class="btn btn-sm btn-secondary" type="submit" name="mark_notifications_read">Mark all read</button>
            </form>
            <?php foreach ($notifications as $n): ?>
                <div class="notif" style="<?php echo ((int)$n['is_read'] === 0) ? 'border-color:#7aa2ff;' : ''; ?>">
                    <?php
                    $actor = trim((string) (($n['first_name'] ?? '') . ' ' . ($n['last_name'] ?? '')));
                    if ($n['type'] === 'friend_request') echo htmlspecialchars($actor . ' sent you a friend request.');
                    elseif ($n['type'] === 'friend_accept') echo htmlspecialchars($actor . ' accepted your friend request.');
                    elseif ($n['type'] === 'trip_invite') echo htmlspecialchars($actor . ' invited you for a trip.');
                    else echo 'Notification';
                    ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="friends-main">
            <div id="chatMount"></div>
        </div>
    </div>
</div>

<div class="modal" id="addFriendModal">
    <div class="modal-content" style="max-width:460px;">
        <span class="close-modal" onclick="document.getElementById('addFriendModal').style.display='none'">&times;</span>
        <h3><i class="fas fa-user-plus"></i> Add Friends</h3>
        <form method="post">
            <div class="form-group">
                <label>Friend User ID</label>
                <input type="text" class="form-control" name="friend_public_id" placeholder="#12345" required>
            </div>
            <button class="btn btn-primary btn-block" type="submit" name="send_request">Send Request</button>
        </form>
    </div>
</div>

<script>
window.addEventListener('click', function (e) {
    const m = document.getElementById('addFriendModal');
    if (e.target === m) m.style.display = 'none';
});

const API_URL = 'friends_api.php';
const SELF_ID = <?php echo (int) $user_id; ?>;
let activeFriendId = 0;
let pollTimer = null;
let lastRenderedConversationKey = '';

const friendLinks = document.querySelectorAll('.friend-item[data-friend-id]');
friendLinks.forEach(link => {
    const fid = parseInt(link.getAttribute('data-friend-id') || '0', 10);
    if (!fid) return;
    link.addEventListener('click', function () {
        openFloatingChat(fid);
        friendLinks.forEach(x => x.classList.remove('active'));
        link.classList.add('active');
    });
});

let floating = document.createElement('div');
floating.className = 'floating-chat';
floating.innerHTML = `
    <div class="fc-head">
        <div class="fc-title" id="fcTitle">Chat</div>
        <div class="fc-actions">
            <button class="fc-mini" id="fcNickBtn">Nickname</button>
            <button class="fc-mini" id="fcEmojiBtn">Emoji</button>
            <button class="fc-mini" id="fcInviteBtn">Trip Invite</button>
            <button class="fc-mini fc-close" id="fcCloseBtn">Close</button>
        </div>
    </div>
    <div id="fcNicknameWrap" style="display:none; padding:8px; border-bottom:1px solid #eceff4;">
        <div style="display:flex; gap:6px;">
            <input id="fcNicknameInput" class="fc-input" placeholder="Nickname (optional)" maxlength="40">
            <button class="fc-mini" id="fcNicknameSave">Save</button>
        </div>
    </div>
    <div class="fc-body" id="fcBody"></div>
    <form class="fc-form" id="fcForm">
        <div class="fc-row" id="fcEmojiRow" style="display:none; flex-wrap:wrap;">
            <button type="button" class="fc-mini fc-emoji">😀</button>
            <button type="button" class="fc-mini fc-emoji">😂</button>
            <button type="button" class="fc-mini fc-emoji">😍</button>
            <button type="button" class="fc-mini fc-emoji">🔥</button>
            <button type="button" class="fc-mini fc-emoji">👍</button>
            <button type="button" class="fc-mini fc-emoji">🙏</button>
        </div>
        <div class="fc-row">
            <input id="fcMessage" class="fc-input" placeholder="Message..." autocomplete="off">
        </div>
        <div class="fc-row">
            <input id="fcFile" class="fc-file" type="file" accept="image/*,video/*,audio/*">
            <button id="fcSendBtn" class="btn btn-primary btn-sm no-loader" type="submit">Send</button>
        </div>
    </form>
`;
document.body.appendChild(floating);
const chatMount = document.getElementById('chatMount');
if (chatMount) {
    chatMount.appendChild(floating);
}

document.getElementById('fcCloseBtn').addEventListener('click', closeFloatingChat);
document.getElementById('fcNickBtn').addEventListener('click', () => {
    const w = document.getElementById('fcNicknameWrap');
    w.style.display = (w.style.display === 'none' || !w.style.display) ? 'block' : 'none';
});
document.getElementById('fcEmojiBtn').addEventListener('click', () => {
    const row = document.getElementById('fcEmojiRow');
    row.style.display = (row.style.display === 'none' || !row.style.display) ? 'flex' : 'none';
});
document.getElementById('fcInviteBtn').addEventListener('click', sendTripInvite);
document.getElementById('fcNicknameSave').addEventListener('click', saveNickname);
document.getElementById('fcForm').addEventListener('submit', sendMessage);
document.querySelectorAll('.fc-emoji').forEach(btn => {
    btn.addEventListener('click', function () {
        const input = document.getElementById('fcMessage');
        input.value += this.textContent;
        input.focus();
    });
});
document.getElementById('fcMessage').addEventListener('keydown', function (e) {
    if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        document.getElementById('fcForm').dispatchEvent(new Event('submit', { cancelable: true }));
    }
});

async function apiRequest(url, options = {}) {
    const res = await fetch(url, options);
    const data = await res.json();
    if (!data.ok) throw new Error(data.error || 'Request failed');
    return data;
}

async function openFloatingChat(friendId) {
    activeFriendId = friendId;
    floating.style.display = 'flex';
    await loadConversation(true);
    startPolling();
}

function closeFloatingChat() {
    floating.style.display = 'none';
    activeFriendId = 0;
    lastRenderedConversationKey = '';
    if (pollTimer) clearInterval(pollTimer);
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    pollTimer = setInterval(() => {
        if (activeFriendId) loadConversation(true);
    }, 2500);
}

async function loadConversation(silent = false) {
    if (!activeFriendId) return;
    try {
        const data = await apiRequest(`${API_URL}?action=conversation&friend_id=${activeFriendId}`);
        const friendName = (data.friend.nickname && data.friend.nickname.trim() !== '')
            ? data.friend.nickname
            : `${data.friend.first_name} ${data.friend.last_name}`;
        document.getElementById('fcTitle').textContent = friendName;
        const messages = data.messages || [];
        const conversationKey = messages.map(m =>
            [
                m.id || '',
                m.sender_id || '',
                m.created_at || '',
                m.message_text || '',
                m.media_path || '',
                m.message_type || '',
                m.is_seen || 0
            ].join('|')
        ).join('~');
        if (conversationKey !== lastRenderedConversationKey) {
            renderMessages(messages);
            lastRenderedConversationKey = conversationKey;
        }
    } catch (e) {
        if (!silent) alert(e.message);
    }
}

function renderMessages(messages) {
    const body = document.getElementById('fcBody');
    body.innerHTML = '';
    messages.forEach(m => {
        const mine = parseInt(m.sender_id, 10) === SELF_ID;
        const box = document.createElement('div');
        box.className = `fc-msg ${mine ? 'me' : 'them'}`;
        let mediaHtml = '';
        if (m.media_path) {
            const src = `<?php echo BASE_URL; ?>${String(m.media_path).replace(/^\/+/, '')}`;
            if (m.message_type === 'image') mediaHtml = `<div style="margin-top:6px;"><img src="${src}" style="max-width:200px;border-radius:8px;"></div>`;
            else if (m.message_type === 'video') mediaHtml = `<div style="margin-top:6px;"><video controls style="max-width:220px;"><source src="${src}"></video></div>`;
            else if (m.message_type === 'audio') mediaHtml = `<div style="margin-top:6px;"><audio controls><source src="${src}"></audio></div>`;
        }
        const invite = parseInt(m.is_trip_invite, 10) === 1 ? `<div class="fc-invite">Trip Invitation</div>` : '';
        const text = m.message_text ? String(m.message_text).replace(/\n/g, '<br>') : '';
        let status = '';
        if (mine) {
            status = `<div class="fc-status">${parseInt(m.is_seen || 0, 10) === 1 ? 'Seen' : 'Delivered'}</div>`;
        }
        box.innerHTML = `${invite}${text}${mediaHtml}<div class="fc-meta">${formatTime(m.created_at)}</div>${status}`;
        body.appendChild(box);
    });
    body.scrollTop = body.scrollHeight;
}

function formatTime(ts) {
    const d = new Date(ts.replace(' ', 'T'));
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleString();
}

async function sendMessage(e) {
    e.preventDefault();
    if (!activeFriendId) return;
    const textEl = document.getElementById('fcMessage');
    const fileEl = document.getElementById('fcFile');
    const sendBtn = document.getElementById('fcSendBtn');
    if (sendBtn.disabled) return;
    const fd = new FormData();
    fd.append('action', 'send_message');
    fd.append('friend_id', String(activeFriendId));
    fd.append('message_text', textEl.value || '');
    if (fileEl.files && fileEl.files[0]) fd.append('media_file', fileEl.files[0]);
    try {
        sendBtn.disabled = true;
        sendBtn.textContent = 'Sending...';
        await apiRequest(API_URL, { method: 'POST', body: fd });
        textEl.value = '';
        fileEl.value = '';
        await loadConversation();
    } catch (err) {
        alert(err.message);
    } finally {
        sendBtn.disabled = false;
        sendBtn.textContent = 'Send';
    }
}

async function sendTripInvite() {
    if (!activeFriendId) return;
    const fd = new FormData();
    fd.append('action', 'send_invite');
    fd.append('friend_id', String(activeFriendId));
    try {
        await apiRequest(API_URL, { method: 'POST', body: fd });
        await loadConversation();
    } catch (e) {
        alert(e.message);
    }
}

async function saveNickname() {
    if (!activeFriendId) return;
    const nick = document.getElementById('fcNicknameInput').value;
    const nicknameWrap = document.getElementById('fcNicknameWrap');
    const nicknameInput = document.getElementById('fcNicknameInput');
    const fd = new FormData();
    fd.append('action', 'save_nickname');
    fd.append('friend_id', String(activeFriendId));
    fd.append('nickname', nick);
    try {
        await apiRequest(API_URL, { method: 'POST', body: fd });
        await loadConversation();
        if (nicknameWrap) {
            nicknameWrap.style.display = 'none';
        }
        if (nicknameInput) {
            nicknameInput.blur();
        }
    } catch (e) {
        alert(e.message);
    }
}
</script>
<?php require_once '../includes/footer.php'; ?>
