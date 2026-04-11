<?php
/**
 * Uber System for University Transport
 * Features both real and AI riders for demo purposes
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];
$user_info = getUserInfo($pdo, $user_id);
$is_rider = isRider($pdo, $user_id);

$rider_profile = null;
if ($is_rider) {
    $rp_stmt = $pdo->prepare("
        SELECT r.*, u.first_name, u.last_name, u.student_id, u.email
        FROM riders r
        JOIN users u ON u.id = r.user_id
        WHERE r.user_id = ?
    ");
    $rp_stmt->execute([$user_id]);
    $rider_profile = $rp_stmt->fetch();
}

$user_mode = isset($_GET['mode']) ? $_GET['mode'] : 'passenger';

// BRACU area — university pickup points (same style as main transport UI)
define('UBER_CAMPUS_LAT', 23.8103);
define('UBER_CAMPUS_LNG', 90.4125);
define('UBER_MATCH_RADIUS_KM', 30.0);
define('UBER_SHOW_DRIVERS', 2);

$UBER_CAMPUS_PICKUPS = [
    'main_gate'      => ['label' => 'University Main Gate',       'lat' => 23.8103, 'lng' => 90.4125],
    'second_gate'    => ['label' => 'University Second Gate',     'lat' => 23.8088, 'lng' => 90.4112],
    'residential'    => ['label' => 'Residential Campus / TARC', 'lat' => 23.8075, 'lng' => 90.4095],
];

// Handle becoming a rider
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] == 'passenger_set_trip') {
        $pk = isset($_POST['pickup_key']) ? (string) $_POST['pickup_key'] : '';
        $drop = trim($_POST['dropoff_location'] ?? '');
        if ($drop === '' || !isset($UBER_CAMPUS_PICKUPS[$pk])) {
            $_SESSION['error'] = 'Choose a campus pickup point and enter your destination.';
            header('Location: uber.php?mode=passenger');
            exit;
        }
        $pt = $UBER_CAMPUS_PICKUPS[$pk];
        $_SESSION['uber_pickup_key'] = $pk;
        $_SESSION['uber_pass_label'] = $pt['label'];
        $_SESSION['uber_pass_lat'] = $pt['lat'];
        $_SESSION['uber_pass_lng'] = $pt['lng'];
        $_SESSION['uber_dropoff'] = $drop;
        unset($_SESSION['uber_selected_rider_id']);
        header('Location: uber.php?mode=passenger');
        exit;
    }
    if ($_POST['action'] == 'passenger_clear_location') {
        unset(
            $_SESSION['uber_pass_lat'],
            $_SESSION['uber_pass_lng'],
            $_SESSION['uber_pass_label'],
            $_SESSION['uber_pickup_key'],
            $_SESSION['uber_dropoff'],
            $_SESSION['uber_selected_rider_id']
        );
        header('Location: uber.php?mode=passenger');
        exit;
    }
    if ($_POST['action'] == 'select_driver') {
        $_SESSION['uber_selected_rider_id'] = (int) ($_POST['rider_id'] ?? 0);
        header('Location: uber.php?mode=passenger');
        exit;
    }
    if ($_POST['action'] == 'clear_driver_selection') {
        unset($_SESSION['uber_selected_rider_id']);
        header('Location: uber.php?mode=passenger');
        exit;
    }
    if ($_POST['action'] == 'become_rider') {
        $vehicle_type = $_POST['vehicle_type'];
        $vehicle_model = $_POST['vehicle_model'];
        $vehicle_number = $_POST['vehicle_number'];
        $license_number = $_POST['license_number'];
        // Removed vehicle_color
        
        // Check if already a rider
        $check_stmt = $pdo->prepare("SELECT id FROM riders WHERE user_id = ?");
        $check_stmt->execute([$user_id]);
        
        if ($check_stmt->fetch()) {
            $sql = "UPDATE riders SET vehicle_type = ?, vehicle_model = ?, vehicle_number = ?, license_number = ?, is_available = 1, is_verified = 1 WHERE user_id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$vehicle_type, $vehicle_model, $vehicle_number, $license_number, $user_id]);
        } else {
            $sql = "INSERT INTO riders (user_id, vehicle_type, vehicle_model, vehicle_number, license_number, is_verified, is_available) VALUES (?, ?, ?, ?, ?, 1, 1)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$user_id, $vehicle_type, $vehicle_model, $vehicle_number, $license_number]);
        }
        
        $_SESSION['success'] = "Your driver profile is verified and saved.";
        header("Location: uber.php?mode=rider");
        exit;
    }
    
    // Handle ride request
    if ($_POST['action'] == 'request_ride' && isset($_POST['rider_id'])) {
        $rider_id = (int)$_POST['rider_id'];
        if ($rider_id < 1) {
            $_SESSION['error'] = 'Please choose a driver first (complete steps 2–3).';
            header('Location: uber.php?mode=passenger');
            exit;
        }
        $pickup = $_POST['pickup_location'];
        $dropoff = $_POST['dropoff_location'];
        $pickup_lat = (float)$_POST['pickup_lat'];
        $pickup_lng = (float)$_POST['pickup_lng'];
        $dropoff_lat = (float)$_POST['dropoff_lat'];
        $dropoff_lng = (float)$_POST['dropoff_lng'];
        $fare = (float)$_POST['fare'];
        $distance = (float)$_POST['distance'];
        
        // Check wallet balance
        $wallet_stmt = $pdo->prepare("SELECT balance FROM wallet WHERE user_id = ?");
        $wallet_stmt->execute([$user_id]);
        $wallet = $wallet_stmt->fetch();
        
        if ($wallet['balance'] < $fare) {
            $_SESSION['error'] = "Insufficient balance. Please add funds to your wallet.";
            header("Location: wallet.php");
            exit;
        }
        
        // Check if this is an AI rider (student_id starts with AI)
        $rider_check = $pdo->prepare("SELECT u.student_id FROM riders r JOIN users u ON r.user_id = u.id WHERE r.id = ?");
        $rider_check->execute([$rider_id]);
        $rider_data = $rider_check->fetch();
        
        $is_ai_rider = ($rider_data && strpos($rider_data['student_id'], 'AI') === 0);
        
        // Create ride request
        $ride_stmt = $pdo->prepare("
            INSERT INTO uber_rides (rider_id, passenger_id, pickup_location, dropoff_location, 
                pickup_latitude, pickup_longitude, dropoff_latitude, dropoff_longitude, 
                fare, distance_km, status) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'requested')
        ");
        $ride_stmt->execute([$rider_id, $user_id, $pickup, $dropoff, 
            $pickup_lat, $pickup_lng, $dropoff_lat, $dropoff_lng, 
            $fare, $distance]);
        
        $ride_id = $pdo->lastInsertId();
        
        // Demo AI drivers: auto-approve so tracking works without admin
        if ($is_ai_rider) {
            $pdo->prepare("UPDATE uber_rides SET status = 'accepted', start_time = CURRENT_TIMESTAMP WHERE id = ?")->execute([$ride_id]);
            $_SESSION['ai_ride'] = $ride_id;
            $_SESSION['success'] = "Your ride is confirmed. The driver will pick you up shortly.";
        } else {
            $_SESSION['success'] = "Ride requested. You can track status below.";
        }
        
        // Redirect to tracking page
        header("Location: ride-tracking.php?ride_id=" . $ride_id);
        exit;
    }
    
    // Rider self-accept removed — real bookings are approved by admin (see admin.php)
    
    // Handle ride start (rider started trip)
    if ($_POST['action'] == 'start_ride' && isset($_POST['ride_id'])) {
        $ride_id = (int)$_POST['ride_id'];
        
        $update_stmt = $pdo->prepare("UPDATE uber_rides SET status = 'started' WHERE id = ?");
        $update_stmt->execute([$ride_id]);
        
        $_SESSION['success'] = "Trip started! Safe journey.";
        header("Location: ride-tracking.php?ride_id=" . $ride_id);
        exit;
    }
    
    // Handle ride completion
    if ($_POST['action'] == 'complete_ride' && isset($_POST['ride_id'])) {
        $ride_id = (int)$_POST['ride_id'];
        
        $pdo->beginTransaction();
        
        try {
            // Update ride status
            $update_stmt = $pdo->prepare("UPDATE uber_rides SET status = 'completed', end_time = CURRENT_TIMESTAMP WHERE id = ?");
            $update_stmt->execute([$ride_id]);
            
            // Get ride details for payment and points
            $ride_stmt = $pdo->prepare("SELECT * FROM uber_rides WHERE id = ?");
            $ride_stmt->execute([$ride_id]);
            $ride = $ride_stmt->fetch();
            
            // Deduct fare from passenger's wallet
            $wallet_stmt = $pdo->prepare("UPDATE wallet SET balance = balance - ? WHERE user_id = ?");
            $wallet_stmt->execute([$ride['fare'], $ride['passenger_id']]);
            
            // Record transaction for passenger
            $trans_stmt = $pdo->prepare("INSERT INTO transactions (user_id, amount, type, description) VALUES (?, ?, 'debit', 'Uber ride payment')");
            $trans_stmt->execute([$ride['passenger_id'], $ride['fare']]);
            
            // Award points (10 points per ride)
            $points = 10;
            
            // Award points to passenger
            awardPoints($pdo, $ride['passenger_id'], $points, 'uber_passenger', $ride_id);
            
            // Award points to rider
            $rider_user_stmt = $pdo->prepare("SELECT user_id FROM riders WHERE id = ?");
            $rider_user_stmt->execute([$ride['rider_id']]);
            $rider_user = $rider_user_stmt->fetch();
            
            awardPoints($pdo, $rider_user['user_id'], $points, 'uber_rider', $ride_id);
            
            $pdo->commit();
            
            // Store ride ID in session for review
            $_SESSION['completed_ride'] = $ride_id;
            $_SESSION['success'] = "Ride completed! You earned $points points!";
            
            // Redirect to tracking page with completed status
            header("Location: ride-tracking.php?ride_id=" . $ride_id . "&completed=1");
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Failed to complete ride: " . $e->getMessage();
            header("Location: ride-tracking.php?ride_id=" . $ride_id);
        }
        exit;
    }
    
    // Handle review submission
    if ($_POST['action'] == 'submit_review' && isset($_POST['ride_id'])) {
        $ride_id = (int)$_POST['ride_id'];
        $rating = (int)$_POST['rating'];
        $review_text = $_POST['review_text'];
        
        $_SESSION['review_submitted'] = true;
        $_SESSION['last_rating'] = $rating;
        
        $_SESSION['success'] = "Thank you for your review!";
        header("Location: uber.php");
        exit;
    }
}

$passenger_lat = isset($_SESSION['uber_pass_lat']) ? (float) $_SESSION['uber_pass_lat'] : null;
$passenger_lng = isset($_SESSION['uber_pass_lng']) ? (float) $_SESSION['uber_pass_lng'] : null;
$passenger_label = isset($_SESSION['uber_pass_label']) ? (string) $_SESSION['uber_pass_label'] : '';
$passenger_dropoff = isset($_SESSION['uber_dropoff']) ? trim((string) $_SESSION['uber_dropoff']) : '';
$passenger_pickup_key = isset($_SESSION['uber_pickup_key']) ? (string) $_SESSION['uber_pickup_key'] : '';

$passenger_has_location = ($passenger_lat !== null && $passenger_lng !== null
    && $passenger_lat >= -90 && $passenger_lat <= 90
    && $passenger_lng >= -180 && $passenger_lng <= 180);
// Show drivers only after campus pickup + destination are set
$passenger_trip_ready = $passenger_has_location && $passenger_dropoff !== '' && $passenger_pickup_key !== '';

$selected_rider_session = isset($_SESSION['uber_selected_rider_id']) ? (int) $_SESSION['uber_selected_rider_id'] : 0;

/**
 * Prefer 1 motorcycle + 1 car/microbus when possible. Max 2 drivers; $sorted is nearest-first.
 */
