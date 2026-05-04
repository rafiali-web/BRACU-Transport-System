<?php


require_once '../includes/config.php';


if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: ../index.php");
    exit;
}


if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    

    $user_stmt = $pdo->prepare("SELECT id, first_name FROM users WHERE email = ?");
    $user_stmt->execute([$email]);
    $user = $user_stmt->fetch();
    
    if ($user) {
        
        $token = bin2hex(random_bytes(32));
        $expires = date("Y-m-d H:i:s", strtotime('+1 hour'));
        
     
        $token_stmt = $pdo->prepare("
            INSERT INTO password_resets (email, token, expires) 
            VALUES (?, ?, ?)
            ON CONFLICT(email) DO UPDATE SET token = ?, expires = ?
        ");
        $token_stmt->execute([$email, $token, $expires, $token, $expires]);
        
      
        $_SESSION['success'] = "Password reset link has been generated.";
        $_SESSION['reset_link'] = "reset-password.php?token=" . $token;
    } else {
        $_SESSION['error'] = "No account found with that email";
    }
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Reset Your Password</h1>

<div class="card">
    <p>Enter your email address and we'll send you a link to reset your password.</p>
    
    <form method="post" action="forgot-password.php">
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary btn-block">Send Reset Link</button>
        </div>
        <div style="text-align: center;">
            <p>Remember your password? <a href="login.php" style="color: var(--primary);">Login here</a></p>
        </div>
    </form>
    
    <?php if (isset($_SESSION['reset_link'])): ?>
    <div class="notification success">
        <p>Reset link: <a href="<?php echo $_SESSION['reset_link']; ?>"><?php echo $_SESSION['reset_link']; ?></a></p>
        <?php unset($_SESSION['reset_link']); ?>
    </div>
    <?php endif; ?>
</div>

<?php
require_once '../includes/footer.php';
?>
