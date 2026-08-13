<?php
// rider/dashboard.php
session_start();
require_once '../includes/functions.php';

require_login();

if ($_SESSION['user_type'] !== 'rider') {
    header("Location: ../driver/dashboard.php");
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rider Dashboard - Merit Drives</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="../css/style.css">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Outfit', sans-serif;
            background-color: #f1f5f9;
        }
        .ride-card {
            transition: all 0.2s ease-in-out;
        }
        .ride-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-premium);
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-dark navbar-moh py-3">
    <div class="container">
        <a class="navbar-brand fw-bold d-flex align-items-center" href="#">
            <i class="fas fa-car-side me-2"></i>Merit Drives <span class="badge bg-white text-primary ms-2" style="font-size: 0.65rem;">Rider</span>
        </a>
        <div class="d-flex align-items-center">
            <span class="text-white me-3 d-none d-md-inline fw-medium"><i class="fas fa-user-circle me-1"></i>Welcome, <?= htmlspecialchars($_SESSION['fullname']) ?></span>
            <button class="btn btn-sm btn-outline-light rounded-pill px-3" onclick="logout()">Log Out</button>
        </div>
    </div>
</nav>

<div class="container py-4">
    <div class="card border-0 mohtoh-hero-card mb-4">
        <div class="card-body p-4 p-md-5">
            <h2 class="fw-bold mb-2">Find your next carpool ride</h2>
            <p class="text-muted mb-3">Search for planned rides shared by drivers. Enter your trip details below.</p>
            <form id="searchRidesForm">
                <div class="row g-2 align-items-end bg-light p-3 rounded">
                    <div class="col-md-4">
                        <label for="pickupLocation" class="form-label small fw-bold">From</label>
                        <input type="text" class="form-control" id="pickupLocation" name="pickup_location" placeholder="e.g., Ikeja" required>
                    </div>
                    <div class="col-md-4">
                        <label for="destinationLocation" class="form-label small fw-bold">To</label>
                        <input type="text" class="form-control" id="destinationLocation" name="destination" placeholder="e.g., Victoria Island" required>
                    </div>
                    <div class="col-md-3">
                        <label for="departureDate" class="form-label small fw-bold">Date</label>
                        <input type="date" class="form-control" id="departureDate" name="departure_date" required min="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-1">
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search"></i></button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <h4 class="fw-bold mb-3">Available Rides</h4>
            <div id="searchResultsAlert"></div>
            <div id="searchResults" class="row g-3">
                <div class="col-12">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        Your search results will appear here.
                    </div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card glass-panel border-0 p-3">
                <h5 class="fw-bold mb-3 text-primary"><i class="fas fa-ticket-alt me-2"></i>My Bookings</h5>
                <div id="myBookingsList" style="max-height: 400px; overflow-y: auto;">
                    <div class="text-center text-muted py-3">Loading your bookings...</div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Ride Details Modal -->
