<?php
// admin_agent_edit.php - 人员编辑 (v2.3: 修复白屏与数据健壮性)
session_start();
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>人员档案编辑</title>
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link href="https://cdnjs.cloudflare.com/ajax/libs/tailwindcss/2.2.19/tailwind.min.css" rel="stylesheet">

    <script src="https://cdn.staticfile.org/vue/3.3.4/vue.global.prod.min.js"></script>
    
    <link href="https://cdn.staticfile.org/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">

    <style>
        body { background: #f8fafc; font-family: sans-serif; }
        .search-res-item { @apply px-3 py-2 border-b cursor-pointer hover:bg-blue-50 text-xs flex justify-between items-center transition; }
        .search-res-item.added { @apply bg-gray-50 text-gray-400 cursor-default hover:bg-gray-50; }
        
        .selected-tag { @apply bg-blue-100 text-blue-700 px-2 py-1 rounded-lg text-xs font-bold flex items-center gap-1 border border-blue-200 animate-[fadeIn_0.2s_ease-out]; }
        .selected-tag i { @apply cursor-pointer hover:text-red-500 opacity-50 hover:opacity-100 transition; }
        
        @keyframes fadeIn { from { opacity: 0; transform: scale(0.9); } to { opacity: 1; transform: scale(1); } }
    </style>
</head>
<body class="p-6">
<div id="app" class="max-w-xl mx-auto bg-white rounded-xl shadow-2xl p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-xl font-bold text-slate-800">{{ form.id ? '编辑人员' : '新增人员' }}</h1>
        <button @click="closeWin" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>

    <div class="space-y-5 h-[600px] overflow-y-auto pr-2">
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-xs font-bold text-gray-500 mb-1">姓名</label><input v-model="form.username" class="w-full border rounded p-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-500 mb-1">手机号</label><input v-model="form.phone" class="w-full border rounded p-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none"></div>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">归属部门</label>
                <select v-model="form.department_id" class="w-full border rounded p-2 text-sm bg-white">
                    <option :value="0">-- 未分配 --</option>
                    <option v-for="d in flatDepts" :value="d.id" :key="d.id">{{ d.name }}</option>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">系统角色</label>
                <select v-model="form.role" class="w-full border rounded p-2 text-sm bg-white">
                    <option value="channel">渠道经纪人</option>
                    <option value="staff">案场人员</option>
                    <option value="finance">财务</option>
                    <option value="admin">管理员</option>
                </select>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">上级经理</label>
                <select v-model="form.manager_id" class="w-full border rounded p-2 text-sm bg-white">
                    <option :value="0">-- 无上级 --</option>
                    <option v-for="agent in allAgents" :value="agent.id" :key="agent.id" v-if="agent && agent.id != (form.id || 0)">{{ agent.username }} ({{ agent.dept_name || '未分配' }})</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">登录密码</label>
            <input v-model="form.password" class="w-full border rounded p-2 text-sm" placeholder="默认: 123456">
        </div>

        <div class="bg-green-50 p-4 rounded-lg border border-green-100">
            <div class="flex justify-between items-center mb-2">
                <label class="block text-xs font-bold text-green-700"><i class="fas fa-city"></i> 负责项目</label>
                <button @click="toggleAllProjects" class="text-[10px] text-green-600 hover:text-green-800 underline">
                    {{ isAllProjectsSelected ? '取消全选' : '全选所有' }}
                </button>
            </div>
            <div class="max-h-32 overflow-y-auto bg-white border rounded p-2 text-sm">
                <div v-for="p in allProjects" :key="p.id" v-if="p" class="flex items-center gap-2 mb-1 cursor-pointer hover:bg-gray-50 p-1 rounded">
                    <input type="checkbox" :id="'proj_'+p.id" :value="p.id" v-model="form.project_ids" class="rounded text-green-600 focus:ring-green-500 cursor-pointer">
                    <label :for="'proj_'+p.id" class="cursor-pointer flex-1 select-none">{{ p.name }}</label>
                </div>
                <div v-if="allProjects.length===0" class="text-gray-400 text-xs">暂无项目</div>
            </div>
        </div>

        <div class="bg-blue-50 p-4 rounded-lg border border-blue-100 relative">
            <label class="block text-xs font-bold text-blue-700 mb-2"><i class="fas fa-store"></i> 负责跟进的商户</label>
            
            <div class="relative mb-3">
                <div class="flex items-center border border-blue-200 rounded bg-white overflow-hidden focus-within:ring-2 focus-within:ring-blue-300">
                    <i class="fas fa-search text-gray-400 pl-2"></i>
                    <input v-model="compSearch" @input="searchCompany" placeholder="输入商户关键词搜索..." class="w-full p-2 text-sm outline-none">
                    <button v-if="compSearch" @click="clearSearch" class="text-gray-400 hover:text-gray-600 px-2"><i class="fas fa-times"></i></button>
                </div>

                <div v-if="compResults.length > 0" class="absolute left-0 right-0 top-full mt-1 bg-white border shadow-xl rounded max-h-48 overflow-y-auto z-50">
                    <template v-for="c in compResults" :key="c ? c.id : Math.random()">
                        <div v-if="c && c.id"
                             @click="!isCompSelected(c.id) && selectComp(c)" 
                             class="search-res-item"
                             :class="{'added': isCompSelected(c.id)}"> 
                            <span>{{ c.name }} <span class="text-gray-400 text-[10px]">({{ c.store_name || '无门店' }})</span></span>
                            
                            <i v-if="isCompSelected(c.id)" class="fas fa-check text-green-500 text-xs"> 已添加</i>
                            <i v-else class="fas fa-plus text-blue-500"></i>
                        </div>
                    </template>
                </div>
                <div v-if="compSearch && compResults.length === 0 && !searching" class="absolute left-0 right-0 top-full mt-1 bg-white border shadow p-2 text-center text-xs text-gray-400 z-50">
                    未找到相关商户
                </div>
            </div>

            <div class="flex flex-wrap gap-2 min-h-[30px]">
                <template v-for="(c, idx) in selectedComps" :key="c ? c.id : idx">
                    <div v-if="c && c.id" class="selected-tag">
                        {{ c.name }}
                        <i @click="removeComp(idx)" class="fas fa-times-circle ml-1"></i>
                    </div>
                </template>
                <div v-if="selectedComps.length===0" class="text-xs text-blue-300 italic pt-1">暂无关联商户，请搜索添加</div>
            </div>
        </div>

    </div>

    <div class="flex justify-end gap-3 mt-4 pt-4 border-t">
        <button @click="closeWin" class="px-6 py-2 border rounded text-sm text-gray-600 hover:bg-gray-50">取消</button>
        <button @click="save" class="px-6 py-2 bg-indigo-600 text-white rounded text-sm hover:bg-indigo-700 shadow-lg">保存配置</button>
    </div>
</div>

<script>
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const form = ref({
            id: '', username: '', phone: '', department_id: 0, role: 'channel', password: '123456', manager_id: 0,
            project_ids: [],
            company_ids: [] 
        });
        const departments = ref([]);
        const allProjects = ref([]);
        const allAgents = ref([]);
        const id = new URLSearchParams(window.location.search).get('id');

        // 商户相关
        const compSearch = ref('');
        const compResults = ref([]);
        const selectedComps = ref([]); 
        const searching = ref(false);
        let searchTimer = null;

        // 扁平化部门 (防崩处理)
        const flatDepts = computed(() => {
            const list = [];
            const traverse = (nodes, prefix='') => {
                if(!nodes || !Array.isArray(nodes)) return; // 安全检查
                for(let node of nodes) {
                    if(node) {
                        list.push({id: node.id, name: prefix + node.name});
                        if(node.children) traverse(node.children, prefix + '-- ');
                    }
                }
            };
            traverse(departments.value);
            return list;
        });

        // 计算属性：是否全选了项目 (防崩处理)
        const isAllProjectsSelected = computed(() => {
            return allProjects.value.length > 0 && form.value.project_ids.length === allProjects.value.length;
        });

        const toggleAllProjects = () => {
            if (isAllProjectsSelected.value) {
                form.value.project_ids = []; 
            } else {
                form.value.project_ids = allProjects.value.map(p => p.id); 
            }
        };

        const loadData = async () => {
            try {
                // 并行加载基础数据
                const [resInit, resProj] = await Promise.all([
                    fetch('api.php?action=get_structure'),
                    fetch('api.php?action=get_projects')
                ]);

                if (!resInit.ok || !resProj.ok) throw new Error('网络请求失败');
                
                const dataInit = await resInit.json();
                // 确保赋值为数组
                departments.value = Array.isArray(dataInit.departments) ? dataInit.departments : [];
                allAgents.value = Array.isArray(dataInit.agents) ? dataInit.agents : [];
                
                const dataProj = await resProj.json();
                allProjects.value = Array.isArray(dataProj) ? dataProj : [];

                if(id) {
                    const resDetail = await fetch('api.php?action=get_agent_detail&id=' + id);
                    const target = await resDetail.json();
                    if(target) {
                        form.value.id = target.id;
                        form.value.username = target.username || '';
                        form.value.phone = target.phone || '';
                        form.value.department_id = target.department_id || 0;
                        form.value.role = target.role || 'channel';
                        form.value.password = target.password || ''; // 注意安全
                        form.value.manager_id = target.manager_id || 0;
                        form.value.project_ids = Array.isArray(target.project_ids) ? target.project_ids : [];
                        
                        // 修复重点：过滤掉 null/undefined 的数据，防止页面白屏
                        if(Array.isArray(target.selected_companies)) {
                            selectedComps.value = target.selected_companies.filter(item => item && item.id);
                        } else {
                            selectedComps.value = [];
                        }
                    }
                }
            } catch (error) {
                console.error('加载数据失败:', error);
                alert('数据加载异常，请检查网络或控制台日志');
            }
        };

        const searchCompany = () => {
            if (searchTimer) clearTimeout(searchTimer);
            if (!compSearch.value) { compResults.value = []; return; }
            
            searching.value = true;
            searchTimer = setTimeout(async () => {
                try {
                    const res = await fetch('api.php?action=get_companies&kw=' + encodeURIComponent(compSearch.value));
                    const data = await res.json();
                    const list = data.data ? data.data : (Array.isArray(data) ? data : []);
                    
                    // 修复重点：过滤掉无效数据
                    compResults.value = list.filter(item => item && item.id);
                } catch(e) {
                    console.error('搜索失败', e);
                    compResults.value = [];
                }
                searching.value = false;
            }, 300);
        };

        // 修复重点：使用可选链防止 c 为 undefined 时崩溃
        const isCompSelected = (id) => {
            if(!id) return false;
            return selectedComps.value.some(c => c && c.id === id);
        };

        const selectComp = (c) => {
            if (!c || !c.id) return;
            if (isCompSelected(c.id)) return;
            selectedComps.value.push({id: c.id, name: c.name});
        };

        const removeComp = (index) => {
            selectedComps.value.splice(index, 1);
        };
        
        const clearSearch = () => {
            compSearch.value = '';
            compResults.value = [];
        };

        const save = async () => {
            try {
                const fd = new FormData();
                // 确保过滤掉脏数据
                const validComps = selectedComps.value.filter(c => c && c.id).map(c => c.id);
                form.value.company_ids = validComps;

                for(let k in form.value) {
                    if(k === 'project_ids') fd.append(k, form.value[k].join(','));
                    else if(k === 'company_ids') fd.append(k, form.value[k].join(','));
                    else fd.append(k, form.value[k] === null ? '' : form.value[k]);
                }
                
                const res = await fetch('api.php?action=save_agent_full', {method:'POST', body:fd});
                const d = await res.json();
                if(d.status === 'success') { alert('保存成功'); window.opener && window.opener.location.reload(); window.close(); }
                else alert(d.msg || '失败');
            } catch (e) {
                alert('保存请求出错');
            }
        };

        const closeWin = () => window.close();
        onMounted(loadData);

        return { 
            form, flatDepts, allProjects, allAgents, save, closeWin,
            compSearch, compResults, selectedComps, searchCompany, selectComp, removeComp, searching,
            isCompSelected, clearSearch,
            isAllProjectsSelected, toggleAllProjects 
        };
    }
}).mount('#app');
</script>
</body>
</html>