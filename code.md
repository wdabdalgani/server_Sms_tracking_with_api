# مرجع عميل PHP — الاتصال بـ `api/send.php`

> **ملاحظة:** هذا الملف **وثائق Markdown** (مرجع بالعربية). التشغيل الفعلي للطلب استخدم السكربت **`test.php`** في نفس المجلد بعد ضبط القيم الحساسة محلياً، ولا ترفع الأسرار إلى مستودع عام.

---

## 1. الهدف

هذا النمط من الكود يمثل **عميلاً (Client)** يرسل من سيرفرك (أو نفس سيرفر `sm`) طلب **`POST`** بصيغة **JSON** إلى مشروع الوسيط **`sm`** عند المسار `api/send.php`، مع ترويسة **`X-API-Key`** لمطابقة `api.key` في `config.php`. بعدها يعيد تنسيق الرد كـ JSON ليسهل قراءته من متصفح أو من نظام آخر.

---

## 2. المتطلبات قبل التشغيل

| المتطلب | الشرح |
|---------|--------|
| PHP مع `curl` | السكربت يعتمد على `curl_init` / `curl_exec`. |
| وصول شبكي | السيرفر الذي يشغّل العميل يصل إلى عنوان `https://…/api/send.php`. |
| `api.key` في `config.php` | غير فارغ، ويساوي المفتاح المرسل في الترويسة. |
| قاعدة بيانات `sm` (للسجلات) | مفعّلة عبر `install_db.php` على سيرفر الوسيط. |
| بيانات السحابة | إمّا في جسم JSON (`cloud_*`) أو في `config.php` → `cloud` على سيرفر `sm`. |

---

## 3. القالب الكامل (للنسخ — استبدل العناصر النائبة)

```php
<?php
declare(strict_types=1);

$apiUrl = 'https://نطاقك/مسار_المشروع/api/send.php';
$apiKey = 'YOUR_API_KEY'; // نفس api.key في config.php على سيرفر sm

$payload = [
    'phone_number'   => '+966500000000',
    'message'        => 'نص الرسالة',
    // اختياري: تمرير السحابة مع الطلب (إلا إذا ضُبطت في config.php على سيرفر sm)
    'cloud_api_url'  => 'https://api.sms-gate.app/3rdparty/v1/message',
    'cloud_username' => 'CLOUD_USER',
    'cloud_password' => 'CLOUD_PASS',
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

$raw   = curl_exec($ch);
$errno = curl_errno($ch);
$err   = $errno ? curl_error($ch) : '';
$http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
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
```

**بديل التوثيق:** يمكن استخدام `Authorization: Bearer YOUR_API_KEY` بدل `X-API-Key` (كما في `api/send.php`).

---

## 4. شرح سطر بسطر (نفس الترتيب المنطقي للقالب)

