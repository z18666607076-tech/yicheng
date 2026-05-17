<?php
// analytics.php - 数据分析页面（项目绩效；渠道效能见 channel_efficiency.php）
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

if ($action == 'get_project_data') {
    header('Content-Type: application/json');
    
    // 处理时间范围参数
    $start_date = $_GET['start_date'] ?? '';
    $end_date = $_GET['end_date'] ?? '';
    $time_filter = '';
    if ($start_date && $end_date) {
        $time_filter = "AND DATE(f.created_at) BETWEEN '$start_date' AND '$end_date'";
    }
    
    // 从数据库获取项目数据
    $sql = "SELECT 
        p.name as name,
        COUNT(f.id) as report_count,
        COUNT(DISTINCT f.company_name) as report_store,
        COUNT(DISTINCT f.broker_name) as report_broker,
        SUM(CASE WHEN f.status >= 2 THEN 1 ELSE 0 END) as visit_broker,
        SUM(CASE WHEN f.status = 4 THEN 1 ELSE 0 END) as deal_count,
        COUNT(DISTINCT CASE WHEN f.status >= 2 THEN f.id ELSE NULL END) as visit_group,
        COUNT(DISTINCT CASE WHEN f.status >= 2 THEN f.company_name ELSE NULL END) as visit_store,
        SUM(CASE WHEN f.room_number != '' THEN 1 ELSE 0 END) as lock_room
    FROM filings f
    LEFT JOIN projects p ON f.project_id = p.id
    WHERE p.name != '' $time_filter
    GROUP BY p.name 
    ORDER BY report_count DESC";
    
    $stmt = $pdo->query($sql);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 处理数据
    foreach ($data as &$item) {
        $item['store_per_report'] = $item['report_store'] > 0 ? number_format($item['report_count'] / $item['report_store'], 2) : 0;
        $item['visit_conversion'] = $item['report_count'] > 0 ? number_format(($item['visit_broker'] / $item['report_count']) * 100, 2) . '%' : '0.00%';
        $item['group_per_visit'] = $item['visit_broker'] > 0 ? number_format($item['visit_group'] / $item['visit_broker'], 2) : 0;
        $item['deal_conversion'] = $item['report_count'] > 0 ? number_format(($item['deal_count'] / $item['report_count']) * 100, 2) . '%' : '0.00%';
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
    <title>数据分析</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        .stat-card { transition: transform 0.2s; }
        .stat-card:hover { transform: translateY(-3px); }
        .tab-btn { transition: all 0.3s; }
        .tab-btn.active { background: #2563eb; color: white; }
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

        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">数据分析</h2>
            </div>
            <div class="flex gap-3">
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
                <button @click="loadData" class="text-gray-500 hover:text-indigo-600 px-3 py-2 text-sm transition" title="手动刷新">
                    <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            <!-- Tab 切换 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
                <div class="border-b border-gray-200 flex">
                    <a href="channel_efficiency.php" class="tab-btn px-6 py-4 text-sm font-medium text-slate-600 hover:bg-slate-50 inline-flex items-center">
                        <i class="fas fa-chart-bar mr-2"></i>渠道效能
                    </a>
                    <button type="button" @click="activeTab = 'project'" class="tab-btn px-6 py-4 text-sm font-medium" :class="{ 'active': activeTab === 'project' }">
                        <i class="fas fa-building mr-2"></i>项目绩效
                    </button>
                </div>
                
                <div class="p-6">
                    <!-- 项目分析表格 -->
                    <div v-show="activeTab === 'project'">
                        <div class="blue-header py-3 px-4 rounded-t-lg mb-2">项目数据</div>
                        <div class="table-container">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">项目名称</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备量</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备门店</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备经纪人数</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">店均报备</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">带看经纪人数</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">报备带看转化率</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">带看组数</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">人均带看组数</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">带看门店</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">成交数</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">成交转化率</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">锁房</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="(item, index) in projectData" :key="index">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.name }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.report_count }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.report_store }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.report_broker }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.store_per_report }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.visit_broker }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.visit_conversion }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.visit_group }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.group_per_visit }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.visit_store }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.deal_count }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.deal_conversion }}</td>
                                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ item.lock_room }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
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
            view: 'analytics',
            loading: false,
            activeTab: 'project',
            projectData: [],
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
                await this.loadProjectData();
            } catch (e) {
                console.error('加载数据失败:', e);
            } finally {
                this.loading = false;
            }
        },
        async loadProjectData() {
            try {
                const params = new URLSearchParams();
                if (this.customStartDate && this.customEndDate) {
                    params.append('start_date', this.customStartDate);
                    params.append('end_date', this.customEndDate);
                }
                const res = await fetch(`analytics.php?action=get_project_data&${params.toString()}`);
                const data = await res.json();
                if (data.code === 0) {
                    this.projectData = data.data;
                }
            } catch (e) {
                console.error('加载项目数据失败:', e);
            }
        },
    }
}).mount('#app');
</script>
</body>
</html>