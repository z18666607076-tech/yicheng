<?php
// compete_admin.php - 数据竞对后台管理 (Web版本)
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$CURRENT_USER = $_SESSION['admin_name'] ?? '管理员';

// === 配置 ===
$COMMISSION_RATE = 0.03; 

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

// [API] 获取项目列表
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $sql = "SELECT id, name, status FROM compete_projects ORDER BY created_at DESC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

// [API] 获取平台列表
if ($action == 'get_platforms') {
    header('Content-Type: application/json');
    $sql = "SELECT id, name, status FROM compete_platforms ORDER BY created_at DESC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
    exit;
}

// [API] 添加项目
if ($action == 'add_project') {
    header('Content-Type: application/json');
    $name = $_POST['name'] ?? '';
    if (empty($name)) {
        echo json_encode(['status' => 'error', 'msg' => '项目名称不能为空']);
        exit;
    }
    try {
        $sql = "INSERT INTO compete_projects (name) VALUES (?)";
        $pdo->prepare($sql)->execute([$name]);
        echo json_encode(['status' => 'success', 'msg' => '项目添加成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '项目名称已存在']);
    }
    exit;
}

// [API] 添加平台
if ($action == 'add_platform') {
    header('Content-Type: application/json');
    $name = $_POST['name'] ?? '';
    if (empty($name)) {
        echo json_encode(['status' => 'error', 'msg' => '平台名称不能为空']);
        exit;
    }
    try {
        $sql = "INSERT INTO compete_platforms (name) VALUES (?)";
        $pdo->prepare($sql)->execute([$name]);
        echo json_encode(['status' => 'success', 'msg' => '平台添加成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '平台名称已存在']);
    }
    exit;
}

// [API] 更新项目状态
if ($action == 'update_project_status') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? 0;
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '项目ID不能为空']);
        exit;
    }
    try {
        $sql = "UPDATE compete_projects SET status = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$status, $id]);
        echo json_encode(['status' => 'success', 'msg' => '状态更新成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '更新失败']);
    }
    exit;
}

// [API] 更新平台状态
if ($action == 'update_platform_status') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    $status = $_POST['status'] ?? 0;
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '平台ID不能为空']);
        exit;
    }
    try {
        $sql = "UPDATE compete_platforms SET status = ? WHERE id = ?";
        $pdo->prepare($sql)->execute([$status, $id]);
        echo json_encode(['status' => 'success', 'msg' => '状态更新成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '更新失败']);
    }
    exit;
}

// [API] 删除项目
if ($action == 'delete_project') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '项目ID不能为空']);
        exit;
    }
    try {
        $sql = "DELETE FROM compete_projects WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
        echo json_encode(['status' => 'success', 'msg' => '项目删除成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '删除失败，可能存在关联数据']);
    }
    exit;
}

// [API] 删除平台
if ($action == 'delete_platform') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    if (empty($id)) {
        echo json_encode(['status' => 'error', 'msg' => '平台ID不能为空']);
        exit;
    }
    try {
        $sql = "DELETE FROM compete_platforms WHERE id = ?";
        $pdo->prepare($sql)->execute([$id]);
        echo json_encode(['status' => 'success', 'msg' => '平台删除成功']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => '删除失败，可能存在关联数据']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据竞对管理</title>
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
                <h2 class="text-lg font-bold text-slate-800">数据竞对管理</h2>
            </div>
            <div class="flex items-center gap-2"><div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold"><?php echo substr($CURRENT_USER, 0, 1); ?></div><span class="text-sm font-bold"><?php echo $CURRENT_USER; ?></span></div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div class="space-y-6 fade-in">
                <!-- 项目管理 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-slate-800">项目管理</h3>
                        <button @click="showAddProjectModal = true" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-1">
                            <i class="fas fa-plus"></i> 添加项目
                        </button>
                    </div>
                    <div v-if="projects.length === 0" class="text-center py-10 text-gray-400">暂无项目</div>
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="project in projects" :key="project.id" class="bg-gray-50 rounded-xl p-4 hover:shadow-md transition">
                            <div class="flex justify-between items-start">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 font-bold">{{ typeof project.name === 'string' && project.name ? project.name.charAt(0) : '?' }}</div>
                                    <div class="ml-3">
                                        <div class="font-bold text-slate-800">{{ project.name }}</div>
                                        <div class="text-xs text-gray-400">{{ project.status ? '启用' : '禁用' }}</div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="toggleProjectStatus(project)" class="text-xs px-3 py-1 rounded-full font-bold" :class="project.status ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-600'">
                                        {{ project.status ? '禁用' : '启用' }}
                                    </button>
                                    <button @click="deleteProject(project.id)" class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full font-bold">
                                        删除
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- 平台管理 -->
                <div class="bg-white rounded-xl shadow-sm p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-slate-800">平台管理</h3>
                        <button @click="showAddPlatformModal = true" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 flex items-center gap-1">
                            <i class="fas fa-plus"></i> 添加平台
                        </button>
                    </div>
                    <div v-if="platforms.length === 0" class="text-center py-10 text-gray-400">暂无平台</div>
                    <div v-else class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                        <div v-for="platform in platforms" :key="platform.id" class="bg-gray-50 rounded-xl p-4 hover:shadow-md transition">
                            <div class="flex justify-between items-start">
                                <div class="flex items-center">
                                    <div class="w-10 h-10 rounded-full bg-blue-100 flex items-center justify-center text-blue-600 font-bold">{{ typeof platform.name === 'string' && platform.name ? platform.name.charAt(0) : '?' }}</div>
                                    <div class="ml-3">
                                        <div class="font-bold text-slate-800">{{ platform.name }}</div>
                                        <div class="text-xs text-gray-400">{{ platform.status ? '启用' : '禁用' }}</div>
                                    </div>
                                </div>
                                <div class="flex gap-2">
                                    <button @click="togglePlatformStatus(platform)" class="text-xs px-3 py-1 rounded-full font-bold" :class="platform.status ? 'bg-red-100 text-red-600' : 'bg-green-100 text-green-600'">
                                        {{ platform.status ? '禁用' : '启用' }}
                                    </button>
                                    <button @click="deletePlatform(platform.id)" class="text-xs bg-gray-100 text-gray-600 px-3 py-1 rounded-full font-bold">
                                        删除
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
    
    <!-- 添加项目模态框 -->
    <div v-if="showAddProjectModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl p-6 w-[450px] shadow-2xl">
            <h3 class="font-bold text-lg mb-4 text-slate-800">添加项目</h3>
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 block mb-2">项目名称</label>
                    <input v-model="newProjectName" type="text" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-3 text-sm outline-none" placeholder="请输入项目名称">
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="showAddProjectModal = false" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl font-bold shadow-sm text-sm">取消</button>
                <button @click="addProject" class="flex-1 bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm">确定</button>
            </div>
        </div>
    </div>
    
    <!-- 添加平台模态框 -->
    <div v-if="showAddPlatformModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl p-6 w-[450px] shadow-2xl">
            <h3 class="font-bold text-lg mb-4 text-slate-800">添加平台</h3>
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-slate-500 block mb-2">平台名称</label>
                    <input v-model="newPlatformName" type="text" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-3 text-sm outline-none" placeholder="请输入平台名称">
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="showAddPlatformModal = false" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl font-bold shadow-sm text-sm">取消</button>
                <button @click="addPlatform" class="flex-1 bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm">确定</button>
            </div>
        </div>
    </div>

</div>

<script>
const { createApp, ref, onMounted } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('compete_admin');
        const projects = ref([]);
        const platforms = ref([]);
        const showAddProjectModal = ref(false);
        const showAddPlatformModal = ref(false);
        const newProjectName = ref('');
        const newPlatformName = ref('');
        
        // 加载项目列表
        const loadProjects = async () => {
            const res = await fetch('?action=get_projects');
            const data = await res.json();
            projects.value = data;
        };
        
        // 加载平台列表
        const loadPlatforms = async () => {
            const res = await fetch('?action=get_platforms');
            const data = await res.json();
            platforms.value = data;
        };
        
        // 添加项目
        const addProject = async () => {
            if (!newProjectName.value.trim()) {
                alert('请输入项目名称');
                return;
            }
            
            const res = await fetch('?action=add_project', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `name=${encodeURIComponent(newProjectName.value.trim())}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                showAddProjectModal.value = false;
                newProjectName.value = '';
                loadProjects();
            } else {
                alert(data.msg);
            }
        };
        
        // 添加平台
        const addPlatform = async () => {
            if (!newPlatformName.value.trim()) {
                alert('请输入平台名称');
                return;
            }
            
            const res = await fetch('?action=add_platform', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `name=${encodeURIComponent(newPlatformName.value.trim())}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                showAddPlatformModal.value = false;
                newPlatformName.value = '';
                loadPlatforms();
            } else {
                alert(data.msg);
            }
        };
        
        // 切换项目状态
        const toggleProjectStatus = async (project) => {
            const newStatus = project.status ? 0 : 1;
            const res = await fetch('?action=update_project_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${project.id}&status=${newStatus}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                project.status = newStatus;
            } else {
                alert(data.msg);
            }
        };
        
        // 切换平台状态
        const togglePlatformStatus = async (platform) => {
            const newStatus = platform.status ? 0 : 1;
            const res = await fetch('?action=update_platform_status', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${platform.id}&status=${newStatus}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                platform.status = newStatus;
            } else {
                alert(data.msg);
            }
        };
        
        // 删除项目
        const deleteProject = async (id) => {
            if (!confirm('确定要删除这个项目吗？')) {
                return;
            }
            
            const res = await fetch('?action=delete_project', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                loadProjects();
            } else {
                alert(data.msg);
            }
        };
        
        // 删除平台
        const deletePlatform = async (id) => {
            if (!confirm('确定要删除这个平台吗？')) {
                return;
            }
            
            const res = await fetch('?action=delete_platform', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded'
                },
                body: `id=${id}`
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                loadPlatforms();
            } else {
                alert(data.msg);
            }
        };
        
        // 初始化
        onMounted(() => {
            loadProjects();
            loadPlatforms();
        });
        
        return {
            sidebarOpen,
            view,
            projects,
            platforms,
            showAddProjectModal,
            showAddPlatformModal,
            newProjectName,
            newPlatformName,
            addProject,
            addPlatform,
            toggleProjectStatus,
            togglePlatformStatus,
            deleteProject,
            deletePlatform
        };
    }
}).mount('#app');
</script>
</body>
</html>