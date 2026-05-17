<?php
/**
 * 经纪公司服务费结算明细表（财务「结算佣金」独立页）
 * 编辑：commission_status=1；查看：commission_status=2 或带 view=1
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

const SETTLE_OUR_COMPANY = '惠州市易成好房科技有限公司';
const SETTLE_REFUND_ACCOUNT = '752900885910109';
const SETTLE_REFUND_BANK = '招商银行股份有限公司惠州分行营业部';

$settleCols = [
    'settlement_broker_display' => "VARCHAR(191) NULL",
    'settlement_invoice_code' => "VARCHAR(64) NULL",
    'settlement_invoice_number' => "VARCHAR(64) NULL",
    'settlement_payee_name' => "VARCHAR(255) NULL",
    'settlement_payee_bank' => "VARCHAR(500) NULL",
    'settlement_payee_account' => "VARCHAR(120) NULL",
    'settlement_settlement_amount' => "DECIMAL(14,2) NULL",
    'settlement_invoicing_amount' => "DECIMAL(14,2) NULL",
    'settlement_paid_at' => "DATETIME NULL",
    'settlement_attach_license' => "VARCHAR(500) NULL",
    'settlement_attach_bank' => "VARCHAR(500) NULL",
    'settlement_attach_agreement' => "VARCHAR(500) NULL",
    'settlement_attach_other' => "VARCHAR(500) NULL",
];
foreach ($settleCols as $col => $def) {
    try {
        $pdo->exec("ALTER TABLE filings ADD COLUMN {$col} {$def}");
    } catch (Throwable $e) { /* 已存在 */
    }
}

function settle_format_client_name(array $row): string
{
    $name = trim((string)($row['client_name'] ?? ''));
    $sub = trim((string)($row['subscriber_name'] ?? ''));
    if ($name === '' && $sub === '') {
        return '未知客户';
    }
    if ($sub !== '' && $sub !== $name) {
        return $sub;
    }
    return $name !== '' ? $name : $sub;
}

function settle_date_slash(?string $dt): string
{
    if ($dt === null || trim((string)$dt) === '') {
        return '';
    }
    $ts = strtotime(str_replace('T', ' ', (string)$dt));
    if (!$ts) {
        return '';
    }
    return date('Y/n/j', $ts);
}

function settle_house_total(array $r): float
{
    $ta = isset($r['transaction_amount']) && is_numeric($r['transaction_amount']) ? (float)$r['transaction_amount'] : 0.0;
    $dp = isset($r['deal_price']) && is_numeric($r['deal_price']) ? (float)$r['deal_price'] : 0.0;
    return $ta > 0 ? $ta : $dp;
}

function settle_default_settlement_amount(array $row): float
{
    if (isset($row['commission_amount']) && is_numeric($row['commission_amount'])) {
        return round((float)$row['commission_amount'], 2);
    }
    return 0.0;
}

$id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
$viewOnly = isset($_GET['view']) && (string)$_GET['view'] === '1';
$err = '';
$ok = '';

if ($id <= 0) {
    $err = '缺少报备 id';
}

if ($err === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? ''));
    $attLicense = trim((string)($_POST['settlement_attach_license'] ?? ''));
    $attBank = trim((string)($_POST['settlement_attach_bank'] ?? ''));
    $attAgreement = trim((string)($_POST['settlement_attach_agreement'] ?? ''));
    $attOther = trim((string)($_POST['settlement_attach_other'] ?? ''));

    if ($postAction === 'save_attachments') {
        $chk = $pdo->prepare('SELECT id, commission_status FROM filings WHERE id = ? LIMIT 1');
        $chk->execute([$id]);
        $cur = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$cur) {
            $err = '记录不存在';
        } elseif ((int)$cur['commission_status'] !== 1) {
            $err = '该单当前不可保存附件（须为「待发放」状态）';
        } else {
            $log = "\n" . date('Y-m-d H:i') . ' [管理员] 保存结算页附件（营业执照/账户/协议等）';
            $sql = 'UPDATE filings SET
                settlement_attach_license = ?,
                settlement_attach_bank = ?,
                settlement_attach_agreement = ?,
                settlement_attach_other = ?,
                status_log = CONCAT(IFNULL(status_log,\'\'), ?)
                WHERE id = ? AND commission_status = 1';
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $attLicense === '' ? null : $attLicense,
                $attBank === '' ? null : $attBank,
                $attAgreement === '' ? null : $attAgreement,
                $attOther === '' ? null : $attOther,
                $log,
                $id,
            ]);
            if ($stmt->rowCount() < 1) {
                $err = '保存失败（状态可能已变更）';
            } else {
                header('Location: admin_finance_commission_settle.php?id=' . $id . '&saved_attach=1');
                exit;
            }
        }
    } else {
        $amtSettle = trim((string)($_POST['settlement_settlement_amount'] ?? ''));
        $amtInv = trim((string)($_POST['settlement_invoicing_amount'] ?? ''));
        $broker = trim((string)($_POST['settlement_broker_display'] ?? ''));
        $invCode = trim((string)($_POST['settlement_invoice_code'] ?? ''));
        $invNum = trim((string)($_POST['settlement_invoice_number'] ?? ''));
        $payeeName = trim((string)($_POST['settlement_payee_name'] ?? ''));
        $payeeBank = trim((string)($_POST['settlement_payee_bank'] ?? ''));
        $payeeAcct = trim((string)($_POST['settlement_payee_account'] ?? ''));
        $proof = trim((string)($_POST['commission_proof'] ?? ''));

        if (!is_numeric($amtSettle) || !is_numeric($amtInv)) {
            $err = '结算佣金、开票金额须为有效数字';
        } elseif ((float)$amtInv < 0 || (float)$amtSettle < 0) {
            $err = '金额不能为负';
        } elseif ($payeeName === '' || $payeeBank === '' || $payeeAcct === '') {
            $err = '请完整填写收款人资料（户名、开户银行、银行账号）';
        } elseif ($proof === '') {
            $err = '请上传转账凭证';
        } else {
            $amtSettleF = round((float)$amtSettle, 2);
            $amtInvF = round((float)$amtInv, 2);
            $chk = $pdo->prepare('SELECT id, commission_status FROM filings WHERE id = ? LIMIT 1');
            $chk->execute([$id]);
            $cur = $chk->fetch(PDO::FETCH_ASSOC);
            if (!$cur) {
                $err = '记录不存在';
            } elseif ((int)$cur['commission_status'] !== 1) {
                $err = '该单当前不可结算（须为「待发放」状态）';
            } else {
                $log = "\n" . date('Y-m-d H:i') . ' [管理员] 提交经纪公司服务费结算明细表';
                $sql = 'UPDATE filings SET commission_status = 2,
                    commission_amount = ?,
                    commission_proof = ?,
                    settlement_broker_display = ?,
                    settlement_invoice_code = ?,
                    settlement_invoice_number = ?,
                    settlement_payee_name = ?,
                    settlement_payee_bank = ?,
                    settlement_payee_account = ?,
                    settlement_settlement_amount = ?,
                    settlement_invoicing_amount = ?,
                    settlement_attach_license = ?,
                    settlement_attach_bank = ?,
                    settlement_attach_agreement = ?,
                    settlement_attach_other = ?,
                    settlement_paid_at = NOW(),
                    status_log = CONCAT(IFNULL(status_log,\'\'), ?)
                    WHERE id = ? AND commission_status = 1';
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $amtInvF,
                    $proof,
                    $broker === '' ? null : $broker,
                    $invCode === '' ? null : $invCode,
                    $invNum === '' ? null : $invNum,
                    $payeeName,
                    $payeeBank,
                    $payeeAcct,
                    $amtSettleF,
                    $amtInvF,
                    $attLicense === '' ? null : $attLicense,
                    $attBank === '' ? null : $attBank,
                    $attAgreement === '' ? null : $attAgreement,
                    $attOther === '' ? null : $attOther,
                    $log,
                    $id,
                ]);
                if ($stmt->rowCount() < 1) {
                    $err = '提交失败（状态可能已变更）';
                } else {
                    header('Location: admin_finance_commission_settle.php?id=' . $id . '&view=1&saved=1');
                    exit;
                }
            }
        }
    }
}

