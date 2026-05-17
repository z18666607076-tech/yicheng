<?php
// admin_statistics.php - 数据统计页面
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

// [API] 获取项目统计数据
if ($action == 'get_project_stats') {
    header('Content-Type: application/json');
    
    // 计算不同时间段的项目数据
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));
    
    // 今日数据 - 包含详细阶段
    $todaySql = "SELECT p.id, p.name as project_name, 
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
                 FROM projects p 
                 LEFT JOIN filings f ON p.id = f.project_id 
                 WHERE p.is_deleted = 0
                 GROUP BY p.id, p.name 
                 ORDER BY p.id";
    
    $stmt = $pdo->prepare($todaySql);
    $stmt->execute();
    $projectStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['code'=>0, 'data'=>$projectStats]); 
    exit;
}

// [API] 获取工作人员统计数据
if ($action == 'get_agent_stats') {
    header('Content-Type: application/json');
    
    // 计算不同时间段的工作人员数据
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));
    
    // 今日数据 - 包含详细阶段
    $todaySql = "SELECT COALESCE(f.broker_name, a.username) as agent_name, 
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
                 LEFT JOIN agents a ON f.agent_id = a.id 
                 GROUP BY agent_name 
                 ORDER BY today_count DESC, week_count DESC, month_count DESC";
    
    $stmt = $pdo->prepare($todaySql);
    $stmt->execute();
    $agentStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['code'=>0, 'data'=>$agentStats]); 
    exit;
}

