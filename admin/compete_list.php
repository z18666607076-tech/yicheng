<?php
// compete_list.php - 数据竞对列表 (Web版本)
session_start();
header('Content-Type: text/html; charset=utf-8');

$action = $_GET['action'] ?? 'view';

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

require_once __DIR__ . '/../includes/compete_list_permissions.php';

/** 案场/经纪人等仅有 agent_id；后台管理员另有 admin_id。只读接口供 compete_mobile 等使用 */
$readApisForAgent = ['get_compete_list', 'get_projects'];
if (in_array($action, $readApisForAgent, true)) {
    if (compete_list_resolve_session_user_id() <= 0) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([]);
        exit;
    }
} elseif (!compete_list_user_is_backend_admin($pdo)) {
    header('Location: login.php');
    exit;
}

$CURRENT_USER = $_SESSION['admin_name'] ?? ($_SESSION['agent_name'] ?? '管理员');

// === 配置 ===
$COMMISSION_RATE = 0.03;

function get_allowed_compete_project_ids(PDO $pdo): ?array {
    // 后台管理员 / 财务：与 admin 一样看全部竞对项目，不按驻场 manager_name 收窄
    if (compete_list_user_is_backend_admin($pdo)) {
        return null;
    }

    $agentId = compete_list_resolve_session_user_id();

    // 未登录或无有效账号，返回空权限
    if ($agentId <= 0) {
        return [];
    }

    // 统计口径与组织架构页一致：本人 + 所有下级姓名，在 projects.manager_name 中匹配
    $allAgents = $pdo->query("SELECT id, username, manager_id FROM agents WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
    $usernameById = [];
    $childrenMap = [];
    foreach ($allAgents as $a) {
        $id = (int)($a['id'] ?? 0);
        if ($id <= 0) continue;
        $usernameById[$id] = (string)($a['username'] ?? '');
        $mid = (int)($a['manager_id'] ?? 0);
        if (!isset($childrenMap[$mid])) $childrenMap[$mid] = [];
        $childrenMap[$mid][] = $id;
    }

    $nameScope = [];
    $collectNames = function($id) use (&$collectNames, &$childrenMap, &$usernameById, &$nameScope) {
        if (isset($nameScope[$id])) return $nameScope[$id];
        $names = [];
        if (isset($usernameById[$id]) && trim($usernameById[$id]) !== '') {
            $names[] = trim($usernameById[$id]);
        }
        $children = $childrenMap[$id] ?? [];
        foreach ($children as $cid) {
            $names = array_merge($names, $collectNames($cid));
        }
        $names = array_values(array_unique($names));
        $nameScope[$id] = $names;
        return $names;
    };
    $scopeNames = $collectNames($agentId);

    $ids = [];

    // 1) 按 projects.manager_name 口径映射到竞对项目
    if (!empty($scopeNames)) {
        $namePlaceholders = implode(',', array_fill(0, count($scopeNames), '?'));
        $sql = "SELECT cp.id
                FROM compete_projects cp
                INNER JOIN projects p ON p.name = cp.name
                WHERE cp.status = 1 AND p.is_deleted = 0 AND p.manager_name IN ($namePlaceholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scopeNames);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    // 2) 兜底：若有人员项目绑定(agent_projects)，一并放行
    $sql = "SELECT cp.id
            FROM compete_projects cp
            INNER JOIN projects p ON p.name = cp.name
            INNER JOIN agent_projects ap ON ap.project_id = p.id
            WHERE cp.status = 1 AND ap.agent_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agentId]);
    $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN));

    return array_values(array_unique(array_map('intval', $ids)));
}

// [API] 获取竞对数据列表
if ($action == 'get_compete_list') {
    header('Content-Type: application/json');
    $project_ids = $_GET['project_ids'] ?? '';
    $date_start = $_GET['date_start'] ?? '';
    $date_end = $_GET['date_end'] ?? '';
    $allowedProjectIds = get_allowed_compete_project_ids($pdo);
    
    $sql = "SELECT cd.*, cp.name as project_name, cpl.name as platform_name 
            FROM compete_data cd 
            LEFT JOIN compete_projects cp ON cd.project_id = cp.id 
            LEFT JOIN compete_platforms cpl ON cd.platform_id = cpl.id 
            WHERE 1=1";
    
    $params = [];

    if (is_array($allowedProjectIds)) {
        if (empty($allowedProjectIds)) {
            echo json_encode([]);
            exit;
        }
        $allowedPlaceholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $sql .= " AND cd.project_id IN ($allowedPlaceholders)";
        $params = array_merge($params, $allowedProjectIds);
    }
    
    if (!empty($project_ids)) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $project_ids))));
        if (empty($ids)) {
            echo json_encode([]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND cd.project_id IN ($placeholders)";
        $params = array_merge($params, $ids);
    }
    
    if (!empty($date_start)) {
        $sql .= " AND cd.date >= ?";
        $params[] = $date_start;
    }
    
    if (!empty($date_end)) {
        $sql .= " AND cd.date <= ?";
        $params[] = $date_end;
    }
    
    $sql .= " ORDER BY cd.date DESC, cd.created_at DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($data);
    exit;
}

