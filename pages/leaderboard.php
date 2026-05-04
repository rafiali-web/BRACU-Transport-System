<?php
/**
 * Enhanced Leaderboard with Weekly Rankings
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];
$week = isset($_GET['week']) ? (int)$_GET['week'] : date('W');
$year = isset($_GET['year']) ? (int)$_GET['year'] : date('Y');

// Get weekly leaderboard
$leaderboard = $pdo->prepare("
    SELECT u.id, u.first_name, u.last_name, u.total_points as lifetime_points,
           COALESCE(wl.total_points, 0) as weekly_points,
           wl.rank_position
    FROM users u
    LEFT JOIN weekly_leaderboard wl ON u.id = wl.user_id AND wl.week_number = ? AND wl.year = ?
    WHERE u.user_type = 'student'
    ORDER BY weekly_points DESC, lifetime_points DESC
    LIMIT 50
");
$leaderboard->execute([$week, $year]);
$rankings = $leaderboard->fetchAll();

// Get current user's rank
$user_rank = 0;
$user_points = 0;
foreach ($rankings as $index => $user) {
    if ($user['id'] == $user_id) {
        $user_rank = $index + 1;
        $user_points = $user['weekly_points'];
        break;
    }
}

require_once '../includes/header.php';
?>

<style>
.leaderboard-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 30px;
    text-align: center;
}

.podium {
    display: flex;
    justify-content: center;
    align-items: flex-end;
    gap: 20px;
    margin: 40px 0;
    padding: 20px;
}

.podium-item {
    text-align: center;
    position: relative;
}

.podium-1 {
    order: 2;
}

.podium-2 {
    order: 1;
}

.podium-3 {
    order: 3;
}

.podium-rank {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 1.5rem;
    font-weight: bold;
    color: white;
}
