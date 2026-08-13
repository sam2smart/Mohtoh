<?php
session_start();
require_once '../includes/functions.php';
require_login();

if ($_SESSION['user_type'] !== 'driver') {
    header('Location: ../rider/dashboard.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mohtoh Ride Sharing</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-4">
    <h2 class="mb-4">Mohtoh Ride Sharing</h2>
    <p class="text-muted">Create or join shared rides without changing the existing ride-hailing flow.</p>
    <div class="row">
        <?php if ($_SESSION['user_type'] === 'driver'): ?>
        <div class="col-lg-7">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Create a Ride Session</h5>
                    <form id="rideshareForm">
                        <div class="row g-3">
                            <div class="col-md-6"><label class="form-label">Starting Location</label><input type="text" class="form-control" name="start_location" required></div>
                            <div class="col-md-6"><label class="form-label">Destination</label><input type="text" class="form-control" name="destination" required></div>
                            <div class="col-md-6"><label class="form-label">Departure Date</label><input type="date" class="form-control" name="departure_date" required></div>
                            <div class="col-md-6"><label class="form-label">Departure Time</label><input type="time" class="form-control" name="departure_time" required></div>
                            <div class="col-md-6"><label class="form-label">Vehicle Type</label><input type="text" class="form-control" name="vehicle_type" placeholder="e.g. Sedan" required></div>
                            <div class="col-md-6"><label class="form-label">Vehicle Information</label><input type="text" class="form-control" name="vehicle_info" placeholder="Color, Plate, Model" required></div>
                            <div class="col-md-6"><label class="form-label">Available Seats</label><input type="number" class="form-control" name="available_seats" min="1" value="3" required></div>
                            <div class="col-md-6"><label class="form-label">Price per Passenger</label><input type="number" step="0.01" class="form-control" name="price_per_passenger" min="0" value="0" required></div>
                            <div class="col-12"><label class="form-label">Ride Description</label><textarea class="form-control" name="description" rows="3" placeholder="Describe your trip"></textarea></div>
                        </div>
                        <button type="submit" class="btn btn-primary mt-4">Create Ride Session</button>
                    </form>
                    <div id="rideshareMessage" class="mt-3"></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <div class="col-lg-<?= $_SESSION['user_type'] === 'driver' ? '5' : '12' ?>">
            <div class="card shadow-sm">
                <div class="card-body">
                    <h5 class="card-title">Available Ride Sessions</h5>
                    <div id="rideshareList">Loading...</div>
                </div>
            </div>
        </div>
    </div>
</div>
<script>
const form = document.getElementById('rideshareForm');
const messageBox = document.getElementById('rideshareMessage');
const listBox = document.getElementById('rideshareList');

async function loadSessions() {
    const res = await fetch('../api/rideshare.php?action=list');
    const data = await res.json();
    if (data.status !== 'success') {
        listBox.innerHTML = '<div class="alert alert-danger">Unable to load ride sessions.</div>';
        return;
    }
    if (!data.data.length) {
        listBox.innerHTML = '<div class="alert alert-info">No active ride sessions yet.</div>';
        return;
    }
    listBox.innerHTML = data.data.map(item => `
        <div class="border rounded p-3 mb-2">
            <strong>${item.start_location} → ${item.destination}</strong><br>
            <small>${item.departure_date} at ${item.departure_time}</small><br>
            <span class="badge bg-primary">${item.vehicle_type}</span>
            <span class="badge bg-secondary">${item.available_seats} seats</span>
            <span class="badge bg-success">₦${parseFloat(item.price_per_passenger).toFixed(2)} each</span>
            <p class="mt-2 mb-0 small">${item.description || 'No description provided.'}</p>
            ${item.driver_id !== <?= (int)$_SESSION['user_id'] ?> ? '<button class="btn btn-sm btn-outline-primary mt-2" onclick="bookRide(' + item.id + ')">Book Seat</button>' : ''}
        </div>
    `).join('');
}

async function bookRide(rideShareId) {
    const res = await fetch('../api/rideshare.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'book', ride_share_id: rideShareId, seats_booked: 1 })
    });
    const data = await res.json();
    alert(data.message || 'Done');
    if (data.status === 'success') loadSessions();
}

if (form) {
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(form);
        const payload = Object.fromEntries(formData.entries());
        payload.action = 'create';

        const res = await fetch('../api/rideshare.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        messageBox.innerHTML = `<div class="alert ${data.status === 'success' ? 'alert-success' : 'alert-danger'}">${data.message}</div>`;
        if (data.status === 'success') {
            form.reset();
            loadSessions();
        }
    });
}

loadSessions();
</script>
</body>
</html>
