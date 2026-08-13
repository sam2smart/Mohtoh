<?php
// database/test_keke_vehicle_verification.php
//
// Drives the EXISTING vehicle verification workflow over HTTP for a keke,
// a car and a bus, using the same endpoints the browser uses:
//
//   driver : api/driver-api.php  setup_vehicle / upload_document / get_vehicle_info
//   admin  : api/admin-api.php   get_vehicles / vehicle_details / approve|reject_vehicle
//
// It proves keke is not excluded from the workflow and that car and bus
// still behave exactly as before. Each vehicle type runs the same script,
// so a car/bus regression fails here the same way a keke gap would.
//
// Everything it creates (3 temp drivers, their vehicles, documents and
// uploaded files) is removed in the teardown.
//
//   php database/test_keke_vehicle_verification.php

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/eligibility.php';

$BASE = getenv('MOHTOH_BASE_URL') ?: 'http://localhost/Mostmerit/merit_drives%20-%20Copy.v1';
$SESSION_DIR = ini_get('session.save_path') ?: sys_get_temp_dir();
$UPLOAD_DIR  = __DIR__ . '/../uploads/';

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): bool {
    global $pass, $fail;
    if ($ok) { $pass++; echo "  PASS  $label\n"; }
    else     { $fail++; echo "  FAIL  $label" . ($detail !== '' ? "  --> $detail" : '') . "\n"; }
    return $ok;
}

function make_session(array $user): string {
    global $SESSION_DIR;
    $sid = 'kekever' . bin2hex(random_bytes(10));
    $data = '';
    foreach ([
        'user_id'   => (int)$user['id'],
        'user_type' => $user['user_type'],
        'fullname'  => $user['fullname'],
        'email'     => $user['email'],
    ] as $k => $v) {
        $data .= $k . '|' . serialize($v);
    }
    file_put_contents(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid, $data);
    return $sid;
}

function call(string $sid, string $url, ?array $post = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($post));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? ['status' => 'parse_error', 'raw' => substr($body, 0, 400)];
}

/** multipart upload, exactly as the sheet's FormData POST does it. */
function upload(string $sid, string $url, array $fields, string $fileField, string $filePath, string $fileName): array {
    $ch = curl_init($url);
    $fields[$fileField] = new CURLFile($filePath, 'image/png', $fileName);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_COOKIE         => 'PHPSESSID=' . $sid,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $fields,
    ]);
    $body = (string)curl_exec($ch);
    curl_close($ch);
    return json_decode($body, true) ?? ['status' => 'parse_error', 'raw' => substr($body, 0, 400)];
}

function required_doc(array $info, string $type): ?array {
    foreach ($info['data']['required_documents'] ?? [] as $d) {
        if ($d['type'] === $type) return $d;
    }
    return null;
}

echo "=== Vehicle Verification: keke + car + bus ===\n";
echo "base: $BASE\n\n";

$sessions = [];
$createdUsers = [];
$createdFiles = [];

