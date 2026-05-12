<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/sms_gateway.php';

function sm_test_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

/**
 * @return array{0: string, 1: string|null}
 */
function sm_test_pretty_json(string $raw): array
{
    $raw = trim($raw);
    if ($raw === '') {
        return ['(فارغ)', null];
    }
    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        $pretty = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return [$pretty !== false ? $pretty : $raw, null];
    } catch (JsonException $e) {
        return [$raw, $e->getMessage()];
    }
}

function sm_test_curl_errno_hint(int $errno): string
{
    return match ($errno) {
        0 => 'لا يوجد خطأ من مكتبة cURL.',
        6 => 'تعذّر حل اسم المضيف (DNS): تحقق من الإنترنت أو من كتابة عنوان الـ API.',
        7 => 'رفض الاتصال: الخادم غير مستمع على المنفذ، أو جدار ناري يمنع الوصول.',
        28 => 'انتهت مهلة الاتصال أو الطلب (timeout).',
        35 => 'خطأ في طبقة SSL عند إكمال المصافحة.',
        51, 52, 54 => 'لم يُستلم رد صالح من الخادم (انقطاع أو إغلاق الاتصال).',
        56 => 'فشل استقبال الشبكة: تحقق من الاتصال أو من أن الخادم أغلق الجلسة.',
        60 => 'شهادة SSL غير موثوقة أو تاريخ غير صالح (تحقق من الساعة على السيرفر).',
        77 => 'تعذّر قراءة ملف شهادة SSL (إعدادات PHP/cURL).',
        default => 'راجع وثائق cURL لرمز الخطأ CURLE_' . $errno . '.',
    };
}

function sm_test_http_hint(?int $code): string
{
    if ($code === null) {
        return 'لم يُستخرج رمز HTTP (غالباً فشل اتصال قبل استلام رأس الاستجابة).';
    }
    return match (true) {
        $code >= 200 && $code < 300 => 'نجاح: الخادم قبل الطلب وأجاب بنطاق 2xx.',
        $code === 400 => 'Bad Request: غالباً صيغة الطلب أو الحقول (رقم، نص، JSON) غير مقبولة لدى البوابة. اقرأ جسم الرد أدناه.',
        $code === 401 => 'غير مصرّح: اسم المستخدم أو كلمة المرور (Basic Auth) غير صحيحة.',
        $code === 403 => 'ممنوع: الحساب لا يملك صلاحية لهذا المسار.',
        $code === 404 => 'المسار غير موجود: تحقق من عنوان الـ URL الكامل (بما فيه المسار /messages …).',
        $code === 405 => 'الطريقة غير مسموحة: الخادم لا يقبل POST على هذا العنوان.',
        $code === 408 => 'انتهت مهلة الخادم.',
        $code === 429 => 'تجاوز حد المعدّل (rate limit) من مزوّد الخدمة.',
        $code >= 500 && $code < 600 => 'خطأ من جهة الخادم أو البوابة (5xx).',
        $code === 0 => 'رمز 0: عادة يعني أن الاستجابة HTTP لم تكتمل (انقطاع، TLS، أو خطأ cURL).',
        default => 'راجع معنى رمز HTTP ' . $code . ' في وثائق مزوّد البوابة.',
    };
}

$config = sm_config();
$transport = strtolower(trim((string) ($config['transport'] ?? 'cloud')));
$gwDef = $config['gateway'] ?? [];
$cloudDef = $config['cloud'] ?? [];

