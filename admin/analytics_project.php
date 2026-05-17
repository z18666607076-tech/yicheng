<?php
// analytics_project.php — 项目能效（按项目；本月 / 同比 / 环比）
declare(strict_types=1);

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

$METRIC_INT_KEYS = channel_efficiency_metric_int_keys();

$EFFICIENCY_DIMENSION_KEYS = [
    'store_count',
    'store_avg_report',
    'report_to_visit_rate',
    'avg_visit_batches_per_broker',
    'deal_conversion_rate',
];

function ap_sql_company_norm_key(string $alias = 'f'): string
{
    $c = "{$alias}.company_name";
    return "NULLIF(TRIM(BOTH ' ' FROM REPLACE(REPLACE(REPLACE(TRIM(IFNULL({$c},'')), CHAR(9), ' '), UNHEX('C2A0'), ' '), UNHEX('E38080'), ' ')), '')";
}

function ap_zero_int_metrics(array $keys): array
{
    $o = [];
    foreach ($keys as $k) {
        $o[$k] = 0;
    }
    return $o;
}

function ap_apply_rates(array &$actual): void
{
    $bc = (int)($actual['broker_count'] ?? 0);
    $vbc = (int)($actual['visit_broker_count'] ?? 0);
    $vc = (int)($actual['visit_count'] ?? 0);
    $dc = (int)($actual['deal_count'] ?? 0);
    $sc = (int)($actual['store_count'] ?? 0);
    $actual['store_avg_report'] = $sc > 0 ? round($bc / $sc, 2) : '—';
    $actual['report_to_visit_rate'] = $bc > 0 ? round($vbc / $bc * 100, 1) . '%' : '—';
    $actual['avg_visit_batches_per_broker'] = $vbc > 0 ? round($vc / $vbc, 2) : '—';
    $actual['deal_conversion_rate'] = $vc > 0 ? round($dc / $vc * 100, 1) . '%' : '—';
}

/** 空档期：整数全 0 + 派生率 */
function ap_empty_actual(array $metricIntKeys): array
{
    $a = ap_zero_int_metrics($metricIntKeys);
    ap_apply_rates($a);
    $a['store_visit_distinct'] = 0;
    $a['store_deal_distinct'] = 0;
    $a['client_report_distinct'] = 0;
    $a['client_visit_distinct'] = 0;
    $a['client_deal_distinct'] = 0;
    $a['deal_broker_count'] = 0;
    return $a;
}

/**
 * @return array<int, array{name: string, sort: int, actual: array<string, mixed>}>
 */
