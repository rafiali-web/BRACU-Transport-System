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

<div class="container">
    <!-- Header -->
    <div class="leaderboard-header">
        <h1 style="font-size: 3rem; margin-bottom: 10px;"><i class="fas fa-trophy"></i> Leaderboard</h1>
        <p style="font-size: 1.2rem;">Top students ranked by points earned this week</p>
    </div>
    
    <!-- Week Selector -->
    <div class="week-selector">
        <a href="?week=<?php echo $week-1; ?>&year=<?php echo $year; ?>" class="btn btn-sm btn-secondary">
            <i class="fas fa-chevron-left"></i> Previous Week
        </a>
        <span style="font-weight: bold; padding: 0 20px;">
            Week <?php echo $week; ?>, <?php echo $year; ?>
        </span>
        <a href="?week=<?php echo $week+1; ?>&year=<?php echo $year; ?>" class="btn btn-sm btn-secondary">
            Next Week <i class="fas fa-chevron-right"></i>
        </a>
        <a href="?week=<?php echo date('W'); ?>&year=<?php echo date('Y'); ?>" class="btn btn-sm btn-primary">
            Current Week
        </a>
    </div>
    
    <!-- Podium (Top 3) -->
    <?php if (count($rankings) >= 3): ?>
    <div class="podium">
        <!-- 2nd Place -->
        <div class="podium-item podium-2">
            <div class="podium-rank">2</div>
            <div class="podium-name"><?php echo htmlspecialchars($rankings[1]['first_name'] . ' ' . $rankings[1]['last_name']); ?></div>
            <div class="podium-points"><?php echo number_format($rankings[1]['weekly_points']); ?> pts</div>
            <div class="podium-bar" style="--height: 160px;"></div>
        </div>
        
        <!-- 1st Place -->
        <div class="podium-item podium-1">
            <div class="podium-rank">1</div>
            <div class="podium-name"><?php echo htmlspecialchars($rankings[0]['first_name'] . ' ' . $rankings[0]['last_name']); ?></div>
            <div class="podium-points"><?php echo number_format($rankings[0]['weekly_points']); ?> pts</div>
            <div class="podium-bar" style="--height: 200px;"></div>
        </div>
        
        <!-- 3rd Place -->
        <div class="podium-item podium-3">
            <div class="podium-rank">3</div>
            <div class="podium-name"><?php echo htmlspecialchars($rankings[2]['first_name'] . ' ' . $rankings[2]['last_name']); ?></div>
            <div class="podium-points"><?php echo number_format($rankings[2]['weekly_points']); ?> pts</div>
            <div class="podium-bar" style="--height: 120px;"></div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Your Rank Card -->
    <?php if ($user_rank > 0): ?>
    <div class="card" style="margin-bottom: 20px; background: linear-gradient(135deg, #f6f9fc, #e9f2f9);">
        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
            <div>
                <span style="color: #666;">Your Rank</span>
                <div style="font-size: 2rem; font-weight: bold; color: #667eea;">#<?php echo $user_rank; ?></div>
            </div>
            <div>
                <span style="color: #666;">Weekly Points</span>
                <div style="font-size: 2rem; font-weight: bold; color: #667eea;"><?php echo number_format($user_points); ?></div>
            </div>
            <a href="rewards.php" class="btn btn-primary">
                <i class="fas fa-gift"></i> Redeem Points
            </a>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Full Leaderboard -->
    <div class="leaderboard-table">
        <?php foreach ($rankings as $index => $user): 
            $rank = $index + 1;
            $is_current = ($user['id'] == $user_id);
        ?>
        <div class="leaderboard-row <?php echo $is_current ? 'current-user' : ''; ?>">
            <div class="rank rank-<?php echo $rank; ?>">#<?php echo $rank; ?></div>
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
            </div>
            <div class="user-info">
                <span class="user-name"><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></span>
                <?php if ($rank <= 3): ?>
                    <span class="user-badge">
                        <?php 
                        if ($rank == 1) echo '🥇 GOLD';
                        elseif ($rank == 2) echo '🥈 SILVER';
                        elseif ($rank == 3) echo '🥉 BRONZE';
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <div class="points-info">
                <span class="weekly-points"><?php echo number_format($user['weekly_points']); ?> pts</span>
                <span class="lifetime-points">Lifetime: <?php echo number_format($user['lifetime_points']); ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <!-- Prizes Info -->
    <div style="margin-top: 30px; text-align: center;">
        <h3>Weekly Prizes</h3>
        <div style="display: flex; justify-content: center; gap: 30px; margin-top: 20px;">
            <div style="text-align: center;">
                <div style="background: gold; width: 50px; height: 50px; border-radius: 50%; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-crown" style="color: white;"></i>
                </div>
                <strong>1st Place</strong>
                <p>100% Off Voucher</p>
            </div>
            <div style="text-align: center;">
                <div style="background: silver; width: 50px; height: 50px; border-radius: 50%; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-medal" style="color: white;"></i>
                </div>
                <strong>2nd Place</strong>
                <p>50% Off Voucher</p>
            </div>
            <div style="text-align: center;">
                <div style="background: #cd7f32; width: 50px; height: 50px; border-radius: 50%; margin: 0 auto 10px; display: flex; align-items: center; justify-content: center;">
                    <i class="fas fa-medal" style="color: white;"></i>
                </div>
                <strong>3rd Place</strong>
                <p>30% Off Voucher</p>
            </div>
        </div>
        <p style="margin-top: 20px; color: #666;">
            <i class="fas fa-info-circle"></i> Winners are announced every Monday. Vouchers valid for 30 days.
        </p>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
    
