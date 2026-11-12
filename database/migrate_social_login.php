<?php
// database/migrate_social_login.php
// Schema for Secure Social Login (Continue with Google / Facebook).
//
// Adds ONLY new, additive structures — nothing here alters or drops any
// existing column, so email/password auth, verification, sessions, roles and
// every unrelated feature keep working byte-for-byte:
//
//   * oauth_identities  — the dedicated social-identity table. One row per
//                         (provider, provider_user_id); UNIQUE(provider,
//                         provider_user_id) makes a provider account map to at
//                         most one MohToh user. FK user_id -> users(id) ON
//                         DELETE CASCADE cleans links up with the user.
//   * oauth_states      — server-side, single-use CSRF/anti-forgery state for
//                         the authorization-code flow (hashed), carrying the
//                         flow mode (login|link), the initiating user for link
//                         mode, a PKCE verifier for Google, and an expiry.
//   * auth_rate_limits  — IP/identifier rate-limit buckets for OAuth start,
//                         callback, link and unlink (the app's existing limiter
//                         is per-account lockout only; OAuth needs IP-based).
//   * users.password_is_set — 1 for every account that has a real, user-chosen
//                         password (all existing rows, via the DEFAULT), 0 for
//                         social-only signups that never set one. Guards the
//                         "don't let a user unlink their only way back in" rule.
//
// Safe to re-run: every step self-heals via SHOW TABLES / SHOW COLUMNS checks,
// mirroring database/migrate_integration_settings.php.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function sl_table_exists($pdo, $table) {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool) $stmt->fetchColumn();
}

function sl_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== oauth_identities ===\n";
    if (!sl_table_exists($pdo, 'oauth_identities')) {
        $pdo->exec("
            CREATE TABLE oauth_identities (
                id INT AUTO_INCREMENT PRIMARY KEY,
                public_id VARCHAR(30) NULL,
                user_id INT NOT NULL,
                provider VARCHAR(20) NOT NULL,
                provider_user_id VARCHAR(191) NOT NULL,
                provider_email VARCHAR(255) NULL,
                provider_email_verified TINYINT(1) NOT NULL DEFAULT 0,
                provider_name VARCHAR(255) NULL,
                linked_ip VARCHAR(45) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_provider_user (provider, provider_user_id),
                INDEX idx_user (user_id),
                INDEX idx_provider_email (provider, provider_email),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== oauth_states ===\n";
    if (!sl_table_exists($pdo, 'oauth_states')) {
        $pdo->exec("
            CREATE TABLE oauth_states (
                id INT AUTO_INCREMENT PRIMARY KEY,
                state_hash CHAR(64) NOT NULL,
                provider VARCHAR(20) NOT NULL,
                mode VARCHAR(20) NOT NULL DEFAULT 'login',
                user_id INT NULL,
                role_hint VARCHAR(20) NULL,
                redirect_after VARCHAR(255) NULL,
                code_verifier VARCHAR(128) NULL,
                created_ip VARCHAR(45) NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_state_hash (state_hash),
                INDEX idx_expires (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== auth_rate_limits ===\n";
    if (!sl_table_exists($pdo, 'auth_rate_limits')) {
        $pdo->exec("
            CREATE TABLE auth_rate_limits (
                id INT AUTO_INCREMENT PRIMARY KEY,
                bucket VARCHAR(40) NOT NULL,
                identifier VARCHAR(100) NOT NULL,
                hits INT NOT NULL DEFAULT 0,
                window_start TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_bucket_identifier (bucket, identifier),
                INDEX idx_window (window_start)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
        ");
        echo "Created.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\n=== users.password_is_set ===\n";
    if (!sl_column_exists($pdo, 'users', 'password_is_set')) {
        // DEFAULT 1: every EXISTING account has a real, user-chosen password,
        // so all of them remain fully able to log in with email/password and
        // to unlink social providers. Only social-only signups created by the
        // OAuth flow will explicitly write 0 here.
        $pdo->exec("ALTER TABLE users ADD COLUMN password_is_set TINYINT(1) NOT NULL DEFAULT 1");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    // Backfill public_id on any pre-existing oauth_identities rows (mirrors the
    // integration log-table backfill in migrate_integration_settings.php).
    echo "\n=== oauth_identities.public_id backfill ===\n";
    $rows = $pdo->query("SELECT id FROM oauth_identities WHERE public_id IS NULL")->fetchAll();
    foreach ($rows as $row) {
        $publicId = generate_unique_public_id($pdo, 'oauth_identities', 'public_id', 'OID');
        $stmt = $pdo->prepare("UPDATE oauth_identities SET public_id = ? WHERE id = ?");
        $stmt->execute([$publicId, $row['id']]);
    }
    echo count($rows) . " row(s) backfilled.\n";

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
