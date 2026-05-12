<?php

declare(strict_types=1);

require __DIR__ . '/includes/db.php';

function sm_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$perPage = 15;
$page = (int) ($_GET['p'] ?? 1);
if ($page < 1) {
    $page = 1;
}

$total = 0;
$totalPages = 1;
$offset = 0;
$rows = [];
$dbError = null;

try {
    $pdo = sm_db();
    $total = (int) $pdo->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare(
        'SELECT id, created_at, phone_number, message, gateway_url, gateway_username, http_code, success,
                curl_error, gateway_response
         FROM sms_logs
         ORDER BY id DESC
         LIMIT :lim OFFSET :off'
    );
    $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$from = $total === 0 ? 0 : $offset + 1;
$to = min($offset + $perPage, $total);

function sm_ops_query_url(int $p): string
{
    return 'operations.php?' . http_build_query(['p' => $p]);
}

$smNav = 'operations';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>سجل العمليات — بوابة SMS</title>
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
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem 1.25rem 2.5rem; }
        h1 { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.35rem; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.25rem; }
        .card {
            background: var(--card);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 1rem 1rem 1.15rem;
            margin-bottom: 1rem;
            overflow: hidden;
        }
        .meta-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
            margin-bottom: 0.85rem;
            font-size: 0.88rem;
            color: var(--muted);
        }
        .table-wrap {
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            margin: 0 -0.15rem;
        }
        table.ops {
            width: 100%;
            min-width: 720px;
            border-collapse: collapse;
            font-size: 0.82rem;
        }
        .ops th, .ops td {
            text-align: right;
            padding: 0.55rem 0.5rem;
            border-bottom: 1px solid var(--border);
            vertical-align: top;
        }
        .ops thead th {
            color: var(--muted);
            font-weight: 600;
            font-size: 0.78rem;
            background: rgba(0, 0, 0, 0.2);
        }
        .ops tbody tr:hover { background: rgba(61, 156, 245, 0.06); }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 0.78rem; }
        .clip {
            max-width: 220px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            display: inline-block;
            vertical-align: top;
        }
        .clip-sm { max-width: 120px; }
        .badge {
            display: inline-block;
            padding: 0.2rem 0.5rem;
            border-radius: 6px;
            font-size: 0.72rem;
            font-weight: 600;
        }
        .badge.ok { background: rgba(52, 199, 89, 0.2); color: var(--ok); }
        .badge.fail { background: rgba(255, 92, 92, 0.2); color: var(--err); }
        .hint-cell {
            color: var(--muted);
            font-size: 0.76rem;
            max-width: 200px;
            word-break: break-word;
            white-space: normal;
        }
        .pagination {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            margin-top: 1.1rem;
            padding-top: 0.85rem;
            border-top: 1px solid var(--border);
        }
        .pagination a, .pagination span {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 2.25rem;
            height: 2.25rem;
            padding: 0 0.45rem;
            border-radius: 8px;
            font-size: 0.85rem;
            text-decoration: none;
            color: var(--text);
            border: 1px solid var(--border);
            background: #121a26;
        }
        .pagination a:hover {
            border-color: var(--accent);
            color: var(--accent);
        }
        .pagination span.ellipsis {
            border: none;
            background: transparent;
            min-width: auto;
            color: var(--muted);
        }
        .pagination span.current {
            background: rgba(61, 156, 245, 0.3);
            border-color: var(--accent);
            color: #fff;
            font-weight: 700;
        }
        .pagination a.nav { min-width: auto; padding: 0 0.85rem; }
        .pagination a.disabled {
            pointer-events: none;
            opacity: 0.4;
        }
        .flash.error {
            padding: 0.85rem 1rem;
            border-radius: 10px;
            background: rgba(255, 92, 92, 0.12);
            border: 1px solid var(--err);
            color: #ffb4b4;
            margin-bottom: 1rem;
        }
        .flash.error a { color: var(--accent); }
        @media (max-width: 640px) {
            .wrap { padding: 0.85rem 0.75rem 2rem; }
            h1 { font-size: 1.15rem; }
        }
    </style>