$report = null;
/** @var list<string> $formErrors */
$formErrors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = trim((string) ($_POST['phone_number'] ?? ''));
    $message = trim((string) ($_POST['message'] ?? ''));

    $gatewayUrlRaw = trim((string) ($_POST['gateway_url'] ?? ''));
    $gatewayUser = trim((string) ($_POST['gateway_username'] ?? ''));
    $gatewayPass = (string) ($_POST['gateway_password'] ?? '');

    $localFilled = $gatewayUrlRaw !== '' && $gatewayUser !== '' && trim($gatewayPass) !== '';

    $cloudApi = trim((string) ($_POST['cloud_api_url'] ?? ''));
    $cloudUser = trim((string) ($_POST['cloud_username'] ?? ''));
    $cloudPass = (string) ($_POST['cloud_password'] ?? '');

    $targetLabel = '';
    $requestUrl = '';
    $usernameForLog = '';
    $payload = [];

    if ($localFilled) {
        $targetLabel = 'Gateway محلي';
        $requestUrl = sm_normalize_gateway_url($gatewayUrlRaw);
        $usernameForLog = $gatewayUser;
        if ($requestUrl === '' || filter_var($requestUrl, FILTER_VALIDATE_URL) === false) {
            $formErrors[] = 'رابط الـ Gateway غير صالح.';
        }
        if ($gatewayUser === '' || trim($gatewayPass) === '') {
            $formErrors[] = 'أدخل اسم المستخدم وكلمة مرور الـ Gateway.';
        }
        if ($phone === '') {
            $formErrors[] = 'أدخل رقم المستلم.';
        }
        if ($message === '') {
            $formErrors[] = 'أدخل نص الرسالة (حتى للاختبار يمكن نصاً قصيراً).';
        }
        $payload = ['message' => $message, 'phoneNumbers' => [$phone]];
    } else {
        $targetLabel = 'Cloud API';
        $requestUrl = $cloudApi;
        $usernameForLog = $cloudUser;
        if ($cloudApi === '' || $cloudUser === '' || trim($cloudPass) === '') {
            $formErrors[] = 'أدخل عنوان Cloud API واسم المستخدم وكلمة المرور كاملين، أو املأ حقول الـ Gateway المحلي الثلاثة للاختبار المحلي.';
        }
        if ($cloudApi !== '' && filter_var($cloudApi, FILTER_VALIDATE_URL) === false) {
            $formErrors[] = 'عنوان Cloud API غير صالح.';
        }
        if ($phone === '') {
            $formErrors[] = 'أدخل رقم المستلم.';
        }
        if ($message === '') {
            $formErrors[] = 'أدخل نص الرسالة.';
        }
        $payload = ['message' => $message, 'phoneNumbers' => [$phone]];
    }

    if ($formErrors === []) {
        $user = $localFilled ? $gatewayUser : $cloudUser;
        $pass = $localFilled ? $gatewayPass : $cloudPass;
        $meta = sm_post_json_basic_auth_with_meta($requestUrl, $user, $pass, $payload);
        $report = [
            'target' => $targetLabel,
            'request_url' => $requestUrl,
            'username' => $usernameForLog,
            'meta' => $meta,
            'payload' => $payload,
        ];
    }
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>اختبار اتصال — بوابة SMS</title>
    <style>
        :root {
            --bg: #0f1419;
            --card: #1a2332;
            --text: #e7ecf3;
            --muted: #8b9cb3;
            --accent: #3d9cf5;
            --ok: #34c759;
            --err: #ff5c5c;
            --warn: #ffcc00;
            --border: #2a3544;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: "Segoe UI", Tahoma, sans-serif;
            background: var(--bg);
            color: var(--text);
            line-height: 1.5;
        }
        .wrap { max-width: 900px; margin: 0 auto; padding: 1.5rem; }
        h1 { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.25rem; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.25rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1.25rem 1.35rem;
            margin-bottom: 1.25rem;
        }
        label { display: block; font-size: 0.85rem; color: var(--muted); margin-bottom: 0.35rem; }
        input[type="text"], input[type="password"], textarea {
            width: 100%;
            padding: 0.65rem 0.75rem;
            border-radius: 8px;
            border: 1px solid var(--border);
            background: #121a26;
            color: var(--text);
            font-size: 1rem;
        }
        textarea { min-height: 88px; resize: vertical; font-family: ui-monospace, Consolas, monospace; font-size: 0.88rem; }
        .row { margin-bottom: 1rem; }
        button {
            background: var(--accent);
            color: #fff;
            border: none;
            padding: 0.65rem 1.25rem;
            border-radius: 8px;
            font-size: 1rem;
            cursor: pointer;
            font-weight: 600;
        }
        button:hover { filter: brightness(1.08); }
        .hint { font-size: 0.85rem; color: var(--muted); margin-top: 0.75rem; }
        .hint code { font-size: 0.85em; background: #121a26; padding: 0.1em 0.35em; border-radius: 4px; }
        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
            margin: 0 0 0.75rem;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid var(--border);
        }
        .banner {
            padding: 1rem 1.15rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 600;
            border: 1px solid var(--border);
        }
        .banner.ok { background: rgba(52, 199, 89, 0.12); border-color: var(--ok); color: #a8e6b5; }
        .banner.err { background: rgba(255, 92, 92, 0.1); border-color: var(--err); color: #ffb4b4; }
        .banner.warn { background: rgba(255, 204, 0, 0.08); border-color: rgba(255, 204, 0, 0.45); color: #ffe08a; }
        .grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
        }
        @media (max-width: 720px) { .grid2 { grid-template-columns: 1fr; } }
        dl.diag { margin: 0; font-size: 0.9rem; }
        dl.diag dt { color: var(--muted); margin-top: 0.65rem; }
        dl.diag dt:first-child { margin-top: 0; }
        dl.diag dd { margin: 0.2rem 0 0; word-break: break-word; }
        table.meta { width: 100%; border-collapse: collapse; font-size: 0.85rem; margin-top: 0.5rem; }
        table.meta th, table.meta td {
            text-align: right;
            padding: 0.45rem 0.6rem;
            border: 1px solid var(--border);
        }
        table.meta th { color: var(--muted); font-weight: 500; width: 42%; background: #121a26; }
        pre.out {
            margin: 0.5rem 0 0;
            padding: 0.85rem 1rem;
            background: #0a0e14;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: auto;
            max-height: 420px;
            font-size: 0.82rem;
            line-height: 1.45;
            white-space: pre-wrap;
            word-break: break-word;
        }
        .pill {
            display: inline-block;
            font-size: 0.72rem;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            background: rgba(61, 156, 245, 0.2);
            color: var(--accent);
            margin-right: 0.35rem;
            vertical-align: middle;
        }
        .flash-err {
            background: rgba(255, 92, 92, 0.12);
            border: 1px solid var(--err);
            color: #ffb4b4;
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
        }
        .flash-err ul { margin: 0.35rem 0 0; padding-right: 1.2rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <?php $smNav = 'test_connection'; require __DIR__ . '/includes/site_nav.php'; ?>

        <h1>اختبار اتصال <span class="pill">تشخيص</span></h1>
        <p class="sub">
            يُرسل نفس جسم JSON الذي تستخدمه صفحة الإرسال (<code>message</code> + <code>phoneNumbers</code>) دون تسجيل في قاعدة البيانات.
            إن مُلئت حقول الـ Gateway المحلي الثلاثة كلها يُختبر المحلي؛ وإلا يُختبر Cloud API.
            الوضع الافتراضي في <code>config.php</code>: <strong><?= sm_test_h($transport) ?></strong>.
        </p>

        <?php if ($formErrors !== []): ?>
            <div class="flash-err">
                <strong>تعذّر التنفيذ</strong>
                <ul>
                    <?php foreach ($formErrors as $fe): ?>
                        <li><?= sm_test_h($fe) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <div class="card">
            <form method="post" action="" autocomplete="off">
                <p class="section-title">بيانات الطلب</p>
                <div class="grid2">
                    <div class="row" style="margin-bottom:0">
                        <label for="phone_number">رقم المستلم</label>
                        <input type="text" id="phone_number" name="phone_number" maxlength="32" placeholder="+966500000000" value="<?= sm_test_h((string) ($_POST['phone_number'] ?? '')) ?>">
                    </div>
                    <div class="row" style="margin-bottom:0">
                        <label for="message">نص الرسالة</label>
                        <textarea id="message" name="message" maxlength="5000" placeholder="اختبار اتصال"><?= sm_test_h((string) ($_POST['message'] ?? 'اختبار اتصال من test_connection.php')) ?></textarea>
                    </div>
                </div>

                <p class="section-title" style="margin-top:1.25rem">Cloud API</p>
                <div class="row">
                    <label for="cloud_api_url">عنوان Cloud API</label>
                    <input type="text" id="cloud_api_url" name="cloud_api_url" maxlength="512" placeholder="https://api.sms-gate.app/3rdparty/v1/message" value="<?= sm_test_h((string) ($_POST['cloud_api_url'] ?? (string) ($cloudDef['api_url'] ?? ''))) ?>">
                </div>
                <div class="row">
                    <label for="cloud_username">اسم المستخدم</label>
                    <input type="text" id="cloud_username" name="cloud_username" maxlength="128" value="<?= sm_test_h((string) ($_POST['cloud_username'] ?? (string) ($cloudDef['username'] ?? ''))) ?>">
                </div>
                <div class="row">
                    <label for="cloud_password">كلمة المرور</label>
                    <input type="password" id="cloud_password" name="cloud_password" maxlength="512" autocomplete="off" placeholder="من التطبيق" value="">
                </div>

                <p class="section-title" style="margin-top:1.25rem">Gateway محلي (اختياري — إن اكتملت الثلاثة يُفضّل على Cloud)</p>
                <div class="row">
                    <label for="gateway_url">رابط الـ Gateway</label>
                    <input type="text" id="gateway_url" name="gateway_url" maxlength="512" placeholder="http://192.168.1.10:8080/messages" value="<?= sm_test_h((string) ($_POST['gateway_url'] ?? (string) ($gwDef['url'] ?? ''))) ?>">
                </div>
                <div class="row">
                    <label for="gateway_username">اسم المستخدم</label>
                    <input type="text" id="gateway_username" name="gateway_username" maxlength="128" value="<?= sm_test_h((string) ($_POST['gateway_username'] ?? (string) ($gwDef['username'] ?? ''))) ?>">
                </div>
                <div class="row">
                    <label for="gateway_password">كلمة المرور</label>
                    <input type="password" id="gateway_password" name="gateway_password" maxlength="256" autocomplete="off" value="">
                </div>

                <button type="submit">تشغيل الاختبار</button>
                <p class="hint">لا يُحفظ شيء في <code>sms_logs</code>. للمراجعة بعد إرسال حقيقي استخدم <a href="operations.php">العمليات</a>.</p>
            </form>
        </div>

        <?php if (is_array($report)): ?>
            <?php
            /** @var array{target: string, request_url: string, username: string, meta: array, payload: array} $report */
            $m = $report['meta'];
            $http = $m['http_code'] ?? null;
            $ok = (bool) ($m['ok'] ?? false);
            $curlErrno = (int) ($m['curl_errno'] ?? 0);
            [$reqPretty, $reqErr] = $m['request_json'] !== null ? sm_test_pretty_json((string) $m['request_json']) : ['—', null];
            [$resPretty, $resParseErr] = sm_test_pretty_json((string) ($m['response'] ?? ''));
            ?>
            <div class="card">
                <p class="section-title">نتيجة التشخيص — <?= sm_test_h($report['target']) ?></p>

                <?php if ($ok): ?>
                    <div class="banner ok">الاتصال ناجح من منظور HTTP (رمز 2xx) وتعذّر cURL.</div>
                <?php elseif ($curlErrno !== 0): ?>
                    <div class="banner err">فشل طبقة الشبكة أو TLS (cURL)</div>
                <?php elseif ($http !== null && ($http < 200 || $http >= 300)): ?>
                    <div class="banner warn">وصل الرد من الخادم لكن الرمز ليس ضمن النجاح (2xx)</div>
                <?php else: ?>
                    <div class="banner err">رد غير متوقع — راجع التفاصيل أدناه</div>
                <?php endif; ?>

                <dl class="diag">
                    <dt>عنوان الطلب</dt>
                    <dd><code><?= sm_test_h($report['request_url']) ?></code></dd>
                    <dt>Basic Auth — اسم المستخدم</dt>
                    <dd><?= sm_test_h($report['username']) ?> <span class="hint">(كلمة المرور لا تُعرض)</span></dd>
                    <dt>رمز HTTP</dt>
                    <dd><strong><?= sm_test_h((string) ($http ?? '—')) ?></strong> — <?= sm_test_h(sm_test_http_hint($http)) ?></dd>
                    <dt>curl_errno</dt>
                    <dd><strong><?= (int) $curlErrno ?></strong> — <?= sm_test_h(sm_test_curl_errno_hint($curlErrno)) ?></dd>
                    <dt>curl_error</dt>
                    <dd><?= $m['curl_error'] !== null && $m['curl_error'] !== '' ? sm_test_h((string) $m['curl_error']) : '<span class="hint">(لا يوجد)</span>' ?></dd>
                    <dt>نجاح التطبيق (2xx + بدون خطأ cURL)</dt>
                    <dd><?= $ok ? 'نعم' : 'لا' ?></dd>
                </dl>

                <?php
                $info = $m['curl_info'] ?? [];
                if (is_array($info) && $info !== []):
                ?>
                    <p class="section-title" style="margin-top:1rem">معلومات cURL (زمن، IP، SSL…)</p>
                    <table class="meta">
                        <?php foreach ($info as $k => $v): ?>
                            <tr>
                                <th><?= sm_test_h((string) $k) ?></th>
                                <td><?= sm_test_h(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endif; ?>

                <p class="section-title" style="margin-top:1rem">JSON المُرسل</p>
                <?php if ($reqErr !== null): ?>
                    <p class="hint">تحذير: <?= sm_test_h($reqErr) ?></p>
                <?php endif; ?>
                <pre class="out"><?= sm_test_h($reqPretty) ?></pre>

                <p class="section-title" style="margin-top:1rem">جسم الاستجابة (خام)</p>
                <pre class="out"><?= sm_test_h((string) ($m['response'] ?? '')) ?></pre>

                <?php if ($resParseErr === null && trim($resPretty) !== trim((string) ($m['response'] ?? ''))): ?>
                    <p class="section-title" style="margin-top:1rem">الاستجابة (JSON مُنسّق)</p>
                    <pre class="out"><?= sm_test_h($resPretty) ?></pre>
                <?php elseif ($resParseErr !== null && trim((string) ($m['response'] ?? '')) !== ''): ?>
                    <p class="hint">الاستجابة ليست JSON صالحاً: <?= sm_test_h($resParseErr) ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
