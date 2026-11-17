<?php
// database/migrate_network_external_partners.php
// MOHTOH NETWORK — External Partner support (Phase 5 of the
// stabilisation assignment; see MOHTOH_EXTERNAL_PARTNER_PROPOSAL.md for
// the approved architecture). Adds exactly one column; everything else
// external partners need (network_partners, network_wallets,
// network_reward_ledger, network_withdrawal_accounts,
// network_withdrawals) already exists unchanged from
// migrate_mohtoh_network.php — an external partner is just a `users` row
// with user_type='rider' and this flag set to 1.
//
// Idempotent: same SHOW COLUMNS self-heal pattern as every other
// migrate_*.php in this project. Kept as its own file rather than
// editing migrate_mohtoh_network.php, which already shipped on
// review/mohtoh-network.
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

function nep_column_exists($pdo, $table, $column) {
    $stmt = $pdo->prepare("SHOW COLUMNS FROM `$table` LIKE ?");
    $stmt->execute([$column]);
    return (bool) $stmt->fetchColumn();
}

try {
    echo "=== users.is_external_partner ===\n";
    if (!nep_column_exists($pdo, 'users', 'is_external_partner')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN is_external_partner TINYINT(1) NOT NULL DEFAULT 0");
        echo "Added.\n";
    } else {
        echo "Already exists.\n";
    }

    echo "\nDone.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