$row = null;
if ($id > 0) {
    $sql = 'SELECT f.*, p.name AS project_name FROM filings f LEFT JOIN projects p ON f.project_id = p.id WHERE f.id = ? LIMIT 1';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row && $err === '') {
        $err = '记录不存在';
    }
}

if (isset($_GET['saved']) && $_GET['saved'] === '1') {
    $ok = '结算信息已保存';
}
if (isset($_GET['saved_attach']) && $_GET['saved_attach'] === '1') {
    $ok = '附件已保存';
}

$readonly = false;
if ($row) {
    $cs = (int)($row['commission_status'] ?? 0);
    if ($cs === 2) {
        $readonly = true;
    }
    if ($viewOnly) {
        $readonly = true;
    }
}

$clientName = $row ? settle_format_client_name($row) : '';
$subDate = $row ? settle_date_slash($row['subscription_date'] ?? '') : '';
$roomNo = $row ? trim((string)($row['room_number'] ?? '')) : '';
$area = ($row && isset($row['transaction_area']) && is_numeric($row['transaction_area'])) ? (float)$row['transaction_area'] : null;
$houseTotal = $row ? settle_house_total($row) : 0.0;
$companyFull = $row ? trim((string)($row['company_name'] ?? '')) : '';

$defSettle = $row ? settle_default_settlement_amount($row) : 0.0;
$defInv = $defSettle;

$sBroker = $row ? trim((string)($row['settlement_broker_display'] ?? '')) : '';
$sInvCode = $row ? trim((string)($row['settlement_invoice_code'] ?? '')) : '';
$sInvNum = $row ? trim((string)($row['settlement_invoice_number'] ?? '')) : '';
$sPayeeName = $row ? trim((string)($row['settlement_payee_name'] ?? '')) : '';
$sPayeeBank = $row ? trim((string)($row['settlement_payee_bank'] ?? '')) : '';
$sPayeeAcct = $row ? trim((string)($row['settlement_payee_account'] ?? '')) : '';
$sSettleAmt = ($row && isset($row['settlement_settlement_amount']) && is_numeric($row['settlement_settlement_amount']))
    ? (float)$row['settlement_settlement_amount'] : null;
$sInvAmt = ($row && isset($row['settlement_invoicing_amount']) && is_numeric($row['settlement_invoicing_amount']))
    ? (float)$row['settlement_invoicing_amount'] : null;

if ($readonly && $sSettleAmt !== null) {
    $defSettle = $sSettleAmt;
}
if ($readonly && $sInvAmt !== null) {
    $defInv = $sInvAmt;
}

if (!$readonly && $sBroker === '' && $row) {
    $sBroker = trim((string)($row['broker_name'] ?? ''));
}

