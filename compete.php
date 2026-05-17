<?php
// compete.php - 前端数据竞对录入（独立于后台）
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 0. 登录鉴权 ===
if (!isset($_SESSION['agent_id'])) { header('Location: login.php'); exit; }
$CURRENT_USER = $_SESSION['agent_name'] ?? '用户';

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

// [API] 手动新增项目
if ($action == 'add_project') {
    header('Content-Type: application/json');
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
    $name = trim($input['name'] ?? '');
    if ($name === '') {
        echo json_encode(['status' => 'error', 'msg' => '项目名称不能为空']);
        exit;
    }
    if (mb_strlen($name, 'UTF-8') > 100) {
        echo json_encode(['status' => 'error', 'msg' => '项目名称过长']);
        exit;
    }

    $checkStmt = $pdo->prepare("SELECT id, status FROM compete_projects WHERE name = ? LIMIT 1");
    $checkStmt->execute([$name]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if (intval($existing['status']) !== 1) {
            $pdo->prepare("UPDATE compete_projects SET status = 1 WHERE id = ?")->execute([$existing['id']]);
        }
        echo json_encode(['status' => 'success', 'msg' => '项目已存在，已启用', 'project' => ['id' => intval($existing['id']), 'name' => $name]]);
        exit;
    }

    $stmt = $pdo->prepare("INSERT INTO compete_projects (name, status, created_at) VALUES (?, 1, NOW())");
    $stmt->execute([$name]);
    $id = intval($pdo->lastInsertId());
    echo json_encode(['status' => 'success', 'msg' => '项目新增成功', 'project' => ['id' => $id, 'name' => $name]]);
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

    $json = file_get_contents('php://input');
    $data_input = json_decode($json, true);

    $date = $data_input['date'] ?? '';
    $project_id = $data_input['project_id'] ?? 0;
    $platforms = $data_input['platforms'] ?? [];

    if (empty($date) || empty($project_id) || empty($platforms)) {
        echo json_encode(['status' => 'error', 'msg' => '请填写完整信息']);
        exit;
    }

    try {
        $pdo->beginTransaction();

        foreach ($platforms as $platform) {
            $platform_id = $platform['platform_id'] ?? 0;
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
    <title>前端数据录入</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .glass-nav { background: rgba(255,255,255,0.98); border-top: 1px solid #f1f5f9; padding-bottom: env(safe-area-inset-bottom); }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
<div id="app" class="max-w-md mx-auto min-h-screen pb-24 relative bg-gray-50">
    <div class="sticky top-0 z-40 bg-white/95 backdrop-blur p-4 shadow-sm flex justify-between items-center">
        <button type="button" @click="goBack" class="text-xs text-slate-500 bg-slate-100 px-2 py-1.5 rounded flex items-center gap-1">
            <i class="fas fa-chevron-left"></i> 返回上一层
        </button>
        <h2 class="font-bold text-lg text-slate-800">前端数据录入</h2>
        <a href="logout.php" class="text-xs text-slate-500 bg-slate-100 px-2 py-1.5 rounded flex items-center gap-1">
            <i class="fas fa-sign-out-alt"></i> 退出
        </a>
    </div>

    <div class="p-4 space-y-5">
        <div class="bg-white rounded-2xl card-shadow p-4">
            <div class="flex justify-between items-center">
                <label class="text-sm font-bold text-slate-800">选择日期</label>
                <input v-model="form.date" type="date" class="text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
            </div>
        </div>

        <div class="bg-white rounded-2xl card-shadow p-4">
            <div class="flex justify-between items-center mb-3">
                <label class="text-sm font-bold text-slate-800">项目名称</label>
                <select v-model="form.project_id" class="text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                    <option value="">请选择项目</option>
                    <option v-for="project in projects" :key="project.id" :value="project.id">{{ project.name }}</option>
                </select>
            </div>
            <div class="flex items-center gap-2">
                <input v-model.trim="newProjectName" @keyup.enter="addProject" type="text" placeholder="手动输入新项目名称" class="flex-1 text-sm text-slate-600 bg-slate-50 border border-gray-200 rounded-lg p-2 outline-none">
                <button @click="addProject" :disabled="addingProject" class="text-xs bg-purple-600 text-white px-3 py-2 rounded-lg font-bold disabled:opacity-50 disabled:cursor-not-allowed">
                    {{ addingProject ? '添加中' : '手动增加' }}
                </button>
            </div>
        </div>

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

            <button @click="addPlatform" class="w-full flex items-center justify-center gap-2 p-4 border-2 border-dashed border-gray-200 rounded-xl text-gray-500 hover:border-purple-500 hover:text-purple-500 transition-colors">
                <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center text-purple-600">
                    <i class="fas fa-plus"></i>
                </div>
                <span class="font-bold">添加平台</span>
            </button>
        </div>

        <button @click="submitData" class="w-full bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">
            提交数据
        </button>
    </div>

    <div class="fixed bottom-0 w-full max-w-md bg-white border-t border-slate-100 flex justify-around py-3 text-[10px] text-gray-400 z-50 glass-nav shadow-[0_-5px_15px_rgba(0,0,0,0.02)]">
        <a href="compete.php" class="text-purple-600 font-bold flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-chart-line text-lg"></i><span>数据</span></a>
        <a href="agent.php" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-pen-to-square text-lg"></i><span>报备</span></a>
        <a href="staff.php" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-check-double text-lg"></i><span>工作台</span></a>
        <a href="agent.php" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-clock-rotate-left text-lg"></i><span>记录</span></a>
    </div>
</div>

<script>
const { createApp, ref, onMounted } = Vue;
createApp({
    setup() {
        const form = ref({
            date: new Date().toISOString().split('T')[0],
            project_id: '',
            platforms: [{ platform_id: '', visits: 0, deals: 0, locks: 0 }]
        });

        const projects = ref([]);
        const platforms = ref([]);
        const newProjectName = ref('');
        const addingProject = ref(false);

        const loadProjects = async () => {
            const res = await fetch('?action=get_projects');
            projects.value = await res.json();
        };

        const loadPlatforms = async () => {
            const res = await fetch('?action=get_platforms');
            platforms.value = await res.json();
        };

        const addPlatform = () => {
            form.value.platforms.push({ platform_id: '', visits: 0, deals: 0, locks: 0 });
        };

        const removePlatform = (index) => {
            if (form.value.platforms.length > 1) form.value.platforms.splice(index, 1);
            else alert('至少需要一个平台');
        };

        const submitData = async () => {
            if (!form.value.date) return alert('请选择日期');
            if (!form.value.project_id) return alert('请选择项目');
            const validPlatforms = form.value.platforms.filter(p => p.platform_id);
            if (validPlatforms.length === 0) return alert('请至少选择一个平台');

            const res = await fetch('?action=submit_compete_data', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    date: form.value.date,
                    project_id: form.value.project_id,
                    platforms: form.value.platforms
                })
            });
            const data = await res.json();
            if (data.status === 'success') {
                alert(data.msg);
                form.value = {
                    date: new Date().toISOString().split('T')[0],
                    project_id: '',
                    platforms: [{ platform_id: '', visits: 0, deals: 0, locks: 0 }]
                };
            } else {
                alert(data.msg);
            }
        };

        const addProject = async () => {
            const name = (newProjectName.value || '').trim();
            if (!name) return alert('请输入项目名称');
            addingProject.value = true;
            try {
                const res = await fetch('?action=add_project', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name })
                });
                const data = await res.json();
                if (data.status === 'success' && data.project) {
                    const exists = projects.value.some(p => String(p.id) === String(data.project.id));
                    if (!exists) {
                        projects.value.unshift(data.project);
                    }
                    form.value.project_id = data.project.id;
                    newProjectName.value = '';
                    alert(data.msg || '项目新增成功');
                } else {
                    alert(data.msg || '新增失败');
                }
            } catch (e) {
                alert('新增失败，请重试');
            } finally {
                addingProject.value = false;
            }
        };

        const goBack = () => {
            if (window.history.length > 1) window.history.back();
            else window.location.href = 'staff.php';
        };

        onMounted(() => {
            loadProjects();
            loadPlatforms();
        });

        return { form, projects, platforms, newProjectName, addingProject, addProject, addPlatform, removePlatform, submitData, goBack };
    }
}).mount('#app');
</script>
</body>
</html>
