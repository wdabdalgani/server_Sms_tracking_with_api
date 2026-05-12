<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sms_gateway.php';

/**
 * @return array<int, string>
 */
function sm_normalize_phone_list(?array $extraPhones, string $singlePhone): array
{
    $phones = [];
    if ($extraPhones !== null) {
        foreach ($extraPhones as $p) {
            $t = trim((string) $p);
            if ($t !== '') {
                $phones[] = $t;
            }
        }
    }
    if ($phones === []) {
        $t = trim($singlePhone);
        if ($t !== '') {
            $phones = [$t];
        }
    }
    return array_values(array_unique($phones));
}

function sm_config_transport(): string
{
    return strtolower(trim((string) (sm_config()['transport'] ?? 'cloud')));
}

/**
 * تنفيذ طلب Cloud وتسجيل DB (نواة مشتركة).
 *
 * @param array<int, string> $phones
 * @return array{
 *   status: 'validation_error'|'db_error'|'ok',
 *   message: string,
 *   code?: string,
 *   gateway_url?: string,
 *   gateway_username?: string,
 *   send?: array{http_code: int|null, response: string, curl_error: string|null, ok: bool},
 *   log_id?: int
 * }
 */
function sm_execute_cloud_request_and_log(string $apiUrl, string $cUser, string $cPass, array $phones, string $message): array
{
    $message = trim($message);
    $apiUrl = trim($apiUrl);
    $cUser = trim($cUser);

    if ($apiUrl === '' || $cUser === '' || $cPass === '') {
        return [
            'status' => 'validation_error',
            'code' => 'cloud_credentials_incomplete',
            'message' => 'أدخل عنوان Cloud API واسم المستخدم وكلمة المرور كاملين.',
        ];
    }

    if ($phones === []) {
        return [
            'status' => 'validation_error',
            'code' => 'missing_phone_message',
            'message' => 'يرجى إدخال رقم مستلم واحد على الأقل.',
        ];
    }

    if (count($phones) > 50) {
        return [
            'status' => 'validation_error',
            'code' => 'too_many_recipients',
            'message' => 'الحد الأقصى 50 رقماً في الطلب الواحد.',
        ];
    }

    if ($message === '') {
        return [
            'status' => 'validation_error',
            'code' => 'missing_phone_message',
            'message' => 'يرجى إدخال نص الرسالة.',
        ];
    }

    if (mb_strlen($message) > 5000) {
        return [
            'status' => 'validation_error',
            'code' => 'message_too_long',
            'message' => 'نص الرسالة طويل جداً (الحد 5000 حرف).',
        ];
    }

    if (mb_strlen($cUser) > 128 || strlen($cPass) > 512) {
        return [
            'status' => 'validation_error',
            'code' => 'cloud_auth_too_long',
            'message' => 'اسم المستخدم أو كلمة مرور السحابة يتجاوز الحد المسموح.',
        ];
    }

    if (mb_strlen($apiUrl) > 512 || filter_var($apiUrl, FILTER_VALIDATE_URL) === false) {
        return [
            'status' => 'validation_error',
            'code' => 'invalid_cloud_url',
            'message' => 'عنوان Cloud API غير صالح (يجب أن يبدأ بـ https:// أو http://).',
        ];
    }

    $send = sm_post_json_basic_auth($apiUrl, $cUser, $cPass, [
        'message' => $message,
        'phoneNumbers' => $phones,
    ]);

    $logPhone = count($phones) === 1 ? $phones[0] : implode(',', $phones);
    if (mb_strlen($logPhone) > 512) {
        $logPhone = mb_substr($logPhone, 0, 509) . '…';
    }

    try {
        $pdo = sm_db();
        $stmt = $pdo->prepare(
            'INSERT INTO sms_logs (phone_number, message, gateway_url, gateway_username, http_code, gateway_response, curl_error, success)
             VALUES (:phone, :message, :gw_url, :gw_user, :http_code, :gw_resp, :curl_err, :success)'
        );
        $stmt->execute([
            ':phone' => $logPhone,
            ':message' => $message,
            ':gw_url' => $apiUrl,
            ':gw_user' => $cUser,
            ':http_code' => $send['http_code'],
            ':gw_resp' => $send['response'] !== '' ? $send['response'] : null,
            ':curl_err' => $send['curl_error'],
            ':success' => $send['ok'] ? 1 : 0,
        ]);
        $logId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return [
            'status' => 'db_error',
            'code' => 'database',
            'message' => 'تعذر حفظ السجل في قاعدة البيانات: ' . $e->getMessage() . ' — هل شغّلت install_db.php؟',
            'gateway_url' => $apiUrl,
            'gateway_username' => $cUser,
            'send' => $send,
        ];
    }

    return [
        'status' => 'ok',
        'message' => '',
        'gateway_url' => $apiUrl,
        'gateway_username' => $cUser,
        'send' => $send,
        'log_id' => $logId,
    ];
}

