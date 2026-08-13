<?php
// includes/CommunityAnalyticsService.php
class CommunityAnalyticsService {
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }

    public function getAnalytics($route_id) {
        $stmt = $this->pdo->prepare("SELECT * FROM community_learning_stats WHERE route_id = ?");
        $stmt->execute([$route_id]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    public function updateLearning($route_id) {
        // Complex logic to compute averages from community_trip_feedback
        // based on driver rating weights and passenger feedback.
        // This would be triggered by a cron job or admin action.
    }
}
