<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>易成好房全盘通</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #f8fafc 0%, #e2e8f0 100%); font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        .role-card { transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); }
        .role-card:active { transform: scale(0.96); }
        .glass-panel { background: rgba(255, 255, 255, 0.85); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.5); }
    </style>
</head>
<body class="min-h-screen flex flex-col items-center justify-center p-6">

    <div class="w-full max-w-md space-y-8">
        
        <div class="text-center space-y-2 animate-[fadeIn_0.5s_ease-out]">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-gradient-to-br from-blue-600 to-indigo-700 text-white shadow-xl shadow-blue-500/30 mb-4">
                <i class="fas fa-cube text-3xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-slate-800 tracking-tight">易成好房全盘通</h1>
            <p class="text-sm text-slate-500">全流程房地产营销效能管理平台</p>
        </div>

        <div class="space-y-4">
            
            <a href="agent.php" class="role-card block glass-panel p-5 rounded-2xl shadow-sm hover:shadow-md group relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-blue-50 rounded-full -mr-8 -mt-8 transition-transform group-hover:scale-150 duration-500"></div>
                <div class="flex items-center gap-4 relative z-10">
                    <div class="w-12 h-12 rounded-xl bg-blue-100 text-blue-600 flex items-center justify-center text-xl shadow-inner">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-lg">渠道工作台</h3>
                        <p class="text-xs text-slate-500">客户报备 / 业绩看板 / 佣金查询</p>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300"></i>
                </div>
            </a>

            <a href="staff.php" class="role-card block glass-panel p-5 rounded-2xl shadow-sm hover:shadow-md group relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-purple-50 rounded-full -mr-8 -mt-8 transition-transform group-hover:scale-150 duration-500"></div>
                <div class="flex items-center gap-4 relative z-10">
                    <div class="w-12 h-12 rounded-xl bg-purple-100 text-purple-600 flex items-center justify-center text-xl shadow-inner">
                        <i class="fas fa-user-shield"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-lg">驻场工作台</h3>
                        <p class="text-xs text-slate-500">接访确客 / 竞品录入 / 复看管理</p>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300"></i>
                </div>
            </a>

            <!-- <a href="admin.php" class="role-card block glass-panel p-5 rounded-2xl shadow-sm hover:shadow-md group relative overflow-hidden">
                <div class="absolute right-0 top-0 w-24 h-24 bg-emerald-50 rounded-full -mr-8 -mt-8 transition-transform group-hover:scale-150 duration-500"></div>
                <div class="flex items-center gap-4 relative z-10">
                    <div class="w-12 h-12 rounded-xl bg-emerald-100 text-emerald-600 flex items-center justify-center text-xl shadow-inner">
                        <i class="fas fa-chart-pie"></i>
                    </div>
                    <div class="flex-1">
                        <h3 class="font-bold text-slate-800 text-lg">企业总控台</h3>
                        <p class="text-xs text-slate-500">效能漏斗 / 财务结算 / 项目配置</p>
                    </div>
                    <i class="fas fa-chevron-right text-slate-300"></i>
                </div>
            </a> -->

        </div>

        <div class="text-center pt-8">
            <p class="text-[10px] text-slate-400">© 2025 YCHF Technology. All rights reserved.</p>
            <div class="flex justify-center gap-4 mt-2 text-[10px] text-slate-400">
                <span><i class="fas fa-shield-alt mr-1"></i>数据安全</span>
                <span><i class="fas fa-bolt mr-1"></i>极速响应</span>
            </div>
        </div>

    </div>

</body>
</html>