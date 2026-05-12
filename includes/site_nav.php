<?php

declare(strict_types=1);

/** @var string $smNav 'index' | 'operations' | 'test_connection' | 'server_inbox' */
$smNav = $smNav ?? 'index';
$isIndex = $smNav === 'index';
$isOps = $smNav === 'operations';
$isTestConn = $smNav === 'test_connection';
$isServerInbox = $smNav === 'server_inbox';
?>
<style>
    .site-nav {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 0.75rem;
        padding: 0.65rem 0;
        margin-bottom: 1.25rem;
        border-bottom: 1px solid var(--border, #2a3544);
    }
    .site-nav .brand {
        font-weight: 700;
        font-size: 1rem;
        color: var(--text, #e7ecf3);
        text-decoration: none;
    }
    .site-nav .brand:hover { color: var(--accent, #3d9cf5); }
    .site-nav .links {
        display: flex;
        flex-wrap: wrap;
        gap: 0.35rem;
    }
    .site-nav .links a {
        color: var(--muted, #8b9cb3);
        text-decoration: none;
        padding: 0.45rem 0.75rem;
        border-radius: 8px;
        font-size: 0.9rem;
        font-weight: 500;
        transition: background 0.15s, color 0.15s;
    }
    .site-nav .links a:hover {
        color: var(--text, #e7ecf3);
        background: rgba(61, 156, 245, 0.12);
    }
    .site-nav .links a.active {
        color: #fff;
        background: rgba(61, 156, 245, 0.25);
        border: 1px solid rgba(61, 156, 245, 0.45);
    }
</style>
<nav class="site-nav" aria-label="التنقل الرئيسي">
    <a class="brand" href="index.php">بوابة SMS</a>
    <div class="links">
        <a href="index.php" <?= $isIndex ? 'class="active" aria-current="page"' : '' ?>>إرسال</a>
        <a href="operations.php" <?= $isOps ? 'class="active" aria-current="page"' : '' ?>>العمليات</a>
        <a href="server_inbox.php" <?= $isServerInbox ? 'class="active" aria-current="page"' : '' ?>>طلبات السيرفرات</a>
        <a href="test_connection.php" <?= $isTestConn ? 'class="active" aria-current="page"' : '' ?>>اختبار اتصال</a>
        <a href="api-demo.html">دليل API</a>
    </div>
</nav>
