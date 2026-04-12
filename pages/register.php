<?php
/**
 * Registration page for University Bus Booking System
 * Handles new user registration with automatic wallet creation
 */

require_once '../includes/config.php';

// Redirect if already logged in
if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    header("Location: ../index.php");
    exit;
}

// Process registration form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $student_id = trim($_POST['student_id']);
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $password = trim($_POST['password']);
    $confirm_password = trim($_POST['confirm_password']);
    
    // Validate input
    $errors = [];
    
    if (empty($student_id) || empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $errors[] = "Please fill in all fields";
    }
    
    if ($password != $confirm_password) {
        $errors[] = "Passwords do not match";
    }
    
    if (strlen($password) < 6) {
        $errors[] = "Password must be at least 6 characters long";
    }
    
    // Check if student ID or email already exists
    if (empty($errors)) {
        $check_stmt = $pdo->prepare("SELECT id FROM users WHERE student_id = ? OR email = ?");
        $check_stmt->execute([$student_id, $email]);
        if ($check_stmt->fetch()) {
            $errors[] = "Student ID or email already exists";
        }
    }
    
    // If no errors, proceed with registration
    if (empty($errors)) {
        // Hash the password
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        
        // Start transaction
        $pdo->beginTransaction();
        
        try {
            // Insert user
            $stmt = $pdo->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, user_type, total_points) VALUES (?, ?, ?, ?, ?, 'student', 0)");
            $stmt->execute([$student_id, $first_name, $last_name, $email, $hashed_password]);
            
            $user_id = $pdo->lastInsertId();
            
            // Initialize wallet for user
            $wallet_stmt = $pdo->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 0)");
            $wallet_stmt->execute([$user_id]);
            
            $pdo->commit();
            
            $_SESSION['success'] = "Registration successful. Please login.";
            header("Location: login.php");
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $_SESSION['error'] = "Registration failed: " . $e->getMessage();
            header("Location: register.php");
            exit();
        }
    } else {
        $_SESSION['error'] = implode("<br>", $errors);
        header("Location: register.php");
        exit();
    }
}

require_once '../includes/header.php';
?>

<h1 class="page-title">Create Account</h1>

<div class="card">
    <form method="post" action="register.php">
        <div class="form-group">
            <label for="student_id">Student ID</label>
            <input type="text" id="student_id" name="student_id" class="form-control" placeholder="Enter your student ID" required>
        </div>
        <div class="form-group">
            <label for="first_name">First Name</label>
            <input type="text" id="first_name" name="first_name" class="form-control" placeholder="Enter your first name" required>
        </div>
        <div class="form-group">
            <label for="last_name">Last Name</label>
            <input type="text" id="last_name" name="last_name" class="form-control" placeholder="Enter your last name" required>
        </div>
        <div class="form-group">
            <label for="email">Email Address</label>
            <input type="email" id="email" name="email" class="form-control" placeholder="Enter your email" required>
        </div>
        <div class="form-group">
            <label for="password">Password</label>
            <input type="password" id="password" name="password" class="form-control" placeholder="Create a password (min 6 characters)" required>
        </div>
        <div class="form-group">
            <label for="confirm_password">Confirm Password</label>
            <input type="password" id="confirm_password" name="confirm_password" class="form-control" placeholder="Confirm your password" required>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary btn-block">Register</button>
        </div>
        <div style="text-align: center;">
            <p>Already have an account? <a href="login.php" style="color: var(--primary);">Login here</a></p>
        </div>
    </form>
</div>

<?php
require_once '../includes/footer.php';
?>