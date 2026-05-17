<?php
// admin_manage_admins.php - 管理员账号管理页面
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库配置 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

require_once __DIR__ . '/menu_config_helper.php';
require_once __DIR__ . '/includes/admin_menu_permissions.php';
require_once __DIR__ . '/../includes/agent_roles.php';
admin_menu_ensure_column($pdo);
agent_roles_ensure_column($pdo);

if (isset($_GET['action']) && $_GET['action'] === 'admin_menu_matrix') {
    header('Content-Type: application/json; charset=utf-8');
    $links = admin_menu_collect_link_entries(menu_load_config());
    $jAdmin = agent_roles_json_for_sql_contains('admin');
    $stmt = $pdo->prepare("SELECT id, username, admin_menu_allow_ids FROM agents WHERE is_deleted = 0 AND (role = 'admin' OR (JSON_VALID(roles_json) AND JSON_CONTAINS(roles_json, ?, '$'))) ORDER BY id DESC");
    $stmt->execute([$jAdmin]);
    $admins = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $admins[] = [
            'id' => (int)$r['id'],
            'username' => (string)($r['username'] ?? ''),
            'allow_raw' => $r['admin_menu_allow_ids'] ?? null,
        ];
    }
    echo json_encode(['ok' => true, 'links' => $links, 'admins' => $admins], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (is_string($ct) && stripos($ct, 'application/json') !== false) {
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (is_array($in) && ($in['action'] ?? '') === 'save_admin_menu') {
            header('Content-Type: application/json; charset=utf-8');
            $targetId = (int)($in['admin_id'] ?? 0);
            $menuIdsIn = $in['menu_ids'] ?? null;
            if ($targetId <= 0 || !is_array($menuIdsIn)) {
                echo json_encode(['ok' => false, 'msg' => '参数错误'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $jAdmin = agent_roles_json_for_sql_contains('admin');
            $chk = $pdo->prepare("SELECT id FROM agents WHERE id = ? AND is_deleted = 0 AND (role = 'admin' OR (JSON_VALID(roles_json) AND JSON_CONTAINS(roles_json, ?, '$'))) LIMIT 1");
            $chk->execute([$targetId, $jAdmin]);
            if (!$chk->fetchColumn()) {
                echo json_encode(['ok' => false, 'msg' => '无效的管理员'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            $known = admin_menu_all_link_ids(menu_load_config());
            $knownSet = array_fill_keys($known, true);
            $clean = [];
            foreach ($menuIdsIn as $mid) {
                $s = trim((string)$mid);
                if ($s !== '' && isset($knownSet[$s])) {
                    $clean[$s] = true;
                }
            }
            $cleanList = array_keys($clean);
            sort($cleanList);
            $allSorted = $known;
            sort($allSorted);
            if ($cleanList === $allSorted) {
                $enc = null;
            } else {
                $enc = admin_menu_encode_allowed_ids($cleanList);
            }
            $jAdmin = agent_roles_json_for_sql_contains('admin');
            $upd = $pdo->prepare("UPDATE agents SET admin_menu_allow_ids = :j WHERE id = :id AND is_deleted = 0 AND (role = 'admin' OR (JSON_VALID(roles_json) AND JSON_CONTAINS(roles_json, :adm, '$')))");
            $upd->bindValue(':j', $enc, $enc === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $upd->bindValue(':id', $targetId, PDO::PARAM_INT);
            $upd->bindValue(':adm', $jAdmin, PDO::PARAM_STR);
            try {
                $upd->execute();
            } catch (Throwable $e) {
                echo json_encode(['ok' => false, 'msg' => '保存失败'], JSON_UNESCAPED_UNICODE);
                exit;
            }
            echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

// === 处理表单提交 ===
$message = '';
$message_type = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_admin'])) {
        // 创建新管理员
        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';
        $phone = $_POST['phone'] ?? '';
        $department_id = $_POST['department_id'] ?? 0;
        
        if (!empty($username) && !empty($password) && !empty($phone)) {
            // 检查用户名是否已存在
            $stmt = $pdo->prepare("SELECT * FROM agents WHERE username = ? AND is_deleted = 0");
            $stmt->execute([$username]);
            if ($stmt->fetch(PDO::FETCH_ASSOC)) {
                $message = '用户名已存在';
                $message_type = 'error';
            } else {
                // 插入新管理员
                $rolesJson = json_encode(['admin'], JSON_UNESCAPED_UNICODE);
                $stmt = $pdo->prepare("INSERT INTO agents (username, password, phone, role, roles_json, department_id, is_deleted) VALUES (?, ?, ?, 'admin', ?, ?, 0)");
                if ($stmt->execute([$username, $password, $phone, $rolesJson, $department_id])) {
                    $message = '管理员账号创建成功';
                    $message_type = 'success';
                } else {
                    $message = '创建失败，请重试';
                    $message_type = 'error';
                }
            }
        } else {
            $message = '请填写所有必填字段';
            $message_type = 'error';
        }
    } elseif (isset($_POST['delete_admin'])) {
        // 删除管理员
        $admin_id = $_POST['admin_id'] ?? 0;
        
        // 不能删除当前登录的管理员
        if ($admin_id == $_SESSION['admin_id']) {
            $message = '不能删除当前登录的管理员';
            $message_type = 'error';
        } else {
            $jAdmin = agent_roles_json_for_sql_contains('admin');
            $stmt = $pdo->prepare("UPDATE agents SET is_deleted = 1 WHERE id = ? AND is_deleted = 0 AND (role = 'admin' OR (JSON_VALID(roles_json) AND JSON_CONTAINS(roles_json, ?, '$')))");
            if ($stmt->execute([$admin_id, $jAdmin])) {
                $message = '管理员账号删除成功';
                $message_type = 'success';
            } else {
                $message = '删除失败，请重试';
                $message_type = 'error';
            }
        }
    }
}

// === 获取管理员列表（与「内部架构-人员」同一 agents 表，无单独库表；改密码请在 admin_agent_edit 编辑该人员）===
$jAdmin = agent_roles_json_for_sql_contains('admin');
$stmt = $pdo->prepare(
    "SELECT a.*, d.name AS dept_name FROM agents a
     LEFT JOIN departments d ON d.id = a.department_id
     WHERE a.is_deleted = 0 AND (a.role = 'admin' OR (JSON_VALID(a.roles_json) AND JSON_CONTAINS(a.roles_json, ?, '$')))
     ORDER BY a.id DESC"
);
$stmt->execute([$jAdmin]);
$admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

// === 获取部门列表 ===
$stmt = $pdo->query("SELECT * FROM departments ORDER BY id ASC");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>管理员账号管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-4 sm:px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-3 min-w-0">
                <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 p-2 -ml-1 rounded-lg text-gray-600 hover:bg-gray-100" aria-label="打开菜单">
                    <i class="fas fa-bars text-lg"></i>
                </button>
                <h2 class="text-lg font-bold text-slate-800 truncate">管理员账号管理</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">当前管理员: <?php echo $_SESSION['admin_name']; ?></span>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <!-- 消息提示 -->
            <?php if (!empty($message)): ?>
                <div class="mb-6 p-4 rounded-lg <?php echo $message_type == 'success' ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'; ?>">
                    <div class="flex items-center">
                        <i class="fas <?php echo $message_type == 'success' ? 'fa-check-circle' : 'fa-exclamation-circle'; ?> mr-2"></i>
                        <span><?php echo $message; ?></span>
                    </div>
                </div>
            <?php endif; ?>

            <!-- 创建新管理员 -->
            <div class="bg-white p-6 rounded-xl shadow-sm mb-8 border border-gray-100">
                <h3 class="text-lg font-bold text-slate-800 mb-4">创建新管理员</h3>
                <form method="post" class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">用户名 <span class="text-red-500">*</span></label>
                            <input type="text" name="username" class="w-full border rounded p-2 text-sm outline-none focus:ring-2 focus:ring-blue-100" placeholder="请输入用户名" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">密码 <span class="text-red-500">*</span></label>
                            <input type="password" name="password" class="w-full border rounded p-2 text-sm outline-none focus:ring-2 focus:ring-blue-100" placeholder="请输入密码" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">手机号 <span class="text-red-500">*</span></label>
                            <input type="tel" name="phone" class="w-full border rounded p-2 text-sm outline-none focus:ring-2 focus:ring-blue-100" placeholder="请输入手机号" required>
                        </div>
                        <div>
                            <label class="block text-xs font-bold text-gray-500 mb-1">所属部门</label>
                            <select name="department_id" class="w-full border rounded p-2 text-sm outline-none focus:ring-2 focus:ring-blue-100 bg-white">
                                <option value="0">无部门</option>
                                <?php foreach ($departments as $dept): ?>
                                    <option value="<?php echo $dept['id']; ?>"><?php echo $dept['name']; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button type="submit" name="create_admin" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700 transition shadow-lg">
                            <i class="fas fa-plus mr-1"></i> 创建管理员
                        </button>
                    </div>
                </form>
            </div>

            <!-- 管理员列表 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="border-b border-gray-200 px-6 py-4 space-y-2">
                    <h3 class="text-lg font-bold text-slate-800">管理员列表</h3>
                    <p class="text-xs text-slate-500 leading-relaxed">与「内部架构」中 <span class="font-semibold text-slate-700">系统角色 = 管理员</span> 为同一批账号；登录后台请使用下表 <span class="font-semibold">用户名或手机号 + 登录密码</span>（与人员编辑里保存的密码一致）。修改姓名/手机/密码请到 <a href="admin_agents.php" class="text-blue-600 hover:underline">内部架构</a> 编辑对应人员。</p>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                            <tr>
                                <th class="px-6 py-3">ID</th>
                                <th class="px-6 py-3">用户名</th>
                                <th class="px-6 py-3">手机号</th>
                                <th class="px-6 py-3">登录密码</th>
                                <th class="px-6 py-3">所属部门</th>
                                <th class="px-6 py-3">在职</th>
                                <th class="px-6 py-3">菜单权限</th>
                                <th class="px-6 py-3">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php if (count($admins) > 0): ?>
                                <?php foreach ($admins as $admin): ?>
                                    <?php
                                    $dept_name = trim((string)($admin['dept_name'] ?? ''));
                                    if ($dept_name === '') {
                                        $dept_name = '无部门';
                                    }
                                    $emp = isset($admin['employment_status']) ? (int)$admin['employment_status'] : 1;
                                    $pwd_show = (string)($admin['password'] ?? '');
                                    ?>
                                    <tr class="hover:bg-slate-50 transition">
                                        <td class="px-6 py-4 font-medium text-gray-900"><?php echo (int)$admin['id']; ?></td>
                                        <td class="px-6 py-4 font-medium text-gray-900"><?php echo htmlspecialchars((string)$admin['username'], ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-6 py-4 font-mono"><?php echo htmlspecialchars((string)($admin['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-6 py-4 font-mono text-slate-700"><?php echo htmlspecialchars($pwd_show, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-6 py-4"><?php echo htmlspecialchars($dept_name, ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-6 py-4"><?php echo $emp === 1 ? '<span class="text-green-600 font-semibold">在职</span>' : '<span class="text-gray-400">离职</span>'; ?></td>
                                        <td class="px-6 py-4 whitespace-nowrap">
                                            <button type="button" @click="openMenuModal(<?php echo (int)$admin['id']; ?>)" class="text-indigo-600 hover:underline text-xs font-bold">
                                                <i class="fas fa-list-check mr-1"></i>配置菜单
                                            </button>
                                        </td>
                                        <td class="px-6 py-4 text-right whitespace-nowrap">
                                            <a href="admin_agent_edit.php?id=<?php echo (int)$admin['id']; ?>" target="_blank" class="text-blue-600 hover:underline text-xs font-bold mr-3">编辑人员</a>
                                            <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                                <form method="post" class="inline-block">
                                                    <input type="hidden" name="admin_id" value="<?php echo (int)$admin['id']; ?>">
                                                    <button type="submit" name="delete_admin" class="text-red-600 hover:bg-red-50 px-2 py-1 rounded font-bold text-xs transition border border-transparent hover:border-red-200" onclick="return confirm('确定要删除此管理员吗？');">
                                                        <i class="fas fa-trash mr-1"></i> 删除
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <span class="text-gray-400 text-xs">当前账号</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr>
                                    <td colspan="8" class="px-6 py-10 text-center text-gray-400">
                                        暂无管理员账号
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <teleport to="body">
        <div v-if="menuModalOpen" class="fixed inset-0 z-[100] flex items-end sm:items-center justify-center p-0 sm:p-4 bg-black/50" @click.self="menuModalOpen = false">
            <div class="bg-white rounded-t-2xl sm:rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] flex flex-col" @click.stop>
                <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between gap-2 shrink-0">
                    <div class="min-w-0">
                        <div class="text-sm font-bold text-slate-800 truncate">侧栏菜单可见项</div>
                        <div class="text-xs text-slate-500 truncate" v-if="menuModalAdmin">管理员：{{ menuModalAdmin.username }}（ID {{ menuModalAdmin.id }}）</div>
                    </div>
                    <button type="button" class="p-2 rounded-lg text-slate-400 hover:bg-slate-100" @click="menuModalOpen = false" aria-label="关闭">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <p class="px-4 py-2 text-xs text-amber-800 bg-amber-50 border-b border-amber-100 shrink-0">未勾选的项目将不在左侧菜单显示；全部勾选并保存等同于「全部可见」（与默认一致）。仅影响菜单展示，不替代页面级权限校验。</p>
                <div class="px-4 py-2 flex gap-2 shrink-0 border-b border-gray-100">
                    <button type="button" @click="menuSelectAll" class="text-xs font-bold px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200">全选</button>
                    <button type="button" @click="menuSelectNone" class="text-xs font-bold px-2 py-1 rounded bg-slate-100 text-slate-700 hover:bg-slate-200">全不选</button>
                </div>
                <div class="flex-1 min-h-0 overflow-y-auto px-4 py-3 space-y-2">
                    <div v-if="menuLoadError" class="text-sm text-red-600">{{ menuLoadError }}</div>
                    <div v-else-if="menuLinks.length === 0 && !menuLoading" class="text-sm text-gray-500">暂无菜单项（请先在「菜单配置」维护菜单）。</div>
                    <template v-else>
                        <label v-for="row in menuLinks" :key="row.id" class="flex items-start gap-3 p-2 rounded-lg border border-slate-100 hover:bg-slate-50 cursor-pointer">
                            <input type="checkbox" class="mt-0.5 rounded border-gray-300 text-indigo-600" :checked="menuSelected[row.id]" @change="toggleMenuId(row.id, $event.target.checked)">
                            <span class="text-sm text-slate-800 leading-snug">{{ row.label }}</span>
                        </label>
                    </template>
                </div>
                <div class="px-4 py-3 border-t border-gray-100 flex justify-end gap-2 shrink-0">
                    <button type="button" @click="menuModalOpen = false" class="px-4 py-2 rounded-lg border border-gray-200 text-sm font-bold text-gray-600 hover:bg-gray-50">取消</button>
                    <button type="button" @click="saveMenuModal" :disabled="menuSaving || menuLoading" class="px-4 py-2 rounded-lg bg-indigo-600 text-white text-sm font-bold hover:bg-indigo-700 disabled:opacity-50">
                        {{ menuSaving ? '保存中…' : '保存' }}
                    </button>
                </div>
            </div>
        </div>
    </teleport>
</div>

<script>
const { createApp, ref, reactive, computed } = Vue;

function parseAllowRaw(raw) {
    if (raw == null || String(raw).trim() === '') return null;
    try {
        const a = JSON.parse(raw);
        return Array.isArray(a) ? a.map(String) : null;
    } catch (e) {
        return null;
    }
}

createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('admin_manage');

        const menuModalOpen = ref(false);
        const menuLoading = ref(false);
        const menuSaving = ref(false);
        const menuLoadError = ref('');
        const menuLinks = ref([]);
        const menuAdmins = ref([]);
        const menuModalAdminId = ref(null);
        const menuSelected = reactive({});

        const menuModalAdmin = computed(() => menuAdmins.value.find((a) => a.id === menuModalAdminId.value) || null);

        const ensureMatrixLoaded = async () => {
            if (menuLinks.value.length > 0) return;
            menuLoading.value = true;
            menuLoadError.value = '';
            try {
                const r = await fetch('admin_manage_admins.php?action=admin_menu_matrix');
                const d = await r.json();
                if (!d.ok) {
                    menuLoadError.value = d.msg || '加载失败';
                    return;
                }
                menuLinks.value = Array.isArray(d.links) ? d.links : [];
                menuAdmins.value = Array.isArray(d.admins) ? d.admins : [];
            } catch (e) {
                menuLoadError.value = '网络错误';
            } finally {
                menuLoading.value = false;
            }
        };

        const syncSelectionFromAdmin = (admin) => {
            const ids = parseAllowRaw(admin.allow_raw);
            const allIds = menuLinks.value.map((x) => x.id);
            Object.keys(menuSelected).forEach((k) => { delete menuSelected[k]; });
            if (ids === null) {
                allIds.forEach((id) => { menuSelected[id] = true; });
            } else {
                const set = new Set(ids);
                allIds.forEach((id) => { menuSelected[id] = set.has(id); });
            }
        };

        const openMenuModal = async (adminId) => {
            await ensureMatrixLoaded();
            menuModalAdminId.value = adminId;
            const admin = menuAdmins.value.find((a) => a.id === adminId);
            if (admin) syncSelectionFromAdmin(admin);
            menuModalOpen.value = true;
        };

        const toggleMenuId = (id, on) => {
            if (on) menuSelected[id] = true;
            else delete menuSelected[id];
        };

        const menuSelectAll = () => {
            menuLinks.value.forEach((row) => { menuSelected[row.id] = true; });
        };

        const menuSelectNone = () => {
            menuLinks.value.forEach((row) => { delete menuSelected[row.id]; });
        };

        const saveMenuModal = async () => {
            if (!menuModalAdminId.value) return;
            menuSaving.value = true;
            try {
                const picked = menuLinks.value.filter((row) => menuSelected[row.id]).map((row) => row.id);
                const r = await fetch('admin_manage_admins.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'save_admin_menu',
                        admin_id: menuModalAdminId.value,
                        menu_ids: picked,
                    }),
                });
                const d = await r.json();
                if (!d.ok) {
                    alert(d.msg || '保存失败');
                    return;
                }
                const adm = menuAdmins.value.find((a) => a.id === menuModalAdminId.value);
                if (adm) {
                    const allIds = menuLinks.value.map((x) => x.id).sort();
                    const ps = [...picked].sort();
                    const isFull = allIds.length === ps.length && allIds.every((id, i) => id === ps[i]);
                    adm.allow_raw = isFull ? null : JSON.stringify(picked);
                }
                menuModalOpen.value = false;
                alert('已保存。该账号重新加载任意后台页后生效。');
            } catch (e) {
                alert('网络错误');
            } finally {
                menuSaving.value = false;
            }
        };

        return {
            sidebarOpen,
            view,
            menuModalOpen,
            menuLoading,
            menuSaving,
            menuLoadError,
            menuLinks,
            menuModalAdmin,
            menuSelected,
            openMenuModal,
            toggleMenuId,
            menuSelectAll,
            menuSelectNone,
            saveMenuModal,
        };
    },
}).mount('#app');
</script>
</body>
</html>