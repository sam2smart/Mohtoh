<?php
// includes/PricingEngine.php
require_once __DIR__ . '/FareCalculator.php';
require_once __DIR__ . '/CommunityFareService.php';
require_once __DIR__ . '/PrivateRideCalculator.php';
require_once __DIR__ . '/WaitingPricingCalculator.php';

class PricingEngine {
    private $pdo;
    private $calculator;
    private $communityService;
    private $privateCalculator;
    private $waitingCalculator;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->calculator = new FareCalculator($pdo);
        $this->communityService = new CommunityFareService($pdo);
        $this->privateCalculator = new PrivateRideCalculator($pdo);
        $this->waitingCalculator = new WaitingPricingCalculator($pdo);
    }

    public function calculate($ride_mode, $vehicle_type, $community=null, $route=null, $distance=0, $capacity=1, $pickup_dist=0, $waiting_seconds=0) {
        $fare = 0;
        if ($ride_mode === 'private') {
            $fare = $this->privateCalculator->calculate($community, $route, $vehicle_type, $capacity, $pickup_dist, $distance);
            $fare = $fare['total'];
        } else {
             // Shared/Community logic
             $fare = $this->calculator->calculateFare($distance);
        }

        $waitingFee = $this->waitingCalculator->calculateFee($waiting_seconds);
        return $fare + $waitingFee;
    }
}