// [API] 获取阶段统计数据
if ($action == 'get_stage_stats') {
    // 关闭错误直接输出，防止破坏 JSON
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    // 初始化默认数据，防止数据库缺字段导致前端报错
    $stageStats = [
        'status' => ['status_0'=>0, 'status_1'=>0, 'status_2'=>0, 'status_3'=>0, 'status_4'=>0, 'status_5'=>0],
        'commission' => ['commission_0'=>0, 'commission_1'=>0, 'commission_2'=>0],
        'visit_type' => ['visit_0'=>0, 'visit_1'=>0, 'visit_2'=>0, 'visit_3'=>0],
        'sub_stages' => ['deposit'=>0, 'lock'=>0, 'sign'=>0]
    ];

    try {
        // 1. 状态统计
        $statusSql = "SELECT COUNT(*) as total, status FROM filings GROUP BY status";
        $stmt = $pdo->prepare($statusSql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stageStats['status']['status_' . $r['status']] = intval($r['total']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }

    try {
        // 2. 佣金统计
        $commSql = "SELECT COUNT(*) as total, commission_status FROM filings GROUP BY commission_status";
        $stmt = $pdo->prepare($commSql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stageStats['commission']['commission_' . $r['commission_status']] = intval($r['total']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }

    try {
        // 3. 访问类型统计
        $visitSql = "SELECT COUNT(*) as total, visit_type FROM filings GROUP BY visit_type";
        $stmt = $pdo->prepare($visitSql);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $stageStats['visit_type']['visit_' . $r['visit_type']] = intval($r['total']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }

    try {
        // 4. 子阶段统计 (这里修复了 `lock` 关键字报错)
        // 注意：这里给 lock 加上了反引号 ``
        $subSql = "SELECT 
             SUM(CASE WHEN sub_stages LIKE '%deposit%' THEN 1 ELSE 0 END) as deposit,
             SUM(CASE WHEN sub_stages LIKE '%lock%' THEN 1 ELSE 0 END) as `lock`,
             SUM(CASE WHEN sub_stages LIKE '%sign%' THEN 1 ELSE 0 END) as sign
             FROM filings";
        $stmt = $pdo->prepare($subSql);
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $stageStats['sub_stages']['deposit'] = intval($res['deposit']);
            $stageStats['sub_stages']['lock'] = intval($res['lock']);
            $stageStats['sub_stages']['sign'] = intval($res['sign']);
        }
    } catch (Exception $e) { 
        // 即使这里报错（比如没有 sub_stages 字段），也不会让整个页面挂掉
    }
    
    echo json_encode(['code'=>0, 'data'=>$stageStats]); 
    exit;
}

// [API] 获取详细进度统计数据
if ($action == 'get_progress_stats') {
    // 关闭错误直接输出，防止破坏 JSON
    ini_set('display_errors', 0);
    header('Content-Type: application/json');
    
    // 计算不同时间段
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d', strtotime('-7 days'));
    $monthAgo = date('Y-m-d', strtotime('-30 days'));
    
    // 初始化默认数据
    $progressStats = [
        'today' => [
            'total' => 0,
            'filing' => 0, // 报备
            'contacted' => 0, // 已联系
            'visited' => 0, // 已带看
            'intent' => 0, // 意向客户
            'deal' => 0 // 已成交
        ],
        'week' => [
            'total' => 0,
            'filing' => 0,
            'contacted' => 0,
            'visited' => 0,
            'intent' => 0,
            'deal' => 0
        ],
        'month' => [
            'total' => 0,
            'filing' => 0,
            'contacted' => 0,
            'visited' => 0,
            'intent' => 0,
            'deal' => 0
        ]
    ];

    try {
        // 今日数据
        $todaySql = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status >= 0 THEN 1 END) as filing,
            COUNT(CASE WHEN status >= 1 THEN 1 END) as contacted,
            COUNT(CASE WHEN status >= 2 THEN 1 END) as visited,
            COUNT(CASE WHEN status >= 3 THEN 1 END) as intent,
            COUNT(CASE WHEN status >= 4 THEN 1 END) as deal
            FROM filings 
            WHERE DATE(created_at) = '$today'";
        $stmt = $pdo->prepare($todaySql);
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $progressStats['today']['total'] = intval($res['total']);
            $progressStats['today']['filing'] = intval($res['filing']);
            $progressStats['today']['contacted'] = intval($res['contacted']);
            $progressStats['today']['visited'] = intval($res['visited']);
            $progressStats['today']['intent'] = intval($res['intent']);
            $progressStats['today']['deal'] = intval($res['deal']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }

    try {
        // 7天数据
        $weekSql = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status >= 0 THEN 1 END) as filing,
            COUNT(CASE WHEN status >= 1 THEN 1 END) as contacted,
            COUNT(CASE WHEN status >= 2 THEN 1 END) as visited,
            COUNT(CASE WHEN status >= 3 THEN 1 END) as intent,
            COUNT(CASE WHEN status >= 4 THEN 1 END) as deal
            FROM filings 
            WHERE DATE(created_at) >= '$weekAgo'";
        $stmt = $pdo->prepare($weekSql);
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $progressStats['week']['total'] = intval($res['total']);
            $progressStats['week']['filing'] = intval($res['filing']);
            $progressStats['week']['contacted'] = intval($res['contacted']);
            $progressStats['week']['visited'] = intval($res['visited']);
            $progressStats['week']['intent'] = intval($res['intent']);
            $progressStats['week']['deal'] = intval($res['deal']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }

    try {
        // 30天数据
        $monthSql = "SELECT 
            COUNT(*) as total,
            COUNT(CASE WHEN status >= 0 THEN 1 END) as filing,
            COUNT(CASE WHEN status >= 1 THEN 1 END) as contacted,
            COUNT(CASE WHEN status >= 2 THEN 1 END) as visited,
            COUNT(CASE WHEN status >= 3 THEN 1 END) as intent,
            COUNT(CASE WHEN status >= 4 THEN 1 END) as deal
            FROM filings 
            WHERE DATE(created_at) >= '$monthAgo'";
        $stmt = $pdo->prepare($monthSql);
        $stmt->execute();
        $res = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($res) {
            $progressStats['month']['total'] = intval($res['total']);
            $progressStats['month']['filing'] = intval($res['filing']);
            $progressStats['month']['contacted'] = intval($res['contacted']);
            $progressStats['month']['visited'] = intval($res['visited']);
            $progressStats['month']['intent'] = intval($res['intent']);
            $progressStats['month']['deal'] = intval($res['deal']);
        }
    } catch (Exception $e) { /* 忽略错误 */ }
    
    echo json_encode(['code'=>0, 'data'=>$progressStats]); 
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据统计</title>
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
                <h2 class="text-lg font-bold text-slate-800">数据统计</h2>
                <a href="admin_statistics_companies.php" class="text-sm text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap">
                    <i class="fas fa-store mr-1"></i>商户统计
                </a>
            </div>
            <div class="flex gap-3">
                <button @click="loadData" class="text-gray-500 hover:text-indigo-600 px-3 py-2 text-sm transition" title="手动刷新">
                    <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                </button>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            <!-- 统计卡片 -->
            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
                <div class="bg-white p-6 rounded-xl shadow-sm border border-gray-100 stat-card">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-gray-500 text-sm">今日数据</p>
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
                            <p class="text-gray-500 text-sm">本周数据</p>
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
                            <p class="text-gray-500 text-sm">本月数据</p>
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
                            <p class="text-gray-500 text-sm">总计数据</p>
                            <p class="text-2xl font-bold text-gray-800 mt-1">{{ stats.total }}</p>
                        </div>
                        <div class="p-3 bg-yellow-100 rounded-lg">
                            <i class="fas fa-chart-line text-yellow-600 text-xl"></i>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 详细进度统计 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-8">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-bold text-gray-800">详细进度统计</h3>
                </div>
                
                <div class="p-6">
                    <!-- 今日进度 -->
                    <div class="mb-8">
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-calendar-day text-blue-500 mr-2"></i>今日进度
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">总报备</p>
                                <p class="text-2xl font-bold text-blue-800">{{ progressStats.today.filing || 0 }}</p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">已联系</p>
                                <p class="text-2xl font-bold text-green-800">{{ progressStats.today.contacted || 0 }}</p>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                <p class="text-sm text-yellow-600 mb-1">已带看</p>
                                <p class="text-2xl font-bold text-yellow-800">{{ progressStats.today.visited || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">意向客户</p>
                                <p class="text-2xl font-bold text-purple-800">{{ progressStats.today.intent || 0 }}</p>
                            </div>
                            <div class="bg-pink-50 p-4 rounded-lg border border-pink-100">
                                <p class="text-sm text-pink-600 mb-1">已成交</p>
                                <p class="text-2xl font-bold text-pink-800">{{ progressStats.today.deal || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 7天进度 -->
                    <div class="mb-8">
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-calendar-week text-green-500 mr-2"></i>7天进度
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">总报备</p>
                                <p class="text-2xl font-bold text-blue-800">{{ progressStats.week.filing || 0 }}</p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">已联系</p>
                                <p class="text-2xl font-bold text-green-800">{{ progressStats.week.contacted || 0 }}</p>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                <p class="text-sm text-yellow-600 mb-1">已带看</p>
                                <p class="text-2xl font-bold text-yellow-800">{{ progressStats.week.visited || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">意向客户</p>
                                <p class="text-2xl font-bold text-purple-800">{{ progressStats.week.intent || 0 }}</p>
                            </div>
                            <div class="bg-pink-50 p-4 rounded-lg border border-pink-100">
                                <p class="text-sm text-pink-600 mb-1">已成交</p>
                                <p class="text-2xl font-bold text-pink-800">{{ progressStats.week.deal || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 30天进度 -->
                    <div>
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-calendar text-purple-500 mr-2"></i>30天进度
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">总报备</p>
                                <p class="text-2xl font-bold text-blue-800">{{ progressStats.month.filing || 0 }}</p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">已联系</p>
                                <p class="text-2xl font-bold text-green-800">{{ progressStats.month.contacted || 0 }}</p>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                <p class="text-sm text-yellow-600 mb-1">已带看</p>
                                <p class="text-2xl font-bold text-yellow-800">{{ progressStats.month.visited || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">意向客户</p>
                                <p class="text-2xl font-bold text-purple-800">{{ progressStats.month.intent || 0 }}</p>
                            </div>
                            <div class="bg-pink-50 p-4 rounded-lg border border-pink-100">
                                <p class="text-sm text-pink-600 mb-1">已成交</p>
                                <p class="text-2xl font-bold text-pink-800">{{ progressStats.month.deal || 0 }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- 阶段统计卡片 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
                <div class="border-b border-gray-200 px-6 py-4">
                    <h3 class="text-lg font-bold text-gray-800">阶段统计</h3>
                </div>
                
                <div class="p-6">
                    <!-- 状态统计 -->
                    <div class="mb-8">
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-tasks text-blue-500 mr-2"></i>状态统计
                        </h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4">
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">待处理</p>
                                <p class="text-2xl font-bold text-blue-800">{{ stageStats.status.status_0 || 0 }}</p>
                            </div>
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">已联系</p>
                                <p class="text-2xl font-bold text-green-800">{{ stageStats.status.status_1 || 0 }}</p>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                <p class="text-sm text-yellow-600 mb-1">已带看</p>
                                <p class="text-2xl font-bold text-yellow-800">{{ stageStats.status.status_2 || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">意向客户</p>
                                <p class="text-2xl font-bold text-purple-800">{{ stageStats.status.status_3 || 0 }}</p>
                            </div>
                            <div class="bg-pink-50 p-4 rounded-lg border border-pink-100">
                                <p class="text-sm text-pink-600 mb-1">已成交</p>
                                <p class="text-2xl font-bold text-pink-800">{{ stageStats.status.status_4 || 0 }}</p>
                            </div>
                            <div class="bg-red-50 p-4 rounded-lg border border-red-100">
                                <p class="text-sm text-red-600 mb-1">已取消</p>
                                <p class="text-2xl font-bold text-red-800">{{ stageStats.status.status_5 || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 佣金状态统计 -->
                    <div class="mb-8">
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-dollar-sign text-green-500 mr-2"></i>佣金状态
                        </h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">待结算</p>
                                <p class="text-2xl font-bold text-green-800">{{ stageStats.commission.commission_0 || 0 }}</p>
                            </div>
                            <div class="bg-yellow-50 p-4 rounded-lg border border-yellow-100">
                                <p class="text-sm text-yellow-600 mb-1">结算中</p>
                                <p class="text-2xl font-bold text-yellow-800">{{ stageStats.commission.commission_1 || 0 }}</p>
                            </div>
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">已结算</p>
                                <p class="text-2xl font-bold text-blue-800">{{ stageStats.commission.commission_2 || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 访问类型统计 -->
                    <div class="mb-8">
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-user-friends text-orange-500 mr-2"></i>访问类型
                        </h4>
                        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                            <div class="bg-orange-50 p-4 rounded-lg border border-orange-100">
                                <p class="text-sm text-orange-600 mb-1">个人</p>
                                <p class="text-2xl font-bold text-orange-800">{{ stageStats.visit_type.visit_0 || 0 }}</p>
                            </div>
                            <div class="bg-teal-50 p-4 rounded-lg border border-teal-100">
                                <p class="text-sm text-teal-600 mb-1">团队</p>
                                <p class="text-2xl font-bold text-teal-800">{{ stageStats.visit_type.visit_1 || 0 }}</p>
                            </div>
                            <div class="bg-indigo-50 p-4 rounded-lg border border-indigo-100">
                                <p class="text-sm text-indigo-600 mb-1">VIP</p>
                                <p class="text-2xl font-bold text-indigo-800">{{ stageStats.visit_type.visit_2 || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">其他</p>
                                <p class="text-2xl font-bold text-purple-800">{{ stageStats.visit_type.visit_3 || 0 }}</p>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 子阶段统计 -->
                    <div>
                        <h4 class="text-md font-medium text-gray-700 mb-4 flex items-center">
                            <i class="fas fa-check-circle text-green-500 mr-2"></i>子阶段统计
                        </h4>
                        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
                            <div class="bg-green-50 p-4 rounded-lg border border-green-100">
                                <p class="text-sm text-green-600 mb-1">已交定金</p>
                                <p class="text-2xl font-bold text-green-800">{{ stageStats.sub_stages.deposit || 0 }}</p>
                            </div>
                            <div class="bg-blue-50 p-4 rounded-lg border border-blue-100">
                                <p class="text-sm text-blue-600 mb-1">已锁定</p>
                                <p class="text-2xl font-bold text-blue-800">{{ stageStats.sub_stages.lock || 0 }}</p>
                            </div>
                            <div class="bg-purple-50 p-4 rounded-lg border border-purple-100">
                                <p class="text-sm text-purple-600 mb-1">已签约</p>
                                <p class="text-2xl font-bold text-purple-800">{{ stageStats.sub_stages.sign || 0 }}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tab 切换 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
                <div class="border-b border-gray-200 flex">
                    <button type="button" @click="activeTab = 'projects'" class="tab-btn px-6 py-4 text-sm font-medium" :class="{ 'active': activeTab === 'projects' }">
                        <i class="fas fa-building mr-2"></i>项目统计
                    </button>
                    <button type="button" @click="activeTab = 'agents'" class="tab-btn px-6 py-4 text-sm font-medium" :class="{ 'active': activeTab === 'agents' }">
                        <i class="fas fa-users mr-2"></i>工作人员统计
                    </button>
                </div>
                
                <div class="p-6">
                    <!-- 项目统计表格 -->
                    <div v-show="activeTab === 'projects'">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">项目名称</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">今日数据</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">7天数据</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">30天数据</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="item in projectStats" :key="item.id">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.project_name }}</td>
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
                    
                    <!-- 工作人员统计表格 -->
                    <div v-show="activeTab === 'agents'">
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-gray-200">
                                <thead class="bg-gray-50">
                                    <tr>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">工作人员</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">今日数据</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">7天数据</th>
                                        <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">30天数据</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white divide-y divide-gray-200">
                                    <tr v-for="item in agentStats" :key="item.agent_name">
                                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ item.agent_name }}</td>
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
            </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref, reactive, onMounted } = Vue;

class ApiClient {
    static async getProjectStats() {
        try {
            const res = await fetch('./admin_statistics.php?action=get_project_stats');
            const text = await res.text();
            console.log('API response text (project):', text);
            return JSON.parse(text);
        } catch (error) {
            console.error('API request failed (project):', error);
            throw error;
        }
    }
    
    static async getAgentStats() {
        try {
            const res = await fetch('./admin_statistics.php?action=get_agent_stats');
            const text = await res.text();
            console.log('API response text (agent):', text);
            return JSON.parse(text);
        } catch (error) {
            console.error('API request failed (agent):', error);
            throw error;
        }
    }
    
    static async getStageStats() {
        try {
            const res = await fetch('./admin_statistics.php?action=get_stage_stats');
            const text = await res.text();
            console.log('API response text (stage):', text);
            return JSON.parse(text);
        } catch (error) {
            console.error('API request failed (stage):', error);
            throw error;
        }
    }
    
    static async getProgressStats() {
        try {
            const res = await fetch('./admin_statistics.php?action=get_progress_stats');
            const text = await res.text();
            console.log('API response text (progress):', text);
            return JSON.parse(text);
        } catch (error) {
            console.error('API request failed (progress):', error);
            throw error;
        }
    }
}

class StatsService {
    static calculateTotalStats(projectStats, agentStats) {
        let today = 0, week = 0, month = 0, total = 0;

        projectStats.forEach(item => {
            today += parseInt(item.today_count) || 0;
            week += parseInt(item.week_count) || 0;
            month += parseInt(item.month_count) || 0;
        });

        agentStats.forEach(item => {
            today += parseInt(item.today_count) || 0;
            week += parseInt(item.week_count) || 0;
            month += parseInt(item.month_count) || 0;
        });

        total = projectStats.length + agentStats.length;

        return { today, week, month, total };
    }
}

createApp({
    setup() {
        const loading = ref(false);
        const activeTab = ref('projects');
        const projectStats = ref([]);
        const agentStats = ref([]);
        const stageStats = ref({ status: {}, commission: {}, visit_type: {}, sub_stages: {} });
        const progressStats = ref({ today: {}, week: {}, month: {} });
        const stats = reactive({ today: 0, week: 0, month: 0, total: 0 });
        const view = ref('dashboard'); // 添加view属性，用于侧边栏高亮
        const sidebarOpen = ref(false); // 添加sidebarOpen属性，用于控制侧边栏显示/隐藏
        
        const loadData = async () => {
            loading.value = true;
            try {
                const [projectRes, agentRes, stageRes, progressRes] = await Promise.all([
                    ApiClient.getProjectStats(),
                    ApiClient.getAgentStats(),
                    ApiClient.getStageStats(),
                    ApiClient.getProgressStats()
                ]);
                
                projectStats.value = projectRes.data || [];
                agentStats.value = agentRes.data || [];
                stageStats.value = stageRes.data || { status: {}, commission: {}, visit_type: {}, sub_stages: {} };
                progressStats.value = progressRes.data || { today: {}, week: {}, month: {} };
                
                // 计算总统计
                Object.assign(stats, StatsService.calculateTotalStats(
                    projectStats.value,
                    agentStats.value
                ));
                
                // 调试信息
                console.log('API请求结果:', {
                    projectRes,
                    agentRes,
                    stageRes,
                    progressRes
                });
                console.log('处理后的数据:', {
                    projectStats: projectStats.value,
                    agentStats: agentStats.value,
                    stageStats: stageStats.value,
                    progressStats: progressStats.value
                });
            } catch (err) {
                console.error('加载数据失败:', err);
                console.error('错误详情:', err.stack);
            } finally {
                loading.value = false;
            }
        };
        
        onMounted(() => {
            loadData();
        });
        
        return {
            loading,
            activeTab,
            projectStats,
            agentStats,
            stageStats,
            progressStats,
            stats,
            view, // 添加view属性到返回对象
            sidebarOpen, // 添加sidebarOpen属性到返回对象
            loadData
        };
    }
}).mount('#app');
</script>
</body>
</html>