<?php
/**
 * 项目认购业绩确认单（财务「确认业绩」独立页）
 * 编辑：commission_status=0；查看：commission_status>=1 或带 view=1
 */
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$host = '127.0.0.1';
$db = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';
try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die('数据库连接失败');
}

try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_description MEDIUMTEXT NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_confirmed_at DATETIME NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_confirm_remarks MEDIUMTEXT NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_finance_rejected_at DATETIME NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_finance_reject_reason MEDIUMTEXT NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_pay_method VARCHAR(255) NULL DEFAULT NULL COMMENT "业绩确认-付款方式"');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_sales_discount VARCHAR(255) NULL DEFAULT NULL COMMENT "业绩确认-折扣(手填)"');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_commission_standard VARCHAR(512) NULL DEFAULT NULL COMMENT "业绩确认-结佣标准"');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN biz_confirm_attachments TEXT NULL COMMENT "案场业确附件URL逗号分隔"');
} catch (Throwable $e) { /* 已存在 */ }

function admin_perf_ensure_reject_history_table(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS filing_finance_performance_rejects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            filing_id INT UNSIGNED NOT NULL,
            reject_reason MEDIUMTEXT NOT NULL,
            rejected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_id INT UNSIGNED NULL,
            INDEX idx_filing_rejected (filing_id, rejected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }
    admin_perf_backfill_reject_history_once($pdo);
}

function admin_perf_backfill_reject_history_once(PDO $pdo): void
{
    static $bf = false;
    if ($bf) {
        return;
    }
    $bf = true;
    try {
        $rows = $pdo->query("SELECT id, performance_finance_reject_reason, performance_finance_rejected_at FROM filings WHERE performance_finance_rejected_at IS NOT NULL AND TRIM(IFNULL(performance_finance_reject_reason,'')) <> ''")->fetchAll(PDO::FETCH_ASSOC);
        $cntStmt = $pdo->prepare('SELECT COUNT(*) FROM filing_finance_performance_rejects WHERE filing_id = ?');
        $insStmt = $pdo->prepare('INSERT INTO filing_finance_performance_rejects (filing_id, reject_reason, rejected_at, admin_id) VALUES (?, ?, ?, NULL)');
        foreach ($rows as $r) {
            $fid = (int)($r['id'] ?? 0);
            if ($fid <= 0) {
                continue;
            }
            $cntStmt->execute([$fid]);
            if ((int)$cntStmt->fetchColumn() > 0) {
                continue;
            }
            $ts = strtotime(str_replace('T', ' ', (string)($r['performance_finance_rejected_at'] ?? '')));
            $at = $ts ? date('Y-m-d H:i:s', $ts) : date('Y-m-d H:i:s');
            $insStmt->execute([$fid, trim((string)($r['performance_finance_reject_reason'] ?? '')), $at]);
        }
    } catch (Throwable $e) {
    }
}

function admin_perf_fmt_datetime(?string $dt): string
{
    if ($dt === null || trim((string)$dt) === '') {
        return '';
    }
    $ts = strtotime(str_replace('T', ' ', (string)$dt));
    return $ts ? date('Y-m-d H:i', $ts) : '';
}

function admin_perf_ensure_packages_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_commission_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            package_name VARCHAR(191) NOT NULL DEFAULT '',
            commission_pct DECIMAL(10,4) NOT NULL DEFAULT 0,
            cash_reward DECIMAL(14,2) NOT NULL DEFAULT 0,
            jump_ratio DECIMAL(10,4) NOT NULL DEFAULT 0,
            jump_reward DECIMAL(14,2) NOT NULL DEFAULT 0,
            is_enabled TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }
}

