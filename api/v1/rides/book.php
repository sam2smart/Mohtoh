<?php
// POST /api/v1/rides/book.php
// Identical operation to api/v1/rider/book-seat.php — rather than
// duplicate the create_ride_booking() orchestration a second time under
// a second URL, this is a direct delegate to that already-implemented,
// already-tested endpoint. One real implementation, two conventional
// entry points (Flutter may reasonably expect either "book a ride" or
// "book a seat as a rider" depending on the screen it's called from).
require __DIR__ . '/../rider/book-seat.php';