$proofUrl = $row ? trim((string)($row['commission_proof'] ?? '')) : '';
$attLicense = $row ? trim((string)($row['settlement_attach_license'] ?? '')) : '';
$attBank = $row ? trim((string)($row['settlement_attach_bank'] ?? '')) : '';
$attAgreement = $row ? trim((string)($row['settlement_attach_agreement'] ?? '')) : '';
$attOther = $row ? trim((string)($row['settlement_attach_other'] ?? '')) : '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') {
    $attLicense = trim((string)($_POST['settlement_attach_license'] ?? $attLicense));
    $attBank = trim((string)($_POST['settlement_attach_bank'] ?? $attBank));
    $attAgreement = trim((string)($_POST['settlement_attach_agreement'] ?? $attAgreement));
    $attOther = trim((string)($_POST['settlement_attach_other'] ?? $attOther));
    $proofUrl = trim((string)($_POST['commission_proof'] ?? $proofUrl));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $row && (int)($row['commission_status'] ?? 0) === 1) {
    $sBroker = trim((string)($_POST['settlement_broker_display'] ?? ''));
    $sInvCode = trim((string)($_POST['settlement_invoice_code'] ?? ''));
    $sInvNum = trim((string)($_POST['settlement_invoice_number'] ?? ''));
    $sPayeeName = trim((string)($_POST['settlement_payee_name'] ?? ''));
    $sPayeeBank = trim((string)($_POST['settlement_payee_bank'] ?? ''));
    $sPayeeAcct = trim((string)($_POST['settlement_payee_account'] ?? ''));
    $a1 = trim((string)($_POST['settlement_settlement_amount'] ?? ''));
    $a2 = trim((string)($_POST['settlement_invoicing_amount'] ?? ''));
    if (is_numeric($a1)) {
        $defSettle = round((float)$a1, 2);
    }
    if (is_numeric($a2)) {
        $defInv = round((float)$a2, 2);
    }
}

