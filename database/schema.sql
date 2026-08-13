CREATE DATABASE IF NOT EXISTS merit_drives;
USE merit_drives;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(255) NOT NULL,
    phone VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    user_type ENUM('rider', 'driver', 'admin') NOT NULL DEFAULT 'rider',
    profile_image VARCHAR(255) NOT NULL DEFAULT 'default.png',
    rating DECIMAL(3, 2) NOT NULL DEFAULT 4.85,
    wallet_balance DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    completed_rides INT NOT NULL DEFAULT 0,
    vehicle_model VARCHAR(100) DEFAULT NULL,
    vehicle_plate VARCHAR(20) DEFAULT NULL,
    is_online TINYINT(1) NOT NULL DEFAULT 0,
    latitude DECIMAL(10, 8) DEFAULT NULL,
    longitude DECIMAL(11, 8) DEFAULT NULL,
    last_location_update TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_location_online_type (latitude, longitude, is_online, user_type),
    INDEX idx_user_type (user_type),
    INDEX idx_online (is_online)
);

CREATE TABLE IF NOT EXISTS ride_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    rider_id INT NOT NULL,
    driver_id INT NULL,
    pickup_lat DECIMAL(10, 8) NOT NULL,
    pickup_lng DECIMAL(11, 8) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    destination_lat DECIMAL(10, 8) DEFAULT NULL,
    destination_lng DECIMAL(11, 8) DEFAULT NULL,
    requested_service_category ENUM('Basic', 'Comfort', 'Standard', 'Premium') NOT NULL DEFAULT 'Standard',
    price DECIMAL(10, 2) DEFAULT NULL,
    platform_fee DECIMAL(10, 2) DEFAULT NULL,
    status ENUM('pending', 'accepted', 'in_progress', 'rejected', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS ride_offers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    driver_id INT NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    expires_at TIMESTAMP NOT NULL,
    status ENUM('pending', 'accepted', 'rejected', 'expired') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS ride_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL,
    pickup_location VARCHAR(255) DEFAULT NULL,
    start_location VARCHAR(255) DEFAULT NULL,
    destination VARCHAR(255) NOT NULL,
    departure_date DATE NOT NULL,
    departure_time TIME NOT NULL,
    vehicle_id INT DEFAULT NULL,
    vehicle_type VARCHAR(100) NOT NULL,
    vehicle_info VARCHAR(255) NOT NULL,
    total_seats INT NOT NULL DEFAULT 1,
    available_seats INT NOT NULL DEFAULT 1,
    pickup_lat DECIMAL(10, 8) DEFAULT NULL,
    pickup_lng DECIMAL(11, 8) DEFAULT NULL,
    destination_lat DECIMAL(10, 8) DEFAULT NULL,
    destination_lng DECIMAL(11, 8) DEFAULT NULL,
    price_per_passenger DECIMAL(10, 2) NOT NULL DEFAULT 0.00,
    description TEXT DEFAULT NULL,
    status ENUM('active', 'full', 'cancelled', 'completed') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_rideshare_status_date (status, departure_date, departure_time)
);

CREATE TABLE IF NOT EXISTS ride_share_bookings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    ride_share_id INT NOT NULL,
    rider_id INT NOT NULL,
    seats_booked INT NOT NULL DEFAULT 1,
    status ENUM('pending', 'confirmed', 'cancelled', 'completed') NOT NULL DEFAULT 'confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_ride_share_booking (ride_share_id, rider_id),
    FOREIGN KEY (ride_share_id) REFERENCES ride_shares(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sender_id INT NOT NULL,
    receiver_id INT NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (receiver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS system_settings (
    setting_key VARCHAR(50) PRIMARY KEY,
    setting_value TEXT NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pricing_configuration (
    star_rating INT PRIMARY KEY,
    price_per_km DECIMAL(10, 2) NOT NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS vehicles (
    id INT AUTO_INCREMENT PRIMARY KEY,
    driver_id INT NOT NULL UNIQUE,
    make VARCHAR(100) NOT NULL,
    model VARCHAR(100) NOT NULL,
    year INT NOT NULL,
    color VARCHAR(50) NOT NULL,
    plate_number VARCHAR(50) NOT NULL,
    ac_available TINYINT(1) NOT NULL DEFAULT 1,
    cleanliness VARCHAR(50) NOT NULL DEFAULT 'Good',
    comfort_level VARCHAR(50) NOT NULL DEFAULT 'Standard',
    seats INT NOT NULL DEFAULT 4,
    luggage_capacity VARCHAR(50) NOT NULL DEFAULT 'Standard',
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    exterior_condition INT NOT NULL DEFAULT 3,
    interior_condition INT NOT NULL DEFAULT 3,
    ac_condition INT NOT NULL DEFAULT 3,
    comfort_rating INT NOT NULL DEFAULT 3,
    cleanliness_rating INT NOT NULL DEFAULT 3,
    appearance_rating INT NOT NULL DEFAULT 3,
    safety_rating INT NOT NULL DEFAULT 3,
    overall_grade INT NOT NULL DEFAULT 3,
    admin_rating INT NOT NULL DEFAULT 3,
    review_remarks TEXT DEFAULT NULL,
    last_reviewed_at TIMESTAMP NULL DEFAULT NULL,
    edit_request_status ENUM('none', 'pending', 'approved', 'rejected') NOT NULL DEFAULT 'none',
    service_category VARCHAR(50) NOT NULL DEFAULT 'Standard',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_photos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    photo_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_verifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    document_name VARCHAR(100) NOT NULL,
    document_path VARCHAR(255) NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    remarks TEXT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_edit_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    driver_id INT NOT NULL,
    requested_changes TEXT NOT NULL,
    status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
    admin_remarks TEXT DEFAULT NULL,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS vehicle_review_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    vehicle_id INT NOT NULL,
    admin_id INT NOT NULL,
    action ENUM('approved', 'rejected', 'edit_approved', 'edit_rejected') NOT NULL,
    remarks TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (vehicle_id) REFERENCES vehicles(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS admin_user_actions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    admin_id INT NULL,
    action ENUM('warning', 'suspension', 'unsuspension', 'note') NOT NULL DEFAULT 'note',
    reason TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_admin_user_actions_user_id (user_id),
    INDEX idx_admin_user_actions_action (action)
);

CREATE TABLE IF NOT EXISTS driver_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    driver_id INT NOT NULL,
    rider_id INT NOT NULL,
    rating INT NOT NULL,
    driving_quality INT NOT NULL,
    professionalism INT NOT NULL,
    cleanliness INT NOT NULL,
    vehicle_claims_match TINYINT(1) NOT NULL DEFAULT 1,
    driver_lied TINYINT(1) NOT NULL DEFAULT 0,
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS passenger_ratings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    rider_id INT NOT NULL,
    driver_id INT NOT NULL,
    rating INT NOT NULL,
    punctuality INT NOT NULL,
    behavior INT NOT NULL,
    communication INT NOT NULL,
    comment TEXT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES ride_requests(id) ON DELETE CASCADE,
    FOREIGN KEY (rider_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (driver_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS call_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    call_id VARCHAR(100) NOT NULL UNIQUE,
    initiator_id INT NOT NULL,
    recipient_id INT NOT NULL,
    status ENUM('ringing', 'active', 'rejected', 'ended') NOT NULL DEFAULT 'ringing',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    ended_at TIMESTAMP NULL DEFAULT NULL,
    duration_seconds INT DEFAULT 0,
    FOREIGN KEY (initiator_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (recipient_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_call_id (call_id),
    INDEX idx_status (status)
);