try {
    $admin = $pdo->query("SELECT id, user_type, fullname, email FROM users WHERE user_type = 'admin' ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if (!$admin) { echo "No admin account to review with. Aborting.\n"; exit(1); }
    $sessions['admin'] = make_session($admin);
    echo "admin: {$admin['email']} (id {$admin['id']})\n\n";

    // Every document row that already existed, so the end of the run can
    // prove this test changed none of them. A snapshot is used rather than a
    // plate-name heuristic: it catches a stray write to ANY pre-existing row.
    $baselineDocuments = $pdo->query("SELECT id, status FROM vehicle_verifications")->fetchAll(PDO::FETCH_KEY_PAIR);

    // A tiny real PNG to upload as each document.
    $sample = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'kekever_doc.png';
    file_put_contents($sample, base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8DwHwAFAAH/q842iQAAAABJRU5ErkJggg=='
    ));

    // The four documents MohToh's verification configuration defines. Read
    // from the app, not hard-coded, so this test tracks the real list.
    $required = vehicle_verification_documents();
    check('verification configuration defines the four documents',
        count($required) === 4, json_encode(array_column($required, 'type')));

    foreach (['keke' => 'Keke Napep', 'car' => 'Car', 'bus' => 'Bus'] as $type => $expectedLabel) {
        echo "\n============ vehicle_type = $type ============\n";

        // A fresh driver, so setup_vehicle takes the INSERT path rather than
        // the approved-vehicle edit-request path. Nothing pre-existing is
        // touched by this test.
        $email = "kekever_{$type}_" . bin2hex(random_bytes(4)) . '@example.test';
        $pdo->prepare(
            "INSERT INTO users (user_id, fullname, email, phone, password, user_type, is_verified)
             VALUES (?, ?, ?, ?, ?, 'driver', 0)"
        )->execute([
            'KV' . strtoupper(bin2hex(random_bytes(4))),
            'Kekever ' . ucfirst($type) . ' Driver',
            $email,
            '0' . random_int(7000000000, 9099999999),
            password_hash('Kekever!' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
        ]);
        $driverId = (int)$pdo->lastInsertId();
        $createdUsers[] = $driverId;
        $driver = ['id' => $driverId, 'user_type' => 'driver', 'fullname' => 'Kekever Driver', 'email' => $email];
        $sessions[$type] = make_session($driver);

        // ---- 1. save the vehicle with this type ----
        $res = call($sessions[$type], "$BASE/api/driver-api.php?action=setup_vehicle", [
            'make' => $type === 'keke' ? 'Bajaj' : ($type === 'bus' ? 'Toyota' : 'Honda'),
            'model' => $type === 'keke' ? 'RE Compact' : ($type === 'bus' ? 'Hiace' : 'Accord'),
            'year' => 2019,
            'color' => 'Yellow',
            'plate_number' => 'KV' . strtoupper(bin2hex(random_bytes(3))),
            'vehicle_type' => $type,
            'seats' => $type === 'keke' ? 3 : ($type === 'bus' ? 14 : 4),
        ]);
        check("[$type] vehicle saves", ($res['status'] ?? '') === 'success', json_encode($res));

        $vehicle = $pdo->query(
            "SELECT id, vehicle_type, make, status, verification_status FROM vehicles WHERE driver_id = $driverId LIMIT 1"
        )->fetch(PDO::FETCH_ASSOC);
        check("[$type] vehicle row exists", (bool)$vehicle, json_encode($res));
        if (!$vehicle) continue;
        $vehicleId = (int)$vehicle['id'];
        check("[$type] stored vehicle_type is canonical '$type'", $vehicle['vehicle_type'] === $type, json_encode($vehicle));
        if ($type === 'keke') {
            check("[keke] make 'Bajaj' survived the save (not blanked)", $vehicle['make'] === 'Bajaj', json_encode($vehicle));
            $isKeke = (int)$pdo->query("SELECT is_keke_driver FROM users WHERE id = $driverId")->fetchColumn();
            check('[keke] driver classified as keke server-side', $isKeke === 1, "is_keke_driver=$isKeke");
        }

        // ---- 2. the driver's verification screen ----
        $info = call($sessions[$type], "$BASE/api/driver-api.php?action=get_vehicle_info");
        check("[$type] verification screen loads", ($info['status'] ?? '') === 'success', json_encode($info));
        check("[$type] screen states the vehicle type as '$expectedLabel'",
            ($info['data']['vehicle_type_label'] ?? '') === $expectedLabel,
            json_encode($info['data']['vehicle_type_label'] ?? null));
        check("[$type] screen lists all 4 required documents",
            count($info['data']['required_documents'] ?? []) === 4,
            json_encode(array_column($info['data']['required_documents'] ?? [], 'type')));
        foreach ($required as $r) {
            $slot = required_doc($info, $r['type']);
            check("[$type] '{$r['label']}' starts as missing", ($slot['status'] ?? '') === 'missing', json_encode($slot));
        }

        // ---- 3. upload every required document ----
        foreach ($required as $r) {
            $up = upload($sessions[$type], "$BASE/api/driver-api.php?action=upload_document",
                ['document_type' => $r['type']], 'document', $sample, $r['type'] . '.png');
            check("[$type] upload '{$r['label']}' accepted", ($up['status'] ?? '') === 'success', json_encode($up));

            $row = $pdo->query(
                "SELECT document_type, document_name, document_path, status FROM vehicle_verifications
                 WHERE vehicle_id = $vehicleId AND document_type = " . $pdo->quote($r['type']) . " ORDER BY id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            check("[$type] '{$r['label']}' stored with its document_type", (bool)$row, json_encode($up));
            if ($row) {
                check("[$type] '{$r['label']}' row starts pending", $row['status'] === 'pending', json_encode($row));
                check("[$type] '{$r['label']}' file landed in uploads/",
                    is_file($UPLOAD_DIR . $row['document_path']), (string)$row['document_path']);
                $createdFiles[] = $UPLOAD_DIR . $row['document_path'];
            }
        }

        $info = call($sessions[$type], "$BASE/api/driver-api.php?action=get_vehicle_info");
        foreach ($required as $r) {
            $slot = required_doc($info, $r['type']);
            check("[$type] '{$r['label']}' now shows Pending on the screen",
                ($slot['status'] ?? '') === 'pending', json_encode($slot));
        }

        // ---- 4. another driver cannot upload against this vehicle ----
        // Ownership is resolved from the session, so there is no vehicle id
        // to tamper with; a driver with no vehicle of their own must be
        // refused rather than writing onto someone else's.
        if ($type === 'keke') {
            $otherEmail = 'kekever_intruder_' . bin2hex(random_bytes(4)) . '@example.test';
            $pdo->prepare(
                "INSERT INTO users (user_id, fullname, email, phone, password, user_type, is_verified)
                 VALUES (?, 'Kekever Intruder', ?, ?, ?, 'driver', 0)"
            )->execute([
                'KV' . strtoupper(bin2hex(random_bytes(4))), $otherEmail,
                '0' . random_int(7000000000, 9099999999),
                password_hash('Kekever!' . bin2hex(random_bytes(4)), PASSWORD_DEFAULT),
            ]);
            $intruderId = (int)$pdo->lastInsertId();
            $createdUsers[] = $intruderId;
            $sessions['intruder'] = make_session([
                'id' => $intruderId, 'user_type' => 'driver',
                'fullname' => 'Kekever Intruder', 'email' => $otherEmail,
            ]);
            $before = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_verifications WHERE vehicle_id = $vehicleId")->fetchColumn();
            $bad = upload($sessions['intruder'], "$BASE/api/driver-api.php?action=upload_document",
                ['document_type' => 'insurance', 'vehicle_id' => $vehicleId], 'document', $sample, 'x.png');
            check('another driver cannot upload against this vehicle', ($bad['status'] ?? '') !== 'success', json_encode($bad));
            $after = (int)$pdo->query("SELECT COUNT(*) FROM vehicle_verifications WHERE vehicle_id = $vehicleId")->fetchColumn();
            check('no document was written for the other driver', $after === $before, "before=$before after=$after");
        }

        // ---- 5. admin sees this vehicle and its type ----
        $list = call($sessions['admin'], "$BASE/api/admin-api.php?action=get_vehicles&status=pending");
        $seen = null;
        foreach ($list['data']['vehicles'] ?? $list['data'] ?? [] as $v) {
            if ((int)($v['id'] ?? 0) === $vehicleId) { $seen = $v; break; }
        }
        check("[$type] admin vehicle list includes it", (bool)$seen, json_encode($list['status'] ?? $list));
        if ($seen) {
            check("[$type] admin list shows the type as '$expectedLabel'",
                ($seen['vehicle_type_label'] ?? '') === $expectedLabel, json_encode($seen['vehicle_type_label'] ?? null));
        }

        $detail = call($sessions['admin'], "$BASE/api/admin-api.php?action=vehicle_details&vehicle_id=$vehicleId");
        check("[$type] admin detail loads", ($detail['status'] ?? '') === 'success', json_encode($detail));
        check("[$type] admin detail shows the type as '$expectedLabel'",
            ($detail['data']['vehicle_type_label'] ?? '') === $expectedLabel,
            json_encode($detail['data']['vehicle_type_label'] ?? null));
        $adminDocTypes = array_column($detail['data']['documents'] ?? [], 'document_type');
        foreach ($required as $r) {
            check("[$type] admin can tell '{$r['label']}' apart", in_array($r['type'], $adminDocTypes, true), json_encode($adminDocTypes));
        }

        // ---- 6. admin approves or rejects, and the driver sees it ----
        $decision = ($type === 'bus') ? 'reject_vehicle' : 'approve_vehicle';
        $remarks  = ($type === 'bus') ? 'Test rejection with a reason' : 'Approved by verification test';
        // admin-api.php reads POST actions from the JSON body (not the query
        // string), so the body carries 'action' exactly as the panel sends it.
        $res = call($sessions['admin'], "$BASE/api/admin-api.php", [
            'action'     => $decision,
            'vehicle_id' => $vehicleId,
            'remarks'    => $remarks,
        ]);
        check("[$type] admin can $decision", ($res['status'] ?? '') === 'success', json_encode($res));

        $info = call($sessions[$type], "$BASE/api/driver-api.php?action=get_vehicle_info");
        if ($type === 'bus') {
            check('[bus] driver sees vehicle status Rejected',
                ($info['data']['vehicle']['verification_status'] ?? '') === 'rejected',
                json_encode($info['data']['vehicle']['verification_status'] ?? null));
        } else {
            check("[$type] driver sees vehicle status Verified",
                ($info['data']['vehicle']['verification_status'] ?? '') === 'verified',
                json_encode($info['data']['vehicle']['verification_status'] ?? null));
        }

        // The decision must have reached the documents that were reviewed
        // to make it — every document this driver uploaded is now resolved.
        foreach ($required as $r) {
            $row = $pdo->query(
                "SELECT status FROM vehicle_verifications WHERE vehicle_id = $vehicleId AND document_type = " . $pdo->quote($r['type']) . " ORDER BY id DESC LIMIT 1"
            )->fetch(PDO::FETCH_ASSOC);
            if ($type === 'bus') {
                check('[bus] document status follows the rejection', ($row['status'] ?? '') === 'rejected', json_encode($row));
            } else {
                check("[$type] document status follows the approval", ($row['status'] ?? '') === 'approved', json_encode($row));
            }
        }
        if ($type === 'bus') {
            $reason = $pdo->query(
                "SELECT rejection_reason FROM vehicle_verifications WHERE vehicle_id = $vehicleId AND document_type = 'driver_licence' ORDER BY id DESC LIMIT 1"
            )->fetchColumn();
            check('[bus] document carries the rejection reason', $reason === $remarks, (string)$reason);
        }

        $info = call($sessions[$type], "$BASE/api/driver-api.php?action=get_vehicle_info");
        foreach ($required as $r) {
            $slot = required_doc($info, $r['type']);
            $want = $type === 'bus' ? 'rejected' : 'approved';
            check("[$type] driver screen shows '{$r['label']}' as " . ucfirst($want),
                ($slot['status'] ?? '') === $want, json_encode($slot));
        }
    }

    // ---- 7. documents that existed before this run were not disturbed ----
    $nowDocuments = $pdo->query("SELECT id, status FROM vehicle_verifications")->fetchAll(PDO::FETCH_KEY_PAIR);
    $disturbed = [];
    foreach ($baselineDocuments as $id => $status) {
        if (!array_key_exists($id, $nowDocuments)) { $disturbed[] = "$id deleted"; }
        elseif ($nowDocuments[$id] !== $status) { $disturbed[] = "$id $status -> {$nowDocuments[$id]}"; }
    }
    check('no pre-existing vehicle document was touched', $disturbed === [], implode(', ', $disturbed));

} finally {
    // ---- teardown: remove every row and file this test created ----
    echo "\n---- teardown ----\n";
    if (!empty($createdUsers)) {
        $ids = implode(',', array_map('intval', $createdUsers));
        $pdo->prepare("DELETE FROM vehicle_verifications WHERE vehicle_id IN (SELECT id FROM vehicles WHERE driver_id IN ($ids))")->execute();
        $pdo->prepare("DELETE FROM vehicle_photos WHERE vehicle_id IN (SELECT id FROM vehicles WHERE driver_id IN ($ids))")->execute();
        $pdo->prepare("DELETE FROM vehicle_review_logs WHERE vehicle_id IN (SELECT id FROM vehicles WHERE driver_id IN ($ids))")->execute();
        $pdo->prepare("DELETE FROM vehicle_edit_requests WHERE vehicle_id IN (SELECT id FROM vehicles WHERE driver_id IN ($ids))")->execute();
        $pdo->prepare("DELETE FROM admin_user_actions WHERE user_id IN ($ids)")->execute();
        $pdo->prepare("DELETE FROM vehicles WHERE driver_id IN ($ids)")->execute();
        $pdo->prepare("DELETE FROM users WHERE id IN ($ids)")->execute();
        echo "removed $ids\n";
    }
    foreach ($createdFiles as $f) {
        if (is_file($f)) { unlink($f); echo "removed file $f\n"; }
    }
    foreach (array_unique($sessions) as $sid) {
        @unlink(rtrim($SESSION_DIR, '\\/') . DIRECTORY_SEPARATOR . 'sess_' . $sid);
    }
    if (isset($sample) && is_file($sample)) unlink($sample);
}

echo "\n==== RESULT: $pass passed, $fail failed ====\n";
exit($fail === 0 ? 0 : 1);

