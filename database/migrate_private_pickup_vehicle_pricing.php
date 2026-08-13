<?php
// database/migrate_private_pickup_vehicle_pricing.php
//
// Gives Keke and Car their OWN Private Pickup rate cards, so a short local
// keke street trip and a longer car trip are no longer forced onto one
// universal price.
//
// No schema change: these are system_settings rows, read by
// includes/private_pickup.php's private_pickup_rate_card() and written by
// api/pricing-admin.php's whitelist - the settings system this app already
// uses for every other pricing value.
//
// PRICE-PRESERVING: each class is seeded with the value Private Pickup is
// charging RIGHT NOW (private_pickup_base_fare / private_pickup_price_per_km,
// or their in-code fallbacks 300 / 120 if unset). Nothing gets more or less
// expensive by running this; the two classes simply become independently
// adjustable from the admin panel. Min/max seed at 0 = unset, and commission
// is left blank so it keeps inheriting the platform rate.
//
// Idempotent: INSERT IGNORE, so an admin's later edits are never overwritten.
//
//   php database/migrate_private_pickup_vehicle_pricing.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

try {
    $sharedBase  = (string) get_pricing_setting($pdo, 'private_pickup_base_fare', '300');
    $sharedPerKm = (string) get_pricing_setting($pdo, 'private_pickup_price_per_km', '120');

    echo "Private Pickup per-vehicle pricing\n";
    echo "  current shared rate: base $sharedBase, per km $sharedPerKm\n";

    // The shared fallback rows themselves, in case this install predates them
    // - the admin panel now edits these, so they must exist to be shown.
    $settings = [
        ['private_pickup_base_fare', $sharedBase],
        ['private_pickup_price_per_km', $sharedPerKm],
        ['private_booking_fee', (string) get_pricing_setting($pdo, 'private_booking_fee', '100')],
    ];

    foreach (['keke', 'car'] as $class) {
        $settings[] = ["private_pickup_{$class}_base_fare", $sharedBase];
        $settings[] = ["private_pickup_{$class}_price_per_km", $sharedPerKm];
        // 0 = no floor / no cap, matching how maximum_fare already reads.
        $settings[] = ["private_pickup_{$class}_minimum_fare", '0'];
        $settings[] = ["private_pickup_{$class}_maximum_fare", '0'];
        // Blank = inherit commission_percentage. Seeded so the row exists and
        // the admin field round-trips, without pinning a rate.
        $settings[] = ["private_pickup_{$class}_commission_percentage", ''];
    }

    $ins = $pdo->prepare("INSERT IGNORE INTO system_settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($settings as [$key, $value]) {
        $ins->execute([$key, $value]);
        $note = $ins->rowCount() > 0 ? 'seeded' : 'already set, left alone';
        echo "  $key: $note\n";
    }

    echo "Done. Keke and Car Private Pickup pricing are now independent.\n";
} catch (PDOException $e) {
    echo 'Migration failed: ' . $e->getMessage() . "\n";
    exit(1);
}
