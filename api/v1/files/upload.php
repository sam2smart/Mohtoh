<?php
// POST /api/v1/files/upload.php (multipart/form-data)
// Fields: file (the upload), type ('profile'|'vehicle_photo'|'document'),
// document_name (required only when type=document).
// Same validation rules (allowed extensions, filename convention,
// uploads/ directory) as api/profile.php's profile_image handling and
// api/driver-api.php's upload_photo/upload_document actions — not a
// second set of upload rules, the same ones reused for the token-based
// caller.
require_once __DIR__ . '/../_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    v1_error('This endpoint only accepts POST.', 'method_not_allowed', 405);
}

$user = v1_require_auth($pdo);
$userId = (int)$user['id'];

$type = mt_scalar_string($_POST['type'] ?? '');
if (!in_array($type, ['profile', 'vehicle_photo', 'document'], true)) {
    v1_error('type must be one of: profile, vehicle_photo, document.', 'validation_error');
}
if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    v1_error('No file uploaded or an upload error occurred.', 'upload_error');
}

$uploadDir = __DIR__ . '/../../../uploads/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}
$ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));

if ($type === 'profile') {
    $allowed = ['jpg', 'jpeg', 'png'];
    if (!in_array($ext, $allowed)) {
        v1_error('Only JPG, JPEG, and PNG images are allowed for a profile photo.', 'invalid_file_type');
    }
    $filename = 'profile_' . $userId . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $filename)) {
        v1_error('Failed to upload profile image.', 'upload_failed');
    }
    $pdo->prepare("UPDATE users SET profile_image = ? WHERE id = ?")->execute([$filename, $userId]);
    v1_success('Profile image uploaded.', ['file_path' => $filename]);
}

// vehicle_photo and document both require the caller's OWN vehicle —
// never a client-supplied vehicle_id, same as the web upload actions.
v1_require_role($user, ['driver']);
$stmt = $pdo->prepare("SELECT id FROM vehicles WHERE driver_id = ?");
$stmt->execute([$userId]);
$vehicle = $stmt->fetch();
if (!$vehicle) {
    v1_error('Please complete your vehicle profile setup before uploading photos or documents.', 'no_vehicle');
}
$vehicleId = (int)$vehicle['id'];

if ($type === 'vehicle_photo') {
    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    if (!in_array($ext, $allowed)) {
        v1_error('Only JPG, JPEG, PNG and WEBP image formats are supported.', 'invalid_file_type');
    }
    $filename = 'vehicle_' . $vehicleId . '_' . uniqid() . '.' . $ext;
    if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $filename)) {
        v1_error('Failed to save uploaded photo file.', 'upload_failed');
    }
    $pdo->prepare("INSERT INTO vehicle_photos (vehicle_id, photo_path) VALUES (?, ?)")->execute([$vehicleId, $filename]);
    v1_success('Vehicle photo uploaded.', ['file_path' => $filename]);
}

// $type === 'document'
$docName = sanitize_input($_POST['document_name'] ?? '');
if ($docName === '') {
    v1_error('Please specify the name of the document being uploaded (e.g. Drivers License, Car Insurance).', 'validation_error');
}
$allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'];
if (!in_array($ext, $allowed)) {
    v1_error('Only PDF, DOC, DOCX, JPG, JPEG, and PNG document formats are supported.', 'invalid_file_type');
}
$filename = 'doc_' . $vehicleId . '_' . uniqid() . '.' . $ext;
if (!move_uploaded_file($_FILES['file']['tmp_name'], $uploadDir . $filename)) {
    v1_error('Failed to save verification document file.', 'upload_failed');
}
$verificationPublicId = generate_unique_public_id($pdo, 'vehicle_verifications', 'public_id', 'VER');
$pdo->prepare("INSERT INTO vehicle_verifications (public_id, vehicle_id, document_name, document_path) VALUES (?, ?, ?, ?)")
    ->execute([$verificationPublicId, $vehicleId, $docName, $filename]);
v1_success('Verification document uploaded.', ['document_name' => $docName, 'file_path' => $filename]);
