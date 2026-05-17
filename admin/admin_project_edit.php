<?php
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
    <title>项目编辑</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <!-- 引入CKEditor 5富文本编辑器 -->
    <script src="https://cdn.ckeditor.com/ckeditor5/36.0.1/classic/ckeditor.js"></script>
</head>
<body class="bg-gray-50 p-8">
<div id="app" class="max-w-5xl mx-auto bg-white rounded-xl shadow-lg p-8">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-slate-800">{{ form.id ? '编辑项目' : '新增项目' }}</h1>
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="text-sm font-bold text-gray-500">项目类型:</span>
                <label class="flex items-center gap-2 cursor-pointer bg-gray-100 px-3 py-1 rounded-lg">
                    <input type="checkbox" v-model="form.is_agent" :true-value="1" :false-value="0" class="w-4 h-4 text-blue-600">
                    <span :class="form.is_agent ? 'text-blue-600 font-bold' : 'text-gray-400'">{{ form.is_agent ? '代理项目 (在售)' : '市场数据 (仅展示)' }}</span>
                </label>
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm font-bold text-gray-500">项目状态:</span>
                <label class="flex items-center gap-2 cursor-pointer bg-gray-100 px-3 py-1 rounded-lg">
                    <input type="checkbox" v-model="form.status" :true-value="1" :false-value="0" class="w-4 h-4 text-green-600">
                    <span :class="form.status ? 'text-green-600 font-bold' : 'text-red-600 font-bold'">{{ form.status ? '上架' : '下架' }}</span>
                </label>
            </div>
        </div>
    </div>

    <div class="space-y-6">
        <div class="flex gap-6">
            <div class="w-48 h-48 bg-gray-100 border-2 border-dashed rounded-lg flex items-center justify-center relative hover:bg-gray-50 cursor-pointer">
                <img v-if="form.image" :src="form.image" class="w-full h-full object-cover rounded-lg">
                <div v-else class="text-center text-gray-400">点击上传封面</div>
                <input type="file" @change="handleUpload" class="absolute inset-0 opacity-0 w-full h-full">
            </div>
            <div class="flex-1 space-y-4">
                <div><label class="block text-xs font-bold text-gray-500 mb-1">项目名称</label><input v-model="form.name" class="w-full border rounded p-2"></div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">均价 (元/㎡)</label><input v-model="form.price" type="number" class="w-full border rounded p-2"></div>
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">佣金</label><input v-model="form.commission_rate" class="w-full border rounded p-2" placeholder="如 3%"></div>
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">税点</label><input v-model="form.tax_rate" class="w-full border rounded p-2" placeholder="如 5%"></div>
                    <div><label class="block text-xs font-bold text-gray-500 mb-1">保护期 (天)</label><input v-model="form.protect_days" type="number" class="w-full border rounded p-2" placeholder="如 30"></div>
                </div>
                <div v-if="form.is_agent" class="bg-blue-50 p-3 rounded border border-blue-100 grid grid-cols-2 gap-4 animate-pulse">
                    <div><label class="block text-xs font-bold text-blue-600 mb-1">驻场管理员</label><input v-model="form.manager_name" class="w-full border border-blue-200 rounded p-2 text-sm"></div>
                    <div><label class="block text-xs font-bold text-blue-600 mb-1">管理员电话</label><input v-model="form.manager_phone" class="w-full border border-blue-200 rounded p-2 text-sm"></div>
                </div>
            </div>
        </div>

        <div class="border rounded-xl p-4 bg-slate-50/80">
            <div class="flex justify-between items-center mb-3">
                <h2 class="text-sm font-bold text-slate-700">佣金套餐</h2>
                <button type="button" @click="addCommissionRow" class="text-xs font-bold px-3 py-1.5 rounded-lg bg-blue-600 text-white hover:bg-blue-700">+ 添加套餐</button>
            </div>
            <p class="text-xs text-gray-500 mb-3">套餐名称必填；保存时整表覆盖写入（空名称行不会入库）。</p>
            <div class="overflow-x-auto border border-gray-200 rounded-lg bg-white">
                <table class="w-full text-sm min-w-[800px]">
                    <thead class="bg-gray-100 text-gray-600 text-xs">
                        <tr>
                            <th class="text-left p-2 font-bold">套餐名称</th>
                            <th class="text-left p-2 font-bold w-24">佣金比例%</th>
                            <th class="text-left p-2 font-bold w-28">现金奖励</th>
                            <th class="text-left p-2 font-bold w-24">跳点比例%</th>
                            <th class="text-left p-2 font-bold w-28">跳点奖励</th>
                            <th class="text-center p-2 font-bold w-20">启用</th>
                            <th class="text-center p-2 font-bold w-16">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="!commissionPackages.length">
                            <td colspan="7" class="p-4 text-center text-gray-400 text-xs">暂无套餐，点击「添加套餐」</td>
                        </tr>
                        <tr v-for="(row, idx) in commissionPackages" :key="'cp-'+idx" class="border-t border-gray-100">
                            <td class="p-2"><input v-model="row.package_name" class="w-full border rounded px-2 py-1 text-sm" placeholder="如 A档"></td>
                            <td class="p-2"><input v-model="row.commission_pct" type="number" step="0.01" class="w-full border rounded px-2 py-1 text-sm" placeholder="0"></td>
                            <td class="p-2"><input v-model="row.cash_reward" type="number" step="0.01" class="w-full border rounded px-2 py-1 text-sm" placeholder="0"></td>
                            <td class="p-2"><input v-model="row.jump_ratio" type="number" step="0.01" class="w-full border rounded px-2 py-1 text-sm" placeholder="0"></td>
                            <td class="p-2"><input v-model="row.jump_reward" type="number" step="0.01" class="w-full border rounded px-2 py-1 text-sm" placeholder="0"></td>
                            <td class="p-2 text-center"><input type="checkbox" v-model="row.is_enabled" :true-value="1" :false-value="0" class="w-4 h-4 text-blue-600 rounded"></td>
                            <td class="p-2 text-center"><button type="button" @click="removeCommissionRow(idx)" class="text-xs text-red-600 font-bold hover:underline">删除</button></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div>
            <label class="block text-xs font-bold text-gray-500 mb-1">项目详情</label>
            <textarea id="detail-editor" class="w-full border rounded p-4 min-h-[200px]">{{ form.detail }}</textarea>
        </div>
        <div class="flex justify-end gap-4 pt-4 border-t">
            <button @click="closeWin" class="px-6 py-2 border rounded text-gray-600 hover:bg-gray-50">取消</button>
            <button @click="save" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 shadow-lg">保存</button>
        </div>
    </div>
