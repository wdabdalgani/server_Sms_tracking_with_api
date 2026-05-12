<?php

declare(strict_types=1);

session_start();

require __DIR__ . '/includes/sms_dispatch.php';

function sm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$transport = sm_config_transport();
$gwDef = sm_config()['gateway'];

$flash = null;
if (!empty($_SESSION['flash'])) {
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = (string) ($_POST['phone_number'] ?? '');
    $message = (string) ($_POST['message'] ?? '');

    $gatewayUrlRaw = trim((string) ($_POST['gateway_url'] ?? ''));
    $gatewayUser = trim((string) ($_POST['gateway_username'] ?? ''));
    $gatewayPass = (string) ($_POST['gateway_password'] ?? '');

    $localFilled = $gatewayUrlRaw !== '' && $gatewayUser !== '' && trim($gatewayPass) !== '';

    $cloudApi = trim((string) ($_POST['cloud_api_url'] ?? ''));
    $cloudUser = trim((string) ($_POST['cloud_username'] ?? ''));
    $cloudPass = (string) ($_POST['cloud_password'] ?? '');

    $dispatch = sm_run_sms_dispatch(
        $localFilled ? $gatewayUrlRaw : '',
        $localFilled ? $gatewayUser : '',
        $localFilled ? $gatewayPass : '',
        $phone,
        $message,
        null,
        $localFilled ? null : $cloudApi,
        $localFilled ? null : $cloudUser,
        $localFilled ? null : $cloudPass
    );

    if ($dispatch['status'] === 'validation_error' || $dispatch['status'] === 'db_error') {
        $_SESSION['flash'] = ['type' => 'error', 'text' => $dispatch['message']];
        header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
        exit;
    }

    $result = $dispatch['send'];
    if ($result['ok']) {
        $_SESSION['flash'] = ['type' => 'ok', 'text' => 'تم الإرسال وتسجيل العملية بنجاح.'];
    } else {
        $detail = $result['curl_error'] ?? ('HTTP ' . (string) ($result['http_code'] ?? ''));
        $_SESSION['flash'] = [
            'type' => 'error',
            'text' => 'فشل الإرسال أو رد غير ناجح. تم حفظ المحاولة في السجل. (' . $detail . ')',
        ];
    }

    header('Location: ' . strtok($_SERVER['REQUEST_URI'] ?? 'index.php', '?'));
    exit;
}

