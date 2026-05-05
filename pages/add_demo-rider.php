<?php
require_once 'includes/config.php';

try {
    // Check if demo rider already exists
    $check = $pdo->query("SELECT id FROM users WHERE student_id = 'DEMO001'")->fetch();
    
    if (!$check) {
        // Create demo user
        $hashed = password_hash('demo123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, user_type, total_points) VALUES (?, ?, ?, ?, ?, 'rider', 9999)");
        $stmt->execute(['DEMO001', 'Demo', 'Rider', 'demo@university.edu', $hashed]);
        $demo_user_id = $pdo->lastInsertId();
        
        // Create demo rider
        $stmt = $pdo->prepare("INSERT INTO riders (user_id, vehicle_type, vehicle_model, vehicle_number, license_number, is_verified, is_available) VALUES (?, 'car', 'Toyota Prius 2024', 'DEMO-1234', 'LIC-DEMO-001', 1, 1)");
        $stmt->execute([$demo_user_id]);
        
        echo "✅ Demo rider created successfully!";
    } else {
        echo "✅ Demo rider already exists.";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>