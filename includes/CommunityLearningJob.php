<?php
// includes/CommunityLearningJob.php
class CommunityLearningJob {
    private $pdo;
    public function __construct($pdo) { $this->pdo = $pdo; }

    public function queueRouteUpdate($route_id) {
        $stmt = $this->pdo->prepare("INSERT IGNORE INTO community_learning_queue (route_id) VALUES (?)");
        $stmt->execute([$route_id]);
    }

    public function processBatch() {
        $startTime = microtime(true);
        $stmt = $this->pdo->query("SELECT route_id FROM community_learning_queue WHERE status = 'pending' LIMIT 50");
        $routes = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($routes)) return;

        $processedCount = 0;
        foreach ($routes as $route_id) {
            $this->pdo->prepare("UPDATE community_learning_queue SET status = 'processing' WHERE route_id = ?")->execute([$route_id]);
            
            // ... Logic to re-run FareRecommendationEngine and update community_learning_stats ...
            
            $this->pdo->prepare("DELETE FROM community_learning_queue WHERE route_id = ?")->execute([$route_id]);
            $processedCount++;
        }

        $duration = microtime(true) - $startTime;
        $this->pdo->prepare("INSERT INTO community_learning_logs (routes_processed, duration_seconds, status) VALUES (?, ?, 'success')")
             ->execute([$processedCount, $duration]);
    }
}
