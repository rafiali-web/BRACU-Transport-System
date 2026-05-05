<?php
/**
 * Create AI Riders for Demo Purposes
 * Run this file once to populate the database with AI riders
 */

require_once 'includes/config.php';

echo "<h1>Creating AI Riders...</h1>";

// List of AI riders to create
$ai_riders = [
    [
        'student_id' => 'AI001',
        'first_name' => 'Alex',
        'last_name' => 'Rider',
        'email' => 'alex.rider@ai.edu',
        'vehicle_type' => 'car',
        'vehicle_model' => 'Tesla Model 3',
        'vehicle_number' => 'AI-1234',
        'vehicle_color' => 'Red',
        'license_number' => 'LIC-AI-001'
    ],
    [
        'student_id' => 'AI002',
        'first_name' => 'Sarah',
        'last_name' => 'Drive',
        'email' => 'sarah.drive@ai.edu',
        'vehicle_type' => 'car',
        'vehicle_model' => 'Honda Civic',
        'vehicle_number' => 'AI-5678',
        'vehicle_color' => 'Blue',
        'license_number' => 'LIC-AI-002'
    ],
    [
        'student_id' => 'AI003',
        'first_name' => 'Mike',
        'last_name' => 'Wheels',
        'email' => 'mike.wheels@ai.edu',
        'vehicle_type' => 'motorcycle',
        'vehicle_model' => 'Yamaha R6',
        'vehicle_number' => 'AI-9012',
        'vehicle_color' => 'Black',
        'license_number' => 'LIC-AI-003'
    ],
    [
        'student_id' => 'AI004',
        'first_name' => 'Emma',
        'last_name' => 'Cruise',
        'email' => 'emma.cruise@ai.edu',
        'vehicle_type' => 'microbus',
        'vehicle_model' => 'Toyota Hiace',
        'vehicle_number' => 'AI-3456',
        'vehicle_color' => 'White',
        'license_number' => 'LIC-AI-004'
    ],
    [
        'student_id' => 'AI005',
        'first_name' => 'Chris',
        'last_name' => 'Rider',
        'email' => 'chris.rider@ai.edu',
        'vehicle_type' => 'car',
        'vehicle_model' => 'BMW X5',
        'vehicle_number' => 'AI-7890',
        'vehicle_color' => 'Silver',
        'license_number' => 'LIC-AI-005'
    ]
];

try {
    // Check if AI riders table needs vehicle_color column
    $pdo->exec("ALTER TABLE riders ADD COLUMN IF NOT EXISTS vehicle_color TEXT");
    
    foreach ($ai_riders as $rider) {
        // Check if rider already exists
        $check = $pdo->prepare("SELECT id FROM users WHERE student_id = ?");
        $check->execute([$rider['student_id']]);
        
        if (!$check->fetch()) {
            // Create user account
            $hashed = password_hash('password123', PASSWORD_DEFAULT);
            $user_stmt = $pdo->prepare("INSERT INTO users (student_id, first_name, last_name, email, password, user_type, total_points) VALUES (?, ?, ?, ?, ?, 'rider', 500)");
            $user_stmt->execute([$rider['student_id'], $rider['first_name'], $rider['last_name'], $rider['email'], $hashed]);
            
            $user_id = $pdo->lastInsertId();
            
            // Create rider profile
            $rider_stmt = $pdo->prepare("INSERT INTO riders (user_id, vehicle_type, vehicle_model, vehicle_number, license_number, vehicle_color, is_verified, is_available) VALUES (?, ?, ?, ?, ?, ?, 1, 1)");
            $rider_stmt->execute([$user_id, $rider['vehicle_type'], $rider['vehicle_model'], $rider['vehicle_number'], $rider['license_number'], $rider['vehicle_color']]);
            
            // Create wallet for AI rider
            $wallet_stmt = $pdo->prepare("INSERT INTO wallet (user_id, balance) VALUES (?, 1000)");
            $wallet_stmt->execute([$user_id]);
            
            echo "<p style='color: green;'>✅ Created AI rider: " . $rider['first_name'] . " " . $rider['last_name'] . "</p>";
        } else {
            echo "<p style='color: orange;'>⚠️ AI rider already exists: " . $rider['first_name'] . " " . $rider['last_name'] . "</p>";
        }
    }
    
    echo "<h2 style='color: green;'>✅ All AI riders created successfully!</h2>";
    echo "<p><a href='pages/uber.php'>Go to Uber Page</a></p>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>