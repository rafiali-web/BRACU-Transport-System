<?php
require_once '../includes/config.php';
ensureFriendsSchema($pdo);

header('Content-Type: application/json');

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$user_id = (int) $_SESSION['id'];

function pairIds($a, $b) {
    $a = (int) $a;
    $b = (int) $b;
    return $a < $b ? [$a, $b] : [$b, $a];
}

function isFriend(PDO $pdo, $uid, $fid) {
    [$u1, $u2] = pairIds($uid, $fid);
    $q = $pdo->prepare("SELECT id FROM friendships WHERE user_one_id = ? AND user_two_id = ?");
    $q->execute([$u1, $u2]);
    return (bool) $q->fetch();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    if ($action === 'conversation') {
        $friend_id = (int) ($_GET['friend_id'] ?? 0);
        if ($friend_id <= 0 || !isFriend($pdo, $user_id, $friend_id)) {
            throw new RuntimeException('Invalid friend.');
        }

        // Mark incoming messages from this friend as seen
        $pdo->prepare("
            UPDATE friend_messages
            SET is_seen = 1, seen_at = CURRENT_TIMESTAMP
            WHERE sender_id = ? AND receiver_id = ? AND is_seen = 0
        ")->execute([$friend_id, $user_id]);

        $friendStmt = $pdo->prepare("
            SELECT u.id, u.first_name, u.last_name, u.public_user_id, fn.nickname
            FROM users u
            LEFT JOIN friend_nicknames fn ON fn.owner_user_id = ? AND fn.friend_user_id = u.id
            WHERE u.id = ?
        ");
        $friendStmt->execute([$user_id, $friend_id]);
        $friend = $friendStmt->fetch();

        $msgStmt = $pdo->prepare("
            SELECT id, sender_id, receiver_id, message_type, message_text, media_path, is_trip_invite, is_seen, seen_at, created_at
            FROM friend_messages
            WHERE (sender_id = ? AND receiver_id = ?) OR (sender_id = ? AND receiver_id = ?)
            ORDER BY id ASC
            LIMIT 300
        ");
        $msgStmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
        $messages = $msgStmt->fetchAll();

        echo json_encode(['ok' => true, 'friend' => $friend, 'messages' => $messages]);
        exit;
    }

    if ($action === 'send_message') {
        $friend_id = (int) ($_POST['friend_id'] ?? 0);
        if ($friend_id <= 0 || !isFriend($pdo, $user_id, $friend_id)) {
            throw new RuntimeException('Invalid friend.');
        }

        $text = trim((string) ($_POST['message_text'] ?? ''));
        $type = 'text';
        $mediaPath = null;

        if (!empty($_FILES['media_file']['name']) && (int) $_FILES['media_file']['error'] === UPLOAD_ERR_OK) {
            $tmp = $_FILES['media_file']['tmp_name'];
            $name = basename((string) $_FILES['media_file']['name']);
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            $allowed = [
                'image' => ['jpg', 'jpeg', 'png', 'gif', 'webp'],
                'video' => ['mp4', 'webm', 'ogg'],
                'audio' => ['mp3', 'wav', 'ogg', 'm4a']
            ];
            foreach ($allowed as $k => $arr) {
                if (in_array($ext, $arr, true)) {
                    $type = $k;
                    break;
                }
            }
            if ($type === 'text') {
                throw new RuntimeException('Unsupported media type.');
            }
            $dir = '../uploads/chat_media';
            if (!is_dir($dir)) {
                mkdir($dir, 0777, true);
            }
            $safeFile = time() . '_' . $user_id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
            $dest = $dir . '/' . $safeFile;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('Failed to upload media.');
            }
            $mediaPath = 'uploads/chat_media/' . $safeFile;
        }

        if ($text === '' && $mediaPath === null) {
            throw new RuntimeException('Message cannot be empty.');
        }
        $text = mb_substr($text, 0, 1000);

        $pdo->prepare("
            INSERT INTO friend_messages (sender_id, receiver_id, message_type, message_text, media_path)
            VALUES (?, ?, ?, ?, ?)
        ")->execute([$user_id, $friend_id, $type, $text !== '' ? $text : null, $mediaPath]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'send_invite') {
        $friend_id = (int) ($_POST['friend_id'] ?? 0);
        if ($friend_id <= 0 || !isFriend($pdo, $user_id, $friend_id)) {
            throw new RuntimeException('Invalid friend.');
        }
        $msg = 'I am inviting you to travel with me. Open ride-share or Uber and join me.';
        $pdo->prepare("
            INSERT INTO friend_messages (sender_id, receiver_id, message_type, message_text, is_trip_invite)
            VALUES (?, ?, 'invite', ?, 1)
        ")->execute([$user_id, $friend_id, $msg]);
        $pdo->prepare("INSERT INTO friend_notifications (user_id, actor_user_id, type, payload) VALUES (?, ?, 'trip_invite', ?)")
            ->execute([$friend_id, $user_id, json_encode(['message' => $msg])]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'save_nickname') {
        $friend_id = (int) ($_POST['friend_id'] ?? 0);
        $nickname = trim((string) ($_POST['nickname'] ?? ''));
        if ($friend_id <= 0 || !isFriend($pdo, $user_id, $friend_id)) {
            throw new RuntimeException('Invalid friend.');
        }
        if ($nickname === '') {
            $pdo->prepare("DELETE FROM friend_nicknames WHERE owner_user_id = ? AND friend_user_id = ?")
                ->execute([$user_id, $friend_id]);
        } else {
            $nickname = mb_substr($nickname, 0, 40);
            $pdo->prepare("
                INSERT INTO friend_nicknames (owner_user_id, friend_user_id, nickname)
                VALUES (?, ?, ?)
                ON CONFLICT(owner_user_id, friend_user_id)
                DO UPDATE SET nickname = excluded.nickname, updated_at = CURRENT_TIMESTAMP
            ")->execute([$user_id, $friend_id, $nickname]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    throw new RuntimeException('Invalid action.');
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
