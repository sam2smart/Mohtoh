<?php
// api/promotions.php
// Section 6 (Promotions) CRUD — promo codes rewarding riders (percentage/
// fixed discount, optionally first-ride-only). Redemption itself happens
// inside api/rideshare.php's book action; this endpoint only manages the
// promo_codes rows. Super-admin gated for writes, same tier as
// api/pricing-admin.php and api/regions.php.
session_start();
require_once '../includes/db.php';
require_once '../includes/functions.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'admin') {
    json_response('error', 'Unauthorized');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmt = $pdo->query("
        SELECT p.id, p.public_id, p.code, p.type, p.value, p.applies_to, p.max_uses, p.per_user_limit,
               p.valid_from, p.valid_until, p.is_active, p.created_at,
               (SELECT COUNT(*) FROM promo_redemptions pr WHERE pr.promo_id = p.id) AS times_used
        FROM promo_codes p
        ORDER BY p.created_at DESC
    ");
    json_response('success', 'Promo codes fetched', ['promo_codes' => $stmt->fetchAll()]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_super_admin($pdo);

    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = $_POST;
    }
    require_csrf($input);
    $action = $input['action'] ?? '';
    $adminId = $_SESSION['user_id'];

    if ($action === 'create') {
        $code = strtoupper(sanitize_input(trim($input['code'] ?? '')));
        $type = $input['type'] ?? '';
        $value = isset($input['value']) ? (float)$input['value'] : 0;
        $appliesTo = ($input['applies_to'] ?? 'all') === 'first_ride' ? 'first_ride' : 'all';
        $maxUses = isset($input['max_uses']) && trim((string)$input['max_uses']) !== '' ? (int)$input['max_uses'] : null;
        $perUserLimit = max(1, (int)($input['per_user_limit'] ?? 1));
        $validFrom = !empty($input['valid_from']) ? $input['valid_from'] : null;
        $validUntil = !empty($input['valid_until']) ? $input['valid_until'] : null;

        if ($code === '' || !in_array($type, ['percentage', 'fixed'], true) || $value <= 0) {
            json_response('error', 'Code, a valid type (percentage/fixed), and a positive value are required.');
        }
        if ($type === 'percentage' && $value > 100) {
            json_response('error', 'A percentage discount cannot exceed 100.');
        }

        $stmt = $pdo->prepare("SELECT id FROM promo_codes WHERE code = ?");
        $stmt->execute([$code]);
        if ($stmt->fetch()) {
            json_response('error', 'A promo code with this code already exists.');
        }

        $publicId = generate_unique_public_id($pdo, 'promo_codes', 'public_id', 'PROMO');
        $stmt = $pdo->prepare("INSERT INTO promo_codes (public_id, code, type, value, applies_to, max_uses, per_user_limit, valid_from, valid_until, created_by_admin_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$publicId, $code, $type, $value, $appliesTo, $maxUses, $perUserLimit, $validFrom, $validUntil, $adminId]);

        log_pricing_change($pdo, $adminId, "promo_created:{$code}", null, json_encode(['type' => $type, 'value' => $value, 'applies_to' => $appliesTo]));

        json_response('success', 'Promo code created.', ['public_id' => $publicId]);
    }

    if ($action === 'toggle_active') {
        $promoId = (int)($input['id'] ?? 0);
        if ($promoId <= 0) {
            json_response('error', 'Invalid promo code.');
        }

        $stmt = $pdo->prepare("SELECT code, is_active FROM promo_codes WHERE id = ?");
        $stmt->execute([$promoId]);
        $promo = $stmt->fetch();
        if (!$promo) {
            json_response('error', 'Promo code not found.');
        }

        $newActive = $promo['is_active'] ? 0 : 1;
        $pdo->prepare("UPDATE promo_codes SET is_active = ? WHERE id = ?")->execute([$newActive, $promoId]);
        log_pricing_change($pdo, $adminId, "promo_active:{$promo['code']}", $promo['is_active'], $newActive);

        json_response('success', $newActive ? 'Promo code activated.' : 'Promo code deactivated.');
    }

    if ($action === 'delete') {
        $promoId = (int)($input['id'] ?? 0);
        if ($promoId <= 0) {
            json_response('error', 'Invalid promo code.');
        }

        $stmt = $pdo->prepare("SELECT code FROM promo_codes WHERE id = ?");
        $stmt->execute([$promoId]);
        $promo = $stmt->fetch();
        if (!$promo) {
            json_response('error', 'Promo code not found.');
        }

        $pdo->prepare("DELETE FROM promo_codes WHERE id = ?")->execute([$promoId]);
        log_pricing_change($pdo, $adminId, "promo_deleted:{$promo['code']}", $promo['code'], null);

        json_response('success', 'Promo code deleted.');
    }

    json_response('error', 'Invalid action.');
}

json_response('error', 'Invalid request method');
