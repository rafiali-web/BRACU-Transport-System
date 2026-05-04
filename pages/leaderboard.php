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
    .podium-1 .podium-rank {
    background: gold;
    box-shadow: 0 0 30px rgba(255,215,0,0.5);
}

.podium-2 .podium-rank {
    background: silver;
    box-shadow: 0 0 30px rgba(192,192,192,0.5);
}

.podium-3 .podium-rank {
    background: #cd7f32;
    box-shadow: 0 0 30px rgba(205,127,50,0.5);
}

.podium-name {
    font-weight: bold;
    margin: 10px 0;
}

.podium-points {
    color: #667eea;
    font-weight: bold;
}

.podium-bar {
    width: 100px;
    height: 150px;
    background: linear-gradient(to top, #667eea, #764ba2);
    border-radius: 50px 50px 0 0;
    margin-top: 20px;
    position: relative;
    animation: grow 1s ease-out;
}

.podium-1 .podium-bar {
    height: 200px;
}

.podium-2 .podium-bar {
    height: 160px;
}

.podium-3 .podium-bar {
    height: 120px;
}

@keyframes grow {
    from { height: 0; }
    to { height: var(--height); }
}

.leaderboard-table {
    background: white;
    border-radius: 15px;
    overflow: hidden;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.leaderboard-row {
    display: flex;
    align-items: center;
    padding: 15px 20px;
    border-bottom: 1px solid #f0f0f0;
    transition: all 0.3s;
}

.leaderboard-row:hover {
    background: #f8f9fa;
    transform: translateX(5px);
}

.leaderboard-row.current-user {
    background: linear-gradient(135deg, #fff9e6, #fff);
    border-left: 4px solid gold;
}

.rank {
    width: 60px;
    font-weight: bold;
    font-size: 1.2rem;
}

.rank-1 { color: gold; }
.rank-2 { color: silver; }
.rank-3 { color: #cd7f32; }

.user-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 15px;
}

.user-info {
    flex: 1;
}

.user-name {
    font-weight: bold;
}

.user-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 12px;
    font-size: 0.7rem;
    background: #4CAF50;
    color: white;
    margin-left: 10px;
}

.points-info {
    display: flex;
    align-items: center;
    gap: 30px;
}

.weekly-points {
    font-weight: bold;
    color: #667eea;
    font-size: 1.2rem;
}

.lifetime-points {
    color: #999;
    font-size: 0.9rem;
}

.week-selector {
    background: white;
    border-radius: 10px;
    padding: 15px;
    margin-bottom: 20px;
    display: flex;
    gap: 15px;
    align-items: center;
    justify-content: center;
    flex-wrap: wrap;
}
</style>
    
