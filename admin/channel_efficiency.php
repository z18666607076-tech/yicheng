<?php
// channel_efficiency.php - 渠道效能（按报备「渠道」follower 人员维度；空为公池）
session_start();
header('Content-Type: text/html; charset=utf-8');

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
} catch (PDOException $e) {
    die('DB Error');
}

require_once __DIR__ . '/includes/channel_targets_lib.php';

/** 可汇总/目标的整数指标（与 channel_targets_lib 一致） */
$METRIC_INT_KEYS = channel_efficiency_metric_int_keys();
/** 效能维度 5 列（与表头「效能维度」一致；其余整数列用于店/人/客户与派生率） */
$EFFICIENCY_DIMENSION_KEYS = [
    'store_count',
    'store_avg_report',
    'report_to_visit_rate',
    'avg_visit_batches_per_broker',
    'deal_conversion_rate',
];
/** 表格列顺序：可填整数列间插/后置计算列（店均、两率、人均带看组数） */
$METRIC_KEYS = [
    'store_count',
    'broker_count',
    'store_avg_report',
    'visit_broker_count',
    'report_to_visit_rate',
    'visit_count',
    'avg_visit_batches_per_broker',
    'deal_conversion_rate',
    'deal_count',
];

function channel_efficiency_zero_int_metrics(array $keys): array {
    $o = [];
    foreach ($keys as $k) {
        $o[$k] = 0;
    }
    return $o;
}

/** 在 $actual 上写入店均报备、报备转带看率、人均带看组数、成交转化率（字符串列） */
function channel_efficiency_apply_rates(array &$actual): void {
    $bc = (int)($actual['broker_count'] ?? 0);
    $vbc = (int)($actual['visit_broker_count'] ?? 0);
    $vc = (int)($actual['visit_count'] ?? 0);
    $dc = (int)($actual['deal_count'] ?? 0);
    $sc = (int)($actual['store_count'] ?? 0);
    /** 店均报备：报备经纪人数÷启动门店 */
    $actual['store_avg_report'] = $sc > 0 ? round($bc / $sc, 2) : '—';
    /** 报备转带看率：带看经纪人数÷报备经纪人数 */
    $actual['report_to_visit_rate'] = $bc > 0 ? round($vbc / $bc * 100, 1) . '%' : '—';
    /** 人均带看组数：带看组数（visit_count）÷带看经纪人数 */
    $actual['avg_visit_batches_per_broker'] = $vbc > 0 ? round($vc / $vbc, 2) : '—';
    /** 成交转化率：成交套数÷带看组数（visit_count） */
    $actual['deal_conversion_rate'] = $vc > 0 ? round($dc / $vc * 100, 1) . '%' : '—';
}

function channel_efficiency_follower_display(string $fk): string {
    if ($fk === '__POOL__') {
        return '公池';
    }
    return $fk;
}

/** filings.follower 归一化：空、公池、公共池 → 同一公池键，避免重复「公池」行 */
function channel_efficiency_sql_follower_bucket(string $alias = 'f'): string {
    $a = $alias;
    return "CASE 
        WHEN TRIM(IFNULL({$a}.follower,'')) = '' THEN '__POOL__'
        WHEN TRIM({$a}.follower) IN ('公池','公共池') THEN '__POOL__'
        ELSE TRIM({$a}.follower)
    END";
}

/**
 * 报备公司名规范化后的去重键（SQL 表达式）：去掉首尾空白，合并 Tab / UTF-8 不换行空格 / 全角空格等，
 * 减少同一商户因录入不可见字符被算成多家。
 */
function channel_efficiency_sql_company_norm_key(string $alias = 'f'): string {
    $c = "{$alias}.company_name";
    return "NULLIF(TRIM(BOTH ' ' FROM REPLACE(REPLACE(REPLACE(TRIM(IFNULL({$c},'')), CHAR(9), ' '), UNHEX('C2A0'), ' '), UNHEX('E38080'), ' ')), '')";
}

/**
 * 案场「客户成交」口径：sub_stages 含认购、签约、业确任一项即计（与 staff.php 录入下定一致；任勾一项即算，避免漏勾其它项）。
 */
