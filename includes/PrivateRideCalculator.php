<?php
// includes/PrivateRideCalculator.php
require_once __DIR__ . '/CommunityFareService.php';

class PrivateRideCalculator {
    private $pdo;
    private $communityService;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->communityService = new CommunityFareService($pdo);
    }

    public function calculate($community, $route, $vehicle_type, $capacity, $pickup_distance, $trip_distance) {
        $fareData = $this->communityService->getRecommendedFare($community, $route, $vehicle_type);
        $baseFare = $fareData ? $fareData['recommended_fare'] : 300; // Fallback

        $stmt = $this->pdo->query("SELECT setting_value FROM system_settings WHERE setting_key IN ('private_booking_fee')");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $bookingFee = (float)($settings['private_booking_fee'] ?? 100);

        // Formula: (Comm Fare * Capacity) + PickupDist + TripDist + Service Charges
        // Assuming 120 per km as per prompt
        $reservationCost = ($baseFare * $capacity);
        $pickupCost = $pickup_distance * 120;
        $tripCost = $trip_distance * 120;

        return [
            'community_base' => $reservationCost,
            'pickup_cost' => $pickupCost,
            'trip_cost' => $tripCost,
            'service_charge' => $bookingFee,
            'total' => $reservationCost + $pickupCost + $tripCost + $bookingFee
        ];
    }
}
