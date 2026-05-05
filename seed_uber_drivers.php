<?php
/**
 * Run once in the browser to add demo drivers (e.g. Arif) and GPS pins for matching.
 * Safe to run multiple times (skips existing accounts).
 */

require_once __DIR__ . '/includes/config.php';

header('Content-Type: text/html; charset=utf-8');
echo '<!DOCTYPE html><html><head><title>Seed Uber drivers</title></head><body style="font-family:sans-serif;max-width:640px;margin:40px auto;">';
echo '<h1>Uber demo drivers</h1>';

$lat0 = 23.8103;
$lng0 = 90.4125;

try {
    $check = $pdo->prepare('SELECT id FROM users WHERE student_id = ?');
    $check->execute(['DEMO-ARIF']);
    if (!$check->fetch()) {
        $hash = password_hash('password123', PASSWORD_DEFAULT);
        $pdo->prepare("
            INSERT INTO users (student_id, first_name, last_name, email, password, user_type, total_points)
            VALUES ('DEMO-ARIF', 'Arif', 'Hossain', 'arif.demo@bracu.test', ?, 'student', 0)
        ")->execute([$hash]);
        $uid = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO wallet (user_id, balance) VALUES (?, 300)')->execute([$uid]);
        $pdo->prepare("
            INSERT INTO riders (user_id, vehicle_type, vehicle_model, vehicle_number, license_number, is_verified, is_available, current_latitude, current_longitude)
            VALUES (?, 'motorcycle', 'Yamaha FZ v3', 'DHK-AR-01', 'LIC-ARIF-01', 1, 1, ?, ?)
        ")->execute([$uid, $lat0 + 0.004, $lng0 - 0.003]);
        echo '<p style="color:green;">Added driver <strong>Arif Hossain</strong> (login: arif.demo@bracu.test / password123)</p>';
    } else {
        echo '<p>User DEMO-ARIF already exists — skipped.</p>';
    }

    // Place every verified rider near campus so distance matching returns 1–2 drivers
    $riders = $pdo->query('SELECT id FROM riders WHERE is_verified = 1')->fetchAll(PDO::FETCH_COLUMN);
    $i = 0;
    foreach ($riders as $rid) {
        $i++;
        $la = $lat0 + ($i * 0.002) - 0.002;
        $ln = $lng0 + ($i * 0.0015) - 0.0015;
        $pdo->prepare('UPDATE riders SET current_latitude = ?, current_longitude = ? WHERE id = ?')->execute([$la, $ln, $rid]);
    }
    echo '<p style="color:green;">Updated GPS for <strong>' . count($riders) . '</strong> verified driver(s).</p>';
    echo '<p><a href="pages/uber.php?mode=passenger">Open Uber (passenger)</a></p>';
} catch (Throwable $e) {
    echo '<p style="color:red;">Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
}

echo '</body></html>';
