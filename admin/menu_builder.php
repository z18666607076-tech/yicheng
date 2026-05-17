<?php
// menu_builder.php - 后台菜单配置器（支持一二级拖拽）
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/menu_config_helper.php';
$action = $_GET['action'] ?? '';

if ($action === 'get_menu') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'success', 'data' => menu_load_config()], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'save_menu') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $body = json_decode($raw, true);
    $data = $body['data'] ?? null;
    if (!is_array($data)) {
        echo json_encode(['status' => 'error', 'msg' => '菜单数据格式错误'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    [$ok, $msg] = menu_save_config($data);
    echo json_encode(['status' => $ok ? 'success' : 'error', 'msg' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>菜单配置</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" />
    <style>
        .drag-ghost { opacity: .45; background: #dbeafe !important; }
        .drag-chosen { cursor: grabbing !important; }
    </style>
</head>
<body class="bg-slate-50 min-h-screen text-slate-700">
<div id="app" class="flex h-screen min-h-0 overflow-hidden md:h-auto md:min-h-screen md:overflow-visible">
    <?php include 'sidebar.php'; ?>
    <main class="flex-1 flex flex-col min-w-0 min-h-0 overflow-hidden p-4 md:p-6">
        <div class="bg-white rounded-xl shadow border border-slate-200 p-4 md:p-6 flex flex-col min-h-0 min-w-0 overflow-hidden flex-1">
            <div class="flex flex-col gap-3 mb-6 shrink-0">
                <div class="flex items-center gap-2 min-w-0">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 p-2 -ml-1 rounded-lg text-slate-600 hover:bg-slate-100" aria-label="打开菜单">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                    <div class="min-w-0 flex-1">
                        <h1 class="text-xl font-bold text-slate-800 truncate">菜单配置</h1>
                        <p class="text-xs text-slate-500 mt-1">支持一二三级菜单；默认图标和高亮标识自动生成，界面已简化。</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 md:justify-end md:self-end w-full">
                    <button @click="addTopMenu" class="px-3 py-2 rounded-lg bg-blue-600 text-white text-sm font-bold hover:bg-blue-700">
                        <i class="fas fa-plus mr-1"></i>新增一级菜单
                    </button>
                    <button @click="saveMenu" :disabled="saving" class="px-3 py-2 rounded-lg bg-emerald-600 text-white text-sm font-bold hover:bg-emerald-700 disabled:opacity-60">
                        {{ saving ? '保存中...' : '保存菜单' }}
                    </button>
                </div>
            </div>

            <div id="top-menu-sortable" class="space-y-4 flex-1 min-h-0 overflow-y-auto">
                <div v-for="(menu, i) in menus" :key="menu.id" class="border border-slate-200 rounded-xl overflow-hidden">
                    <div class="bg-slate-100 p-3 flex items-center gap-2">
                        <i class="fas fa-grip-vertical text-slate-400 cursor-grab top-drag-handle"></i>
                        <span class="text-xs text-slate-500 font-bold w-14">一级</span>
                        <input v-model="menu.title" placeholder="一级菜单名称" class="flex-1 border border-slate-300 rounded px-2 py-1 text-sm">
                        <button @click="addChildMenu(menu)" class="px-2 py-1 rounded bg-indigo-500 text-white text-xs">新增二级</button>
                        <button @click="removeTopMenu(i)" class="px-2 py-1 rounded bg-rose-500 text-white text-xs">删除</button>
                    </div>

                    <div class="p-3 bg-white">
                        <div class="mb-3">
                            <input v-model="menu.url" placeholder="一级直链 URL（留空表示仅做分组）" class="w-full border border-slate-300 rounded px-2 py-1 text-sm">
                        </div>

                        <div :id="'child-sortable-' + menu.id" class="space-y-2">
                            <div v-for="(child, j) in menu.children" :key="child.id" class="space-y-2">
                                <div class="border border-slate-200 rounded-lg p-2 bg-slate-50 flex items-center gap-2">
                                    <i class="fas fa-grip-vertical text-slate-400 cursor-grab child-drag-handle"></i>
                                    <span class="text-xs text-slate-500 font-bold">二级</span>
                                    <input v-model="child.title" placeholder="菜单名称" class="w-36 border border-slate-300 rounded px-2 py-1 text-sm">
                                    <input v-model="child.url" placeholder="页面 URL（如 admin_projects.php）" class="flex-1 border border-slate-300 rounded px-2 py-1 text-sm">
                                    <button @click="addThirdMenu(child)" class="px-2 py-1 rounded bg-violet-500 text-white text-xs">新增三级</button>
                                    <button @click="removeChildMenu(menu, j)" class="px-2 py-1 rounded bg-rose-500 text-white text-xs">删</button>
                                </div>
                                <div :id="'third-sortable-' + child.id" class="space-y-2">
                                    <div v-for="(third, k) in child.children" :key="third.id" class="ml-10 border border-slate-200 rounded-lg p-2 bg-white flex items-center gap-2">
                                        <i class="fas fa-grip-vertical text-slate-400 cursor-grab third-drag-handle"></i>
                                        <span class="text-xs text-slate-500 font-bold">三级</span>
                                        <input v-model="third.title" placeholder="三级菜单名称" class="w-44 border border-slate-300 rounded px-2 py-1 text-sm">
                                        <input v-model="third.url" placeholder="页面 URL（如 report.php）" class="flex-1 border border-slate-300 rounded px-2 py-1 text-sm">
                                        <button @click="removeThirdMenu(child, k)" class="px-2 py-1 rounded bg-rose-500 text-white text-xs">删</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script>
const { createApp, ref, nextTick, onMounted } = Vue;

createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('menu_builder');
        const menus = ref([]);
        const saving = ref(false);

        const uid = () => 'm_' + Math.random().toString(36).slice(2, 10);
        const makeItem = (level) => {
            if (level === 0) return { id: uid(), title: '新一级菜单', url: '', children: [] };
            if (level === 1) return { id: uid(), title: '新二级菜单', url: '', children: [] };
            return { id: uid(), title: '新三级菜单', url: '', children: [] };
        };
        const makeMenu = () => makeItem(0);
        const makeChild = () => makeItem(1);
        const makeThird = () => makeItem(2);

        const normalizeItem = (item) => ({
            id: item.id || uid(),
            title: item.title || '',
            url: item.url || '',
            children: (Array.isArray(item.children) ? item.children : []).map(normalizeItem)
        });
        const normalize = (raw) => (Array.isArray(raw) ? raw : []).map(normalizeItem);

        const bindSortables = async () => {
            await nextTick();
            const topEl = document.getElementById('top-menu-sortable');
            if (topEl && !topEl.__sortable) {
                topEl.__sortable = new Sortable(topEl, {
                    animation: 150,
                    handle: '.top-drag-handle',
                    ghostClass: 'drag-ghost',
                    chosenClass: 'drag-chosen',
                    onEnd: (evt) => {
                        const arr = [...menus.value];
                        const moved = arr.splice(evt.oldIndex, 1)[0];
                        arr.splice(evt.newIndex, 0, moved);
                        menus.value = arr;
                    }
                });
            }
            menus.value.forEach((m) => {
                const childEl = document.getElementById('child-sortable-' + m.id);
                if (childEl && !childEl.__sortable) {
                    childEl.__sortable = new Sortable(childEl, {
                        group: 'menu-children',
                        animation: 150,
                        handle: '.child-drag-handle',
                        ghostClass: 'drag-ghost',
                        chosenClass: 'drag-chosen',
                        onEnd: (evt) => {
                            const fromId = evt.from.id.replace('child-sortable-', '');
                            const toId = evt.to.id.replace('child-sortable-', '');
                            if (!fromId || !toId) return;
                            const fromMenu = menus.value.find(x => x.id === fromId);
                            const toMenu = menus.value.find(x => x.id === toId);
                            if (!fromMenu || !toMenu) return;
                            const moved = fromMenu.children.splice(evt.oldIndex, 1)[0];
                            toMenu.children.splice(evt.newIndex, 0, moved);
                        }
                    });
                }
                m.children.forEach((c) => {
                    const thirdEl = document.getElementById('third-sortable-' + c.id);
                    if (thirdEl && !thirdEl.__sortable) {
                        thirdEl.__sortable = new Sortable(thirdEl, {
                            animation: 150,
                            handle: '.third-drag-handle',
                            ghostClass: 'drag-ghost',
                            chosenClass: 'drag-chosen',
                            onEnd: (evt) => {
                                const childId = evt.to.id.replace('third-sortable-', '');
                                const parentMenu = menus.value.find(x => Array.isArray(x.children) && x.children.some(y => y.id === childId));
                                if (!parentMenu) return;
                                const child = parentMenu.children.find(y => y.id === childId);
                                if (!child || !Array.isArray(child.children)) return;
                                const moved = child.children.splice(evt.oldIndex, 1)[0];
                                child.children.splice(evt.newIndex, 0, moved);
                            }
                        });
                    }
                });
            });
        };

        const loadMenu = async () => {
            const r = await fetch('menu_builder.php?action=get_menu');
            const d = await r.json();
            if (d.status !== 'success') {
                alert(d.msg || '加载失败');
                return;
            }
            menus.value = normalize(d.data);
            bindSortables();
        };

        const addTopMenu = () => {
            menus.value.push(makeMenu());
            bindSortables();
        };
        const removeTopMenu = (i) => {
            if (!confirm('确定删除这个一级菜单吗？')) return;
            menus.value.splice(i, 1);
        };
        const addChildMenu = (menu) => {
            menu.children.push(makeChild());
            bindSortables();
        };
        const removeChildMenu = (menu, j) => {
            menu.children.splice(j, 1);
        };
        const addThirdMenu = (child) => {
            if (!Array.isArray(child.children)) child.children = [];
            child.children.push(makeThird());
        };
        const removeThirdMenu = (child, k) => {
            child.children.splice(k, 1);
        };

        const saveMenu = async () => {
            if (!menus.value.length) {
                alert('请至少保留一个菜单');
                return;
            }
            saving.value = true;
            try {
                const r = await fetch('menu_builder.php?action=save_menu', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ data: menus.value })
                });
                const d = await r.json();
                if (d.status === 'success') {
                    alert('保存成功');
                    await loadMenu();
                } else {
                    alert(d.msg || '保存失败');
                }
            } catch (e) {
                alert('保存失败，请重试');
            } finally {
                saving.value = false;
            }
        };

        onMounted(loadMenu);
        return {
            sidebarOpen, view, menus, saving,
            addTopMenu, removeTopMenu, addChildMenu, removeChildMenu, addThirdMenu, removeThirdMenu,
            saveMenu
        };
    }
}).mount('#app');
</script>
</body>
</html>

