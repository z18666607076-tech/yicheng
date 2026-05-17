<?php
// agent_import.php - 经纪人数据导入与管理页面
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$CURRENT_USER = [
    'id' => (int)$_SESSION['admin_id'],
    'name' => $_SESSION['admin_name'] ?? '管理员',
    'phone' => $_SESSION['admin_phone'] ?? '',
    'company' => $_SESSION['agent_company'] ?? '',
    'role' => 'admin',
];

// === 1. 数据库连接 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

$action = $_GET['action'] ?? 'view';

// 创建经纪人数据表（如果不存在）
$createTableSql = "CREATE TABLE IF NOT EXISTS agents_data (
    id INT AUTO_INCREMENT PRIMARY KEY,
    company_name VARCHAR(255) NOT NULL,
    agent_name VARCHAR(100) NOT NULL,
    agent_phone VARCHAR(20) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_agent (agent_name, agent_phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
$pdo->exec($createTableSql);

// [API] 获取经纪人数据列表
if ($action == 'get_agents_list') {
    // 确保返回JSON格式
    header('Content-Type: application/json');
    
    try {
        $keyword = trim((string)($_GET['keyword'] ?? ''));
        $searchCompany = trim((string)($_GET['search_company'] ?? ''));
        $searchAgentName = trim((string)($_GET['search_agent_name'] ?? ''));
        $searchAgentPhone = trim((string)($_GET['search_agent_phone'] ?? ''));
        $searchChannelFollower = trim((string)($_GET['search_channel_follower'] ?? ''));
        $sortBy = $_GET['sort_by'] ?? 'id';
        $sortOrder = $_GET['sort_order'] ?? 'desc';
        $page = max(1, intval($_GET['page'] ?? 1));
        $pageSize = 20; // 固定每页 20 条
        $offset = ($page - 1) * $pageSize;
        
        // 验证排序字段和顺序
        $validSortFields = ['id', 'company_name', 'agent_name', 'agent_phone', 'total_filings'];
        $sortBy = in_array($sortBy, $validSortFields) ? $sortBy : 'id';
        $sortOrder = ($sortOrder == 'asc') ? 'asc' : 'desc';
        
        // 构建查询（agents_data 别名 ad；与商户 companies 按「所属公司」名称反查）
        $whereClause = 'WHERE 1=1';
        $params = [];

        $hasStructured = ($searchCompany !== '' || $searchAgentName !== '' || $searchAgentPhone !== '' || $searchChannelFollower !== '');
        if ($hasStructured) {
            if ($searchCompany !== '') {
                $whereClause .= ' AND ad.company_name LIKE ?';
                $params[] = '%' . $searchCompany . '%';
            }
            if ($searchAgentName !== '') {
                $whereClause .= ' AND ad.agent_name LIKE ?';
                $params[] = '%' . $searchAgentName . '%';
            }
            if ($searchAgentPhone !== '') {
                $whereClause .= ' AND ad.agent_phone LIKE ?';
                $params[] = '%' . $searchAgentPhone . '%';
            }
            if ($searchChannelFollower !== '') {
                $whereClause .= ' AND COALESCE(c.follower, \'\') LIKE ?';
                $params[] = '%' . $searchChannelFollower . '%';
            }
        } elseif ($keyword !== '') {
            $whereClause .= ' AND (ad.company_name LIKE ? OR ad.agent_name LIKE ? OR ad.agent_phone LIKE ?)';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
            $params[] = '%' . $keyword . '%';
        }

        $countSql = "SELECT COUNT(*) FROM agents_data ad $whereClause";
        $countStmt = $pdo->prepare($countSql);
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        $orderMap = [
            'id' => 'ad.id',
            'company_name' => 'ad.company_name',
            'agent_name' => 'ad.agent_name',
            'agent_phone' => 'ad.agent_phone',
            'total_filings' => 'total_filings',
        ];
        $orderExpr = $orderMap[$sortBy] ?? 'ad.id';

        $dataSql = "SELECT ad.*,
            (SELECT COUNT(*) FROM filings f WHERE (
                (f.broker_name COLLATE utf8mb4_unicode_ci) = ad.agent_name
                OR (f.broker_phone COLLATE utf8mb4_unicode_ci) = ad.agent_phone
            )) AS total_filings,
            c.id AS company_id,
            c.address AS company_address,
            c.franchise_brand AS company_franchise,
            c.store_name AS company_short_name,
            NULLIF(TRIM(c.follower), '') AS company_follower,
            NULLIF(TRIM(CONCAT_WS(' · ',
                NULLIF(TRIM(c.business_status), ''),
                NULLIF(TRIM(c.related_store), '')
            )), '') AS company_remark
            FROM agents_data ad
            LEFT JOIN companies c ON c.id = (
                SELECT MAX(c2.id) FROM companies c2
                WHERE (TRIM(c2.name) COLLATE utf8mb4_unicode_ci) = (TRIM(ad.company_name) COLLATE utf8mb4_unicode_ci)
            )
            $whereClause
            ORDER BY $orderExpr $sortOrder
            LIMIT ? OFFSET ?";
        $dataStmt = $pdo->prepare($dataSql);

        foreach ($params as $i => $param) {
            $dataStmt->bindValue($i + 1, $param, PDO::PARAM_STR);
        }
        $dataStmt->bindValue(count($params) + 1, $pageSize, PDO::PARAM_INT);
        $dataStmt->bindValue(count($params) + 2, $offset, PDO::PARAM_INT);

        $dataStmt->execute();
        $data = $dataStmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 返回带分页的数据
        echo json_encode([
            'data' => $data,
            'total' => $total,
            'page' => $page,
            'pageSize' => $pageSize
        ]);
    } catch (Exception $e) {
        error_log('agent_import get_agents_list: ' . $e->getMessage());
        echo json_encode(['data' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20]);
    } catch (Error $e) {
        error_log('agent_import get_agents_list: ' . $e->getMessage());
        echo json_encode(['data' => [], 'total' => 0, 'page' => 1, 'pageSize' => 20]);
    }
    exit;
}

// [API] 删除经纪人数据
if ($action == 'delete_agent') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '数据ID不能为空']);
        exit;
    }
    
    try {
        $sql = "DELETE FROM agents_data WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
        echo json_encode(['status' => 'success', 'msg' => '数据删除成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '删除失败']);
    }
    exit;
}

// [API] 编辑经纪人数据（agents_data 本表字段）
if ($action == 'update_agent') {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_POST['id'] ?? 0);
    $company_name = trim((string)($_POST['company_name'] ?? ''));
    $agent_name = trim((string)($_POST['agent_name'] ?? ''));
    $agent_phone = trim((string)($_POST['agent_phone'] ?? ''));

    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'msg' => '无效的数据ID']);
        exit;
    }
    if ($company_name === '' || $agent_name === '' || $agent_phone === '') {
        echo json_encode(['status' => 'error', 'msg' => '经纪公司、经纪人姓名、号码均不能为空']);
        exit;
    }

    $dup = $pdo->prepare('SELECT id FROM agents_data WHERE agent_name = ? AND agent_phone = ? AND id != ? LIMIT 1');
    $dup->execute([$agent_name, $agent_phone, $id]);
    if ($dup->fetchColumn()) {
        echo json_encode(['status' => 'error', 'msg' => '已存在相同的「姓名+号码」组合，请修改后保存']);
        exit;
    }

    try {
        $stmt = $pdo->prepare('UPDATE agents_data SET company_name = ?, agent_name = ?, agent_phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $stmt->execute([$company_name, $agent_name, $agent_phone, $id]);
        if ($stmt->rowCount() === 0) {
            $exists = $pdo->prepare('SELECT 1 FROM agents_data WHERE id = ?');
            $exists->execute([$id]);
            if (!$exists->fetchColumn()) {
                echo json_encode(['status' => 'error', 'msg' => '记录不存在']);
                exit;
            }
        }
        echo json_encode(['status' => 'success', 'msg' => '保存成功']);
    } catch (PDOException $e) {
        if ((int)$e->getCode() === 23000) {
            echo json_encode(['status' => 'error', 'msg' => '与已有数据冲突（姓名+号码唯一）']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => '保存失败']);
        }
    }
    exit;
}