/**
 * سحابة من config.php (للـ API عند عدم تمرير cloud_* في JSON).
 *
 * @param array<int, string> $phones
 */
function sm_run_cloud_dispatch(array $phones, string $message): array
{
    $cloud = sm_config()['cloud'] ?? [];
    $apiUrl = trim((string) ($cloud['api_url'] ?? 'https://api.sms-gate.app/3rdparty/v1/message'));
    $cUser = trim((string) ($cloud['username'] ?? ''));
    $cPass = (string) ($cloud['password'] ?? '');

    if ($cUser === '' || $cPass === '') {
        return [
            'status' => 'validation_error',
            'code' => 'cloud_not_configured',
            'message' => 'السحابة: أضف cloud.username و cloud.password في config.php، أو أرسل cloud_api_url و cloud_username و cloud_password في JSON.',
        ];
    }

    return sm_execute_cloud_request_and_log($apiUrl, $cUser, $cPass, $phones, $message);
}

/**
 * سحابة ببيانات تُمرَّر يدوياً (صفحة index أو حقول JSON).
 *
 * @param array<int, string> $phones
 */
function sm_run_cloud_dispatch_explicit(string $apiUrl, string $cUser, string $cPass, array $phones, string $message): array
{
    return sm_execute_cloud_request_and_log($apiUrl, $cUser, $cPass, $phones, $message);
}

/**
 * @param array<int, string>|null $extraPhones
 */
function sm_run_sms_dispatch(
    string $gatewayUrlRaw,
    string $gatewayUser,
    string $gatewayPass,
    string $phone,
    string $message,
    ?array $extraPhones = null,
    ?string $cloudApiUrl = null,
    ?string $cloudUsername = null,
    ?string $cloudPassword = null
): array {
    $phones = sm_normalize_phone_list($extraPhones, $phone);

    $local = trim($gatewayUrlRaw) !== '' && trim($gatewayUser) !== '' && trim($gatewayPass) !== '';
    if ($local) {
        if (count($phones) !== 1) {
            return [
                'status' => 'validation_error',
                'code' => 'local_single_recipient',
                'message' => 'الـ Gateway المحلي: رقم واحد لكل طلب. استخدم السحابة لعدة أرقام في طلب واحد.',
            ];
        }
        return sm_run_local_gateway_dispatch(trim($gatewayUrlRaw), trim($gatewayUser), trim($gatewayPass), $phones[0], trim($message));
    }

    $explicitCloud = trim((string) $cloudApiUrl) !== ''
        && trim((string) $cloudUsername) !== ''
        && $cloudPassword !== null
        && $cloudPassword !== '';

    if ($explicitCloud) {
        return sm_run_cloud_dispatch_explicit(
            trim((string) $cloudApiUrl),
            trim((string) $cloudUsername),
            $cloudPassword,
            $phones,
            $message
        );
    }

    if (sm_config_transport() === 'local') {
        return [
            'status' => 'validation_error',
            'code' => 'local_gateway_required',
            'message' => 'وضع الشبكة المحلية (transport=local): أدخل رابط الـ Gateway واسم المستخدم وكلمة المرور.',
        ];
    }

    return sm_run_cloud_dispatch($phones, $message);
}

