<?php
session_start();
// 已通过后台登录页写入 admin_id 时，根目录不再展示手机号登录表单
if (isset($_SESSION['admin_id'])) {
    header('Location: admin/channel_efficiency.php');
    exit;
}
// 如果已登录，根据角色自动跳回对应页面，而不是死板跳 agent.php
if (isset($_SESSION['agent_id']) && isset($_SESSION['agent_role'])) {
    $r = $_SESSION['agent_role'];
    if ($r === 'staff') {
        header('Location: staff.php');
    } elseif ($r === 'channel') {
        header('Location: agent.php');
    } elseif (($r === 'admin' || $r === 'finance') && isset($_SESSION['admin_id'])) {
        header('Location: admin/channel_efficiency.php');
    } elseif ($r === 'admin' || $r === 'finance') {
        // 手机号登录仅含后台角色：未走 admin 登录不写 admin_id，引导至后台登录页
        header('Location: admin/login.php');
    } else {
        header('Location: agent.php');
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>系统登录</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-slate-50 flex items-center justify-center min-h-screen">

<div id="app" class="w-full max-w-sm px-6">
    <div class="text-center mb-10">
        <div class="w-16 h-16 bg-blue-600 rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-lg shadow-blue-500/30">
            <i class="fas fa-building text-3xl text-white"></i>
        </div>
        <h1 class="text-2xl font-bold text-slate-800">易成好房全盘通</h1>
        <p class="text-slate-400 text-xs mt-2">渠道报备 · 案场确客 · 佣金结算</p>
    </div>

    <div class="bg-white p-6 rounded-3xl shadow-xl shadow-slate-100 border border-slate-100">
        <div class="space-y-5">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 ml-1">手机号码</label>
                <div class="relative">
                    <i class="fas fa-phone absolute left-4 top-3.5 text-slate-300"></i>
                    <input v-model="form.phone" type="tel" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-blue-500 focus:bg-white transition" placeholder="请输入手机号">
                </div>
            </div>
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1 ml-1">登录密码</label>
                <div class="relative">
                    <i class="fas fa-lock absolute left-4 top-3.5 text-slate-300"></i>
                    <input v-model="form.password" type="password" class="w-full pl-10 pr-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm outline-none focus:border-blue-500 focus:bg-white transition" placeholder="请输入密码">
                </div>
            </div>
            
            <button @click="login" :disabled="loading" class="w-full bg-slate-900 text-white py-3.5 rounded-xl font-bold shadow-lg active:scale-95 transition disabled:opacity-50 disabled:cursor-not-allowed mt-2">
                <span v-if="loading"><i class="fas fa-spinner fa-spin mr-2"></i>登录中...</span>
                <span v-else>立即登录</span>
            </button>
        </div>
    </div>

    <div class="text-center mt-8 text-xs text-slate-400">
        <a href="#" class="hover:text-blue-600">忘记密码?</a>
        <span class="mx-2">|</span>
        <span>还没有账号? 请联系管理员开通</span>
    </div>
    <p class="text-center mt-4 text-xs text-slate-500">
        <a href="admin/login.php" class="text-blue-600 hover:underline font-medium">后台管理登录</a>
        <span class="text-slate-400 mx-1">（用户名/手机号+密码）</span>
    </p>
</div>

<script>
const { createApp, ref } = Vue;
createApp({
    setup() {
        const form = ref({ phone: '', password: '' });
        const loading = ref(false);

        const login = async () => {
            if (!form.value.phone || !form.value.password) return alert('请输入账号和密码');
            
            loading.value = true;
            try {
                const fd = new FormData();
                fd.append('phone', form.value.phone);
                fd.append('password', form.value.password);
                
                const res = await fetch('agent_login_api.php', { method: 'POST', body: fd });
                const data = await res.json();
                
                if (data.status === 'success') {
                    // [核心修改] 使用后端返回的 redirect 地址进行跳转
                    window.location.href = data.redirect; 
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