function admin_perf_package_commission(array $deal): array
{
    $dp = isset($deal['deal_price']) && is_numeric($deal['deal_price']) ? (float) $deal['deal_price'] : 0.0;
    $taRaw = $deal['transaction_amount'] ?? null;
    $ta = ($taRaw !== null && $taRaw !== '' && is_numeric($taRaw)) ? (float) $taRaw : null;
    $baseAmt = ($ta !== null && $ta > 0) ? $ta : $dp;
    $pkgName = trim((string) ($deal['finance_pkg_name'] ?? ''));
    $pkgId = (int) ($deal['commission_package_id'] ?? 0);
    if ($pkgId <= 0 || $pkgName === '') {
        $legacy = isset($deal['commission_amount']) && is_numeric($deal['commission_amount']) ? (float) $deal['commission_amount'] : 0.0;
        return ['amount' => $legacy, 'detail' => ''];
    }
    $p = isset($deal['finance_pkg_pct']) && is_numeric($deal['finance_pkg_pct']) ? (float) $deal['finance_pkg_pct'] : 0.0;
    $c = isset($deal['finance_pkg_cash']) && is_numeric($deal['finance_pkg_cash']) ? (float) $deal['finance_pkg_cash'] : 0.0;
    $jp = isset($deal['finance_pkg_jump_pct']) && is_numeric($deal['finance_pkg_jump_pct']) ? (float) $deal['finance_pkg_jump_pct'] : 0.0;
    $jr = isset($deal['finance_pkg_jump_cash']) && is_numeric($deal['finance_pkg_jump_cash']) ? (float) $deal['finance_pkg_jump_cash'] : 0.0;
    $total = round($baseAmt * ($p / 100.0) + $c + $baseAmt * ($jp / 100.0) + $jr, 2);
    return ['amount' => $total, 'detail' => ''];
}

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$viewOnly = isset($_GET['view']) && (string)$_GET['view'] === '1';
$err = '';
$ok = '';

if ($id <= 0) {
    $err = '缺少报备 id';
}

if ($err === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($id > 0) {
        admin_perf_ensure_reject_history_table($pdo);
    }
    $formAction = trim((string)($_POST['form_action'] ?? ''));
    if ($formAction === 'reject') {
        $reason = trim((string)($_POST['reject_reason'] ?? ''));
        if ($reason === '') {
            $err = '请填写驳回原因';
        } else {
            if (mb_strlen($reason, 'UTF-8') > 2000) {
                $reason = mb_substr($reason, 0, 2000, 'UTF-8');
            }
            $chk = $pdo->prepare('SELECT id, commission_status FROM filings WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $cur = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                $err = '记录不存在';
            } elseif ((int)$cur['commission_status'] !== 0) {
                $err = '该单已不是待确认状态，无法驳回';
            } else {
                $log = "\n" . date('Y-m-d H:i') . ' [管理员] 财务驳回业绩确认：' . str_replace(["\r", "\n"], ' ', mb_substr($reason, 0, 200, 'UTF-8'));
                $adminId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 0;
                try {
                    $pdo->beginTransaction();
                    $ins = $pdo->prepare('INSERT INTO filing_finance_performance_rejects (filing_id, reject_reason, rejected_at, admin_id) VALUES (?, ?, NOW(), ?)');
                    $ins->execute([$id, $reason, $adminId > 0 ? $adminId : null]);
                    $sql = 'UPDATE filings SET performance_finance_rejected_at = NOW(), performance_finance_reject_reason = ?, status_log = CONCAT(IFNULL(status_log,\'\'), ?) WHERE id = ? AND commission_status = 0';
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([$reason, $log, $id]);
                    if ($stmt->rowCount() < 1) {
                        $pdo->rollBack();
                        $err = '驳回失败';
                    } else {
                        $pdo->commit();
                        header('Location: admin_finance.php?perf_reject_ok=1');
                        exit;
                    }
                } catch (Throwable $e) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                    $err = '驳回失败，请稍后重试或联系管理员';
                }
            }
        }
    } elseif ($formAction === 'confirm') {
        $perfDesc = trim((string)($_POST['performance_description'] ?? ''));
        $perfRemarks = trim((string)($_POST['performance_confirm_remarks'] ?? ''));
        $perfPayMethod = trim((string)($_POST['performance_pay_method'] ?? ''));
        $perfSalesDiscount = trim((string)($_POST['performance_sales_discount'] ?? ''));
        $perfCommStd = trim((string)($_POST['performance_commission_standard'] ?? ''));
        if (mb_strlen($perfPayMethod, 'UTF-8') > 255) {
            $perfPayMethod = mb_substr($perfPayMethod, 0, 255, 'UTF-8');
        }
        if (mb_strlen($perfSalesDiscount, 'UTF-8') > 255) {
            $perfSalesDiscount = mb_substr($perfSalesDiscount, 0, 255, 'UTF-8');
        }
        if (mb_strlen($perfCommStd, 'UTF-8') > 512) {
            $perfCommStd = mb_substr($perfCommStd, 0, 512, 'UTF-8');
        }
        $amountRaw = trim((string)($_POST['commission_amount'] ?? ''));
        if (!is_numeric($amountRaw)) {
            $err = '应收佣金金额格式不正确';
        } else {
            $amount = round((float) $amountRaw, 2);
            $chk = $pdo->prepare('SELECT id, commission_status FROM filings WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $cur = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                $err = '记录不存在';
            } elseif ((int)$cur['commission_status'] !== 0) {
                $err = '该单已确认业绩，无法重复提交';
            } else {
                $log = "\n" . date('Y-m-d H:i') . ' [管理员] 财务确认认购业绩确认单';
                $sql = 'UPDATE filings SET commission_status = 1, commission_amount = ?, performance_description = ?, performance_confirm_remarks = ?, performance_pay_method = ?, performance_sales_discount = ?, performance_commission_standard = ?, performance_confirmed_at = NOW(), performance_finance_rejected_at = NULL, performance_finance_reject_reason = NULL, status_log = CONCAT(IFNULL(status_log,\'\'), ?) WHERE id = ? AND commission_status = 0';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $amount,
                    $perfDesc === '' ? null : $perfDesc,
                    $perfRemarks === '' ? null : $perfRemarks,
                    $perfPayMethod === '' ? null : $perfPayMethod,
                    $perfSalesDiscount === '' ? null : $perfSalesDiscount,
                    $perfCommStd === '' ? null : $perfCommStd,
                    $log,
                    $id,
                ]);
                if ($stmt->rowCount() < 1) {
                    $err = '提交失败（可能已被他人确认）';
                } else {
                    header('Location: admin_finance_performance_confirm.php?id=' . $id . '&view=1&saved=1');
                    exit;
                }
            }
        }
    } else {
        $err = '无效操作';
    }
}

