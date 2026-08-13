<?php
// database/seed_admin.php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $email = 'admin@meritdrives.com';
    $password = 'adminpassword';
    $fullname = 'System Admin';
    $phone = '0123456789';
    $user_type = 'admin';

    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        echo "Admin already exists!\n";
    } else {
        $hashed_password = password_hash($password, PASSWORD_DEFAULT);
        $public_user_id = generate_unique_user_id($pdo, $user_type);
        $stmt = $pdo->prepare("INSERT INTO users (fullname, phone, email, password, user_type, user_id) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$fullname, $phone, $email, $hashed_password, $user_type, $public_user_id]);
        echo "Admin account seeded successfully! ($public_user_id)\n";
    }
} catch (Exception $e) {
    echo "Failed to seed admin: " . $e->getMessage() . "\n";
}