function channel_efficiency_sql_norm_sub_stages_col(string $alias = 'f'): string {
    $col = "{$alias}.sub_stages";
    return "REPLACE(REPLACE(IFNULL({$col}, ''), ' ', ''), ',,', ',')";
}

function channel_efficiency_sql_filing_has_client_deal_milestone(string $alias = 'f'): string {
    $norm = channel_efficiency_sql_norm_sub_stages_col($alias);
    return "(FIND_IN_SET('subscription', {$norm}) > 0 OR FIND_IN_SET('contract', {$norm}) > 0 OR FIND_IN_SET('biz_confirm', {$norm}) > 0)";
}

function channel_efficiency_as_of_label(string $end_date): string {
    if ($end_date !== '' && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $end_date, $m)) {
        return (int)$m[2] . '月' . (int)$m[3] . '日';
    }
    $t = time();
    return (int)date('n', $t) . '月' . (int)date('j', $t) . '日';
}

$action = $_GET['action'] ?? 'view';

if ($action === 'get_channel_efficiency') {
    header('Content-Type: application/json; charset=utf-8');

    $start_date = trim((string)($_GET['start_date'] ?? ''));
    $end_date = trim((string)($_GET['end_date'] ?? ''));
    if ($start_date === '' || $end_date === '') {
        // 与前端「本月」一致；未传日期时不再统计全量历史（否则与按自然月导出无法对账）
        $start_date = date('Y-m-01');
        $end_date = date('Y-m-d');
    }

    $where = '1=1';
    $params = [];
    $where .= ' AND DATE(f.created_at) BETWEEN ? AND ?';
    $params[] = $start_date;
    $params[] = $end_date;

    $bucket = channel_efficiency_sql_follower_bucket('f');
    $companyKey = channel_efficiency_sql_company_norm_key('f');

    $sql = "SELECT 
        {$bucket} AS fk,
        COUNT(DISTINCT {$companyKey}) AS store_count,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_visit_distinct,
        COUNT(DISTINCT CASE WHEN f.status = 4 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_deal_distinct,
        COUNT(DISTINCT CASE
            WHEN TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> ''
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status >= 2 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS visit_broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status = 4 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS deal_broker_count,
        SUM(CASE WHEN f.status >= 2 THEN 1 ELSE 0 END) AS visit_count,
        SUM(CASE WHEN f.status = 4 THEN 1 ELSE 0 END) AS deal_count,
        COUNT(DISTINCT CASE WHEN TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_report_distinct,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_visit_distinct,
        COUNT(DISTINCT CASE WHEN " . channel_efficiency_sql_filing_has_client_deal_milestone('f') . " AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_deal_distinct
    FROM filings f
    WHERE {$where}
    GROUP BY fk";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $statsMap = [];
    foreach ($rows as $row) {
        $statsMap[(string)$row['fk']] = $row;
    }

    $roster = channel_efficiency_load_roster($pdo);

    $groups = [];

    foreach ($roster as $person) {
        $fk = (string)$person['follower_key'];
        $row = $statsMap[$fk] ?? null;
        $actual = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
        if ($row !== null) {
            foreach ($METRIC_INT_KEYS as $k) {
                $actual[$k] = (int)($row[$k] ?? 0);
            }
        }
        channel_efficiency_apply_rates($actual);
        $actual['store_visit_distinct'] = $row !== null ? (int)($row['store_visit_distinct'] ?? 0) : 0;
        $actual['store_deal_distinct'] = $row !== null ? (int)($row['store_deal_distinct'] ?? 0) : 0;
        $actual['client_report_distinct'] = $row !== null ? (int)($row['client_report_distinct'] ?? 0) : 0;
        $actual['client_visit_distinct'] = $row !== null ? (int)($row['client_visit_distinct'] ?? 0) : 0;
        $actual['client_deal_distinct'] = $row !== null ? (int)($row['client_deal_distinct'] ?? 0) : 0;
        $actual['deal_broker_count'] = $row !== null ? (int)($row['deal_broker_count'] ?? 0) : 0;
        $groups[] = [
            'name' => $person['name'],
            'follower_key' => $fk,
            'agent_id' => $person['agent_id'],
            'target' => channel_efficiency_zero_int_metrics($METRIC_INT_KEYS),
            'actual' => $actual,
        ];
    }

    $label_end = $end_date !== '' ? $end_date : date('Y-m-d');
    $targetYear = (int)date('Y', strtotime($label_end));
    $targetMonth = (int)date('n', strtotime($label_end));
    if ($end_date !== '' && preg_match('/^(\d{4})-(\d{2})-\d{2}$/', $end_date, $tm)) {
        $targetYear = (int)$tm[1];
        $targetMonth = (int)$tm[2];
    }
    $targetMap = channel_monthly_targets_fetch_map($pdo, $targetYear, $targetMonth);
    foreach ($groups as &$g) {
        $fk = (string)$g['follower_key'];
        $tInts = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
        foreach ($METRIC_INT_KEYS as $k) {
            $v = (int)($targetMap[$fk][$k] ?? 0);
            $tInts[$k] = $v;
            $g['target'][$k] = $v;
        }
        channel_efficiency_apply_rates($tInts);
        $g['target']['store_avg_report'] = $tInts['store_avg_report'];
        $g['target']['report_to_visit_rate'] = $tInts['report_to_visit_rate'];
        $g['target']['avg_visit_batches_per_broker'] = $tInts['avg_visit_batches_per_broker'];
        $g['target']['deal_conversion_rate'] = $tInts['deal_conversion_rate'];
    }
    unset($g);

    $sum_target = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
    foreach ($groups as $g) {
        foreach ($METRIC_INT_KEYS as $k) {
            $sum_target[$k] += (int)($g['target'][$k] ?? 0);
        }
    }

    $sqlAll = "SELECT 
        COUNT(DISTINCT {$companyKey}) AS store_count,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_visit_distinct,
        COUNT(DISTINCT CASE WHEN f.status = 4 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_deal_distinct,
        COUNT(DISTINCT CASE
            WHEN TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> ''
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status >= 2 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS visit_broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status = 4 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS deal_broker_count,
        SUM(CASE WHEN f.status >= 2 THEN 1 ELSE 0 END) AS visit_count,
        SUM(CASE WHEN f.status = 4 THEN 1 ELSE 0 END) AS deal_count,
        COUNT(DISTINCT CASE WHEN TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_report_distinct,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_visit_distinct,
        COUNT(DISTINCT CASE WHEN " . channel_efficiency_sql_filing_has_client_deal_milestone('f') . " AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_deal_distinct
    FROM filings f
    WHERE {$where}";
    $stmtAll = $pdo->prepare($sqlAll);
    $stmtAll->execute($params);
    $allRow = $stmtAll->fetch(PDO::FETCH_ASSOC) ?: [];

    $total_actual = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
    foreach ($METRIC_INT_KEYS as $k) {
        $total_actual[$k] = (int)($allRow[$k] ?? 0);
    }
    channel_efficiency_apply_rates($total_actual);
    $total_actual['store_visit_distinct'] = (int)($allRow['store_visit_distinct'] ?? 0);
    $total_actual['store_deal_distinct'] = (int)($allRow['store_deal_distinct'] ?? 0);
    $total_actual['client_report_distinct'] = (int)($allRow['client_report_distinct'] ?? 0);
    $total_actual['client_visit_distinct'] = (int)($allRow['client_visit_distinct'] ?? 0);
    $total_actual['client_deal_distinct'] = (int)($allRow['client_deal_distinct'] ?? 0);
    $total_actual['deal_broker_count'] = (int)($allRow['deal_broker_count'] ?? 0);

    $sum_target_display = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
    foreach ($METRIC_INT_KEYS as $k) {
        $sum_target_display[$k] = (int)($sum_target[$k] ?? 0);
    }
    channel_efficiency_apply_rates($sum_target_display);

    $groups[] = [
        'name' => '合计',
        'follower_key' => '__TOTAL__',
        'target' => $sum_target_display,
        'actual' => $total_actual,
    ];

    $progress = [];
    foreach ($EFFICIENCY_DIMENSION_KEYS as $k) {
        if ($k === 'store_avg_report' || $k === 'report_to_visit_rate' || $k === 'avg_visit_batches_per_broker' || $k === 'deal_conversion_rate') {
            $tmp = channel_efficiency_zero_int_metrics($METRIC_INT_KEYS);
            foreach ($METRIC_INT_KEYS as $ik) {
                $tmp[$ik] = (int)($total_actual[$ik] ?? 0);
            }
            $tmp['store_visit_distinct'] = (int)($total_actual['store_visit_distinct'] ?? 0);
            $tmp['store_deal_distinct'] = (int)($total_actual['store_deal_distinct'] ?? 0);
            $tmp['client_report_distinct'] = (int)($total_actual['client_report_distinct'] ?? 0);
            $tmp['client_visit_distinct'] = (int)($total_actual['client_visit_distinct'] ?? 0);
            $tmp['client_deal_distinct'] = (int)($total_actual['client_deal_distinct'] ?? 0);
            channel_efficiency_apply_rates($tmp);
            $progress[$k] = (string)($tmp[$k] ?? '—');
            continue;
        }
        $t = (int)($sum_target_display[$k] ?? 0);
        $a = (int)($total_actual[$k] ?? 0);
        if ($t > 0) {
            $progress[$k] = round($a / $t * 100, 1) . '%';
        } else {
            $progress[$k] = '—';
        }
    }

    $as_of_label = channel_efficiency_as_of_label($label_end);

    echo json_encode([
        'code' => 0,
        'as_of_label' => $as_of_label,
        'target_period' => [
            'year' => $targetYear,
            'month' => $targetMonth,
            'label' => $targetYear . '年' . $targetMonth . '月',
        ],
        'groups' => $groups,
        'progress' => $progress,
        'efficiency_dimension_keys' => $EFFICIENCY_DIMENSION_KEYS,
        'metric_keys' => $METRIC_KEYS,
        'metric_labels' => channel_efficiency_metric_table_labels(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>渠道能效 - 数据分析</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        .tab-btn { transition: all 0.3s; }
        .tab-btn.active { background: #2563eb; color: white; }
        .table-container { overflow-x: auto; }
        table { min-width: 1400px; border-collapse: collapse; }
        .blue-header th { background-color: #3b82f6; color: white; font-weight: bold; font-size: 12px; text-align: center; padding: 8px 6px; border: 1px solid #2563eb; }
        .blue-header th.sub-th { font-size: 11px; font-weight: 600; padding: 6px 4px; }
        td { font-size: 13px; text-align: center; padding: 8px 6px; border: 1px solid #e2e8f0; vertical-align: middle; }
        .row-target { background-color: #dcfce7; }
        .row-actual { background-color: #fff; }
        .row-total-target { background-color: #d9f99d; font-weight: 600; }
        .row-total-actual { background-color: #f1f5f9; font-weight: 600; }
        .row-progress { color: #dc2626; font-weight: 700; font-size: 13px; background: #fff7ed; }
        .cell-person { font-weight: 600; text-align: center; background: #f8fafc; min-width: 88px; }
        .cell-row-label { text-align: center; font-size: 12px; color: #475569; white-space: nowrap; min-width: 108px; background: #f8fafc; }
        .section-cap { background-color: #3b82f6; color: white; font-weight: bold; font-size: 14px; }
        .person-nav { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; padding: 10px 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-top: 0; font-size: 12px; }
        .person-nav-label { color: #64748b; margin-right: 4px; white-space: nowrap; }
        .person-nav-btn { padding: 4px 10px; border-radius: 6px; border: 1px solid #e2e8f0; background: #fff; color: #334155; font-weight: 600; cursor: pointer; transition: background .15s, border-color .15s; }
        .person-nav-btn:hover { background: #eff6ff; border-color: #93c5fd; color: #1d4ed8; }
        .person-nav-btn-total { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">

    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50 relative">

        <transition name="fade">
            <div v-if="loading" class="absolute inset-0 z-50 bg-white/60 backdrop-blur-sm flex items-center justify-center">
                <div class="bg-white px-6 py-4 rounded-xl shadow-xl flex items-center gap-3 border border-slate-100">
                    <i class="fas fa-circle-notch fa-spin text-indigo-600 text-xl"></i>
                    <span class="text-sm font-bold text-slate-700">数据加载中...</span>
                </div>
            </div>
        </transition>

        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">数据分析</h2>
            </div>
                <div class="flex flex-wrap items-center gap-2 md:gap-3">
                    <a v-if="targetPeriod && targetPeriod.year && targetPeriod.month" :href="targetsPageHref" class="inline-flex items-center gap-1.5 px-3 py-2 rounded-lg text-xs font-bold bg-amber-500 text-white hover:bg-amber-600 shadow-sm shrink-0" title="按当前筛选结束日期对应年、月录入目标">
                        <i class="fas fa-bullseye"></i>录入目标
                    </a>
                    <div class="flex items-center gap-1 bg-gray-100 p-1 rounded-lg">
                    <button type="button" @click="setTimeRange('today')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='today'?'bg-white text-blue-700 shadow':'text-gray-500'">本日</button>
                    <button type="button" @click="setTimeRange('week')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='week'?'bg-white text-blue-700 shadow':'text-gray-500'">本周</button>
                    <button type="button" @click="setTimeRange('month')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='month'?'bg-white text-blue-700 shadow':'text-gray-500'">本月</button>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" v-model="customStartDate" class="px-2 py-1.5 border rounded-md text-xs">
                    <input type="date" v-model="customEndDate" class="px-2 py-1.5 border rounded-md text-xs">
                    <button type="button" @click="applyCustomDate" class="px-3 py-1.5 bg-blue-600 text-white rounded-md text-xs font-bold">确定</button>
                </div>
                <button type="button" @click="loadData" class="text-gray-500 hover:text-indigo-600 px-2 py-2 text-sm transition whitespace-nowrap" title="手动刷新">
                    <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
                <div class="border-b border-gray-200 flex flex-wrap items-stretch">
                    <div class="flex">
                        <span class="tab-btn px-6 py-4 text-sm font-medium active cursor-default inline-flex items-center" role="status" aria-current="page">
                            <i class="fas fa-chart-bar mr-2"></i>渠道能效
                        </span>
                    </div>
                    <a :href="targetsPageHref" class="tab-btn px-5 py-4 text-sm font-medium text-slate-600 hover:bg-slate-50 inline-flex items-center ml-auto border-l border-slate-200">
                        <i class="fas fa-bullseye mr-2 text-amber-600"></i>每月目标
                    </a>
                </div>

                <div class="p-6">
                    <div v-if="targetsNeedSetup && targetPeriod && targetPeriod.label" class="mb-4 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 flex flex-wrap items-center justify-between gap-3">
                        <div class="text-sm text-amber-950">
                            <i class="fas fa-exclamation-circle text-amber-600 mr-1"></i>
                            <strong>{{ targetPeriod.label }}</strong> 的渠道目标尚未录入（合计为 0）。录入后本页「目标」行与底部「指标进度」才会显示比例。
                        </div>
                        <a :href="targetsPageHref" class="shrink-0 inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-amber-600 text-white text-sm font-bold hover:bg-amber-700 shadow">
                            <i class="fas fa-edit"></i>去添加目标
                        </a>
                    </div>
                    <div class="section-cap py-3 px-4 rounded-t-lg mb-0 flex flex-wrap items-center justify-between gap-2">
                        <span>渠道数据（按渠道人员）</span>
                        <span v-if="targetPeriod && targetPeriod.label" class="text-sm font-normal opacity-95">「目标」数据月：<strong>{{ targetPeriod.label }}</strong>（与筛选<strong>结束日期</strong>同年月）</span>
                    </div>
                    <div v-if="personGroups.length" class="person-nav">
                        <span class="person-nav-label">人员定位</span>
                        <button v-for="(g, gi) in personGroups" :key="'nav-' + gi + '-' + g.follower_key" type="button" class="person-nav-btn" @click="scrollToPerson(gi)">{{ g.name }}</button>
                        <button v-if="totalGroup" type="button" class="person-nav-btn person-nav-btn-total" @click="scrollToTotal">合计</button>
                        <a href="admin_agents.php" class="ml-auto text-xs text-blue-600 hover:underline shrink-0">内部架构（维护渠道人员）</a>
                    </div>
                    <div class="table-container border border-t-0 border-gray-200 rounded-b-lg">
                        <table class="min-w-full">
                            <thead class="blue-header">
                                <tr>
                                    <th rowspan="2" class="align-middle">渠道人员</th>
                                    <th rowspan="2" class="align-middle">分项</th>
                                    <th colspan="5">效能维度</th>
                                    <th colspan="3">店</th>
                                    <th colspan="3">人</th>
                                    <th colspan="3">客户</th>
                                </tr>
                                <tr>
                                    <th v-for="k in efficiencyKeys" :key="'eff-h-' + k" class="sub-th" :title="metricHints[k] || ''">{{ metricLabels[k] || k }}</th>
                                    <th v-for="(lab, ti) in tripleSubHeaders" :key="'st-' + ti" class="sub-th">{{ lab }}</th>
                                    <th v-for="(lab, ti) in tripleSubHeaders" :key="'pe-' + ti" class="sub-th">{{ lab }}</th>
                                    <th v-for="(lab, ti) in tripleSubHeaders" :key="'cl-' + ti" class="sub-th">{{ lab }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                <template v-for="(g, gi) in personGroups" :key="'p-' + gi + '-' + g.follower_key">
                                    <tr :id="'ce-anchor-' + gi" :class="rowClass(g, 'target')">
                                        <td class="cell-person text-sm text-slate-800" rowspan="2">
                                            <span class="inline-flex items-center justify-center gap-1.5">
                                                <i class="fas fa-chevron-right text-blue-500 text-[10px]"></i>{{ g.name }}
                                            </span>
                                        </td>
                                        <td class="cell-row-label">目标</td>
                                        <td v-for="k in efficiencyKeys" :key="'t-' + k" class="text-sm text-slate-800">{{ fmtTarget(g.target[k]) }}</td>
                                        <td class="text-sm text-slate-800">{{ fmtTarget(g.target.store_count) }}</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-800">{{ fmtTarget(g.target.broker_count) }}</td>
                                        <td class="text-sm text-slate-800">{{ fmtTarget(g.target.visit_broker_count) }}</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-800">{{ fmtTarget(g.target.visit_count) }}</td>
                                        <td class="text-sm text-slate-800">{{ fmtTarget(g.target.deal_count) }}</td>
                                    </tr>
                                    <tr :class="rowClass(g, 'actual')">
                                        <td class="cell-row-label">本月</td>
                                        <td v-for="k in efficiencyKeys" :key="'a-' + k" class="text-sm text-slate-800">{{ g.actual[k] }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.store_count }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.store_visit_distinct }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.store_deal_distinct }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.broker_count }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.visit_broker_count }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.deal_broker_count }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.client_report_distinct }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.client_visit_distinct }}</td>
                                        <td class="text-sm text-slate-800">{{ g.actual.client_deal_distinct }}</td>
                                    </tr>
                                </template>
                                <template v-if="totalGroup">
                                    <tr id="ce-anchor-total" :class="rowClass(totalGroup, 'target')">
                                        <td class="cell-person text-sm text-slate-900" rowspan="2">
                                            <span class="inline-flex items-center justify-center gap-1.5">
                                                <i class="fas fa-chevron-right text-blue-500 text-[10px]"></i>{{ totalGroup.name }}
                                            </span>
                                        </td>
                                        <td class="cell-row-label">目标</td>
                                        <td v-for="k in efficiencyKeys" :key="'tt-' + k" class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target[k]) }}</td>
                                        <td class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target.store_count) }}</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target.broker_count) }}</td>
                                        <td class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target.visit_broker_count) }}</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-400">—</td>
                                        <td class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target.visit_count) }}</td>
                                        <td class="text-sm text-slate-900">{{ fmtTarget(totalGroup.target.deal_count) }}</td>
                                    </tr>
                                    <tr :class="rowClass(totalGroup, 'actual')">
                                        <td class="cell-row-label">本月</td>
                                        <td v-for="k in efficiencyKeys" :key="'ta-' + k" class="text-sm text-slate-900">{{ totalGroup.actual[k] }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.store_count }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.store_visit_distinct }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.store_deal_distinct }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.broker_count }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.visit_broker_count }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.deal_broker_count }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.client_report_distinct }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.client_visit_distinct }}</td>
                                        <td class="text-sm text-slate-900">{{ totalGroup.actual.client_deal_distinct }}</td>
                                    </tr>
                                </template>
                                <tr v-if="progress && efficiencyKeys.length" class="row-progress">
                                    <td colspan="2" class="text-left pl-4">指标进度（全渠道合计）</td>
                                    <td v-for="k in efficiencyKeys" :key="'p-' + k">{{ progress[k] }}</td>
                                    <td colspan="9" class="text-xs text-left text-orange-800 pl-2">店 / 人 / 客户「目标」行：与每月目标页一致的整数项（店带看/店成交/人成交/客户报备暂无目标录入处，显示 —）。客户「成交」：案场 sub_stages 已勾选「认购」「签约」「业确」中任一项即计（与 staff 录入下定一致，漏勾其它项仍算）。</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref, computed } = Vue;

