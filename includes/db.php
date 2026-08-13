<?php
$host = 'localhost';
$dbname = 'merit_drives';
$username = 'root'; // default XAMPP user
$password = ''; // default XAMPP password

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    try {
        $pdo->query("SELECT wallet_balance FROM users LIMIT 1");
    } catch (PDOException $e) {
        $pdo->exec("ALTER TABLE users ADD COLUMN wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00");
    }
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}
?>
