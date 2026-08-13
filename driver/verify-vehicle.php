<?php
// driver/verify-vehicle.php
// Superseded by the vehicleVerificationSheet on driver/dashboard.php
// (same underlying api/driver-api.php actions, same vehicle/document
// upload logic — just the approved Mobile Frame sheet UI instead of the
// old Bootstrap sidebar layout). Kept as a redirect, not deleted, so
// any existing bookmarks/notification links pointing here keep working
// instead of 404ing — same reasoning as rider/my-trips.php and
// rider/wallet.php. Confirmed via project-wide search: nothing in the
// current app links here anymore.
header('Location: dashboard.php?open=vehicle');
exit;
