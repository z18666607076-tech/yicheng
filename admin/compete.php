<?php
// compete.php - 数据竞对录入
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 0. 登录鉴权 ===
if (!isset($_SESSION['agent_id'])) { header('Location: login.php'); exit; }
$CURRENT_USER = $_SESSION['agent_name'] ?? '用户';

// === 配置 ===
$COMMISSION_RATE = 0.03; 

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

$action = $_GET['action'] ?? 'view';

// [API] 获取项目列表
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $sql = "SELECT id, name FROM compete_projects WHERE status = 1 ORDER BY created_at DESC";
    $stmt = $pdo->query($sql);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); 
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

// [API] 提交竞对数据
if ($action == 'submit_compete_data') {
    header('Content-Type: application/json');
    
    // 获取 JSON 输入流
    $json = file_get_contents('php://input');
    $data_input = json_decode($json, true);
    
    // 从解析后的 JSON 中提取数据
    $date = $data_input['date'] ?? '';
    $project_id = $data_input['project_id'] ?? 0;
    $platforms = $data_input['platforms'] ?? [];
    
    // 修正校验逻辑：数据为 0 是允许的
    if (empty($date) || empty($project_id) || empty($platforms)) {
        echo json_encode(['status' => 'error', 'msg' => '请填写完整信息']);
        exit;
    }
    
    try {
        $pdo->beginTransaction();
        
        foreach ($platforms as $platform) {
            $platform_id = $platform['platform_id'] ?? 0;
            // 使用 null 合并运算符确保 0 被正确处理
            $visits = isset($platform['visits']) ? (int)$platform['visits'] : 0;
            $deals = isset($platform['deals']) ? (int)$platform['deals'] : 0;
            $locks = isset($platform['locks']) ? (int)$platform['locks'] : 0;
            
            if (empty($platform_id)) continue;
            
            $checkSql = "SELECT id FROM compete_data WHERE project_id = ? AND date = ? AND platform_id = ?";
            $checkStmt = $pdo->prepare($checkSql);
            $checkStmt->execute([$project_id, $date, $platform_id]);
            $existingId = $checkStmt->fetchColumn();
            
            if ($existingId) {
                $updateSql = "UPDATE compete_data SET visits = ?, deals = ?, locks = ? WHERE id = ?";
                $pdo->prepare($updateSql)->execute([$visits, $deals, $locks, $existingId]);
            } else {
                $insertSql = "INSERT INTO compete_data (project_id, date, platform_id, visits, deals, locks) VALUES (?, ?, ?, ?, ?, ?)";
                $pdo->prepare($insertSql)->execute([$project_id, $date, $platform_id, $visits, $deals, $locks]);
            }
        }
        
        $pdo->commit();
        echo json_encode(['status' => 'success', 'msg' => '数据提交成功']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => '提交失败：' . $e->getMessage()]);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>数据竞对录入</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .glass-nav { background: rgba(255,255,255,0.95); backdrop-filter: blur(12px); border-top: 1px solid #f1f5f9; padding-bottom: env(safe-area-inset-bottom); }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
        .purple-gradient { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
    </style>
</head>
<body>
<div id="app" class="max-w-md mx-auto min-h-screen pb-24 relative bg-gray-50">
    
    <div class="sticky top-0 z-40 bg-white/95 backdrop-blur p-4 shadow-sm flex justify-between items-center">
        <h2 class="font-bold text-lg text-slate-800">数据竞对</h2>
        <div class="flex gap-2">
            <a href="compete_admin.php" class="text-xs text-slate-400 bg-slate-100 px-2 py-1.5 rounded flex items-center">管理 <i class="fas fa-angle-right ml-1"></i></a>
        </div>
    </div>

    <div class="p-4 space-y-5">
        
        <!-- 日期选择 -->
        <div class="bg-white rounded-2xl card-shadow p-4">
            <div class="flex justify-between items-center">
                <label class="text-sm font-bold text-slate-800">选择日期</label>
                <input v-model="form.date" type="date" class="text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
            </div>
        </div>
        
        <!-- 项目选择 -->
        <div class="bg-white rounded-2xl card-shadow p-4">
            <div class="flex justify-between items-center">
                <label class="text-sm font-bold text-slate-800">项目名称</label>
                <select v-model="form.project_id" class="text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                    <option value="">请选择项目</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                </select>
            </div>
        </div>
        
        <!-- 平台数据 -->
        <div class="bg-white rounded-2xl card-shadow p-4">
            <div v-for="(platform, index) in form.platforms" :key="index" class="mb-4 p-3 border border-gray-200 rounded-xl">
                <div class="flex justify-between items-center mb-3">
                    <label class="text-sm font-bold text-slate-800">{{ index + 1 }}. 请选择平台</label>
                    <button @click="removePlatform(index)" class="text-gray-400 hover:text-red-500">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
                <div class="mb-3">
                    <select v-model="platform.platform_id" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                        <option value="">请选择平台</option>
                        <option v-for="p in platforms" :key="p.id" :value="p.id">{{ p.name }}</option>
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div>
                        <label class="text-xs font-bold text-slate-500 block mb-1">来访</label>
                        <input v-model.number="platform.visits" type="number" min="0" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none" placeholder="请输入">
                    </div>
                    <div>
                        <label class="text-xs font-bold text-slate-500 block mb-1">成交</label>
                        <input v-model.number="platform.deals" type="number" min="0" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none" placeholder="请输入">
                    </div>
                </div>
                <div>
                    <label class="text-xs font-bold text-slate-500 block mb-1">锁筹</label>
                    <input v-model.number="platform.locks" type="number" min="0" class="w-full text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none" placeholder="请输入">
                </div>
            </div>
            
            <!-- 添加平台按钮 -->
            <button @click="addPlatform" class="w-full flex items-center justify-center gap-2 p-4 border-2 border-dashed border-gray-200 rounded-xl text-gray-500 hover:border-purple-500 hover:text-purple-500 transition-colors">
                <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center text-purple-600">
                    <i class="fas fa-plus"></i>
                </div>
                <span class="font-bold">添加</span>
            </button>
        </div>
        
        <!-- 提交按钮 -->
        <button @click="submitData" class="w-full bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">
            提交数据
        </button>
        
    </div>
    
</div>

<script>
const { createApp, ref, onMounted } = Vue;
createApp({
    setup() {
        const form = ref({
            date: new Date().toISOString().split('T')[0],
            project_id: '',
            platforms: [{
                platform_id: '',
                visits: 0,
                deals: 0,
                locks: 0
            }]
        });
        
        const projects = ref([]);
        const platforms = ref([]);
        
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
        
        // 添加平台
        const addPlatform = () => {
            form.value.platforms.push({
                platform_id: '',
                visits: 0,
                deals: 0,
                locks: 0
            });
        };
        
        // 移除平台
        const removePlatform = (index) => {
            if (form.value.platforms.length > 1) {
                form.value.platforms.splice(index, 1);
            } else {
                alert('至少需要一个平台');
            }
        };
        
        // 提交数据
        const submitData = async () => {
            // 验证表单
            if (!form.value.date) {
                alert('请选择日期');
                return;
            }
            
            if (!form.value.project_id) {
                alert('请选择项目');
                return;
            }
            
            const validPlatforms = form.value.platforms.filter(p => p.platform_id);
            if (validPlatforms.length === 0) {
                alert('请至少选择一个平台');
                return;
            }
            
            // 提交数据
            const res = await fetch('?action=submit_compete_data', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    date: form.value.date,
                    project_id: form.value.project_id,
                    platforms: form.value.platforms
                })
            });
            
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                // 重置表单
                form.value = {
                    date: new Date().toISOString().split('T')[0],
                    project_id: '',
                    platforms: [{
                        platform_id: '',
                        visits: 0,
                        deals: 0,
                        locks: 0
                    }]
                };
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
            form,
            projects,
            platforms,
            addPlatform,
            removePlatform,
            submitData
        };
    }
}).mount('#app');
</script>
</body>
</html>