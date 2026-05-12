<?php

declare(strict_types=1);

function sm_api_client_ip(): string
{
    $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
    if (is_string($xff) && $xff !== '') {
        $parts = explode(',', $xff);
        $first = trim((string) ($parts[0] ?? ''));
        if ($first !== '') {
            return mb_substr($first, 0, 64);
        }
    }
    $cf = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? '';
    if (is_string($cf) && trim($cf) !== '') {
        return mb_substr(trim($cf), 0, 64);
    }
    $ra = $_SERVER['REMOTE_ADDR'] ?? '';

    return mb_substr(is_string($ra) ? trim($ra) : '', 0, 64);
}

function sm_api_user_agent(): string
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $s = is_string($ua) ? trim($ua) : '';

    return mb_substr($s, 0, 512);
}

/**
 * @param array<string, mixed> $payload
 */
function sm_api_sanitize_payload_for_log(array $payload): string
{
    $copy = $payload;
    unset($copy['gateway_password'], $copy['cloud_password']);
    $json = json_encode($copy, JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        return '{}';
    }
    if (strlen($json) > 65000) {
        return mb_substr($json, 0, 64997) . '...';
    }

    return $json;
}

/**
 * @param array<string, mixed> $payload
 */
function sm_api_phones_line_from_payload(array $payload, string $fallbackPhone): string
{
    $phones = [];
    if (isset($payload['phoneNumbers']) && is_array($payload['phoneNumbers'])) {
        foreach ($payload['phoneNumbers'] as $p) {
            $t = trim((string) $p);
            if ($t !== '') {
                $phones[] = $t;
            }
        }
    }
    if ($phones === []) {
        $fb = trim($fallbackPhone);
        if ($fb === '') {
            $fb = trim((string) ($payload['phone_number'] ?? $payload['phone'] ?? ''));
        }
        if ($fb !== '') {
            $phones = [$fb];
        }
    }
    $line = implode(',', $phones);
    if (mb_strlen($line) > 512) {
        $line = mb_substr($line, 0, 509) . '…';
    }

    return $line;
}

function sm_api_inbox_insert(PDO $pdo, string $ip, string $ua, string $phonesLine, string $message, string $payloadSanitizedJson): int
{
    $stmt = $pdo->prepare(
        'INSERT INTO api_server_inbox (client_ip, user_agent, phone_numbers, message, payload_sanitized, delivered, sms_log_id)
         VALUES (:ip, :ua, :phones, :msg, :pj, 0, NULL)'
    );
    $stmt->execute([
        ':ip' => $ip,
        ':ua' => $ua === '' ? null : $ua,
        ':phones' => $phonesLine,
        ':msg' => $message,
        ':pj' => $payloadSanitizedJson,
    ]);

    return (int) $pdo->lastInsertId();
}

function sm_api_inbox_update_dispatch_result(
    PDO $pdo,
    int $inboxId,
    int $smsLogId,
    bool $delivered,
    ?int $gatewayHttp,
    ?string $curlErr
): void {
    $stmt = $pdo->prepare(
        'UPDATE api_server_inbox SET
            sms_log_id = :sid,
            delivered = :del,
            gateway_http_code = :hc,
            curl_error = :ce,
            validation_error_code = NULL,
            validation_error_msg = NULL
         WHERE id = :id'
    );
    $stmt->execute([
        ':sid' => $smsLogId,
        ':del' => $delivered ? 1 : 0,
        ':hc' => $gatewayHttp,
        ':ce' => $curlErr !== null && $curlErr !== '' ? mb_substr($curlErr, 0, 512) : null,
        ':id' => $inboxId,
    ]);
}

function sm_api_inbox_update_validation_error(PDO $pdo, int $inboxId, string $code, string $message): void
{
    $stmt = $pdo->prepare(
        'UPDATE api_server_inbox SET
            validation_error_code = :c,
            validation_error_msg = :m,
            delivered = 0,
            sms_log_id = NULL,
            gateway_http_code = NULL,
            curl_error = NULL
         WHERE id = :id'
    );
    $stmt->execute([
        ':c' => mb_substr($code, 0, 80),
        ':m' => mb_substr($message, 0, 512),
        ':id' => $inboxId,
    ]);
}

function sm_api_inbox_update_db_error(
    PDO $pdo,
    int $inboxId,
    bool $delivered,
    ?int $gatewayHttp,
    ?string $curlErr,
    string $dbMessage
): void {
    $stmt = $pdo->prepare(
        'UPDATE api_server_inbox SET
            sms_log_id = NULL,
            delivered = :del,
            gateway_http_code = :hc,
            curl_error = :ce,
            validation_error_code = :vc,
            validation_error_msg = :vm
         WHERE id = :id'
    );
    $stmt->execute([
        ':del' => $delivered ? 1 : 0,
        ':hc' => $gatewayHttp,
        ':ce' => $curlErr !== null && $curlErr !== '' ? mb_substr($curlErr, 0, 512) : null,
        ':vc' => 'database',
        ':vm' => mb_substr($dbMessage, 0, 500),
        ':id' => $inboxId,
    ]);
}
