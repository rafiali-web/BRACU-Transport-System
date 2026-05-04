<?php

require_once '../includes/config.php';


if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];


$user = $pdo->prepare("SELECT total_points FROM users WHERE id = ?");
$user->execute([$user_id]);
$total_points = $user->fetchColumn();


$history = $pdo->prepare("
    SELECT * FROM points_history 
    WHERE user_id = ? 
    ORDER BY COALESCE(created_at, earned_date) DESC 
    LIMIT 20
");
$history->execute([$user_id]);
$points_history = $history->fetchAll();


$rewards = $pdo->prepare("
    SELECT ur.*, r.name, r.description, r.discount_type, r.discount_value, r.icon, r.color
    FROM user_rewards ur
    JOIN rewards r ON ur.reward_id = r.id
    WHERE ur.user_id = ? AND ur.is_used = 0 AND (ur.expires_at IS NULL OR ur.expires_at > CURRENT_TIMESTAMP)
    ORDER BY ur.created_at DESC
");
$rewards->execute([$user_id]);
$active_rewards = $rewards->fetchAll();


$stats = [
    'earned' => $pdo->prepare("SELECT SUM(points) FROM points_history WHERE user_id = ? AND points > 0")->execute([$user_id]) ? 
               abs($pdo->query("SELECT SUM(points) FROM points_history WHERE user_id = $user_id AND points > 0")->fetchColumn()) : 0,
    'spent' => $pdo->prepare("SELECT SUM(points) FROM points_history WHERE user_id = ? AND points < 0")->execute([$user_id]) ? 
               abs($pdo->query("SELECT SUM(points) FROM points_history WHERE user_id = $user_id AND points < 0")->fetchColumn()) : 0,
];

require_once '../includes/header.php';
?>

<style>
.points-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 20px;
    margin-bottom: 30px;
    text-align: center;
}

.points-circle {
    width: 150px;
    height: 150px;
    border-radius: 50%;
    background: rgba(255,255,255,0.2);
    margin: 0 auto 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 4px solid white;
}

.points-number {
    font-size: 3rem;
    font-weight: bold;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 15px;
    padding: 25px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #667eea;
    margin: 10px 0;
}

.stat-label {
    color: #666;
}

.reward-card {
    background: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 15px;
    border-left: 4px solid #667eea;
    display: flex;
    align-items: center;
    gap: 20px;
    flex-wrap: wrap;
}

.reward-icon {
    width: 50px;
    height: 50px;
    border-radius: 10px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
}

.reward-code {
    font-family: monospace;
    font-size: 1.2rem;
    background: #f0f0f0;
    padding: 8px 15px;
    border-radius: 5px;
    letter-spacing: 1px;
}

.history-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border-bottom: 1px solid #eee;
}

.history-item:last-child {
    border-bottom: none;
}

.history-points {
    font-weight: bold;
    font-size: 1.2rem;
}

.history-points.positive {
    color: #4CAF50;
}

.history-points.negative {
    color: #f44336;
}

.history-date {
    color: #999;
    font-size: 0.9rem;
}
</style>

<div class="container">

    <div class="points-header">
        <div class="points-circle">
            <span class="points-number"><?php echo number_format($total_points); ?></span>
        </div>
        <h2>Your Points Balance</h2>
        <p>Keep earning points to unlock amazing rewards!</p>
    </div>
    
 
    <div class="stats-grid">
        <div class="stat-card">
            <i class="fas fa-arrow-up" style="color: #4CAF50; font-size: 2rem;"></i>
            <div class="stat-value"><?php echo number_format($stats['earned']); ?></div>
            <div class="stat-label">Total Points Earned</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-arrow-down" style="color: #f44336; font-size: 2rem;"></i>
            <div class="stat-value"><?php echo number_format($stats['spent']); ?></div>
            <div class="stat-label">Points Spent</div>
        </div>
        <div class="stat-card">
            <i class="fas fa-gift" style="color: #FF9800; font-size: 2rem;"></i>
            <div class="stat-value"><?php echo count($active_rewards); ?></div>
            <div class="stat-label">Active Rewards</div>
        </div>
    </div>
    
   
    <?php if (count($active_rewards) > 0): ?>
    <div class="card">
        <h2><i class="fas fa-tag"></i> Your Active Rewards</h2>
        
        <?php foreach ($active_rewards as $reward): ?>
        <div class="reward-card">
            <div class="reward-icon" style="background: <?php echo $reward['color']; ?>;">
                <i class="fas <?php echo $reward['icon']; ?>"></i>
            </div>
            <div style="flex: 1;">
                <h3 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($reward['name']); ?></h3>
                <p style="margin: 0; color: #666;"><?php echo htmlspecialchars($reward['description']); ?></p>
                <?php if ($reward['expires_at']): ?>
                    <small style="color: #999;">Expires: <?php echo date('M j, Y', strtotime($reward['expires_at'])); ?></small>
                <?php endif; ?>
            </div>
            <div style="text-align: center;">
                <div class="reward-code"><?php echo $reward['discount_code']; ?></div>
                <button class="btn btn-sm btn-primary" onclick="copyCode('<?php echo $reward['discount_code']; ?>')">
                    <i class="fas fa-copy"></i> Copy
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <div class="card">
        <h2><i class="fas fa-history"></i> Points History</h2>
        
        <?php if (count($points_history) > 0): ?>
            <?php foreach ($points_history as $item): ?>
            <div class="history-item">
                <div>
                    <strong><?php echo htmlspecialchars($item['description'] ?? $item['action'] ?? $item['source_type'] ?? 'Points'); ?></strong>
                    <div class="history-date"><?php echo date('M j, Y g:i A', strtotime($item['created_at'] ?? $item['earned_date'] ?? 'now')); ?></div>
                </div>
                <div class="history-points <?php echo $item['points'] > 0 ? 'positive' : 'negative'; ?>">
                    <?php echo $item['points'] > 0 ? '+' : ''; ?><?php echo number_format($item['points']); ?> ⭐
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p style="text-align: center; padding: 40px; color: #999;">
                <i class="fas fa-history fa-3x" style="margin-bottom: 15px;"></i><br>
                No points history yet. Start using the platform to earn points!
            </p>
        <?php endif; ?>
    </div>
    
   
    <div class="card">
        <h2><i class="fas fa-star"></i> Ways to Earn Points</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px;">
            <div style="text-align: center;">
                <i class="fas fa-bus" style="font-size: 2rem; color: #667eea;"></i>
                <h4>Book a Bus</h4>
                <p>5 points per trip</p>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-taxi" style="font-size: 2rem; color: #667eea;"></i>
                <h4>Take an Uber</h4>
                <p>10 points per ride</p>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-users" style="font-size: 2rem; color: #667eea;"></i>
                <h4>Join Ride Share</h4>
                <p>3 points per ride</p>
            </div>
            <div style="text-align: center;">
                <i class="fas fa-user-plus" style="font-size: 2rem; color: #667eea;"></i>
                <h4>Refer a Friend</h4>
                <p>50 points per referral</p>
            </div>
        </div>
    </div>
</div>

<script>
function copyCode(code) {
    navigator.clipboard.writeText(code).then(function() {
        alert('Discount code copied to clipboard!');
    });
}
</script>

<?php require_once '../includes/footer.php'; ?>