$row = null;
if ($id > 0) {
    admin_perf_ensure_reject_history_table($pdo);
    admin_perf_ensure_packages_schema($pdo);
    $sql = "SELECT f.*,
            p.name AS project_name,
            cpkg.package_name AS finance_pkg_name,
            cpkg.commission_pct AS finance_pkg_pct,
            cpkg.cash_reward AS finance_pkg_cash,
            cpkg.jump_ratio AS finance_pkg_jump_pct,
            cpkg.jump_reward AS finance_pkg_jump_cash
            FROM filings f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN project_commission_packages cpkg ON cpkg.id = f.commission_package_id
            WHERE f.id = ? LIMIT 1";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row && $err === '') {
        $err = '记录不存在';
    }
}

$rejectHistory = [];
if ($id > 0 && $row) {
    try {
        $rh = $pdo->prepare('SELECT rejected_at, reject_reason FROM filing_finance_performance_rejects WHERE filing_id = ? ORDER BY rejected_at DESC');
        $rh->execute([$id]);
        $rejectHistory = $rh->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rejectHistory = [];
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $ok = '业绩确认已保存';
}

$readonly = false;
if ($row) {
    $cs = (int)($row['commission_status'] ?? 0);
    if ($cs >= 1) {
        $readonly = true;
    }
    if ($viewOnly) {
        $readonly = true;
    }
}

$showFinanceRejectBanner = $rejectHistory !== [];

$openRejectFromList = false;
if ($row && !$readonly && isset($_GET['open_reject']) && (string) $_GET['open_reject'] === '1') {
    $openRejectFromList = true;
}

$calc = $row ? admin_perf_package_commission($row) : ['amount' => 0, 'detail' => ''];
$packCalcCommission = $row ? $calc['amount'] : 0.0;
$savedDesc = $row ? (string)($row['performance_description'] ?? '') : '';
$savedConfirmRemarks = $row ? (string)($row['performance_confirm_remarks'] ?? '') : '';
$savedPayMethod = $row ? (string)($row['performance_pay_method'] ?? '') : '';
$savedSalesDiscount = $row ? (string)($row['performance_sales_discount'] ?? '') : '';
$savedCommStd = $row ? (string)($row['performance_commission_standard'] ?? '') : '';
$savedCommDb = ($row && isset($row['commission_amount']) && is_numeric($row['commission_amount']))
    ? (float) $row['commission_amount']
    : null;
$readonlyShowCommission = $savedCommDb !== null ? $savedCommDb : $packCalcCommission;
$editInputCommissionDefault = $packCalcCommission;

$bizConfirmAttachUrls = [];
if ($row && isset($row['biz_confirm_attachments']) && trim((string) $row['biz_confirm_attachments']) !== '') {
    foreach (explode(',', (string) $row['biz_confirm_attachments']) as $u) {
        $u = trim(str_replace(["\r", "\n", "\t"], '', $u));
        if ($u !== '') {
            $bizConfirmAttachUrls[] = $u;
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $row && (int)($row['commission_status'] ?? 0) === 0) {
    $savedDesc = trim((string)($_POST['performance_description'] ?? ''));
    $savedConfirmRemarks = trim((string)($_POST['performance_confirm_remarks'] ?? ''));
    $savedPayMethod = trim((string)($_POST['performance_pay_method'] ?? ''));
    $savedSalesDiscount = trim((string)($_POST['performance_sales_discount'] ?? ''));
    $savedCommStd = trim((string)($_POST['performance_commission_standard'] ?? ''));
    $ar = trim((string)($_POST['commission_amount'] ?? ''));
    if (is_numeric($ar)) {
        $editInputCommissionDefault = round((float) $ar, 2);
    }
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>认购业绩确认单</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .perf-table-section-title {
            font-size: 0.9375rem;
            font-weight: 700;
            color: #0f172a;
            letter-spacing: 0.02em;
        }
        .perf-table-shell {
            border-radius: 0.75rem;
            border: 1px solid rgb(226 232 240 / 0.95);
            background: #fff;
            box-shadow: 0 1px 2px rgb(15 23 42 / 0.04), 0 4px 12px rgb(15 23 42 / 0.04);
            overflow: hidden;
        }
        .perf-biz-gallery-head {
            font-size: 0.75rem;
            font-weight: 600;
            color: #78350f;
            background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%);
            padding: 0.55rem 0.75rem;
            border-bottom: 1px solid #fcd34d;
        }
        .perf-biz-gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(7.25rem, 1fr));
            gap: 0.6rem;
            padding: 0.75rem;
        }
        .perf-biz-gallery-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 0.5rem;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
        }
        .perf-biz-gallery-item a { display: block; width: 100%; height: 100%; }
        .perf-biz-gallery-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .perf-form-hidden-fields {
            position: absolute;
            width: 1px;
            height: 1px;
            padding: 0;
            margin: -1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        @media print {
            @page {
                size: A4 landscape;
                margin: 6mm;
            }
            .no-print { display: none !important; }
            html, body {
                background: #fff !important;
                padding: 0 !important;
                margin: 0 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            .perf-print-sheet {
                max-width: 100% !important;
                width: 100% !important;
                margin: 0 !important;
                padding: 3mm 5mm !important;
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                font-size: 10pt;
                line-height: 1.25;
                zoom: 0.9;
            }
            .perf-print-sheet .perf-print-h1 {
                font-size: 15pt !important;
                margin: 0 0 4mm 0 !important;
                line-height: 1.2;
            }
            .perf-print-sheet .perf-print-section {
                margin-bottom: 3mm !important;
            }
            .perf-table-shell {
                box-shadow: none !important;
                border-radius: 0 !important;
                border: 1px solid #94a3b8 !important;
            }
            .perf-biz-gallery-grid {
                grid-template-columns: repeat(auto-fill, minmax(26mm, 1fr)) !important;
                gap: 2mm !important;
                padding: 2mm !important;
            }
            .perf-biz-gallery-item img {
                max-height: 36mm !important;
                object-fit: contain !important;
            }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen py-6 px-4">
    <div class="max-w-6xl mx-auto bg-white shadow-lg rounded-lg p-6 md:p-10 border border-slate-200 perf-print-sheet">
        <div class="no-print flex flex-wrap gap-3 justify-between items-center mb-6">
            <a href="admin_finance.php" class="text-sm text-blue-600 hover:underline">&larr; 返回佣金结算</a>
            <button type="button" onclick="window.print()" class="text-sm px-3 py-1.5 rounded border border-slate-300 hover:bg-slate-50">打印</button>
        </div>

        <?php if (!$row): ?>
            <p class="text-red-600 font-bold mb-4"><?= htmlspecialchars($err !== '' ? $err : '记录不存在', ENT_QUOTES, 'UTF-8') ?></p>
            <a href="admin_finance.php" class="text-blue-600 text-sm">返回</a>
        <?php else: ?>
            <?php if ($err !== ''): ?>
                <p class="text-red-600 font-bold mb-4"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if ($ok !== ''): ?>
                <p class="text-green-700 text-sm font-bold mb-4"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <h1 class="perf-print-h1 text-center text-xl md:text-2xl font-bold tracking-wide mb-8">
                【<?= htmlspecialchars((string)($row['project_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>】项目认购业绩确认单
            </h1>

            <div class="mb-6 perf-print-section">
                <div class="perf-table-section-title mb-2 border-l-4 border-blue-600 pl-2">一、认购明细表</div>
                <p class="text-xs text-slate-500 mb-3">以下为案场工作台「业确」上传的附件图片；点击图片可在新窗口查看原图。</p>

                <div class="perf-table-shell mb-4">
                    <div class="perf-biz-gallery-head">业确附件（案场上传）</div>
                    <?php if ($bizConfirmAttachUrls !== []): ?>
                        <div class="perf-biz-gallery-grid">
                            <?php foreach ($bizConfirmAttachUrls as $imgUrl): ?>
                                <div class="perf-biz-gallery-item">
                                    <a href="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" title="查看原图">
                                        <img src="<?= htmlspecialchars($imgUrl, ENT_QUOTES, 'UTF-8') ?>" alt="业确附件" loading="lazy" decoding="async">
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="px-4 py-10 text-center text-sm text-slate-400 border-t border-slate-100">暂无业确附件图片（案场勾选业确并上传后，将在此处以缩略图展示）</div>
                    <?php endif; ?>
                </div>

                <?php if (!$readonly): ?>
                <div class="no-print perf-form-hidden-fields" aria-hidden="true">
                    <input type="hidden" name="performance_description" form="perf-form" value="<?= htmlspecialchars($savedDesc, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="performance_pay_method" form="perf-form" value="<?= htmlspecialchars($savedPayMethod, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="performance_sales_discount" form="perf-form" value="<?= htmlspecialchars($savedSalesDiscount, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="performance_commission_standard" form="perf-form" value="<?= htmlspecialchars($savedCommStd, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="commission_amount" form="perf-form" value="<?= htmlspecialchars((string) $editInputCommissionDefault, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="performance_confirm_remarks" form="perf-form" value="<?= htmlspecialchars($savedConfirmRemarks, ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <?php endif; ?>
            </div>

            <?php if (!$readonly): ?>
                <div id="perf-reject-area" class="no-print flex flex-wrap items-center justify-end gap-3">
                    <button type="button" class="inline-flex items-center justify-center px-4 py-2 rounded border-2 border-green-600 text-red-600 bg-white hover:bg-green-50 text-sm font-bold shadow-sm" onclick="window.close(); setTimeout(function(){ window.location.href='admin_finance.php'; }, 200);">关闭</button>
                    <button type="button" id="btnRejectOpen" class="inline-flex items-center justify-center px-4 py-2 rounded border-2 border-green-600 text-red-600 bg-white hover:bg-green-50 text-sm font-bold shadow-sm">驳回</button>
                    <form id="perf-form" method="post" action="admin_finance_performance_confirm.php?id=<?= (int)$id ?>" class="inline-flex">
                        <input type="hidden" name="id" value="<?= (int)$id ?>">
                        <input type="hidden" name="form_action" value="confirm">
                        <button type="submit" class="inline-flex items-center justify-center px-5 py-2 rounded border-2 border-green-600 text-red-600 bg-white hover:bg-green-50 text-sm font-bold shadow-sm">财务确认</button>
                    </form>
                </div>

                <div id="rejectModal" class="no-print fixed inset-0 z-[100] hidden items-center justify-center p-4 bg-black/50" aria-hidden="true">
                    <div class="bg-white rounded-xl shadow-xl max-w-md w-full p-6 border border-slate-200" role="dialog" aria-labelledby="rejectModalTitle">
                        <h3 id="rejectModalTitle" class="text-lg font-bold text-slate-800 mb-2">驳回业绩确认</h3>
                        <p class="text-xs text-slate-500 mb-4">请填写驳回原因，提交后驻场工作台将显示提醒。</p>
                        <form method="post" action="admin_finance_performance_confirm.php?id=<?= (int)$id ?>">
                            <input type="hidden" name="id" value="<?= (int)$id ?>">
                            <input type="hidden" name="form_action" value="reject">
                            <textarea name="reject_reason" rows="5" required class="w-full border border-slate-300 rounded-lg p-3 text-sm focus:ring-2 focus:ring-amber-500 outline-none mb-4" placeholder="驳回原因（必填）"></textarea>
                            <div class="flex justify-end gap-3">
                                <button type="button" id="btnRejectCancel" class="px-4 py-2 border border-slate-300 rounded-lg text-sm text-slate-700 hover:bg-slate-50">取消</button>
                                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-bold bg-amber-600 text-white hover:bg-amber-700">提交驳回</button>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                (function () {
                    var m = document.getElementById('rejectModal');
                    var openBtn = document.getElementById('btnRejectOpen');
                    var cancelBtn = document.getElementById('btnRejectCancel');
                    if (!m || !openBtn) return;
                    function show() { m.classList.remove('hidden'); m.classList.add('flex'); m.setAttribute('aria-hidden', 'false'); }
                    function hide() { m.classList.add('hidden'); m.classList.remove('flex'); m.setAttribute('aria-hidden', 'true'); }
                    openBtn.addEventListener('click', show);
                    if (cancelBtn) cancelBtn.addEventListener('click', hide);
                    m.addEventListener('click', function (e) { if (e.target === m) hide(); });
                    <?php if (!empty($openRejectFromList)): ?>
                    var area = document.getElementById('perf-reject-area');
                    if (area) { area.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
                    setTimeout(function () { openBtn.click(); }, 350);
                    <?php endif; ?>
                })();
                </script>
            <?php else: ?>
                <p class="text-xs text-slate-500 no-print">
                    <?php if (!empty($row['performance_confirmed_at'])): ?>
                        确认时间：<?= htmlspecialchars((string)$row['performance_confirmed_at'], ENT_QUOTES, 'UTF-8') ?>
                    <?php endif; ?>
                </p>
            <?php endif; ?>

            <?php if ($showFinanceRejectBanner): ?>
                <div class="no-print mt-8 rounded-lg border border-slate-300 bg-slate-100 px-4 py-3 text-sm text-slate-800 perf-reject-history-box">
                    <div class="font-bold text-slate-900 mb-2">
                        <?php if ($row && (int)($row['commission_status'] ?? 0) >= 1): ?>
                            历史财务驳回记录
                        <?php else: ?>
                            财务驳回记录（按时间倒序；驻场工作台将同步提醒）
                        <?php endif; ?>
                    </div>
                    <div class="overflow-x-auto rounded border border-slate-200 bg-white">
                        <table class="w-full text-xs text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-200 text-slate-900">
                                    <th class="px-3 py-2 border-b border-slate-300 whitespace-nowrap w-36">驳回时间</th>
                                    <th class="px-3 py-2 border-b border-slate-300">驳回原因</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rejectHistory as $h): ?>
                                    <tr class="border-b border-slate-100 last:border-0">
                                        <td class="px-3 py-2 align-top tabular-nums text-slate-800 whitespace-nowrap"><?= htmlspecialchars(admin_perf_fmt_datetime($h['rejected_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-3 py-2 align-top text-slate-700 whitespace-pre-wrap"><?= htmlspecialchars((string)($h['reject_reason'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