try {
    sm_db();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>بوابة SMS — إرسال</title>
    <style>
        :root {
            --bg: #0f1419;
            --card: #1a2332;
            --text: #e7ecf3;
            --muted: #8b9cb3;
            --accent: #3d9cf5;
            --ok: #34c759;
            --err: #ff5c5c;
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
        .wrap { max-width: 720px; margin: 0 auto; padding: 1.5rem; }
        h1 { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.25rem; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.5rem; }
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
        textarea { min-height: 120px; resize: vertical; }
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
        .flash {
            padding: 0.75rem 1rem;
            border-radius: 8px;
            margin-bottom: 1rem;
            font-size: 0.95rem;
        }
        .flash.ok { background: rgba(52, 199, 89, 0.15); border: 1px solid var(--ok); color: #a8e6b5; }
        .flash.error { background: rgba(255, 92, 92, 0.12); border: 1px solid var(--err); color: #ffb4b4; }
        .hint { font-size: 0.85rem; color: var(--muted); margin-top: 1rem; }
        .hint a { color: var(--accent); }
        code { font-size: 0.85em; background: #121a26; padding: 0.1em 0.35em; border-radius: 4px; }
        details.adv { margin-top: 1rem; font-size: 0.88rem; color: var(--muted); }
        details.adv summary { cursor: pointer; color: var(--accent); margin-bottom: 0.5rem; }
        .pill {
            display: inline-block;
            font-size: 0.72rem;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            background: rgba(61, 156, 245, 0.2);
            color: var(--accent);
            margin-left: 0.35rem;
            vertical-align: middle;
        }
        .section-title {
            font-size: 0.95rem;
            font-weight: 600;
            color: #fff;
            margin: 0 0 0.75rem;
            padding-bottom: 0.35rem;
            border-bottom: 1px solid var(--border);
        }
    </style>
</head>
<body>
    <div class="wrap">
        <?php $smNav = 'index'; require __DIR__ . '/includes/site_nav.php'; ?>

        <h1>إرسال SMS <?php if ($transport === 'cloud'): ?><span class="pill">Cloud</span><?php else: ?><span class="pill">شبكة محلية</span><?php endif; ?></h1>

        <?php if ($transport === 'cloud'): ?>
            <p class="sub">
                أدخل يدوياً عنوان <strong>Cloud API</strong> وبيانات الحساب من تطبيق SMS Gate (كل طلب). هذا السيرفر يمرّر الطلب إلى السحابة ثم يسجّل النتيجة.
                الأنظمة الأخرى يمكنها <code>POST api/send.php</code> مع نفس الحقول اختيارياً (<code>cloud_api_url</code> …) أو الاعتماد على <code>config.php</code> فقط للـ API.
            </p>
        <?php else: ?>
            <p class="sub">
                وضع الشبكة المحلية: الإرسال إلى عنوان Android SMS Gateway على LAN. للسحابة عيّن <code>transport</code> إلى <code>cloud</code> في <code>config.php</code>.
            </p>
        <?php endif; ?>

        <?php if ($flash): ?>
            <div class="flash <?= $flash['type'] === 'ok' ? 'ok' : 'error' ?>"><?= sm_h($flash['text']) ?></div>
        <?php endif; ?>

        <?php if (isset($dbError)): ?>
            <div class="flash error">
                تعذر الاتصال بقاعدة البيانات: <?= sm_h($dbError) ?>
                <p class="hint">افتح مرة واحدة: <a href="install_db.php">install_db.php</a> ثم حدّث الصفحة.</p>
            </div>
        <?php else: ?>

        <div class="card">
            <form method="post" action="" autocomplete="off">
                <?php if ($transport === 'cloud'): ?>
                    <p class="section-title">اتصال السحابة (يدوي)</p>
                    <div class="row">
                        <label for="cloud_api_url">عنوان Cloud API</label>
                        <input type="text" id="cloud_api_url" name="cloud_api_url" required maxlength="512" placeholder="https://api.sms-gate.app/3rdparty/v1/message" value="">
                    </div>
                    <div class="row">
                        <label for="cloud_username">اسم المستخدم (Cloud)</label>
                        <input type="text" id="cloud_username" name="cloud_username" required maxlength="128" placeholder="من التطبيق" value="">
                    </div>
                    <div class="row">
                        <label for="cloud_password">كلمة المرور (Cloud)</label>
                        <input type="password" id="cloud_password" name="cloud_password" required maxlength="512" autocomplete="off" placeholder="من التطبيق">
                    </div>
                    <div class="row">
                        <label for="phone_number">رقم المستلم</label>
                        <input type="text" id="phone_number" name="phone_number" required placeholder="+23560000000" maxlength="32">
                    </div>
                    <div class="row">
                        <label for="message">نص الرسالة</label>
                        <textarea id="message" name="message" required maxlength="5000" placeholder="نص الرسالة..."></textarea>
                    </div>
                    <details class="adv">
                        <summary>إرسال عبر Gateway محلي (اختياري)</summary>
                        <p style="margin:0 0 0.75rem;">إن مُلئت الحقول الثلاثة أدناه كلها، يُستخدم الـ Gateway المحلي بدل السحابة لهذا الطلب فقط (تُتجاهل حقول Cloud أعلاه لهذا الإرسال).</p>
                        <div class="row">
                            <label for="gateway_url">رابط الـ Gateway</label>
                            <input type="text" id="gateway_url" name="gateway_url" maxlength="512" placeholder="http://192.168.1.10:8080/messages" value="">
                        </div>
                        <div class="row">
                            <label for="gateway_username">اسم المستخدم</label>
                            <input type="text" id="gateway_username" name="gateway_username" maxlength="128" value="">
                        </div>
                        <div class="row">
                            <label for="gateway_password">كلمة المرور</label>
                            <input type="password" id="gateway_password" name="gateway_password" maxlength="256" value="">
                        </div>
                    </details>
                <?php else: ?>
                    <div class="row">
                        <label for="gateway_url">رابط الـ API (الـ Gateway)</label>
                        <input type="text" id="gateway_url" name="gateway_url" required maxlength="512" placeholder="http://192.168.1.10:8080/messages" value="<?= sm_h((string) ($gwDef['url'] ?? '')) ?>">
                    </div>
                    <div class="row">
                        <label for="gateway_username">اسم المستخدم (Basic Auth)</label>
                        <input type="text" id="gateway_username" name="gateway_username" required maxlength="128" value="<?= sm_h((string) ($gwDef['username'] ?? '')) ?>">
                    </div>
                    <div class="row">
                        <label for="gateway_password">كلمة المرور</label>
                        <input type="password" id="gateway_password" name="gateway_password" required maxlength="256" placeholder="من تطبيق Android SMS Gateway">
                    </div>
                    <div class="row">
                        <label for="phone_number">رقم المستلم</label>
                        <input type="text" id="phone_number" name="phone_number" required placeholder="+23560000000" maxlength="32">
                    </div>
                    <div class="row">
                        <label for="message">نص الرسالة</label>
                        <textarea id="message" name="message" required maxlength="5000" placeholder="نص الرسالة..."></textarea>
                    </div>
                <?php endif; ?>
                <button type="submit">إرسال وحفظ في السجل</button>
            </form>
            <p class="hint">سجل العمليات: <a href="operations.php">operations.php</a></p>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>
