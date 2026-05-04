<?php


require_once '../includes/config.php';


if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}


if (isAdmin($pdo, $_SESSION['id'])) {
    $_SESSION['info'] = "Booking functionality is for students only.";
    header("Location: admin.php");
    exit;
}

$user_id = $_SESSION['id'];


$user_info = getUserInfo($pdo, $user_id);
if ($user_info) {
    $_SESSION['student_id'] = $user_info['student_id'];
    $_SESSION['first_name'] = $user_info['first_name'];
    $_SESSION['last_name'] = $user_info['last_name'];
}

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
 
            $time_until = $departure_time - $current_time;
            $hours_until = floor($time_until / 3600);
            $minutes_until = floor(($time_until % 3600) / 60);
            
          
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

<div class="modal" id="ticketModal">
    <div class="modal-content">
        <span class="close-modal">&times;</span>
        <h2>Your Bus Ticket</h2>
        <div class="ticket-container">
            <div class="ticket-header">
                <i class="fas fa-bus"></i>
                <h3>Bracu Ticket</h3>
            </div>
            <div class="ticket-body">
                <div class="ticket-info">
                    <div class="ticket-field">
                        <span class="label">Ticket Number:</span>
                        <span class="value" id="ticketNumber"></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Passenger:</span>
                        <span class="value" id="passengerName">
                            <?php echo htmlspecialchars(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? '')); ?>
                        </span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Student ID:</span>
                        <span class="value" id="studentId"><?php echo htmlspecialchars($_SESSION['student_id'] ?? 'N/A'); ?></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Route:</span>
                        <span class="value" id="ticketRoute"></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Bus Number:</span>
                        <span class="value" id="ticketBus"></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Seat Number:</span>
                        <span class="value" id="ticketSeat"></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Departure:</span>
                        <span class="value" id="ticketDeparture"></span>
                    </div>
                    <div class="ticket-field">
                        <span class="label">Date:</span>
                        <span class="value" id="ticketDate"></span>
                    </div>
                </div>
                <div class="ticket-barcode">
                    <div id="barcode"></div>
                    <small>Ticket ID: <span id="ticketId"></span></small>
                </div>
            </div>
            <div class="ticket-footer">
                <p>Please show this ticket when boarding the bus</p>
                <button class="btn btn-primary" onclick="printTicket()">
                    <i class="fas fa-print"></i> Print Ticket
                </button>
            </div>
        </div>
    </div>
</div>

<script>

function showTicket(booking) {
    const studentId = '<?php echo $_SESSION['student_id'] ?? 'N/A'; ?>';
    const ticketNumber = 'BUS' + booking.bus_id + 'S' + booking.seat_number + 'ID' + studentId;
    
    const bookingDate = new Date(booking.booking_time);
    const departureTime = booking.departure_time;
    
    document.getElementById('ticketNumber').textContent = ticketNumber;
    document.getElementById('ticketRoute').textContent = booking.route_name;
    document.getElementById('ticketBus').textContent = booking.bus_number;
    document.getElementById('ticketSeat').textContent = 'Seat ' + booking.seat_number;
    document.getElementById('ticketDeparture').textContent = departureTime;
    document.getElementById('ticketDate').textContent = bookingDate.toLocaleDateString();
    document.getElementById('ticketId').textContent = 'BK' + booking.id.toString().padStart(6, '0');
    
    document.getElementById('barcode').innerHTML = '';
    for(let i = 0; i < 3; i++) {
        const line = document.createElement('div');
        line.style.height = '20px';
        line.style.backgroundColor = '#000';
        line.style.margin = '2px 0';
        line.style.width = Math.floor(Math.random() * 50 + 50) + '%';
        document.getElementById('barcode').appendChild(line);
    }
    
    document.getElementById('ticketModal').style.display = 'flex';
}

function printTicket() {
    const ticketContent = document.querySelector('.ticket-container').outerHTML;
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <!DOCTYPE html>
        <html>
        <head>
            <title>Print Ticket</title>
            <style>
                body { font-family: Arial, sans-serif; padding: 20px; }
                .ticket-container { border: 2px solid #000; padding: 20px; max-width: 400px; margin: 0 auto; border-radius: 10px; }
                .ticket-header { text-align: center; margin-bottom: 20px; }
                .ticket-header i { font-size: 2rem; color: #3498db; }
                .ticket-field { display: flex; justify-content: space-between; margin: 8px 0; }
                .label { font-weight: bold; }
                .ticket-barcode { text-align: center; margin: 20px 0; }
                .ticket-footer { text-align: center; margin-top: 20px; }
                @media print {
                    body { padding: 0; }
                    .ticket-container { border: none; box-shadow: none; }
                    button { display: none; }
                }
            </style>
        </head>
        <body>
            ${ticketContent}
        </body>
        </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
}

document.querySelector('.close-modal').addEventListener('click', function() {
    document.getElementById('ticketModal').style.display = 'none';
});

window.addEventListener('click', function(event) {
    if (event.target === document.getElementById('ticketModal')) {
        document.getElementById('ticketModal').style.display = 'none';
    }
});

function updateDepartureTimers() {
    const timers = document.querySelectorAll('.departure-timer');
    
    timers.forEach(timer => {
        const departureTimeStr = timer.getAttribute('data-departure');
        const [datePart, timePart] = departureTimeStr.split(' ');
        const [year, month, day] = datePart.split('-');
        const [hours, minutes, seconds] = timePart.split(':');
        
        const departureDate = new Date(year, month - 1, day, hours, minutes, seconds);
        const now = new Date();
        
        const timeDiff = departureDate - now;
        
        if (timeDiff <= 0) {
            timer.textContent = 'Departed';
            timer.classList.add('text-danger');
            
            const cancelBtn = timer.closest('.booking-card').querySelector('button[type="submit"]');
            if (cancelBtn) {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancel (Expired)';
                cancelBtn.classList.remove('btn-warning');
                cancelBtn.classList.add('btn-secondary');
            }
            return;
        }
        
        const hoursLeft = Math.floor(timeDiff / (1000 * 60 * 60));
        const minutesLeft = Math.floor((timeDiff % (1000 * 60 * 60)) / (1000 * 60));
        const secondsLeft = Math.floor((timeDiff % (1000 * 60)) / 1000);
        
        timer.textContent = `${hoursLeft}h ${minutesLeft}m ${secondsLeft}s`;
        
        if (hoursLeft < 1) {
            const refundElement = timer.closest('.booking-card').querySelector('.text-success');
            if (refundElement) {
                refundElement.textContent = 'No refund available';
                refundElement.classList.remove('text-success');
                refundElement.classList.add('text-danger');
            }
            
            const cancelBtn = timer.closest('.booking-card').query.querySelector('button[type="submit"]');
            if (cancelBtn && cancelBtn.textContent.includes('Refund')) {
                cancelBtn.disabled = true;
                cancelBtn.textContent = 'Cancel (No Refund)';
                cancelBtn.classList.remove('btn-warning');
                cancelBtn.classList.add('btn-danger');
            }
        }
    });
}

setInterval(updateDepartureTimers, 1000);
updateDepartureTimers();
</script>

<style>
.departure-timer { font-weight: bold; color: var(--primary); }
.text-success { color: var(--secondary) !important; font-weight: bold; }
.text-danger { color: var(--danger) !important; font-weight: bold; }
.booking-card .btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<?php
require_once '../includes/footer.php';
?>
