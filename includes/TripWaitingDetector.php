<?php
// includes/TripWaitingDetector.php

class TripWaitingDetector {
    // Detects if a vehicle is intentionally stopped for waiting,
    // filtering out traffic or temporary stops.
    public static function isIntentionalStop($speed, $duration_seconds) {
        // Business Rule: Stop duration must exceed a threshold to be "intentional"
        // Traffic/stops under 2 minutes (120 seconds) are ignored.
        $stop_threshold = 120; 
        
        return ($speed < 1 && $duration_seconds > $stop_threshold);
    }
}
