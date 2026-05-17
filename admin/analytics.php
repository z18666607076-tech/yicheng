<?php
// analytics.php - 数据分析页面
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

if ($action == 'get_channel_data') {
    header('Content-Type: application/json');
    
    // 处理时间范围参数
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $time_filter = '';
    if ($start_date && $end_date) {
        $time_filter = "AND DATE(created_at) BETWEEN '$start_date' AND '$end_date'";
    }
    
    // 从数据库获取渠道数据
    $sql = "SELECT 
        broker_name as name,
        COUNT(*) as actual,
        COUNT(DISTINCT company_name) as store_count,
        SUM(CASE WHEN status >= 2 THEN 1 ELSE 0 END) as visit_count,
        SUM(CASE WHEN status = 4 THEN 1 ELSE 0 END) as deal_count,
        SUM(client_num) as total_clients
    FROM filings 
    WHERE broker_name != '' $time_filter
    GROUP BY broker_name 
    ORDER BY actual DESC";
    
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理数据
    foreach ($data as &$item) {
        $item['target'] = $item['actual'] + rand(10, 30); // 模拟目标值
        $item['store_report'] = $item['store_count'] > 0 ? number_format($item['actual'] / $item['store_count'], 2) : 0;
        $item['visit_rate'] = $item['actual'] > 0 ? number_format(($item['visit_count'] / $item['actual']) * 100, 2) . '%' : '0.00%';
        $item['person_per_visit'] = $item['visit_count'] > 0 ? number_format($item['total_clients'] / $item['visit_count'], 2) : 0;
        $item['conversion_rate'] = $item['actual'] > 0 ? number_format(($item['deal_count'] / $item['actual']) * 100, 2) . '%' : '0.00%';
    }
    
    // 计算合计
    if (!empty($data)) {
        $total = [
            'name' => '合计',
            'target' => array_sum(array_column($data, 'target')),
            'actual' => array_sum(array_column($data, 'actual')),
            'store_report' => number_format(array_sum(array_column($data, 'actual')) / array_sum(array_column($data, 'store_count')), 2),
            'visit_rate' => number_format((array_sum(array_column($data, 'visit_count')) / array_sum(array_column($data, 'actual'))) * 100, 2) . '%',
            'person_per_visit' => number_format(array_sum(array_column($data, 'total_clients')) / array_sum(array_column($data, 'visit_count')), 2),
            'conversion_rate' => number_format((array_sum(array_column($data, 'deal_count')) / array_sum(array_column($data, 'actual'))) * 100, 2) . '%',
            'deal_count' => array_sum(array_column($data, 'deal_count')),
        ];
        $data[] = $total;
    }
    
    echo json_encode(['code' => 0, 'data' => $data]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>经纪人能效 - 数据分析</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .table-container { overflow-x: auto; }
        table { min-width: 1000px; }
        th { background-color: #f8fafc; font-size: 12px; font-weight: bold; text-align: center; padding: 8px; border-bottom: 2px solid #e2e8f0; }
        td { font-size: 12px; text-align: center; padding: 8px; border-bottom: 1px solid #f1f5f9; }
        .total-row { background-color: #f1f5f9; font-weight: bold; }
        .blue-header { background-color: #3b82f6; color: white; font-weight: bold; font-size: 14px; }
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
                <h2 class="text-base md:text-lg font-bold text-slate-800 truncate shrink">经纪人能效</h2>
                <a href="analytics_project.php" class="text-xs sm:text-sm text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap shrink-0">
                    <i class="fas fa-building mr-1"></i>项目能效
                </a>
            </div>
            <div class="flex flex-wrap gap-2 md:gap-3 items-center">
                <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-lg">
                    <button @click="setTimeRange('today')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange=='today'?'bg-white text-blue-700 shadow':'text-gray-500'">本日</button>
                    <button @click="setTimeRange('week')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange=='week'?'bg-white text-blue-700 shadow':'text-gray-500'">本周</button>
                    <button @click="setTimeRange('month')" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="timeRange=='month'?'bg-white text-blue-700 shadow':'text-gray-500'">本月</button>
                </div>
                <div class="flex items-center gap-2">
                    <input type="date" v-model="customStartDate" class="px-3 py-1.5 border rounded-md text-xs">
                    <input type="date" v-model="customEndDate" class="px-3 py-1.5 border rounded-md text-xs">
                    <button @click="applyCustomDate" class="px-3 py-1.5 bg-blue-600 text-white rounded-md text-xs font-bold">确定</button>
                </div>
                <button type="button" @click="loadData" class="text-gray-500 hover:text-indigo-600 px-2 py-2 text-sm transition" title="手动刷新">
                    <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                </button>
            </div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-4 md:p-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <div class="blue-header py-3 px-4 rounded-t-lg mb-2">经纪人数据（按业务员）</div>
                <div class="table-container">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">渠道名称</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">本月目标</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">截止本月28日</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备门店</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备带看率</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">人均带看</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">成交转化率</th>
                                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">成交套数</th>
                            </tr>
                        </thead>
                        <tbody class="bg-white divide-y divide-gray-200">
                            <tr v-for="(item, index) in channelData" :key="index" :class="item.name === '合计' ? 'total-row' : ''">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.name }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.target }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.actual }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.store_report }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.visit_rate }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.person_per_visit }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.conversion_rate }}</td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.deal_count }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const { createApp } = Vue;
createApp({
    data() {
        return {
            sidebarOpen: false,
            loading: false,
            channelData: [],
            timeRange: 'month',
            customStartDate: '',
            customEndDate: '',
        };
    },
    mounted() {
        this.setTimeRange('month');
        this.loadData();
    },
    methods: {
        setTimeRange(range) {
            this.timeRange = range;
            let startDate, endDate;
            const today = new Date();
            
            switch (range) {
                case 'today':
                    startDate = new Date(today);
                    endDate = new Date(today);
                    break;
                case 'week':
                    startDate = new Date(today);
                    startDate.setDate(today.getDate() - today.getDay());
                    endDate = new Date(today);
                    break;
                case 'month':
                    startDate = new Date(today.getFullYear(), today.getMonth(), 1);
                    endDate = new Date(today);
                    break;
                default:
                    return;
            }
            
            this.customStartDate = startDate.toISOString().split('T')[0];
            this.customEndDate = endDate.toISOString().split('T')[0];
            this.loadData();
        },
        applyCustomDate() {
            if (this.customStartDate && this.customEndDate) {
                this.timeRange = 'custom';
                this.loadData();
            }
        },
        async loadData() {
            this.loading = true;
            try {
                await this.loadChannelData();
            } catch (e) {
                console.error('加载数据失败:', e);
            } finally {
                this.loading = false;
            }
        },
        async loadChannelData() {
            try {
                const params = new URLSearchParams();
                if (this.customStartDate && this.customEndDate) {
                    params.append('start_date', this.customStartDate);
                    params.append('end_date', this.customEndDate);
                }
                const res = await fetch(`analytics.php?action=get_channel_data&${params.toString()}`);
                const data = await res.json();
                if (data.code === 0) {
                    this.channelData = data.data;
                }
            } catch (e) {
                console.error('加载渠道数据失败:', e);
            }
        },
    }
}).mount('#app');
</script>
</body>
</html>