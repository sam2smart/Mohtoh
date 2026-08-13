<?php
// includes/audit.php
// Single insert point for admin_audit_logs — every approval/rejection/
// delete action in the app (vehicle review, driver verification,
// premium requests, ride/message reports) calls log_admin_action()
// instead of writing its own INSERT, so "who did what, to what, when,
// why" is always captured the same way. Distinct from
// admin_user_actions (includes/functions.php's suspend/warn/etc log),
// which is user-specific and predates this generic version.
function log_admin_action($pdo, $adminUserId, $action, $entityType, $entityId, $oldStatus = null, $newStatus = null, $reason = null) {
    $publicId = generate_unique_public_id($pdo, 'admin_audit_logs', 'public_id', 'AUD');
    $stmt = $pdo->prepare("INSERT INTO admin_audit_logs (public_id, admin_user_id, action, entity_type, entity_id, old_status, new_status, reason) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->execute([$publicId, $adminUserId, $action, $entityType, $entityId, $oldStatus, $newStatus, $reason]);
    return $publicId;
}
