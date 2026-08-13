<?php
// Registration flow verification — exercises api/auth.php's register action
// exactly as the browser does (POST fields), then reads the DB back.
chdir('C:\xampp\htdocs\Mostmerit\merit_drives - Copy.v1');

$base = 'http://localhost:8123';

function post_form(string $url, array $fields): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $body = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['body' => $body, 'err' => $err, 'code' => $code];
}

$stamp = time();
$cases = [
    ['label' => 'KEKE driver',  'driver_class' => 'keke', 'user_type' => 'driver'],
    ['label' => 'CAR driver',   'driver_class' => 'car',  'user_type' => 'driver'],
    ['label' => 'driver, NO class (must fail)', 'driver_class' => '', 'user_type' => 'driver'],
    ['label' => 'RIDER (unaffected)', 'driver_class' => '', 'user_type' => 'rider'],
];

require_once 'includes/db.php';

foreach ($cases as $i => $c) {
    $email = "verify{$stamp}_{$i}@example.com";
    $phone = '080' . str_pad((string)(10000000 + $stamp % 10000000 + $i), 8, '0', STR_PAD_LEFT);
    $fields = [
        'action' => 'register',
        'fullname' => 'Test Driver',
        'phone' => $phone,
        'email' => $email,
        'password' => 'Passw0rd!23',
        'confirm_password' => 'Passw0rd!23',
        'user_type' => $c['user_type'],
    ];
    if ($c['driver_class'] !== '') $fields['driver_class'] = $c['driver_class'];

    $res = post_form("$base/api/auth.php", $fields);
    echo "=== {$c['label']} ===\n";
    if ($res['err']) { echo "  CURL ERROR: {$res['err']}\n\n"; continue; }
    $json = json_decode($res['body'], true);
    echo "  HTTP {$res['code']} status=" . ($json['status'] ?? '?') . " msg=" . ($json['message'] ?? substr((string)$res['body'],0,120)) . "\n";

    $stmt = $pdo->prepare("SELECT id, user_type, is_keke_driver, private_pickup_enabled, join_ride_enabled FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        echo "  DB: user_type={$row['user_type']} is_keke_driver={$row['is_keke_driver']} private_pickup_enabled={$row['private_pickup_enabled']} join_ride_enabled={$row['join_ride_enabled']}\n";
        $v = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE driver_id = ?");
        $v->execute([$row['id']]);
        echo "  DB: vehicle rows=" . $v->fetchColumn() . " (expect 0 — no stub seeded)\n";
        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$row['id']]);
        echo "  (cleaned up)\n";
    } else {
        echo "  DB: no user row created\n";
    }
    echo "\n";
}
