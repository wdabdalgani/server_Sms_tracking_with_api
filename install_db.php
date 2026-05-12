<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$db = $config['db'];

header('Content-Type: text/html; charset=utf-8');

try {
    $pdo = new PDO(
        sprintf('mysql:host=%s;charset=%s', $db['host'], $db['charset']),
        $db['user'],
        $db['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $dbName = $db['name'];
    $pdo->exec(
        'CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbName) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    );
    $pdo->exec('USE `' . str_replace('`', '``', $dbName) . '`');

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS sms_logs (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            phone_number VARCHAR(512) NOT NULL,
            message TEXT NOT NULL,
            gateway_url VARCHAR(512) NULL,
            gateway_username VARCHAR(128) NULL,
            http_code SMALLINT UNSIGNED NULL,
            gateway_response TEXT NULL,
            curl_error VARCHAR(512) NULL,
            success TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS api_server_inbox (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            client_ip VARCHAR(64) NOT NULL DEFAULT \'\',
            user_agent VARCHAR(512) NULL,
            phone_numbers VARCHAR(512) NOT NULL DEFAULT \'\',
            message TEXT NOT NULL,
            payload_sanitized MEDIUMTEXT NULL,
            sms_log_id INT UNSIGNED NULL,
            delivered TINYINT(1) NOT NULL DEFAULT 0,
            validation_error_code VARCHAR(80) NULL,
            validation_error_msg VARCHAR(512) NULL,
            gateway_http_code SMALLINT UNSIGNED NULL,
            curl_error VARCHAR(512) NULL,
            PRIMARY KEY (id),
            KEY idx_created_at (created_at),
            KEY idx_sms_log (sms_log_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>تثبيت قاعدة البيانات</title>';
    echo '<p style="font-family:sans-serif;">تم إنشاء قاعدة البيانات والجدول بنجاح. يمكنك الآن فتح <a href="index.php">index.php</a>.</p>';
    echo '<p style="font-family:sans-serif;color:#666;">للأمان يُفضّل حذف ملف install_db.php بعد التثبيت.</p></html>';
} catch (Throwable $e) {
    http_response_code(500);
    echo '<!DOCTYPE html><html lang="ar" dir="rtl"><meta charset="utf-8"><title>خطأ</title>';
    echo '<pre style="font-family:sans-serif;">' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre></html>';
}