// [API] 导入Excel数据
if ($action == 'import_excel') {
    header('Content-Type: application/json');
    
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'msg' => '文件上传失败']);
        exit;
    }
    
    $file = $_FILES['file']['tmp_name'];
    $fileExtension = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
    
    if ($fileExtension !== 'csv') {
        echo json_encode(['status' => 'error', 'msg' => '只支持CSV格式的文件，请将Excel文件另存为CSV格式后导入']);
        exit;
    }
    
    try {
        // 处理CSV格式的文件
        $handle = fopen($file, 'r');
        if (!$handle) {
            echo json_encode(['status' => 'error', 'msg' => '文件打开失败']);
            exit;
        }
        
        $importCount = 0;
        $skipCount = 0;
        
        // 跳过表头
        fgetcsv($handle);
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            if (count($data) >= 3) {
                $companyName = trim($data[0]);
                $agentName = trim($data[1]);
                $agentPhone = trim($data[2]);
                
                if (!empty($companyName) && !empty($agentName) && !empty($agentPhone)) {
                    try {
                        $sql = "INSERT IGNORE INTO agents_data (company_name, agent_name, agent_phone) VALUES (?, ?, ?)";
                        $pdo->prepare($sql)->execute([$companyName, $agentName, $agentPhone]);
                        $importCount++;
                    } catch (Exception $e) {
                        $skipCount++;
                    }
                } else {
                    $skipCount++;
                }
            } else {
                $skipCount++;
            }
        }
        
        fclose($handle);
        
        echo json_encode(['status' => 'success', 'msg' => "导入成功：$importCount 条数据，跳过：$skipCount 条数据"]);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '导入失败：' . $e->getMessage()]);
    } catch (Error $e) {
        echo json_encode(['status' => 'error', 'msg' => '导入失败：' . $e->getMessage()]);
    }
    exit;
}

