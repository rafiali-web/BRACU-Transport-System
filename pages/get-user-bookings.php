<?php
require_once '../includes/config.php';

if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true || !isAdmin($pdo, $_SESSION['id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if (!isset($_GET['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'User ID required']);
    exit;
}

$user_id = (int)$_GET['user_id'];

// Get user's detailed bookings
$sql = "SELECT 
            b.*,
            bus.bus_number,
            rt.name as route_name,
            rt.fare,
            rt.start_point,
            rt.end_point
        FROM bookings b
        JOIN buses bus ON b.bus_id = bus.id
        JOIN routes rt ON bus.route_id = rt.id
        WHERE b.user_id = :user_id
        ORDER BY b.booking_time DESC";

$bookings = [];

if ($stmt = $pdo->prepare($sql)) {
    $stmt->bindParam(":user_id", $user_id, PDO::PARAM_INT);
    
    if ($stmt->execute()) {
        $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($stmt);
}

header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'bookings' => $bookings
]);