</head>
<body>
    <div class="wrap">
        <?php require __DIR__ . '/includes/site_nav.php'; ?>

        <h1>سجل العمليات</h1>
        <p class="sub">جميع محاولات الإرسال المحفوظة في قاعدة البيانات، مع تصفح بالصفحات (<?= (int) $perPage ?> سجلًا في الصفحة).</p>

        <?php if ($dbError !== null): ?>
            <div class="flash error">
                تعذر تحميل السجلات: <?= sm_h($dbError) ?>
                <p style="margin:0.5rem 0 0;font-size:0.88rem;">جرّب <a href="install_db.php">install_db.php</a> ثم <a href="index.php">العودة للإرسال</a>.</p>
            </div>
        <?php else: ?>

        <div class="card">
            <div class="meta-bar">
                <span>إجمالي السجلات: <strong style="color:var(--text);"><?= (int) $total ?></strong></span>
                <span>
                    <?php if ($total > 0): ?>
                        عرض <?= (int) $from ?>–<?= (int) $to ?> من <?= (int) $total ?>
                    <?php else: ?>
                        لا توجد عمليات بعد
                    <?php endif; ?>
                </span>
            </div>

            <?php if ($rows === []): ?>
                <p style="color:var(--muted);margin:0;">لا توجد عمليات مسجّلة. ابدأ من <a href="index.php" style="color:var(--accent);">صفحة الإرسال</a>.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="ops">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>التاريخ</th>
                                <th>الرابط</th>
                                <th>مستخدم GW</th>
                                <th>الرقم</th>
                                <th>الرسالة</th>
                                <th>HTTP</th>
                                <th>الحالة</th>
                                <th>ملاحظة</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rows as $row): ?>
                                <?php
                                $ok = (int) $row['success'] === 1;
                                $curlErr = trim((string) ($row['curl_error'] ?? ''));
                                $gwResp = trim((string) ($row['gateway_response'] ?? ''));
                                $hint = '—';
                                if (!$ok) {
                                    if ($curlErr !== '') {
                                        $hint = $curlErr;
                                    } elseif ($gwResp !== '') {
                                        $hint = mb_substr($gwResp, 0, 120);
                                        if (mb_strlen($gwResp) > 120) {
                                            $hint .= '…';
                                        }
                                    }
                                }
                                ?>
                                <tr>
                                    <td class="mono"><?= (int) $row['id'] ?></td>
                                    <td class="mono"><?= sm_h((string) $row['created_at']) ?></td>
                                    <td>
                                        <?php $u = (string) ($row['gateway_url'] ?? ''); ?>
                                        <?php if ($u !== ''): ?>
                                            <span class="clip clip-sm" title="<?= sm_h($u) ?>"><?= sm_h($u) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td><?= sm_h((string) ($row['gateway_username'] ?? '') ?: '—') ?></td>
                                    <td class="mono"><?= sm_h((string) $row['phone_number']) ?></td>
                                    <td><span class="clip" title="<?= sm_h((string) $row['message']) ?>"><?= sm_h((string) $row['message']) ?></span></td>
                                    <td class="mono"><?= $row['http_code'] !== null ? (int) $row['http_code'] : '—' ?></td>
                                    <td>
                                        <?php if ($ok): ?>
                                            <span class="badge ok">نجاح</span>
                                        <?php else: ?>
                                            <span class="badge fail">فشل</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="hint-cell" title="<?= sm_h($hint !== '—' ? $hint : '') ?>"><?= sm_h($hint) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="pagination" aria-label="تصفح الصفحات">
                        <a class="nav<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= $page <= 1 ? '#' : sm_h(sm_ops_query_url($page - 1)) ?>" <?= $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>>السابق</a>

                        <?php
                        $window = 2;
                        $pageNums = [1];
                        $low = max(2, $page - $window);
                        $high = min($totalPages - 1, $page + $window);
                        for ($i = $low; $i <= $high; $i++) {
                            $pageNums[] = $i;
                        }
                        if ($totalPages > 1) {
                            $pageNums[] = $totalPages;
                        }
                        $pageNums = array_values(array_unique($pageNums));
                        sort($pageNums, SORT_NUMERIC);
                        $lastShown = 0;
                        foreach ($pageNums as $pi) {
                            if ($lastShown && $pi - $lastShown > 1) {
                                echo '<span class="ellipsis" aria-hidden="true">…</span>';
                            }
                            if ($pi === $page) {
                                echo '<span class="current" aria-current="page">' . (int) $pi . '</span>';
                            } else {
                                echo '<a href="' . sm_h(sm_ops_query_url($pi)) . '">' . (int) $pi . '</a>';
                            }
                            $lastShown = $pi;
                        }
                        ?>

                        <a class="nav<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : sm_h(sm_ops_query_url($page + 1)) ?>" <?= $page >= $totalPages ? ' tabindex="-1" aria-disabled="true"' : '' ?>>التالي</a>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>
</body>
</html>
