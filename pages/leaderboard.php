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
