<?php
// database/migrate_auth_verification.php
// Authentication, Verification & Account Recovery module. Purely
// additive on users (existing is_verified/is_suspended are untouched —
// those mean "driver KYC approved" / "admin-suspended", genuinely
// different concepts from "this person owns this email/phone").
// New verification_events table mirrors the audit-log pattern already
// used everywhere else (trip_state_events, admin_user_actions, ...).
require_once __DIR__ . '/../includes/db.php';

try {
    echo "Adding verification/recovery/lockout columns to users...\n";
    $columns = [
        'email_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'phone_verified' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'email_verified_at' => 'DATETIME NULL',
        'phone_verified_at' => 'DATETIME NULL',
        'email_verification_token' => 'VARCHAR(64) NULL', // SHA-256 hex hash of the emailed token, never the raw token
        'email_verification_expires' => 'DATETIME NULL',
        'email_resend_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'last_verification_sent' => 'DATETIME NULL',
        'otp_code' => 'VARCHAR(255) NULL', // password_hash() of the 6-digit code, never plaintext
        'otp_expires' => 'DATETIME NULL',
        'otp_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'otp_resend_count' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'otp_last_sent_at' => 'DATETIME NULL',
        'reset_token' => 'VARCHAR(64) NULL', // SHA-256 hex hash of the raw reset token/OTP
        'reset_expires' => 'DATETIME NULL',
        'reset_method' => "ENUM('email','phone') NULL",
        'reset_used' => 'TINYINT(1) NOT NULL DEFAULT 0',
        'failed_login_attempts' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
        'lockout_until' => 'DATETIME NULL',
    ];
    foreach ($columns as $col => $definition) {
        $check = $pdo->query("SHOW COLUMNS FROM users LIKE '$col'");
        if (!$check->fetch()) {
            $pdo->exec("ALTER TABLE users ADD COLUMN $col $definition");
        }
    }

    echo "Backfilling email_verified/phone_verified for existing accounts...\n";
    // Accounts created before this module existed were never asked to
    // verify anything — grandfather them in as verified so this rollout
    // doesn't lock out the whole existing user base. Only brand new
    // registrations from here on go through the real flow.
    $pdo->exec("UPDATE users SET email_verified = 1, email_verified_at = created_at WHERE email_verified = 0");
    $pdo->exec("UPDATE users SET phone_verified = 1, phone_verified_at = created_at WHERE phone_verified = 0");

    echo "Creating verification_events audit table...\n";
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS verification_events (
            id INT AUTO_INCREMENT PRIMARY KEY,
            public_id VARCHAR(30) NULL,
            user_id INT NOT NULL,
            type ENUM('email','phone','password_reset','login') NOT NULL,
            action ENUM('sent','verified','failed','expired','resent','locked','unlocked','rate_limited') NOT NULL,
            ip_address VARCHAR(45) NULL,
            meta JSON NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_user (user_id)
        )
    ");

    $check = $pdo->query("SHOW COLUMNS FROM verification_events LIKE 'public_id'");
    if ($check->fetch()) {
        require_once __DIR__ . '/../includes/functions.php';
        $stmt = $pdo->query("SELECT id FROM verification_events WHERE public_id IS NULL OR public_id = ''");
        foreach ($stmt->fetchAll() as $row) {
            $publicId = generate_unique_public_id($pdo, 'verification_events', 'public_id', 'VEV');
            $pdo->prepare("UPDATE verification_events SET public_id = ? WHERE id = ?")->execute([$publicId, $row['id']]);
        }
        $indexCheck = $pdo->query("SHOW INDEX FROM verification_events WHERE Key_name = 'public_id'");
        if (!$indexCheck->fetch()) {
            $pdo->exec("ALTER TABLE verification_events ADD UNIQUE (public_id)");
        }
    }

    echo "Auth verification migration completed successfully!\n";
} catch (PDOException $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    exit(1);
}
