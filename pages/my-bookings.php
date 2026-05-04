<?php
/**
 * My Bookings page for University Bus Booking System
 * Displays user's booked seats and journey history
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Redirect admins away from my-bookings page
if (isAdmin($pdo, $_SESSION['id'])) {
    $_SESSION['info'] = "Booking functionality is for students only.";
    header("Location: admin.php");
    exit;
}

$user_id = $_SESSION['id'];

// Get user's full information from database
$user_info = getUserInfo($pdo, $user_id);
if ($user_info) {
    $_SESSION['student_id'] = $user_info['student_id'];
    $_SESSION['first_name'] = $user_info['first_name'];
    $_SESSION['last_name'] = $user_info['last_name'];
}

// Fetch user's bookings with route and bus details
$bookings_stmt = $pdo->prepare("
    SELECT b.*, 
           bus.bus_number, bus.departure_time, bus.arrival_time,
           rt.name as route_name, rt.start_point, rt.end_point, rt.fare
    FROM bookings b
    JOIN buses bus ON b.bus_id = bus.id
    JOIN routes rt ON bus.route_id = rt.id
    WHERE b.user_id = ?
    ORDER BY b.booking_time DESC
");
$bookings_stmt->execute([$user_id]);
$bookings = $bookings_stmt->fetchAll();

require_once '../includes/header.php';
?>

<h1 class="page-title">My Bookings</h1>

<?php if (count($bookings) > 0): ?>
    <div class="bookings-container">
        <?php foreach ($bookings as $booking): 
            $departure_time = strtotime($booking['departure_time']);
            $current_time = time();
            
            if ($current_time >= $departure_time && $booking['status'] === 'confirmed') {
                $status = 'completed';
            } else {
                $status = strtolower($booking['status']);
            }
    // Calculate time until departure
            $time_until = $departure_time - $current_time;
            $hours_until = floor($time_until / 3600);
            $minutes_until = floor(($time_until % 3600) / 60);
            
            // Check if cancellation is allowed (more than 1 hour before departure)
            $can_cancel = $time_until > 3600 && ($booking['status'] == 'pending' || $booking['status'] == 'confirmed');
            $refund_percentage = $can_cancel ? 100 : 0;
        ?>
        
        <div class="card booking-card">
            <div class="booking-header">
                <h3><?php echo htmlspecialchars($booking['route_name']); ?></h3>
                <span class="booking-status <?php echo $status; ?>">
                    <?php echo ucfirst($status); ?>
                </span>
            </div>
            
            <div class="booking-details">
                <div class="detail-item">
                    <i class="fas fa-bus"></i>
                    <span>Bus: <?php echo htmlspecialchars($booking['bus_number']); ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-chair"></i>
                    <span>Seat: <?php echo $booking['seat_number']; ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-calendar"></i>
                    <span>Booked on: <?php echo date("M j, Y g:i A", strtotime($booking['booking_time'])); ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-clock"></i>
                    <span>Departure: <?php echo date("g:i A", strtotime($booking['departure_time'])); ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-map-marker-alt"></i>
                    <span>From: <?php echo htmlspecialchars($booking['start_point']); ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-flag-checkered"></i>
                    <span>To: <?php echo htmlspecialchars($booking['end_point']); ?></span>
                </div>
                
                <div class="detail-item">
                    <i class="fas fa-dollar-sign"></i>
                    <span>Fare: $<?php echo number_format($booking['fare'], 2); ?></span>
                </div>
                
                <?php if ($booking['status'] == 'pending' || $booking['status'] == 'confirmed'): ?>
                <div class="detail-item">
                    <i class="fas fa-hourglass-half"></i>
                    <span>Departure in: 
                        <span class="departure-timer" data-departure="<?php echo $booking['departure_time']; ?>">
                            <?php echo $hours_until . 'h ' . $minutes_until . 'm'; ?>
                        </span>
                    </span>
                </div>
                
                <?php if ($can_cancel): ?>
                <div class="detail-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span class="text-success">Refund: <?php echo $refund_percentage; ?>% ($<?php echo number_format($booking['fare'], 2); ?>)</span>
                </div>
                <?php elseif ($booking['status'] == 'pending' || $booking['status'] == 'confirmed'): ?>
                <div class="detail-item">
                    <i class="fas fa-money-bill-wave"></i>
                    <span class="text-danger">No refund available</span>
                </div>
                <?php endif; ?>
                <?php endif; ?>
            </div>
            
            <?php if ($booking['status'] == 'pending' || $booking['status'] == 'confirmed'): ?>
                <div class="booking-actions">
                    <?php if ($current_time >= $departure_time && $booking['status'] === 'confirmed'): ?>
                        <button class="btn btn-secondary" disabled>
                            <i class="fas fa-check"></i> Completed
                        </button>
                    <?php else: ?>
                        <?php if ($can_cancel): ?>
                            <form action="cancel-booking.php" method="post" style="display: inline;">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                <input type="hidden" name="refund_percentage" value="<?php echo $refund_percentage; ?>">
                                <button type="submit" class="btn btn-warning"
                                    onclick="return confirm('Are you sure you want to cancel this booking? You will receive a <?php echo $refund_percentage; ?>% refund ($<?php echo number_format($booking['fare'] * ($refund_percentage / 100), 2); ?>).')">
                                    <i class="fas fa-times"></i> Cancel with Refund
                                </button>
                            </form>
                        <?php else: ?>
                            <button class="btn btn-danger" disabled title="Cannot cancel within 1 hour of departure">
                                <i class="fas fa-times"></i> Cancel (No Refund)
                            </button>
                        <?php endif; ?>

                        <?php if ($booking['status'] == 'pending'): ?>
                            <form action="confirm-booking.php" method="post" style="display: inline;">
                                <input type="hidden" name="booking_id" value="<?php echo $booking['id']; ?>">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-check"></i> Confirm Now
                                </button>
                            </form>
                        <?php elseif ($booking['status'] == 'confirmed'): ?>
                            <button class="btn btn-primary view-ticket-btn" 
                                    onclick="showTicket(<?php echo htmlspecialchars(json_encode($booking), ENT_QUOTES, 'UTF-8'); ?>)">
                                <i class="fas fa-ticket-alt"></i> View Ticket
                            </button>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
<?php else: ?>
    <div class="card">
        <div class="no-bookings">
            <i class="fas fa-ticket-alt fa-4x" style="color: #ddd; margin-bottom: 20px;"></i>
            <h3>No bookings yet</h3>
            <p>You haven't made any bookings yet. Start by exploring our available routes.</p>
            <a href="routes.php" class="btn btn-primary">View Routes</a>
        </div>
    </div>
<?php endif; ?>
