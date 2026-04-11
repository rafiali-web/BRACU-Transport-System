<?php
/**
 * Buses page for University Bus Booking System
 * Displays buses for a selected route
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Get route ID from query parameter
$route_id = isset($_GET['route_id']) ? (int)$_GET['route_id'] : 0;

// Fetch route details
$route_stmt = $pdo->prepare("SELECT * FROM routes WHERE id = ? AND active = 1");
$route_stmt->execute([$route_id]);
$route = $route_stmt->fetch();

if (!$route) {
    $_SESSION['error'] = "Invalid route selected";
    header("Location: routes.php");
    exit();
}

// Fetch buses for this route
$buses_stmt = $pdo->prepare("
    SELECT b.*, 
           (SELECT COUNT(*) FROM bookings WHERE bus_id = b.id AND status IN ('confirmed', 'pending')) as booked_seats
    FROM buses b 
    WHERE b.route_id = ? AND b.active = 1 
    ORDER BY b.departure_time
");
$buses_stmt->execute([$route_id]);
$buses = $buses_stmt->fetchAll();

require_once '../includes/header.php';
?>

<h1 class="page-title">Buses for <?php echo htmlspecialchars($route['name']); ?></h1>

<?php if (count($buses) > 0): ?>
    <div class="route-grid">
        <?php foreach ($buses as $bus): 
            $available_seats = $bus['capacity'] - $bus['booked_seats'];
        ?>
        <div class="route-card">
            <div class="route-name">Bus #<?php echo htmlspecialchars($bus['bus_number']); ?></div>
            <div class="route-details">
                <i class="fas fa-clock"></i>
                <span>Departure: <?php echo date("h:i A", strtotime($bus['departure_time'])); ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-clock"></i>
                <span>Arrival: <?php echo date("h:i A", strtotime($bus['arrival_time'])); ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-chair"></i>
                <span>Seats: <?php echo $available_seats; ?>/<?php echo $bus['capacity']; ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-dollar-sign"></i>
                <span>Fare: $<?php echo number_format($route['fare'], 2); ?></span>
            </div>
            
            <?php if ($available_seats > 0): ?>
            <div style="margin-top: 15px;">
                <a href="booking.php?bus_id=<?php echo $bus['id']; ?>" class="btn btn-primary">Book Seat</a>
            </div>
            <?php else: ?>
            <div style="margin-top: 15px;">
                <button class="btn btn-danger" disabled>Fully Booked</button>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <p>No buses available for this route at the moment. Please check back later.</p>
        <a href="routes.php" class="btn btn-primary">Back to Routes</a>
    </div>
<?php endif; ?>

<?php
require_once '../includes/footer.php';
?>