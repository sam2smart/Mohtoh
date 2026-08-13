<?php
// includes/WaitingPricingCalculator.php

class WaitingPricingCalculator {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function calculateFee($duration_seconds) {
        $stmt = $this->pdo->query("SELECT * FROM waiting_settings LIMIT 1");
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$settings || !$settings['enabled']) return 0;

        $freeSeconds = $settings['free_waiting_minutes'] * 60;
        $chargeableSeconds = max(0, $duration_seconds - $freeSeconds);
        $chargeableMinutes = ceil($chargeableSeconds / 60);

        $fee = $chargeableMinutes * $settings['waiting_price_per_minute'];
        
        // Apply min/max limits
        $fee = max($settings['minimum_wait_charge'], min($fee, $settings['maximum_wait_charge']));

        return (float)$fee;
    }
}
