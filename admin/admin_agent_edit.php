<?php
// admin_agent_edit.php - 人员编辑 (v2.4: 终极修复白屏与CDN问题)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>人员档案编辑</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8fafc; font-family: sans-serif; }
    </style>
</head>
<body class="p-6">
<div id="app" class="max-w-2xl mx-auto bg-white rounded-xl shadow-2xl p-6">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-xl font-bold text-slate-800">{{ form.id ? '编辑人员' : '新增人员' }}</h1>
        <button @click="closeWin" class="text-gray-400 hover:text-gray-600"><i class="fas fa-times"></i></button>
    </div>

    <div class="space-y-5 h-[720px] overflow-y-auto pr-2">
        <div class="grid grid-cols-2 gap-4">
            <div><label class="block text-xs font-bold text-gray-500 mb-1">姓名</label><input v-model="form.username" class="w-full border rounded p-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none"></div>
            <div><label class="block text-xs font-bold text-gray-500 mb-1">手机号</label><input v-model="form.phone" class="w-full border rounded p-2 text-sm focus:ring-2 focus:ring-blue-100 outline-none"></div>
        </div>
        
        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">归属部门</label>
                <select v-model="form.department_id" class="w-full border rounded p-2 text-sm bg-white">
                    <option :value="0">-- 未分配 --</option>
                    <template v-for="d in flatDepts" :key="d.id">
                        <option :value="d.id">{{ d.name }}</option>
                    </template>
                </select>
            </div>
            <div class="col-span-2">
                <label class="block text-xs font-bold text-gray-500 mb-1">系统角色（可多选）</label>
                <div class="border rounded-lg p-3 space-y-2 bg-slate-50">
                    <label v-for="opt in roleOptions" :key="opt.value" class="flex items-center gap-2 text-sm cursor-pointer">
                        <input type="checkbox" class="rounded border-gray-300 text-indigo-600" :checked="form.roles.includes(opt.value)" @change="toggleRole(opt.value, $event.target.checked)">
                        <span>{{ opt.label }}</span>
                    </label>
                </div>
                <p class="text-[10px] text-gray-400 mt-1">可同时勾选「渠道经纪人」与「管理员」等；至少选一项。保存后 <code class="bg-slate-100 px-0.5 rounded">agents.role</code> 会同步为其中优先级最高的一项以兼容旧逻辑。</p>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">上级经理</label>
                <select v-model="form.manager_id" class="w-full border rounded p-2 text-sm bg-white">
                    <option :value="0">-- 无上级 --</option>
                    <template v-for="agent in allAgents" :key="agent ? agent.id : 'null'">
                        <option v-if="agent && agent.id != (form.id || 0)" :value="agent.id">
                            {{ agent.username }} ({{ agent.dept_name || '未分配' }})
                        </option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs font-bold text-gray-500 mb-1">在职状态</label>
                <select v-model="form.employment_status" class="w-full border rounded p-2 text-sm bg-white">
                    <option :value="1">在职</option>
                    <option :value="0">离职</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">登录密码</label>
            <input v-model="form.password" class="w-full border rounded p-2 text-sm" placeholder="默认: 123456">
        </div>

        <div class="border-t pt-4">
            <label class="block text-xs font-bold text-gray-500 mb-1"><i class="fas fa-building text-indigo-500 mr-1"></i>关联项目（案场端可见与可处理）</label>
            <p class="text-[10px] text-gray-400 mb-2 leading-relaxed">勾选后保存，该账号登录案场（项目驻场）时可管理这些楼盘的报备；与「项目库-管理员」可同时生效。</p>
            <input v-model="projectKeyword" type="text" class="w-full border rounded p-2 text-xs mb-2" placeholder="筛选楼盘名称…" autocomplete="off">
            <div class="border border-gray-200 rounded-lg max-h-52 overflow-y-auto bg-slate-50/80 p-2 space-y-1">
                <label v-for="p in filteredProjectOptions" :key="'p_'+p.id" class="flex items-center gap-2 text-xs py-1.5 px-2 rounded-md hover:bg-white cursor-pointer">
                    <input type="checkbox" class="rounded border-gray-300 text-indigo-600" :checked="isProjectSelected(p.id)" @change="toggleProject(p.id, $event)">
                    <span class="flex-1 min-w-0 truncate text-slate-700">{{ p.name }}</span>
                </label>
                <div v-if="filteredProjectOptions.length===0" class="text-xs text-gray-400 py-3 text-center">无匹配或未加载项目</div>
            </div>
            <p class="text-[10px] text-gray-400 mt-1">已选 <b class="text-slate-600">{{ form.project_ids.length }}</b> 个</p>
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
        const roleOptions = [
            { value: 'channel', label: '渠道经纪人' },
            { value: 'staff', label: '案场人员' },
            { value: 'finance', label: '财务' },
            { value: 'admin', label: '管理员' },
        ];
        const form = ref({
            id: '', username: '', phone: '', department_id: 0, roles: ['channel'], password: '123456', manager_id: 0, employment_status: 1,
            project_ids: []
        });
        const departments = ref([]);
        const allAgents = ref([]);
        const allProjects = ref([]);
        const projectKeyword = ref('');
        const id = new URLSearchParams(window.location.search).get('id');

        const filteredProjectOptions = computed(() => {
            const kw = projectKeyword.value.trim();
            const arr = Array.isArray(allProjects.value) ? allProjects.value : [];
            if (!kw) return arr;
            return arr.filter((p) => (p.name || '').includes(kw));
        });
        const isProjectSelected = (pid) => form.value.project_ids.map(String).includes(String(pid));
        const toggleRole = (value, on) => {
            let cur = Array.isArray(form.value.roles) ? [...form.value.roles] : [];
            if (on && !cur.includes(value)) cur.push(value);
            if (!on) cur = cur.filter((x) => x !== value);
            if (cur.length === 0) cur = ['channel'];
            form.value.roles = cur;
        };

        const toggleProject = (pid, ev) => {
            const sid = String(pid);
            const on = ev && ev.target ? ev.target.checked : false;
            const cur = form.value.project_ids.map(String);
            const idx = cur.indexOf(sid);
            if (on && idx < 0) cur.push(sid);
            if (!on && idx >= 0) cur.splice(idx, 1);
            form.value.project_ids = cur;
        };

        // 扁平化部门 (防崩处理)
        const flatDepts = computed(() => {
            const list = [];
            const traverse = (nodes, prefix='') => {
                if(!nodes || !Array.isArray(nodes)) return;
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

        const loadData = async () => {
            try {
                // 加载基础数据
                const resInit = await fetch('../api.php?action=get_structure');

                // 安全解析
                let dataInit = {};
                
                if(resInit.ok) dataInit = await resInit.json();

                // 强制转为数组，防止 null
                departments.value = Array.isArray(dataInit.departments) ? dataInit.departments : [];
                
                // 过滤掉 null 的 agent
                const rawAgents = Array.isArray(dataInit.agents) ? dataInit.agents : [];
                allAgents.value = rawAgents.filter(a => a && a.id);

                const resProj = await fetch('../api.php?action=get_projects');
                if (resProj.ok) {
                    const pj = await resProj.json();
                    allProjects.value = Array.isArray(pj) ? pj.filter((p) => p && p.id && String(p.status) === '1') : [];
                } else {
                    allProjects.value = [];
                }

                if(id) {
                    const resDetail = await fetch('../api.php?action=get_agent_detail&id=' + id);
                    if(resDetail.ok) {
                        const target = await resDetail.json();
                        if(target) {
                            form.value.id = target.id;
                            form.value.username = target.username || '';
                            form.value.phone = target.phone || '';
                            form.value.department_id = target.department_id || 0;
                            if (Array.isArray(target.roles) && target.roles.length) {
                                form.value.roles = [...target.roles];
                            } else if (target.role) {
                                form.value.roles = [String(target.role)];
                            } else {
                                form.value.roles = ['channel'];
                            }
                            form.value.password = target.password || ''; 
                            form.value.manager_id = target.manager_id || 0;
                            form.value.employment_status = Number(target.employment_status) === 0 ? 0 : 1;
                            const pids = Array.isArray(target.project_ids) ? target.project_ids : [];
                            form.value.project_ids = pids.map((x) => String(x));
                        }
                    }
                }
            } catch (error) {
                console.error('加载数据失败:', error);
            }
        };



        const save = async () => {
            try {
                if (!Array.isArray(form.value.roles) || form.value.roles.length === 0) {
                    alert('请至少选择一个系统角色');
                    return;
                }
                const fd = new FormData();

                for (let k in form.value) {
                    if (k === 'project_ids' || k === 'roles') continue;
                    const v = form.value[k];
                    fd.append(k, v === null || v === undefined ? '' : v);
                }
                fd.append('roles_json', JSON.stringify(form.value.roles));
                fd.append('project_ids', Array.isArray(form.value.project_ids) ? form.value.project_ids.join(',') : '');

                const res = await fetch('../api.php?action=save_agent_full', {method:'POST', body:fd});
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
            form, roleOptions, flatDepts, allAgents, allProjects, projectKeyword, filteredProjectOptions,
            isProjectSelected, toggleRole, toggleProject, save, closeWin
        };
    }
}).mount('#app');
</script>
</body>
</html>