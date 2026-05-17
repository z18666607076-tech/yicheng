<?php
// admin_agents.php - 内部架构独立管理页 (v2.2: 性能优化版)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// 当前用户信息
$current_user_id = $_SESSION['admin_id'];
$current_user_role = 'admin';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>内部组织架构</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        .tree-item { cursor: pointer; padding: 8px 12px; border-radius: 6px; transition: all 0.2s; font-size: 14px; color: #475569; display: flex; align-items: center; gap: 8px; }
        .tree-item:hover { background-color: #f8fafc; color: #2563eb; }
        .tree-item.active { background-color: #eff6ff; color: #2563eb; font-weight: bold; }
        .tree-children { padding-left: 24px; border-left: 1px solid #e2e8f0; margin-left: 12px; margin-top: 4px; }
        .tree-item .dept-icon { width: 16px; text-align: center; }
        .tree-item .dept-actions { margin-left: auto; display: flex; gap: 4px; }
        /* 优化 Loading 动画，使其更柔和 */
        .fade-enter-active, .fade-leave-active { transition: opacity 0.3s; }
        .fade-enter-from, .fade-leave-to { opacity: 0; }
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
                    <span class="text-sm font-bold text-slate-700">数据同步中...</span>
                </div>
            </div>
        </transition>

        <header class="bg-white border-b border-gray-200 shadow-sm flex-shrink-0">
            <div class="flex flex-col gap-2 py-2 px-3 sm:px-4 md:flex-row md:items-center md:justify-between md:h-16 md:py-0 md:px-6 lg:px-8">
                <div class="flex items-center gap-3 min-w-0">
                    <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden shrink-0 p-2 -ml-1 rounded-lg text-gray-600 hover:bg-gray-100" aria-label="打开菜单">
                        <i class="fas fa-bars text-lg"></i>
                    </button>
                    <h2 class="text-base md:text-lg font-bold text-slate-800 truncate">内部组织架构</h2>
                </div>
                <div class="flex flex-wrap items-center gap-2 md:gap-3 md:justify-end">
                    <button type="button" @click="loadData" class="text-gray-500 hover:text-indigo-600 px-2 py-2 text-sm transition" title="手动刷新">
                        <i class="fas fa-sync-alt" :class="{'fa-spin': loading}"></i> 刷新
                    </button>
                    <button type="button" @click="openAgentModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-3 py-2 md:px-4 rounded-lg text-sm shadow-md transition flex items-center gap-2">
                        <i class="fas fa-user-plus"></i> 新增人员
                    </button>
                </div>
            </div>
        </header>

        <div class="flex-1 flex flex-col md:flex-row overflow-hidden min-h-0 min-w-0">
            <aside class="w-full md:w-80 shrink-0 max-h-[40vh] md:max-h-none bg-white border-b md:border-b-0 md:border-r border-gray-200 flex flex-col min-h-0">
                <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                    <span class="font-bold text-sm text-slate-600">部门结构</span>
                    <button @click="openDeptModal()" class="text-xs text-blue-600 hover:underline">+ 添加部门</button>
                </div>
                <div class="flex-1 overflow-y-auto p-4">
                    <div @click="currDeptId=0" class="tree-item mb-1 group" :class="{'active': currDeptId===0}">
                        <div class="dept-icon"><i class="fas fa-sitemap text-blue-500"></i></div>
                        <div class="flex-1">所有部门</div>
                    </div>
                    <tree-menu :nodes="departments" :curr="currDeptId" @select="selectDept" @edit="editDept" @del="delDept"></tree-menu>
                </div>
            </aside>

            <div class="flex-1 overflow-auto p-4 md:p-8 min-w-0">
                <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <h3 class="font-bold text-slate-700 text-base md:text-lg min-w-0">
                        {{ getDeptName(currDeptId) }}
                        <span class="text-sm font-normal text-gray-400 ml-2">({{ filteredAgents.length }} 人)</span>
                    </h3>
                    <input v-model="search" class="border rounded-lg px-3 py-1.5 text-sm w-full sm:w-64 max-w-full outline-none focus:ring-1" placeholder="搜索姓名或手机...">
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4 md:gap-6">
                    <div v-for="agent in filteredAgents" :key="agent.id" class="rounded-xl shadow-sm border p-5 transition relative group" :class="Number(agent.employment_status)===0 ? 'bg-gray-100 border-gray-200 opacity-70' : 'bg-white border-slate-100 hover:shadow-md'">
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-indigo-50 text-indigo-600 flex items-center justify-center font-bold text-lg">
                                    {{ agent.username.charAt(0) }}
                                </div>
                                <div>
                                    <div class="font-bold text-slate-800">{{ agent.username }}</div>
                                    <div class="text-xs text-gray-400">{{ formatAgentRoles(agent) }} · {{ agent.dept_name || '未分配' }} · {{ Number(agent.employment_status)===0 ? '离职' : '在职' }}</div>
                                </div>
                            </div>
                            <div class="opacity-100 md:opacity-0 md:group-hover:opacity-100 transition flex gap-2">
                                <button @click="openAgentModal(agent)" class="text-blue-500 hover:bg-blue-50 p-1.5 rounded"><i class="fas fa-edit"></i></button>
                                <button @click="delAgent(agent.id)" class="text-red-400 hover:bg-red-50 p-1.5 rounded"><i class="fas fa-trash"></i></button>
                            </div>
                        </div>
                        <div class="space-y-2 mt-4 pt-4 border-t border-gray-50 text-xs">

                            <div class="flex items-center gap-2">
                                <span class="text-gray-400">{{ agent.responsible_label || '负责项目' }}:</span>
                                <span class="font-bold text-slate-700">{{ agent.company_count }}</span> {{ agent.responsible_count_unit || '个' }}
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-gray-400">联系电话:</span>
                                <span class="font-mono text-slate-600">{{ agent.phone }}</span>
                            </div>
                            <div class="flex items-center gap-2">
                                <span class="text-gray-400">上级经理:</span>
                                <span class="text-slate-600">{{ agent.manager_name || '无' }}</span>
                            </div>
                            <div v-if="Number(agent.employment_status)===0" class="flex items-center gap-2">
                                <span class="text-gray-400">离职时间:</span>
                                <span class="text-slate-600">{{ agent.left_at || '-' }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <div v-if="showDeptModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl p-6 w-96 shadow-2xl">
            <h3 class="font-bold text-lg mb-4 text-slate-800">{{ deptForm.id ? '编辑' : '新增' }}部门</h3>
            <div class="space-y-3">
                <div><label class="block text-xs font-bold text-gray-500 mb-1">上级部门</label><select v-model="deptForm.parent_id" class="w-full border rounded p-2 text-sm bg-gray-50"><option :value="0">顶级部门</option><option v-for="d in flatDepts" :value="d.id" v-show="d.id != deptForm.id">{{ d.name }}</option></select></div>
                <div><label class="block text-xs font-bold text-gray-500 mb-1">部门名称</label><input v-model="deptForm.name" class="w-full border rounded p-2 text-sm outline-none focus:ring-1"></div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button @click="showDeptModal=false" class="px-4 py-2 border rounded text-sm text-gray-600 hover:bg-gray-50">取消</button>
                <button @click="saveDept" class="px-4 py-2 bg-indigo-600 rounded text-sm text-white hover:bg-indigo-700">保存</button>
            </div>
        </div>
    </div>

</div>

<script>
const { createApp, ref, onMounted, computed } = Vue;

const TreeMenu = {
    name: 'TreeMenu',
    props: ['nodes', 'curr'],
    template: `
        <div class="tree-children" :class="{'ml-0 border-0': !nodes[0]?.parent_id}">
            <div v-for="node in nodes" :key="node.id">
                <div class="tree-item group" :class="{'active': curr===node.id}" @click="$emit('select', node.id)">
                    <div class="dept-icon"><i class="fas fa-folder text-yellow-400"></i></div>
                    <div class="flex-1">{{ node.name }}</div>
                    <div class="dept-actions opacity-100 md:opacity-0 md:group-hover:opacity-100">
                        <button @click.stop="$emit('edit', node)" class="text-xs text-blue-500 hover:text-blue-700 p-1"><i class="fas fa-pen"></i></button>
                        <button @click.stop="$emit('del', node.id)" class="text-xs text-gray-400 hover:text-red-500 p-1"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <tree-menu v-if="node.children" :nodes="node.children" :curr="curr" @select="$emit('select', $event)" @edit="$emit('edit', $event)" @del="$emit('del', $event)"></tree-menu>
            </div>
        </div>
    `
};

createApp({
    components: { TreeMenu },
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('agents');
        const loading = ref(false);
        const departments = ref([]);
        const agents = ref([]);
        const currDeptId = ref(0);
        const search = ref('');
        const showDeptModal = ref(false);
        const deptForm = ref({id:'', name:'', parent_id:0});
        const currentUserId = ref(<?php echo $current_user_id; ?>);
        const currentUserRole = ref('<?php echo $current_user_role; ?>');

        const flatDepts = computed(() => {
            const list = [];
            const traverse = (nodes, prefix='') => {
                for(let node of nodes) {
                    list.push({id: node.id, name: prefix + node.name});
                    if(node.children) traverse(node.children, prefix + '-- ');
                }
            };
            traverse(departments.value);
            return list;
        });

        // 检查人员是否是当前用户的下级（包括直接和间接下级）
        const isSubordinate = (agentId) => {
            // 递归查找下级
            const findSubordinates = (managerId) => {
                const subs = agents.value.filter(a => parseInt(a.manager_id) === parseInt(managerId));
                let allSubs = [...subs];
                subs.forEach(sub => {
                    allSubs = [...allSubs, ...findSubordinates(sub.id)];
                });
                return allSubs;
            };
            
            // 获取当前用户的所有下级
            const allSubordinates = findSubordinates(currentUserId.value);
            // 检查目标人员是否在下级列表中
            return allSubordinates.some(sub => parseInt(sub.id) === parseInt(agentId));
        };

        const filteredAgents = computed(() => {
            return agents.value.filter(a => {
                // 部门筛选
                const matchDept = currDeptId.value === 0 || parseInt(a.department_id) === parseInt(currDeptId.value);
                // 搜索筛选
                const matchSearch = !search.value || a.username.includes(search.value) || a.phone.includes(search.value);
                // 层级筛选
                const matchHierarchy = 
                    currentUserRole.value === 'admin' || // 管理员可以看到所有人
                    parseInt(a.id) === parseInt(currentUserId.value) || // 可以看到自己
                    isSubordinate(a.id); // 可以看到下级
                
                return matchDept && matchSearch && matchHierarchy;
            });
        });

        const loadData = async () => {
            loading.value = true;
            try {
                const res = await fetch('../api.php?action=get_structure');
                const data = await res.json();
                departments.value = data.departments;
                agents.value = data.agents;
            } finally {
                loading.value = false;
            }
        };

        const getDeptName = (id) => id===0 ? '所有部门' : (flatDepts.value.find(d=>d.id==id)?.name.replace(/-/g,'') || '未知');
        const selectDept = (id) => currDeptId.value = id;
        const openDeptModal = () => { deptForm.value = {id:'', name:'', parent_id:0}; showDeptModal.value = true; };
        const editDept = (node) => { deptForm.value = {id:node.id, name:node.name, parent_id:node.parent_id}; showDeptModal.value = true; };
        
        const saveDept = async () => {
            loading.value = true;
            const fd = new FormData(); for(let k in deptForm.value) fd.append(k, deptForm.value[k]);
            await fetch('../api.php?action=save_department', {method:'POST', body:fd});
            showDeptModal.value = false; loadData();
        };
        
        const delDept = async (id) => {
            if(!confirm('确认删除此部门？需先清空部门下人员。')) return;
            loading.value = true;
            const fd = new FormData(); fd.append('id', id);
            const res = await fetch('../api.php?action=delete_department', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status==='success') loadData(); else { alert(d.msg); loading.value = false; }
        };

        const roleLabelMap = { channel: '渠道经纪人', staff: '案场人员', finance: '财务', admin: '管理员' };
        const formatAgentRoles = (agent) => {
            const arr = Array.isArray(agent.roles) && agent.roles.length ? agent.roles : (agent.role ? [agent.role] : []);
            return arr.map((r) => roleLabelMap[r] || r).join(' + ');
        };

        const openAgentModal = (agent=null) => {
            const url = agent ? `admin_agent_edit.php?id=${agent.id}` : 'admin_agent_edit.php';
            window.open(url, 'agent_edit', 'width=600,height=750');
        };
        const delAgent = async (id) => { 
            if(confirm('确认删除该人员？')) { 
                loading.value = true;
                const fd=new FormData(); fd.append('id',id); 
                await fetch('../api.php?action=delete_agent',{method:'POST',body:fd}); 
                loadData(); 
            }
        };

        // 核心修改：移除了 window.addEventListener('focus', loadData);
        // 这样切换窗口时就不会自动刷新了，避免了闪烁和卡顿
        onMounted(loadData);

        return {
            sidebarOpen, view, loading, departments, agents, currDeptId, search, filteredAgents, flatDepts,
            showDeptModal, deptForm,
            loadData, selectDept, getDeptName, formatAgentRoles, openDeptModal, editDept, saveDept, delDept,
            openAgentModal, delAgent
        };
    }
}).mount('#app');
</script>
</body>
</html>