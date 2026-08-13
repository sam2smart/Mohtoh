<?php
// POST /api/v1/rides/cancel.php — delegates to api/v1/rider/cancel-booking.php.
// See book.php's header comment for why this is a delegate, not a copy.
require __DIR__ . '/../rider/cancel-booking.php';
