<?php

declare(strict_types=1);

require __DIR__ . '/../includes/sms_dispatch.php';
require_once __DIR__ . '/../includes/api_inbox.php';

function sm_api_json_response(int $httpStatus, array $body): void
{
    http_response_code($httpStatus);
    header('Content-Type: application/json; charset=utf-8');
    $json = json_encode($body, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        http_response_code(500);
        echo '{"success":false,"error":"json_encode_failed","error_code":"internal"}';
        return;
    }
    echo $json;
}

function sm_api_get_key_from_request(): ?string
{
    $x = $_SERVER['HTTP_X_API_KEY'] ?? '';
    if (is_string($x) && trim($x) !== '') {
        return trim($x);
    }
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!is_string($auth)) {
        return null;
    }
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    return null;
}

$config = sm_config();
$apiKeyExpected = (string) ($config['api']['key'] ?? '');
$corsOrigin = trim((string) ($config['api']['cors_origin'] ?? ''));

if ($corsOrigin !== '') {
    header('Access-Control-Allow-Origin: ' . $corsOrigin);
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, Authorization');
    header('Access-Control-Max-Age: 86400');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sm_api_json_response(405, [
        'success' => false,
        'error' => 'Method not allowed. Use POST.',
        'error_code' => 'method_not_allowed',
    ]);
    exit;
}

if ($apiKeyExpected === '') {
    sm_api_json_response(503, [
        'success' => false,
        'error' => 'API disabled: set api.key in config.php',
        'error_code' => 'api_disabled',
    ]);
    exit;
}

$provided = sm_api_get_key_from_request();
if ($provided === null || !hash_equals($apiKeyExpected, $provided)) {
    sm_api_json_response(401, [
        'success' => false,
        'error' => 'Invalid or missing API key. Use header X-API-Key or Authorization: Bearer <key>',
        'error_code' => 'unauthorized',
    ]);
    exit;
}

$raw = file_get_contents('php://input');
if ($raw === false || trim($raw) === '') {
    sm_api_json_response(400, [
        'success' => false,
        'error' => 'Empty request body. Send JSON.',
        'error_code' => 'empty_body',
    ]);
    exit;
}

try {
    $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    sm_api_json_response(400, [
        'success' => false,
        'error' => 'Invalid JSON: ' . $e->getMessage(),
        'error_code' => 'invalid_json',
    ]);
    exit;
}

if (!is_array($payload)) {
    sm_api_json_response(400, [
        'success' => false,
        'error' => 'JSON root must be an object',
        'error_code' => 'invalid_json_shape',
    ]);
    exit;
}

$gwUrl = (string) ($payload['gateway_url'] ?? '');
$gwUser = (string) ($payload['gateway_username'] ?? '');
$gwPass = (string) ($payload['gateway_password'] ?? '');
$message = trim((string) ($payload['message'] ?? ''));

$phonesList = [];
if (isset($payload['phoneNumbers']) && is_array($payload['phoneNumbers'])) {
    foreach ($payload['phoneNumbers'] as $p) {
        $t = trim((string) $p);
        if ($t !== '') {
            $phonesList[] = $t;
        }
    }
}

$fallbackPhone = trim((string) ($payload['phone_number'] ?? $payload['phone'] ?? ''));
$extraForDispatch = $phonesList === [] ? null : $phonesList;

$cloudApiUrl = trim((string) ($payload['cloud_api_url'] ?? ''));
$cloudUserBody = trim((string) ($payload['cloud_username'] ?? ''));
$cloudPassBody = (string) ($payload['cloud_password'] ?? '');
$explicitCloudBody = $cloudApiUrl !== '' && $cloudUserBody !== '' && $cloudPassBody !== '';

$pdoInbox = null;
$inboxId = null;
try {
    $pdoInbox = sm_db();
    $inboxId = sm_api_inbox_insert(
        $pdoInbox,
        sm_api_client_ip(),
        sm_api_user_agent(),
        sm_api_phones_line_from_payload($payload, $fallbackPhone),
        $message,
        sm_api_sanitize_payload_for_log($payload)
    );
} catch (Throwable $e) {
    $pdoInbox = null;
    $inboxId = null;
}

$out = sm_run_sms_dispatch(
    $gwUrl,
    $gwUser,
    $gwPass,
    $fallbackPhone,
    $message,
    $extraForDispatch,
    $explicitCloudBody ? $cloudApiUrl : null,
    $explicitCloudBody ? $cloudUserBody : null,
    $explicitCloudBody ? $cloudPassBody : null
);

if ($out['status'] === 'validation_error') {
    if ($inboxId !== null && $pdoInbox !== null) {
        try {
            sm_api_inbox_update_validation_error(
                $pdoInbox,
                $inboxId,
                (string) ($out['code'] ?? 'validation_error'),
                (string) $out['message']
            );
        } catch (Throwable $e) {
        }
    }
    sm_api_json_response(400, array_merge([
        'success' => false,
        'error' => $out['message'],
        'error_code' => $out['code'] ?? 'validation_error',
    ], $inboxId !== null ? ['inbox_id' => $inboxId] : []));
    exit;
}

if ($out['status'] === 'db_error') {
    /** @var array{http_code: int|null, response: string, curl_error: string|null, ok: bool} $send */
    $send = $out['send'] ?? ['http_code' => null, 'response' => '', 'curl_error' => null, 'ok' => false];
    if ($inboxId !== null && $pdoInbox !== null) {
        try {
            sm_api_inbox_update_db_error(
                $pdoInbox,
                $inboxId,
                (bool) ($send['ok'] ?? false),
                $send['http_code'] ?? null,
                $send['curl_error'] ?? null,
                (string) $out['message']
            );
        } catch (Throwable $e) {
        }
    }
    sm_api_json_response(500, array_merge([
        'success' => false,
        'error' => $out['message'],
        'error_code' => 'database',
        'delivered' => $send['ok'],
        'http_code' => $send['http_code'],
        'gateway_response' => $send['response'],
        'curl_error' => $send['curl_error'],
    ], $inboxId !== null ? ['inbox_id' => $inboxId] : []));
    exit;
}

/** @var array{http_code: int|null, response: string, curl_error: string|null, ok: bool} $send */
$send = $out['send'];
if ($inboxId !== null && $pdoInbox !== null) {
    try {
        sm_api_inbox_update_dispatch_result(
            $pdoInbox,
            $inboxId,
            (int) $out['log_id'],
            (bool) $send['ok'],
            $send['http_code'] ?? null,
            $send['curl_error'] ?? null
        );
    } catch (Throwable $e) {
    }
}
$apiTransport = (trim($gwUrl) !== '' && trim($gwUser) !== '' && trim($gwPass) !== '') ? 'local' : 'cloud';
$cloudCredSource = $apiTransport === 'local' ? null : ($explicitCloudBody ? 'inline_json' : 'config_file');

sm_api_json_response(200, [
    'success' => true,
    'log_id' => $out['log_id'],
    'inbox_id' => $inboxId,
    'delivered' => $send['ok'],
    'http_code' => $send['http_code'],
    'gateway_response' => $send['response'],
    'curl_error' => $send['curl_error'],
    'transport' => $apiTransport,
    'cloud_credentials_source' => $cloudCredSource,
]);
exit;
