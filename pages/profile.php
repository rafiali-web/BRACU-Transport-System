<?php
/**
 * Enhanced User Profile Page
 */

require_once '../includes/config.php';

// Check if user is logged in
if (!isset($_SESSION['loggedin']) || $_SESSION['loggedin'] !== true) {
    header("Location: login.php");
    exit;
}

$user_id = $_SESSION['id'];
$success = '';
$error = '';

// Handle profile update
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_profile'])) {
        // Update basic info
        $first_name = $_POST['first_name'];
        $last_name = $_POST['last_name'];
        $email = $_POST['email'];
        $phone = $_POST['phone'];
        $address = $_POST['address'];
        $bio = $_POST['bio'];
        $emergency_contact_name = $_POST['emergency_contact_name'];
        $emergency_contact_phone = $_POST['emergency_contact_phone'];
        
        try {
            $stmt = $pdo->prepare("
                UPDATE users SET 
                    first_name = ?, last_name = ?, email = ?, 
                    phone = ?, address = ?, bio = ?,
                    emergency_contact_name = ?, emergency_contact_phone = ?
                WHERE id = ?
            ");
            $stmt->execute([$first_name, $last_name, $email, $phone, $address, $bio, 
                           $emergency_contact_name, $emergency_contact_phone, $user_id]);
            
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name'] = $last_name;
            
            $success = "Profile updated successfully!";
        } catch (Exception $e) {
            $error = "Update failed: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['update_settings'])) {
        // Update settings
        $email_notifications = isset($_POST['email_notifications']) ? 1 : 0;
        $sms_notifications = isset($_POST['sms_notifications']) ? 1 : 0;
        $push_notifications = isset($_POST['push_notifications']) ? 1 : 0;
        $dark_mode = isset($_POST['dark_mode']) ? 1 : 0;
        $language = $_POST['language'];
        
        try {
            $pdo->prepare('INSERT OR IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$user_id]);
            $stmt = $pdo->prepare("
                UPDATE user_settings SET 
                    email_notifications = ?, sms_notifications = ?, 
                    push_notifications = ?, dark_mode = ?, language = ?,
                    updated_at = CURRENT_TIMESTAMP
                WHERE user_id = ?
            ");
            $stmt->execute([$email_notifications, $sms_notifications, $push_notifications, 
                           $dark_mode, $language, $user_id]);
            $_SESSION['theme_dark'] = ((int) $dark_mode === 1);
            
            $_SESSION['success'] = 'Settings updated successfully!';
            header('Location: profile.php');
            exit;
        } catch (Exception $e) {
            $error = "Settings update failed: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['add_favorite'])) {
        // Add favorite location
        $location_name = $_POST['location_name'];
        $address = $_POST['address'];
        $location_type = $_POST['location_type'];
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO user_favorites (user_id, location_name, address, location_type)
                VALUES (?, ?, ?, ?)
            ");
            $stmt->execute([$user_id, $location_name, $address, $location_type]);
            
            $success = "Favorite location added!";
        } catch (Exception $e) {
            $error = "Failed to add favorite: " . $e->getMessage();
        }
    }
    
    if (isset($_POST['delete_favorite'])) {
        // Delete favorite location
        $fav_id = $_POST['favorite_id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM user_favorites WHERE id = ? AND user_id = ?");
            $stmt->execute([$fav_id, $user_id]);
            
            $success = "Favorite removed!";
        } catch (Exception $e) {
            $error = "Failed to remove favorite: " . $e->getMessage();
        }
    }
}

// Get user data
$user_stmt = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$user_stmt->execute([$user_id]);
$user = $user_stmt->fetch();

// Get user settings (ensure row exists so toggles save correctly)
try {
    $pdo->prepare('INSERT OR IGNORE INTO user_settings (user_id) VALUES (?)')->execute([$user_id]);
} catch (Throwable $e) {
    // user_settings table may be missing until schema update
}
$settings_stmt = $pdo->prepare("SELECT * FROM user_settings WHERE user_id = ?");
$settings_stmt->execute([$user_id]);
$settings = $settings_stmt->fetch() ?: [];

// Get favorite locations
$favorites_stmt = $pdo->prepare("SELECT * FROM user_favorites WHERE user_id = ? ORDER BY created_at DESC");
$favorites_stmt->execute([$user_id]);
$favorites = $favorites_stmt->fetchAll();

// Get user ratings
$ratings_stmt = $pdo->prepare("
    SELECT ur.*, u.first_name, u.last_name 
    FROM user_ratings ur
    JOIN users u ON ur.rater_id = u.id
    WHERE ur.rated_user_id = ?
    ORDER BY ur.created_at DESC
");
$ratings_stmt->execute([$user_id]);
$ratings = $ratings_stmt->fetchAll();

// Calculate average rating
$avg_rating = 0;
if (count($ratings) > 0) {
    $sum = 0;
    foreach ($ratings as $r) {
        $sum += $r['rating'];
    }
    $avg_rating = round($sum / count($ratings), 1);
}

// Get ride statistics
$ride_stats = [
    'total' => $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE user_id = ?")->execute([$user_id]) ? 
               $pdo->query("SELECT COUNT(*) FROM bookings WHERE user_id = $user_id")->fetchColumn() : 0,
    'uber' => $pdo->prepare("SELECT COUNT(*) FROM uber_rides WHERE passenger_id = ?")->execute([$user_id]) ? 
              $pdo->query("SELECT COUNT(*) FROM uber_rides WHERE passenger_id = $user_id")->fetchColumn() : 0,
    'rides' => $pdo->prepare("SELECT COUNT(*) FROM ride_share_participants WHERE user_id = ?")->execute([$user_id]) ? 
               $pdo->query("SELECT COUNT(*) FROM ride_share_participants WHERE user_id = $user_id")->fetchColumn() : 0
];

// Riding history (Uber as passenger/driver + ride shares)
$riding_history = [];
try {
    $qh = $pdo->prepare("
        SELECT 'Uber · passenger' AS svc, pickup_location AS loc_from, dropoff_location AS loc_to, fare, status, booking_time, end_time
        FROM uber_rides WHERE passenger_id = ? ORDER BY datetime(COALESCE(end_time, booking_time)) DESC LIMIT 15
    ");
    $qh->execute([$user_id]);
    foreach ($qh->fetchAll() as $row) {
        $row['sort'] = $row['end_time'] ?: $row['booking_time'];
        $riding_history[] = $row;
    }
    $qh2 = $pdo->prepare("
        SELECT 'Uber · driver' AS svc, ur.pickup_location AS loc_from, ur.dropoff_location AS loc_to, ur.fare, ur.status, ur.booking_time, ur.end_time
        FROM uber_rides ur
        INNER JOIN riders rr ON rr.id = ur.rider_id AND rr.user_id = ?
        ORDER BY datetime(COALESCE(ur.end_time, ur.booking_time)) DESC LIMIT 15
    ");
    $qh2->execute([$user_id]);
    foreach ($qh2->fetchAll() as $row) {
        $row['sort'] = $row['end_time'] ?: $row['booking_time'];
        $riding_history[] = $row;
    }
    $qh3 = $pdo->prepare("
        SELECT 'Ride share' AS svc, rs.from_location AS loc_from, rs.to_location AS loc_to,
               (rs.fare_per_person * rsp.seats_booked) AS fare, rsp.status, rsp.booking_time, rs.departure_time AS end_time
        FROM ride_share_participants rsp
        INNER JOIN ride_shares rs ON rs.id = rsp.ride_share_id
        WHERE rsp.user_id = ?
        ORDER BY datetime(COALESCE(rs.departure_time, rsp.booking_time)) DESC LIMIT 15
    ");
    $qh3->execute([$user_id]);
    foreach ($qh3->fetchAll() as $row) {
        $row['sort'] = $row['end_time'] ?: $row['booking_time'];
        $riding_history[] = $row;
    }
    usort($riding_history, function ($a, $b) {
        return strtotime($b['sort'] ?? '1970-01-01') <=> strtotime($a['sort'] ?? '1970-01-01');
    });
    $riding_history = array_slice($riding_history, 0, 30);
} catch (Throwable $e) {
    $riding_history = [];
}

require_once '../includes/header.php';
?>

<style>
.profile-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 40px;
    border-radius: 15px;
    margin-bottom: 30px;
    position: relative;
    overflow: hidden;
}

.profile-header::after {
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

.profile-avatar {
    width: 120px;
    height: 120px;
    border-radius: 50%;
    border: 4px solid white;
    box-shadow: 0 5px 20px rgba(0,0,0,0.2);
    margin-bottom: 20px;
    background: linear-gradient(135deg, #ff6b6b, #feca57);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 3rem;
    color: white;
    font-weight: bold;
}

.profile-name {
    font-size: 2rem;
    font-weight: bold;
    margin-bottom: 5px;
}

.profile-badge {
    display: inline-block;
    padding: 5px 15px;
    border-radius: 20px;
    background: rgba(255,255,255,0.2);
    margin: 5px;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin: 30px 0;
}

.stat-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    text-align: center;
    box-shadow: 0 5px 15px rgba(0,0,0,0.1);
    transition: transform 0.3s;
}

.stat-card:hover {
    transform: translateY(-5px);
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

.profile-tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #eee;
    padding-bottom: 10px;
}

.profile-tab {
    padding: 10px 20px;
    cursor: pointer;
    border-radius: 5px;
    transition: all 0.3s;
    font-weight: 500;
}

.profile-tab:hover {
    background: #f0f0f0;
}

.profile-tab.active {
    background: #667eea;
    color: white;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
    animation: fadeIn 0.5s;
}

@keyframes fadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}

.favorite-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 15px;
    border: 1px solid #eee;
    border-radius: 8px;
    margin-bottom: 10px;
    transition: all 0.3s;
}

.favorite-item:hover {
    background: #f8f9fa;
    border-color: #667eea;
}

.favorite-type {
    padding: 3px 10px;
    border-radius: 15px;
    font-size: 0.8rem;
    font-weight: bold;
}

.favorite-type.home { background: #4CAF50; color: white; }
.favorite-type.work { background: #2196F3; color: white; }
.favorite-type.school { background: #9C27B0; color: white; }
.favorite-type.other { background: #FF9800; color: white; }

.rating-stars {
    color: #FFD700;
    font-size: 1.2rem;
}

.review-item {
    padding: 15px;
    border-left: 3px solid #667eea;
    background: #f8f9fa;
    margin-bottom: 15px;
    border-radius: 0 8px 8px 0;
}

.review-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.reviewer-name {
    font-weight: bold;
    color: #333;
}

.review-date {
    color: #999;
    font-size: 0.9rem;
}

.settings-group {
    margin-bottom: 25px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.settings-group h3 {
    margin-top: 0;
    color: #333;
    border-bottom: 1px solid #ddd;
    padding-bottom: 10px;
    margin-bottom: 15px;
}

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
    border-radius: 34px;
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
    border-radius: 50%;
}

input:checked + .slider {
    background-color: #667eea;
}

input:focus + .slider {
    box-shadow: 0 0 1px #667eea;
}

input:checked + .slider:before {
    transform: translateX(26px);
}

.setting-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 0;
    border-bottom: 1px solid #eee;
}

.setting-item:last-child {
    border-bottom: none;
}

.avatar-upload {
    position: relative;
    display: inline-block;
}

.avatar-upload input {
    display: none;
}

.avatar-upload label {
    position: absolute;
    bottom: 0;
    right: 0;
    background: #667eea;
    color: white;
    width: 35px;
    height: 35px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    border: 2px solid white;
    transition: all 0.3s;
}

.avatar-upload label:hover {
    background: #5a67d8;
    transform: scale(1.1);
}

@media (max-width: 768px) {
    .profile-tabs {
        flex-wrap: wrap;
    }
    
    .profile-tab {
        flex: 1 1 auto;
        text-align: center;
    }
}

/* Dark mode — profile page (overrides strong light backgrounds above) */
body.theme-dark .stat-card {
    background: #1e232d !important;
    color: #e4e6eb;
    box-shadow: 0 5px 15px rgba(0,0,0,0.35);
}
body.theme-dark .stat-value { color: #a5b4fc !important; }
body.theme-dark .stat-label { color: #9ca3af !important; }
body.theme-dark .profile-tabs { border-bottom-color: #3d4655 !important; }
body.theme-dark .profile-tab:hover { background: #252b36 !important; }
body.theme-dark .settings-group {
    background: #252b36 !important;
    color: #e4e6eb;
}
body.theme-dark .settings-group h3 {
    color: #f0f2f5 !important;
    border-bottom-color: #3d4655 !important;
}
body.theme-dark .setting-item {
    border-bottom-color: #3d4655 !important;
    color: #e4e6eb;
}
body.theme-dark .review-item {
    background: #252b36 !important;
    border-left-color: #667eea;
}
body.theme-dark .reviewer-name { color: #e4e6eb !important; }
body.theme-dark .favorite-item {
    border-color: #3d4655 !important;
    color: #e4e6eb;
}
body.theme-dark .favorite-item:hover {
    background: #2d3544 !important;
}
</style>

<div class="container">
    <!-- Success/Error Messages -->
    <?php if ($success): ?>
        <div class="notification success"><?php echo $success; ?></div>
    <?php endif; ?>
    
    <?php if ($error): ?>
        <div class="notification error"><?php echo $error; ?></div>
    <?php endif; ?>

    <!-- Profile Header -->
    <div class="profile-header">
        <div style="position: relative; z-index: 1;">
            <div style="display: flex; align-items: center; gap: 30px; flex-wrap: wrap;">
                <div class="avatar-upload">
                    <div class="profile-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                    <label for="avatar-upload" title="Change avatar">
                        <i class="fas fa-camera"></i>
                    </label>
                    <input type="file" id="avatar-upload" accept="image/*">
                </div>
                
                <div>
                    <div class="profile-name">
                        <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>
                    </div>
                    <div>
                        <span class="profile-badge">
                            <i class="fas fa-star"></i> <?php echo $avg_rating; ?> Rating
                        </span>
                        <span class="profile-badge">
                            <i class="fas fa-road"></i> <?php echo array_sum($ride_stats); ?> Rides
                        </span>
                        <span class="profile-badge">
                            <i class="fas fa-clock"></i> Member since <?php echo date('M Y', strtotime($user['created_at'])); ?>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Stats Grid -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?php echo $ride_stats['total']; ?></div>
            <div class="stat-label">Bus Bookings</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $ride_stats['uber']; ?></div>
            <div class="stat-label">Uber Rides</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $ride_stats['rides']; ?></div>
            <div class="stat-label">Ride Shares</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?php echo $user['total_points']; ?></div>
            <div class="stat-label">Total Points</div>
        </div>
    </div>

    <div class="card" style="margin-bottom: 28px;">
        <h2 style="margin-top: 0;"><i class="fas fa-route"></i> Riding history</h2>
        <p style="color: #666; margin-top: 0;">Trips you used on campus transport: Uber (as passenger or driver) and ride shares you joined.</p>
        <?php if (empty($riding_history)): ?>
            <p style="color: #999; text-align: center; padding: 24px 0;">No trips recorded yet.</p>
        <?php else: ?>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse; font-size: 0.95rem;">
                    <thead>
                        <tr style="text-align: left; border-bottom: 2px solid #eee;">
                            <th style="padding: 10px 8px;">Type</th>
                            <th style="padding: 10px 8px;">From → To</th>
                            <th style="padding: 10px 8px;">When</th>
                            <th style="padding: 10px 8px;">Status</th>
                            <th style="padding: 10px 8px;">Fare</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($riding_history as $rh): ?>
                        <tr style="border-bottom: 1px solid #f0f0f0;">
                            <td style="padding: 10px 8px; white-space: nowrap;"><?php echo htmlspecialchars($rh['svc']); ?></td>
                            <td style="padding: 10px 8px;">
                                <strong><?php echo htmlspecialchars($rh['loc_from']); ?></strong>
                                <span style="color: #999;"> → </span>
                                <strong><?php echo htmlspecialchars($rh['loc_to']); ?></strong>
                            </td>
                            <td style="padding: 10px 8px; color: #555; white-space: nowrap;">
                                <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($rh['sort'] ?? $rh['booking_time'] ?? 'now'))); ?>
                            </td>
                            <td style="padding: 10px 8px;"><?php echo htmlspecialchars(ucfirst((string) ($rh['status'] ?? ''))); ?></td>
                            <td style="padding: 10px 8px;">$<?php echo number_format((float) ($rh['fare'] ?? 0), 2); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Profile Tabs -->
    <div class="profile-tabs">
        <div class="profile-tab active" onclick="showTab('info')">
            <i class="fas fa-user"></i> Personal Info
        </div>
        <div class="profile-tab" onclick="showTab('favorites')">
            <i class="fas fa-heart"></i> Favorites
        </div>
        <div class="profile-tab" onclick="showTab('reviews')">
            <i class="fas fa-star"></i> Reviews
        </div>
        <div class="profile-tab" onclick="showTab('settings')">
            <i class="fas fa-cog"></i> Settings
        </div>
        <div class="profile-tab" onclick="showTab('security')">
            <i class="fas fa-shield-alt"></i> Security
        </div>
    </div>

    <!-- Tab: Personal Info -->
    <div id="tab-info" class="tab-content active">
        <div class="card">
            <h2>Personal Information</h2>
            <form method="post">
                <input type="hidden" name="update_profile" value="1">
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="form-control" 
                               value="<?php echo htmlspecialchars($user['first_name']); ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="form-control" 
                               value="<?php echo htmlspecialchars($user['last_name']); ?>" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" class="form-control" 
                           value="<?php echo htmlspecialchars($user['email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" class="form-control" 
                           value="<?php echo htmlspecialchars($user['phone'] ?? ''); ?>" 
                           placeholder="+880 1XXXXXXXXX">
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <textarea name="address" class="form-control" rows="2" 
                              placeholder="Your address"><?php echo htmlspecialchars($user['address'] ?? ''); ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Bio / About Me</label>
                    <textarea name="bio" class="form-control" rows="3" 
                              placeholder="Tell us a bit about yourself"><?php echo htmlspecialchars($user['bio'] ?? ''); ?></textarea>
                </div>
                
                <h3 style="margin-top: 30px;">Emergency Contact</h3>
                
                <div class="form-row" style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                    <div class="form-group">
                        <label>Contact Name</label>
                        <input type="text" name="emergency_contact_name" class="form-control" 
                               value="<?php echo htmlspecialchars($user['emergency_contact_name'] ?? ''); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label>Contact Phone</label>
                        <input type="tel" name="emergency_contact_phone" class="form-control" 
                               value="<?php echo htmlspecialchars($user['emergency_contact_phone'] ?? ''); ?>">
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Changes</button>
            </form>
        </div>
    </div>

    <!-- Tab: Favorites -->
    <div id="tab-favorites" class="tab-content">
        <div class="card">
            <h2>Favorite Locations</h2>
            
            <!-- Add new favorite form -->
            <form method="post" style="margin-bottom: 30px; background: #f8f9fa; padding: 20px; border-radius: 8px;">
                <input type="hidden" name="add_favorite" value="1">
                
                <h3>Add New Favorite</h3>
                
                <div class="form-group">
                    <label>Location Name</label>
                    <input type="text" name="location_name" class="form-control" 
                           placeholder="e.g., Home, Work, Gym" required>
                </div>
                
                <div class="form-group">
                    <label>Address</label>
                    <input type="text" name="address" class="form-control" 
                           placeholder="Full address" required>
                </div>
                
                <div class="form-group">
                    <label>Location Type</label>
                    <select name="location_type" class="form-control">
                        <option value="home">Home 🏠</option>
                        <option value="work">Work 💼</option>
                        <option value="school">School 🎓</option>
                        <option value="other">Other 📍</option>
                    </select>
                </div>
                
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-plus"></i> Add Favorite
                </button>
            </form>
            
            <!-- List of favorites -->
            <?php if (count($favorites) > 0): ?>
                <?php foreach ($favorites as $fav): ?>
                <div class="favorite-item">
                    <div style="display: flex; align-items: center; gap: 15px;">
                        <span class="favorite-type <?php echo $fav['location_type']; ?>">
                            <?php 
                            if ($fav['location_type'] == 'home') echo '🏠';
                            elseif ($fav['location_type'] == 'work') echo '💼';
                            elseif ($fav['location_type'] == 'school') echo '🎓';
                            else echo '📍';
                            ?>
                        </span>
                        <div>
                            <strong><?php echo htmlspecialchars($fav['location_name']); ?></strong>
                            <br>
                            <small><?php echo htmlspecialchars($fav['address']); ?></small>
                        </div>
                    </div>
                    
                    <form method="post" onsubmit="return confirm('Remove this favorite?');">
                        <input type="hidden" name="delete_favorite" value="1">
                        <input type="hidden" name="favorite_id" value="<?php echo $fav['id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                            <i class="fas fa-trash"></i>
                        </button>
                    </form>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No favorite locations yet. Add your first favorite above!</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Reviews -->
    <div id="tab-reviews" class="tab-content">
        <div class="card">
            <h2>Reviews & Ratings</h2>
            
            <?php if (count($ratings) > 0): ?>
                <?php foreach ($ratings as $rating): ?>
                <div class="review-item">
                    <div class="review-header">
                        <span class="reviewer-name">
                            <?php echo htmlspecialchars($rating['first_name'] . ' ' . $rating['last_name']); ?>
                        </span>
                        <span class="review-date">
                            <?php echo date('M j, Y', strtotime($rating['created_at'])); ?>
                        </span>
                    </div>
                    
                    <div class="rating-stars">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                            <?php if ($i <= $rating['rating']): ?>
                                <i class="fas fa-star"></i>
                            <?php else: ?>
                                <i class="far fa-star"></i>
                            <?php endif; ?>
                        <?php endfor; ?>
                    </div>
                    
                    <?php if ($rating['review']): ?>
                        <p style="margin-top: 10px;"><?php echo htmlspecialchars($rating['review']); ?></p>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>No reviews yet.</p>
            <?php endif; ?>
        </div>
    </div>

    <!-- Tab: Settings -->
    <div id="tab-settings" class="tab-content">
        <div class="card">
            <h2>Preferences</h2>
            
            <form method="post">
                <input type="hidden" name="update_settings" value="1">
                
                <div class="settings-group">
                    <h3>Notifications</h3>
                    
                    <div class="setting-item">
                        <span>Email Notifications</span>
                        <label class="switch">
                            <input type="checkbox" name="email_notifications" 
                                   <?php echo ($settings['email_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-item">
                        <span>SMS Notifications</span>
                        <label class="switch">
                            <input type="checkbox" name="sms_notifications" 
                                   <?php echo ($settings['sms_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-item">
                        <span>Push Notifications</span>
                        <label class="switch">
                            <input type="checkbox" name="push_notifications" 
                                   <?php echo ($settings['push_notifications'] ?? 1) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
                
                <div class="settings-group">
                    <h3>Appearance</h3>
                    
                    <div class="setting-item">
                        <span>Dark Mode</span>
                        <label class="switch">
                            <input type="checkbox" name="dark_mode" 
                                   <?php echo ($settings['dark_mode'] ?? 0) ? 'checked' : ''; ?>>
                            <span class="slider"></span>
                        </label>
                    </div>
                    
                    <div class="setting-item">
                        <span>Language</span>
                        <select name="language" class="form-control" style="width: auto;">
                            <option value="en" <?php echo ($settings['language'] ?? 'en') == 'en' ? 'selected' : ''; ?>>English</option>
                            <option value="bn" <?php echo ($settings['language'] ?? 'en') == 'bn' ? 'selected' : ''; ?>>Bengali</option>
                            <option value="es" <?php echo ($settings['language'] ?? 'en') == 'es' ? 'selected' : ''; ?>>Spanish</option>
                        </select>
                    </div>
                </div>
                
                <button type="submit" class="btn btn-primary">Save Settings</button>
            </form>
        </div>
    </div>

    <!-- Tab: Security -->
    <div id="tab-security" class="tab-content">
        <div class="card">
            <h2>Security Settings</h2>
            
            <div class="settings-group">
                <h3>Change Password</h3>
                
                <form method="post" action="change-password.php">
                    <div class="form-group">
                        <label>Current Password</label>
                        <input type="password" name="current_password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>New Password</label>
                        <input type="password" name="new_password" class="form-control" required>
                    </div>
                    
                    <div class="form-group">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" class="form-control" required>
                    </div>
                    
                    <button type="submit" class="btn btn-primary">Update Password</button>
                </form>
            </div>
            
            <div class="settings-group">
                <h3>Two-Factor Authentication</h3>
                <p>Enhance your account security with 2FA.</p>
                <button class="btn btn-primary">Enable 2FA</button>
            </div>
            
            <div class="settings-group">
                <h3>Active Sessions</h3>
                <p>You are currently logged in on this device.</p>
                <button class="btn btn-danger">Log Out All Devices</button>
            </div>
        </div>
    </div>
</div>

<script>
function showTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.profile-tab').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById('tab-' + tabName).classList.add('active');
    
    // Add active class to clicked tab
    event.target.classList.add('active');
}

// Avatar upload preview (simulated)
document.getElementById('avatar-upload').addEventListener('change', function(e) {
    if (this.files && this.files[0]) {
        alert('Avatar upload functionality would be implemented here.\nFile selected: ' + this.files[0].name);
        // In a real implementation, you'd upload via AJAX
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>