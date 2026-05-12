<?php

declare(strict_types=1);

function sm_config(): array
{
    static $config;
    if ($config === null) {
        $path = dirname(__DIR__) . '/config.php';
        $config = require $path;
    }
    return $config;
}

function sm_ensure_sms_logs_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $r = $pdo->query("SHOW COLUMNS FROM `sms_logs` LIKE 'gateway_url'")->fetch();
        if (!$r) {
            $pdo->exec(
                'ALTER TABLE `sms_logs` ADD COLUMN `gateway_url` VARCHAR(512) NULL DEFAULT NULL AFTER `message`, '
                . 'ADD COLUMN `gateway_username` VARCHAR(128) NULL DEFAULT NULL AFTER `gateway_url`'
            );
        }
        $col = $pdo->query("SHOW COLUMNS FROM `sms_logs` LIKE 'phone_number'")->fetch(PDO::FETCH_ASSOC);
        if ($col !== false && isset($col['Type']) && preg_match('/varchar\s*\(\s*32\s*\)/i', (string) $col['Type'])) {
            $pdo->exec('ALTER TABLE `sms_logs` MODIFY `phone_number` VARCHAR(512) NOT NULL');
        }
    } catch (Throwable $e) {
        // الجدول غير موجود بعد — install_db.php
    }
}

function sm_ensure_api_server_inbox_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS `api_server_inbox` (
                `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `client_ip` VARCHAR(64) NOT NULL DEFAULT \'\',
                `user_agent` VARCHAR(512) NULL DEFAULT NULL,
                `phone_numbers` VARCHAR(512) NOT NULL DEFAULT \'\',
                `message` TEXT NOT NULL,
                `payload_sanitized` MEDIUMTEXT NULL,
                `sms_log_id` INT UNSIGNED NULL DEFAULT NULL,
                `delivered` TINYINT(1) NOT NULL DEFAULT 0,
                `validation_error_code` VARCHAR(80) NULL DEFAULT NULL,
                `validation_error_msg` VARCHAR(512) NULL DEFAULT NULL,
                `gateway_http_code` SMALLINT UNSIGNED NULL DEFAULT NULL,
                `curl_error` VARCHAR(512) NULL DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_created_at` (`created_at`),
                KEY `idx_sms_log` (`sms_log_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    } catch (Throwable $e) {
        // قاعدة غير جاهزة
    }
}

function sm_db(): PDO
{
    static $pdo;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $c = sm_config()['db'];
    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        $c['host'],
        $c['name'],
        $c['charset']
    );
    $pdo = new PDO($dsn, $c['user'], $c['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    sm_ensure_sms_logs_schema($pdo);
    sm_ensure_api_server_inbox_schema($pdo);
    return $pdo;
}
