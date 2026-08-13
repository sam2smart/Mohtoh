<?php
// rider/my-trips.php
// Superseded by rider/trips.php's proper Active/Upcoming/Completed/
// Cancelled Trips module (same underlying APIs — my_bookings,
// cancel_booking, rate_ride, get_driver_location — just a redesigned
// mobile app-shell UI instead of the old Bootstrap layout). Kept as a
// redirect, not deleted, so existing notification links and bookmarks
// pointing here keep working instead of 404ing.
header('Location: trips.php');
exit;
