<?php
session_start();
require_once '../includes/functions.php';
require_login();

$ride_id = (int)($_GET['ride_id'] ?? 0);
if ($ride_id <= 0) {
    header('Location: ../rider/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mohtoh Ride Details</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark navbar-moh py-3">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="../rider/dashboard.php">
            <i class="fas fa-car-side me-2"></i>Merit Drives
        </a>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 d-none d-md-inline fw-medium"><i class="fas fa-user-circle me-1"></i>Welcome, <?= htmlspecialchars($_SESSION['fullname']) ?></span>
        </div>
    </div>
</nav>

<div class="container py-4 py-md-5">
    <div class="card border-0 mohtoh-hero-card shadow-sm">
        <div class="card-body p-4 p-md-5">
            <div id="rideDetailsAlert" class="alert d-none"></div>
            <div id="rideDetailsContent">
                <div class="text-muted">Loading ride details...</div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    async function loadRideDetails() {
        const rideId = <?= $ride_id ?>;
        const response = await fetch(`../api/rideshare.php?action=details&ride_id=${rideId}`);
        const data = await response.json();
        const content = document.getElementById('rideDetailsContent');
        const alertBox = document.getElementById('rideDetailsAlert');

        if (data.status !== 'success') {
            content.innerHTML = '<div class="alert alert-danger">' + (data.message || 'Unable to load ride details.') + '</div>';
            return;
        }

        const ride = data.data.ride;
        content.innerHTML = `
            <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                <div>
                    <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
                        <h3 class="fw-bold mb-0">${ride.driver_name || 'Driver'}</h3>
                        ${Number(ride.driver_verified || 0) ? '<span class="badge rounded-pill bg-success-subtle text-success"><i class="fas fa-shield-check me-1"></i>Verified</span>' : '<span class="badge rounded-pill bg-secondary-subtle text-secondary"><i class="fas fa-hourglass-half me-1"></i>Pending</span>'}
                    </div>
                    <p class="text-muted mb-0">${ride.vehicle_type || 'Vehicle'} • ${ride.vehicle_info || 'Standard vehicle'}</p>
                </div>
                <span class="badge bg-success fs-6">₦${Number(ride.price_per_passenger || 0).toFixed(2)}</span>
            </div>
            <hr>
            <div class="row g-4">
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark">Driver</h6>
                    <div class="d-flex align-items-center gap-3">
                        <img src="../assets/${ride.profile_image || 'default.png'}" alt="Driver" class="rounded-circle border" width="56" height="56" onerror="this.src='https://ui-avatars.com/api/?name=${encodeURIComponent(ride.driver_name || 'Driver')}';">
                        <div>
                            <div class="fw-semibold">${ride.driver_name || 'Driver'}</div>
                            <div class="text-muted small">Rating: ${Number(ride.driver_rating || 0).toFixed(1)} / 5</div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark">Vehicle</h6>
                    <ul class="list-unstyled mb-0 small text-muted">
                        <li><strong>Type:</strong> ${ride.vehicle_type || 'N/A'}</li>
                        <li><strong>Model:</strong> ${ride.vehicle_info ? ride.vehicle_info.split(';')[0].replace('Model: ', '') : 'N/A'}</li>
                        <li><strong>Plate:</strong> ${ride.vehicle_info ? ride.vehicle_info.split('Plate: ')[1] || 'N/A' : 'N/A'}</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark">Trip</h6>
                    <ul class="list-unstyled mb-0 small text-muted">
                        <li><strong>Pickup:</strong> ${ride.start_location}</li>
                        <li><strong>Destination:</strong> ${ride.destination}</li>
                        <li><strong>Date:</strong> ${ride.departure_date}</li>
                        <li><strong>Time:</strong> ${ride.departure_time}</li>
                    </ul>
                </div>
                <div class="col-md-6">
                    <h6 class="fw-bold text-dark">Ride</h6>
                    <ul class="list-unstyled mb-0 small text-muted">
                        <li><strong>Available seats:</strong> ${ride.remaining_seats}</li>
                        <li><strong>Price:</strong> ₦${Number(ride.price_per_passenger || 0).toFixed(2)} per passenger</li>
                        <li><strong>Description:</strong> ${ride.description || 'No description provided.'}</li>
                    </ul>
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button class="btn btn-primary rounded-pill px-4 fw-bold" onclick="joinRide(${ride.id})" ${ride.remaining_seats <= 0 || ride.already_joined ? 'disabled' : ''}>
                    <i class="fas fa-user-plus me-1"></i>${ride.already_joined ? 'Already Joined' : ride.remaining_seats <= 0 ? 'Sold Out' : 'Join Ride'}
                </button>
                <a href="../rider/dashboard.php" class="btn btn-outline-secondary rounded-pill px-4">Back</a>
            </div>
        `;
    }

    async function joinRide(rideId) {
        const alertBox = document.getElementById('rideDetailsAlert');
        alertBox.className = 'alert alert-info';
        alertBox.textContent = 'Joining this ride...';
        alertBox.classList.remove('d-none');

        const response = await fetch('../api/rideshare.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'book', ride_share_id: rideId, seats_booked: 1 })
        });
        const data = await response.json();

        if (data.status === 'success') {
            alertBox.className = 'alert alert-success';
            alertBox.textContent = data.message || 'You joined the ride successfully.';
            await loadRideDetails();
        } else {
            alertBox.className = 'alert alert-danger';
            alertBox.textContent = data.message || 'Unable to join the ride.';
        }
        alertBox.classList.remove('d-none');
    }

    document.addEventListener('DOMContentLoaded', loadRideDetails);
</script>
</body>
</html>
