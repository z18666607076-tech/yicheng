<?php
// admin_projects.php - 项目库独立管理页 (v2.1: 增加默认图片容错)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库连接 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

require_once __DIR__ . '/../project_image_url.php';

$action = $_GET['action'] ?? 'view';

// [API] 获取项目列表
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $sql = "SELECT * FROM projects WHERE is_deleted=0 ORDER BY sort_order ASC, id DESC";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if (isset($r['image'])) {
            $r['image'] = project_image_public_url($r['image']);
        }
    }
    unset($r);
    echo json_encode($rows);
    exit;
}

// [API] 更新项目排序
if ($action == 'update_sort_order') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $direction = $_POST['direction']; // 'up' or 'down'
    
    // 获取当前项目的排序值
    $stmt = $pdo->prepare("SELECT sort_order FROM projects WHERE id=?");
    $stmt->execute([$id]);
    $currentSort = $stmt->fetchColumn();
    
    if ($direction == 'up') {
        // 找到前一个项目并交换排序值
        $stmt = $pdo->prepare("SELECT id, sort_order FROM projects WHERE is_deleted=0 AND sort_order < ? ORDER BY sort_order DESC, id DESC LIMIT 1");
        $stmt->execute([$currentSort]);
        $prevProject = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($prevProject) {
            $pdo->prepare("UPDATE projects SET sort_order=? WHERE id=?")->execute([$prevProject['sort_order'], $id]);
            $pdo->prepare("UPDATE projects SET sort_order=? WHERE id=?")->execute([$currentSort, $prevProject['id']]);
        }
    } else if ($direction == 'down') {
        // 找到后一个项目并交换排序值
        $stmt = $pdo->prepare("SELECT id, sort_order FROM projects WHERE is_deleted=0 AND sort_order > ? ORDER BY sort_order ASC, id ASC LIMIT 1");
        $stmt->execute([$currentSort]);
        $nextProject = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($nextProject) {
            $pdo->prepare("UPDATE projects SET sort_order=? WHERE id=?")->execute([$nextProject['sort_order'], $id]);
            $pdo->prepare("UPDATE projects SET sort_order=? WHERE id=?")->execute([$currentSort, $nextProject['id']]);
        }
    }
    
    echo json_encode(['status'=>'success']); 
    exit;
}

// [API] 更新项目数字排序
if ($action == 'update_sort_number') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $sort_number = (int)$_POST['sort_number'];
    
    // 更新排序值
    $pdo->prepare("UPDATE projects SET sort_order=? WHERE id=?")->execute([$sort_number, $id]);
    
    echo json_encode(['status'=>'success']); 
    exit;
}

// [API] 删除项目
if ($action == 'delete_project') {
    header('Content-Type: application/json');
    $pdo->prepare("UPDATE projects SET is_deleted=1 WHERE id=?")->execute([$_POST['id']]);
    echo json_encode(['status'=>'success']); 
    exit;
}