| # | السطر / المقطع | الوظيفة |
|---|------------------|---------|
| 1 | `<?php` | فتح كتلة PHP. |
| 2 | `declare(strict_types=1);` | تفعيل الأنواع الصارمة لتقليل أخطاء التحويل الصامتة. |
| 4 | `$apiUrl = '…';` | عنوان URL الكامل لـ `api/send.php` على سيرفر مشروع `sm`. |
| 5 | `$apiKey = '…';` | المفتاح السري؛ يجب أن يطابق `api.key` في `config.php`. |
| 7–13 | `$payload = [ … ];` | الحقول المرسلة كـ JSON: رقم، رسالة، و(اختياري) ثلاثية السحابة. |
| 8 | `phone_number` | رقم المستلم (يمكن أيضاً `phone` أو مصفوفة `phoneNumbers` حسب الـ API). |
| 9 | `message` | نص الرسالة. |
| 10–12 | `cloud_*` | عند اكتمالها يستخدم الوسيط هذه القيم لهذا الطلب دون الاعتماد على `config.php` للسحابة. |
| 15 | `curl_init($apiUrl)` | إنشاء جلسة cURL نحو عنوان الوسيط. |
| 16–25 | `curl_setopt_array` | ضبط POST، الجسم، إرجاع الرد كنص، الترويسات، المهلة. |
| 17 | `CURLOPT_POST => true` | استخدام HTTP POST. |
| 18 | `CURLOPT_POSTFIELDS` + `json_encode` | تحويل المصفوفة إلى JSON (`JSON_UNESCAPED_UNICODE` للعربية). |
| 19 | `CURLOPT_RETURNTRANSFER` | جعل `curl_exec` يعيد نص الاستجابة بدلاً من طباعته مباشرة. |
| 20–23 | `Content-Type` + `X-API-Key` | نوع الجسم + توثيق الطلب. |
| 24 | `CURLOPT_TIMEOUT` | أقصى وقت انتظار بالثواني. |
| 27 | `curl_exec` | تنفيذ الطلب؛ الناتج عادة JSON من `sm`. |
| 28 | `curl_errno` | رقم خطأ النقل؛ `0` يعني عدم وجود خطأ من cURL. |
| 29 | `curl_error` | وصف الخطأ عند فشل النقل. |
| 30 | `curl_getinfo(...HTTP_CODE)` | رمز HTTP من استجابة سيرفر `sm`. |
| 31 | `curl_close` | إنهاء المقبض. |
| 33 | `header('Content-Type: application/json')` | إخراج هذه الصفحة كـ JSON للعميل النهائي. |
| 34–36 | شرط `errno` | عند فشل الشبكة/TLS: إرجاع `{ ok: false, curl_error }`. |
| 39 | `json_decode(..., true)` | تحويل رد `sm` إلى مصفوفة إن كان JSON صالحاً. |
| 40–45 | `echo json_encode([...])` | دمج رمز HTTP مع جسم الرد (`body`) بصيغة مقروءة (`PRETTY_PRINT`). |

---

## 5. تفسير الحقول في `$payload` (مرتبطة بـ `api/send.php`)

- **`phone_number`** أو **`phone`**: رقم واحد عندما لا تُستخدم `phoneNumbers`.
- **`phoneNumbers`**: قائمة أرقام (سحابة؛ حد أقصى يفرضه الوسيط في `sms_dispatch.php`).
- **`message`**: النص المرسل.
- **`gateway_url` / `gateway_username` / `gateway_password`**: إن اكتملت، يُستخدم **Gateway محلي** (رقم واحد لكل طلب).
- **`cloud_api_url` / `cloud_username` / `cloud_password`**: إن اكتملت، تُستخدم لهذا الطلب بدل قيم `config.php`.

---

## 6. ماذا تتوقع في `body` بعد نجاح الاتصال بـ `sm`؟

عند **HTTP 200** من `sm` غالباً يظهر في الجسم:

- `success`, `log_id`, `inbox_id`, `delivered`, `http_code`, `gateway_response`, `curl_error`, `transport`, …

**مهم:** `success: true` لا يعني بالضرورة أن المزوّد سلّم الرسالة؛ راقب **`delivered`** و`http_code` و`gateway_response`.

---

## 7. أخطاء شائعة

| رمز HTTP من sm | المعنى المعتاد |
|----------------|----------------|
| 401 | مفتاح API مفقود أو خاطئ. |
| 400 | JSON غير صالح أو حقول ناقصة / تحقق منطقي. |
| 503 | `api.key` فارغ على سيرفر `sm` (الـ API معطّل). |
| 500 | خطأ قاعدة بيانات على الوسيط بعد الإرسال. |

---

## 8. الأمان

- لا تضع مفاتيحاً حقيقية في مستودع Git؛ استخدم **متغيرات بيئة** أو ملف إعداد خارج جذر الويب.
- استخدم **HTTPS** بين العميل والوسيط وبين الوسيط والسحابة في الإنتاج.
- راجع أيضاً **`README.md`** و **`api-demo.html`** في المشروع.

---

## 9. مستند HTML مرافق

لعرض مرجعي بصيغة صفحة ويب (جداول، تنسيق، روابط داخلية)، افتح في المتصفح:

**[`code-reference.html`](code-reference.html)**

---

## 10. الملف التنفيذي في المشروع

| الملف | الغرض |
|--------|--------|
| `test.php` | نسخة تشغيل فعلية — عدّل القيم ثم نفّذ من المتصفح أو `php test.php`. |
