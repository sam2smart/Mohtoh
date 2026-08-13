<?php
// database/migrate_public_user_id.php
// Adds a public-facing user_id (RDR-/DRV-/ADM-/USR- + 8 secure random
// chars) alongside the existing numeric `id` primary key, which stays
// the foreign key used everywhere else untouched. Backfills existing
// rows, then enforces uniqueness once every row has a value.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    echo "Starting public user_id migration...\n";

    echo "Adding user_id column to users...\n";
    $check = $pdo->query("SHOW COLUMNS FROM users LIKE 'user_id'");
    if (!$check->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN user_id VARCHAR(20) NULL");
    }

    echo "Backfilling user_id for existing users...\n";
    $stmt = $pdo->query("SELECT id, user_type FROM users WHERE user_id IS NULL OR user_id = ''");
    $rows = $stmt->fetchAll();

    foreach ($rows as $row) {
        $publicId = generate_unique_user_id($pdo, $row['user_type']);
        $update = $pdo->prepare("UPDATE users SET user_id = ? WHERE id = ?");
        $update->execute([$publicId, $row['id']]);
        echo "  id {$row['id']} ({$row['user_type']}) -> $publicId\n";
    }

    echo "Adding UNIQUE constraint on user_id...\n";
    $indexCheck = $pdo->query("SHOW INDEX FROM users WHERE Key_name = 'user_id'");
    if (!$indexCheck->fetch()) {
        $pdo->exec("ALTER TABLE users ADD UNIQUE (user_id)");
    }

    echo "Public user_id migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
