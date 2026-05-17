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
    <title>商户详情</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
</head>
<body class="bg-gray-50 p-8">
<div id="app" class="max-w-3xl mx-auto bg-white rounded-xl shadow-lg p-8">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-slate-800">{{ form.id ? '编辑商户' : '新增商户' }}</h1>
        <button @click="closeWin" class="text-gray-400 hover:text-gray-600">关闭</button>
    </div>

    <div class="grid grid-cols-2 gap-6">
        <div class="col-span-2">
            <label class="block text-xs font-bold text-gray-500 mb-1">公司全称</label>
            <input v-model="form.name" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500 outline-none">
        </div>
        
        <div class="col-span-2 bg-blue-50 p-4 rounded-lg grid grid-cols-2 gap-4">
            <div><label class="block text-xs font-bold text-blue-600 mb-1">所属板块 (Main Region)</label><input v-model="form.region_main" class="w-full border rounded p-2"></div>
            <div><label class="block text-xs font-bold text-blue-600 mb-1">详细地区 (Sub Region)</label><input v-model="form.region_sub" class="w-full border rounded p-2"></div>
        </div>

        <div><label class="block text-xs font-bold text-gray-500 mb-1">门店名称</label><input v-model="form.store_name" class="w-full border rounded p-2"></div>
        <div><label class="block text-xs font-bold text-gray-500 mb-1">有无门店</label>
            <select v-model="form.store_type" class="w-full border rounded p-2 bg-white">
                <option value="">未知</option><option value="有门店">有门店</option><option value="无门店">无门店</option>
            </select>
        </div>
        <div class="col-span-2">
            <label class="block text-xs font-bold text-gray-500 mb-1">经营状态（与列表「备注」前半一致）</label>
            <input v-model="form.business_status" class="w-full border rounded p-2" placeholder="如自由经纪人；导入表常为 关系/战力/人数 拼成 D/D/1">
        </div>
        <div><label class="block text-xs font-bold text-gray-500 mb-1">关联门店（备注后半）</label><input v-model="form.related_store" class="w-full border rounded p-2"></div>
        <div><label class="block text-xs font-bold text-gray-500 mb-1">加盟品牌</label><input v-model="form.franchise_brand" class="w-full border rounded p-2"></div>

        <div class="col-span-2 border-t pt-4 grid grid-cols-3 gap-4">
            <div><label class="block text-xs font-bold text-green-600 mb-1">店东/联系人</label><input v-model="form.contact_name" class="w-full border rounded p-2"></div>
            <div><label class="block text-xs font-bold text-green-600 mb-1">联系电话</label><input v-model="form.contact_phone" class="w-full border rounded p-2"></div>
            <div><label class="block text-xs font-bold text-purple-600 mb-1">我司跟进人</label>
                <select v-model="form.follower" class="w-full border rounded p-2 focus:ring-2 focus:ring-blue-500 outline-none bg-white">
                    <option value="">请选择跟进人</option>
                    <option v-for="agent in agents" :key="agent.id" :value="agent.username">{{ agent.username }}</option>
                </select>
            </div>
        </div>

        <div class="col-span-2">
            <label class="block text-xs font-bold text-gray-500 mb-1">详细地址</label>
            <input v-model="form.address" class="w-full border rounded p-2">
        </div>
    </div>

    <div class="flex justify-end gap-4 mt-8 pt-4 border-t">
        <button @click="closeWin" class="px-6 py-2 border rounded text-gray-600 hover:bg-gray-50">取消</button>
        <button @click="save" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 shadow-lg font-bold">保存信息</button>
    </div>
</div>

<script>
const { createApp, ref, onMounted } = Vue;
createApp({
    setup() {
        const emptyForm = () => ({
            id: '',
            name: '',
            region_main: '',
            region_sub: '',
            store_name: '',
            store_type: '',
            business_status: '',
            related_store: '',
            franchise_brand: '',
            address: '',
            contact_name: '',
            contact_phone: '',
            follower: '',
        });
        const form = ref(emptyForm());
        const agents = ref([]);
        const id = new URLSearchParams(window.location.search).get('id');

        const loadData = async () => {
            if (!id) {
                form.value = emptyForm();
                return;
            }
            const res = await fetch('../api.php?action=get_company_detail&id=' + encodeURIComponent(id));
            const data = await res.json();
            if (data && typeof data === 'object' && !Array.isArray(data)) {
                form.value = { ...emptyForm(), ...data };
            } else {
                form.value = emptyForm();
                alert('未找到该商户或数据无效');
            }
        };

        const loadAgents = async () => {
            // 从api.php获取员工列表
            const res = await fetch('../api.php?action=get_structure');
            const data = await res.json();
            agents.value = data.agents || [];
        };

        const save = async () => {
            const name = String(form.value.name || '').trim();
            if (!name) {
                alert('请填写公司全称');
                return;
            }
            const fd = new FormData();
            const keys = ['id', 'name', 'region_main', 'region_sub', 'store_name', 'store_type', 'business_status', 'related_store', 'franchise_brand', 'address', 'contact_name', 'contact_phone', 'follower'];
            for (const k of keys) {
                const v = form.value[k];
                if (k === 'id' && (v === '' || v === null || v === undefined)) continue;
                fd.append(k, v == null ? '' : String(v));
            }
            try {
                const res = await fetch('../api.php?action=save_company', { method: 'POST', body: fd });
                const text = await res.text();
                let d;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    console.error(text);
                    alert('服务器返回异常，请查看控制台或联系管理员');
                    return;
                }
                if (d.status === 'success') {
                    alert('保存成功');
                    try {
                        if (window.opener && !window.opener.closed) window.opener.location.reload();
                    } catch (e) {}
                    window.close();
                } else {
                    alert(d.msg || '保存失败');
                }
            } catch (e) {
                console.error(e);
                alert('网络错误，保存失败');
            }
        };

        const closeWin = () => window.close();
        
        // 页面加载时同时加载商户数据和员工列表
        onMounted(async () => {
            await loadAgents();
            await loadData();
        });
        
        return { form, agents, save, closeWin };
    }
}).mount('#app');
</script>
</body>
</html>