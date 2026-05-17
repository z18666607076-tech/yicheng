<?php
session_start();
// 如果已登录，跳转到后台首页
if (isset($_SESSION['admin_id'])) {
    header('Location: channel_efficiency.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>后台管理登录</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen">

<div id="app" class="w-full max-w-sm px-6">
    <div class="text-center mb-10">
        <div class="w-16 h-16 bg-indigo-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-indigo-500/30">
            <i class="fas fa-shield-alt text-3xl text-white"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">后台管理系统</h1>
        <p class="text-slate-400 text-xs mt-2">管理员登录入口</p>
    </div>

    <div class="bg-white p-6 rounded-3xl shadow-xl shadow-slate-100 border border-slate-100">
        <div class="space-y-5">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 ml-1">用户名</label>
                <div class="relative">
                    <i class="fas fa-user absolute left-4 top-3.5 text-slate-300"></i>
                    <input v-model="form.username" type="text" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="用户名或手机号">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 ml-1">登录密码</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-4 top-3.5 text-slate-300"></i>
                    <input v-model="form.password" type="password" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-indigo-500 focus:bg-white transition" placeholder="请输入密码">
                </div>
            </div>
            
            <button @click="login" :disabled="loading" class="w-full bg-indigo-600 text-white py-3.5 rounded-xl font-bold shadow-lg active:scale-95 transition disabled:opacity-50 disabled:cursor-not-allowed mt-2">
                <span v-if="loading"><i class="fas fa-spinner fa-spin mr-2"></i>登录中...</span>
                <span v-else>立即登录</span>
            </button>
        </div>
    </div>

    <div class="text-center mt-8 text-xs text-slate-400">
        <span>还没有账号? 请联系超级管理员开通</span>
    </div>
</div>

<script>
const { createApp, ref } = Vue;
createApp({
    setup() {
        const form = ref({ username: '', password: '' });
        const loading = ref(false);

        const login = async () => {
            if (!form.value.username || !form.value.password) return alert('请输入用户名和密码');
            
            loading.value = true;
            try {
                const fd = new FormData();
                fd.append('username', form.value.username);
                fd.append('password', form.value.password);
                
                const res = await fetch('admin_login_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.status === 'success') {
                    window.location.href = 'channel_efficiency.php';
                } else {
                    alert(data.msg || '登录失败');
                }
            } catch (e) {
                alert('网络错误，请稍后重试');
            } finally {
                loading.value = false;
            }
        };

        return { form, login, loading };
    }
}).mount('#app');
</script>
</body>
</html>