</div>
<script>
const { createApp, ref, onMounted } = Vue;
createApp({
    setup() {
        const form = ref({ id:'', name:'', price:'', commission_rate:'', tax_rate:'', protect_days:30, detail:'', image:'', is_agent:1, status:1, manager_name:'', manager_phone:'' });
        const commissionPackages = ref([]);
        const idFromQuery = new URLSearchParams(window.location.search).get('id');

        let editor;

        const emptyCommissionRow = () => ({
            package_name: '',
            commission_pct: '',
            cash_reward: '',
            jump_ratio: '',
            jump_reward: '',
            is_enabled: 1,
        });
        const addCommissionRow = () => {
            commissionPackages.value = [...commissionPackages.value, emptyCommissionRow()];
        };
        const removeCommissionRow = (idx) => {
            commissionPackages.value = commissionPackages.value.filter((_, i) => i !== idx);
        };
        
        const normalizeImageUrl = (u) => {
            if (!u || typeof u !== 'string') return u;
            const t = u.trim();
            if (/^https?:\/\//i.test(t) || t.startsWith('/')) return t;
            return '/' + t.replace(/^\.+\//, '');
        };

        const loadData = async () => {
            const pid = form.value.id || idFromQuery;
            if (!pid) return;
            const res = await fetch('../api.php?action=get_project_detail&id=' + encodeURIComponent(String(pid)));
            const data = await res.json();
            form.value = data;
            if (form.value.image) form.value.image = normalizeImageUrl(form.value.image);
            const pk = Array.isArray(data.commission_packages) ? data.commission_packages : [];
            commissionPackages.value = pk.map((r) => ({
                package_name: r.package_name != null ? String(r.package_name) : '',
                commission_pct: r.commission_pct != null && r.commission_pct !== '' ? String(r.commission_pct) : '',
                cash_reward: r.cash_reward != null && r.cash_reward !== '' ? String(r.cash_reward) : '',
                jump_ratio: r.jump_ratio != null && r.jump_ratio !== '' ? String(r.jump_ratio) : '',
                jump_reward: r.jump_reward != null && r.jump_reward !== '' ? String(r.jump_reward) : '',
                is_enabled: Number(r.is_enabled) === 1 ? 1 : 0,
            }));
            if (editor) {
                editor.setData(form.value.detail || '');
            }
        };
        
        const handleUpload = async (e) => {
            const fd = new FormData(); fd.append('file', e.target.files[0]);
            const res = await fetch('../upload.php', {method:'POST', body:fd});
            const d = await res.json();
            if(d.status==='success') form.value.image = d.url;
        };
        
        const save = async () => {
            try {
                if (editor) form.value.detail = editor.getData();
            } catch (e) { console.warn(e); }
            const fd = new FormData();
            Object.keys(form.value).forEach((k) => {
                const v = form.value[k];
                if (v === undefined || v === null) return;
                fd.append(k, String(v));
            });
            fd.append('commission_packages', JSON.stringify(commissionPackages.value));
            let res;
            try {
                res = await fetch('../api.php?action=save_project', { method: 'POST', body: fd });
            } catch (e) {
                alert('网络错误，请稍后重试');
                return;
            }
            const raw = await res.text();
            let d;
            try {
                d = JSON.parse(raw);
            } catch (e) {
                alert('保存失败：服务器返回异常');
                return;
            }
            if (d.status === 'success') {
                alert('保存成功');
                if (d.id != null && d.id !== '') {
                    form.value.id = String(d.id);
                    try {
                        const u = new URL(window.location.href);
                        u.searchParams.set('id', String(d.id));
                        window.history.replaceState(null, '', u.pathname + u.search);
                    } catch (e) { /* 忽略 */ }
                }
                await loadData();
                try {
                    if (window.opener && !window.opener.closed) window.opener.location.reload();
                } catch (e) { /* 忽略 */ }
            } else {
                alert(d.msg || '保存失败');
            }
        };
        
        const closeWin = () => window.close();
        
        onMounted(() => {
            // 初始化CKEditor 5富文本编辑器
            // 勿使用无效的 image.upload.adapter 配置，会导致 ClassicEditor.create 失败、整页无法保存
            ClassicEditor
                .create(document.querySelector('#detail-editor'), {
                    toolbar: [
                        'undo', 'redo',
                        '|', 'heading',
                        '|', 'bold', 'italic', 'underline',
                        '|', 'alignment',
                        '|', 'bulletedList', 'numberedList',
                        '|', 'insertTable',
                        '|', 'link',
                        '|', 'code'
                    ],
                    language: 'zh-cn'
                })
                .then((newEditor) => {
                    editor = newEditor;
                    loadData();
                })
                .catch((error) => {
                    console.error('CKEditor初始化失败:', error);
                    loadData();
                });
        });
        return { form, commissionPackages, save, handleUpload, closeWin, addCommissionRow, removeCommissionRow };
    }
}).mount('#app');
</script>
</body>
</html>