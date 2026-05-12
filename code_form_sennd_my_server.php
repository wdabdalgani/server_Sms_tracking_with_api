<?php
declare(strict_types=1);

$apiUrl  = 'https://sms.itgaantech.com/api/send.php'; // غيّر حسب موقع مشروع sm
$apiKey  = 'sk_9Xf2LmQ7vRt4NpK1yHc8Zw3BjUa6Md'; // نفس api.key في config.php على سيرفر sm

$payload = [
    'phone_number'       => '+23585631500',
    'message'            => 'نص الرسالة',
    'cloud_api_url'      => 'https://api.sms-gate.app/3rdparty/v1/message',
    'cloud_username'     => 'FPMAMJ',
    'cloud_password'     => 's7mct-pxhwddix',
];

$ch = curl_init($apiUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json; charset=utf-8',
        'X-API-Key: ' . $apiKey,
    ],
    CURLOPT_TIMEOUT        => 45,
]);

$raw    = curl_exec($ch);
$errno  = curl_errno($ch);
$err    = $errno ? curl_error($ch) : '';
$http   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json; charset=utf-8');
if ($errno !== 0) {
    echo json_encode(['ok' => false, 'curl_error' => $err], JSON_UNESCAPED_UNICODE);
    exit;
}

$decoded = json_decode((string) $raw, true);
echo json_encode(
    [
        'http_status_from_sm' => $http,
        'body'                => is_array($decoded) ? $decoded : $raw,
    ],
    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
);