<?php

/**
 * انسخ الإعدادات حسب بيئتك.
 * بعد أول تشغيل ناجح لـ install_db.php يمكنك حذف install_db.php إن أردت.
 */
return [
    'db' => [
        'host' => '127.0.0.1',
        'name' => 'sm_sms',
        'user' => 'root',
        'pass' => '',
        'charset' => 'utf8mb4',
    ],

    /**
     * cloud = وسيطك يتصل بـ api.sms-gate.app (الهاتف أونلاين).
     * local = إرسال عبر عنوان الـ Gateway على الشبكة المحلية (حقول الصفحة).
     */
    'transport' => 'cloud',

    /**
     * اختياري: إن وُضعت مع cloud.password يُستخدم الـ API عندما لا يُرسل الطلب حقول cloud_* في JSON.
     * صفحة index لا تعتمد على هذا — الإدخال من الفورم فقط.
     */
    'cloud' => [
        'api_url' => 'https://api.sms-gate.app/3rdparty/v1/message',
        'username' => '',
        'password' => '',
    ],

    /** افتراضيات وضع local فقط (حقول الفورم) */
    'gateway' => [
        'url' => 'http://192.168.1.10:8080/messages',
        'username' => 'sms',
        'password' => '',
    ],

    'api' => [
        'key' => 'sk_9Xf2LmQ7vRt4NpK1yHc8Zw3BjUa6Md',
        'cors_origin' => '',
    ],
];
