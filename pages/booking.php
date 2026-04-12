<?php
/**
 * Booking page for University Bus Booking System
 * Handles seat selection and booking
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

// Get bus ID from query parameter
$bus_id = isset($_GET['bus_id']) ? (int)$_GET['bus_id'] : 0;

// Fetch bus details
$bus_stmt = $pdo->prepare("
    SELECT b.*, r.name as route_name, r.fare, r.start_point, r.end_point 
    FROM buses b 
    JOIN routes r ON b.route_id = r.id 
    WHERE b.id = ?
");
$bus_stmt->execute([$bus_id]);
$bus = $bus_stmt->fetch();

if (!$bus) {
    $_SESSION['error'] = "Invalid bus selected";
    header("Location: routes.php");
    exit();
}

// Check if user has sufficient balance
$user_id = $_SESSION['id'];
$wallet_stmt = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
$wallet_stmt->execute([$user_id]);
$wallet = $wallet_stmt->fetch();
$balance = $wallet ? $wallet['balance'] : 0;

// Check if user can afford this trip
if ($balance < $bus['fare']) {
    $_SESSION['error'] = "Insufficient balance. Please add funds to your wallet.";
    header("Location: wallet.php");
    exit();
}

// Get booked seats
$booked_stmt = $pdo->prepare("SELECT seat_number FROM bookings WHERE bus_id = ? AND status IN ('confirmed', 'pending')");
$booked_stmt->execute([$bus_id]);
$booked_seats = $booked_stmt->fetchAll(PDO::FETCH_COLUMN, 0);

// Process seat selection
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['seat_number'])) {
    $seat_number = (int)$_POST['seat_number'];
    
    // Check if seat is available
    if (in_array($seat_number, $booked_seats)) {
        $_SESSION['error'] = "Seat already booked. Please select another seat.";
        header("Location: booking.php?bus_id=" . $bus_id);
        exit();
    }
    
    // Start transaction
    $pdo->beginTransaction();
    
    try {
        // Create booking
        $booking_stmt = $pdo->prepare("
            INSERT INTO bookings (user_id, bus_id, seat_number, status, points_earned) 
            VALUES (?, ?, ?, 'confirmed', 5)
        ");
        $booking_stmt->execute([$user_id, $bus_id, $seat_number]);
        $booking_id = $pdo->lastInsertId();
        
        // Deduct from wallet
        $wallet_update = $pdo->prepare("UPDATE wallet SET balance = balance - ? WHERE user_id = ?");
        $wallet_update->execute([$bus['fare'], $user_id]);
        
        // Record transaction
        $trans_stmt = $pdo->prepare("
            INSERT INTO transactions (user_id, amount, type, description) 
            VALUES (?, ?, 'debit', 'Bus booking')
        ");
        $trans_stmt->execute([$user_id, $bus['fare']]);
        
        // Award points
        $points_stmt = $pdo->prepare("UPDATE users SET total_points = total_points + 5 WHERE id = ?");
        $points_stmt->execute([$user_id]);
        
        $pdo->commit();
        
        $_SESSION['success'] = "Seat booked successfully! You earned 5 points!";
        header("Location: my-bookings.php");
        exit();
        
    } catch (Exception $e) {
        $pdo->rollBack();
        $_SESSION['error'] = "Booking failed: " . $e->getMessage();
        header("Location: booking.php?bus_id=" . $bus_id);
        exit();
    }
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Select a Seat - <?php echo htmlspecialchars($bus['route_name']); ?></h1>

<div class="card">
    <div class="route-details">
        <strong>Bus Number:</strong> <?php echo htmlspecialchars($bus['bus_number']); ?>
    </div>
    <div class="route-details">
        <strong>Departure:</strong> <?php echo date("h:i A", strtotime($bus['departure_time'])); ?>
    </div>
    <div class="route-details">
        <strong>Arrival:</strong> <?php echo date("h:i A", strtotime($bus['arrival_time'])); ?>
    </div>
    <div class="route-details">
        <strong>Fare:</strong> $<?php echo number_format($bus['fare'], 2); ?>
    </div>
    <div class="route-details">
        <strong>Your balance:</strong> $<?php echo number_format($balance, 2); ?>
    </div>
</div>

<div class="card">
    <h3>Select Your Seat</h3>
    <p>Click on an available seat to select it.</p>
    
    <div class="bus-layout">
        <div class="driver-cabin">
            <i class="fas fa-steering-wheel"></i> Driver's Cabin
        </div>
        
        <?php for ($i = 1; $i <= $bus['capacity']; $i++): 
            $seat_class = in_array($i, $booked_seats) ? 'occupied' : 'available';
        ?>
            <div class="seat <?php echo $seat_class; ?> <?php echo $seat_class == 'available' ? 'seat-selectable' : ''; ?>" 
                 data-seat="<?php echo $i; ?>">
                <?php echo $i; ?>
            </div>
        <?php endfor; ?>
    </div>
    
    <form id="bookingForm" method="post">
        <input type="hidden" name="seat_number" id="selectedSeat">
        <button type="submit" class="btn btn-primary btn-block" id="confirmBtn" disabled>Confirm Booking</button>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const seats = document.querySelectorAll('.seat-selectable');
    const selectedSeatInput = document.getElementById('selectedSeat');
    const confirmBtn = document.getElementById('confirmBtn');
    
    seats.forEach(seat => {
        seat.addEventListener('click', function() {
            seats.forEach(s => s.classList.remove('selected'));
            this.classList.add('selected');
            selectedSeatInput.value = this.getAttribute('data-seat');
            confirmBtn.disabled = false;
        });
    });
});
</script>

<style>
.seat.available { background: var(--secondary); color: white; cursor: pointer; }
.seat.occupied { background: var(--danger); color: white; cursor: not-allowed; }
.seat.selected { background: var(--warning); color: white; transform: scale(1.1); }
.bus-layout { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; margin: 20px 0; }
.seat { width: 50px; height: 50px; display: flex; align-items: center; justify-content: center; border-radius: 4px; transition: all 0.3s; }
.driver-cabin { grid-column: 1 / -1; text-align: center; padding: 10px; background: var(--dark); color: white; border-radius: 4px; }
</style>

<?php
require_once '../includes/footer.php';
?>