<?php
/**
 * Toggle Rider Availability
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];

// Get current availability
$rider_stmt = $pdo->prepare("SELECT is_available FROM riders WHERE user_id = ?");
$rider_stmt->execute([$user_id]);
$rider = $rider_stmt->fetch();

if ($rider) {
    $new_status = $rider['is_available'] ? 0 : 1;
    
    $update_stmt = $pdo->prepare("UPDATE riders SET is_available = ? WHERE user_id = ?");
    $update_stmt->execute([$new_status, $user_id]);
    
    $_SESSION['success'] = "Availability updated!";
} else {
    $_SESSION['error'] = "You are not registered as a rider.";
}

header("Location: uber.php?mode=rider");
exit;