// [API] 切换项目状态
if ($action == 'toggle_project_status') {
    header('Content-Type: application/json');
    $pdo->prepare("UPDATE projects SET status=1-status WHERE id=?")->execute([$_POST['id']]);
    echo json_encode(['status'=>'success']); 
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>项目资源库</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        .badge-agent { background: #10b981; color: white; }
        .badge-market { background: #94a3b8; color: white; }
        .badge-active { background: #10b981; color: white; }
        .badge-inactive { background: #ef4444; color: white; }
        .status-btn { transition: all 0.2s; }
        .status-btn:hover { transform: scale(1.05); }
        .fade-enter-active, .fade-leave-active { transition: opacity 0.2s; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">项目资源库</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">共 {{ filteredList.length }} 个项目</span>
                <a href="admin_project_edit.php" target="_blank" class="bg-blue-600 hover:bg-blue-700 text-white px-4 py-2 rounded-lg text-sm font-bold shadow-lg transition">
                    <i class="fas fa-plus mr-1"></i> 新增项目
                </a>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div class="bg-white p-4 rounded-xl shadow-sm mb-6 flex justify-between items-center flex-wrap gap-4">
                <div class="flex gap-4 items-center flex-1">
                    <div class="relative w-64">
                        <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                        <input v-model="search" class="w-full pl-10 pr-4 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-blue-100 outline-none" placeholder="搜索项目名称...">
                    </div>
                    <div class="flex bg-gray-100 p-1 rounded-lg">
                        <button @click="filterType='all'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterType=='all'?'bg-white text-blue-700 shadow':'text-gray-500'">全部</button>
                        <button @click="filterType='agent'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterType=='agent'?'bg-white text-green-700 shadow':'text-gray-500'">代理在售</button>
                        <button @click="filterType='market'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterType=='market'?'bg-white text-gray-700 shadow':'text-gray-500'">市场数据</button>
                    </div>
                    <div class="flex bg-gray-100 p-1 rounded-lg">
                        <button @click="filterStatus='all'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterStatus=='all'?'bg-white text-blue-700 shadow':'text-gray-500'">全部状态</button>
                        <button @click="filterStatus='active'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterStatus=='active'?'bg-white text-green-700 shadow':'text-gray-500'">上架</button>
                        <button @click="filterStatus='inactive'" class="px-3 py-1.5 rounded-md text-xs font-bold transition" :class="filterStatus=='inactive'?'bg-white text-red-700 shadow':'text-gray-500'">下架</button>
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-gray-500 font-bold whitespace-nowrap">管理员</span>
                        <select v-model="filterManager" class="px-3 py-2 border rounded-lg text-sm bg-white focus:ring-2 focus:ring-blue-100 outline-none min-w-[170px]">
                            <option value="">全部管理员</option>
                            <option value="__unset__">未设置</option>
                            <option v-for="name in managerOptions" :key="name" :value="name">{{ name }}</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-2 bg-gray-100 p-1 rounded-lg">
                    <button @click="viewMode='grid'" class="w-8 h-8 flex items-center justify-center rounded transition" :class="viewMode=='grid' ? 'bg-white text-blue-600 shadow' : 'text-gray-400 hover:text-gray-600'" title="卡片视图"><i class="fas fa-th-large"></i></button>
                    <button @click="viewMode='list'" class="w-8 h-8 flex items-center justify-center rounded transition" :class="viewMode=='list' ? 'bg-white text-blue-600 shadow' : 'text-gray-400 hover:text-gray-600'" title="列表视图"><i class="fas fa-list"></i></button>
                </div>
            </div>

            <div v-if="filteredList.length === 0" class="text-center py-20 text-gray-400 bg-white rounded-xl border border-dashed">
                <i class="fas fa-folder-open text-4xl mb-3"></i>
                <p>暂无符合条件的项目</p>
            </div>

            <div v-if="viewMode === 'grid'" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                <div v-for="p in filteredList" :key="p.id" class="bg-white rounded-xl shadow-sm overflow-hidden group border border-slate-100 hover:shadow-xl transition duration-300 flex flex-col h-full">
                    <div class="h-48 bg-gray-200 relative overflow-hidden group-hover:opacity-90 transition">
                        <img 
                            :src="p.image || defaultImg" 
                            @error="handleImgError($event)"
                            class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                        >
                        <div class="absolute top-2 right-2 px-2 py-1 rounded text-[10px] font-bold shadow-sm" :class="p.is_agent == 1 ? 'badge-agent' : 'badge-market'">
                            {{ p.is_agent == 1 ? '代理在售' : '市场数据' }}
                        </div>
                        <div class="absolute top-2 left-2 px-2 py-1 rounded text-[10px] font-bold shadow-sm" :class="p.status == 1 ? 'badge-active' : 'badge-inactive'">
                            {{ p.status == 1 ? '上架' : '下架' }}
                        </div>
                    </div>
                    <div class="p-5 flex-1 flex flex-col">
                        <h3 class="font-bold text-lg text-slate-800 mb-2 flex justify-between items-start">
                            <span class="truncate" :title="p.name">{{ p.name }}</span>
                            <span class="text-rose-500 text-base font-extrabold whitespace-nowrap">
                                <small class="text-xs font-normal text-gray-400">均价</small> {{ parseFloat(p.price) > 0 ? p.price : '待定' }}
                            </span>
                        </h3>
                        <div v-if="p.is_agent == 1" class="bg-green-50 p-3 rounded-lg border border-green-100 mb-3">
                            <div class="flex items-center text-xs text-green-800 mb-1"><i class="fas fa-user-tie w-4"></i> 管理员: <span class="font-bold ml-1">{{ p.manager_name || '未设置' }}</span></div>
                            <div class="flex items-center text-xs text-green-800 mb-1"><i class="fas fa-phone w-4"></i> 电话: <span class="font-mono ml-1">{{ p.manager_phone || '-' }}</span></div>
                            <div class="flex items-center text-xs text-green-800"><i class="fas fa-coins w-4"></i> 佣金: <span class="font-bold ml-1">{{ p.commission_rate || '暂无' }}</span></div>
                        </div>
                        <div v-else class="bg-gray-50 p-3 rounded-lg border border-gray-100 mb-3 text-xs text-gray-400 italic text-center">仅作为市场参考数据展示</div>
                        <div class="mt-auto pt-4 border-t border-gray-50 flex justify-between items-center">
                            <span class="text-xs text-gray-400">保护期: {{ p.protect_days }}天</span>
                            <div class="space-x-3 text-sm">
                                <a :href="'admin_project_edit.php?id='+p.id" target="_blank" class="text-blue-600 hover:text-blue-800 font-bold"><i class="fas fa-edit"></i> 编辑</a>
                                <button @click="toggleStatus(p.id)" class="status-btn" :class="p.status == 1 ? 'text-red-500 hover:text-red-700' : 'text-green-500 hover:text-green-700'">
                                    <i :class="p.status == 1 ? 'fas fa-eye-slash' : 'fas fa-eye'"></i> {{ p.status == 1 ? '下架' : '上架' }}
                                </button>
                                <button @click="delProject(p.id)" class="text-red-400 hover:text-red-600 transition"><i class="fas fa-trash-alt"></i> 删除</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="viewMode === 'list'" class="bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                            <tr>
                                <th class="px-6 py-3 w-20">封面</th>
                                <th class="px-6 py-3">项目名称</th>
                                <th class="px-6 py-3">价格/佣金</th>
                                <th class="px-6 py-3">管理员信息</th>
                                <th class="px-6 py-3">排序</th>
                                <th class="px-6 py-3 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="p in filteredList" :key="p.id" class="hover:bg-slate-50 transition">
                                <td class="px-6 py-3">
                                    <div class="w-12 h-12 rounded-lg bg-gray-100 overflow-hidden border border-gray-200">
                                        <img :src="p.image || defaultImg" @error="handleImgError($event)" class="w-full h-full object-cover">
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-col">
                                        <span class="font-bold text-slate-800 text-base mb-1">{{ p.name }}</span>
                                        <div class="flex gap-2">
                                            <span v-if="p.is_agent" class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-800 w-fit">代理在售</span>
                                            <span v-else class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-gray-100 text-gray-800 w-fit">市场数据</span>
                                            <span :class="p.status == 1 ? 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-800 w-fit' : 'inline-flex items-center px-2 py-0.5 rounded text-[10px] font-medium bg-red-100 text-red-800 w-fit'">
                                                {{ p.status == 1 ? '上架' : '下架' }}
                                            </span>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-col gap-1">
                                        <span class="text-rose-500 font-bold">¥{{ parseFloat(p.price) > 0 ? p.price : '-' }} <span class="text-xs font-normal text-gray-400">/㎡</span></span>
                                        <span class="text-xs text-blue-600 bg-blue-50 px-1.5 rounded w-fit" v-if="p.is_agent">{{ p.commission_rate || '佣金未设' }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-3">
                                    <div v-if="p.is_agent" class="text-xs">
                                        <div class="font-bold text-slate-700">{{ p.manager_name }}</div>
                                        <div class="text-gray-400 font-mono">{{ p.manager_phone }}</div>
                                    </div>
                                    <span v-else class="text-xs text-gray-300">-</span>
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-col gap-2">
                                        <input 
                                            v-model.number="p.sort_order" 
                                            @change="updateSortNumber(p.id, p.sort_order)" 
                                            type="number" 
                                            min="0" 
                                            class="w-16 px-2 py-1 border rounded text-center text-sm"
                                            title="输入排序数字，小的在前"
                                        >
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-right">
                                    <div class="flex justify-end gap-4">
                                        <a :href="'admin_project_edit.php?id='+p.id" target="_blank" class="text-blue-600 hover:underline font-bold text-xs"><i class="fas fa-edit mr-1"></i>编辑</a>
                                        <button @click="toggleStatus(p.id)" class="status-btn text-xs" :class="p.status == 1 ? 'text-red-500 hover:text-red-700' : 'text-green-500 hover:text-green-700'">
                                            <i :class="p.status == 1 ? 'fas fa-eye-slash mr-1' : 'fas fa-eye mr-1'"></i>{{ p.status == 1 ? '下架' : '上架' }}
                                        </button>
                                        <button @click="delProject(p.id)" class="text-red-400 hover:text-red-600 text-xs"><i class="fas fa-trash-alt mr-1"></i>删除</button>
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
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('projects');
        const projects = ref([]);
        const search = ref('');
        const filterType = ref('all');
        const filterStatus = ref('all');
        const filterManager = ref('');
        const viewMode = ref('list'); 

        // === 核心：定义默认图片 ===
        const defaultImg = 'https://dummyimage.com/600x400/e2e8f0/94a3b8&text=暂无图片';

        // === 核心：图片加载失败时的补救措施 ===
        const handleImgError = (e) => {
            e.target.src = defaultImg;
        };

        const loadData = async () => {
            try {
                const res = await fetch('?action=get_projects');
                const data = await res.json();
                projects.value = data;
            } catch (e) { console.error("加载失败", e); }
        };

        const managerOptions = computed(() => {
            const names = projects.value
                .map(p => (p.manager_name || '').trim())
                .filter(name => name.length > 0);
            return [...new Set(names)];
        });

        const filteredList = computed(() => {
            return projects.value.filter(p => {
                if (filterType.value === 'agent' && p.is_agent != 1) return false;
                if (filterType.value === 'market' && p.is_agent == 1) return false;
                if (filterStatus.value === 'active' && p.status != 1) return false;
                if (filterStatus.value === 'inactive' && p.status == 1) return false;
                if (filterManager.value === '__unset__' && (p.manager_name || '').trim() !== '') return false;
                if (filterManager.value && filterManager.value !== '__unset__' && (p.manager_name || '').trim() !== filterManager.value) return false;
                if (search.value) return p.name.includes(search.value);
                return true;
            });
        });

        const delProject = async (id) => {
            if(!confirm('确定删除该项目？删除后经纪人端将不可见。')) return;
            const fd = new FormData(); fd.append('id', id);
            const res = await fetch('?action=delete_project', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status === 'success') loadData();
        };

        const toggleStatus = async (id) => {
            const fd = new FormData(); fd.append('id', id);
            const res = await fetch('?action=toggle_project_status', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status === 'success') loadData();
        };

        const updateSortOrder = async (id, direction) => {
            const fd = new FormData(); 
            fd.append('id', id);
            fd.append('direction', direction);
            const res = await fetch('?action=update_sort_order', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status === 'success') loadData();
        };

        const updateSortNumber = async (id, sortNumber) => {
            const fd = new FormData(); 
            fd.append('id', id);
            fd.append('sort_number', sortNumber);
            const res = await fetch('?action=update_sort_number', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status === 'success') loadData();
        };

        onMounted(loadData);

        return { sidebarOpen, view, projects, search, filterType, filterStatus, filterManager, managerOptions, filteredList, delProject, toggleStatus, updateSortOrder, updateSortNumber, viewMode, defaultImg, handleImgError };
    }
}).mount('#app');
</script>
</body>
</html>