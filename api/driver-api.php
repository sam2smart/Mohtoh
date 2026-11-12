<?php
// api/driver-api.php
session_start();
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
// Canonical vehicle-type and verification-document vocabulary. Required at
// the top because the vehicle setup, the document upload and the vehicle
// read all speak it.
require_once __DIR__ . '/../includes/eligibility.php';
// In-place optimizer for newly uploaded VEHICLE PHOTOS only. Verification
// documents (upload_document below) are deliberately never optimized so their
// originals stay byte-for-byte identical.
require_once __DIR__ . '/../includes/image_optimizer.php';

header('Content-Type: application/json');

if (!is_logged_in() || $_SESSION['user_type'] !== 'driver') {
    json_response('error', 'Unauthorized access');
}

$driver_id = $_SESSION['user_id'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// Ensure uploads folder structure exists
$upload_dir = '../uploads/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        $input = $_POST;
    }
    
    $post_action = $input['action'] ?? $action;

    if ($post_action === 'setup_vehicle') {
        $make = sanitize_input($input['make'] ?? '');
        $model = sanitize_input($input['model'] ?? '');
        $year = (int)($input['year'] ?? 0);
        $color = sanitize_input($input['color'] ?? '');
        $plate_number = sanitize_input($input['plate_number'] ?? '');
        $ac_available = (int)($input['ac_available'] ?? 1);
        $cleanliness = sanitize_input($input['cleanliness'] ?? 'Good');
        $comfort_level = sanitize_input($input['comfort_level'] ?? 'Standard');
        $seats = (int)($input['seats'] ?? 4);
        $luggage_capacity = sanitize_input($input['luggage_capacity'] ?? 'Standard');
        // Canonicalised on write: the form posts the lowercased UI label
        // ("keke napep"), but every downstream keke check compares against
        // 'keke'. Normalising here is what makes a keke registration
        // actually classify the driver as a Keke driver.
        $vehicle_type = normalize_vehicle_type($input['vehicle_type'] ?? 'car');
        if ($vehicle_type === '') {
            $vehicle_type = 'car';
        }

        if (empty($make) || empty($model) || $year <= 1900 || empty($color) || empty($plate_number) || $seats <= 0) {
            json_response('error', 'All vehicle specification fields are required and must be valid');
        }

        try {
            // Check if vehicle profile already exists
            $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_id = ?");
            $stmt->execute([$driver_id]);
            $existing = $stmt->fetch();

            if ($existing) {
                $vehicle_id = $existing['id'];
                $stmt = $pdo->prepare("SELECT status FROM vehicles WHERE id = ?");
                $stmt->execute([$vehicle_id]);
                $vehicleStatus = $stmt->fetchColumn();

                if ($vehicleStatus === 'approved') {
                    $requestedChanges = [
                        'make' => $make,
                        'model' => $model,
                        'year' => $year,
                        'color' => $color,
                        'plate_number' => $plate_number,
                        'ac_available' => $ac_available,
                        'cleanliness' => $cleanliness,
                        'comfort_level' => $comfort_level,
                        'seats' => $seats,
                        'luggage_capacity' => $luggage_capacity,
                        'vehicle_type' => $vehicle_type
                    ];

                    $edit_request_public_id = generate_unique_public_id($pdo, 'vehicle_edit_requests', 'public_id', 'EDT');
                    $stmt = $pdo->prepare("INSERT INTO vehicle_edit_requests (public_id, vehicle_id, driver_id, requested_changes) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$edit_request_public_id, $vehicle_id, $driver_id, json_encode($requestedChanges)]);

                    $stmt = $pdo->prepare("UPDATE vehicles SET edit_request_status = 'pending' WHERE id = ?");
                    $stmt->execute([$vehicle_id]);

                    $message = 'Your vehicle update request has been submitted and will be reviewed by Admin before it becomes active.';
                } else {
                    // Update vehicle details and reset status to pending for re-verification
                    $stmt = $pdo->prepare("
                        UPDATE vehicles 
                        SET make = ?, model = ?, year = ?, color = ?, plate_number = ?, ac_available = ?, cleanliness = ?, comfort_level = ?, seats = ?, luggage_capacity = ?, vehicle_type = ?, status = 'pending', edit_request_status = 'pending'
                        WHERE driver_id = ?
                    ");
                    $stmt->execute([$make, $model, $year, $color, $plate_number, $ac_available, $cleanliness, $comfort_level, $seats, $luggage_capacity, $vehicle_type, $driver_id]);

                    // A pending/rejected vehicle being re-edited means
                    // this driver is no longer in a verified state —
                    // is_verified must track vehicle approval, otherwise
                    // the driver keeps looking "verified" after their
                    // vehicle details go back under review.
                    $pdo->prepare("UPDATE users SET is_verified = 0 WHERE id = ?")->execute([$driver_id]);

                    $vehicle_id = $existing['id'];
                    $message = 'Vehicle specifications updated! Re-verification is now pending.';
                }
            } else {
                // Insert new vehicle profile
                $vehicle_public_id = generate_unique_public_id($pdo, 'vehicles', 'public_id', 'VEH');
                $stmt = $pdo->prepare("
                    INSERT INTO vehicles (public_id, driver_id, make, model, year, color, plate_number, ac_available, cleanliness, comfort_level, seats, luggage_capacity, vehicle_type, status, edit_request_status)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
                ");
                $stmt->execute([$vehicle_public_id, $driver_id, $make, $model, $year, $color, $plate_number, $ac_available, $cleanliness, $comfort_level, $seats, $luggage_capacity, $vehicle_type]);
                $vehicle_id = $pdo->lastInsertId();
                $message = 'Vehicle specifications registered successfully! Verification is now pending.';
            }

            // Sync driver's vehicle details with 'users' table columns for backward compatibility (model/plate)
            $stmt = $pdo->prepare("UPDATE users SET vehicle_model = ?, vehicle_plate = ? WHERE id = ?");
            $stmt->execute(["$make $model ($color)", $plate_number, $driver_id]);

            // Keep keke classification consistent with the vehicle's ACTUAL
            // persisted type. For an approved vehicle whose change is queued
            // as an edit request the live type has not changed yet, so this
            // reads the current row and stays correct; for a new/pending
            // vehicle the type was just written, so it reflects the update.
            // is_keke_driver is never set from client input directly - it is
            // always derived here (criterion #2: no conflicting data can grant
            // keke functionality).
            sync_keke_classification($pdo, $driver_id);

            $stmt = $pdo->prepare("SELECT public_id FROM vehicles WHERE id = ?");
            $stmt->execute([$vehicle_id]);
            json_response('success', $message, ['vehicle_id' => $vehicle_id, 'vehicle_public_id' => $stmt->fetchColumn()]);

        } catch (PDOException $e) {
            json_response('error', 'Database write error: ' . $e->getMessage());
        }

    } elseif ($post_action === 'upload_photo') {
        // Fetch current vehicle ID
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            json_response('error', 'Please complete your vehicle profile setup before uploading photos');
        }
        $vehicle_id = $vehicle['id'];

        if (!isset($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
            json_response('error', 'No photo file uploaded or file upload error occurred');
        }

        // Validate file type
        $allowed = ['jpg', 'jpeg', 'png', 'webp'];
        $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            json_response('error', 'Only JPG, JPEG, PNG and WEBP image formats are supported');
        }

        $photo_type = sanitize_input($input['photo_type'] ?? $_POST['photo_type'] ?? 'other');
        $filename = 'vehicle_' . $vehicle_id . '_' . uniqid() . '.' . $ext;
        $target = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['photo']['tmp_name'], $target)) {
            // Compress/resize the vehicle photo in place (safe no-op if GD is
            // unavailable; leaves the file untouched on any failure).
            mt_optimize_uploaded_image($target);
            $stmt = $pdo->prepare("INSERT INTO vehicle_photos (vehicle_id, photo_path, photo_type) VALUES (?, ?, ?)");
            $stmt->execute([$vehicle_id, $filename, $photo_type]);
            json_response('success', 'Vehicle photo uploaded successfully!', ['photo_path' => $filename]);
        } else {
            json_response('error', 'Failed to save uploaded photo file');
        }

    } elseif ($post_action === 'upload_document') {
        // Fetch current vehicle ID
        $stmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_id = ?");
        $stmt->execute([$driver_id]);
        $vehicle = $stmt->fetch();
        if (!$vehicle) {
            json_response('error', 'Please complete your vehicle profile setup before uploading documents');
        }
        $vehicle_id = $vehicle['id'];

        $doc_name = sanitize_input($input['document_name'] ?? $_POST['document_name'] ?? '');
        // The sheet now posts which of MohToh's required documents this is,
        // so the (previously unused) vehicle_verifications.document_type
        // column finally records it and both the driver checklist and the
        // admin review can tell the documents apart. Older clients that
        // only post a free-text name still work: the type is resolved from
        // the name and the upload is accepted rather than rejected.
        $doc_type = strtolower(trim((string)($input['document_type'] ?? $_POST['document_type'] ?? '')));
        if ($doc_type === '' || !is_valid_vehicle_document_type($doc_type)) {
            $doc_type = resolve_vehicle_document_type(null, $doc_name);
        }
        if ($doc_name === '') {
            $doc_name = vehicle_document_label($doc_type);
        }
        if (empty($doc_name)) {
            json_response('error', 'Please specify the name of the document being uploaded (e.g. Drivers License, Car Insurance)');
        }

        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            json_response('error', 'No document file uploaded or upload error occurred');
        }

        $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed)) {
            json_response('error', 'Only PDF, DOC, DOCX, JPG, JPEG, and PNG document formats are supported');
        }

        $filename = 'doc_' . $vehicle_id . '_' . uniqid() . '.' . $ext;
        $target = $upload_dir . $filename;

        if (move_uploaded_file($_FILES['document']['tmp_name'], $target)) {
            $verification_public_id = generate_unique_public_id($pdo, 'vehicle_verifications', 'public_id', 'VER');
            $stmt = $pdo->prepare("INSERT INTO vehicle_verifications (public_id, vehicle_id, document_name, document_type, document_path) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$verification_public_id, $vehicle_id, $doc_name, $doc_type, $filename]);
            json_response('success', 'Verification document uploaded successfully!', ['document_name' => $doc_name, 'document_type' => $doc_type, 'document_path' => $filename]);
        } else {
            json_response('error', 'Failed to save verification document file');
        }
    } else {
        json_response('error', 'Invalid action');
    }

} elseif ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if ($action === 'get_vehicle_info') {
        try {
            $stmt = $pdo->prepare("SELECT is_verified FROM users WHERE id = ?");
            $stmt->execute([$driver_id]);
            $isVerified = (bool)$stmt->fetchColumn();

            // Fetch vehicle
            $stmt = $pdo->prepare("SELECT * FROM vehicles WHERE driver_id = ?");
            $stmt->execute([$driver_id]);
            $vehicle = $stmt->fetch();

            if (!$vehicle) {
                json_response('success', 'No vehicle profile set up yet', ['has_vehicle' => false, 'is_verified' => $isVerified]);
            }

            // Fetch photos
            $stmt = $pdo->prepare("SELECT id, photo_path FROM vehicle_photos WHERE vehicle_id = ?");
            $stmt->execute([$vehicle['id']]);
            $photos = $stmt->fetchAll();

            // Fetch documents
            $stmt = $pdo->prepare("SELECT id, public_id, document_name, document_type, document_path, status, rejection_reason FROM vehicle_verifications WHERE vehicle_id = ? AND archived_at IS NULL ORDER BY id");
            $stmt->execute([$vehicle['id']]);
            $documents = $stmt->fetchAll();

            // Resolve each stored document to a checklist slot so documents
            // uploaded before document_type existed still count.
            foreach ($documents as &$doc) {
                $doc['document_type'] = resolve_vehicle_document_type($doc['document_type'], $doc['document_name']);
            }
            unset($doc);

            // The checklist the driver actually works through. Built from
            // the one document set the workflow defines, so a keke driver
            // sees exactly the same required documents as a car or bus
            // driver instead of an unlabelled free-text box. The latest
            // upload per slot wins - a resubmitted document supersedes the
            // one it replaces without either row being deleted.
            $requiredDocuments = [];
            foreach (vehicle_verification_documents() as $required) {
                $latest = null;
                foreach ($documents as $doc) {
                    if ($doc['document_type'] === $required['type']) {
                        $latest = $doc;
                    }
                }
                $requiredDocuments[] = [
                    'type'              => $required['type'],
                    'label'             => $required['label'],
                    'status'            => $latest['status'] ?? 'missing',
                    'document_id'       => $latest['id'] ?? null,
                    'document_path'     => $latest['document_path'] ?? null,
                    'rejection_reason'  => $latest['rejection_reason'] ?? null,
                ];
            }

            // Fetch pending edit request state — must filter by status,
            // otherwise an edit request that was already approved or
            // rejected still gets picked up by ORDER BY...LIMIT 1 and the
            // driver sees a permanent "pending review" banner even long
            // after it was resolved.
            $stmt = $pdo->prepare("SELECT id, public_id, requested_changes, status, admin_remarks, created_at, reviewed_at FROM vehicle_edit_requests WHERE vehicle_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$vehicle['id']]);
            $pendingEditRequest = $stmt->fetch();
            if ($pendingEditRequest) {
                $pendingEditRequest['requested_changes'] = json_decode($pendingEditRequest['requested_changes'], true);
            }

            json_response('success', 'Vehicle profile data retrieved', [
                'has_vehicle' => true,
                'is_verified' => $isVerified,
                'vehicle' => $vehicle,
                'vehicle_type_label' => vehicle_type_label($vehicle['vehicle_type'] ?? ''),
                'photos' => $photos,
                'documents' => $documents,
                'required_documents' => $requiredDocuments,
                'pending_edit_request' => $pendingEditRequest
            ]);

        } catch (PDOException $e) {
            json_response('error', 'Database read query error: ' . $e->getMessage());
        }
    } else {
        json_response('error', 'Invalid action');
    }
}
?>