function uber_pick_diverse_drivers(array $sorted, $max = 2) {
    if (count($sorted) <= $max) {
        return $sorted;
    }
    $out = [];
    $used_ids = [];
    foreach ($sorted as $row) {
        if (count($out) >= $max) {
            break;
        }
        $t = strtolower((string) ($row['vehicle_type'] ?? ''));
        if ($t === 'motorcycle') {
            $out[] = $row;
            $used_ids[(int) $row['id']] = true;
            break;
        }
    }
    foreach ($sorted as $row) {
        if (count($out) >= $max) {
            break;
        }
        if (!empty($used_ids[(int) $row['id']])) {
            continue;
        }
        $t = strtolower((string) ($row['vehicle_type'] ?? ''));
        if ($t === 'car' || $t === 'microbus') {
            $out[] = $row;
            $used_ids[(int) $row['id']] = true;
            break;
        }
    }
    foreach ($sorted as $row) {
        if (count($out) >= $max) {
            break;
        }
        if (!empty($used_ids[(int) $row['id']])) {
            continue;
        }
        $out[] = $row;
        $used_ids[(int) $row['id']] = true;
    }
    return $out;
}

// Up to 2 verified drivers near selected gate; try mixed vehicle types
$available_riders = [];
$nearby_pool_count = 0;
if ($user_mode === 'passenger' && $passenger_trip_ready) {
    $riders_stmt = $pdo->prepare("
        SELECT r.*, u.first_name, u.last_name, u.student_id, u.total_points,
               (SELECT COUNT(*) FROM uber_rides WHERE rider_id = r.id AND status = 'completed') as ride_count
        FROM riders r
        JOIN users u ON r.user_id = u.id
        WHERE r.is_available = 1 AND r.is_verified = 1 AND r.user_id != ?
    ");
    $riders_stmt->execute([$user_id]);
    $pool = $riders_stmt->fetchAll();
    $with_dist = [];
    foreach ($pool as $row) {
        $rlat = ($row['current_latitude'] !== null && $row['current_latitude'] !== '')
            ? (float) $row['current_latitude'] : UBER_CAMPUS_LAT;
        $rlng = ($row['current_longitude'] !== null && $row['current_longitude'] !== '')
            ? (float) $row['current_longitude'] : UBER_CAMPUS_LNG;
        $d = uber_haversine_km($passenger_lat, $passenger_lng, $rlat, $rlng);
        if ($d <= UBER_MATCH_RADIUS_KM) {
            $row['distance_km'] = round($d, 1);
            $with_dist[] = $row;
        }
    }
    usort($with_dist, function ($a, $b) {
        return ($a['distance_km'] <=> $b['distance_km']);
    });
    $nearby_pool_count = count($with_dist);
    $available_riders = uber_pick_diverse_drivers($with_dist, UBER_SHOW_DRIVERS);
}

$selected_driver_row = null;
if ($selected_rider_session > 0 && $passenger_trip_ready) {
    foreach ($available_riders as $nr) {
        if ((int) $nr['id'] === $selected_rider_session) {
            $selected_driver_row = $nr;
            break;
        }
    }
    if (!$selected_driver_row) {
        $one = $pdo->prepare("
            SELECT r.*, u.first_name, u.last_name, u.student_id, u.total_points,
                   (SELECT COUNT(*) FROM uber_rides WHERE rider_id = r.id AND status = 'completed') as ride_count
            FROM riders r
            JOIN users u ON r.user_id = u.id
            WHERE r.id = ? AND r.is_verified = 1 AND r.user_id != ?
        ");
        $one->execute([$selected_rider_session, $user_id]);
        $selected_driver_row = $one->fetch();
    }
}

// Get ride requests (for riders) — no self-accept; show status + trip controls after admin approval
$ride_requests = [];
if ($user_mode === 'rider' && $is_rider && $rider_profile) {
    $requests_stmt = $pdo->prepare("
        SELECT ur.*, u.first_name, u.last_name, u.student_id
        FROM uber_rides ur
        JOIN users u ON ur.passenger_id = u.id
        WHERE ur.rider_id = ? AND ur.status IN ('requested', 'accepted', 'started')
        ORDER BY ur.booking_time DESC
    ");
    $requests_stmt->execute([$rider_profile['id']]);
    $ride_requests = $requests_stmt->fetchAll();
}

// Active rides: passenger + driver names and vehicle (for correct labels per mode)
$active_rides_stmt = $pdo->prepare("
    SELECT ur.*,
           pu.first_name AS passenger_first, pu.last_name AS passenger_last, pu.student_id AS passenger_sid,
           du.first_name AS driver_first, du.last_name AS driver_last, du.student_id AS driver_sid,
           r.vehicle_type, r.vehicle_model, r.vehicle_number
    FROM uber_rides ur
    JOIN users pu ON pu.id = ur.passenger_id
    JOIN riders r ON r.id = ur.rider_id
    JOIN users du ON du.id = r.user_id
    WHERE (ur.passenger_id = ? OR r.user_id = ?)
    AND ur.status IN ('requested', 'accepted', 'started')
    ORDER BY ur.booking_time DESC
");
$active_rides_stmt->execute([$user_id, $user_id]);
$active_rides = $active_rides_stmt->fetchAll();

require_once '../includes/header.php';
?>

<style>
:root {
    --uber-black: #000000;
    --uber-green: #06C167;
    --ai-blue: #3498db;
    --ai-purple: #9b59b6;
    --ai-tag: #f39c12;
}

.uber-header {
    background: linear-gradient(135deg, #000000, #1a1a1a);
    color: white;
    padding: 30px;
    border-radius: 15px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.2);
}

.uber-title {
    font-size: 2.5rem;
    font-weight: bold;
    margin-bottom: 10px;
}

.uber-title i {
    color: var(--uber-green);
    margin-right: 15px;
}

.uber-subtitle {
    color: #aaa;
    font-size: 1.1rem;
}

/* Mode Tabs */
.uber-tabs {
    display: flex;
    gap: 20px;
    margin-bottom: 30px;
    background: white;
    padding: 10px;
    border-radius: 50px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.uber-tab {
    flex: 1;
    text-align: center;
    padding: 15px 30px;
    border-radius: 40px;
    text-decoration: none;
    color: #666;
    font-weight: bold;
    transition: all 0.3s;
}

.uber-tab i {
    margin-right: 10px;
    font-size: 1.2rem;
}

.uber-tab.active {
    background: var(--uber-black);
    color: white;
    transform: scale(1.05);
}

.uber-tab.passenger.active {
    background: var(--uber-green);
}

.uber-tab.rider.active {
    background: var(--ai-purple);
}

/* Map Container */
.map-section {
    background: white;
    border-radius: 15px;
    padding: 20px;
    margin-bottom: 30px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
}

.map-container {
    height: 300px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    position: relative;
    overflow: hidden;
    margin-bottom: 20px;
}

.mini-map {
    width: 100%;
    height: 100%;
    position: relative;
}

.map-marker {
    position: absolute;
    width: 20px;
    height: 20px;
    border: 3px solid white;
    border-radius: 50%;
    box-shadow: 0 0 20px rgba(0,0,0,0.3);
    animation: pulse 2s infinite;
}

.map-marker.user {
    background: #3498db;
    left: 30%;
    top: 40%;
}

.map-marker.rider {
    background: var(--uber-green);
    left: 60%;
    top: 30%;
    animation: moveRider 3s infinite alternate;
}

.map-marker.ai-rider {
    background: var(--ai-purple);
    left: 60%;
    top: 30%;
    animation: moveRider 3s infinite alternate;
}

.map-marker.destination {
    background: #e74c3c;
    left: 80%;
    top: 60%;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.5); opacity: 0.7; }
    100% { transform: scale(1); opacity: 1; }
}

@keyframes moveRider {
    0% { transform: translate(0, 0); }
    100% { transform: translate(20px, -10px); }
}

.map-route {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: repeating-linear-gradient(90deg, transparent, transparent 20px, rgba(255,255,255,0.3) 20px, rgba(255,255,255,0.3) 40px);
    opacity: 0.3;
    pointer-events: none;
}

.location-inputs {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
}

.location-input {
    background: #f6f6f6;
    padding: 15px;
    border-radius: 10px;
    border: 2px solid transparent;
    transition: all 0.3s;
}

.location-input:focus-within {
    border-color: var(--uber-green);
    background: white;
}

.location-input label {
    display: block;
    font-size: 0.8rem;
    color: #666;
    margin-bottom: 5px;
}

.location-input input {
    width: 100%;
    border: none;
    background: transparent;
    font-size: 1rem;
    outline: none;
}

/* Rider Cards */
.rider-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.rider-card {
    background: white;
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 5px 20px rgba(0,0,0,0.1);
    transition: all 0.3s;
    border: 2px solid transparent;
    position: relative;
    overflow: hidden;
}

.rider-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 15px 30px rgba(0,0,0,0.15);
}

.rider-card.real-rider:hover {
    border-color: var(--uber-green);
}

.rider-card.ai-rider:hover {
    border-color: var(--ai-purple);
}

.rider-badge {
    position: absolute;
    top: 10px;
    right: 10px;
    padding: 4px 12px;
    border-radius: 20px;
    font-size: 0.7rem;
    font-weight: bold;
    color: white;
}

.rider-badge.real {
    background: var(--uber-green);
}

.rider-badge.ai {
    background: var(--ai-purple);
}

.rider-badge.ai::before {
    content: '🤖 ';
}

.rider-header {
    display: flex;
    align-items: center;
    gap: 15px;
    margin-bottom: 15px;
}

.rider-avatar {
    width: 60px;
    height: 60px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-size: 1.5rem;
    font-weight: bold;
}

.rider-avatar.real {
    background: linear-gradient(135deg, var(--uber-green), #0a8043);
}

.rider-avatar.ai {
    background: linear-gradient(135deg, var(--ai-purple), #8e44ad);
}

.rider-info h3 {
    margin: 0;
    font-size: 1.2rem;
}

.rider-stats {
    display: flex;
    gap: 10px;
    margin-top: 5px;
    flex-wrap: wrap;
}

.rider-stat {
    background: #f6f6f6;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.8rem;
    color: #666;
}

.rider-stat i {
    color: gold;
    margin-right: 3px;
}

.rider-vehicle {
    background: #f6f6f6;
    padding: 10px;
    border-radius: 8px;
    margin: 15px 0;
    display: flex;
    align-items: center;
    gap: 10px;
}

.eta-badge {
    background: var(--uber-green);
    color: white;
    padding: 5px 15px;
    border-radius: 25px;
    display: inline-block;
    font-weight: bold;
    margin-top: 10px;
}

.ai-note {
    margin-top: 20px;
    padding: 15px;
    background: #f0f7ff;
    border-radius: 8px;
    border-left: 4px solid var(--ai-purple);
    font-size: 0.9rem;
}

.ai-note i {
    color: var(--ai-purple);
    margin-right: 5px;
}

.live-indicator {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-top: 10px;
}

.live-dot {
    width: 10px;
    height: 10px;
    background: #2ecc71;
    border-radius: 50%;
    animation: livePulse 1.5s infinite;
}

@keyframes livePulse {
    0% { opacity: 1; transform: scale(1); }
    50% { opacity: 0.5; transform: scale(1.5); }
    100% { opacity: 1; transform: scale(1); }
}

.filter-badge {
    display: inline-block;
    padding: 5px 15px;
    border-radius: 20px;
    font-size: 0.8rem;
    font-weight: bold;
    margin-right: 10px;
}

.filter-badge.real {
    background: var(--uber-green);
    color: white;
}

.filter-badge.ai {
    background: var(--ai-purple);
    color: white;
}

@media (max-width: 768px) {
    .uber-tabs {
        flex-direction: column;
        border-radius: 15px;
    }
    
    .location-inputs {
        grid-template-columns: 1fr;
    }
    
    .rider-grid {
        grid-template-columns: 1fr;
    }
}
</style>

<div class="container">
    <!-- Uber Header -->
    <div class="uber-header">
        <div class="uber-title">
            <i class="fas fa-taxi"></i> 
            Uber for University
        </div>
        <div class="uber-subtitle">
            Campus pickup → your destination → up to two drivers (bike + car when available) → confirm → request ride.
        </div>
    </div>

    <!-- Mode Tabs -->
    <div class="uber-tabs">
        <a href="?mode=passenger" class="uber-tab passenger <?php echo $user_mode == 'passenger' ? 'active' : ''; ?>">
            <i class="fas fa-user"></i> I'm a Passenger
        </a>
        <a href="?mode=rider" class="uber-tab rider <?php echo $user_mode == 'rider' ? 'active' : ''; ?>">
            <i class="fas fa-motorcycle"></i> I'm a Rider
        </a>
    </div>

    <?php if ($user_mode == 'passenger'): ?>
        <!-- PASSENGER VIEW -->
        
        <?php if (!$passenger_trip_ready): ?>
        <div class="card" style="background: linear-gradient(135deg, #1a1a1a, #2d2d2d); color: #fff; padding: 24px; border-radius: 12px; margin-bottom: 24px;">
            <h2 style="margin: 0 0 8px 0; color: #fff;"><i class="fas fa-university" style="color: var(--uber-green);"></i> Step 1 — Campus pickup &amp; destination</h2>
            <p style="opacity: 0.9; margin: 0 0 18px 0; line-height: 1.5;">Pickup is always a university gate. Enter where you want to go — then we show 1–2 nearby drivers (bike + car when possible).</p>
            <form method="post">
                <input type="hidden" name="action" value="passenger_set_trip">
                <label style="display: block; font-size: 0.85rem; margin-bottom: 6px; opacity: 0.85;"><i class="fas fa-map-pin"></i> Pickup point</label>
                <select name="pickup_key" required style="width: 100%; max-width: 440px; padding: 12px; border-radius: 8px; border: none; margin-bottom: 16px;">
                    <option value="">— Select gate / pickup —</option>
                    <?php foreach ($UBER_CAMPUS_PICKUPS as $key => $p): ?>
                    <option value="<?php echo htmlspecialchars($key); ?>" <?php echo ($passenger_pickup_key === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($p['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <label style="display: block; font-size: 0.85rem; margin-bottom: 6px; opacity: 0.85;"><i class="fas fa-flag-checkered"></i> Destination (you type)</label>
                <input type="text" name="dropoff_location" value="<?php echo htmlspecialchars($passenger_dropoff); ?>" placeholder="Where to? e.g. Bashundhara Gate, Jamuna Future Park" required style="width: 100%; max-width: 440px; padding: 12px; border-radius: 8px; border: none; margin-bottom: 16px;">
                <button type="submit" class="btn" style="background: var(--uber-green); color: #000; border: none; padding: 12px 22px; border-radius: 8px; font-weight: 700; cursor: pointer;">
                    <i class="fas fa-arrow-right"></i> Find drivers
                </button>
            </form>
        </div>
        <?php else: ?>
        <div class="card" style="padding: 18px 20px; margin-bottom: 20px;">
            <div style="display: flex; flex-wrap: wrap; align-items: flex-start; justify-content: space-between; gap: 12px;">
                <div>
                    <strong><i class="fas fa-circle" style="color: var(--uber-green); font-size: 0.6rem; vertical-align: middle;"></i> Pickup</strong>
                    <div style="color: #333; margin-top: 4px;"><?php echo htmlspecialchars($passenger_label); ?></div>
                    <strong style="display: block; margin-top: 12px;"><i class="fas fa-flag-checkered" style="color: #e74c3c;"></i> Destination</strong>
                    <div style="color: #333; margin-top: 4px;"><?php echo htmlspecialchars($passenger_dropoff); ?></div>
                </div>
                <form method="post" style="margin: 0;">
                    <input type="hidden" name="action" value="passenger_clear_location">
                    <button type="submit" style="background: #eee; border: none; padding: 8px 14px; border-radius: 6px; cursor: pointer;">Change</button>
                </form>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($passenger_trip_ready && !$selected_driver_row): ?>
        <div class="card" style="margin-bottom: 24px;">
            <h2 style="margin: 0 0 6px 0;"><i class="fas fa-user-friends"></i> Step 2 — Choose a driver</h2>
            <p style="color: #666; margin: 0 0 18px 0;">Up to two nearby drivers — we try to show one <strong>bike</strong> and one <strong>car</strong> when both are available.</p>
            <?php if (count($available_riders) === 0): ?>
                <p style="color: #888;">No drivers in range right now. Try again later or ask an admin to add demo drivers (run <code>seed_uber_drivers.php</code> once).</p>
            <?php else: ?>
                <div class="rider-grid">
                    <?php foreach ($available_riders as $rider): 
                        $is_ai = (strpos($rider['student_id'], 'AI') === 0);
                        $initials = strtoupper(substr($rider['first_name'], 0, 1) . substr($rider['last_name'], 0, 1));
                        $avatarColor = $is_ai ? 'linear-gradient(135deg, #9b59b6, #8e44ad)' : 'linear-gradient(135deg, var(--uber-green), #0a8043)';
                    ?>
                    <div class="rider-card <?php echo $is_ai ? 'ai-rider' : 'real-rider'; ?>">
                        <?php
                        $vtype = strtolower((string) ($rider['vehicle_type'] ?? ''));
                        $type_label = $vtype === 'motorcycle' ? 'Bike' : (($vtype === 'car' || $vtype === 'microbus') ? 'Car' : ucfirst($vtype));
                        ?>
                        <div class="rider-badge <?php echo $is_ai ? 'ai' : 'real'; ?>"><?php echo htmlspecialchars($type_label); ?></div>
                        <div class="rider-header">
                            <div class="rider-avatar <?php echo $is_ai ? 'ai' : 'real'; ?>" style="background: <?php echo $avatarColor; ?>;"><?php echo htmlspecialchars($initials); ?></div>
                            <div class="rider-info">
                                <h3><?php echo htmlspecialchars($rider['first_name'] . ' ' . $rider['last_name']); ?></h3>
                                <p style="margin: 4px 0 0 0; font-size: 0.9rem; color: #666;">~<?php echo (float) $rider['distance_km']; ?> km away</p>
                            </div>
                        </div>
                        <div class="rider-vehicle">
                            <div>
                                <strong><?php echo htmlspecialchars(ucfirst($rider['vehicle_type'])); ?></strong>
                                · <?php echo htmlspecialchars($rider['vehicle_model']); ?>
                                <br><small>Plate <?php echo htmlspecialchars($rider['vehicle_number']); ?> · License <?php echo htmlspecialchars($rider['license_number']); ?></small>
                            </div>
                        </div>
                        <form method="post">
                            <input type="hidden" name="action" value="select_driver">
                            <input type="hidden" name="rider_id" value="<?php echo (int) $rider['id']; ?>">
                            <button type="submit" style="width: 100%; margin-top: 12px; padding: 12px; border: none; border-radius: 8px; background: <?php echo $is_ai ? 'var(--ai-purple)' : 'var(--uber-black)'; ?>; color: #fff; font-weight: 700; cursor: pointer;">
                                Select <?php echo htmlspecialchars($rider['first_name']); ?>
                            </button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
        
        <?php if ($passenger_trip_ready && $selected_driver_row): ?>
        <div class="card" style="margin-bottom: 20px; border: 2px solid var(--uber-green);">
            <h2 style="margin: 0 0 8px 0;"><i class="fas fa-check"></i> Step 3 — Confirm your driver</h2>
            <p style="margin: 0 0 16px 0; color: #444;">
                <strong><?php echo htmlspecialchars($selected_driver_row['first_name'] . ' ' . $selected_driver_row['last_name']); ?></strong>
                · <?php echo htmlspecialchars(ucfirst($selected_driver_row['vehicle_type'])); ?>
                · <?php echo htmlspecialchars($selected_driver_row['vehicle_model']); ?>
                (<?php echo htmlspecialchars($selected_driver_row['vehicle_number']); ?>)
            </p>
            <form method="post" style="display: inline-block; margin-right: 10px;">
                <input type="hidden" name="action" value="clear_driver_selection">
                <button type="submit" style="padding: 10px 16px; border-radius: 8px; border: 1px solid #ccc; background: #fff; cursor: pointer;">Pick someone else</button>
            </form>
            <button type="button" class="btn-open-trip" style="padding: 10px 20px; border-radius: 8px; border: none; background: var(--uber-green); color: #000; font-weight: 700; cursor: pointer;">
                Continue to trip details
            </button>
        </div>
        <?php endif; ?>
        
        <!-- Active Rides Section (passenger: your name + track; driver details below) -->
        <?php if (!empty($active_rides) && $user_mode === 'passenger'): ?>
            <h2 style="margin: 24px 0 12px 0;"><i class="fas fa-road"></i> Your active rides</h2>
            <?php foreach ($active_rides as $ride): 
                if ((int) $ride['passenger_id'] !== (int) $user_id) {
                    continue;
                }
                $is_ai = isset($ride['driver_sid']) && strpos($ride['driver_sid'], 'AI') === 0;
            ?>
            <div class="active-ride-card" style="background: <?php echo $is_ai ? 'linear-gradient(135deg, #9b59b6, #8e44ad)' : 'linear-gradient(135deg, #667eea, #764ba2)'; ?>;">
                <div class="ride-status <?php echo $ride['status']; ?>" style="display: inline-block; padding: 5px 15px; border-radius: 20px; color: white; margin-bottom: 10px;">
                    <?php 
                    if ($ride['status'] == 'requested') echo '⏳ Waiting for driver';
                    elseif ($ride['status'] == 'accepted') echo '✅ Driver confirmed';
                    elseif ($ride['status'] == 'started') echo '🚗 Trip in progress';
                    ?>
                    <?php if ($is_ai): ?>
                        <span style="margin-left: 10px; background: rgba(255,255,255,0.3); padding: 2px 8px; border-radius: 12px; font-size: 0.7rem;">Demo</span>
                    <?php endif; ?>
                </div>
                
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px;">
                    <div>
                        <p style="margin: 0 0 4px 0; opacity: 0.85; font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.04em;">Passenger</p>
                        <h3 style="color: white; margin: 0 0 8px 0;">
                            <?php echo htmlspecialchars(($ride['passenger_first'] ?? '') . ' ' . ($ride['passenger_last'] ?? '')); ?>
                        </h3>
                        <p style="opacity: 0.95; margin: 0; font-size: 0.95rem;">
                            <i class="fas fa-user-tie"></i> Driver: <?php echo htmlspecialchars(($ride['driver_first'] ?? '') . ' ' . ($ride['driver_last'] ?? '')); ?>
                            · <?php echo htmlspecialchars(ucfirst($ride['vehicle_type'] ?? '')); ?> · <?php echo htmlspecialchars($ride['vehicle_model'] ?? ''); ?>
                        </p>
                    </div>
                    <a href="ride-tracking.php?ride_id=<?php echo $ride['id']; ?>" class="btn btn-light" style="color: #333; text-decoration: none; padding: 10px 20px; border-radius: 5px; background: white;">
                        <i class="fas fa-eye"></i> Track
                    </a>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>

    <?php elseif ($user_mode == 'rider'): ?>
        <!-- RIDER VIEW -->
        
        <?php if (!$is_rider): ?>
            <!-- Rider Registration Form -->
            <div class="card" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px;">
                <h2 style="color: white;"><i class="fas fa-id-card"></i> Register as a driver</h2>
                <p>Submit your vehicle details. Your profile is verified immediately.</p>
                
                <form method="post" style="background: white; color: #333; padding: 30px; border-radius: 10px; margin-top: 20px;">
                    <input type="hidden" name="action" value="become_rider">
                    
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="vehicle_type" style="display: block; margin-bottom: 5px;">Vehicle Type</label>
                        <select id="vehicle_type" name="vehicle_type" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" required>
                            <option value="">Select vehicle type</option>
                            <option value="car">Car 🚗</option>
                            <option value="motorcycle">Motorcycle 🏍️</option>
                            <option value="microbus">Microbus 🚐</option>
                        </select>
                    </div>
                    
                    <div class="form-group" style="margin-bottom: 15px;">
                        <label for="vehicle_model" style="display: block; margin-bottom: 5px;">Vehicle Model</label>
                        <input type="text" id="vehicle_model" name="vehicle_model" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" 
                               placeholder="e.g., Toyota Camry 2020" required>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                        <div class="form-group">
                            <label for="vehicle_number" style="display: block; margin-bottom: 5px;">Vehicle Number</label>
                            <input type="text" id="vehicle_number" name="vehicle_number" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" 
                                   placeholder="e.g., ABC-1234" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="license_number" style="display: block; margin-bottom: 5px;">License Number</label>
                            <input type="text" id="license_number" name="license_number" class="form-control" style="width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 5px;" 
                                   placeholder="e.g., LIC12345" required>
                        </div>
                    </div>
                    
                    <button type="submit" class="btn btn-success btn-block" style="width: 100%; padding: 15px; background: var(--uber-green); color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1rem;">
                        <i class="fas fa-check"></i> Register as Rider
                    </button>
                </form>
            </div>
        <?php else: ?>
            <?php if ($rider_profile): ?>
            <div class="card" style="background: #111; color: #fff; padding: 22px; border-radius: 12px; margin-bottom: 20px; border: 1px solid #333;">
                <h2 style="margin: 0 0 6px 0; color: #fff; font-size: 1.35rem;"><i class="fas fa-id-card" style="color: var(--uber-green);"></i> Verified driver</h2>
                <p style="margin: 0 0 18px 0; opacity: 0.88; font-size: 0.95rem;">
                    <?php echo htmlspecialchars($rider_profile['first_name'] . ' ' . $rider_profile['last_name']); ?>
                    <span style="opacity: 0.65;"> · <?php echo htmlspecialchars($rider_profile['student_id'] ?? ''); ?></span>
                </p>
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 14px; padding-top: 14px; border-top: 1px solid #333;">
                    <div><span style="font-size: 0.7rem; text-transform: uppercase; opacity: 0.5;">Vehicle</span><br><strong><?php echo htmlspecialchars(ucfirst($rider_profile['vehicle_type'])); ?></strong></div>
                    <div><span style="font-size: 0.7rem; text-transform: uppercase; opacity: 0.5;">Model</span><br><strong><?php echo htmlspecialchars($rider_profile['vehicle_model'] ?? ''); ?></strong></div>
                    <div><span style="font-size: 0.7rem; text-transform: uppercase; opacity: 0.5;">Number</span><br><strong><?php echo htmlspecialchars($rider_profile['vehicle_number'] ?? ''); ?></strong></div>
                    <div><span style="font-size: 0.7rem; text-transform: uppercase; opacity: 0.5;">License</span><br><strong><?php echo htmlspecialchars($rider_profile['license_number'] ?? ''); ?></strong></div>
                </div>
            </div>
            <?php endif; ?>

            <div class="card" style="background: linear-gradient(135deg, #2c3e50, #34495e); color: #fff; padding: 20px; border-radius: 12px; margin-bottom: 24px;">
                <h3 style="margin: 0 0 10px 0;"><i class="fas fa-info-circle"></i> Driver trips</h3>
                <p style="margin: 0; line-height: 1.55; opacity: 0.95;">
                    Your name appears on each trip as the driver. Passenger bookings may need a quick confirmation before you start.
                </p>
            </div>
            
            <?php if (!empty($ride_requests)): ?>
                <h2><i class="fas fa-route"></i> Your trips</h2>
                <?php foreach ($ride_requests as $ride): ?>
                <div class="active-ride-card" style="background: linear-gradient(135deg, #667eea, #764ba2); color: white; padding: 20px; border-radius: 10px; margin-bottom: 15px;">
                    <div class="ride-status <?php echo $ride['status']; ?>" style="display: inline-block; padding: 5px 15px; border-radius: 20px; background: rgba(255,255,255,0.2); margin-bottom: 10px;">
                        <?php if ($ride['status'] === 'requested'): ?>
                            Pending confirmation
                        <?php else: ?>
                            <?php echo ucfirst($ride['status']); ?>
                        <?php endif; ?>
                    </div>
                    
                    <div style="display: flex; justify-content: space-between; flex-wrap: wrap; gap: 16px;">
                        <div>
                            <p style="margin: 0 0 4px 0; opacity: 0.85; font-size: 0.8rem; text-transform: uppercase;">Driver (you)</p>
                            <h3 style="color: white; margin: 0 0 10px 0;"><?php echo htmlspecialchars(($rider_profile['first_name'] ?? '') . ' ' . ($rider_profile['last_name'] ?? '')); ?></h3>
                            <p style="opacity: 0.95; margin: 0 0 8px 0;"><i class="fas fa-user"></i> Passenger: <?php echo htmlspecialchars($ride['first_name'] . ' ' . $ride['last_name']); ?></p>
                            <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($ride['pickup_location']); ?></p>
                            <p><i class="fas fa-flag-checkered"></i> <?php echo htmlspecialchars($ride['dropoff_location']); ?></p>
                            <p style="font-size: 1.2rem; font-weight: bold;">$<?php echo number_format($ride['fare'], 2); ?></p>
                        </div>
                        <div style="display: flex; flex-direction: column; gap: 10px;">
                            <?php if ($ride['status'] == 'requested'): ?>
                                <span style="padding: 10px 14px; background: rgba(0,0,0,0.25); border-radius: 8px; font-size: 0.9rem;">Waiting for confirmation to drive.</span>
                            <?php elseif ($ride['status'] == 'accepted'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="start_ride">
                                    <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                                    <button type="submit" class="btn btn-primary" style="padding: 8px 15px; background: #3498db; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                        <i class="fas fa-play"></i> Start trip
                                    </button>
                                </form>
                            <?php elseif ($ride['status'] == 'started'): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="complete_ride">
                                    <input type="hidden" name="ride_id" value="<?php echo $ride['id']; ?>">
                                    <button type="submit" class="btn btn-warning" style="padding: 8px 15px; background: #f39c12; color: white; border: none; border-radius: 5px; cursor: pointer;">
                                        <i class="fas fa-flag-checkered"></i> Complete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div class="card" style="background: white; padding: 40px; text-align: center; border-radius: 10px;">
                    <i class="fas fa-inbox fa-4x" style="color: #ddd;"></i>
                    <h3>No active trips</h3>
                    <p style="color: #666; max-width: 420px; margin: 12px auto 0;">When someone books you, the trip will show here.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Ride Request Modal -->
<div class="modal" id="rideRequestModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000; justify-content: center; align-items: center;">
    <div class="modal-content" style="background: white; padding: 30px; border-radius: 15px; max-width: 600px; width: 90%; max-height: 90vh; overflow-y: auto; position: relative;">
        <span class="close-modal" style="position: absolute; top: 15px; right: 15px; font-size: 1.5rem; cursor: pointer;">&times;</span>
        <h2 id="modalTitle"><i class="fas fa-taxi" style="color: var(--uber-green);"></i> Step 4 — Trip details</h2>
        <p id="modalRiderName"><?php
            if (!empty($selected_driver_row)) {
                echo '<i class="fas fa-user"></i> Driver: <strong>' . htmlspecialchars($selected_driver_row['first_name'] . ' ' . $selected_driver_row['last_name']) . '</strong>';
            } else {
                echo 'Select a driver first.';
            }
        ?></p>
        <div id="aiNotice" style="display: <?php echo (!empty($selected_driver_row) && strpos($selected_driver_row['student_id'], 'AI') === 0) ? 'block' : 'none'; ?>; background: #f0f7ff; padding: 10px; border-radius: 5px; margin: 10px 0;">
            <i class="fas fa-robot" style="color: var(--ai-purple);"></i> 
            <strong>Demo driver:</strong> This trip is auto-confirmed for practice (no admin step).
        </div>
        
        <form method="post" id="rideRequestForm">
            <input type="hidden" name="action" value="request_ride">
            <input type="hidden" name="rider_id" id="riderId" value="<?php echo !empty($selected_driver_row) ? (int) $selected_driver_row['id'] : ''; ?>">
            <input type="hidden" name="pickup_lat" id="pickup_lat" value="<?php echo $passenger_has_location ? htmlspecialchars((string) $passenger_lat) : (string) UBER_CAMPUS_LAT; ?>">
            <input type="hidden" name="pickup_lng" id="pickup_lng" value="<?php echo $passenger_has_location ? htmlspecialchars((string) $passenger_lng) : (string) UBER_CAMPUS_LNG; ?>">
            <input type="hidden" name="dropoff_lat" id="dropoff_lat" value="23.7800">
            <input type="hidden" name="dropoff_lng" id="dropoff_lng" value="90.4200">
            <input type="hidden" name="fare" id="fareAmount">
            <input type="hidden" name="distance" id="distanceAmount">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label><i class="fas fa-circle" style="color: var(--uber-green);"></i> Pickup (campus gate)</label>
                <input type="text" id="modal_pickup" name="pickup_location" class="form-control" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px; background: #f8f9fa;" 
                       value="<?php echo htmlspecialchars($passenger_label ?: 'University Main Gate'); ?>" required readonly>
            </div>
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label><i class="fas fa-flag-checkered" style="color: #e74c3c;"></i> Destination</label>
                <input type="text" id="modal_dropoff" name="dropoff_location" class="form-control" style="width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 5px; margin-top: 5px;" 
                       placeholder="Where to?" value="<?php echo htmlspecialchars($passenger_dropoff); ?>" required>
            </div>
            
            <div style="background: #f8f9fa; padding: 20px; border-radius: 8px; margin: 20px 0;">
                <h4 style="margin: 0 0 15px 0;">Fare Estimate</h4>
                <div style="display: flex; justify-content: space-between; margin-bottom: 10px;">
                    <span>Distance:</span>
                    <span id="estimate_distance">2.5 km</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-size: 1.2rem; font-weight: bold; margin-top: 10px;">
                    <span>Total:</span>
                    <span id="estimate_fare">$7.50</span>
                </div>
            </div>
            
            <button type="submit" class="btn btn-success btn-block" id="confirmBtn" style="width: 100%; padding: 15px; background: var(--uber-green); color: white; border: none; border-radius: 5px; cursor: pointer; font-size: 1rem;">
                <i class="fas fa-check"></i> Confirm & Request Ride
            </button>
        </form>
    </div>
</div>

<script>
(function() {
    function openTripModal() {
        var modal = document.getElementById('rideRequestModal');
        var riderId = document.getElementById('riderId');
        if (!riderId || !riderId.value) {
            alert('Choose a driver first (steps 2–3).');
            return;
        }
        var distance = (Math.random() * 4 + 1.5).toFixed(1);
        var fare = (parseFloat(distance) * 2 + 3).toFixed(2);
        document.getElementById('estimate_distance').textContent = distance + ' km';
        document.getElementById('estimate_fare').textContent = '$' + fare;
        document.getElementById('fareAmount').value = fare;
        document.getElementById('distanceAmount').value = distance;
        if (!document.getElementById('modal_dropoff').value) {
            document.getElementById('modal_dropoff').placeholder = 'Where to?';
        }
        modal.style.display = 'flex';
    }
    document.querySelectorAll('.btn-open-trip').forEach(function(btn) {
        btn.addEventListener('click', openTripModal);
    });

    document.querySelector('.close-modal').addEventListener('click', function() {
        document.getElementById('rideRequestModal').style.display = 'none';
    });
    window.addEventListener('click', function(event) {
        if (event.target === document.getElementById('rideRequestModal')) {
            document.getElementById('rideRequestModal').style.display = 'none';
        }
    });
})();
</script>

<!-- Toggle Switch Styles -->
<style>
.switch {
  position: relative;
  display: inline-block;
  width: 60px;
  height: 34px;
}
.switch input {
  opacity: 0;
  width: 0;
  height: 0;
}
.slider {
  position: absolute;
  cursor: pointer;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background-color: #ccc;
  transition: .4s;
}
.slider:before {
  position: absolute;
  content: "";
  height: 26px;
  width: 26px;
  left: 4px;
  bottom: 4px;
  background-color: white;
  transition: .4s;
}
input:checked + .slider {
  background-color: #2ecc71;
}
input:focus + .slider {
  box-shadow: 0 0 1px #2ecc71;
}
input:checked + .slider:before {
  transform: translateX(26px);
}
.slider.round {
  border-radius: 34px;
}
.slider.round:before {
  border-radius: 50%;
}

.active-ride-card {
    color: white;
    border-radius: 15px;
    padding: 25px;
    margin-bottom: 20px;
    position: relative;
    overflow: hidden;
}

.ride-status {
    display: inline-block;
    padding: 8px 20px;
    border-radius: 25px;
    font-weight: bold;
    margin-bottom: 15px;
}

.ride-status.requested { background: #f39c12; }
.ride-status.accepted { background: #3498db; }
.ride-status.started { background: #2ecc71; }
</style>

<?php require_once '../includes/footer.php'; ?>