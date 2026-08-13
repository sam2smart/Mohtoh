<?php
// database/migrate_indrive.php
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Starting inDrive database extensions...\n";

    // 1. Add columns to users table
    $columns = [
        'rating' => "DECIMAL(3, 2) DEFAULT 4.85 AFTER profile_image",
        'completed_rides' => "INT DEFAULT 12 AFTER rating",
        'vehicle_model' => "VARCHAR(100) DEFAULT NULL AFTER completed_rides",
        'vehicle_plate' => "VARCHAR(20) DEFAULT NULL AFTER vehicle_model"
    ];

    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if (!$check->fetch()) {
            echo "Adding column '$col' to users table...\n";
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $definition");
        }
    }

    // 2. Seed drivers with high-fidelity vehicle details
    echo "Seeding drivers with vehicle details...\n";
    $vehicles = [
        ['Toyota Camry (Silver)', 'LAG-409-MD'],
        ['Honda Civic (Black)', 'ABJ-312-MD'],
        ['Hyundai Elantra (Blue)', 'KAD-881-DR'],
        ['Toyota Corolla (White)', 'BEN-105-DR']
    ];

    $drivers = $pdo->query("SELECT id FROM users WHERE user_type = 'driver'")->fetchAll();
    
    $i = 0;
    foreach ($drivers as $driver) {
        $vehicle = $vehicles[$i % count($vehicles)];
        $rating = 4.5 + (rand(0, 4) / 10); // Random rating between 4.5 and 4.9
        $rides = rand(15, 230); // Random completed rides
        
        $stmt = $pdo->prepare("UPDATE users SET rating = ?, completed_rides = ?, vehicle_model = ?, vehicle_plate = ? WHERE id = ?");
        $stmt->execute([$rating, $rides, $vehicle[0], $vehicle[1], $driver['id']]);
        
        $i++;
    }

    echo "inDrive database extensions completed successfully!\n";

} catch (PDOException $e) {
    echo "Extension failed: " . $e->getMessage() . "\n";
    exit(1);
}
?>
