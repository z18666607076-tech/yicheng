<?php
// admin_followup_summaries.php - 管理跟进概述选项
session_start();
header('Content-Type: text/html; charset=utf-8');

// 先读取 action，get_all 允许前端页面匿名读取
$action = $_GET['action'] ?? 'view';

// 登录检查（get_all 除外）
if ($action !== 'get_all' && !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// 数据库连接
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

// 添加跟进概述
if ($action == 'add') {
    header('Content-Type: application/json');
    
    // 处理JSON格式的请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $followup_count = intval($input['followup_count'] ?? $_POST['followup_count']);
    $summary = $input['summary'] ?? $_POST['summary'] ?? '';
    $sort_order = intval($input['sort_order'] ?? $_POST['sort_order']);
    
    if (empty($summary) || $followup_count < 1 || $followup_count > 3) {
        echo json_encode(['status' => 'error', 'msg' => '参数错误']);
        exit;
    }
    
    try {
        $sql = "INSERT INTO followup_summaries (followup_count, summary, sort_order) VALUES (?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$followup_count, $summary, $sort_order]);
        echo json_encode(['status' => 'success', 'msg' => '添加成功']);
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo json_encode(['status' => 'error', 'msg' => '该跟进概述已存在']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => '添加失败: ' . $e->getMessage()]);
        }
    }
    exit;
}

// 删除跟进概述
if ($action == 'delete') {
    header('Content-Type: application/json');
    
    // 处理JSON格式的请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $id = intval($input['id'] ?? $_POST['id']);
    
    try {
        $sql = "DELETE FROM followup_summaries WHERE id = ?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$id]);
        echo json_encode(['status' => 'success', 'msg' => '删除成功']);
    } catch (PDOException $e) {
        echo json_encode(['status' => 'error', 'msg' => '删除失败: ' . $e->getMessage()]);
    }
    exit;
}

// 更新排序
if ($action == 'update_order') {
    header('Content-Type: application/json');
    
    // 处理JSON格式的请求数据
    $input = json_decode(file_get_contents('php://input'), true);
    $orders = $input['orders'] ?? $_POST['orders'] ?? [];
    
    try {
        $pdo->beginTransaction();
        foreach ($orders as $id => $sort_order) {
            $sql = "UPDATE followup_summaries SET sort_order = ? WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$sort_order, $id]);
        }
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => '排序更新成功']);
    } catch (PDOException $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => '排序更新失败: ' . $e->getMessage()]);
    }
    exit;
}

// 获取跟进概述列表
if ($action == 'get_list') {
    header('Content-Type: application/json');
    $followup_count = intval($_GET['followup_count'] ?? 0);
    
    $sql = "SELECT * FROM followup_summaries WHERE followup_count = ? ORDER BY sort_order ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$followup_count]);
    $summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($summaries);
    exit;
}

