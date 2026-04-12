<?php
/**
 * Routes page for University Bus Booking System
 * Displays all available bus routes
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Fetch all routes from database
$routes = $pdo->query("SELECT * FROM routes WHERE active = 1 ORDER BY name")->fetchAll();

require_once '../includes/header.php';
?>

<h1 class="page-title">Available Bus Routes</h1>

<div class="route-grid">
    <?php foreach ($routes as $route): ?>
    <a href="buses.php?route_id=<?php echo $route['id']; ?>" class="route-card-link">
        <div class="route-card">
            <div class="route-name"><?php echo htmlspecialchars($route['name']); ?></div>
            <div class="route-details">
                <i class="fas fa-map-marker-alt"></i>
                <span>From: <?php echo htmlspecialchars($route['start_point']); ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-flag-checkered"></i>
                <span>To: <?php echo htmlspecialchars($route['end_point']); ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-road"></i>
                <span>Distance: <?php echo htmlspecialchars($route['distance']); ?> km</span>
            </div>
            <div class="route-details">
                <i class="fas fa-clock"></i>
                <span>Estimated Time: <?php echo htmlspecialchars($route['estimated_time']); ?> minutes</span>
            </div>
            <div class="route-details">
                <i class="fas fa-dollar-sign"></i>
                <span>Fare: $<?php echo number_format($route['fare'], 2); ?></span>
            </div>
            <div class="route-details">
                <i class="fas fa-chevron-right"></i>
                <span>Click to view buses</span>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>

<?php
require_once '../includes/footer.php';
?>