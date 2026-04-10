<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>University Bus Booking System</title>
    <link rel="stylesheet" href="<?php echo BASE_URL; ?>css/style.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    
    <!-- Loading overlay -->
    <div id="loadingOverlay" style="position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(255,255,255,0.9); z-index: 9999; display: flex; justify-content: center; align-items: center; transition: opacity 0.3s ease;">
        <div class="spinner" style="border: 4px solid rgba(0, 0, 0, 0.1); border-left: 4px solid var(--primary); border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite;"></div>
    </div>

    <script>
    // Hide loading overlay when page is fully loaded
    window.addEventListener('load', function() {
        setTimeout(function() {
            document.getElementById('loadingOverlay').style.opacity = '0';
            setTimeout(function() {
                document.getElementById('loadingOverlay').style.display = 'none';
            }, 300);
        }, 500);
    });
    </script>
    <!-- Header Section -->
    <header>
        <div class="container">
            <nav>
                <div class="logo">
                    <i class="fas fa-bus"></i>
                    <span>BRACU Transport</span>
                </div>
                <div class="nav-links">
                    <a href="<?php echo BASE_URL; ?>index.php"><i class="fas fa-home"></i> Home</a>
                    <a href="<?php echo BASE_URL; ?>pages/routes.php"><i class="fas fa-route"></i> Routes</a>
                    <a href="<?php echo BASE_URL; ?>pages/uber.php"><i class="fas fa-car"></i> Uber</a>
                    <a href="<?php echo BASE_URL; ?>pages/ride-sharing.php"><i class="fas fa-users"></i> RideShare</a>
                    <a href="<?php echo BASE_URL; ?>pages/my-bookings.php"><i class="fas fa-ticket-alt"></i> MyBookings</a>
                    <a href="<?php echo BASE_URL; ?>pages/wallet.php"><i class="fas fa-wallet"></i> Wallet</a>
                    <a href="<?php echo BASE_URL; ?>pages/live-tracking.php"><i class="fas fa-map-marker-alt"></i> Live</a>
                    
                    <?php if(isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>
                        <?php if(isAdmin($pdo, $_SESSION['id'])): ?>
                            <a href="<?php echo BASE_URL; ?>pages/admin.php"><i class="fas fa-shield-alt"></i> Admin</a>
                        <?php endif; ?>
                        <a href="<?php echo BASE_URL; ?>pages/logout.php"><i class="fas fa-sign-out-alt"></i> Logout</a>
                    <?php else: ?>
                        <a href="<?php echo BASE_URL; ?>pages/login.php"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <?php endif; ?>
                </div>
                
                <?php if(isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>
                <div class="user-wallet">
                    <i class="fas fa-coins"></i>
                    <span>$<?php echo isset($_SESSION['balance']) ? number_format($_SESSION['balance'], 2) : '0.00'; ?></span>
                    
                    <!-- Points Display -->
                    <?php
                    $user_info = getUserInfo($pdo, $_SESSION['id']);
                    if ($user_info && isset($user_info['total_points'])):
                    ?>
                    <a href="pages/my-points.php" style="color: white; margin-left: 15px; text-decoration: none; background: rgba(255,255,255,0.2); padding: 5px 12px; border-radius: 20px;">
                        <i class="fas fa-star"></i> <?php echo number_format($user_info['total_points']); ?>
                    </a>
                    <?php endif; ?>
                    
                    <a href="<?php echo BASE_URL; ?>pages/profile.php" style="color: white; margin-left: 10px;" title="My Profile">
                        <i class="fas fa-user-circle"></i>
                    </a>
                </div>
                <?php endif; ?>
            </nav>
        </div>
    </header>

    <!-- Main Content -->
    <main class="main-content">
        <div class="container">
            <?php if(isset($_SESSION['error'])): ?>
            <div class="notification error">
                <i class="fas fa-exclamation-circle"></i> <?php echo $_SESSION['error']; unset($_SESSION['error']); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['success'])): ?>
            <div class="notification success">
                <i class="fas fa-check-circle"></i> <?php echo $_SESSION['success']; unset($_SESSION['success']); ?>
            </div>
            <?php endif; ?>
            
            <?php if(isset($_SESSION['info'])): ?>
            <div class="notification info" style="background: var(--primary);">
                <i class="fas fa-info-circle"></i> <?php echo $_SESSION['info']; unset($_SESSION['info']); ?>
            </div>
            <?php endif; ?>
            
            <div class="weather-widget">
                <i class="fas fa-cloud-sun weather-icon"></i>
                <div class="weather-info">
                    <span class="weather-temp">24°C</span>
                    <span class="weather-desc">Partly Cloudy</span>
                </div>
            </div>

            <style>
            .weather-widget {
                display: flex;
                align-items: center;
                padding: 10px;
                background: linear-gradient(135deg, #74b9ff, #0984e3);
                color: white;
                border-radius: 10px;
                margin: 10px 0;
            }
            .weather-icon {
                font-size: 2rem;
                margin-right: 10px;
                animation: weatherFloat 4s ease-in-out infinite;
            }
            @keyframes weatherFloat {
                0%, 100% { transform: translateY(0) rotate(0deg); }
                50% { transform: translateY(-5px) rotate(5deg); }
            }
            </style>

            <div class="live-time">
                <i class="fas fa-clock"></i>
                <span id="current-time">Loading...</span>
            </div>

            <script>
            function updateClock() {
                const now = new Date();
                const timeString = now.toLocaleTimeString();
                const dateString = now.toLocaleDateString('en-US', { 
                    weekday: 'long', year: 'numeric', month: 'long', day: 'numeric' 
                });
                
                document.getElementById('current-time').textContent = 
                    `${dateString} | ${timeString}`;
            }
            setInterval(updateClock, 1000);
            updateClock();
            </script>