// 获取所有跟进概述
if ($action == 'get_all') {
    header('Content-Type: application/json');
    
    // 跳过登录检查，因为这个API可能被其他页面调用
    $sql = "SELECT * FROM followup_summaries ORDER BY followup_count ASC, sort_order ASC";
    $stmt = $pdo->query($sql);
    $summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 按跟进次数分组
    $result = [];
    foreach ($summaries as $summary) {
        $result[$summary['followup_count']][] = $summary;
    }
    
    echo json_encode($result);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理跟进概述选项</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <style>
        .draggable-item {
            cursor: move;
            user-select: none;
        }
        .draggable-item:hover {
            background-color: #f8fafc;
        }
        .dragging {
            opacity: 0.5;
            background-color: #e2e8f0;
        }
    </style>
</head>
<body class="bg-gray-50">
    <div id="app" class="flex h-screen overflow-hidden">
        <?php include 'sidebar.php'; ?>

        <div class="flex-1 flex flex-col min-w-0 min-h-0 bg-gray-50">
            <div class="shrink-0 flex items-center gap-2 px-3 py-2.5 border-b border-gray-200 bg-white md:px-6 md:pt-6 md:pb-0 md:border-0 md:bg-transparent">
                <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 p-2 -ml-1 rounded-lg text-gray-600 hover:bg-gray-100" aria-label="打开菜单">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <div class="flex min-w-0 flex-1 items-center justify-between gap-2">
                    <h1 class="truncate text-lg font-bold text-gray-800 md:text-2xl">管理跟进概述选项</h1>
                    <a href="admin.php" class="shrink-0 rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-600 hover:bg-gray-200 md:px-4 flex items-center gap-2">
                        <i class="fas fa-arrow-left"></i><span class="hidden sm:inline">返回</span>
                    </a>
                </div>
            </div>

        <div class="flex-1 overflow-y-auto p-4 md:p-6">
            
            <div class="space-y-8">
                <!-- 第1次跟进 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-700">第1次跟进选项</h2>
                        <button data-count="1" class="add-btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm flex items-center gap-1">
                            <i class="fas fa-plus"></i> 添加
                        </button>
                    </div>
                    <div data-count="1" class="followup-list space-y-2 min-h-[100px]">
                        <!-- 动态生成 -->
                    </div>
                </div>
                
                <!-- 第2次跟进 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-700">第2次跟进选项</h2>
                        <button data-count="2" class="add-btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm flex items-center gap-1">
                            <i class="fas fa-plus"></i> 添加
                        </button>
                    </div>
                    <div data-count="2" class="followup-list space-y-2 min-h-[100px]">
                        <!-- 动态生成 -->
                    </div>
                </div>
                
                <!-- 第3次跟进 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold text-gray-700">第3次跟进选项</h2>
                        <button data-count="3" class="add-btn bg-blue-500 hover:bg-blue-600 text-white px-3 py-1 rounded-lg text-sm flex items-center gap-1">
                            <i class="fas fa-plus"></i> 添加
                        </button>
                    </div>
                    <div data-count="3" class="followup-list space-y-2 min-h-[100px]">
                        <!-- 动态生成 -->
                    </div>
                </div>
            </div>
        </div>
        </div>
    </div>

    <!-- 添加模态框 -->
    <div id="addModal" class="fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 hidden">
        <div class="bg-white rounded-xl p-6 w-full max-w-md">
            <h3 class="text-lg font-semibold text-gray-800 mb-4">添加跟进概述</h3>
            <input type="hidden" id="modalCount">
            <div class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">跟进概述</label>
                    <input type="text" id="summaryInput" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">排序</label>
                    <input type="number" id="sortInput" class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500" value="0">
                </div>
            </div>
            <div class="flex justify-end gap-3 mt-6">
                <button id="cancelBtn" class="px-4 py-2 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50">取消</button>
                <button id="saveBtn" class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">保存</button>
            </div>
        </div>
    </div>
    
    <script>
        // 加载跟进概述列表
        async function loadSummaries() {
            try {
                const response = await axios.get('?action=get_all');
                const data = response.data;
                
                // 清空所有列表
                document.querySelectorAll('.followup-list').forEach(list => {
                    list.innerHTML = '';
                });
                
                // 填充数据
                for (let count = 1; count <= 3; count++) {
                    const summaries = data[count] || [];
                    const list = document.querySelector(`.followup-list[data-count="${count}"]`);
                    
                    if (summaries.length === 0) {
                        list.innerHTML = '<div class="text-gray-400 text-sm">暂无选项</div>';
                    } else {
                        summaries.forEach((summary, index) => {
                            const item = document.createElement('div');
                            item.className = 'draggable-item flex justify-between items-center p-3 border border-gray-200 rounded-lg';
                            item.dataset.id = summary.id;
                            item.innerHTML = `
                                <span>${summary.summary}</span>
                                <button class="delete-btn text-red-500 hover:text-red-700" data-id="${summary.id}">
                                    <i class="fas fa-trash"></i>
                                </button>
                            `;
                            list.appendChild(item);
                        });
                    }
                }
                
                // 删除通过 #app 上的事件委托处理；初始化拖拽排序
                initDragAndDrop();
            } catch (error) {
                console.error('加载失败:', error);
            }
        }
        
        // 初始化拖拽排序
        function initDragAndDrop() {
            let draggedItem = null;
            
            document.querySelectorAll('.draggable-item').forEach(item => {
                item.addEventListener('dragstart', function(e) {
                    draggedItem = this;
                    setTimeout(() => {
                        this.classList.add('dragging');
                    }, 0);
                });
                
                item.addEventListener('dragend', function() {
                    this.classList.remove('dragging');
                    draggedItem = null;
                    
                    // 更新排序
                    updateOrder();
                });
                
                item.addEventListener('dragover', function(e) {
                    e.preventDefault();
                });
                
                item.addEventListener('dragenter', function(e) {
                    e.preventDefault();
                    if (this !== draggedItem) {
                        this.classList.add('bg-gray-50');
                    }
                });
                
                item.addEventListener('dragleave', function() {
                    this.classList.remove('bg-gray-50');
                });
                
                item.addEventListener('drop', function(e) {
                    e.preventDefault();
                    this.classList.remove('bg-gray-50');
                    
                    if (this !== draggedItem) {
                        const list = this.parentNode;
                        const draggedIndex = Array.from(list.children).indexOf(draggedItem);
                        const targetIndex = Array.from(list.children).indexOf(this);
                        
                        if (draggedIndex < targetIndex) {
                            list.insertBefore(draggedItem, this.nextSibling);
                        } else {
                            list.insertBefore(draggedItem, this);
                        }
                    }
                });
            });
        }
        
        // 更新排序
        async function updateOrder() {
            const orders = {};
            
            document.querySelectorAll('.followup-list').forEach(list => {
                const items = list.querySelectorAll('.draggable-item');
                items.forEach((item, index) => {
                    orders[item.dataset.id] = index + 1;
                });
            });
            
            try {
                const response = await axios.post('?action=update_order', { orders });
                if (response.data.status !== 'success') {
                    console.error('排序更新失败:', response.data.msg);
                }
            } catch (error) {
                console.error('排序更新失败:', error);
            }
        }
        
        function setupDelegatedClicks() {
            const root = document.getElementById('app');
            if (!root || root.dataset.delegated === '1') return;
            root.dataset.delegated = '1';
            root.addEventListener('click', async (e) => {
                const addBtn = e.target.closest('.add-btn');
                if (addBtn) {
                    const count = addBtn.dataset.count;
                    document.getElementById('modalCount').value = count;
                    document.getElementById('summaryInput').value = '';
                    document.getElementById('sortInput').value = '0';
                    document.getElementById('addModal').classList.remove('hidden');
                    return;
                }
                const delBtn = e.target.closest('.delete-btn');
                if (delBtn) {
                    const id = delBtn.dataset.id;
                    if (!id || !confirm('确定要删除这个跟进概述吗？')) return;
                    try {
                        const response = await axios.post('?action=delete', { id });
                        if (response.data.status === 'success') {
                            loadSummaries();
                        } else {
                            alert(response.data.msg);
                        }
                    } catch (error) {
                        console.error('删除失败:', error);
                    }
                }
            });
        }

        function setupModalButtons() {
            const cancelBtn = document.getElementById('cancelBtn');
            const saveBtn = document.getElementById('saveBtn');
            if (cancelBtn && !cancelBtn.dataset.bound) {
                cancelBtn.dataset.bound = '1';
                cancelBtn.addEventListener('click', () => {
                    document.getElementById('addModal').classList.add('hidden');
                });
            }
            if (saveBtn && !saveBtn.dataset.bound) {
                saveBtn.dataset.bound = '1';
                saveBtn.addEventListener('click', async () => {
                    const count = document.getElementById('modalCount').value;
                    const summary = document.getElementById('summaryInput').value.trim();
                    const sortOrder = document.getElementById('sortInput').value;
                    if (!summary) {
                        alert('请输入跟进概述');
                        return;
                    }
                    try {
                        const response = await axios.post('?action=add', {
                            followup_count: count,
                            summary: summary,
                            sort_order: sortOrder
                        });
                        if (response.data.status === 'success') {
                            document.getElementById('addModal').classList.add('hidden');
                            loadSummaries();
                        } else {
                            alert(response.data.msg);
                        }
                    } catch (error) {
                        console.error('添加失败:', error);
                    }
                });
            }
        }

        const { createApp, ref, onMounted, nextTick } = Vue;
        createApp({
            setup() {
                const sidebarOpen = ref(false);
                const view = ref('followup_summaries');
                onMounted(async () => {
                    setupDelegatedClicks();
                    setupModalButtons();
                    await nextTick();
                    loadSummaries();
                });
                return { sidebarOpen, view };
            }
        }).mount('#app');
    </script>
</body>
</html>