<?php
require_once '../includes/config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isAdmin($pdo, $_SESSION['id'])) {
    header('Location: login.php');
    exit;
}

// Keep table available even on older DB files.
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_reviews (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        rating INTEGER NOT NULL CHECK (rating BETWEEN 1 AND 5),
        review_text TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )
");
$pdo->exec("
    CREATE TABLE IF NOT EXISTS user_review_votes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        review_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        vote_type TEXT NOT NULL CHECK (vote_type IN ('like', 'dislike')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (review_id) REFERENCES user_reviews(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
        UNIQUE (review_id, user_id)
    )
");

$reviews_stmt = $pdo->query("
    SELECT
        ur.id,
        ur.rating,
        ur.review_text,
        ur.created_at,
        u.id AS user_id,
        u.student_id,
        u.first_name,
        u.last_name,
        u.email,
        COALESCE(SUM(CASE WHEN rv.vote_type = 'like' THEN 1 ELSE 0 END), 0) AS like_count,
        COALESCE(SUM(CASE WHEN rv.vote_type = 'dislike' THEN 1 ELSE 0 END), 0) AS dislike_count
    FROM user_reviews ur
    INNER JOIN users u ON u.id = ur.user_id
    LEFT JOIN user_review_votes rv ON rv.review_id = ur.id
    GROUP BY ur.id, ur.rating, ur.review_text, ur.created_at, u.id, u.student_id, u.first_name, u.last_name, u.email
    ORDER BY ur.created_at DESC
");
$reviews = $reviews_stmt->fetchAll();

require_once '../includes/header.php';
?>

<h1 class="page-title">User Review Reports <i class="fas fa-chart-bar"></i></h1>

<div class="card">
    <h2><i class="fas fa-list"></i> All Submitted Reviews</h2>
    <p style="color: #666;">This report shows all user feedback with account details for admin monitoring.</p>

    <?php if (empty($reviews)): ?>
        <p style="color: #888; margin: 0;">No reviews submitted yet.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Review ID</th>
                    <th>User</th>
                    <th>Student ID</th>
                    <th>Email</th>
                    <th>Rating</th>
                    <th>Likes</th>
                    <th>Dislikes</th>
                    <th>Review</th>
                    <th>Submitted</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($reviews as $r): ?>
                <tr>
                    <td>#<?php echo (int) $r['id']; ?></td>
                    <td><?php echo htmlspecialchars($r['first_name'] . ' ' . $r['last_name']); ?></td>
                    <td><?php echo htmlspecialchars((string) ($r['student_id'] ?? 'N/A')); ?></td>
                    <td><?php echo htmlspecialchars($r['email']); ?></td>
                    <td><?php echo (int) $r['rating']; ?>/5</td>
                    <td><?php echo (int) $r['like_count']; ?></td>
                    <td><?php echo (int) $r['dislike_count']; ?></td>
                    <td style="max-width: 420px;"><?php echo nl2br(htmlspecialchars($r['review_text'])); ?></td>
                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($r['created_at']))); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>