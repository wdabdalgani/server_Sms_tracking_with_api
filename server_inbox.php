<?php

declare(strict_types=1);

require __DIR__ . '/includes/db.php';

function sm_inbox_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$perPage = 20;
$page = (int) ($_GET['p'] ?? 1);
if ($page < 1) {
    $page = 1;
}

$detailId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$detailRow = null;
$dbError = null;
$total = 0;
$totalPages = 1;
$offset = 0;
$rows = [];

try {
    $pdo = sm_db();
    if ($detailId > 0) {
        $st = $pdo->prepare('SELECT * FROM api_server_inbox WHERE id = :id LIMIT 1');
        $st->execute([':id' => $detailId]);
        $detailRow = $st->fetch();
        if (!$detailRow) {
            $detailId = 0;
        }
    } else {
        $total = (int) $pdo->query('SELECT COUNT(*) FROM api_server_inbox')->fetchColumn();
        $totalPages = max(1, (int) ceil($total / $perPage));
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare(
            'SELECT id, created_at, client_ip, user_agent, phone_numbers, message, sms_log_id, delivered,
                    validation_error_code, validation_error_msg, gateway_http_code, curl_error
             FROM api_server_inbox
             ORDER BY id DESC
             LIMIT :lim OFFSET :off'
        );
        $stmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    }
} catch (Throwable $e) {
    $dbError = $e->getMessage();
}

$from = $total === 0 ? 0 : $offset + 1;
$to = min($offset + $perPage, $total);

function sm_inbox_query_url(int $p): string
{
    return 'server_inbox.php?' . http_build_query(['p' => $p]);
}

