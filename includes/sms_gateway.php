<?php

declare(strict_types=1);

function sm_normalize_gateway_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (!preg_match('#\Ahttps?://#i', $url)) {
        $url = 'http://' . ltrim($url, '/');
    }
    return $url;
}

/**
 * POST JSON + Basic Auth مع بيانات تشخيصية (زمن، IP، SSL، …).
 *
 * @param array<string, mixed> $data
 * @return array{
 *   http_code: int|null,
 *   response: string,
 *   curl_error: string|null,
 *   ok: bool,
 *   curl_errno: int,
 *   curl_info: array<string, float|int|string|null>,
 *   request_json: string|null
 * }
 */
function sm_post_json_basic_auth_with_meta(string $url, string $username, string $password, array $data): array
{
    $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);
    if ($jsonData === false) {
        return [
            'http_code' => null,
            'response' => '',
            'curl_error' => 'فشل ترميز JSON',
            'ok' => false,
            'curl_errno' => 0,
            'curl_info' => [],
            'request_json' => null,
        ];
    }

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonData);
    curl_setopt($ch, CURLOPT_USERPWD, $username . ':' . $password);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);

    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = $errno ? curl_error($ch) : null;
    $info = curl_getinfo($ch);
    curl_close($ch);

    if (!is_string($response)) {
        $response = '';
    }

    $httpCodeRaw = $info['http_code'] ?? 0;
    $httpCode = is_numeric($httpCodeRaw) ? (int) $httpCodeRaw : null;

    $ok = $errno === 0 && $httpCode !== null && $httpCode >= 200 && $httpCode < 300;

    $metaKeys = [
        'url',
        'effective_url',
        'primary_ip',
        'primary_port',
        'local_ip',
        'local_port',
        'total_time',
        'namelookup_time',
        'connect_time',
        'appconnect_time',
        'pretransfer_time',
        'starttransfer_time',
        'redirect_count',
        'ssl_verify_result',
        'content_type',
        'size_download',
        'speed_download',
        'http_version',
    ];
    $picked = [];
    foreach ($metaKeys as $k) {
        if (!array_key_exists($k, $info)) {
            continue;
        }
        $v = $info[$k];
        if (is_float($v) || is_int($v) || is_string($v) || $v === null) {
            $picked[$k] = $v;
        }
    }

    return [
        'http_code' => $httpCode,
        'response' => $response,
        'curl_error' => $err,
        'ok' => $ok,
        'curl_errno' => $errno,
        'curl_info' => $picked,
        'request_json' => $jsonData,
    ];
}

/**
 * POST JSON + Basic Auth (متوافق مع Android SMS Gateway المحلي و Cloud API).
 *
 * @param array<string, mixed> $data
 * @return array{http_code: int|null, response: string, curl_error: string|null, ok: bool}
 */
function sm_post_json_basic_auth(string $url, string $username, string $password, array $data): array
{
    $r = sm_post_json_basic_auth_with_meta($url, $username, $password, $data);
    return [
        'http_code' => $r['http_code'],
        'response' => $r['response'],
        'curl_error' => $r['curl_error'],
        'ok' => $r['ok'],
    ];
}

/**
 * @return array{http_code: int|null, response: string, curl_error: string|null, ok: bool}
 */
function sm_send_via_gateway(string $gatewayUrl, string $username, string $password, string $phoneNumber, string $message): array
{
    return sm_post_json_basic_auth($gatewayUrl, $username, $password, [
        'message' => $message,
        'phoneNumbers' => [$phoneNumber],
    ]);
}