$totalSettle = $defSettle;
$totalInv = $defInv;

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>经纪公司服务费结算明细表</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        .settle-table { border-collapse: collapse; width: 100%; font-size: 12px; }
        .settle-table th, .settle-table td { border: 1px solid #333; padding: 10px 12px; vertical-align: middle; }
        .settle-table th { background: #f3f4f6; font-weight: 700; text-align: center; white-space: nowrap; }
        .settle-table td.num { text-align: right; font-variant-numeric: tabular-nums; }
        .settle-table input[type="text"], .settle-table input[type="number"] { width: 100%; min-width: 0; font-size: 12px; padding: 4px 6px; border: 1px solid #cbd5e1; border-radius: 4px; }
        /* 结算附件：画廊式方格（参考业确附件样式） */
        .settle-att-panel {
            background: linear-gradient(180deg, #fffbeb 0%, #fef3c7 100%);
            border: 2px solid rgba(251, 191, 36, 0.55);
            border-radius: 0.75rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.6);
        }
        .settle-att-field-hd {
            display: flex;
            align-items: center;
            gap: 0.35rem;
            font-size: 0.875rem;
            font-weight: 600;
            color: #78350f;
            margin-bottom: 0.5rem;
        }
        .settle-att-cols-4 {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.5rem;
        }
        @media (max-width: 639px) {
            .settle-att-cols-4 { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        .settle-att-field-hd .settle-att-ico { font-size: 1rem; line-height: 1; }
        .settle-att-gallery { display: flex; flex-wrap: wrap; gap: 0.65rem; align-items: flex-start; }
        .settle-att-tile {
            position: relative;
            width: 100%;
            max-width: 7.5rem;
            aspect-ratio: 1;
            height: auto;
            border-radius: 0.5rem;
            border: 2px solid rgba(251, 146, 60, 0.65);
            background: #fff;
            overflow: hidden;
            flex-shrink: 0;
            margin-left: auto;
            margin-right: auto;
        }
        .settle-att-tile-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            border-style: dashed;
            border-color: rgba(234, 88, 12, 0.55);
            background: rgba(255, 255, 255, 0.75);
            color: rgba(234, 88, 12, 0.65);
            font-size: 2rem;
            font-weight: 300;
            line-height: 1;
            cursor: pointer;
            padding: 0;
            font-family: inherit;
        }
        .settle-att-tile-empty:hover {
            background: #fff;
            color: #ea580c;
            border-color: #ea580c;
        }
        .settle-att-tile-fill { cursor: pointer; position: relative; }
        .settle-att-tile-fill img {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }
        .settle-att-tile-doc {
            position: absolute;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: rgba(255, 251, 235, 0.97);
            padding: 0.35rem;
            font-size: 0.65rem;
            line-height: 1.35;
            text-align: center;
            color: #78350f;
        }
        .settle-att-del {
            position: absolute;
            top: 3px;
            right: 3px;
            width: 1.35rem;
            height: 1.35rem;
            padding: 0;
            border: 2px solid #fff;
            border-radius: 0.25rem;
            background: #dc2626;
            color: #fff;
            font-size: 0.85rem;
            font-weight: 700;
            line-height: 1;
            cursor: pointer;
            z-index: 5;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.2);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .settle-att-del:hover { background: #b91c1c; }
        /* 收款人三列：限制宽度，避免大屏横向过于空洞 */
        .settle-payee-grid {
            max-width: 42rem;
            row-gap: 1.1rem;
            column-gap: 1.5rem;
        }
        @media print {
            .no-print { display: none !important; }
            @page {
                size: A4 landscape;
                margin: 8mm 10mm;
            }
            html, body {
                background: #fff !important;
                color: #000;
                font-size: 10px;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            body.min-h-screen {
                min-height: 0 !important;
                padding-top: 0 !important;
                padding-bottom: 0 !important;
            }
            .settle-print-sheet {
                box-shadow: none !important;
                border: none !important;
                border-radius: 0 !important;
                margin: 0 !important;
                max-width: 100% !important;
                padding: 4mm 2mm 5mm !important;
                box-sizing: border-box !important;
            }
            .settle-print-sheet .mb-3,
            .settle-print-sheet .mb-4 { margin-bottom: 0.85rem !important; }
            .settle-print-sheet .mb-5,
            .settle-print-sheet .mb-6 { margin-bottom: 1.1rem !important; }
            .settle-print-sheet .mb-8 { margin-bottom: 1.25rem !important; }
            .settle-print-sheet .mb-10 { margin-bottom: 1.4rem !important; }
            .settle-print-sheet .mt-1 { margin-top: 0.2rem !important; }
            .settle-print-sheet .my-6,
            .settle-print-sheet .my-8,
            .settle-print-sheet .my-10 {
                margin-top: 1rem !important;
                margin-bottom: 1.05rem !important;
            }
            .settle-print-sheet .pt-5 { padding-top: 0.55rem !important; }
            .settle-print-sheet .pt-8 { padding-top: 1rem !important; }
            .settle-print-sheet .pt-10 { padding-top: 1.2rem !important; }
            .settle-print-sheet .p-4,
            .settle-print-sheet .p-5,
            .settle-print-sheet .p-6,
            .settle-print-sheet .md\:p-6,
            .settle-print-sheet .p-7,
            .settle-print-sheet .md\:p-7,
            .settle-print-sheet .md\:p-8 { padding: 0.75rem 0.95rem !important; }
            .settle-print-sheet .gap-4 { gap: 0.5rem !important; }
            .settle-print-sheet .space-y-4 > * + * { margin-top: 0.45rem !important; }
            .settle-print-sheet .space-y-2 > * + * { margin-top: 0.35rem !important; }
            .settle-print-sheet .space-y-1 > * + * { margin-top: 0.2rem !important; }
            .settle-print-sheet .leading-relaxed { line-height: 1.45 !important; }
            .settle-table {
                font-size: 9.5px !important;
                width: 100% !important;
            }
            .settle-table th, .settle-table td {
                padding: 5px 7px !important;
                border-color: #222 !important;
            }
            .settle-table th {
                white-space: normal !important;
                line-height: 1.25;
                font-size: 8.5px !important;
            }
            .settle-table input[type="text"],
            .settle-table input[type="number"] {
                font-size: 9.5px !important;
                padding: 2px 4px !important;
            }
            .settle-print-h1 {
                font-size: 15px !important;
                line-height: 1.35 !important;
            }
            .settle-print-sheet td.text-base { font-size: 11px !important; }
            .settle-print-sheet .text-sm { font-size: 9.5px !important; }
            .settle-print-sheet .settle-att-panel {
                padding: 0.45rem 0.55rem !important;
                margin-bottom: 0.75rem !important;
                page-break-inside: avoid;
            }
            .settle-print-sheet .settle-att-tile { max-width: 4.5rem !important; }
            .settle-print-sheet .settle-att-field-hd { font-size: 8.5px !important; margin-bottom: 0.2rem !important; }
            .settle-print-sheet .settle-att-cols-4 { gap: 0.4rem !important; }
            .settle-print-footer {
                font-size: 9.5px !important;
                line-height: 1.5 !important;
                page-break-inside: avoid;
                margin-top: 0 !important;
                padding-top: 0.85rem !important;
            }
            .settle-print-footer .bg-slate-50 {
                padding: 0.55rem 0.75rem !important;
            }
            .settle-print-table-wrap {
                margin-bottom: 0.8rem !important;
                page-break-inside: avoid;
            }
            .settle-print-payee { margin-bottom: 0.8rem !important; page-break-inside: avoid; }
            .settle-print-sheet .settle-print-proof {
                margin-bottom: 0.7rem !important;
                font-size: 9.5px !important;
            }
            .overflow-x-auto { overflow: visible !important; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-900 min-h-screen py-6 px-3">
    <div class="max-w-[1100px] mx-auto bg-white shadow-lg rounded-lg p-5 md:p-8 border border-slate-200 settle-print-sheet">
        <div class="no-print flex flex-wrap gap-3 justify-between items-center mb-5">
            <a href="admin_finance.php" class="text-sm text-blue-600 hover:underline">&larr; 返回佣金结算</a>
            <button type="button" onclick="window.print()" class="text-sm px-3 py-1.5 rounded border border-slate-300 hover:bg-slate-50">打印</button>
        </div>

        <?php if (!$row): ?>
            <p class="text-red-600 font-bold mb-4"><?= htmlspecialchars($err !== '' ? $err : '记录不存在', ENT_QUOTES, 'UTF-8') ?></p>
            <a href="admin_finance.php" class="text-blue-600 text-sm">返回</a>
        <?php elseif ((int)($row['commission_status'] ?? 0) === 0): ?>
            <p class="text-red-600 font-bold mb-4">请先完成「确认业绩」，再办理结算佣金。</p>
            <a href="admin_finance.php" class="text-blue-600 text-sm">返回</a>
        <?php else: ?>
            <?php if ($err !== ''): ?>
                <p class="text-red-600 font-bold mb-3"><?= htmlspecialchars($err, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <?php if ($ok !== ''): ?>
                <p class="no-print text-green-700 text-sm font-bold mb-3"><?= htmlspecialchars($ok, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>

            <?php if ($readonly): ?>
                <?php
                $attList = [
                    '营业执照' => $attLicense,
                    '开户行账户' => $attBank,
                    '战略协议' => $attAgreement,
                    '其他附件' => $attOther,
                ];
                $anyAtt = false;
                foreach ($attList as $u) {
                    if ($u !== '') {
                        $anyAtt = true;
                        break;
                    }
                }
                ?>
                <?php if ($anyAtt): ?>
                    <div class="mb-8 settle-att-panel p-5 md:p-6">
                        <div class="settle-att-field-hd text-base mb-4">
                            <span class="settle-att-ico" aria-hidden="true">🖼</span>
                            <span>附件</span>
                        </div>
                        <div class="settle-att-cols-4">
                            <?php foreach ($attList as $lbl => $u): ?>
                                <?php if ($u === '') {
                                    continue;
                                } ?>
                                <?php
                                $isImg = (bool) preg_match('/\.(jpe?g|png|gif|webp)(\?|#|$)/i', $u);
                                $uEsc = htmlspecialchars($u, ENT_QUOTES, 'UTF-8');
                                $lblEsc = htmlspecialchars($lbl, ENT_QUOTES, 'UTF-8');
                                ?>
                                <div class="min-w-0 flex flex-col items-stretch">
                                    <div class="settle-att-field-hd text-xs sm:text-sm leading-tight">
                                        <span class="settle-att-ico" aria-hidden="true">🖼</span>
                                        <span class="truncate"><?= $lblEsc ?></span>
                                    </div>
                                    <div class="settle-att-gallery">
                                        <?php if ($isImg): ?>
                                            <a href="<?= $uEsc ?>" target="_blank" rel="noopener noreferrer" class="settle-att-tile settle-att-tile-fill block" title="点击查看">
                                                <img src="<?= $uEsc ?>" alt="<?= $lblEsc ?>">
                                            </a>
                                        <?php else: ?>
                                            <div class="settle-att-tile flex items-center justify-center bg-white">
                                                <a class="text-blue-600 underline text-xs px-1 text-center leading-snug" href="<?= $uEsc ?>" target="_blank" rel="noopener noreferrer">打开文件</a>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="no-print mb-8 settle-att-panel p-5 md:p-6">
                    <div class="settle-att-field-hd text-base mb-5">
                        <span class="settle-att-ico" aria-hidden="true">🖼</span>
                        <span>上传附件 <span class="text-xs text-amber-800/90 font-normal">（方格内上传，右上角 × 删除）</span></span>
                    </div>
                    <div class="settle-att-cols-4">
                        <div class="min-w-0 flex flex-col items-stretch">
                            <div class="settle-att-field-hd text-xs sm:text-sm leading-tight">
                                <span class="settle-att-ico" aria-hidden="true">🖼</span>
                                <span class="min-w-0"><span class="hidden sm:inline">营业执照 </span><span class="sm:hidden">执照 </span><span class="font-normal text-[10px] sm:text-xs text-amber-800/85">(图/PDF)</span></span>
                            </div>
                            <div class="settle-att-gallery">
                                <div id="settleAttLicenseTile" class="settle-att-tile settle-att-tile-fill hidden" title="点击查看原图">
                                    <img id="settleAttLicenseImg" src="" alt="营业执照" class="hidden">
                                    <div id="settleAttLicenseDoc" class="settle-att-tile-doc hidden"></div>
                                    <button type="button" class="settle-att-del" data-action="clear" data-prefix="settleAttLicense" title="删除">×</button>
                                </div>
                                <button type="button" id="settleAttLicenseAdd" class="settle-att-tile settle-att-tile-empty" data-action="pick" data-prefix="settleAttLicense" title="上传">+</button>
                                <input type="file" id="settleAttLicenseFile" class="hidden" accept="image/*,.pdf,.doc,.docx">
                            </div>
                            <input type="hidden" name="settlement_attach_license" id="settleAttLicenseUrl" form="settle-form" value="<?= htmlspecialchars($attLicense, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="min-w-0 flex flex-col items-stretch">
                            <div class="settle-att-field-hd text-xs sm:text-sm leading-tight">
                                <span class="settle-att-ico" aria-hidden="true">🖼</span>
                                <span class="min-w-0"><span class="hidden sm:inline">开户行账户 </span><span class="sm:hidden">账户 </span><span class="font-normal text-[10px] sm:text-xs text-amber-800/85">(图/PDF)</span></span>
                            </div>
                            <div class="settle-att-gallery">
                                <div id="settleAttBankTile" class="settle-att-tile settle-att-tile-fill hidden" title="点击查看原图">
                                    <img id="settleAttBankImg" src="" alt="开户行账户" class="hidden">
                                    <div id="settleAttBankDoc" class="settle-att-tile-doc hidden"></div>
                                    <button type="button" class="settle-att-del" data-action="clear" data-prefix="settleAttBank" title="删除">×</button>
                                </div>
                                <button type="button" id="settleAttBankAdd" class="settle-att-tile settle-att-tile-empty" data-action="pick" data-prefix="settleAttBank" title="上传">+</button>
                                <input type="file" id="settleAttBankFile" class="hidden" accept="image/*,.pdf,.doc,.docx">
                            </div>
                            <input type="hidden" name="settlement_attach_bank" id="settleAttBankUrl" form="settle-form" value="<?= htmlspecialchars($attBank, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="min-w-0 flex flex-col items-stretch">
                            <div class="settle-att-field-hd text-xs sm:text-sm leading-tight">
                                <span class="settle-att-ico" aria-hidden="true">🖼</span>
                                <span class="min-w-0"><span class="hidden sm:inline">战略协议 </span><span class="sm:hidden">协议 </span><span class="font-normal text-[10px] sm:text-xs text-amber-800/85">(图/PDF)</span></span>
                            </div>
                            <div class="settle-att-gallery">
                                <div id="settleAttAgreementTile" class="settle-att-tile settle-att-tile-fill hidden" title="点击查看原图">
                                    <img id="settleAttAgreementImg" src="" alt="战略协议" class="hidden">
                                    <div id="settleAttAgreementDoc" class="settle-att-tile-doc hidden"></div>
                                    <button type="button" class="settle-att-del" data-action="clear" data-prefix="settleAttAgreement" title="删除">×</button>
                                </div>
                                <button type="button" id="settleAttAgreementAdd" class="settle-att-tile settle-att-tile-empty" data-action="pick" data-prefix="settleAttAgreement" title="上传">+</button>
                                <input type="file" id="settleAttAgreementFile" class="hidden" accept="image/*,.pdf,.doc,.docx">
                            </div>
                            <input type="hidden" name="settlement_attach_agreement" id="settleAttAgreementUrl" form="settle-form" value="<?= htmlspecialchars($attAgreement, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div class="min-w-0 flex flex-col items-stretch">
                            <div class="settle-att-field-hd text-xs sm:text-sm leading-tight">
                                <span class="settle-att-ico" aria-hidden="true">🖼</span>
                                <span class="min-w-0"><span class="hidden sm:inline">其他附件 </span><span class="sm:hidden">其他 </span><span class="font-normal text-[10px] sm:text-xs text-amber-800/85">(图/PDF)</span></span>
                            </div>
                            <div class="settle-att-gallery">
                                <div id="settleAttOtherTile" class="settle-att-tile settle-att-tile-fill hidden" title="点击查看原图">
                                    <img id="settleAttOtherImg" src="" alt="其他附件" class="hidden">
                                    <div id="settleAttOtherDoc" class="settle-att-tile-doc hidden"></div>
                                    <button type="button" class="settle-att-del" data-action="clear" data-prefix="settleAttOther" title="删除">×</button>
                                </div>
                                <button type="button" id="settleAttOtherAdd" class="settle-att-tile settle-att-tile-empty" data-action="pick" data-prefix="settleAttOther" title="上传">+</button>
                                <input type="file" id="settleAttOtherFile" class="hidden" accept="image/*,.pdf,.doc,.docx">
                            </div>
                            <input type="hidden" name="settlement_attach_other" id="settleAttOtherUrl" form="settle-form" value="<?= htmlspecialchars($attOther, ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div class="flex flex-wrap justify-between items-center gap-3 mt-5 pt-3 border-t border-amber-200/60">
                        <button type="submit" form="settle-form" name="action" value="save_attachments" class="min-w-[7rem] px-6 py-2 rounded-lg bg-slate-500 text-white text-sm font-medium hover:bg-slate-600 shadow-sm">保存</button>
                        <button type="button" id="btnSettleGenerate" class="min-w-[7rem] px-6 py-2 rounded-lg bg-slate-500 text-white text-sm font-medium hover:bg-slate-600 shadow-sm">结算生成</button>
                    </div>
                </div>
            <?php endif; ?>

            <h1 class="settle-print-h1 text-center text-lg md:text-xl font-bold tracking-wide my-8 md:my-10">经纪公司服务费结算明细表</h1>

            <div class="settle-print-table-wrap overflow-x-auto mb-8 md:mb-10">
                <table class="settle-table">
                    <thead>
                        <tr>
                            <th class="w-10">序号</th>
                            <th>项目</th>
                            <th>客户姓名</th>
                            <th>认购时间</th>
                            <th>房号</th>
                            <th>面积</th>
                            <th>房屋总价</th>
                            <th>经纪公司全称</th>
                            <th>经纪人</th>
                            <th>结算佣金</th>
                            <th>开票金额</th>
                            <th>发票代码</th>
                            <th>发票号码</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="text-center">1</td>
                            <td><?= htmlspecialchars((string)($row['project_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= htmlspecialchars($roomNo !== '' ? $roomNo : '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="text-center"><?= $area !== null && $area > 0 ? htmlspecialchars((string)$area, ENT_QUOTES, 'UTF-8') : '' ?></td>
                            <td class="num"><?= $houseTotal > 0 ? number_format($houseTotal, 2, '.', '') : '' ?></td>
                            <td><?= htmlspecialchars($companyFull !== '' ? $companyFull : '—', ENT_QUOTES, 'UTF-8') ?></td>
                            <td>
                                <?php if ($readonly): ?>
                                    <?= htmlspecialchars($sBroker !== '' ? $sBroker : '—', ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <input type="text" name="settlement_broker_display" form="settle-form" value="<?= htmlspecialchars($sBroker, ENT_QUOTES, 'UTF-8') ?>" placeholder="选填" autocomplete="off">
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ($readonly): ?>
                                    <?= number_format($totalSettle, 2, '.', '') ?>
                                <?php else: ?>
                                    <input type="number" step="0.01" name="settlement_settlement_amount" form="settle-form" value="<?= htmlspecialchars((string)$defSettle, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </td>
                            <td class="num">
                                <?php if ($readonly): ?>
                                    <?= number_format($totalInv, 2, '.', '') ?>
                                <?php else: ?>
                                    <input type="number" step="0.01" name="settlement_invoicing_amount" form="settle-form" value="<?= htmlspecialchars((string)$defInv, ENT_QUOTES, 'UTF-8') ?>">
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($readonly): ?>
                                    <?= htmlspecialchars($sInvCode !== '' ? $sInvCode : '—', ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <input type="text" name="settlement_invoice_code" form="settle-form" value="<?= htmlspecialchars($sInvCode, ENT_QUOTES, 'UTF-8') ?>" placeholder="选填" autocomplete="off">
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($readonly): ?>
                                    <?= htmlspecialchars($sInvNum !== '' ? $sInvNum : '—', ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <input type="text" name="settlement_invoice_number" form="settle-form" value="<?= htmlspecialchars($sInvNum, ENT_QUOTES, 'UTF-8') ?>" placeholder="选填" autocomplete="off">
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <td colspan="9" class="text-center font-bold">合计</td>
                            <td class="num font-bold"><?= number_format($totalSettle, 2, '.', '') ?></td>
                            <td class="num font-bold"><?= number_format($totalInv, 2, '.', '') ?></td>
                            <td></td>
                            <td></td>
                        </tr>
                        <tr>
                            <td colspan="10" class="text-right font-bold">本次实际转账（开票金额）</td>
                            <td class="num font-bold text-base" colspan="3"><?= number_format($totalInv, 2, '.', '') ?></td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="settle-print-payee mb-8 md:mb-10 border border-slate-200 rounded-lg p-6 md:p-7 bg-slate-50/90">
                <div class="font-bold text-sm mb-5 text-slate-800">收款人资料</div>
                <div class="settle-payee-grid grid grid-cols-1 text-sm sm:grid-cols-3">
                    <label class="block">
                        <span class="text-slate-600">户名（经纪公司名字）</span>
                        <?php if ($readonly): ?>
                            <div class="mt-2 font-medium"><?= htmlspecialchars($sPayeeName !== '' ? $sPayeeName : '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php else: ?>
                            <input type="text" name="settlement_payee_name" form="settle-form" class="mt-2 w-full border border-slate-300 rounded px-3 py-2" value="<?= htmlspecialchars($sPayeeName, ENT_QUOTES, 'UTF-8') ?>" placeholder="必填" autocomplete="organization">
                        <?php endif; ?>
                    </label>
                    <label class="block">
                        <span class="text-slate-600">开户银行</span>
                        <?php if ($readonly): ?>
                            <div class="mt-2 font-medium"><?= htmlspecialchars($sPayeeBank !== '' ? $sPayeeBank : '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php else: ?>
                            <input type="text" name="settlement_payee_bank" form="settle-form" class="mt-2 w-full border border-slate-300 rounded px-3 py-2" value="<?= htmlspecialchars($sPayeeBank, ENT_QUOTES, 'UTF-8') ?>" placeholder="必填" autocomplete="off">
                        <?php endif; ?>
                    </label>
                    <label class="block">
                        <span class="text-slate-600">银行账号</span>
                        <?php if ($readonly): ?>
                            <div class="mt-2 font-medium font-mono"><?= htmlspecialchars($sPayeeAcct !== '' ? $sPayeeAcct : '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php else: ?>
                            <input type="text" name="settlement_payee_account" form="settle-form" class="mt-2 w-full border border-slate-300 rounded px-3 py-2 font-mono" value="<?= htmlspecialchars($sPayeeAcct, ENT_QUOTES, 'UTF-8') ?>" placeholder="必填" autocomplete="off">
                        <?php endif; ?>
                    </label>
                </div>
            </div>

            <?php if (!$readonly): ?>
                <div class="no-print mb-6 space-y-2">
                    <label class="block text-sm font-medium text-slate-700">上传转账凭证 <span class="text-red-600">*</span></label>
                    <input type="file" id="settleProofFile" accept="image/*,.pdf" class="text-sm">
                    <input type="hidden" name="commission_proof" id="settleProofUrl" form="settle-form" value="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>">
                    <p id="settleProofHint" class="text-xs text-slate-500">上传后自动填入；可多次更换。</p>
                    <?php if ($proofUrl !== ''): ?>
                        <p class="text-xs text-green-700">已保存凭证：<a class="text-blue-600 underline" href="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">打开</a></p>
                    <?php endif; ?>
                </div>
            <?php elseif ($proofUrl !== ''): ?>
                <p class="settle-print-proof text-sm mb-6 md:mb-8">转账凭证：<a class="text-blue-600 underline" href="<?= htmlspecialchars($proofUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer">查看</a></p>
            <?php endif; ?>

            <div class="settle-print-footer text-sm text-slate-800 leading-relaxed border-t border-slate-200 pt-8 md:pt-10">
                <p class="mb-5 md:mb-6">本公司承诺：以上房号若挞定或退房，则我司收到退款通知之日起，三个工作日内把此表格相应金额的款项全额退回以下公司账户。</p>
                <div class="bg-slate-50 border border-slate-200 rounded-lg px-5 py-5 md:px-6 md:py-6 space-y-3 text-sm mb-5 md:mb-6">
                    <div><span class="font-bold">户名：</span><?= htmlspecialchars(SETTLE_OUR_COMPANY, ENT_QUOTES, 'UTF-8') ?></div>
                    <div><span class="font-bold">账号：</span><?= htmlspecialchars(SETTLE_REFUND_ACCOUNT, ENT_QUOTES, 'UTF-8') ?></div>
                    <div><span class="font-bold">开户行：</span><?= htmlspecialchars(SETTLE_REFUND_BANK, ENT_QUOTES, 'UTF-8') ?></div>
                </div>
                <p class="font-bold text-red-700 mb-0">结佣表需经纪公司盖公章私章！谢谢！</p>
            </div>

            <?php if (!$readonly): ?>
                <form id="settle-form" method="post" action="admin_finance_commission_settle.php?id=<?= (int)$id ?>" class="no-print mt-8 flex flex-wrap items-center justify-end gap-3">
                    <input type="hidden" name="id" value="<?= (int)$id ?>">
                    <button type="submit" id="btnConfirmSettle" class="px-6 py-2.5 bg-purple-600 text-white rounded-lg font-bold hover:bg-purple-700 shadow">确认提交结算</button>
                </form>
                <script>
                (function () {
                    function absUrl(u) {
                        var s = String(u || '').trim();
                        if (!s) return '';
                        if (/^https?:\/\//i.test(s)) return s;
                        if (s.charAt(0) === '/') return window.location.origin + s;
                        return window.location.origin + '/' + s;
                    }
                    function isPreviewImageUrl(u) {
                        return /\.(jpe?g|png|gif|webp)(\?|#|$)/i.test(String(u || ''));
                    }
                    function fillDocPanel(doc, full) {
                        doc.textContent = '';
                        doc.appendChild(document.createTextNode('非图片附件 · '));
                        var a = document.createElement('a');
                        a.href = full;
                        a.target = '_blank';
                        a.rel = 'noopener noreferrer';
                        a.className = 'text-blue-600 underline text-xs font-medium';
                        a.textContent = '打开';
                        doc.appendChild(a);
                    }
                    function syncAttSlot(prefix) {
                        var h = document.getElementById(prefix + 'Url');
                        if (!h) return;
                        var u = (h.value || '').trim();
                        var full = absUrl(u);
                        var tile = document.getElementById(prefix + 'Tile');
                        var addBtn = document.getElementById(prefix + 'Add');
                        var img = document.getElementById(prefix + 'Img');
                        var doc = document.getElementById(prefix + 'Doc');
                        if (!tile || !addBtn || !img || !doc) return;
                        if (!u) {
                            tile.classList.add('hidden');
                            addBtn.classList.remove('hidden');
                            addBtn.textContent = '+';
                            img.classList.add('hidden');
                            img.removeAttribute('src');
                            doc.classList.add('hidden');
                            doc.textContent = '';
                            return;
                        }
                        addBtn.classList.add('hidden');
                        tile.classList.remove('hidden');
                        img.onload = null;
                        img.onerror = null;
                        if (isPreviewImageUrl(u)) {
                            doc.classList.add('hidden');
                            doc.textContent = '';
                            img.classList.remove('hidden');
                            img.onerror = function () {
                                img.onerror = null;
                                img.classList.add('hidden');
                                img.removeAttribute('src');
                                doc.classList.remove('hidden');
                                fillDocPanel(doc, full);
                            };
                            img.src = full;
                        } else {
                            img.classList.add('hidden');
                            img.removeAttribute('src');
                            doc.classList.remove('hidden');
                            fillDocPanel(doc, full);
                        }
                    }
                    ['settleAttLicense', 'settleAttBank', 'settleAttAgreement', 'settleAttOther'].forEach(function (prefix) {
                        syncAttSlot(prefix);
                        var tile = document.getElementById(prefix + 'Tile');
                        if (!tile) return;
                        tile.addEventListener('click', function (e) {
                            if (e.target.closest('.settle-att-del')) return;
                            if (e.target.closest('a')) return;
                            var h = document.getElementById(prefix + 'Url');
                            var u = h && (h.value || '').trim();
                            if (!u) return;
                            window.open(absUrl(u), '_blank', 'noopener,noreferrer');
                        });
                    });

                    document.querySelectorAll('[data-action="pick"]').forEach(function (btn) {
                        btn.addEventListener('click', function () {
                            var prefix = btn.getAttribute('data-prefix');
                            var fi = prefix ? document.getElementById(prefix + 'File') : null;
                            if (fi) fi.click();
                        });
                    });
                    document.querySelectorAll('[data-action="clear"]').forEach(function (btn) {
                        btn.addEventListener('click', function (e) {
                            e.preventDefault();
                            e.stopPropagation();
                            var prefix = btn.getAttribute('data-prefix');
                            var h = prefix ? document.getElementById(prefix + 'Url') : null;
                            if (!h) return;
                            if (!window.confirm('确定删除该附件？')) return;
                            h.value = '';
                            syncAttSlot(prefix);
                        });
                    });

                    function bindUpload(fileInputId, prefix) {
                        var fileInput = document.getElementById(fileInputId);
                        var hidden = document.getElementById(prefix + 'Url');
                        var addBtn = document.getElementById(prefix + 'Add');
                        if (!fileInput || !hidden) return;
                        fileInput.addEventListener('change', function () {
                            var file = fileInput.files && fileInput.files[0];
                            if (!file) return;
                            var fd = new FormData();
                            fd.append('file', file);
                            if (addBtn) addBtn.textContent = '…';
                            fetch('../upload.php', { method: 'POST', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (data) {
                                    if (data.status === 'success' && data.url) {
                                        hidden.value = data.url;
                                        syncAttSlot(prefix);
                                    } else {
                                        alert('上传失败：' + (data.msg || '未知错误'));
                                        syncAttSlot(prefix);
                                    }
                                })
                                .catch(function () {
                                    alert('上传异常，请重试。');
                                    syncAttSlot(prefix);
                                })
                                .finally(function () { fileInput.value = ''; });
                        });
                    }
                    bindUpload('settleAttLicenseFile', 'settleAttLicense');
                    bindUpload('settleAttBankFile', 'settleAttBank');
                    bindUpload('settleAttAgreementFile', 'settleAttAgreement');
                    bindUpload('settleAttOtherFile', 'settleAttOther');

                    var fileInput = document.getElementById('settleProofFile');
                    var hidden = document.getElementById('settleProofUrl');
                    var hint = document.getElementById('settleProofHint');
                    if (fileInput && hidden) {
                        fileInput.addEventListener('change', function () {
                            var file = fileInput.files && fileInput.files[0];
                            if (!file) return;
                            var fd = new FormData();
                            fd.append('file', file);
                            if (hint) hint.textContent = '上传中…';
                            fetch('../upload.php', { method: 'POST', body: fd })
                                .then(function (r) { return r.json(); })
                                .then(function (data) {
                                    if (data.status === 'success' && data.url) {
                                        hidden.value = data.url;
                                        if (hint) hint.textContent = '已上传，提交表单即可保存。';
                                    } else {
                                        if (hint) hint.textContent = '上传失败：' + (data.msg || '未知错误');
                                    }
                                })
                                .catch(function () {
                                    if (hint) hint.textContent = '上传异常，请重试。';
                                });
                        });
                    }

                    var gen = document.getElementById('btnSettleGenerate');
                    var confirmBtn = document.getElementById('btnConfirmSettle');
                    if (gen && confirmBtn) {
                        gen.addEventListener('click', function () {
                            confirmBtn.click();
                        });
                    }
                })();
                </script>
            <?php else: ?>
                <?php if (!empty($row['settlement_paid_at'])): ?>
                    <p class="text-xs text-slate-500 no-print mt-6">结算提交时间：<?= htmlspecialchars((string)$row['settlement_paid_at'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