$smNav = 'server_inbox';
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>طلبات السيرفرات — بوابة SMS</title>
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
        body { margin: 0; min-height: 100vh; font-family: "Segoe UI", Tahoma, sans-serif; background: var(--bg); color: var(--text); line-height: 1.5; }
        .wrap { max-width: 1100px; margin: 0 auto; padding: 1rem 1.25rem 2.5rem; }
        h1 { font-size: 1.35rem; font-weight: 600; margin: 0 0 0.35rem; }
        .sub { color: var(--muted); font-size: 0.9rem; margin-bottom: 1.25rem; }
        .card { background: var(--card); border: 1px solid var(--border); border-radius: 12px; padding: 1rem 1.1rem 1.15rem; margin-bottom: 1rem; overflow: hidden; }
        .meta-bar { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.85rem; font-size: 0.88rem; color: var(--muted); }
        .table-wrap { overflow-x: auto; margin: 0 -0.15rem; }
        table.ops { width: 100%; min-width: 760px; border-collapse: collapse; font-size: 0.82rem; }
        .ops th, .ops td { text-align: right; padding: 0.55rem 0.5rem; border-bottom: 1px solid var(--border); vertical-align: top; }
        .ops thead th { color: var(--muted); font-weight: 600; font-size: 0.78rem; background: rgba(0,0,0,0.2); }
        .ops tbody tr:hover { background: rgba(61, 156, 245, 0.06); }
        .mono { font-family: ui-monospace, Consolas, monospace; font-size: 0.78rem; }
        .clip { max-width: 180px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; display: inline-block; vertical-align: top; }
        .badge { display: inline-block; padding: 0.2rem 0.5rem; border-radius: 6px; font-size: 0.72rem; font-weight: 600; }
        .badge.ok { background: rgba(52, 199, 89, 0.2); color: var(--ok); }
        .badge.fail { background: rgba(255, 92, 92, 0.2); color: var(--err); }
        .badge.warn { background: rgba(255, 193, 7, 0.15); color: #ffc857; }
        .flash.error { padding: 0.85rem 1rem; border-radius: 8px; background: rgba(255, 92, 92, 0.12); border: 1px solid var(--err); color: #ffb4b4; margin-bottom: 1rem; }
        .flash.error a { color: var(--accent); }
        .pagination { display: flex; flex-wrap: wrap; align-items: center; justify-content: center; gap: 0.35rem; margin-top: 1.1rem; padding-top: 0.85rem; border-top: 1px solid var(--border); }
        .pagination a, .pagination span { display: inline-flex; align-items: center; justify-content: center; min-width: 2.25rem; height: 2.25rem; padding: 0 0.45rem; border-radius: 8px; font-size: 0.85rem; text-decoration: none; color: var(--text); border: 1px solid var(--border); background: #121a26; }
        .pagination a:hover { border-color: var(--accent); color: var(--accent); }
        .pagination span.ellipsis { border: none; background: transparent; min-width: auto; color: var(--muted); }
        .pagination span.current { background: rgba(61, 156, 245, 0.3); border-color: var(--accent); color: #fff; font-weight: 700; }
        .pagination a.nav { min-width: auto; padding: 0 0.85rem; }
        .pagination a.disabled { pointer-events: none; opacity: 0.4; }
        .back { display: inline-block; margin-bottom: 1rem; color: var(--accent); text-decoration: none; font-size: 0.9rem; }
        .back:hover { text-decoration: underline; }
        pre.payload { margin: 0.5rem 0 0; padding: 1rem; background: #0a0e14; border: 1px solid var(--border); border-radius: 8px; overflow: auto; max-height: 480px; font-size: 0.8rem; white-space: pre-wrap; word-break: break-word; direction: ltr; text-align: left; }
        dl.rowd { margin: 0; font-size: 0.9rem; }
        dl.rowd dt { color: var(--muted); margin-top: 0.65rem; }
        dl.rowd dt:first-child { margin-top: 0; }
        dl.rowd dd { margin: 0.2rem 0 0; word-break: break-word; }
    </style>
</head>
<body>
    <div class="wrap">
        <?php require __DIR__ . '/includes/site_nav.php'; ?>

        <?php if ($detailId > 0 && is_array($detailRow)): ?>
            <a class="back" href="server_inbox.php">← العودة للقائمة</a>
            <h1>طلب سيرفر #<?= (int) $detailRow['id'] ?></h1>
            <p class="sub">سجل طلب وارد عبر <code>api/send.php</code> (بعد التحقق من المفتاح وصحة JSON). كلمات مرور البوابة لا تُخزَّن في JSON المعروض.</p>
            <div class="card">
                <dl class="rowd">
                    <dt>التاريخ</dt>
                    <dd class="mono"><?= sm_inbox_h((string) $detailRow['created_at']) ?></dd>
                    <dt>عنوان IP</dt>
                    <dd class="mono"><?= sm_inbox_h((string) $detailRow['client_ip']) ?></dd>
                    <dt>User-Agent</dt>
                    <dd><?= sm_inbox_h(trim((string) ($detailRow['user_agent'] ?? '')) ?: '—') ?></dd>
                    <dt>الأرقام</dt>
                    <dd class="mono"><?= sm_inbox_h((string) $detailRow['phone_numbers']) ?></dd>
                    <dt>نص الرسالة</dt>
                    <dd><?= nl2br(sm_inbox_h((string) $detailRow['message'])) ?></dd>
                    <dt>ربط سجل الإرسال</dt>
                    <dd>
                        <?php $sid = $detailRow['sms_log_id'] ?? null; ?>
                        <?php if ($sid !== null && (int) $sid > 0): ?>
                            <a href="operations.php" style="color:var(--accent);">سجل العمليات</a> — معرف <span class="mono"><?= (int) $sid ?></span>
                        <?php else: ?>
                            —
                        <?php endif; ?>
                    </dd>
                    <dt>تسليم البوابة</dt>
                    <dd>
                        <?php if ((int) ($detailRow['delivered'] ?? 0) === 1): ?>
                            <span class="badge ok">delivered</span>
                        <?php else: ?>
                            <span class="badge fail">لم يُعتمد 2xx</span>
                        <?php endif; ?>
                        <?php if ($detailRow['gateway_http_code'] !== null): ?>
                            <span class="mono" style="margin-right:0.5rem;">HTTP <?= (int) $detailRow['gateway_http_code'] ?></span>
                        <?php endif; ?>
                    </dd>
                    <?php if (trim((string) ($detailRow['validation_error_code'] ?? '')) !== ''): ?>
                        <dt>خطأ</dt>
                        <dd>
                            <span class="badge warn"><?= sm_inbox_h((string) $detailRow['validation_error_code']) ?></span>
                            <?= sm_inbox_h((string) ($detailRow['validation_error_msg'] ?? '')) ?>
                        </dd>
                    <?php endif; ?>
                    <?php if (trim((string) ($detailRow['curl_error'] ?? '')) !== ''): ?>
                        <dt>cURL</dt>
                        <dd class="mono"><?= sm_inbox_h((string) $detailRow['curl_error']) ?></dd>
                    <?php endif; ?>
                    <dt>JSON الطلب (منزوع كلمات المرور)</dt>
                    <dd>
                        <?php
                        $pj = (string) ($detailRow['payload_sanitized'] ?? '');
                        $pretty = $pj;
                        try {
                            $d = json_decode($pj, true, 512, JSON_THROW_ON_ERROR);
                            $enc = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                            if ($enc !== false) {
                                $pretty = $enc;
                            }
                        } catch (JsonException $e) {
                        }
                        ?>
                        <pre class="payload"><?= sm_inbox_h($pretty) ?></pre>
                    </dd>
                </dl>
            </div>
        <?php else: ?>
            <h1>طلبات السيرفرات</h1>
            <p class="sub">كل طلب <strong>POST</strong> ناجح إلى <code>api/send.php</code> (مفتاح صحيح + JSON صالح) يُسجَّل هنا مع الرسالة والأرقام وعنوان الطالب، ثم يُحدَّد بعد التنفيذ إن وُجد سجل في <code>sms_logs</code>.</p>

            <?php if ($dbError !== null): ?>
                <div class="flash error">
                    تعذر التحميل: <?= sm_inbox_h($dbError) ?>
                    <p style="margin:0.5rem 0 0;font-size:0.88rem;">شغّل <a href="install_db.php">install_db.php</a> لإنشاء الجدول <code>api_server_inbox</code> ثم حدّث الصفحة.</p>
                </div>
            <?php else: ?>
                <div class="card">
                    <div class="meta-bar">
                        <span>إجمالي الطلبات: <strong style="color:var(--text);"><?= (int) $total ?></strong></span>
                        <span>
                            <?php if ($total > 0): ?>
                                عرض <?= (int) $from ?>–<?= (int) $to ?> من <?= (int) $total ?>
                            <?php else: ?>
                                لا توجد طلبات بعد
                            <?php endif; ?>
                        </span>
                    </div>

                    <?php if ($rows === []): ?>
                        <p style="color:var(--muted);margin:0;">لم يُستدعَ <code>api/send.php</code> بعد، أو الجدول جديد.</p>
                    <?php else: ?>
                        <div class="table-wrap">
                            <table class="ops">
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>التاريخ</th>
                                        <th>IP</th>
                                        <th>الأرقام</th>
                                        <th>الرسالة</th>
                                        <th>سجل SMS</th>
                                        <th>التسليم</th>
                                        <th>ملاحظة</th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rows as $row): ?>
                                        <?php
                                        $del = (int) ($row['delivered'] ?? 0) === 1;
                                        $valc = trim((string) ($row['validation_error_code'] ?? ''));
                                        $note = '—';
                                        if ($valc !== '') {
                                            $note = $valc;
                                        } elseif (!$del && trim((string) ($row['curl_error'] ?? '')) !== '') {
                                            $note = mb_substr((string) $row['curl_error'], 0, 80);
                                        }
                                        ?>
                                        <tr>
                                            <td class="mono"><?= (int) $row['id'] ?></td>
                                            <td class="mono"><?= sm_inbox_h((string) $row['created_at']) ?></td>
                                            <td class="mono"><?= sm_inbox_h((string) $row['client_ip']) ?></td>
                                            <td class="mono"><span class="clip" title="<?= sm_inbox_h((string) $row['phone_numbers']) ?>"><?= sm_inbox_h((string) $row['phone_numbers']) ?></span></td>
                                            <td><span class="clip" title="<?= sm_inbox_h((string) $row['message']) ?>"><?= sm_inbox_h((string) $row['message']) ?></span></td>
                                            <td class="mono"><?= $row['sms_log_id'] !== null ? (int) $row['sms_log_id'] : '—' ?></td>
                                            <td>
                                                <?php if ($del): ?>
                                                    <span class="badge ok">نعم</span>
                                                <?php else: ?>
                                                    <span class="badge fail">لا</span>
                                                <?php endif; ?>
                                                <?php if ($row['gateway_http_code'] !== null): ?>
                                                    <span class="mono" style="font-size:0.72rem;"><?= (int) $row['gateway_http_code'] ?></span>
                                                <?php endif; ?>
                                            </td>
                                            <td style="font-size:0.76rem;color:var(--muted);max-width:140px;"><?= sm_inbox_h($note) ?></td>
                                            <td><a href="server_inbox.php?id=<?= (int) $row['id'] ?>" style="color:var(--accent);font-size:0.82rem;">تفاصيل</a></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ($totalPages > 1): ?>
                            <nav class="pagination" aria-label="تصفح الصفحات">
                                <a class="nav<?= $page <= 1 ? ' disabled' : '' ?>" href="<?= $page <= 1 ? '#' : sm_inbox_h(sm_inbox_query_url($page - 1)) ?>" <?= $page <= 1 ? ' tabindex="-1" aria-disabled="true"' : '' ?>>السابق</a>
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
                                        echo '<a href="' . sm_inbox_h(sm_inbox_query_url($pi)) . '">' . (int) $pi . '</a>';
                                    }
                                    $lastShown = $pi;
                                }
                                ?>
                                <a class="nav<?= $page >= $totalPages ? ' disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : sm_inbox_h(sm_inbox_query_url($page + 1)) ?>" <?= $page >= $totalPages ? ' tabindex="-1" aria-disabled="true"' : '' ?>>التالي</a>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