const TARGET_INT_METRIC_KEYS = [
    'store_count', 'broker_count', 'visit_broker_count', 'visit_count', 'deal_count',
];

const DEFAULT_METRIC_KEYS = [
    'store_count', 'broker_count', 'store_avg_report', 'visit_broker_count', 'report_to_visit_rate',
    'visit_count', 'avg_visit_batches_per_broker', 'deal_conversion_rate', 'deal_count',
];

const DEFAULT_EFFICIENCY_KEYS = [
    'store_count', 'store_avg_report', 'report_to_visit_rate', 'avg_visit_batches_per_broker', 'deal_conversion_rate',
];

const TRIPLE_SUB_HEADERS = ['报备', '带看', '成交'];

const METRIC_HINTS = {
    store_count: '筛选起止日内、该渠道跟进人名下报备：公司名去重（已合并 Tab/不换行空格/全角空格等后再去重）；与导出请用相同日期范围',
    broker_count: '按经纪人电话+姓名去重后的报备经纪人数',
    visit_broker_count: '已带看（status≥2）的报备中去重经纪人数',
    visit_count: '带看组数（报备中 status≥2 的条数之和）',
    deal_count: '成交套数（status=4）',
    store_avg_report: '报备经纪人数÷报备门店数，保留两位小数',
    report_to_visit_rate: '带看经纪人数÷报备经纪人数（百分比）',
    avg_visit_batches_per_broker: '带看组数÷带看经纪人数',
    deal_conversion_rate: '成交套数÷带看组数（百分比）',
};

createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('channel_efficiency');
        const loading = ref(false);
        const groups = ref([]);
        const progress = ref(null);
        const asOfLabel = ref('');
        const targetPeriod = ref({ year: null, month: null, label: '' });
        const metricKeys = ref([...DEFAULT_METRIC_KEYS]);
        const efficiencyKeys = ref([...DEFAULT_EFFICIENCY_KEYS]);
        const tripleSubHeaders = TRIPLE_SUB_HEADERS;
        const metricLabels = ref({});
        const timeRange = ref('month');
        const customStartDate = ref('');
        const customEndDate = ref('');

        const personGroups = computed(() => groups.value.filter((g) => g.follower_key !== '__TOTAL__'));
        const totalGroup = computed(() => groups.value.find((g) => g.follower_key === '__TOTAL__') || null);

        const targetsPageHref = computed(() => {
            const y = targetPeriod.value?.year;
            const m = targetPeriod.value?.month;
            if (y && m) {
                return `channel_targets.php?year=${encodeURIComponent(y)}&month=${encodeURIComponent(m)}`;
            }
            return 'channel_targets.php';
        });

        /** 合计行各整数目标之和为 0 → 引导去目标页录入 */
        const targetsNeedSetup = computed(() => {
            const tg = totalGroup.value;
            if (!tg || !tg.target) return false;
            let s = 0;
            for (const k of TARGET_INT_METRIC_KEYS) {
                s += Number(tg.target[k]) || 0;
            }
            return s === 0;
        });

        /** 本地日历 YYYY-MM-DD（勿用 toISOString，否则 UTC 会错位到前一天） */
        function padDate(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        function fmtTarget(v) {
            if (v === null || v === undefined || v === '') return '—';
            if (typeof v === 'string') {
                if (v === '—') return '—';
                if (v.includes('%')) return v;
                const ns = Number(v);
                if (Number.isFinite(ns)) return ns <= 0 ? '—' : ns;
                return v;
            }
            const n = Number(v);
            if (!Number.isFinite(n) || n <= 0) return '—';
            return n;
        }

        function rowClass(g, kind) {
            const isTotal = g.follower_key === '__TOTAL__';
            if (isTotal) {
                return kind === 'target' ? 'row-total-target' : 'row-total-actual';
            }
            return kind === 'target' ? 'row-target' : 'row-actual';
        }

        function scrollToPerson(index) {
            const el = document.getElementById('ce-anchor-' + index);
            el?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function scrollToTotal() {
            document.getElementById('ce-anchor-total')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }

        function setTimeRange(range) {
            timeRange.value = range;
            const today = new Date();
            let startDate;
            let endDate;
            switch (range) {
                case 'today':
                    startDate = new Date(today);
                    endDate = new Date(today);
                    break;
                case 'week': {
                    // 本周：周一 00:00 所在自然日 ～ 周日（与 today 同一周）
                    const dow = today.getDay(); // 0 周日 … 6 周六
                    const daysSinceMonday = dow === 0 ? 6 : dow - 1;
                    startDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - daysSinceMonday);
                    startDate.setHours(0, 0, 0, 0);
                    endDate = new Date(startDate);
                    endDate.setDate(startDate.getDate() + 6);
                    break;
                }
                case 'month':
                    // 本月：当月 1 号 ～ 今天（与「从 1 号起算」一致；结束日仍含今日数据）
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    startDate.setHours(0, 0, 0, 0);
                    endDate = new Date(today);
                    break;
                default:
                    return;
            }
            customStartDate.value = padDate(startDate);
            customEndDate.value = padDate(endDate);
            loadData();
        }

        function applyCustomDate() {
            if (customStartDate.value && customEndDate.value) {
                timeRange.value = 'custom';
                loadData();
            }
        }

        async function loadData() {
            loading.value = true;
            try {
                const params = new URLSearchParams({ action: 'get_channel_efficiency' });
                if (customStartDate.value && customEndDate.value) {
                    params.append('start_date', customStartDate.value);
                    params.append('end_date', customEndDate.value);
                }
                const res = await fetch('channel_efficiency.php?' + params.toString());
                const data = await res.json();
                if (data.code === 0) {
                    groups.value = Array.isArray(data.groups) ? data.groups : [];
                    progress.value = data.progress || null;
                    asOfLabel.value = data.as_of_label || '';
                    targetPeriod.value = data.target_period && data.target_period.label
                        ? data.target_period
                        : { year: null, month: null, label: '' };
                    if (Array.isArray(data.metric_keys) && data.metric_keys.length) {
                        metricKeys.value = data.metric_keys;
                    }
                    if (Array.isArray(data.efficiency_dimension_keys) && data.efficiency_dimension_keys.length) {
                        efficiencyKeys.value = data.efficiency_dimension_keys;
                    }
                    metricLabels.value = data.metric_labels && typeof data.metric_labels === 'object' ? data.metric_labels : {};
                }
            } catch (e) {
                console.error(e);
            } finally {
                loading.value = false;
            }
        }

        setTimeRange('month');

        return {
            sidebarOpen, view, loading, groups, progress, asOfLabel, targetPeriod,
            metricKeys, efficiencyKeys, tripleSubHeaders, metricLabels, metricHints: METRIC_HINTS,
            personGroups, totalGroup, targetsPageHref, targetsNeedSetup,
            timeRange, customStartDate, customEndDate,
            setTimeRange, applyCustomDate, loadData, fmtTarget, rowClass,
            scrollToPerson, scrollToTotal,
        };
    },
}).mount('#app');
</script>
</body>
</html>