// [API] 获取项目列表
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $allowedProjectIds = get_allowed_compete_project_ids($pdo);
    $params = [];
    $sql = "SELECT id, name FROM compete_projects WHERE status = 1";

    if (is_array($allowedProjectIds)) {
        if (empty($allowedProjectIds)) {
            echo json_encode([]);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $sql .= " AND id IN ($placeholders)";
        $params = array_merge($params, $allowedProjectIds);
    }

    $sql .= " ORDER BY created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// [API] 删除竞对数据
if ($action == 'delete_compete_data') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '数据ID不能为空']);
        exit;
    }
    
    try {
        $sql = "DELETE FROM compete_data WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
        echo json_encode(['status' => 'success', 'msg' => '数据删除成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '删除失败']);
    }
    exit;
}

// [API] 更新竞对数据
if ($action == 'update_compete_data') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    $visits = $_POST['visits'] ?? 0;
    $deals = $_POST['deals'] ?? 0;
    $locks = $_POST['locks'] ?? 0;
    
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '数据ID不能为空']);
        exit;
    }
    
    try {
        $sql = "UPDATE compete_data SET visits = ?, deals = ?, locks = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$visits, $deals, $locks, $id]);
        echo json_encode(['status' => 'success', 'msg' => '数据更新成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '更新失败']);
    }
    exit;
}

// [API] 获取平台列表
if ($action == 'get_platforms') {
    header('Content-Type: application/json');
    $sql = "SELECT id, name FROM compete_platforms WHERE status = 1 ORDER BY created_at DESC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

// [API] 导出竞对数据
if ($action == 'export_compete_data') {
    $project_ids = $_GET['project_ids'] ?? '';
    $date_start = $_GET['date_start'] ?? '';
    $date_end = $_GET['date_end'] ?? '';
    $allowedProjectIds = get_allowed_compete_project_ids($pdo);
    
    $sql = "SELECT cd.date, cp.name as project_name, cpl.name as platform_name, cd.visits, cd.deals, cd.locks 
            FROM compete_data cd 
            LEFT JOIN compete_projects cp ON cd.project_id = cp.id 
            LEFT JOIN compete_platforms cpl ON cd.platform_id = cpl.id 
            WHERE 1=1";
    
    $params = [];

    if (is_array($allowedProjectIds)) {
        if (empty($allowedProjectIds)) {
            $data = [];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=compete_data_' . date('Ymd') . '.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['日期', '项目名称', '平台名称', '来访', '成交', '锁筹']);
            fclose($output);
            exit;
        }
        $allowedPlaceholders = implode(',', array_fill(0, count($allowedProjectIds), '?'));
        $sql .= " AND cd.project_id IN ($allowedPlaceholders)";
        $params = array_merge($params, $allowedProjectIds);
    }
    
    if (!empty($project_ids)) {
        $ids = array_values(array_filter(array_map('intval', explode(',', $project_ids))));
        if (empty($ids)) {
            $data = [];
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=compete_data_' . date('Ymd') . '.csv');
            $output = fopen('php://output', 'w');
            fputcsv($output, ['日期', '项目名称', '平台名称', '来访', '成交', '锁筹']);
            fclose($output);
            exit;
        }
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql .= " AND cd.project_id IN ($placeholders)";
        $params = array_merge($params, $ids);
    }
    
    if (!empty($date_start)) {
        $sql .= " AND cd.date >= ?";
        $params[] = $date_start;
    }
    
    if (!empty($date_end)) {
        $sql .= " AND cd.date <= ?";
        $params[] = $date_end;
    }
    
    $sql .= " ORDER BY cd.date DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 生成CSV
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=compete_data_' . date('Ymd') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['日期', '项目名称', '平台名称', '来访', '成交', '锁筹']);
    
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据竞对列表</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm z-10 flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">数据竞对列表</h2>
            </div>
            <div class="flex items-center gap-2"><div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold"><?php echo substr($CURRENT_USER, 0, 1); ?></div><span class="text-sm font-bold"><?php echo $CURRENT_USER; ?></span></div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div class="space-y-6 fade-in">
                <!-- 筛选条件 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-slate-800">筛选条件</h3>
                        <div class="flex gap-2">
                            <a href="compete.php" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-1">
                                <i class="fas fa-plus"></i> 录入竞对
                            </a>
                            <a href="compete_view.php" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-green-700 flex items-center gap-1">
                                <i class="fas fa-eye"></i> 查看竞对
                            </a>
                        </div>
                    </div>
                    <div class="grid grid-cols-4 gap-4">
                        <div>
                            <label class="text-xs font-bold text-slate-500 block mb-2">项目名称</label>
                            <div class="relative">
                                <input type="text" :value="selectedProjectsText" readonly placeholder="选择项目 (可多选)" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none cursor-pointer" @click="showProjectDropdown = !showProjectDropdown">
                                <div class="absolute right-2 top-2 text-gray-400">
                                    <i class="fas fa-chevron-down" :class="{ 'rotate-180': showProjectDropdown }" @click="showProjectDropdown = !showProjectDropdown"></i>
                                </div>
                                <div v-if="showProjectDropdown" class="absolute top-full left-0 right-0 mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-60 overflow-y-auto z-50">
                                    <div class="p-2 border-b border-gray-100 flex justify-between items-center">
                                        <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                                            <input type="checkbox" v-model="selectAllProjects" @change="toggleSelectAllProjects" class="rounded text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm font-bold">全部项目</span>
                                        </label>
                                        <button @click="showProjectDropdown = false" class="text-xs text-gray-400 hover:text-gray-600">
                                            <i class="fas fa-times"></i>
                                        </button>
                                    </div>
                                    <div v-for="project in projects" :key="project.id" class="p-2 hover:bg-gray-50 border-b border-gray-50">
                                        <label class="flex items-center gap-2 cursor-pointer" @click.stop>
                                            <input type="checkbox" v-model="filters.project_ids" :value="project.id" class="rounded text-blue-600 focus:ring-blue-500">
                                            <span class="text-sm">{{ project.name }}</span>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 block mb-2">开始日期</label>
                            <input v-model="filters.date_start" type="date" weekstart="1" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                        </div>
                        <div>
                            <label class="text-xs font-bold text-slate-500 block mb-2">结束日期</label>
                            <input v-model="filters.date_end" type="date" weekstart="1" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                        </div>
                        <div class="flex items-end gap-2">
                            <button @click="loadData" class="flex-1 bg-purple-600 text-white py-2 rounded-lg font-bold shadow-sm text-sm">
                                筛选
                            </button>
                            <button @click="exportData" class="bg-green-600 text-white py-2 px-4 rounded-lg font-bold shadow-sm text-sm flex items-center gap-1">
                                <i class="fas fa-download"></i> 导出
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- 对比按钮 -->
                <div v-if="selectedItems.length > 0" class="bg-blue-50 rounded-xl p-4 flex items-center gap-4">
                    <span class="text-sm font-bold text-blue-700">已选择 {{ selectedItems.length }} 个项目</span>
                    <button @click="compareData" class="bg-blue-600 text-white py-2 px-4 rounded-lg font-bold shadow-sm text-sm flex items-center gap-1">
                        <i class="fas fa-chart-bar"></i> 对比数据
                    </button>
                    <button @click="selectedItems = []" class="text-blue-600 py-2 px-4 rounded-lg font-bold shadow-sm text-sm">
                        取消选择
                    </button>
                </div>
                
                <!-- 数据列表 -->
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                            <tr>
                                <th class="px-6 py-3">
                                    <input type="checkbox" v-model="selectAll" @change="toggleSelectAll" class="rounded text-blue-600 focus:ring-blue-500">
                                </th>
                                <th class="px-6 py-3">日期</th>
                                <th class="px-6 py-3">项目名称</th>
                                <th class="px-6 py-3">平台名称</th>
                                <th class="px-6 py-3 cursor-pointer select-none hover:bg-gray-100 whitespace-nowrap" title="按各项目汇总排序" @click.stop="toggleProjectSort('visits')">
                                    来访
                                    <i class="fas text-[10px] ml-0.5 align-middle" :class="projectSortIconClass('visits')"></i>
                                </th>
                                <th class="px-6 py-3 cursor-pointer select-none hover:bg-gray-100 whitespace-nowrap" title="按各项目汇总排序" @click.stop="toggleProjectSort('deals')">
                                    成交
                                    <i class="fas text-[10px] ml-0.5 align-middle" :class="projectSortIconClass('deals')"></i>
                                </th>
                                <th class="px-6 py-3 cursor-pointer select-none hover:bg-gray-100 whitespace-nowrap" title="按各项目汇总排序" @click.stop="toggleProjectSort('locks')">
                                    锁筹
                                    <i class="fas text-[10px] ml-0.5 align-middle" :class="projectSortIconClass('locks')"></i>
                                </th>
                                <th class="px-6 py-3 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-if="Object.keys(groupedData).length === 0" class="text-center">
                                <td colspan="8" class="py-10 text-gray-400">暂无数据</td>
                            </tr>
                            <!-- 按项目分组显示 -->
                            <template v-for="([projectName, items]) in sortedGroupedEntries" :key="projectName">
                                <!-- 项目分组标题行 -->
                                <tr @click="toggleProject(projectName)" class="bg-gray-50 hover:bg-gray-100 cursor-pointer">
                                    <td class="px-6 py-3">
                                        <input type="checkbox" @click.stop v-model="selectAll" @change="toggleSelectAll" class="rounded text-blue-600 focus:ring-blue-500">
                                    </td>
                                    <td class="px-6 py-3">
                                        <i class="fas" :class="expandedProjects.includes(projectName) ? 'fa-chevron-down' : 'fa-chevron-right'"></i>
                                    </td>
                                    <td class="px-6 py-3 font-bold text-slate-800">{{ projectName }}</td>
                                    <td class="px-6 py-3 text-xs font-semibold text-slate-500">汇总</td>
                                    <td class="px-6 py-3 font-bold text-indigo-700 tabular-nums">{{ sumGroupMetrics(items).visits }}</td>
                                    <td class="px-6 py-3 font-bold text-indigo-700 tabular-nums">{{ sumGroupMetrics(items).deals }}</td>
                                    <td class="px-6 py-3 font-bold text-indigo-700 tabular-nums">{{ sumGroupMetrics(items).locks }}</td>
                                    <td class="px-6 py-3 text-right">
                                        <span class="text-xs text-gray-400">{{ items.length }} 条记录</span>
                                    </td>
                                </tr>
                                <!-- 项目下的具体记录 -->
                                <template v-if="expandedProjects.includes(projectName)">
                                    <tr v-for="item in items" :key="item.id" class="hover:bg-slate-50 border-b border-gray-50">
                                        <td class="px-6 py-4">
                                            <input type="checkbox" v-model="selectedItems" :value="item.id" class="rounded text-blue-600 focus:ring-blue-500">
                                        </td>
                                        <td class="px-6 py-4">{{ item.date }}</td>
                                        <td class="px-6 py-4 font-bold text-slate-800">{{ item.project_name }}</td>
                                        <td class="px-6 py-4">{{ item.platform_name }}</td>
                                        <td class="px-6 py-4">{{ item.visits }}</td>
                                        <td class="px-6 py-4">{{ item.deals }}</td>
                                        <td class="px-6 py-4">{{ item.locks }}</td>
                                        <td class="px-6 py-4 text-right">
                                            <button @click="editData(item)" class="text-blue-400 hover:text-blue-600 flex items-center gap-1 mr-4">
                                                <i class="fas fa-edit"></i> 编辑
                                            </button>
                                            <button @click="deleteData(item.id)" class="text-red-400 hover:text-red-600 flex items-center gap-1">
                                                <i class="fas fa-trash"></i> 删除
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                            </template>
                        </tbody>
                    </table>
                </div>
                
                <!-- 编辑弹窗 -->
                <div v-if="showEditModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-xl p-6 w-full max-w-md">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-slate-800">编辑竞对数据</h3>
                            <button @click="showEditModal = false" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="space-y-4">
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">项目名称</label>
                                <input v-model="editItem.project_name" disabled class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">平台名称</label>
                                <input v-model="editItem.platform_name" disabled class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">日期</label>
                                <input v-model="editItem.date" disabled class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">来访</label>
                                <input v-model.number="editItem.visits" type="number" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">成交</label>
                                <input v-model.number="editItem.deals" type="number" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                            <div>
                                <label class="text-xs font-bold text-slate-500 block mb-2">锁筹</label>
                                <input v-model.number="editItem.locks" type="number" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                            </div>
                        </div>
                        <div class="flex justify-end gap-4 mt-6">
                            <button @click="showEditModal = false" class="bg-gray-200 text-gray-800 py-2 px-4 rounded-lg font-bold shadow-sm text-sm">
                                取消
                            </button>
                            <button @click="saveEdit" class="bg-blue-600 text-white py-2 px-4 rounded-lg font-bold shadow-sm text-sm">
                                保存
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- 对比弹窗 -->
                <div v-if="showCompareModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50">
                    <div class="bg-white rounded-xl p-6 w-full max-w-4xl max-h-[80vh] overflow-y-auto">
                        <div class="flex justify-between items-center mb-4">
                            <h3 class="text-lg font-bold text-slate-800">数据对比</h3>
                            <button @click="showCompareModal = false" class="text-gray-400 hover:text-gray-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left text-gray-500">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                                    <tr>
                                        <th class="px-6 py-3">日期</th>
                                        <th class="px-6 py-3">项目名称</th>
                                        <th class="px-6 py-3">平台名称</th>
                                        <th class="px-6 py-3">来访</th>
                                        <th class="px-6 py-3">成交</th>
                                        <th class="px-6 py-3">锁筹</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="item in compareDataList" :key="item.id" class="hover:bg-slate-50 border-b border-gray-50">
                                        <td class="px-6 py-4">{{ item.date }}</td>
                                        <td class="px-6 py-4 font-bold text-slate-800">{{ item.project_name }}</td>
                                        <td class="px-6 py-4">{{ item.platform_name }}</td>
                                        <td class="px-6 py-4">{{ item.visits }}</td>
                                        <td class="px-6 py-4">{{ item.deals }}</td>
                                        <td class="px-6 py-4">{{ item.locks }}</td>
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
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('compete_list');
        const filters = ref({
            project_ids: [],
            date_start: '',
            date_end: ''
        });
        
        const showProjectDropdown = ref(false);
        const selectAllProjects = ref(false);
        
        const projects = ref([]);
        const competeData = ref([]);
        const selectedItems = ref([]);
        const selectAll = ref(false);
        const showEditModal = ref(false);
        const showCompareModal = ref(false);
        const editItem = ref({});
        const compareDataList = ref([]);
        
        // 加载项目列表
        const loadProjects = async () => {
            const res = await fetch('?action=get_projects');
            const data = await res.json();
            projects.value = data;
        };
        
        // 计算属性：显示已选择的项目名称
        const selectedProjectsText = computed(() => {
            if (filters.value.project_ids.length === 0) {
                return '';
            }
            if (filters.value.project_ids.length === projects.value.length) {
                return '全部项目';
            }
            const selectedNames = projects.value
                .filter(project => filters.value.project_ids.includes(project.id))
                .map(project => project.name);
            return selectedNames.length > 3 ? `${selectedNames.slice(0, 3).join(', ')} 等${selectedNames.length}个项目` : selectedNames.join(', ');
        });
        
        // 全选/取消全选项目
        const toggleSelectAllProjects = () => {
            if (selectAllProjects.value) {
                filters.value.project_ids = projects.value.map(project => project.id);
            } else {
                filters.value.project_ids = [];
            }
        };
        
        // 加载竞对数据
        const loadData = async () => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'get_compete_list');
            url.searchParams.set('project_ids', filters.value.project_ids.join(','));
            url.searchParams.set('date_start', filters.value.date_start);
            url.searchParams.set('date_end', filters.value.date_end);
            
            const res = await fetch(url.toString());
            const data = await res.json();
            competeData.value = data;
            
            // 按项目名称分组
            groupDataByProject();
            
            selectedItems.value = [];
            selectAll.value = false;
        };
        
        // 按项目名称分组
        const groupedData = ref({});
        const expandedProjects = ref([]);
        const projectSortKey = ref('');
        const projectSortDir = ref('desc');

        const groupDataByProject = () => {
            const groups = {};
            competeData.value.forEach(item => {
                const projectName = item.project_name;
                if (!groups[projectName]) {
                    groups[projectName] = [];
                }
                groups[projectName].push(item);
            });
            groupedData.value = groups;
        };

        /** 分组标题行：来访 / 成交 / 锁筹 小计 */
        const sumGroupMetrics = (items) => {
            if (!items || !items.length) {
                return { visits: 0, deals: 0, locks: 0 };
            }
            return items.reduce(
                (acc, row) => {
                    acc.visits += Number(row.visits) || 0;
                    acc.deals += Number(row.deals) || 0;
                    acc.locks += Number(row.locks) || 0;
                    return acc;
                },
                { visits: 0, deals: 0, locks: 0 }
            );
        };

        const toggleProjectSort = (key) => {
            if (projectSortKey.value === key) {
                projectSortDir.value = projectSortDir.value === 'desc' ? 'asc' : 'desc';
            } else {
                projectSortKey.value = key;
                projectSortDir.value = 'desc';
            }
        };

        const projectSortIconClass = (key) => {
            if (projectSortKey.value !== key) {
                return 'fa-sort text-gray-300';
            }
            return projectSortDir.value === 'asc'
                ? 'fa-sort-up text-indigo-600'
                : 'fa-sort-down text-indigo-600';
        };

        const sortedGroupedEntries = computed(() => {
            const entries = Object.entries(groupedData.value);
            const sk = projectSortKey.value;
            if (!sk || !['visits', 'deals', 'locks'].includes(sk)) {
                return entries;
            }
            const mul = projectSortDir.value === 'asc' ? 1 : -1;
            return [...entries].sort((a, b) => {
                const sa = sumGroupMetrics(a[1])[sk];
                const sb = sumGroupMetrics(b[1])[sk];
                const diff = (Number(sa) || 0) - (Number(sb) || 0);
                if (diff !== 0) {
                    return diff * mul;
                }
                return String(a[0]).localeCompare(String(b[0]), 'zh-CN');
            });
        });

        // 切换项目展开/收起状态
        const toggleProject = (projectName) => {
            const index = expandedProjects.value.indexOf(projectName);
            if (index > -1) {
                expandedProjects.value.splice(index, 1);
            } else {
                expandedProjects.value.push(projectName);
            }
        };
        
        // 全选/取消全选
        const toggleSelectAll = () => {
            if (selectAll.value) {
                selectedItems.value = competeData.value.map(item => item.id);
            } else {
                selectedItems.value = [];
            }
        };
        
        // 删除数据
        const deleteData = async (id) => {
            if (!confirm('确定要删除这条数据吗？')) {
                return;
            }
            
            const res = await fetch('?action=delete_compete_data', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                loadData();
            } else {
                alert(data.msg);
            }
        };
        
        // 编辑数据
        const editData = (item) => {
            editItem.value = { ...item };
            showEditModal.value = true;
        };
        
        // 保存编辑
        const saveEdit = async () => {
            const res = await fetch('?action=update_compete_data', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${editItem.value.id}&visits=${editItem.value.visits}&deals=${editItem.value.deals}&locks=${editItem.value.locks}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                showEditModal.value = false;
                loadData();
            } else {
                alert(data.msg);
            }
        };
        
        // 对比数据
        const compareData = () => {
            compareDataList.value = competeData.value.filter(item => selectedItems.value.includes(item.id));
            showCompareModal.value = true;
        };
        
        // 导出数据
        const exportData = async () => {
            const url = new URL(window.location.href);
            url.searchParams.set('action', 'export_compete_data');
            url.searchParams.set('project_ids', filters.value.project_ids.join(','));
            url.searchParams.set('date_start', filters.value.date_start);
            url.searchParams.set('date_end', filters.value.date_end);
            
            window.location.href = url.toString();
        };
        
        // 初始化
        onMounted(() => {
            loadProjects();
            loadData();
            
            // 添加全局点击事件监听器，点击空白区域关闭下拉菜单
            document.addEventListener('click', (event) => {
                const dropdown = document.querySelector('.relative');
                if (dropdown && !dropdown.contains(event.target)) {
                    showProjectDropdown.value = false;
                }
            });
        });
        
        return {
            sidebarOpen,
            view,
            filters,
            projects,
            competeData,
            selectedItems,
            selectAll,
            showEditModal,
            showCompareModal,
            editItem,
            compareDataList,
            showProjectDropdown,
            selectAllProjects,
            selectedProjectsText,
            groupedData,
            sortedGroupedEntries,
            expandedProjects,
            toggleProjectSort,
            projectSortIconClass,
            loadProjects,
            loadData,
            toggleSelectAll,
            deleteData,
            editData,
            saveEdit,
            compareData,
            exportData,
            toggleSelectAllProjects,
            toggleProject,
            sumGroupMetrics
        };
    }
}).mount('#app');
</script>
</body>
</html>