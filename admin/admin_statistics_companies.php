<?php
// admin_statistics_companies.php — 商户统计（由 admin_statistics.php 拆分）
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

if ($action == 'get_company_stats') {
    header('Content-Type: application/json');

    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));

    $todaySql = "SELECT f.company_name, 
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' THEN 1 END) as today_count,
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' AND f.status >= 0 THEN 1 END) as today_filing,
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' AND f.status >= 1 THEN 1 END) as today_contacted,
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' AND f.status >= 2 THEN 1 END) as today_visited,
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' AND f.status >= 3 THEN 1 END) as today_intent,
                 COUNT(CASE WHEN DATE(f.created_at) = '$today' AND f.status >= 4 THEN 1 END) as today_deal,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' THEN 1 END) as week_count,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' AND f.status >= 0 THEN 1 END) as week_filing,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' AND f.status >= 1 THEN 1 END) as week_contacted,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' AND f.status >= 2 THEN 1 END) as week_visited,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' AND f.status >= 3 THEN 1 END) as week_intent,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$weekAgo' AND f.status >= 4 THEN 1 END) as week_deal,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' THEN 1 END) as month_count,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' AND f.status >= 0 THEN 1 END) as month_filing,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' AND f.status >= 1 THEN 1 END) as month_contacted,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' AND f.status >= 2 THEN 1 END) as month_visited,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' AND f.status >= 3 THEN 1 END) as month_intent,
                 COUNT(CASE WHEN DATE(f.created_at) >= '$monthAgo' AND f.status >= 4 THEN 1 END) as month_deal
                 FROM filings f 
                 WHERE f.company_name IS NOT NULL AND f.company_name != ''
                 GROUP BY f.company_name 
                 ORDER BY today_count DESC, week_count DESC, month_count DESC";

    $stmt = $pdo->prepare($todaySql);
    $stmt->execute();
    $companyStats = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['code' => 0, 'data' => $companyStats]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>商户统计</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
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
            <div class="flex items-center gap-4 flex-wrap">
                <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">商户统计</h2>
            </div>
            <div class="flex gap-3">
                <button type="button" @click="loadData" class="text-gray-500 hover:text-indigo-600 px-3 py-2 text-sm transition" title="手动刷新">
                    <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">商户维度 · 今日</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ stats.today }}</p>
                        </div>
                        <div class="p-3 bg-blue-100 rounded-lg">
                            <i class="fas fa-calendar-day text-blue-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">商户维度 · 7天</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ stats.week }}</p>
                        </div>
                        <div class="p-3 bg-green-100 rounded-lg">
                            <i class="fas fa-calendar-week text-green-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">商户维度 · 30天</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ stats.month }}</p>
                        </div>
                        <div class="p-3 bg-purple-100 rounded-lg">
                            <i class="fas fa-calendar text-purple-600 text-xl"></i>
                        </div>
                    </div>
                </div>
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">有数据的商户数</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ stats.total }}</p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-lg">
                            <i class="fas fa-store text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="border-b border-gray-200 pb-4 mb-4">
                    <h3 class="text-lg font-bold text-gray-800 flex items-center">
                        <i class="fas fa-store mr-2 text-amber-600"></i>商户数据明细
                    </h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">商户名称</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">今日数据</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">7天数据</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">30天数据</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="item in companyStats" :key="item.company_name">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.company_name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div class="space-y-1">
                                        <div>总计: {{ item.today_count }}</div>
                                        <div>报备: {{ item.today_filing || 0 }}</div>
                                        <div>联系: {{ item.today_contacted || 0 }}</div>
                                        <div>带看: {{ item.today_visited || 0 }}</div>
                                        <div>意向: {{ item.today_intent || 0 }}</div>
                                        <div>成交: {{ item.today_deal || 0 }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div class="space-y-1">
                                        <div>总计: {{ item.week_count }}</div>
                                        <div>报备: {{ item.week_filing || 0 }}</div>
                                        <div>联系: {{ item.week_contacted || 0 }}</div>
                                        <div>带看: {{ item.week_visited || 0 }}</div>
                                        <div>意向: {{ item.week_intent || 0 }}</div>
                                        <div>成交: {{ item.week_deal || 0 }}</div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    <div class="space-y-1">
                                        <div>总计: {{ item.month_count }}</div>
                                        <div>报备: {{ item.month_filing || 0 }}</div>
                                        <div>联系: {{ item.month_contacted || 0 }}</div>
                                        <div>带看: {{ item.month_visited || 0 }}</div>
                                        <div>意向: {{ item.month_intent || 0 }}</div>
                                        <div>成交: {{ item.month_deal || 0 }}</div>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref, reactive, onMounted } = Vue;

class StatsService {
    static sumCompanyStats(companyStats) {
        let today = 0, week = 0, month = 0;
        companyStats.forEach((item) => {
            today += parseInt(item.today_count, 10) || 0;
            week += parseInt(item.week_count, 10) || 0;
            month += parseInt(item.month_count, 10) || 0;
        });
        return {
            today,
            week,
            month,
            total: companyStats.length,
        };
    }
}

createApp({
    setup() {
        const loading = ref(false);
        const companyStats = ref([]);
        const stats = reactive({ today: 0, week: 0, month: 0, total: 0 });
        const sidebarOpen = ref(false);

        const loadData = async () => {
            loading.value = true;
            try {
                const res = await fetch('./admin_statistics_companies.php?action=get_company_stats');
                const text = await res.text();
                const companyRes = JSON.parse(text);
                companyStats.value = companyRes.data || [];
                Object.assign(stats, StatsService.sumCompanyStats(companyStats.value));
            } catch (err) {
                console.error('加载商户统计失败:', err);
            } finally {
                loading.value = false;
            }
        };

        onMounted(() => {
            loadData();
        });

        return {
            loading,
            companyStats,
            stats,
            sidebarOpen,
            loadData,
        };
    },
}).mount('#app');
</script>
</body>
</html>
