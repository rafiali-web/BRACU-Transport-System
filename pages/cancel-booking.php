<?php
/**
 * Cancel Booking page for University Bus Booking System
 * Handles booking cancellation and refunds
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
    $user_id = $_SESSION['id'];
    $refund_percentage = isset($_POST['refund_percentage']) ? (int)$_POST['refund_percentage'] : 0;
    
    // Verify the booking belongs to the user and get booking details
    $booking_stmt = $pdo->prepare("
        SELECT b.*, rt.fare, bus.departure_time
        FROM bookings b
        JOIN buses bus ON b.bus_id = bus.id
        JOIN routes rt ON bus.route_id = rt.id
        WHERE b.id = ? AND b.user_id = ?
    ");
    $booking_stmt->execute([$booking_id, $user_id]);
    $booking = $booking_stmt->fetch();
    
    if (!$booking) {
        $_SESSION['error'] = "Booking not found or access denied.";
        header("Location: my-bookings.php");
        exit;
    }
    
    // Check if fare is available
    if (!isset($booking['fare']) || $booking['fare'] === null) {
        $_SESSION['error'] = "Cannot process cancellation: Fare information missing.";
        header("Location: my-bookings.php");
        exit;
    }
    
    $fare_amount = (float)$booking['fare'];
    $refund_amount = $fare_amount * ($refund_percentage / 100);
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Update booking status to cancelled
        $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'cancelled' WHERE id = ?");
        $update_stmt->execute([$booking_id]);
        
        // Refund money to wallet only if refund is applicable
        if ($refund_amount > 0 && $booking['status'] == 'confirmed') {
            $refund_stmt = $pdo->prepare("UPDATE wallet SET balance = balance + ? WHERE user_id = ?");
            $refund_stmt->execute([$refund_amount, $user_id]);
            
            // Record transaction
            $trans_stmt = $pdo->prepare("
                INSERT INTO transactions (user_id, amount, type, description) 
                VALUES (?, ?, 'credit', 'Booking cancellation refund ($refund_percentage%)')
            ");
            $trans_stmt->execute([$user_id, $refund_amount]);
            
            // Refresh wallet balance in session
            refreshWalletBalance($pdo, $user_id);
        }
        
        $pdo->commit();
        
        if ($refund_amount > 0) {
            $_SESSION['success'] = "Booking cancelled successfully. $$refund_amount refunded to your wallet.";
        } else {
            $_SESSION['success'] = "Booking cancelled successfully. No refund applicable.";
        }
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Failed to cancel booking: " . $e->getMessage();
    }
} else {
    $_SESSION['error'] = "Invalid request.";
}

header("Location: my-bookings.php");
exit;
?>