/**
 * @return array{
 *   status: 'validation_error'|'db_error'|'ok',
 *   message: string,
 *   code?: string,
 *   gateway_url?: string,
 *   gateway_username?: string,
 *   send?: array{http_code: int|null, response: string, curl_error: string|null, ok: bool},
 *   log_id?: int
 * }
 */
function sm_run_local_gateway_dispatch(
    string $gatewayUrlRaw,
    string $gatewayUser,
    string $gatewayPass,
    string $phone,
    string $message
): array {
    $gatewayUrlRaw = trim($gatewayUrlRaw);
    $gatewayUser = trim($gatewayUser);
    $gatewayPass = trim($gatewayPass);
    $phone = trim($phone);
    $message = trim($message);

    if ($gatewayUrlRaw === '' || $gatewayUser === '' || $gatewayPass === '') {
        return [
            'status' => 'validation_error',
            'code' => 'missing_gateway_auth',
            'message' => 'يرجى إدخال رابط الـ Gateway واسم المستخدم وكلمة المرور.',
        ];
    }

    $gatewayUrl = sm_normalize_gateway_url($gatewayUrlRaw);
    if ($gatewayUrl === '' || filter_var($gatewayUrl, FILTER_VALIDATE_URL) === false) {
        return [
            'status' => 'validation_error',
            'code' => 'invalid_gateway_url',
            'message' => 'رابط الـ Gateway غير صالح (مثال: http://192.168.1.5:8080/messages).',
        ];
    }

    if (mb_strlen($gatewayUrl) > 512) {
        return [
            'status' => 'validation_error',
            'code' => 'gateway_url_too_long',
            'message' => 'رابط الـ Gateway طويل جداً.',
        ];
    }

    if (mb_strlen($gatewayUser) > 128 || mb_strlen($gatewayPass) > 256) {
        return [
            'status' => 'validation_error',
            'code' => 'gateway_auth_too_long',
            'message' => 'اسم المستخدم أو كلمة المرور يتجاوز الحد المسموح.',
        ];
    }

    if ($phone === '' || $message === '') {
        return [
            'status' => 'validation_error',
            'code' => 'missing_phone_message',
            'message' => 'يرجى إدخال الرقم ونص الرسالة.',
        ];
    }

    if (mb_strlen($message) > 5000) {
        return [
            'status' => 'validation_error',
            'code' => 'message_too_long',
            'message' => 'نص الرسالة طويل جداً (الحد 5000 حرف).',
        ];
    }

    $send = sm_send_via_gateway(
        $gatewayUrl,
        $gatewayUser,
        $gatewayPass,
        $phone,
        $message
    );

    try {
        $pdo = sm_db();
        $stmt = $pdo->prepare(
            'INSERT INTO sms_logs (phone_number, message, gateway_url, gateway_username, http_code, gateway_response, curl_error, success)
             VALUES (:phone, :message, :gw_url, :gw_user, :http_code, :gw_resp, :curl_err, :success)'
        );
        $stmt->execute([
            ':phone' => $phone,
            ':message' => $message,
            ':gw_url' => $gatewayUrl,
            ':gw_user' => $gatewayUser,
            ':http_code' => $send['http_code'],
            ':gw_resp' => $send['response'] !== '' ? $send['response'] : null,
            ':curl_err' => $send['curl_error'],
            ':success' => $send['ok'] ? 1 : 0,
        ]);
        $logId = (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        return [
            'status' => 'db_error',
            'code' => 'database',
            'message' => 'تعذر حفظ السجل في قاعدة البيانات: ' . $e->getMessage() . ' — هل شغّلت install_db.php؟',
            'gateway_url' => $gatewayUrl,
            'gateway_username' => $gatewayUser,
            'send' => $send,
        ];
    }

    return [
        'status' => 'ok',
        'message' => '',
        'gateway_url' => $gatewayUrl,
        'gateway_username' => $gatewayUser,
        'send' => $send,
        'log_id' => $logId,
    ];
}
