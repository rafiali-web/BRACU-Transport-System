<?php


require_once '../includes/config.php';


if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking_id'])) {
    $booking_id = (int)$_POST['booking_id'];
    $user_id = $_SESSION['id'];
    
   
    $check_stmt = $pdo->prepare("SELECT * FROM bookings WHERE id = ? AND user_id = ? AND status = 'pending'");
    $check_stmt->execute([$booking_id, $user_id]);
    
    if ($check_stmt->fetch()) {
       
        $update_stmt = $pdo->prepare("UPDATE bookings SET status = 'confirmed' WHERE id = ?");
        if ($update_stmt->execute([$booking_id])) {
            $_SESSION['success'] = "Booking confirmed successfully!";
        } else {
            $_SESSION['error'] = "Failed to confirm booking.";
        }
    } else {
        $_SESSION['error'] = "Booking not found or already confirmed.";
    }
}

header("Location: my-bookings.php");
exit;
?>
