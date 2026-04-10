<?php
/*
 * Database configuration file
 * Establishes connection to SQLite database
 */

// Base URL for CSS and other assets
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http";
$host = $_SERVER['HTTP_HOST'];
$script_name = $_SERVER['SCRIPT_NAME'];
$script_dir = dirname($script_name);

// Determine project root path
if (strpos($script_dir, '/pages') !== false) {
    $project_path = dirname($script_dir);
} elseif (strpos($script_dir, '/includes') !== false) {
    $project_path = dirname($script_dir);
} else {
    $project_path = $script_dir;
}

$project_path = trim($project_path, '/');
if (empty($project_path)) {
    $base_url = $protocol . "://" . $host . "/";
} else {
    $base_url = $protocol . "://" . $host . "/" . $project_path . "/";
}
define('BASE_URL', $base_url);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// SQLite Database connection
try {
    // Use SQLite database file in the project root
    $db_file = __DIR__ . '/../university_bus.sqlite';
    $pdo = new PDO("sqlite:" . $db_file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable foreign keys for SQLite
    $pdo->exec("PRAGMA foreign_keys = ON");
    
} catch(PDOException $e) {
    die("ERROR: Could not connect. " . $e->getMessage());
}

// Start session
session_start();

// Function to refresh wallet balance
function refreshWalletBalance($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $wallet = $stmt->fetch();
    $balance = $wallet ? $wallet['balance'] : 0;
    $_SESSION['balance'] = $balance;
    return $balance;
}

// Function to check if user is admin
function isAdmin($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT user_type FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();
    return $user && $user['user_type'] === 'admin';
}

// Function to get user info
function getUserInfo($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id, student_id, first_name, last_name, email, user_type, total_points FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch();
}

// Function to check if user is a verified rider
function isRider($pdo, $user_id) {
    $stmt = $pdo->prepare("SELECT id FROM riders WHERE user_id = ? AND is_verified = 1");
    $stmt->execute([$user_id]);
    return $stmt->fetch() ? true : false;
}

// Function to award points for activities
// Function to award points for activities
function awardPoints($pdo, $user_id, $points, $action, $description, $reference_id = null) {
    // Update user's total points
    $pdo->prepare("UPDATE users SET total_points = total_points + ? WHERE id = ?")
        ->execute([$points, $user_id]);
    
    // Record in points history
    $pdo->prepare("INSERT INTO points_history (user_id, points, action, description, reference_id) VALUES (?, ?, ?, ?, ?)")
        ->execute([$user_id, $points, $action, $description, $reference_id]);
    
    // Update weekly leaderboard
    $week = date('W');
    $year = date('Y');
    
    $pdo->prepare("
        INSERT INTO weekly_leaderboard (user_id, week_number, year, total_points) 
        VALUES (?, ?, ?, ?)
        ON CONFLICT(user_id, week_number, year) 
        DO UPDATE SET total_points = total_points + ?
    ")->execute([$user_id, $week, $year, $points, $points]);
    
    return true;
}

// Function to get user rank
function getUserRank($pdo, $user_id) {
    $week = date('W');
    $year = date('Y');
    
    $stmt = $pdo->prepare("
        SELECT COUNT(*) + 1 as rank
        FROM weekly_leaderboard
        WHERE week_number = ? AND year = ? AND total_points > (
            SELECT COALESCE(total_points, 0) FROM weekly_leaderboard WHERE user_id = ? AND week_number = ? AND year = ?
        )
    ");
    $stmt->execute([$week, $year, $user_id, $week, $year]);
    return $stmt->fetchColumn();
}

// Function to calculate weekly leaderboard and generate vouchers
function calculateWeeklyLeaderboard($pdo) {
    $week = date('W');
    $year = date('Y');
    
    // Get top 3 users for the week
    $stmt = $pdo->prepare("
        SELECT user_id, total_points 
        FROM weekly_leaderboard 
        WHERE week_number = ? AND year = ? 
        ORDER BY total_points DESC 
        LIMIT 3
    ");
    $stmt->execute([$week, $year]);
    $top_users = $stmt->fetchAll();
    
    $rank = 1;
    foreach ($top_users as $row) {
        // Determine voucher type based on rank
        $voucher_type = 'none';
        $discount_value = 0;
        
        if ($rank == 1) {
            $voucher_type = '100%_off';
            $discount_value = 100;
        } elseif ($rank == 2) {
            $voucher_type = '50%_off';
            $discount_value = 50;
        } elseif ($rank == 3) {
            $voucher_type = '30%_off';
            $discount_value = 30;
        }
        
        // Update rank in leaderboard
        $stmt = $pdo->prepare("
            UPDATE weekly_leaderboard 
            SET rank_position = ?, voucher_type = ? 
            WHERE user_id = ? AND week_number = ? AND year = ?
        ");
        $stmt->execute([$rank, $voucher_type, $row['user_id'], $week, $year]);
        
        // Generate voucher for top 3
        if ($rank <= 3) {
            $voucher_code = 'VOUCHER' . $week . $year . $row['user_id'] . rand(100, 999);
            $expiry_date = date('Y-m-d', strtotime('+30 days'));
            
            $stmt = $pdo->prepare("
                INSERT INTO vouchers (user_id, voucher_code, discount_type, discount_value, week_number, year, expiry_date) 
                VALUES (?, ?, 'percentage', ?, ?, ?, ?)
            ");
            $stmt->execute([$row['user_id'], $voucher_code, $discount_value, $week, $year, $expiry_date]);
        }
        
        $rank++;
    }
}
?>