function ap_fetch_project_metrics(PDO $pdo, string $startDate, string $endDate, array $metricIntKeys): array
{
    $companyKey = ap_sql_company_norm_key('f');
    $sql = "SELECT
        p.id AS project_id,
        MAX(COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('项目#', p.id))) AS project_name,
        COUNT(f.id) AS sort_weight,
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
        COUNT(DISTINCT CASE WHEN f.status = 4 AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_deal_distinct
    FROM filings f
    INNER JOIN projects p ON p.id = f.project_id
    WHERE DATE(f.created_at) BETWEEN ? AND ?
    GROUP BY p.id
    ORDER BY sort_weight DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$startDate, $endDate]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $out = [];
    foreach ($rows as $row) {
        $id = (int)($row['project_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $actual = ap_zero_int_metrics($metricIntKeys);
        foreach ($metricIntKeys as $k) {
            $actual[$k] = (int)($row[$k] ?? 0);
        }
        ap_apply_rates($actual);
        $actual['store_visit_distinct'] = (int)($row['store_visit_distinct'] ?? 0);
        $actual['store_deal_distinct'] = (int)($row['store_deal_distinct'] ?? 0);
        $actual['client_report_distinct'] = (int)($row['client_report_distinct'] ?? 0);
        $actual['client_visit_distinct'] = (int)($row['client_visit_distinct'] ?? 0);
        $actual['client_deal_distinct'] = (int)($row['client_deal_distinct'] ?? 0);
        $actual['deal_broker_count'] = (int)($row['deal_broker_count'] ?? 0);

        $out[$id] = [
            'name' => (string)($row['project_name'] ?? ('项目#' . $id)),
            'sort' => (int)($row['sort_weight'] ?? 0),
            'actual' => $actual,
        ];
    }
    return $out;
}

function ap_parse_date(string $s): ?string
{
    $s = trim($s);
    if ($s !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) {
        return $s;
    }
    return null;
}

function ap_shift_range_yoy(string $start, string $end): array
{
    $ds = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if (!$ds || !$de) {
        return [$start, $end];
    }
    return [
        $ds->modify('-1 year')->format('Y-m-d'),
        $de->modify('-1 year')->format('Y-m-d'),
    ];
}

/** 与当前区间等长的上一段（环比） */
function ap_shift_range_mom(string $start, string $end): array
{
    $ds = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if (!$ds || !$de) {
        return [$start, $end];
    }
    $days = $ds->diff($de)->days + 1;
    $momEnd = $ds->modify('-1 day');
    $momStart = $momEnd->modify('-' . ($days - 1) . ' days');
    return [$momStart->format('Y-m-d'), $momEnd->format('Y-m-d')];
}

$action = $_GET['action'] ?? 'view';

if ($action === 'get_project_data') {
    header('Content-Type: application/json; charset=utf-8');

    $start = ap_parse_date((string)($_GET['start_date'] ?? ''));
    $end = ap_parse_date((string)($_GET['end_date'] ?? ''));
    if ($start === null || $end === null) {
        $start = date('Y-m-01');
        $end = date('Y-m-d');
    }
    if ($start > $end) {
        $t = $start;
        $start = $end;
        $end = $t;
    }

    [$yoyStart, $yoyEnd] = ap_shift_range_yoy($start, $end);
    [$momStart, $momEnd] = ap_shift_range_mom($start, $end);

    $currentMap = ap_fetch_project_metrics($pdo, $start, $end, $METRIC_INT_KEYS);
    $yoyMap = ap_fetch_project_metrics($pdo, $yoyStart, $yoyEnd, $METRIC_INT_KEYS);
    $momMap = ap_fetch_project_metrics($pdo, $momStart, $momEnd, $METRIC_INT_KEYS);

    $empty = ap_empty_actual($METRIC_INT_KEYS);

    $projects = [];
    foreach ($currentMap as $pid => $pack) {
        $projects[] = [
            'project_id' => $pid,
            'name' => $pack['name'],
            'current' => $pack['actual'],
            'yoy' => $yoyMap[$pid]['actual'] ?? $empty,
            'mom' => $momMap[$pid]['actual'] ?? $empty,
        ];
    }

    $metricLabels = channel_efficiency_metric_table_labels();

    echo json_encode([
        'code' => 0,
        'projects' => $projects,
        'efficiency_dimension_keys' => $EFFICIENCY_DIMENSION_KEYS,
        'metric_labels' => $metricLabels,
        'periods' => [
            'current' => ['start' => $start, 'end' => $end, 'label' => '本月（筛选区间）'],
            'yoy' => ['start' => $yoyStart, 'end' => $yoyEnd, 'label' => '同比（去年同期同区间）'],
            'mom' => ['start' => $momStart, 'end' => $momEnd, 'label' => '环比（上一等长区间）'],
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目能效 - 数据分析</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .table-container { overflow-x: auto; }
        table { min-width: 1400px; border-collapse: collapse; }
        .blue-header th { background-color: #3b82f6; color: white; font-weight: bold; font-size: 12px; text-align: center; padding: 8px 6px; border: 1px solid #2563eb; }
        .blue-header th.sub-th { font-size: 11px; font-weight: 600; padding: 6px 4px; }
        td { font-size: 13px; text-align: center; padding: 8px 6px; border: 1px solid #e2e8f0; vertical-align: middle; }
        .row-current { background-color: #fff; }
        .row-yoy { background-color: #ecfccb; }
        .row-mom { background-color: #d9f99d; }
        .cell-project { font-weight: 600; text-align: center; background: #f8fafc; min-width: 120px; cursor: pointer; user-select: none; }
        .cell-project:hover { background: #f1f5f9; }
        .cell-row-label { text-align: center; font-size: 12px; color: #475569; white-space: nowrap; min-width: 72px; background: #f8fafc; }
        .section-cap { background-color: #3b82f6; color: white; font-weight: bold; font-size: 14px; }
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

        <header class="bg-white border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex flex-col gap-2 py-2 px-3 sm:px-4 md:flex-row md:items-center md:justify-between md:h-16 md:py-0 md:px-8">
                <div class="flex items-center gap-2 sm:gap-4 min-w-0">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 p-2 -ml-1 rounded-lg text-gray-600 hover:bg-gray-100" aria-label="打开菜单">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                    <h2 class="text-base md:text-lg font-bold text-slate-800 truncate shrink">项目能效</h2>
                    <a href="analytics.php" class="text-xs sm:text-sm text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap shrink-0">
                        <i class="fas fa-user-tie mr-1"></i>经纪人能效
                    </a>
                </div>
                <div class="flex flex-wrap gap-2 md:gap-3 justify-start md:justify-end items-center">
                    <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-lg">
                        <button type="button" @click="setTimeRange('today')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='today'?'bg-white text-blue-700 shadow':'text-gray-500'">本日</button>
                        <button type="button" @click="setTimeRange('week')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='week'?'bg-white text-blue-700 shadow':'text-gray-500'">本周</button>
                        <button type="button" @click="setTimeRange('month')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange==='month'?'bg-white text-blue-700 shadow':'text-gray-500'">本月</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <input type="date" v-model="customStartDate" class="px-3 py-1.5 border rounded-md text-xs">
                        <input type="date" v-model="customEndDate" class="px-3 py-1.5 border rounded-md text-xs">
                        <button type="button" @click="applyCustomDate" class="px-3 py-1.5 bg-blue-600 text-white rounded-md text-xs font-bold">确定</button>
                    </div>
                    <button type="button" @click="loadData" class="text-gray-500 hover:text-indigo-600 px-2 py-2 text-sm transition" title="手动刷新">
                        <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                    </button>
                </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-4 md:p-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="section-cap py-3 px-4 rounded-t-lg mb-0 flex flex-wrap items-center justify-between gap-2">
                    <span>项目数据</span>
                    <span v-if="periods && periods.current" class="text-xs sm:text-sm font-normal opacity-95">
                        本月：<strong>{{ periods.current.start }}</strong> ～ <strong>{{ periods.current.end }}</strong>
                        <span class="opacity-80 mx-1">|</span>
                        同比：<strong>{{ periods.yoy.start }}</strong> ～ <strong>{{ periods.yoy.end }}</strong>
                        <span class="opacity-80 mx-1">|</span>
                        环比：<strong>{{ periods.mom.start }}</strong> ～ <strong>{{ periods.mom.end }}</strong>
                    </span>
                </div>
                <div class="table-container border border-t-0 border-gray-200 rounded-b-lg">
                    <table class="min-w-full">
                        <thead class="blue-header">
                            <tr>
                                <th rowspan="2" class="align-middle">项目</th>
                                <th rowspan="2" class="align-middle">分项</th>
                                <th colspan="5">效能维度</th>
                                <th colspan="3">店</th>
                                <th colspan="3">人</th>
                                <th colspan="3">客户</th>
                            </tr>
                            <tr>
                                <th v-for="k in efficiencyKeys" :key="'h-' + k" class="sub-th" :title="metricHints[k] || ''">{{ metricLabels[k] || k }}</th>
                                <th v-for="(lab, ti) in tripleSubHeaders" :key="'st-' + ti" class="sub-th">{{ lab }}</th>
                                <th v-for="(lab, ti) in tripleSubHeaders" :key="'pe-' + ti" class="sub-th">{{ lab }}</th>
                                <th v-for="(lab, ti) in tripleSubHeaders" :key="'cl-' + ti" class="sub-th">{{ lab }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template v-for="(g, gi) in projects" :key="'proj-' + g.project_id">
                                <tr :class="rowClass('current')">
                                    <td class="cell-project text-sm text-slate-800"
                                        :rowspan="isExpanded(g.project_id) ? 3 : 1"
                                        @click="toggleExpand(g.project_id)">
                                        <span class="inline-flex items-center justify-center gap-1.5">
                                            <i :class="isExpanded(g.project_id) ? 'fas fa-chevron-down' : 'fas fa-chevron-right'" class="text-blue-500 text-[10px]"></i>
                                            {{ g.name }}
                                        </span>
                                    </td>
                                    <td class="cell-row-label">本月</td>
                                    <td v-for="k in efficiencyKeys" :key="'c-' + k" class="text-sm text-slate-800">{{ g.current[k] }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.store_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.store_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.store_deal_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.visit_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.deal_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.client_report_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.client_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.current.client_deal_distinct }}</td>
                                </tr>
                                <tr v-if="isExpanded(g.project_id)" :class="rowClass('yoy')">
                                    <td class="cell-row-label">同比</td>
                                    <td v-for="k in efficiencyKeys" :key="'y-' + k" class="text-sm text-slate-800">{{ g.yoy[k] }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.store_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.store_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.store_deal_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.visit_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.deal_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.client_report_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.client_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.yoy.client_deal_distinct }}</td>
                                </tr>
                                <tr v-if="isExpanded(g.project_id)" :class="rowClass('mom')">
                                    <td class="cell-row-label">环比</td>
                                    <td v-for="k in efficiencyKeys" :key="'m-' + k" class="text-sm text-slate-800">{{ g.mom[k] }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.store_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.store_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.store_deal_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.visit_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.deal_broker_count }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.client_report_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.client_visit_distinct }}</td>
                                    <td class="text-sm text-slate-800">{{ g.mom.client_deal_distinct }}</td>
                                </tr>
                            </template>
                            <tr v-if="!projects.length && !loading">
                                <td colspan="16" class="py-10 text-slate-500 text-sm">暂无项目报备数据（请调整日期或确认报备已关联项目）</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref } = Vue;

const DEFAULT_EFFICIENCY_KEYS = [
    'store_count', 'store_avg_report', 'report_to_visit_rate', 'avg_visit_batches_per_broker', 'deal_conversion_rate',
];
const TRIPLE_SUB_HEADERS = ['报备', '带看', '成交'];
const METRIC_HINTS = {
    store_count: '筛选起止日内、该项目下报备：公司名去重（已合并 Tab/不换行空格/全角空格等后再去重）',
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
        const loading = ref(false);
        const projects = ref([]);
        const periods = ref(null);
        const efficiencyKeys = ref([...DEFAULT_EFFICIENCY_KEYS]);
        const metricLabels = ref({});
        const tripleSubHeaders = TRIPLE_SUB_HEADERS;
        const timeRange = ref('month');
        const customStartDate = ref('');
        const customEndDate = ref('');
        /** @type {import('vue').Ref<Record<number, boolean>>} */
        const expandedById = ref({});

        function padLocalDate(d) {
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            return `${y}-${m}-${day}`;
        }

        function rowClass(kind) {
            if (kind === 'yoy') return 'row-yoy';
            if (kind === 'mom') return 'row-mom';
            return 'row-current';
        }

        function isExpanded(projectId) {
            return expandedById.value[projectId] === true;
        }

        function toggleExpand(projectId) {
            expandedById.value = {
                ...expandedById.value,
                [projectId]: !isExpanded(projectId),
            };
        }

        function ensureDefaultExpand(list) {
            const next = { ...expandedById.value };
            let setFirst = false;
            for (let i = 0; i < list.length; i++) {
                const id = list[i].project_id;
                if (next[id] === undefined) {
                    next[id] = i === 0;
                    setFirst = true;
                }
            }
            if (setFirst || Object.keys(next).length) {
                expandedById.value = next;
            }
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
                    const dow = today.getDay();
                    const daysSinceMonday = dow === 0 ? 6 : dow - 1;
                    startDate = new Date(today.getFullYear(), today.getMonth(), today.getDate() - daysSinceMonday);
                    startDate.setHours(0, 0, 0, 0);
                    endDate = new Date(startDate);
                    endDate.setDate(startDate.getDate() + 6);
                    break;
                }
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    startDate.setHours(0, 0, 0, 0);
                    endDate = new Date(today);
                    break;
                default:
                    return;
            }
            customStartDate.value = padLocalDate(startDate);
            customEndDate.value = padLocalDate(endDate);
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
                const params = new URLSearchParams({ action: 'get_project_data' });
                if (customStartDate.value && customEndDate.value) {
                    params.append('start_date', customStartDate.value);
                    params.append('end_date', customEndDate.value);
                }
                const res = await fetch('analytics_project.php?' + params.toString());
                const data = await res.json();
                if (data.code === 0) {
                    projects.value = Array.isArray(data.projects) ? data.projects : [];
                    periods.value = data.periods || null;
                    if (Array.isArray(data.efficiency_dimension_keys) && data.efficiency_dimension_keys.length) {
                        efficiencyKeys.value = data.efficiency_dimension_keys;
                    }
                    metricLabels.value = data.metric_labels && typeof data.metric_labels === 'object' ? data.metric_labels : {};
                    ensureDefaultExpand(projects.value);
                }
            } catch (e) {
                console.error(e);
            } finally {
                loading.value = false;
            }
        }

        setTimeRange('month');

        return {
            sidebarOpen,
            loading,
            projects,
            periods,
            efficiencyKeys,
            metricLabels,
            metricHints: METRIC_HINTS,
            tripleSubHeaders,
            timeRange,
            customStartDate,
            customEndDate,
            setTimeRange,
            applyCustomDate,
            loadData,
            rowClass,
            isExpanded,
            toggleExpand,
        };
    },
}).mount('#app');
</script>
</body>
</html>
