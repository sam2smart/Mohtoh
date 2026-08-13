<?php
// includes/FareCalculator.php - Centralized Fare Calculation Service

class FareCalculator {
    private $pdo;
    private $baseFare = 2.50;
    private $pricePerKm = 1.20;
    private $minimumFare = 5.00;

    public function __construct($pdo) {
        $this->pdo = $pdo;
        $this->loadSettings();
    }

    /**
     * Load fare settings from database
     */
    private function loadSettings() {
        try {
            $stmt = $this->pdo->query("
                SELECT setting_key, setting_value 
                FROM system_settings 
                WHERE setting_key IN ('base_fare', 'price_per_km', 'minimum_fare')
            ");
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (isset($settings['base_fare'])) {
                $this->baseFare = (float)$settings['base_fare'];
            }
            if (isset($settings['price_per_km'])) {
                $this->pricePerKm = (float)$settings['price_per_km'];
            }
            if (isset($settings['minimum_fare'])) {
                $this->minimumFare = (float)$settings['minimum_fare'];
            }
        } catch (Exception $e) {
            // Use defaults if query fails
        }
    }

    /**
     * Calculate fare for a ride
     * 
     * @param float $distanceKm - Distance in kilometers
     * @param int $vehicleRating - Vehicle admin rating (1-5 stars)
     * @return array - Calculated fare breakdown
     */
    public function calculateFare($distanceKm, $vehicleRating = 3) {
        $distanceKm = (float)$distanceKm;
        $vehicleRating = max(1, min(5, (int)$vehicleRating)); // Clamp 1-5

        // Get vehicle rating multiplier
        $multiplier = $this->getVehicleMultiplier($vehicleRating);

        // Calculate base + distance
        $baseCost = $this->baseFare + ($distanceKm * $this->pricePerKm);
        
        // Apply vehicle rating multiplier
        $totalFare = $baseCost * $multiplier;

        // Apply minimum fare
        $finalFare = max($totalFare, $this->minimumFare);

        return [
            'base_cost' => round($baseCost, 2),
            'vehicle_multiplier' => $multiplier,
            'final_fare' => round($finalFare, 2),
            'distance_km' => $distanceKm,
            'base_fare' => $this->baseFare,
            'price_per_km' => $this->pricePerKm,
            'minimum_fare' => $this->minimumFare
        ];
    }

    /**
     * Get vehicle rating multiplier
     * 
     * @param int $rating - Rating 1-5
     * @return float - Multiplier
     */
    private function getVehicleMultiplier($rating) {
        $multipliers = [
            1 => 1.0,   // Basic vehicle
            2 => 1.1,   // Fair condition
            3 => 1.2,   // Standard
            4 => 1.35,  // Premium
            5 => 1.5    // Luxury
        ];
        
        return $multipliers[$rating] ?? 1.2;
    }

    /**
     * Get all fare settings from database
     */
    public function getAllSettings() {
        try {
            $stmt = $this->pdo->query("SELECT setting_key, setting_value FROM system_settings");
            return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            return [];
        }
    }

    /**
     * Update fare setting
     */
    public function updateSetting($key, $value) {
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO system_settings (setting_key, setting_value) 
                 VALUES (?, ?) 
                 ON DUPLICATE KEY UPDATE setting_value = ?"
            );
            return $stmt->execute([$key, $value, $value]);
        } catch (Exception $e) {
            return false;
        }
    }
}
?>
