<?php
// rider/wallet.php
// Superseded by the walletSheet on rider/dashboard.php (same underlying
// api/wallet.php actions — get_history, initialize_topup — just the
// approved Mobile Frame sheet UI instead of the old Bootstrap sidebar
// layout). Kept as a redirect, not deleted, so existing bookmarks and
// notification links pointing here keep working instead of 404ing —
// same reasoning as rider/my-trips.php.
header('Location: dashboard.php?open=wallet');
exit;
