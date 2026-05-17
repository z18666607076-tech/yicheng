<?php
// channel_targets.php - 渠道人员「每月目标」手工录入
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

$action = $_GET['action'] ?? 'view';
$adminId = (int)($_SESSION['admin_id'] ?? 0);

if ($action === 'get_target_form') {
    header('Content-Type: application/json; charset=utf-8');
    $year = max(2020, min(2035, (int)($_GET['year'] ?? date('Y'))));
    $month = max(1, min(12, (int)($_GET['month'] ?? date('n'))));
    channel_monthly_targets_ensure_table($pdo);
    $roster = channel_efficiency_load_roster($pdo);
    $map = channel_monthly_targets_fetch_map($pdo, $year, $month);
    $metrics = channel_efficiency_metric_int_keys();
    $labels = channel_monthly_targets_metric_labels();
    $rows = [];
    foreach ($roster as $p) {
        $fk = (string)$p['follower_key'];
        $cells = [];
        foreach ($metrics as $mk) {
            $cells[$mk] = (int)($map[$fk][$mk] ?? 0);
        }
        $rows[] = [
            'follower_key' => $fk,
            'name' => $p['name'],
            'agent_id' => $p['agent_id'],
            'metrics' => $cells,
        ];
    }
    echo json_encode([
        'code' => 0,
        'year' => $year,
        'month' => $month,
        'metrics' => $metrics,
        'labels' => $labels,
        'rows' => $rows,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_targets') {
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        echo json_encode(['code' => 1, 'msg' => '请使用 POST']);
        exit;
    }
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: 'null', true);
    if (!is_array($body)) {
        echo json_encode(['code' => 1, 'msg' => 'JSON 无效']);
        exit;
    }
    $year = max(2020, min(2035, (int)($body['year'] ?? 0)));
    $month = max(1, min(12, (int)($body['month'] ?? 0)));
    if ($year < 2020 || $month < 1) {
        echo json_encode(['code' => 1, 'msg' => '年月无效']);
        exit;
    }
    $metrics = channel_efficiency_metric_int_keys();
    $allowed = array_flip($metrics);
    $rowsIn = $body['rows'] ?? null;
    if (!is_array($rowsIn)) {
        echo json_encode(['code' => 1, 'msg' => 'rows 无效']);
        exit;
    }
    channel_monthly_targets_ensure_table($pdo);
    $sql = 'INSERT INTO channel_monthly_targets (follower_key, target_year, target_month, metric_key, target_value, updated_by)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE target_value = VALUES(target_value), updated_by = VALUES(updated_by)';
    $stmt = $pdo->prepare($sql);
    try {
        $pdo->beginTransaction();
        foreach ($rowsIn as $r) {
            $fk = trim((string)($r['follower_key'] ?? ''));
            if ($fk === '' || strlen($fk) > 190) {
                continue;
            }
            $mets = $r['metrics'] ?? null;
            if (!is_array($mets)) {
                continue;
            }
            foreach ($mets as $mk => $val) {
                $mk = (string)$mk;
                if (!isset($allowed[$mk])) {
                    continue;
                }
                $v = (int)$val;
                if ($v < 0) {
                    $v = 0;
                }
                $stmt->execute([$fk, $year, $month, $mk, $v, $adminId > 0 ? $adminId : null]);
            }
        }
        $pdo->commit();
        echo json_encode(['code' => 0, 'msg' => '已保存']);
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['code' => 1, 'msg' => '保存失败']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>每月目标 - 渠道效能</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        th { background: #3b82f6; color: #fff; font-weight: 700; font-size: 12px; padding: 10px 6px; border: 1px solid #2563eb; }
        td { border: 1px solid #e2e8f0; padding: 6px; font-size: 13px; }
        .inp { width: 100%; max-width: 96px; margin: 0 auto; text-align: center; border: 1px solid #cbd5e1; border-radius: 6px; padding: 4px 6px; font-size: 13px; }
        .rate-cell { background: #f1f5f9; font-weight: 600; color: #334155; text-align: center; white-space: nowrap; min-width: 88px; }
        th.rate-th { background: #1d4ed8; }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">每月目标</h2>
                <span class="text-xs text-slate-500">渠道人员 · 按自然月维护「本月目标」数值</span>
            </div>
            <div class="flex items-center gap-3">
                <a href="channel_efficiency.php" class="text-sm text-blue-600 hover:underline"><i class="fas fa-chart-bar mr-1"></i>返回渠道效能</a>
            </div>
        </header>
        <div class="flex-1 overflow-auto p-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 max-w-[1400px] mx-auto">
                <div class="flex flex-wrap items-end gap-4 mb-6">
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">年份</label>
                        <select
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 bg-white min-w-[7.5rem]"
                            :value="String(year)"
                            @change="onYearSelect($event)"
                        >
                            <option v-for="y in yearOptions" :key="'y-' + y" :value="String(y)">{{ y }} 年</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-slate-500 mb-1">月份</label>
                        <select
                            class="border border-slate-300 rounded-lg px-3 py-2 text-sm text-slate-900 bg-white min-w-[6.5rem]"
                            :value="String(month)"
                            @change="onMonthSelect($event)"
                        >
                            <option v-for="m in monthOptions" :key="'m-' + m" :value="String(m)">{{ m }} 月</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-1">
                        <button type="button" @click="shiftMonth(-1)" class="px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-50" title="上一自然月" :disabled="loading">‹ 上月</button>
                        <button type="button" @click="goThisMonth" class="px-3 py-2 border border-amber-300 bg-amber-50 text-amber-900 rounded-lg text-sm font-bold hover:bg-amber-100" title="当前日历月" :disabled="loading">本月</button>
                        <button type="button" @click="shiftMonth(1)" class="px-3 py-2 border border-slate-300 rounded-lg text-sm font-bold text-slate-700 hover:bg-slate-50" title="下一自然月" :disabled="loading">下月 ›</button>
                    </div>
                    <button type="button" @click="loadForm" class="px-4 py-2 bg-slate-700 text-white rounded-lg text-sm font-bold hover:bg-slate-800" :disabled="loading">
                        <i class="fas fa-sync-alt mr-1" :class="{'fa-spin': loading}"></i>加载
                    </button>
                    <button type="button" @click="saveAll" class="px-4 py-2 bg-blue-600 text-white rounded-lg text-sm font-bold hover:bg-blue-700" :disabled="saving">
                        <i class="fas fa-save mr-1"></i>{{ saving ? '保存中…' : '保存全部' }}
                    </button>
                    <p v-if="msg" class="text-sm" :class="msgOk ? 'text-green-600' : 'text-red-600'">{{ msg }}</p>
                </div>
                <div class="mb-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-xs text-slate-700 leading-relaxed">
                    <p class="font-bold text-slate-800 mb-1.5"><i class="fas fa-circle-info text-blue-500 mr-1"></i>怎么查历史月、怎么录当月（例如已是 6 月）</p>
                    <ul class="list-disc pl-4 space-y-1">
                        <li><strong>查 5 月目标：</strong>年份选「2026」、月份选「5」，点「加载」。也可在已是 6 月时连续点「‹ 上月」回到 5 月。支持在浏览器地址栏直接打开带参数的链接（改完年月会自动出现在地址栏，便于收藏），例如：<code class="bg-white border border-slate-200 px-1.5 py-0.5 rounded text-[11px]">channel_targets.php?year=2026&amp;month=5</code></li>
                        <li><strong>录 6 月目标：</strong>年份、月份选到「2026」「6」，或点「本月」一键切到当前日历月，填表后点「保存全部」。</li>
                        <li><strong>和「渠道效能」页对照：</strong>效能里「本月目标」那一行，按<strong>筛选区里的结束日期</strong>落在哪个月，就读哪个月的这里的数据。要看 6 月目标是否达标，请把开始/结束日期选在 6 月内（例如结束日选 6 月 30 日）。</li>
                    </ul>
                </div>
                <div v-if="rows.length && monthTotalTargets === 0" class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-950 flex flex-wrap items-center justify-between gap-2">
                    <span><i class="fas fa-info-circle mr-1 text-amber-600"></i>当前 <strong>{{ year }} 年 {{ month }} 月</strong> 各渠道人员目标合计仍为 <strong>0</strong>，请在下方表格填写后点「保存全部」。</span>
                </div>
                <div class="overflow-x-auto rounded-lg border border-slate-200">
                    <table class="min-w-full border-collapse">
                        <thead>
                            <tr>
                                <th class="text-left pl-3">渠道人员</th>
                                <th v-for="mk in metrics" :key="mk">{{ labels[mk] || mk }}</th>
                                <th class="rate-th" title="报备经纪人数÷启动门店（预览）">店均报备</th>
                                <th class="rate-th" title="带看经纪人数÷报备经纪人数（预览）">报备转带看率</th>
                                <th class="rate-th" title="带看组数÷带看经纪人数（预览）">人均带看组数</th>
                                <th class="rate-th" title="成交套数÷带看组数（预览）">成交转化率</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="(row, ri) in rows" :key="row.follower_key + '-' + ri" class="bg-white hover:bg-slate-50">
                                <td class="font-semibold text-slate-800 pl-3 bg-slate-50">{{ row.name }}</td>
                                <td v-for="mk in metrics" :key="row.follower_key + '-' + mk">
                                    <input type="number" min="0" step="1" class="inp" v-model.number="row.metrics[mk]" @focus="msg = ''">
                                </td>
                                <td class="rate-cell">{{ rateStoreAvgReport(row) }}</td>
                                <td class="rate-cell">{{ rateReportToVisit(row) }}</td>
                                <td class="rate-cell">{{ rateAvgVisitGroupsPerBroker(row) }}</td>
                                <td class="rate-cell">{{ rateDealConversion(row) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-slate-500 mt-4">说明：渠道效能页「本月目标」按<strong>筛选结束日期所在年、月</strong>读取此处左侧可填列。右侧四列为预览：<strong>店均报备</strong>（报备经纪人÷启动门店）、<strong>报备转带看率</strong>（带看经纪人÷报备经纪人）、<strong>人均带看组数</strong>（带看组数÷带看经纪人数）、<strong>成交转化率</strong>（成交套数÷带看组数），<strong>不落库</strong>；与效能页「本月目标」「截止」口径一致。</p>
            </div>
        </div>
    </main>
</div>
<script>
const { createApp, ref, computed, onMounted } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('channel_targets');
        const loading = ref(false);
        const saving = ref(false);
        const year = ref(new Date().getFullYear());
        const month = ref(new Date().getMonth() + 1);
        const yearOptions = ref([]);
        const monthOptions = ref([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
        const metrics = ref([]);
        const labels = ref({});
        const rows = ref([]);
        const msg = ref('');
        const msgOk = ref(true);

        const monthTotalTargets = computed(() => {
            let s = 0;
            for (const r of rows.value) {
                const m = r.metrics || {};
                for (const v of Object.values(m)) {
                    s += Number(v) || 0;
                }
            }
            return s;
        });

        /** 报备经纪人数÷启动门店 */
        function rateStoreAvgReport(row) {
            const m = row.metrics || {};
            const bc = Number(m.broker_count) || 0;
            const sc = Number(m.store_count) || 0;
            if (sc <= 0) return '—';
            return (Math.round((bc / sc) * 100) / 100).toFixed(2);
        }

        /** 带看经纪人数÷报备经纪人数 */
        function rateReportToVisit(row) {
            const m = row.metrics || {};
            const bc = Number(m.broker_count) || 0;
            const vbc = Number(m.visit_broker_count) || 0;
            if (bc <= 0) return '—';
            return (Math.round((vbc / bc) * 1000) / 10).toFixed(1) + '%';
        }

        /** 带看组数÷带看经纪人数 */
        function rateAvgVisitGroupsPerBroker(row) {
            const m = row.metrics || {};
            const vbc = Number(m.visit_broker_count) || 0;
            const vc = Number(m.visit_count) || 0;
            if (vbc <= 0) return '—';
            return (Math.round((vc / vbc) * 100) / 100).toFixed(2);
        }

        /** 成交套数÷带看组数 */
        function rateDealConversion(row) {
            const m = row.metrics || {};
            const vc = Number(m.visit_count) || 0;
            const dc = Number(m.deal_count) || 0;
            if (vc <= 0) return '—';
            return (Math.round((dc / vc) * 1000) / 10).toFixed(1) + '%';
        }

        function syncQueryToUrl() {
            try {
                const u = new URL(window.location.href);
                u.searchParams.set('year', String(year.value));
                u.searchParams.set('month', String(month.value));
                const qs = u.searchParams.toString();
                window.history.replaceState({}, '', u.pathname + (qs ? '?' + qs : ''));
            } catch (e) { /* ignore */ }
        }

        function shiftMonth(delta) {
            let y = year.value;
            let mo = month.value + delta;
            while (mo < 1) {
                mo += 12;
                y -= 1;
            }
            while (mo > 12) {
                mo -= 12;
                y += 1;
            }
            if (y < 2020) {
                y = 2020;
                mo = 1;
            }
            if (y > 2035) {
                y = 2035;
                mo = 12;
            }
            year.value = y;
            month.value = mo;
            loadForm();
        }

        function goThisMonth() {
            const d = new Date();
            year.value = d.getFullYear();
            month.value = d.getMonth() + 1;
            loadForm();
        }

        function onPeriodChange() {
            loadForm();
        }

        function onYearSelect(ev) {
            const v = parseInt(String(ev.target && ev.target.value), 10);
            if (v >= 2020 && v <= 2035) {
                year.value = v;
                onPeriodChange();
            }
        }

        function onMonthSelect(ev) {
            const v = parseInt(String(ev.target && ev.target.value), 10);
            if (v >= 1 && v <= 12) {
                month.value = v;
                onPeriodChange();
            }
        }

        const y0 = new Date().getFullYear();
        for (let i = y0 - 2; i <= y0 + 2; i++) {
            yearOptions.value.push(i);
        }

        async function loadForm() {
            loading.value = true;
            msg.value = '';
            try {
                const u = new URLSearchParams({ action: 'get_target_form', year: String(year.value), month: String(month.value) });
                const res = await fetch('channel_targets.php?' + u.toString());
                const d = await res.json();
                if (d.code === 0) {
                    metrics.value = d.metrics || [];
                    labels.value = d.labels || {};
                    rows.value = (d.rows || []).map((r) => ({
                        follower_key: r.follower_key,
                        name: r.name,
                        agent_id: r.agent_id,
                        metrics: { ...r.metrics },
                    }));
                    syncQueryToUrl();
                }
            } catch (e) {
                console.error(e);
            } finally {
                loading.value = false;
            }
        }

        async function saveAll() {
            saving.value = true;
            msg.value = '';
            try {
                const res = await fetch('channel_targets.php?action=save_targets', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        year: year.value,
                        month: month.value,
                        rows: rows.value.map((r) => ({
                            follower_key: r.follower_key,
                            metrics: r.metrics,
                        })),
                    }),
                });
                const d = await res.json();
                msg.value = d.msg || (d.code === 0 ? '保存成功' : '失败');
                msgOk.value = d.code === 0;
            } catch (e) {
                msg.value = '网络错误';
                msgOk.value = false;
            } finally {
                saving.value = false;
            }
        }

        onMounted(() => {
            const p = new URLSearchParams(window.location.search);
            const y = parseInt(p.get('year') || '', 10);
            const m = parseInt(p.get('month') || '', 10);
            if (y >= 2020 && y <= 2035) year.value = y;
            if (m >= 1 && m <= 12) month.value = m;
            year.value = parseInt(String(year.value), 10) || new Date().getFullYear();
            month.value = parseInt(String(month.value), 10) || (new Date().getMonth() + 1);
            loadForm();
        });

        return {
            sidebarOpen, view, loading, saving, year, month, yearOptions, monthOptions, metrics, labels, rows, msg, msgOk,
            monthTotalTargets,
            rateStoreAvgReport, rateReportToVisit, rateAvgVisitGroupsPerBroker, rateDealConversion,
            loadForm, saveAll, shiftMonth, goThisMonth, onYearSelect, onMonthSelect,
        };
    },
}).mount('#app');
</script>
</body>
</html>
