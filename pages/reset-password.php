<?php
/**
 * Reset Password page for University Bus Booking System
 * Handles password reset with token
 */

require_once '../includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: ../index.php");
    exit;
}

$token = isset($_GET['token']) ? $_GET['token'] : '';

// Process password reset
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['password'])) {
    $token = $_POST['token'];
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // Validate token
    $token_stmt = $pdo->prepare("SELECT * FROM password_resets WHERE token = ? AND expires > ?");
    $token_stmt->execute([$token, date('Y-m-d H:i:s')]);
    $reset = $token_stmt->fetch();
    
    if (!$reset) {
        $_SESSION['error'] = "Invalid or expired token.";
        header("Location: forgot-password.php");
        exit;
    }
    
    if ($password != $confirm_password) {
        $_SESSION['error'] = "Passwords do not match.";
        header("Location: reset-password.php?token=" . $token);
        exit;
    }
    
    if (strlen($password) < 6) {
        $_SESSION['error'] = "Password must be at least 6 characters.";
        header("Location: reset-password.php?token=" . $token);
        exit;
    }
    
    // Update password
    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $update_stmt = $pdo->prepare("UPDATE users SET password = ? WHERE email = ?");
    $update_stmt->execute([$hashed, $reset['email']]);
    
    // Delete used token
    $delete_stmt = $pdo->prepare("DELETE FROM password_resets WHERE token = ?");
    $delete_stmt->execute([$token]);
    
    $_SESSION['success'] = "Password reset successfully. Please login.";
    header("Location: login.php");
    exit;
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Reset Password</h1>

<div class="card">
    <form method="post" action="reset-password.php">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        
        <div class="form-group">
            <label for="password">New Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Enter new password" required>
        </div>
        
        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm new password" required>
        </div>
        
        <div class="form-group">
            <button type="submit" class="btn btn-primary btn-block">Reset Password</button>
        </div>
    </form>
</div>

<?php
require_once '../includes/footer.php';
?>