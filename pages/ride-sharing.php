<?php
/**
 * Enhanced Ride Sharing System
 * Features: Group rides, fare splitting, real-time chat, ratings
 */

require_once '../includes/config.php';
require_once '../includes/ride_sharing_schema.php';
ensure_ride_sharing_schema($pdo);

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];

// Handle creating a new ride share
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    
    // Create new ride share
    if ($_POST['action'] == 'create_ride') {
        $from_location = $_POST['from_location'];
        $to_location = $_POST['to_location'];
        $departure_time = $_POST['departure_time'];
        $vehicle_type = $_POST['vehicle_type'];
        $total_seats = (int)$_POST['total_seats'];
        $fare_per_person = (float)$_POST['fare_per_person'];
        $estimated_duration = (int)$_POST['estimated_duration'];
        $flexible_time = isset($_POST['flexible_time']) ? 1 : 0;
        $allow_smoking = isset($_POST['allow_smoking']) ? 1 : 0;
        $allow_pets = isset($_POST['allow_pets']) ? 1 : 0;
        $allow_luggage = isset($_POST['allow_luggage']) ? 1 : 0;
        $music_preference = $_POST['music_preference'];
        $description = $_POST['description'];
        
        $pdo->beginTransaction();
        
        try {
            // Insert ride share
            $stmt = $pdo->prepare("
                INSERT INTO ride_shares (
                    creator_id, from_location, to_location, departure_time,
                    vehicle_type, total_seats, available_seats, fare_per_person,
                    estimated_duration, flexible_time, allow_smoking, allow_pets,
                    allow_luggage, music_preference, description, status
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active'
                )
            ");
            $stmt->execute([
                $user_id, $from_location, $to_location, $departure_time,
                $vehicle_type, $total_seats, $total_seats, $fare_per_person,
                $estimated_duration, $flexible_time, $allow_smoking, $allow_pets,
                $allow_luggage, $music_preference, $description
            ]);
            
            $ride_id = $pdo->lastInsertId();
            
            // Add creator as participant
            $stmt = $pdo->prepare("
                INSERT INTO ride_share_participants (ride_share_id, user_id, seats_booked, status, payment_status)
                VALUES (?, ?, 1, 'confirmed', 'pending')
            ");
            $stmt->execute([$ride_id, $user_id]);
            
            // Initialize fare split
            $stmt = $pdo->prepare("
                INSERT INTO fare_splits (ride_share_id, user_id, amount_owed)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$ride_id, $user_id, $fare_per_person]);
            
            // Add system message to chat
            $stmt = $pdo->prepare("
                INSERT INTO chat_messages (ride_share_id, user_id, message, message_type, is_system)
                VALUES (?, ?, 'Ride created!', 'system', 1)
            ");
            $stmt->execute([$ride_id, $user_id]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Ride share created successfully!";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to create ride: " . $e->getMessage();
            header("Location: ride-sharing.php");
            exit;
        }
    }
    
    // Join a ride
    if ($_POST['action'] == 'join_ride') {
        $ride_id = (int)$_POST['ride_id'];
        $seats = (int)$_POST['seats'];
        
        // Get ride details
        $ride = $pdo->prepare("SELECT * FROM ride_shares WHERE id = ? AND status = 'active'");
        $ride->execute([$ride_id]);
        $ride_data = $ride->fetch();
        
        if (!$ride_data) {
            $_SESSION['error'] = "Ride not available";
            header("Location: ride-sharing.php");
            exit;
        }
        
        if ($ride_data['available_seats'] < $seats) {
            $_SESSION['error'] = "Not enough seats available";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
        }
        
        // Check if already joined
        $check = $pdo->prepare("SELECT id FROM ride_share_participants WHERE ride_share_id = ? AND user_id = ?");
        $check->execute([$ride_id, $user_id]);
        
        if ($check->fetch()) {
            $_SESSION['error'] = "You've already joined this ride";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Add participant
            $stmt = $pdo->prepare("
                INSERT INTO ride_share_participants (ride_share_id, user_id, seats_booked, status)
                VALUES (?, ?, ?, 'confirmed')
            ");
            $stmt->execute([$ride_id, $user_id, $seats]);
            
            // Update available seats
            $stmt = $pdo->prepare("UPDATE ride_shares SET available_seats = available_seats - ? WHERE id = ?");
            $stmt->execute([$seats, $ride_id]);
            
            // Add fare split
            $amount_owed = $seats * $ride_data['fare_per_person'];
            $stmt = $pdo->prepare("
                INSERT INTO fare_splits (ride_share_id, user_id, amount_owed)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$ride_id, $user_id, $amount_owed]);
            
            // Add chat message
            $user = getUserInfo($pdo, $user_id);
            $stmt = $pdo->prepare("
                INSERT INTO chat_messages (ride_share_id, user_id, message, message_type, is_system)
                VALUES (?, ?, ?, 'system', 1)
            ");
            $stmt->execute([$ride_id, $user_id, $user['first_name'] . " joined the ride!"]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "You've joined the ride!";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to join ride: " . $e->getMessage();
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
        }
    }
    
    // Send chat message
    if ($_POST['action'] == 'send_message') {
        $ride_id = (int)$_POST['ride_id'];
        $message = trim($_POST['message']);
        
        if (!empty($message)) {
            $stmt = $pdo->prepare("
                INSERT INTO chat_messages (ride_share_id, user_id, message, message_type)
                VALUES (?, ?, ?, 'text')
            ");
            $stmt->execute([$ride_id, $user_id, $message]);
        }
        
        header("Location: ride-sharing.php?view=" . $ride_id . "#chat");
        exit;
    }
    
    // Make payment
    if ($_POST['action'] == 'make_payment') {
        $ride_id = (int)$_POST['ride_id'];
        $amount = (float)$_POST['amount'];
        
        // Check wallet balance
        $wallet = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
        $wallet->execute([$user_id]);
        $balance = $wallet->fetchColumn();
        
        if ($balance < $amount) {
            $_SESSION['error'] = "Insufficient balance";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
        }
        
        $pdo->beginTransaction();
        
        try {
            // Update fare split
            $stmt = $pdo->prepare("
                UPDATE fare_splits SET 
                    amount_paid = ?, 
                    payment_status = 'paid',
                    paid_at = CURRENT_TIMESTAMP,
                    payment_method = 'wallet'
                WHERE ride_share_id = ? AND user_id = ?
            ");
            $stmt->execute([$amount, $ride_id, $user_id]);
            
            // Deduct from wallet
            $stmt = $pdo->prepare("UPDATE wallet SET balance = balance - ? WHERE user_id = ?");
            $stmt->execute([$amount, $user_id]);
            
            // Record transaction
            $stmt = $pdo->prepare("
                INSERT INTO transactions (user_id, amount, type, description)
                VALUES (?, ?, 'debit', 'Ride share payment')
            ");
            $stmt->execute([$user_id, $amount]);
            
            // Award points
            awardPoints($pdo, $user_id, 5, 'ride_share_payment', 'Ride share wallet payment', $ride_id);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Payment successful! You earned 5 points!";
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Payment failed: " . $e->getMessage();
            header("Location: ride-sharing.php?view=" . $ride_id);
            exit;
        }
    }
    
    // Rate a rider
    if ($_POST['action'] == 'submit_rating') {
        $ride_id = (int)$_POST['ride_id'];
        $ratee_id = (int)$_POST['ratee_id'];
        $rating = (int)$_POST['rating'];
        $review = $_POST['review'];
        
        // Check if already rated
        $check = $pdo->prepare("
            SELECT id FROM ride_ratings 
            WHERE ride_share_id = ? AND rater_id = ? AND ratee_id = ?
        ");
        $check->execute([$ride_id, $user_id, $ratee_id]);
        
        if (!$check->fetch()) {
            $stmt = $pdo->prepare("
                INSERT INTO ride_ratings (ride_share_id, rater_id, ratee_id, rating, review)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$ride_id, $user_id, $ratee_id, $rating, $review]);
            
            $_SESSION['success'] = "Thank you for rating!";
        }
        
        header("Location: ride-sharing.php?view=" . $ride_id);
        exit;
    }
}

// Get single ride view if specified
$view_ride = isset($_GET['view']) ? (int)$_GET['view'] : 0;

if ($view_ride > 0) {
    // Get ride details
    $ride = $pdo->prepare("
        SELECT rs.*, u.first_name, u.last_name, u.id as creator_user_id,
               (SELECT COUNT(*) FROM ride_share_participants WHERE ride_share_id = rs.id) as participant_count
        FROM ride_shares rs
        JOIN users u ON rs.creator_id = u.id
        WHERE rs.id = ?
    ");
    $ride->execute([$view_ride]);
    $ride_data = $ride->fetch();
    
    if (!$ride_data) {
        $_SESSION['error'] = "Ride not found";
        header("Location: ride-sharing.php");
        exit;
    }
    
    // Get participants - FIXED
    $participants = $pdo->prepare("
        SELECT rsp.*, u.first_name, u.last_name, u.id as user_id,
               COALESCE(fs.amount_owed, 0) as amount_owed,
               COALESCE(fs.amount_paid, 0) as amount_paid,
               COALESCE(fs.payment_status, 'pending') as payment_status
        FROM ride_share_participants rsp
        JOIN users u ON rsp.user_id = u.id
        LEFT JOIN fare_splits fs ON rsp.ride_share_id = fs.ride_share_id AND rsp.user_id = fs.user_id
        WHERE rsp.ride_share_id = ?
    ");
    $participants->execute([$view_ride]);
    $participants_data = $participants->fetchAll();
    
    // Get chat messages - FIXED
    $messages = $pdo->prepare("
        SELECT cm.*, u.first_name, u.last_name
        FROM chat_messages cm
        JOIN users u ON cm.user_id = u.id
        WHERE cm.ride_share_id = ?
        ORDER BY cm.id ASC
    ");
    $messages->execute([$view_ride]);
    $chat_messages = $messages->fetchAll();
    
    // Get ratings
    $ratings = $pdo->prepare("
        SELECT rr.*, u.first_name, u.last_name
        FROM ride_ratings rr
        JOIN users u ON rr.rater_id = u.id
        WHERE rr.ride_share_id = ?
    ");
    $ratings->execute([$view_ride]);
    $ride_ratings = $ratings->fetchAll();
    
    // Check if current user is participant
    $is_participant = false;
    $user_participant = null;
    foreach ($participants_data as $p) {
        if ($p['user_id'] == $user_id) {
            $is_participant = true;
            $user_participant = $p;
            break;
        }
    }
    
    $is_creator = ((int) $ride_data['creator_id'] === (int) $user_id);
    $can_join_ride = !$is_participant
        && isset($ride_data['status']) && $ride_data['status'] === 'active'
        && (int) $ride_data['available_seats'] > 0
        && strtotime($ride_data['departure_time']) > time();
    
    require_once '../includes/header.php';
    ?>
    
    <style>
    .ride-detail-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 15px;
        margin-bottom: 30px;
        position: relative;
        overflow: hidden;
    }
    
    .ride-detail-header::after {
        content: '';
        position: absolute;
        top: -50%;
        right: -50%;
        width: 200%;
        height: 200%;
        background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255,255,255,0.1) 20px, rgba(255,255,255,0.1) 40px);
        animation: moveStripes 20s linear infinite;
    }
    
    @keyframes moveStripes {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
    
    .chat-container {
        height: 400px;
        overflow-y: auto;
        background: #f8f9fa;
        border-radius: 10px;
        padding: 20px;
        margin-bottom: 20px;
        display: flex;
        flex-direction: column;
    }
    
    .chat-message {
        max-width: 70%;
        margin-bottom: 15px;
        padding: 10px 15px;
        border-radius: 15px;
        position: relative;
        animation: fadeIn 0.3s;
    }
    
    .chat-message.sent {
        align-self: flex-end;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        border-bottom-right-radius: 5px;
    }
    
    .chat-message.received {
        align-self: flex-start;
        background: white;
        border: 1px solid #e0e0e0;
        border-bottom-left-radius: 5px;
    }
    
    .chat-message.system {
        align-self: center;
        background: #ffd700;
        color: #333;
        font-style: italic;
        max-width: 90%;
        text-align: center;
    }
    
    .chat-message .sender {
        font-size: 0.8rem;
        font-weight: bold;
        margin-bottom: 3px;
    }
    
    .chat-message .time {
        font-size: 0.7rem;
        opacity: 0.7;
        margin-top: 3px;
        text-align: right;
    }
    
    @keyframes fadeIn {
        from { opacity: 0; transform: translateY(10px); }
        to { opacity: 1; transform: translateY(0); }
    }
    
    .participant-card {
        background: white;
        border-radius: 10px;
        padding: 15px;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 15px;
        border-left: 4px solid #ddd;
        transition: all 0.3s;
    }
    
    .rs-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 14px;
        border-radius: 999px;
        font-size: 0.9rem;
        font-weight: 600;
    }
    .rs-status-pill.rs-booked {
        background: rgba(46, 204, 113, 0.95);
        color: #fff;
    }
    .rs-status-pill.rs-hosting {
        background: rgba(241, 196, 15, 0.95);
        color: #333;
    }
    
    .participant-card.creator {
        border-left-color: gold;
        background: linear-gradient(135deg, #fff9e6, #fff);
    }
    
    .participant-card.paid {
        border-left-color: #4CAF50;
    }
    
    .participant-avatar {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea, #764ba2);
        color: white;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
        font-weight: bold;
    }
    
    .payment-status {
        padding: 3px 10px;
        border-radius: 20px;
        font-size: 0.8rem;
        font-weight: bold;
    }
    
    .payment-status.paid {
        background: #4CAF50;
        color: white;
    }
    
    .payment-status.pending {
        background: #ff9800;
        color: white;
    }
    
    .rating-stars {
        color: #FFD700;
        font-size: 1.2rem;
    }
    
    .rating-stars i {
        cursor: pointer;
        transition: all 0.2s;
    }
    
    .rating-stars i:hover {
        transform: scale(1.2);
    }
    
    .preference-tag {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        font-size: 0.8rem;
        margin: 3px;
        background: #f0f0f0;
    }
    
    .preference-tag.allowed {
        background: #4CAF50;
        color: white;
    }
    
    .preference-tag.not-allowed {
        background: #f44336;
        color: white;
    }
    
    .live-indicator {
        display: inline-block;
        padding: 5px 12px;
        border-radius: 20px;
        background: #f44336;
        color: white;
        animation: pulse 2s infinite;
    }
    
    @keyframes pulse {
        0% { opacity: 1; transform: scale(1); }
        50% { opacity: 0.7; transform: scale(1.05); }
        100% { opacity: 1; transform: scale(1); }
    }
    </style>
    
    <div class="container">
        <a href="ride-sharing.php" class="btn btn-secondary" style="margin-bottom: 20px;">
            <i class="fas fa-arrow-left"></i> Back to All Rides
        </a>
        
        <!-- Ride Header -->
        <div class="ride-detail-header">
            <div style="position: relative; z-index: 1;">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap;">
                    <div>
                        <h1 style="margin: 0; color: white;">
                            <i class="fas fa-route"></i> 
                            <?php echo htmlspecialchars($ride_data['from_location']); ?> → 
                            <?php echo htmlspecialchars($ride_data['to_location']); ?>
                        </h1>
                        <p style="margin: 10px 0 0 0; opacity: 0.9;">
                            <i class="fas fa-user"></i> Created by <?php echo htmlspecialchars($ride_data['first_name'] . ' ' . $ride_data['last_name']); ?>
                            <?php if ($ride_data['creator_id'] == $user_id): ?>
                                <span style="background: gold; color: #333; padding: 2px 8px; border-radius: 12px; margin-left: 10px;">You</span>
                            <?php endif; ?>
                        </p>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px; flex-wrap: wrap;">
                        <?php if ($is_participant): ?>
                            <?php if ($is_creator): ?>
                                <span class="rs-status-pill rs-hosting"><i class="fas fa-crown"></i> Your ride</span>
                            <?php else: ?>
                                <span class="rs-status-pill rs-booked"><i class="fas fa-check-circle"></i> Booked</span>
                            <?php endif; ?>
                        <?php endif; ?>
                        <span class="live-indicator">
                            <i class="fas fa-circle"></i> LIVE
                        </span>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="row" style="display: grid; grid-template-columns: 2fr 1fr; gap: 20px;">
            <!-- Left Column - Chat & Details -->
            <div>
                <!-- Chat Section -->
                <div class="card">
                    <h3><i class="fas fa-comments"></i> Group Chat</h3>
                    
                    <div class="chat-container" id="chat">
                        <?php foreach ($chat_messages as $msg): ?>
                            <?php if ($msg['is_system']): ?>
                                <div class="chat-message system">
                                    <i class="fas fa-info-circle"></i> <?php echo htmlspecialchars($msg['message']); ?>
                                    <div class="time"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></div>
                                </div>
                            <?php elseif ($msg['user_id'] == $user_id): ?>
                                <div class="chat-message sent">
                                    <div class="sender">You</div>
                                    <div><?php echo htmlspecialchars($msg['message']); ?></div>
                                    <div class="time"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></div>
                                </div>
                            <?php else: ?>
                                <div class="chat-message received">
                                    <div class="sender"><?php echo htmlspecialchars($msg['first_name']); ?></div>
                                    <div><?php echo htmlspecialchars($msg['message']); ?></div>
                                    <div class="time"><?php echo date('h:i A', strtotime($msg['created_at'])); ?></div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                    
                    <?php if ($is_participant): ?>
                    <form method="post" style="display: flex; gap: 10px;">
                        <input type="hidden" name="action" value="send_message">
                        <input type="hidden" name="ride_id" value="<?php echo $view_ride; ?>">
                        <input type="text" name="message" class="form-control" placeholder="Type your message..." required>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-paper-plane"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                
                <!-- Ride Details -->
                <div class="card">
                    <h3><i class="fas fa-info-circle"></i> Ride Details</h3>
                    
                    <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 15px;">
                        <div>
                            <strong>Departure:</strong><br>
                            <i class="fas fa-clock"></i> <?php echo date('M j, Y g:i A', strtotime($ride_data['departure_time'])); ?>
                        </div>
                        <div>
                            <strong>Duration:</strong><br>
                            <i class="fas fa-hourglass-half"></i> <?php echo isset($ride_data['estimated_duration']) ? $ride_data['estimated_duration'] : '30'; ?> minutes
                        </div>
                        <div>
                            <strong>Vehicle:</strong><br>
                            <i class="fas fa-car"></i> <?php echo ucfirst($ride_data['vehicle_type']); ?>
                        </div>
                        <div>
                            <strong>Seats:</strong><br>
                            <i class="fas fa-chair"></i> <?php echo $ride_data['available_seats']; ?>/<?php echo $ride_data['total_seats']; ?> available
                        </div>
                        <div>
                            <strong>Fare per seat:</strong><br>
                            <i class="fas fa-dollar-sign"></i> $<?php echo number_format($ride_data['fare_per_person'], 2); ?>
                        </div>
                        <div>
                            <strong>Total fare:</strong><br>
                            <i class="fas fa-calculator"></i> $<?php echo number_format($ride_data['fare_per_person'] * $ride_data['participant_count'], 2); ?>
                        </div>
                    </div>
                    
                    <?php if (!empty($ride_data['description'])): ?>
                        <div style="margin-top: 15px; padding: 10px; background: #f8f9fa; border-radius: 5px;">
                            <strong>Description:</strong><br>
                            <?php echo nl2br(htmlspecialchars($ride_data['description'])); ?>
                        </div>
                    <?php endif; ?>
                    
                    <div style="margin-top: 15px;">
                        <strong>Preferences:</strong><br>
                        <span class="preference-tag <?php echo (isset($ride_data['flexible_time']) && $ride_data['flexible_time']) ? 'allowed' : 'not-allowed'; ?>">
                            <i class="fas fa-clock"></i> Flexible Time
                        </span>
                        <span class="preference-tag <?php echo (isset($ride_data['allow_smoking']) && $ride_data['allow_smoking']) ? 'allowed' : 'not-allowed'; ?>">
                            <i class="fas fa-smoking"></i> Smoking
                        </span>
                        <span class="preference-tag <?php echo (isset($ride_data['allow_pets']) && $ride_data['allow_pets']) ? 'allowed' : 'not-allowed'; ?>">
                            <i class="fas fa-dog"></i> Pets
                        </span>
                        <span class="preference-tag <?php echo (isset($ride_data['allow_luggage']) && $ride_data['allow_luggage']) ? 'allowed' : 'not-allowed'; ?>">
                            <i class="fas fa-suitcase"></i> Luggage
                        </span>
                        <span class="preference-tag">
                            <i class="fas fa-music"></i> 
                            <?php 
                            $music = isset($ride_data['music_preference']) ? $ride_data['music_preference'] : 'any';
                            echo ucfirst($music); 
                            ?>
                        </span>
                    </div>
                </div>
            </div>
            
            <!-- Right Column - Participants & Payments -->
            <div>
                <!-- Participants -->
                <div class="card">
                    <h3><i class="fas fa-users"></i> Participants (<?php echo count($participants_data); ?>)</h3>
                    
                    <?php foreach ($participants_data as $p): ?>
                        <div class="participant-card <?php 
                            echo $p['user_id'] == $ride_data['creator_id'] ? 'creator' : '';
                            echo (isset($p['payment_status']) && $p['payment_status'] == 'paid') ? ' paid' : '';
                        ?>">
                            <div class="participant-avatar">
                                <?php echo strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)); ?>
                            </div>
                            <div style="flex: 1;">
                                <div style="display: flex; justify-content: space-between;">
                                    <strong>
                                        <?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?>
                                        <?php if ($p['user_id'] == $ride_data['creator_id']): ?>
                                            <span style="color: gold;">👑</span>
                                        <?php endif; ?>
                                    </strong>
                                    <span class="payment-status <?php echo isset($p['payment_status']) ? $p['payment_status'] : 'pending'; ?>">
                                        <?php echo isset($p['payment_status']) ? ucfirst($p['payment_status']) : 'Pending'; ?>
                                    </span>
                                </div>
                                <div style="font-size: 0.9rem; color: #666;">
                                    <i class="fas fa-chair"></i> <?php echo $p['seats_booked']; ?> seat(s) · 
                                    <i class="fas fa-dollar-sign"></i> $<?php echo number_format(isset($p['amount_owed']) ? $p['amount_owed'] : 0, 2); ?>
                                    <?php if (isset($p['amount_paid']) && $p['amount_paid'] > 0): ?>
                                        · Paid: $<?php echo number_format($p['amount_paid'], 2); ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if ($can_join_ride): ?>
                <div class="card" style="border: 2px solid #667eea;">
                    <h3><i class="fas fa-ticket-alt"></i> Book a seat</h3>
                    <p style="color: #555; margin-bottom: 12px;">Join this ride and split the fare with the group.</p>
                    <form method="post" style="display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;">
                        <input type="hidden" name="action" value="join_ride">
                        <input type="hidden" name="ride_id" value="<?php echo (int) $view_ride; ?>">
                        <div>
                            <label for="join_seats" style="display: block; font-size: 0.85rem; color: #666; margin-bottom: 4px;">Seats</label>
                            <select name="seats" id="join_seats" class="form-control" style="min-width: 100px;">
                                <?php for ($s = 1; $s <= min(8, (int) $ride_data['available_seats']); $s++): ?>
                                <option value="<?php echo $s; ?>"><?php echo $s; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-primary" style="padding: 10px 20px;">
                            <i class="fas fa-check"></i> Book seats
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                
                <!-- Payment Section (if user is participant and hasn't paid) -->
                <?php if ($is_participant && $user_participant && (!isset($user_participant['payment_status']) || $user_participant['payment_status'] != 'paid')): ?>
                <div class="card">
                    <h3><i class="fas fa-credit-card"></i> Payment</h3>
                    
                    <div style="text-align: center; padding: 20px; background: linear-gradient(135deg, #f6f9fc, #e9f2f9); border-radius: 10px;">
                        <div style="font-size: 2rem; font-weight: bold; color: var(--uber-green);">
                            $<?php echo number_format(isset($user_participant['amount_owed']) ? $user_participant['amount_owed'] : 0, 2); ?>
                        </div>
                        <p>Due for <?php echo $user_participant['seats_booked']; ?> seat(s)</p>
                        
                        <form method="post" onsubmit="return confirm('Confirm payment of $<?php echo number_format(isset($user_participant['amount_owed']) ? $user_participant['amount_owed'] : 0, 2); ?> from your wallet?');">
                            <input type="hidden" name="action" value="make_payment">
                            <input type="hidden" name="ride_id" value="<?php echo $view_ride; ?>">
                            <input type="hidden" name="amount" value="<?php echo isset($user_participant['amount_owed']) ? $user_participant['amount_owed'] : 0; ?>">
                            <button type="submit" class="btn btn-success btn-block">
                                <i class="fas fa-check-circle"></i> Pay with Wallet
                            </button>
                        </form>
                        
                        <p style="margin-top: 10px; font-size: 0.9rem; color: #666;">
                            <i class="fas fa-info-circle"></i> You'll earn 5 points!
                        </p>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Rating Section (for completed rides) -->
                <?php if (isset($ride_data['status']) && $ride_data['status'] == 'completed' && $is_participant): ?>
                <div class="card">
                    <h3><i class="fas fa-star"></i> Rate Participants</h3>
                    
                    <?php foreach ($participants_data as $p): ?>
                        <?php if ($p['user_id'] != $user_id): 
                            $already_rated = false;
                            foreach ($ride_ratings as $r) {
                                if ($r['rater_id'] == $user_id && $r['ratee_id'] == $p['user_id']) {
                                    $already_rated = true;
                                    break;
                                }
                            }
                        ?>
                            <?php if (!$already_rated): ?>
                            <div style="padding: 15px; border-bottom: 1px solid #eee;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <div class="participant-avatar" style="width: 40px; height: 40px; font-size: 1rem;">
                                        <?php echo strtoupper(substr($p['first_name'], 0, 1) . substr($p['last_name'], 0, 1)); ?>
                                    </div>
                                    <strong><?php echo htmlspecialchars($p['first_name'] . ' ' . $p['last_name']); ?></strong>
                                </div>
                                
                                <form method="post">
                                    <input type="hidden" name="action" value="submit_rating">
                                    <input type="hidden" name="ride_id" value="<?php echo $view_ride; ?>">
                                    <input type="hidden" name="ratee_id" value="<?php echo $p['user_id']; ?>">
                                    <input type="hidden" name="rating" id="rating_<?php echo $p['user_id']; ?>" value="5">
                                    
                                    <div class="rating-stars" style="margin-bottom: 10px;">
                                        <?php for ($i = 1; $i <= 5; $i++): ?>
                                            <i class="fas fa-star" data-rating="<?php echo $i; ?>" data-target="<?php echo $p['user_id']; ?>"></i>
                                        <?php endfor; ?>
                                    </div>
                                    
                                    <textarea name="review" class="form-control" placeholder="Write a review (optional)" rows="2"></textarea>
                                    
                                    <button type="submit" class="btn btn-sm btn-primary" style="margin-top: 10px;">
                                        Submit Rating
                                    </button>
                                </form>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
    // Scroll chat to bottom
    var chat = document.getElementById('chat');
    if (chat) {
        chat.scrollTop = chat.scrollHeight;
    }
    
    // Star rating hover effect
    document.querySelectorAll('.rating-stars i').forEach(star => {
        star.addEventListener('mouseenter', function() {
            let rating = this.getAttribute('data-rating');
            let target = this.getAttribute('data-target');
            document.querySelectorAll(`.rating-stars i[data-target="${target}"]`).forEach(s => {
                if (s.getAttribute('data-rating') <= rating) {
                    s.style.color = '#FFD700';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
        
        star.addEventListener('mouseleave', function() {
            let target = this.getAttribute('data-target');
            let currentRating = document.getElementById('rating_' + target).value;
            document.querySelectorAll(`.rating-stars i[data-target="${target}"]`).forEach(s => {
                if (s.getAttribute('data-rating') <= currentRating) {
                    s.style.color = '#FFD700';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
        
        star.addEventListener('click', function() {
            let rating = this.getAttribute('data-rating');
            let target = this.getAttribute('data-target');
            document.getElementById('rating_' + target).value = rating;
            
            document.querySelectorAll(`.rating-stars i[data-target="${target}"]`).forEach(s => {
                if (s.getAttribute('data-rating') <= rating) {
                    s.style.color = '#FFD700';
                } else {
                    s.style.color = '#ddd';
                }
            });
        });
    });
    </script>
    
    <?php
    require_once '../includes/footer.php';
    exit;
}

// ==================== MAIN LISTING PAGE ====================

ensure_demo_community_ride($pdo, $user_id);

$stmt = $pdo->query("
    SELECT rs.*, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM ride_share_participants WHERE ride_share_id = rs.id) as participant_count
    FROM ride_shares rs
    JOIN users u ON rs.creator_id = u.id
    WHERE rs.status = 'active'
    AND rs.departure_time > CURRENT_TIMESTAMP
    ORDER BY rs.departure_time ASC
");
$all_rides = $stmt->fetchAll();

// Get user's active rides
$my_rides = $pdo->prepare("
    SELECT rs.*, rsp.seats_booked, rsp.status as participant_status,
           CASE WHEN rs.creator_id = ? THEN 'creator' ELSE 'participant' END as user_role
    FROM ride_shares rs
    JOIN ride_share_participants rsp ON rs.id = rsp.ride_share_id
    WHERE rsp.user_id = ? AND rs.status = 'active'
    ORDER BY rs.departure_time ASC
");
$my_rides->execute([$user_id, $user_id]);
$my_active_rides = $my_rides->fetchAll();

$booked_ids_stmt = $pdo->prepare("SELECT ride_share_id FROM ride_share_participants WHERE user_id = ?");
$booked_ids_stmt->execute([$user_id]);
$user_booked_ride_ids = array_map('intval', array_column($booked_ids_stmt->fetchAll(PDO::FETCH_ASSOC), 'ride_share_id'));

$community_float_stmt = $pdo->prepare("
    SELECT rs.*, u.first_name, u.last_name,
           (SELECT COUNT(*) FROM ride_share_participants p WHERE p.ride_share_id = rs.id) AS participant_count
    FROM ride_shares rs
    JOIN users u ON rs.creator_id = u.id
    WHERE rs.status = 'active'
      AND rs.creator_id != ?
      AND datetime(rs.departure_time) > datetime('now')
    ORDER BY rs.departure_time ASC
    LIMIT 15
");
$community_float_stmt->execute([$user_id]);
$community_float_rides = $community_float_stmt->fetchAll();

require_once '../includes/header.php';
?>

<style>
:root {
    --gradient-1: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    --gradient-2: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    --gradient-3: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
    --gradient-4: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.hero-section {
    background: var(--gradient-1);
    color: white;
    padding: 50px;
    border-radius: 20px;
    margin-bottom: 30px;
    text-align: center;
    position: relative;
    overflow: hidden;
}

.hero-section::after {
    content: '';
    position: absolute;
    top: -50%;
    right: -50%;
    width: 200%;
    height: 200%;
    background: repeating-linear-gradient(45deg, transparent, transparent 20px, rgba(255,255,255,0.1) 20px, rgba(255,255,255,0.1) 40px);
    animation: moveStripes 20s linear infinite;
}

@keyframes moveStripes {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.hero-title {
    font-size: 3rem;
    font-weight: bold;
    margin-bottom: 20px;
    position: relative;
    z-index: 1;
}

.hero-subtitle {
    font-size: 1.2rem;
    opacity: 0.9;
    position: relative;
    z-index: 1;
}

.stats-bar {
    display: flex;
    justify-content: space-around;
    background: white;
    border-radius: 15px;
    padding: 20px;
    margin: 30px 0;
    box-shadow: 0 10px 30px rgba(0,0,0,0.1);
}

.stat-item {
    text-align: center;
}

.stat-value {
    font-size: 2rem;
    font-weight: bold;
    color: #667eea;
}

.stat-label {
    color: #666;
    margin-top: 5px;
}

.ride-card {
    background: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    transition: all 0.3s;
    border-left: 5px solid transparent;
    position: relative;
    overflow: hidden;
    cursor: pointer;
}

.ride-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}

.ride-card.featured {
    border-left-color: gold;
    background: linear-gradient(135deg, #fff9e6, white);
}

.ride-card::before {
    content: '';
    position: absolute;
    top: 0;
    right: 0;
    width: 100px;
    height: 100px;
    background: linear-gradient(135deg, transparent 50%, rgba(102, 126, 234, 0.1) 50%);
    border-radius: 0 0 0 100px;
}

.ride-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
}

.ride-route {
    font-size: 1.3rem;
    font-weight: bold;
}

.ride-time {
    background: #f0f0f0;
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.9rem;
}

.rs-card-pill {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 999px;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.03em;
}
.rs-card-pill.booked {
    background: #2ecc71;
    color: #fff;
}
.rs-card-pill.hosting {
    background: #f1c40f;
    color: #333;
}

.ride-creator {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 15px;
}

.creator-avatar {
    width: 40px;
    height: 40px;
    border-radius: 50%;
    background: var(--gradient-1);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
}

.ride-details-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
    margin: 15px 0;
}

.ride-detail-item {
    text-align: center;
    padding: 10px;
    background: #f8f9fa;
    border-radius: 8px;
}

.ride-detail-value {
    font-size: 1.2rem;
    font-weight: bold;
    color: #667eea;
}

.ride-detail-label {
    font-size: 0.8rem;
    color: #666;
}

.preferences {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
    margin: 15px 0;
}

.preference-badge {
    padding: 5px 12px;
    border-radius: 20px;
    font-size: 0.8rem;
    background: #f0f0f0;
}

.preference-badge.allowed {
    background: #4CAF50;
    color: white;
}

.create-ride-btn {
    background: var(--gradient-2);
    color: white;
    border: none;
    padding: 15px 30px;
    border-radius: 50px;
    font-weight: bold;
    cursor: pointer;
    transition: all 0.3s;
    font-size: 1.1rem;
    box-shadow: 0 5px 20px rgba(240, 147, 251, 0.4);
}

.create-ride-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 30px rgba(240, 147, 251, 0.6);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    background: white;
    border-radius: 15px;
}

.empty-state i {
    font-size: 4rem;
    color: #ddd;
    margin-bottom: 20px;
}

.my-rides-section {
    background: linear-gradient(135deg, #f6f9fc, #e9f2f9);
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 30px;
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-content {
    background: white;
    border-radius: 15px;
    padding: 30px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.vehicle-option {
    border: 2px solid #ddd;
    border-radius: 10px;
    padding: 15px;
    margin: 10px 0;
    cursor: pointer;
    transition: all 0.3s;
}

.vehicle-option:hover {
    border-color: #667eea;
    transform: translateY(-2px);
}

.vehicle-option.selected {
    border-color: #667eea;
    background: #f0f7ff;
}

/* Floating info panels (public list — everyone sees the same community panel) */
.rs-float-panel {
    position: fixed;
    bottom: max(12px, env(safe-area-inset-bottom));
    width: min(340px, calc(100vw - 24px));
    max-height: min(48vh, 420px);
    z-index: 850;
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 10px 40px rgba(0,0,0,0.18);
    display: flex;
    flex-direction: column;
    border: 1px solid rgba(102,126,234,0.25);
}
.rs-float-left { left: 12px; }
.rs-float-right { right: 12px; }
.rs-float-head {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: #fff;
    padding: 10px 14px;
    font-weight: 700;
    font-size: 0.9rem;
    display: flex;
    align-items: center;
    gap: 8px;
    flex-shrink: 0;
}
.rs-float-head.alt {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
    color: #0d3d2e;
}
.rs-float-body {
    overflow-y: auto;
    padding: 10px 12px 12px;
    flex: 1;
    font-size: 0.82rem;
    line-height: 1.4;
    color: #333;
}
.rs-float-item {
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}
.rs-float-item:last-child { border-bottom: none; }
.rs-float-item strong { display: block; color: #222; margin-bottom: 2px; }
.rs-float-meta { color: #666; font-size: 0.78rem; }
.rs-float-empty { color: #999; text-align: center; padding: 12px; font-size: 0.85rem; }
.rs-float-body a { color: #667eea; font-weight: 600; }
@media (max-width: 900px) {
    .rs-float-panel {
        width: calc(50vw - 18px);
        max-height: 38vh;
        font-size: 0.75rem;
    }
    .rs-float-left { left: 8px; width: calc(50vw - 14px); }
    .rs-float-right { right: 8px; width: calc(50vw - 14px); }
}
@media (max-width: 600px) {
    .rs-float-panel {
        width: calc(100vw - 16px);
        left: 8px !important;
        right: auto !important;
        max-height: 34vh;
    }
    .rs-float-right { bottom: calc(36vh + 16px); }
}
</style>

<div class="container">
    <!-- Hero Section -->
    <div class="hero-section">
        <div class="hero-title">
            <i class="fas fa-car-side"></i> Ride Sharing
        </div>
        <div class="hero-subtitle">
            Share rides, split costs, make friends. Join the community!
        </div>
    </div>
    
    <!-- Stats Bar -->
    <?php
    $total_rides = $pdo->query("SELECT COUNT(*) FROM ride_shares WHERE status = 'active'")->fetchColumn();
    $total_users = $pdo->query("SELECT COUNT(DISTINCT user_id) FROM ride_share_participants")->fetchColumn();
    $avg_rating = 4.8; // Placeholder
    ?>
    <div class="stats-bar">
        <div class="stat-item">
            <div class="stat-value"><?php echo $total_rides; ?></div>
            <div class="stat-label">Active Rides</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo $total_users; ?></div>
            <div class="stat-label">Happy Riders</div>
        </div>
        <div class="stat-item">
            <div class="stat-value"><?php echo $avg_rating; ?></div>
            <div class="stat-label">Avg Rating</div>
        </div>
    </div>
    
    <!-- My Active Rides -->
    <?php if (!empty($my_active_rides)): ?>
    <div class="my-rides-section">
        <h2><i class="fas fa-clock"></i> Your Active Rides</h2>
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 15px;">
            <?php foreach ($my_active_rides as $ride): ?>
            <div class="ride-card featured">
                <div class="ride-header">
                    <span class="ride-route">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars(substr($ride['from_location'], 0, 15)) . '...'; ?>
                    </span>
                    <span class="ride-time">
                        <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($ride['departure_time'])); ?>
                    </span>
                </div>
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong><?php echo $ride['user_role'] == 'creator' ? '👑 You are the creator' : '🎟️ You joined'; ?></strong>
                        <br>
                        <small><?php echo $ride['seats_booked']; ?> seat(s) · $<?php echo number_format($ride['fare_per_person'] * $ride['seats_booked'], 2); ?></small>
                    </div>
                    <a href="?view=<?php echo $ride['id']; ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-eye"></i> View
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Create Ride Button -->
    <div style="text-align: center; margin: 30px 0;">
        <button class="create-ride-btn" onclick="document.getElementById('createRideModal').style.display='flex'">
            <i class="fas fa-plus-circle"></i> Create New Ride Share
        </button>
    </div>
    
    <!-- Available Rides -->
    <?php if (count($all_rides) > 0): ?>
        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
            <?php foreach ($all_rides as $ride): 
                // Safe defaults for all values
                $estimated_duration = isset($ride['estimated_duration']) ? $ride['estimated_duration'] : 30;
                $allow_pets = isset($ride['allow_pets']) ? $ride['allow_pets'] : 0;
                $allow_luggage = isset($ride['allow_luggage']) ? $ride['allow_luggage'] : 1;
                $music_preference = isset($ride['music_preference']) ? $ride['music_preference'] : 'any';
                $flexible_time = isset($ride['flexible_time']) ? $ride['flexible_time'] : 0;
                $allow_smoking = isset($ride['allow_smoking']) ? $ride['allow_smoking'] : 0;
                
                $is_featured = ($ride['participant_count'] / $ride['total_seats']) > 0.5;
                $user_is_creator_card = ((int) $ride['creator_id'] === (int) $user_id);
                $user_booked_joiner = in_array((int) $ride['id'], $user_booked_ride_ids, true) && !$user_is_creator_card;
                $ride['current_user_booked'] = $user_booked_joiner;
                $ride['current_user_hosting'] = $user_is_creator_card;
            ?>
            <div class="ride-card <?php echo $is_featured ? 'featured' : ''; ?>" onclick="showRidePreview(<?php echo htmlspecialchars(json_encode($ride)); ?>)">
                <div class="ride-header">
                    <span class="ride-route">
                        <i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ride['from_location']); ?>
                    </span>
                    <span style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                        <?php if ($user_is_creator_card): ?>
                            <span class="rs-card-pill hosting">Your ride</span>
                        <?php elseif ($user_booked_joiner): ?>
                            <span class="rs-card-pill booked">Booked</span>
                        <?php endif; ?>
                        <span class="ride-time">
                            <i class="fas fa-clock"></i> <?php echo date('h:i A', strtotime($ride['departure_time'])); ?>
                        </span>
                    </span>
                </div>
                
                <div style="font-size: 0.9rem; color: #666; margin-bottom: 10px;">
                    <i class="fas fa-arrow-right"></i> to <?php echo htmlspecialchars($ride['to_location']); ?>
                </div>
                
                <div class="ride-creator">
                    <div class="creator-avatar">
                        <?php echo strtoupper(substr($ride['first_name'], 0, 1) . substr($ride['last_name'], 0, 1)); ?>
                    </div>
                    <div>
                        <strong><?php echo htmlspecialchars($ride['first_name'] . ' ' . $ride['last_name']); ?></strong>
                        <br>
                        <small>Creator</small>
                    </div>
                </div>
                
                <div class="ride-details-grid">
                    <div class="ride-detail-item">
                        <div class="ride-detail-value"><?php echo $ride['available_seats']; ?>/<?php echo $ride['total_seats']; ?></div>
                        <div class="ride-detail-label">Seats</div>
                    </div>
                    <div class="ride-detail-item">
                        <div class="ride-detail-value">$<?php echo number_format($ride['fare_per_person'], 2); ?></div>
                        <div class="ride-detail-label">Per Seat</div>
                    </div>
                    <div class="ride-detail-item">
                        <div class="ride-detail-value"><?php echo $estimated_duration; ?>m</div>
                        <div class="ride-detail-label">Duration</div>
                    </div>
                </div>
                
                <div class="preferences">
                    <span class="preference-badge <?php echo $allow_pets ? 'allowed' : ''; ?>" title="<?php echo $allow_pets ? 'Pets allowed' : 'No pets'; ?>">
                        <i class="fas fa-dog"></i>
                    </span>
                    <span class="preference-badge <?php echo $allow_luggage ? 'allowed' : ''; ?>" title="<?php echo $allow_luggage ? 'Luggage allowed' : 'No luggage'; ?>">
                        <i class="fas fa-suitcase"></i>
                    </span>
                    <span class="preference-badge" title="Music: <?php echo ucfirst($music_preference); ?>">
                        <i class="fas fa-music"></i> 
                        <?php 
                        if ($music_preference == 'quiet') echo '🔇';
                        elseif ($music_preference == 'conversation') echo '💬';
                        elseif ($music_preference == 'music') echo '🎵';
                        else echo '🎵';
                        ?>
                    </span>
                    <?php if ($flexible_time): ?>
                    <span class="preference-badge allowed" title="Flexible time">
                        <i class="fas fa-clock"></i> Flexible
                    </span>
                    <?php endif; ?>
                </div>
                
                <?php if ($user_is_creator_card): ?>
                <a href="?view=<?php echo $ride['id']; ?>" class="btn btn-primary btn-block" style="background: linear-gradient(135deg, #f1c40f, #e67e22); border: none;" onclick="event.stopPropagation();">
                    <i class="fas fa-crown"></i> Your ride · Open
                </a>
                <?php elseif ($user_booked_joiner): ?>
                <a href="?view=<?php echo $ride['id']; ?>" class="btn btn-success btn-block" onclick="event.stopPropagation();">
                    <i class="fas fa-check-circle"></i> Booked · View trip
                </a>
                <?php else: ?>
                <a href="?view=<?php echo $ride['id']; ?>" class="btn btn-primary btn-block" onclick="event.stopPropagation();">
                    <i class="fas fa-eye"></i> View Details
                </a>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty-state">
            <i class="fas fa-car-side"></i>
            <h3>No rides available</h3>
            <p>Be the first to create a ride share!</p>
            <button class="create-ride-btn" onclick="document.getElementById('createRideModal').style.display='flex'">
                <i class="fas fa-plus-circle"></i> Create New Ride
            </button>
        </div>
    <?php endif; ?>
</div>

<!-- Create Ride Modal -->
<div class="modal" id="createRideModal">
    <div class="modal-content">
        <span class="close-modal" onclick="document.getElementById('createRideModal').style.display='none'">&times;</span>
        <h2><i class="fas fa-plus-circle"></i> Create New Ride Share</h2>
        
        <form method="post">
            <input type="hidden" name="action" value="create_ride">
            
            <div class="form-group">
                <label>From Location</label>
                <input type="text" name="from_location" class="form-control" placeholder="e.g., University Main Gate" required>
            </div>
            
            <div class="form-group">
                <label>To Location</label>
                <input type="text" name="to_location" class="form-control" placeholder="e.g., Mirpur" required>
            </div>
            
            <div class="form-group">
                <label>Departure Time</label>
                <input type="datetime-local" name="departure_time" class="form-control" min="<?php echo date('Y-m-d\TH:i'); ?>" required>
            </div>
            
            <div class="form-group">
                <label>Vehicle Type</label>
                <select name="vehicle_type" class="form-control" required>
                    <option value="car">Car 🚗</option>
                    <option value="motorcycle">Motorcycle 🏍️</option>
                    <option value="microbus">Microbus 🚐</option>
                    <option value="bus">Bus 🚌</option>
                </select>
            </div>
            
            <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label>Total Seats</label>
                    <input type="number" name="total_seats" class="form-control" min="1" max="20" value="4" required>
                </div>
                
                <div class="form-group">
                    <label>Fare per Person ($)</label>
                    <input type="number" name="fare_per_person" class="form-control" min="1" step="0.5" value="50" required>
                </div>
            </div>
            
            <div class="form-group">
                <label>Estimated Duration (minutes)</label>
                <input type="number" name="estimated_duration" class="form-control" min="5" max="300" value="30" required>
            </div>
            
            <div class="form-group">
                <label>Description (optional)</label>
                <textarea name="description" class="form-control" rows="2" placeholder="Any additional info..."></textarea>
            </div>
            
            <div class="form-group">
                <label>Preferences</label>
                <div style="display: grid; grid-template-columns: repeat(2, 1fr); gap: 10px;">
                    <label>
                        <input type="checkbox" name="flexible_time"> Flexible departure time
                    </label>
                    <label>
                        <input type="checkbox" name="allow_pets"> Allow pets
                    </label>
                    <label>
                        <input type="checkbox" name="allow_smoking"> Allow smoking
                    </label>
                    <label>
                        <input type="checkbox" name="allow_luggage" checked> Allow luggage
                    </label>
                </div>
            </div>
            
            <div class="form-group">
                <label>Music Preference</label>
                <select name="music_preference" class="form-control">
                    <option value="any">Any is fine</option>
                    <option value="quiet">Prefer quiet</option>
                    <option value="conversation">Like to chat</option>
                    <option value="music">Like music</option>
                </select>
            </div>
            
            <button type="submit" class="btn btn-success btn-block">
                <i class="fas fa-check"></i> Create Ride Share
            </button>
        </form>
    </div>
</div>

<!-- Quick Preview Modal -->
<div class="modal" id="previewModal">
    <div class="modal-content" style="max-width: 400px;">
        <span class="close-modal" onclick="closePreview()">&times;</span>
        <div id="previewContent">
            <div style="text-align: center;">
                <i class="fas fa-spinner fa-spin fa-3x"></i>
                <p>Loading...</p>
            </div>
        </div>
        <div style="text-align: center; margin-top: 20px;">
            <a href="#" id="previewViewDetailsBtn" class="btn btn-primary">View Full Details</a>
        </div>
    </div>
</div>

<style>
.preview-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    background: linear-gradient(135deg, #667eea, #764ba2);
    color: white;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.5rem;
    font-weight: bold;
    margin: 0 auto 15px;
}

.preview-info {
    text-align: left;
    margin: 15px 0;
}

.preview-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #eee;
}

.preview-label {
    color: #666;
    font-weight: 500;
}

.preview-value {
    font-weight: bold;
    color: #333;
}

.preview-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 0.8rem;
    margin: 2px;
}

.preview-badge.allowed {
    background: #4CAF50;
    color: white;
}

.preview-badge.not-allowed {
    background: #f44336;
    color: white;
}
</style>

<script>
function showRidePreview(ride) {
    try {
        // Build preview HTML
        let musicIcon = '🎵';
        if (ride.music_preference === 'quiet') musicIcon = '🔇';
        else if (ride.music_preference === 'conversation') musicIcon = '💬';
        
        let petsBadge = ride.allow_pets ? 
            '<span class="preview-badge allowed"><i class="fas fa-dog"></i> Pets OK</span>' : 
            '<span class="preview-badge not-allowed"><i class="fas fa-dog"></i> No Pets</span>';
            
        let luggageBadge = ride.allow_luggage ? 
            '<span class="preview-badge allowed"><i class="fas fa-suitcase"></i> Luggage OK</span>' : 
            '<span class="preview-badge not-allowed"><i class="fas fa-suitcase"></i> No Luggage</span>';
        
        let statusBanner = '';
        if (ride.current_user_hosting) {
            statusBanner = '<div style="text-align:center;margin-bottom:12px;"><span style="display:inline-block;padding:6px 14px;border-radius:999px;background:#f1c40f;color:#333;font-weight:700;font-size:0.85rem;"><i class="fas fa-crown"></i> Your ride</span></div>';
        } else if (ride.current_user_booked) {
            statusBanner = '<div style="text-align:center;margin-bottom:12px;"><span style="display:inline-block;padding:6px 14px;border-radius:999px;background:#2ecc71;color:#fff;font-weight:700;font-size:0.85rem;"><i class="fas fa-check-circle"></i> Booked</span></div>';
        }
        
        let previewHTML = `
            ${statusBanner}
            <div class="preview-avatar">
                ${ride.first_name.charAt(0)}${ride.last_name.charAt(0)}
            </div>
            <h3 style="text-align: center; margin-bottom: 5px;">${ride.first_name} ${ride.last_name}</h3>
            <p style="text-align: center; color: #666; margin-bottom: 20px;">Ride Creator</p>
            
            <div class="preview-info">
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-map-marker-alt"></i> From</span>
                    <span class="preview-value">${ride.from_location}</span>
                </div>
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-flag-checkered"></i> To</span>
                    <span class="preview-value">${ride.to_location}</span>
                </div>
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-clock"></i> Time</span>
                    <span class="preview-value">${new Date(ride.departure_time).toLocaleString()}</span>
                </div>
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-chair"></i> Seats</span>
                    <span class="preview-value">${ride.available_seats}/${ride.total_seats} available</span>
                </div>
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-dollar-sign"></i> Fare</span>
                    <span class="preview-value">$${parseFloat(ride.fare_per_person).toFixed(2)} per seat</span>
                </div>
                <div class="preview-row">
                    <span class="preview-label"><i class="fas fa-car"></i> Vehicle</span>
                    <span class="preview-value">${ride.vehicle_type}</span>
                </div>
            </div>
            
            <div style="display: flex; gap: 5px; justify-content: center; margin: 15px 0;">
                ${petsBadge}
                ${luggageBadge}
                <span class="preview-badge" style="background: #f0f0f0;">
                    <i class="fas fa-music"></i> ${musicIcon}
                </span>
            </div>
        `;
        
        document.getElementById('previewContent').innerHTML = previewHTML;
        document.getElementById('previewViewDetailsBtn').href = '?view=' + ride.id;
        var pvBtn = document.getElementById('previewViewDetailsBtn');
        if (ride.current_user_hosting) {
            pvBtn.innerHTML = '<i class="fas fa-crown"></i> Open your ride';
            pvBtn.className = 'btn btn-primary';
            pvBtn.style.background = 'linear-gradient(135deg, #f1c40f, #e67e22)';
            pvBtn.style.border = 'none';
        } else if (ride.current_user_booked) {
            pvBtn.innerHTML = '<i class="fas fa-check-circle"></i> Booked — view trip';
            pvBtn.className = 'btn btn-success';
            pvBtn.style.background = '';
            pvBtn.style.border = '';
        } else {
            pvBtn.innerHTML = 'View Full Details';
            pvBtn.className = 'btn btn-primary';
            pvBtn.style.background = '';
            pvBtn.style.border = '';
        }
        document.getElementById('previewModal').style.display = 'flex';
        
    } catch (e) {
        console.error('Preview error:', e);
        // If preview fails, just go to the details page
        window.location.href = '?view=' + ride.id;
    }
}

function closePreview() {
    document.getElementById('previewModal').style.display = 'none';
}

// Close modal when clicking outside
window.addEventListener('click', function(event) {
    var modal = document.getElementById('previewModal');
    if (event.target == modal) {
        closePreview();
    }
    
    var createModal = document.getElementById('createRideModal');
    if (event.target == createModal) {
        createModal.style.display = 'none';
    }
});

// Auto-refresh chat every 10 seconds if on ride view
<?php if ($view_ride > 0): ?>
setTimeout(function() {
    location.reload();
}, 10000);
<?php endif; ?>
</script>

<!-- Floating panels: your rides + everyone else's (public) -->
<div class="rs-float-panel rs-float-left" id="rs-float-my" aria-label="My ride shares">
    <div class="rs-float-head"><i class="fas fa-id-badge"></i> My ride shares</div>
    <div class="rs-float-body">
        <?php if (empty($my_active_rides)): ?>
            <div class="rs-float-empty">No active ride yet. Use <strong>Create New Ride Share</strong> above.</div>
        <?php else: ?>
            <?php foreach ($my_active_rides as $mr): ?>
            <div class="rs-float-item">
                <strong><?php echo htmlspecialchars($mr['from_location']); ?> → <?php echo htmlspecialchars($mr['to_location']); ?></strong>
                <div class="rs-float-meta">
                    <?php echo $mr['user_role'] === 'creator' ? 'You are the host' : 'You joined'; ?> ·
                    <?php echo (int) $mr['seats_booked']; ?> seat(s) ·
                    <?php echo date('M j · g:i A', strtotime($mr['departure_time'])); ?>
                </div>
                <a href="?view=<?php echo (int) $mr['id']; ?>">Details →</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="rs-float-panel rs-float-right" id="rs-float-community" aria-label="Community rides">
    <div class="rs-float-head alt"><i class="fas fa-globe"></i> Others sharing <small style="opacity:.85">(public)</small></div>
    <div class="rs-float-body">
        <?php if (empty($community_float_rides)): ?>
            <div class="rs-float-empty">No other rides listed. Check back later.</div>
        <?php else: ?>
            <?php foreach ($community_float_rides as $cr): ?>
            <div class="rs-float-item">
                <strong><?php echo htmlspecialchars($cr['first_name'] . ' ' . $cr['last_name']); ?></strong>
                <div class="rs-float-meta">
                    <?php echo htmlspecialchars($cr['from_location']); ?> → <?php echo htmlspecialchars($cr['to_location']); ?>
                    · <?php echo (int) $cr['participant_count']; ?> people ·
                    <?php echo (int) $cr['available_seats']; ?>/<?php echo (int) $cr['total_seats']; ?> seats ·
                    $<?php echo number_format((float) $cr['fare_per_person'], 2); ?>/seat
                </div>
                <a href="?view=<?php echo (int) $cr['id']; ?>">View &amp; join →</a>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>