<div class="modal fade" id="rideDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content glass-panel border-0 p-3">
            <div class="modal-header border-0 pb-1">
                <h5 class="modal-title fw-bold text-dark"><i class="fas fa-info-circle text-primary me-2"></i>Ride Details</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2" id="rideDetailsContent">
                <!-- Content will be loaded via JS -->
            </div>
            <div class="modal-footer border-0" id="rideDetailsFooter">
                <!-- Content will be loaded via JS -->
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, function (char) {
            const map = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
            return map[char];
        });
    }

    async function logout() {
        const formData = new FormData();
        formData.append('action', 'logout');
        const res = await fetch('../api/auth.php', { method: 'POST', body: formData });
        const data = await res.json();
        if (data.status === 'success') {
            window.location.href = data.data.redirect;
        }
    }

    const searchForm = document.getElementById('searchRidesForm');
    const searchResultsContainer = document.getElementById('searchResults');
    const searchResultsAlert = document.getElementById('searchResultsAlert');
    const myBookingsList = document.getElementById('myBookingsList');
    const rideDetailsModalEl = document.getElementById('rideDetailsModal');
    const rideDetailsModal = new bootstrap.Modal(rideDetailsModalEl);
    const rideDetailsContent = document.getElementById('rideDetailsContent');
    const rideDetailsFooter = document.getElementById('rideDetailsFooter');

    searchForm.addEventListener('submit', async (e) => {
        e.preventDefault();
        const formData = new FormData(searchForm);
        const payload = Object.fromEntries(formData.entries());
        payload.action = 'search';

        searchResultsContainer.innerHTML = `<div class="d-flex justify-content-center align-items-center p-5"><div class="spinner-border text-primary" role="status"><span class="visually-hidden">Loading...</span></div></div>`;
        searchResultsAlert.innerHTML = '';

        try {
            const response = await fetch('../api/rideshare.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await response.json();

            if (data.status === 'success' && data.data.rides.length > 0) {
                renderSearchResults(data.data.rides);
            } else if (data.status === 'success') {
                searchResultsContainer.innerHTML = '';
                searchResultsAlert.innerHTML = `<div class="alert alert-warning">No rides found matching your criteria. Try a different date or location.</div>`;
            } else {
                searchResultsContainer.innerHTML = '';
                searchResultsAlert.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message)}</div>`;
            }
        } catch (error) {
            searchResultsContainer.innerHTML = '';
            searchResultsAlert.innerHTML = `<div class="alert alert-danger">An error occurred while searching for rides.</div>`;
        }
    });

    function renderSearchResults(rides) {
        searchResultsContainer.innerHTML = rides.map(ride => `
            <div class="col-md-6">
                <div class="card ride-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h5 class="card-title fw-bold">${escapeHtml(ride.start_location)} <i class="fas fa-arrow-right small"></i> ${escapeHtml(ride.destination)}</h5>
                                <h6 class="card-subtitle mb-2 text-muted small">
                                    <i class="fas fa-user-circle"></i> ${escapeHtml(ride.driver_name)} 
                                    <span class="ms-2"><i class="fas fa-star text-warning"></i> ${parseFloat(ride.driver_rating || 5).toFixed(1)}</span>
                                </h6>
                            </div>
                            <span class="badge bg-primary-subtle text-primary">${escapeHtml(ride.departure_time)}</span>
                        </div>
                        <hr>
                        <div class="d-flex justify-content-between small">
                            <div>
                                <div class="text-muted">Price</div>
                                <div class="fw-bold">₦${Number(ride.price_per_passenger).toLocaleString()}</div>
                            </div>
                            <div>
                                <div class="text-muted">Seats Left</div>
                                <div class="fw-bold">${ride.available_seats}</div>
                            </div>
                             <div>
                                <div class="text-muted">Vehicle</div>
                                <div class="fw-bold">${escapeHtml(ride.vehicle_type)}</div>
                            </div>
                        </div>
                        <div class="d-grid mt-3">
                            <button class="btn btn-primary btn-sm" onclick="viewRideDetails(${ride.id})">View & Book</button>
                        </div>
                    </div>
                </div>
            </div>
        `).join('');
    }

    async function viewRideDetails(rideId) {
        rideDetailsContent.innerHTML = `<div class="text-center p-3"><div class="spinner-border"></div></div>`;
        rideDetailsFooter.innerHTML = '';
        rideDetailsModal.show();

        try {
            const response = await fetch(`../api/rideshare.php?action=details&ride_id=${rideId}`);
            const data = await response.json();

            if (data.status === 'success') {
                renderRideDetailsModal(data.data.ride);
            } else {
                rideDetailsContent.innerHTML = `<div class="alert alert-danger">${escapeHtml(data.message)}</div>`;
            }
        } catch (error) {
            rideDetailsContent.innerHTML = `<div class="alert alert-danger">Could not load ride details.</div>`;
        }
    }

    function renderRideDetailsModal(ride) {
        rideDetailsContent.innerHTML = `
            <div class="mb-2">
                <div class="fw-bold text-dark">${escapeHtml(ride.driver_name || 'Driver')}</div>
                <div class="text-muted small">${escapeHtml(ride.vehicle_type || 'Vehicle')} • ${escapeHtml(ride.vehicle_info || 'Standard vehicle')}</div>
            </div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item px-0"><strong>Route:</strong> ${escapeHtml(ride.start_location)} → ${escapeHtml(ride.destination)}</li>
                <li class="list-group-item px-0"><strong>Departure:</strong> ${escapeHtml(ride.departure_date)} at ${escapeHtml(ride.departure_time)}</li>
                <li class="list-group-item px-0"><strong>Seats remaining:</strong> ${ride.remaining_seats} / ${ride.total_seats}</li>
                <li class="list-group-item px-0"><strong>Passengers joined:</strong> ${ride.passengers_joined || 0}</li>
                <li class="list-group-item px-0"><strong>Price:</strong> ₦${Number(ride.price_per_passenger || 0).toFixed(2)} per passenger</li>
                <li class="list-group-item px-0"><strong>Description:</strong> ${escapeHtml(ride.description || 'No description provided.')}</li>
            </ul>
        `;

        let footerHtml = `<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>`;
        if (ride.already_joined) {
            footerHtml += `<button class="btn btn-success btn-sm" disabled><i class="fas fa-check"></i> Already Joined</button>`;
        } else if (ride.remaining_seats > 0 && ride.status === 'active') {
            footerHtml += `
                <div class="input-group input-group-sm" style="max-width: 200px;">
                     <span class="input-group-text">Seats:</span>
                     <input type="number" id="seatsToBook" class="form-control" value="1" min="1" max="${ride.remaining_seats}">
                     <button class="btn btn-primary" onclick="bookRide(${ride.id})">Book Now</button>
                </div>
            `;
        } else {
             footerHtml += `<button class="btn btn-warning btn-sm" disabled>Ride is Full</button>`;
        }
        rideDetailsFooter.innerHTML = footerHtml;
    }

    async function bookRide(rideId) {
        const seatsInput = document.getElementById('seatsToBook');
        const seats_booked = parseInt(seatsInput.value, 10);

        if (isNaN(seats_booked) || seats_booked < 1) {
            alert('Please enter a valid number of seats.');
            return;
        }

        const bookBtn = rideDetailsFooter.querySelector('button.btn-primary');
        bookBtn.disabled = true;
        bookBtn.innerHTML = `<span class="spinner-border spinner-border-sm"></span>`;

        try {
            const response = await fetch('../api/rideshare.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'book',
                    ride_share_id: rideId,
                    seats_booked: seats_booked
                })
            });
            const data = await response.json();

            if (data.status === 'success') {
                rideDetailsContent.innerHTML = `<div class="alert alert-success"><strong>Success!</strong> You have booked your seat(s).</div>`;
                rideDetailsFooter.innerHTML = `<button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Close</button>`;
                loadMyBookings();
                // Optionally, refresh search results if the form is filled
                if (searchForm.checkValidity()) {
                    searchForm.dispatchEvent(new Event('submit'));
                }
            } else {
                alert(`Booking failed: ${data.message}`);
                bookBtn.disabled = false;
                bookBtn.innerHTML = 'Book Now';
            }
        } catch (error) {
            alert('An error occurred during booking.');
            bookBtn.disabled = false;
            bookBtn.innerHTML = 'Book Now';
        }
    }

    async function loadMyBookings() {
        myBookingsList.innerHTML = `<div class="text-center text-muted py-3">Loading...</div>`;
        try {
            const response = await fetch('../api/rideshare.php?action=my_bookings');
            const data = await response.json();

            if (data.status === 'success' && data.data.rides.length > 0) {
                renderMyBookings(data.data.rides);
            } else {
                myBookingsList.innerHTML = `<div class="text-center text-muted py-3 small">You have no upcoming bookings.</div>`;
            }
        } catch (error) {
            myBookingsList.innerHTML = `<div class="text-center text-danger py-3 small">Could not load bookings.</div>`;
        }
    }

    function renderMyBookings(bookings) {
        myBookingsList.innerHTML = bookings.map(booking => `
            <div class="card mb-2">
                <div class="card-body p-2">
                    <div class="d-flex justify-content-between">
                        <div class="small">
                            <div class="fw-bold">${escapeHtml(booking.start_location)} to ${escapeHtml(booking.destination)}</div>
                            <div class="text-muted">${escapeHtml(booking.departure_date)} at ${escapeHtml(booking.departure_time)}</div>
                        </div>
                        <div class="small text-end">
                             <span class="badge bg-${booking.ride_status === 'active' ? 'success' : 'secondary'}">${escapeHtml(booking.ride_status)}</span>
                             <div>${booking.seats_booked} seat(s)</div>
                        </div>
                    </div>
                     <div class="d-flex justify-content-end mt-1">
                        <button class="btn btn-outline-primary btn-sm me-1" onclick="viewRideDetails(${booking.ride_share_id})">Details</button>
                        ${booking.ride_status !== 'completed' && booking.ride_status !== 'cancelled' ? `<button class="btn btn-outline-danger btn-sm" onclick="cancelBooking(${booking.booking_id})">Cancel</button>` : ''}
                    </div>
                </div>
            </div>
        `).join('');
    }

    async function cancelBooking(bookingId) {
        if (!confirm('Are you sure you want to cancel this booking?')) {
            return;
        }

        try {
            const response = await fetch('../api/rideshare.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action: 'cancel_booking',
                    booking_id: bookingId
                })
            });
            const data = await response.json();

            if (data.status === 'success') {
                alert('Booking cancelled successfully.');
                loadMyBookings();
            } else {
                alert(`Failed to cancel: ${data.message}`);
            }
        } catch (error) {
            alert('An error occurred while cancelling.');
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        // Set default date to today
        const dateInput = document.getElementById('departureDate');
        if (dateInput) {
            dateInput.valueAsDate = new Date();
        }

        loadMyBookings();
    });

    // When modal is hidden, if a search was performed, refresh it
    rideDetailsModalEl.addEventListener('hidden.bs.modal', () => {
        if (searchResultsContainer.innerHTML.includes('ride-card')) {
             searchForm.dispatchEvent(new Event('submit'));
        }
    });

</script>
</body>
</html>