// [API] 导出经纪人数据
if ($action == 'export_agents') {
    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $searchCompany = trim((string)($_GET['search_company'] ?? ''));
    $searchAgentName = trim((string)($_GET['search_agent_name'] ?? ''));
    $searchAgentPhone = trim((string)($_GET['search_agent_phone'] ?? ''));
    $searchChannelFollower = trim((string)($_GET['search_channel_follower'] ?? ''));

    $whereClause = 'WHERE 1=1';
    $params = [];
    $hasStructured = ($searchCompany !== '' || $searchAgentName !== '' || $searchAgentPhone !== '' || $searchChannelFollower !== '');
    if ($hasStructured) {
        if ($searchCompany !== '') {
            $whereClause .= ' AND ad.company_name LIKE ?';
            $params[] = '%' . $searchCompany . '%';
        }
        if ($searchAgentName !== '') {
            $whereClause .= ' AND ad.agent_name LIKE ?';
            $params[] = '%' . $searchAgentName . '%';
        }
        if ($searchAgentPhone !== '') {
            $whereClause .= ' AND ad.agent_phone LIKE ?';
            $params[] = '%' . $searchAgentPhone . '%';
        }
        if ($searchChannelFollower !== '') {
            $whereClause .= ' AND COALESCE(c.follower, \'\') LIKE ?';
            $params[] = '%' . $searchChannelFollower . '%';
        }
    } elseif ($keyword !== '') {
        $whereClause .= ' AND (ad.company_name LIKE ? OR ad.agent_name LIKE ? OR ad.agent_phone LIKE ?)';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
        $params[] = '%' . $keyword . '%';
    }

    $sql = "SELECT ad.company_name, ad.agent_name, ad.agent_phone
            FROM agents_data ad
            LEFT JOIN companies c ON c.id = (
                SELECT MAX(c2.id) FROM companies c2
                WHERE (TRIM(c2.name) COLLATE utf8mb4_unicode_ci) = (TRIM(ad.company_name) COLLATE utf8mb4_unicode_ci)
            )
            $whereClause
            ORDER BY ad.id DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 生成CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=agents_data_' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['经纪公司', '经纪人', '经纪人号码']);
    
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>经纪人管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .glass-nav { background: rgba(255,255,255,0.98); border-top: 1px solid #f1f5f9; padding-bottom: env(safe-area-inset-bottom); }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
        .input-group { width: 100%; background-color: #f9fafb; padding: 0.75rem; border-radius: 0.75rem; border: 1px solid #e5e7eb; font-size: 0.875rem; outline: none; transition: all 0.2s; }
        .input-group:focus { border-color: #3b82f6; ring: 2px solid #93c5fd; }
        .label-text { font-size: 0.75rem; font-weight: bold; color: #6b7280; margin-bottom: 0.25rem; display: block; margin-left: 0.25rem; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: flex-end; justify-content: center; }
        .modal-content { background: white; width: 100%; max-width: 480px; border-radius: 20px 20px 0 0; padding: 20px; max-height: 80vh; overflow-y: auto; animation: slideUp 0.3s ease-out; position: relative; }
        .close-btn { position: absolute; top: 15px; right: 15px; color: #94a3b8; font-size: 20px; cursor: pointer; padding: 5px; }
        .filter-input { width: 100%; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.5rem; font-size: 0.75rem; outline: none; }
        .filter-label { font-size: 10px; font-weight: bold; color: #9ca3af; margin-bottom: 4px; display: block; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .btn-purple { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .btn-green { background: linear-gradient(135deg, #10b981 0%, #059669 100%); }
        .btn-blue { background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); }
        .btn-red { background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%); }
        .btn-gray { background: linear-gradient(135deg, #9ca3af 0%, #6b7280 100%); }
        .btn-hover:hover { transform: translateY(-1px); box-shadow: 0 6px 24px -4px rgba(0,0,0,0.15); }
        .table-hover tr:hover { background-color: #f5f3ff !important; }
        .checkbox-custom { accent-color: #8b5cf6; }
        .input-focus:focus { border-color: #8b5cf6; box-shadow: 0 0 0 3px rgba(139, 92, 246, 0.1); }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="bg-white border-b border-gray-200 shadow-sm z-10 flex-shrink-0 flex flex-col gap-3 py-3 px-4 md:px-8">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-3 min-w-0 shrink-0">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 text-gray-500"><i class="fas fa-bars"></i></button>
                    <h2 class="text-lg font-bold text-slate-800 truncate">经纪人管理</h2>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold text-sm">A</div>
                    <span class="text-sm font-bold text-slate-700 hidden sm:inline">{{ userInfo.name }}</span>
                </div>
            </div>
            <div class="w-full space-y-2 border-t border-gray-100 pt-3">
                <div class="flex flex-wrap items-end gap-3 gap-y-2 w-full">
                    <div class="flex flex-wrap items-end gap-3 gap-y-2 flex-1 min-w-0">
                        <div class="flex flex-col gap-1 min-w-[120px] flex-1 sm:max-w-[200px]">
                            <label class="text-xs font-bold text-gray-500">经纪公司</label>
                            <input v-model="filterCompany" @input="onSearchDebounced" @keyup.enter="searchNow" type="text" placeholder="公司名称" class="w-full px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm text-slate-800 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100">
                        </div>
                        <div class="flex flex-col gap-1 min-w-[120px] flex-1 sm:max-w-[200px]">
                            <label class="text-xs font-bold text-gray-500">经纪人姓名</label>
                            <input v-model="filterAgentName" @input="onSearchDebounced" @keyup.enter="searchNow" type="text" placeholder="姓名" class="w-full px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm text-slate-800 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100">
                        </div>
                        <div class="flex flex-col gap-1 min-w-[120px] flex-1 sm:max-w-[200px]">
                            <label class="text-xs font-bold text-gray-500">经纪人号码</label>
                            <input v-model="filterAgentPhone" @input="onSearchDebounced" @keyup.enter="searchNow" type="text" placeholder="手机号" class="w-full px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm text-slate-800 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100">
                        </div>
                        <div class="flex flex-col gap-1 min-w-[120px] flex-1 sm:max-w-[200px]">
                            <label class="text-xs font-bold text-gray-500">渠道跟进人</label>
                            <input v-model="filterChannelFollower" @input="onSearchDebounced" @keyup.enter="searchNow" type="text" placeholder="商户跟进人" class="w-full px-3 py-2 rounded-lg border border-gray-200 bg-white text-sm text-slate-800 outline-none focus:border-violet-400 focus:ring-2 focus:ring-violet-100">
                        </div>
                    </div>
                    <div class="flex flex-wrap items-end justify-end gap-2 shrink-0 w-full sm:w-auto sm:ml-auto">
                        <input type="file" ref="fileInput" accept=".csv" @change="handleFileUpload" class="hidden">
                        <button type="button" @click="searchNow" class="shrink-0 btn-purple text-white py-2 px-5 rounded-xl text-sm font-bold shadow-sm btn-hover">
                            搜索
                        </button>
                        <button type="button" @click="$refs.fileInput.click()" class="shrink-0 btn-purple text-white py-2 px-4 rounded-xl text-sm font-bold shadow-sm btn-hover flex items-center gap-2">
                            <i class="fas fa-upload"></i> 导入Excel
                        </button>
                        <button type="button" @click="exportData" class="shrink-0 btn-green text-white py-2 px-4 rounded-xl text-sm font-bold shadow-sm btn-hover flex items-center gap-2">
                            <i class="fas fa-download"></i> 导出
                        </button>
                    </div>
                </div>
                <div v-if="importStatus" :class="importStatus.status === 'success' ? 'text-green-600' : importStatus.status === 'loading' ? 'text-violet-600' : 'text-red-600'" class="text-xs sm:text-sm pl-0.5">
                    {{ importStatus.msg }}
                </div>
            </div>
        </header>

        <div class="flex-1 flex flex-col min-h-0 overflow-hidden p-4 md:p-8 gap-4 md:gap-6">
        <!-- 数据列表：表格区域滚动，分页条吸底固定在主内容可视区底部 -->
        <div class="flex flex-1 flex-col min-h-0 bg-white rounded-2xl card-shadow overflow-hidden fade-in">
            <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto">
                <table class="w-full text-sm text-left text-gray-600">
                    <thead class="bg-gray-50 text-[11px] sm:text-xs font-bold text-gray-700 whitespace-nowrap">
                        <tr>
                            <th class="px-2 py-3 w-10 text-center align-middle">
                                <input type="checkbox" class="checkbox-custom align-middle" title="全选" :checked="allRowsSelected" @change="onToggleAll($event)">
                            </th>
                            <th class="px-2 py-3">跟进渠道</th>
                            <th class="px-2 py-3">
                                <div class="flex items-center gap-1">
                                    <span>经纪人姓名</span>
                                    <button type="button" @click="sortBy('agent_name')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-sort"></i></button>
                                </div>
                            </th>
                            <th class="px-2 py-3">经纪人号码</th>
                            <th class="px-2 py-3">
                                <div class="flex items-center gap-1">
                                    <span>所属公司</span>
                                    <button type="button" @click="sortBy('company_name')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-sort"></i></button>
                                </div>
                            </th>
                            <th class="px-2 py-3">地址</th>
                            <th class="px-2 py-3">加盟</th>
                            <th class="px-2 py-3">备注</th>
                            <th class="px-2 py-3">公司简称</th>
                            <th class="px-2 py-3">
                                <div class="flex items-center gap-1">
                                    <span>历史门店</span>
                                    <button type="button" @click="sortBy('total_filings')" class="text-gray-400 hover:text-gray-600"><i class="fas fa-sort"></i></button>
                                </div>
                            </th>
                            <th class="px-3 py-3 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="table-hover">
                        <tr v-if="agentsData.length === 0" class="text-center">
                            <td colspan="11" class="py-12 text-gray-400">
                                <div class="flex flex-col items-center justify-center">
                                    <i class="fas fa-user-friends text-4xl text-gray-300 mb-4"></i>
                                    <p class="text-base">暂无数据</p>
                                    <p class="text-sm text-gray-400 mt-2">请先导入经纪人数据</p>
                                </div>
                            </td>
                        </tr>
                        <tr v-for="item in agentsData" :key="item.id" class="hover:bg-slate-50 border-b border-gray-100 text-xs sm:text-sm">
                            <td class="px-2 py-2 text-center">
                                <input type="checkbox" class="checkbox-custom" :checked="selectedIds.includes(item.id)" @change="onToggleRow(item.id, $event.target.checked)">
                            </td>
                            <td class="px-2 py-2 text-slate-700 max-w-[7rem] truncate" :title="item.company_id ? (String(item.company_follower || '').trim() || '公池') : ''">{{ item.company_id ? (String(item.company_follower || '').trim() || '公池') : '—' }}</td>
                            <td class="px-2 py-2 font-medium text-slate-800">{{ item.agent_name }}</td>
                            <td class="px-2 py-2 font-mono text-blue-600 font-semibold">{{ item.agent_phone }}</td>
                            <td class="px-2 py-2 font-semibold text-slate-800">{{ item.company_name }}</td>
                            <td class="px-2 py-2 text-slate-600 max-w-[10rem] truncate" :title="item.company_address || ''">{{ item.company_address || '—' }}</td>
                            <td class="px-2 py-2 text-slate-600 max-w-[6rem] truncate" :title="item.company_franchise || ''">{{ item.company_franchise || '—' }}</td>
                            <td class="px-2 py-2 text-slate-600 max-w-[10rem] truncate" :title="item.company_remark || ''">{{ item.company_remark || '—' }}</td>
                            <td class="px-2 py-2 text-slate-600 max-w-[8rem] truncate" :title="item.company_short_name || ''">{{ item.company_short_name || '—' }}</td>
                            <td class="px-2 py-2 font-bold text-emerald-600 text-center">{{ item.total_filings || 0 }}</td>
                            <td class="px-3 py-2 text-right">
                                <div class="flex flex-col gap-1 items-end">
                                    <button type="button" @click="openEdit(item)" class="bg-violet-50 text-violet-700 py-1.5 px-3 rounded-lg font-bold shadow-sm text-xs hover:bg-violet-100 transition flex items-center gap-1">
                                        <i class="fas fa-pen"></i> 编辑
                                    </button>
                                    <button type="button" @click="deleteAgent(item.id)" class="bg-red-50 text-red-600 py-1.5 px-3 rounded-lg font-bold shadow-sm text-xs hover:bg-red-100 transition flex items-center gap-1">
                                        <i class="fas fa-trash"></i> 删除
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <!-- 分页控件：固定在主内容底部，无需滚到页面最末端 -->
            <div v-if="total > 0" class="shrink-0 z-10 px-4 py-3 md:py-4 bg-gray-50 border-t border-gray-200 shadow-[0_-4px_14px_-4px_rgba(15,23,42,0.08)] flex flex-col gap-3">
                <div class="flex flex-wrap items-center gap-3 sm:gap-4">
                    <span class="text-xs text-gray-600">共 <strong class="text-slate-700">{{ total }}</strong> 条</span>
                    <span class="text-xs text-gray-500">每页 <strong class="text-slate-700">20</strong> 条</span>
                    <span class="text-xs text-gray-500 tabular-nums sm:ml-auto">第 {{ currentPage }} / {{ totalPages }} 页</span>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-2 sm:gap-1">
                    <button type="button" @click="changePage(currentPage - 1)" :disabled="currentPage === 1" class="text-sm px-3 py-2 sm:px-4 border border-gray-300 rounded-lg bg-white font-medium text-slate-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed shrink-0">
                        上一页
                    </button>
                    <div class="flex flex-wrap items-center justify-center gap-1 max-w-full">
                        <template v-for="(item, idx) in paginationItems" :key="'pg-' + idx + '-' + (item.type === 'page' ? item.n : 'e')">
                            <span v-if="item.type === 'dots'" class="px-1 text-gray-400 select-none text-sm">…</span>
                            <button
                                v-else
                                type="button"
                                @click="changePage(item.n)"
                                class="min-w-[2.25rem] h-9 px-2 rounded-lg border text-sm font-semibold tabular-nums transition shrink-0"
                                :class="currentPage === item.n ? 'border-violet-600 bg-violet-600 text-white shadow-sm' : 'border-gray-300 bg-white text-slate-700 hover:bg-violet-50 hover:border-violet-300'"
                            >{{ item.n }}</button>
                        </template>
                    </div>
                    <div class="flex items-center gap-1.5 shrink-0">
                        <span class="text-xs text-gray-600 whitespace-nowrap">跳转到</span>
                        <input
                            v-model="jumpPageInput"
                            type="text"
                            inputmode="numeric"
                            pattern="[0-9]*"
                            maxlength="8"
                            class="w-14 sm:w-16 px-2 py-2 border border-gray-300 rounded-lg bg-white text-sm text-center tabular-nums text-slate-800 focus:outline-none focus:ring-2 focus:ring-violet-400 focus:border-violet-400"
                            placeholder="页"
                            aria-label="页码"
                            @keyup.enter="jumpToPage"
                        />
                        <span class="text-xs text-gray-500">页</span>
                        <button type="button" @click="jumpToPage" class="text-sm px-3 py-2 border border-violet-300 rounded-lg bg-violet-50 font-semibold text-violet-700 hover:bg-violet-100 shrink-0">
                            跳转
                        </button>
                    </div>
                    <button type="button" @click="changePage(currentPage + 1)" :disabled="currentPage >= totalPages" class="text-sm px-3 py-2 sm:px-4 border border-gray-300 rounded-lg bg-white font-medium text-slate-700 hover:bg-gray-50 disabled:opacity-50 disabled:cursor-not-allowed shrink-0">
                        下一页
                    </button>
                </div>
            </div>
        </div>
        
        <!-- 编辑经纪人 -->
        <div v-if="showEditModal" class="fixed inset-0 bg-black/50 flex items-center justify-center z-50 p-4" @click.self="showEditModal = false">
            <div class="bg-white rounded-2xl card-shadow w-full max-w-md max-h-[90vh] overflow-y-auto">
                <div class="px-5 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="text-lg font-bold text-slate-800">编辑经纪人</h3>
                    <button type="button" @click="showEditModal = false" class="text-gray-400 hover:text-gray-600 p-1"><i class="fas fa-times text-lg"></i></button>
                </div>
                <form @submit.prevent="saveEdit" class="p-5 space-y-4">
                    <div>
                        <label class="label-text">所属公司（经纪公司）</label>
                        <input v-model="editForm.company_name" type="text" required class="w-full input-group input-focus" placeholder="公司名称">
                    </div>
                    <div>
                        <label class="label-text">经纪人姓名</label>
                        <input v-model="editForm.agent_name" type="text" required class="w-full input-group input-focus" placeholder="姓名">
                    </div>
                    <div>
                        <label class="label-text">经纪人号码</label>
                        <input v-model="editForm.agent_phone" type="text" required class="w-full input-group input-focus" placeholder="手机号">
                    </div>
                    <p v-if="editError" class="text-sm text-red-600">{{ editError }}</p>
                    <div class="flex gap-3 pt-2">
                        <button type="button" @click="showEditModal = false" class="flex-1 py-3 rounded-xl font-bold text-sm bg-gray-100 text-gray-700 hover:bg-gray-200">取消</button>
                        <button type="submit" :disabled="editSaving" class="flex-1 py-3 rounded-xl font-bold text-sm btn-purple text-white btn-hover disabled:opacity-50">
                            {{ editSaving ? '保存中…' : '保存' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- 导入提示弹窗 -->
        <div v-if="showImportModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
            <div class="bg-white rounded-2xl p-6 w-full max-w-md mx-4 card-shadow">
                <div class="w-12 h-2 bg-gray-200 rounded-full mx-auto mb-6"></div>
                <div class="flex justify-between items-center mb-6">
                    <h3 class="text-xl font-bold text-slate-800">导入提示</h3>
                    <button @click="showImportModal = false" class="text-gray-400 hover:text-gray-600 transition">
                        <i class="fas fa-times text-lg"></i>
                    </button>
                </div>
                <div class="space-y-5">
                    <div class="bg-blue-50 p-4 rounded-xl">
                        <h4 class="font-bold text-sm text-blue-700 mb-2">导入说明</h4>
                        <ul class="text-sm text-blue-600 space-y-2">
                            <li>1. 请确保Excel文件包含以下三列：</li>
                            <li>   - 第一列：经纪公司</li>
                            <li>   - 第二列：经纪人</li>
                            <li>   - 第三列：经纪人号码</li>
                            <li>2. 请将Excel文件另存为CSV格式后导入</li>
                            <li>3. 系统会自动跳过重复的数据</li>
                        </ul>
                    </div>
                    <div class="flex gap-4">
                        <button @click="showImportModal = false" class="flex-1 bg-gray-100 text-gray-800 py-3 rounded-xl font-bold shadow-sm text-sm hover:bg-gray-200 transition">
                            取消
                        </button>
                        <button @click="$refs.fileInput.click(); showImportModal = false" class="flex-1 btn-blue text-white py-3 rounded-xl font-bold shadow-lg text-sm btn-hover">
                            选择文件
                        </button>
                    </div>
                </div>
            </div>
        </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref, onMounted, computed, watch } = Vue;
createApp({
    setup() {
        const filterCompany = ref('');
        const filterAgentName = ref('');
        const filterAgentPhone = ref('');
        const filterChannelFollower = ref('');
        const agentsData = ref([]);
        const importStatus = ref(null);
        const showImportModal = ref(false);
        const userInfo = ref(<?php echo json_encode(['name' => $CURRENT_USER['name'] ?? '管理员'], JSON_UNESCAPED_UNICODE); ?>);
        const sidebarOpen = ref(true);
        const selectedIds = ref([]);
        const allRowsSelected = computed(() => {
            const list = agentsData.value;
            return list.length > 0 && list.every((a) => selectedIds.value.includes(a.id));
        });
        const onToggleAll = (e) => {
            if (e.target.checked) {
                selectedIds.value = agentsData.value.map((a) => a.id);
            } else {
                selectedIds.value = [];
            }
        };
        const onToggleRow = (id, on) => {
            if (on) {
                if (!selectedIds.value.includes(id)) {
                    selectedIds.value = [...selectedIds.value, id];
                }
            } else {
                selectedIds.value = selectedIds.value.filter((x) => x !== id);
            }
        };
        const view = ref('agent_import');
        const pageTitle = ref('经纪人数据管理');
        const sortByField = ref('id');
        const sortOrder = ref('desc');
        const PAGE_SIZE = 20;
        const currentPage = ref(1);
        const total = ref(0);
        const totalPages = ref(0);
        const jumpPageInput = ref('1');
        watch(currentPage, (p) => {
            jumpPageInput.value = String(p);
        }, { immediate: true });

        /** 可点击页码：总页数少时全列，多时用省略号 */
        const paginationItems = computed(() => {
            const tp = totalPages.value;
            const cp = currentPage.value;
            if (tp < 1) return [];
            if (tp <= 9) {
                return Array.from({ length: tp }, (_, i) => ({ type: 'page', n: i + 1 }));
            }
            const set = new Set([1, tp]);
            for (let i = cp - 2; i <= cp + 2; i++) {
                if (i >= 1 && i <= tp) set.add(i);
            }
            const sorted = [...set].sort((a, b) => a - b);
            const out = [];
            for (let i = 0; i < sorted.length; i++) {
                if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                    out.push({ type: 'dots' });
                }
                out.push({ type: 'page', n: sorted[i] });
            }
            return out;
        });

        let searchDebounceTimer = null;
        const onSearchDebounced = () => {
            clearTimeout(searchDebounceTimer);
            searchDebounceTimer = setTimeout(() => {
                currentPage.value = 1;
                loadData();
            }, 400);
        };
        const searchNow = () => {
            clearTimeout(searchDebounceTimer);
            currentPage.value = 1;
            loadData();
        };

        const showEditModal = ref(false);
        const editForm = ref({ id: null, company_name: '', agent_name: '', agent_phone: '' });
        const editError = ref('');
        const editSaving = ref(false);
        const openEdit = (item) => {
            editForm.value = {
                id: item.id,
                company_name: item.company_name || '',
                agent_name: item.agent_name || '',
                agent_phone: item.agent_phone || ''
            };
            editError.value = '';
            showEditModal.value = true;
        };
        const saveEdit = async () => {
            editSaving.value = true;
            editError.value = '';
            try {
                const body = new URLSearchParams();
                body.set('id', String(editForm.value.id));
                body.set('company_name', (editForm.value.company_name || '').trim());
                body.set('agent_name', (editForm.value.agent_name || '').trim());
                body.set('agent_phone', (editForm.value.agent_phone || '').trim());
                const res = await fetch('?action=update_agent', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString()
                });
                const data = await res.json();
                if (data.status === 'success') {
                    showEditModal.value = false;
                    await loadData();
                } else {
                    editError.value = data.msg || '保存失败';
                }
            } catch (e) {
                editError.value = '网络错误，请重试';
            } finally {
                editSaving.value = false;
            }
        };
        
        // 加载数据
        const loadData = async () => {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('action', 'get_agents_list');
                url.searchParams.set('search_company', filterCompany.value);
                url.searchParams.set('search_agent_name', filterAgentName.value);
                url.searchParams.set('search_agent_phone', filterAgentPhone.value);
                url.searchParams.set('search_channel_follower', filterChannelFollower.value);
                url.searchParams.set('sort_by', sortByField.value);
                url.searchParams.set('sort_order', sortOrder.value);
                url.searchParams.set('page', String(currentPage.value));
                url.searchParams.set('pageSize', String(PAGE_SIZE));
                
                const res = await fetch(url.toString());
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const response = await res.json();
                agentsData.value = response.data || [];
                total.value = response.total || 0;
                totalPages.value = total.value > 0 ? Math.max(1, Math.ceil(total.value / PAGE_SIZE)) : 0;
                selectedIds.value = [];
            } catch (error) {
                console.error('加载数据失败:', error);
                agentsData.value = [];
                total.value = 0;
                totalPages.value = 0;
            }
        };
        
        // 排序方法
        const sortBy = (field) => {
            if (sortByField.value === field) {
                // 如果点击的是当前排序字段，则切换排序顺序
                sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc';
            } else {
                // 否则，设置新的排序字段和默认排序顺序
                sortByField.value = field;
                sortOrder.value = 'desc';
            }
            // 重新加载数据
            loadData();
        };
        
        // 处理文件上传
        const handleFileUpload = async (event) => {
            const file = event.target.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('file', file);
            
            importStatus.value = { status: 'loading', msg: '导入中，请稍候...' };
            
            try {
                const res = await fetch('?action=import_excel', {
                    method: 'POST',
                    body: formData
                });
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const data = await res.json();
                importStatus.value = data;
                loadData();
                
                // 清空文件输入
                event.target.value = '';
            } catch (error) {
                importStatus.value = { status: 'error', msg: '导入失败：' + error.message };
            }
        };
        
        // 删除数据
        const deleteAgent = async (id) => {
            if (!confirm('确定要删除这条数据吗？')) {
                return;
            }
            
            try {
                const res = await fetch('?action=delete_agent', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded'
                    },
                    body: `id=${id}`
                });
                
                if (!res.ok) {
                    throw new Error(`HTTP error! status: ${res.status}`);
                }
                
                const data = await res.json();
                alert(data.msg);
                loadData();
            } catch (error) {
                alert('删除失败');
            }
        };
        
        // 导出数据
        const exportData = () => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'export_agents');
            url.searchParams.set('search_company', filterCompany.value);
            url.searchParams.set('search_agent_name', filterAgentName.value);
            url.searchParams.set('search_agent_phone', filterAgentPhone.value);
            url.searchParams.set('search_channel_follower', filterChannelFollower.value);
            
            window.location.href = url.toString();
        };
        
        // 分页相关函数
        const changePage = (page) => {
            if (page < 1 || page > totalPages.value) return;
            currentPage.value = page;
            loadData();
        };
        const jumpToPage = () => {
            const tp = totalPages.value;
            if (tp < 1) return;
            const n = parseInt(String(jumpPageInput.value).trim(), 10);
            if (Number.isNaN(n)) {
                jumpPageInput.value = String(currentPage.value);
                return;
            }
            changePage(Math.max(1, Math.min(tp, n)));
        };
        
        // 初始化
        onMounted(() => {
            loadData();
        });
        
        return {
            filterCompany,
            filterAgentName,
            filterAgentPhone,
            filterChannelFollower,
            onSearchDebounced,
            searchNow,
            agentsData,
            importStatus,
            showImportModal,
            showEditModal,
            editForm,
            editError,
            openEdit,
            saveEdit,
            editSaving,
            loadData,
            handleFileUpload,
            deleteAgent,
            exportData,
            userInfo,
            sidebarOpen,
            view,
            pageTitle,
            selectedIds,
            allRowsSelected,
            onToggleAll,
            onToggleRow,
            sortBy,
            currentPage,
            total,
            totalPages,
            paginationItems,
            changePage,
            jumpPageInput,
            jumpToPage
        };
    }
}).mount('#app');
</script>
</body>
</html>