<?php
// includes/FareRecommendationEngine.php
class FareRecommendationEngine {
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }

    public function calculateRecommendation($community, $route) {
        $stmt = $this->pdo->prepare("
            SELECT s.suggested_fare, u.rating, 
                   (SELECT COUNT(*) FROM ride_requests WHERE driver_id = u.id AND status = 'completed') as completed_trips
            FROM community_fare_suggestions s
            JOIN users u ON s.driver_id = u.id
            WHERE s.community_name = ? AND s.route_name = ?
        ");
        $stmt->execute([$community, $route]);
        $suggestions = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (count($suggestions) < 3) return null;

        $weightedSum = 0;
        $totalWeight = 0;
        foreach ($suggestions as $s) {
            // Weight = (rating * completed_trips)
            $weight = ($s['rating'] / 5) * (1 + log(1 + $s['completed_trips']));
            $weightedSum += $s['suggested_fare'] * $weight;
            $totalWeight += $weight;
        }

        $avg = $weightedSum / $totalWeight;
        
        // ... (rest of the logic)
        return [
            'recommended' => round($avg, -1),
            'min' => round($avg * 0.9, -1),
            'max' => round($avg * 1.1, -1),
            'confidence' => 80 // Simplified for now
        ];
    }
}
