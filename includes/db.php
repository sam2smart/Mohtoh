<?php
// Loaded unconditionally (require_once is safe to call regardless of
// whether a caller already loaded it) so DB_* env vars are available no
// matter which file requires db.php first — callers across the app do
// this in inconsistent order (some require config.php, which loads
// environment.php, before db.php; others require db.php first).
require_once __DIR__ . '/environment.php';

// Falls back to the XAMPP local-development defaults when unset, so a
// fresh local checkout with no .env keeps working with zero setup. Any
// shared/production environment MUST set real values via .env (see
// .env.example) — never rely on this fallback outside a developer's own
// machine. See MOHTOH_SECURITY_CREDENTIAL_REMEDIATION.md.
//
// $_ENV[...] alone is sufficient here (not chained with getenv()) because
// the require_once above guarantees mt_load_env() has already populated
// $_ENV directly from .env, if the key was present there at all — an
// empty-string .env value is deliberately treated the same as "unset"
// (falls through to the default) since a blank host/db/user is never a
// real intentional value, unlike DB_PASSWORD which may legitimately be ''.
$host     = (($_ENV['DB_HOST'] ?? '') !== '') ? $_ENV['DB_HOST'] : 'localhost';
$dbname   = (($_ENV['DB_NAME'] ?? '') !== '') ? $_ENV['DB_NAME'] : 'merit_drives';
$username = (($_ENV['DB_USER'] ?? '') !== '') ? $_ENV['DB_USER'] : 'root';
$password = $_ENV['DB_PASSWORD'] ?? '';

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
