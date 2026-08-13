<?php
// includes/CommunityFareService.php
class CommunityFareService {
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }

    public function getRecommendedFare($community, $route, $vehicle_type) {
        $stmt = $this->pdo->prepare("SELECT recommended_fare, minimum_fare, maximum_fare FROM community_route_fares WHERE community_name = ? AND route_name = ? AND vehicle_type = ? AND status = 'active'");
        $stmt->execute([$community, $route, $vehicle_type]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function submitSuggestion($driver_id, $community, $route, $fare) {
        $stmt = $this->pdo->prepare("INSERT INTO community_fare_suggestions (driver_id, community_name, route_name, suggested_fare) VALUES (?, ?, ?, ?)");
        return $stmt->execute([$driver_id, $community, $route, $fare]);
    }
}
