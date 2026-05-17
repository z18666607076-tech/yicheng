<?php
// admin.php - 总控台 (v12.0: 全功能合体版 - 修复数据丢失问题)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 1. 数据库配置 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

require_once __DIR__ . '/../project_image_url.php';

$__admin_welcome = trim((string)($_SESSION['admin_name'] ?? ''));
if ($__admin_welcome === '') {
    $__admin_welcome = '管理员';
}

// === 2. 路由与API处理 ===
$action = $_GET['action'] ?? 'view';
$initView = $_GET['v'] ?? 'dashboard'; // 获取侧边栏传递的视图参数

// --- [API] 仪表盘 ---
if ($action == 'get_dashboard_stats') {
    header('Content-Type: application/json');
    $total_filing = $pdo->query("SELECT count(*) FROM filings")->fetchColumn();
    $total_deal   = $pdo->query("SELECT count(*) FROM filings WHERE status = 4")->fetchColumn();
    $total_gmv    = $pdo->query("SELECT SUM(deal_price) FROM filings WHERE status = 4")->fetchColumn() ?: 0;
    $total_comm   = $pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status = 4")->fetchColumn() ?: 0;
    // 模拟图表数据
    $months = ['8月', '9月', '10月', '11月', '12月', '1月'];
    $comm_trend = ['pending' => [12, 13, 10, 13, 9, 23], 'paid' => [8, 9, 11, 12, 14, 8]]; 
    $funnel = [['name'=>'报备','value'=>$total_filing?:100], ['name'=>'有效','value'=>80], ['name'=>'成交','value'=>$total_deal?:10]];
    echo json_encode(['cards'=>['filing'=>$total_filing, 'deal'=>$total_deal, 'gmv'=>$total_gmv, 'comm'=>$total_comm], 'chart_comm'=>['months'=>$months, 'data'=>$comm_trend], 'funnel'=>$funnel]); exit;
}

// --- [API] 商户管理 ---
if ($action == 'get_companies') {
    header('Content-Type: application/json');
    $kw = $_GET['kw'] ?? '';
    $sql = "SELECT * FROM companies WHERE 1=1";
    if($kw) $sql .= " AND (name LIKE ? OR store_name LIKE ? OR contact_name LIKE ?)";
    $sql .= " ORDER BY id DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    if($kw) $stmt->execute(["%$kw%", "%$kw%", "%$kw%"]); else $stmt->execute();
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC)); exit;
}
if ($action == 'delete_company') {
    header('Content-Type: application/json');
    $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$_POST['id']]);
    echo json_encode(['status'=>'success']); exit;
}

// --- [API] 项目管理 ---
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT * FROM projects WHERE is_deleted=0 ORDER BY is_agent DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if (isset($r['image'])) {
            $r['image'] = project_image_public_url($r['image']);
        }
    }
    unset($r);
    echo json_encode($rows);
    exit;
}
if ($action == 'delete_project') {
    header('Content-Type: application/json');
    $pdo->prepare("UPDATE projects SET is_deleted=1 WHERE id=?")->execute([$_POST['id']]);
    echo json_encode(['status'=>'success']); exit;
}

// --- [API] 报备列表 ---
if ($action == 'get_filings') {
    header('Content-Type: application/json');
    $sql = "SELECT f.*, p.name as project_name, f.broker_name FROM filings f LEFT JOIN projects p ON f.project_id = p.id ORDER BY f.id DESC LIMIT 200";
    echo json_encode($pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// --- [API] 财务佣金 ---
if ($action == 'get_finance_data') {
    header('Content-Type: application/json');
    $stats = ['gmv'=>$pdo->query("SELECT SUM(deal_price) FROM filings WHERE status=4")->fetchColumn()?:0, 'pending'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=0")->fetchColumn()?:0, 'confirmed'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=1")->fetchColumn()?:0, 'paid'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=2")->fetchColumn()?:0];
    $deals = $pdo->query("SELECT f.*, p.name as project_name, f.broker_name, f.agent_id FROM filings f LEFT JOIN projects p ON f.project_id = p.id WHERE f.status = 4 ORDER BY f.commission_status ASC, f.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    // 补全 agent_name
    foreach($deals as &$d) {
        if($d['agent_id']) {
            $a = $pdo->query("SELECT username, department FROM agents WHERE id=".$d['agent_id'])->fetch(PDO::FETCH_ASSOC);
            $d['agent_name'] = $a['username'] ?? '';
            $d['department'] = $a['department'] ?? '';
        }
    }
    echo json_encode(['stats'=>$stats, 'deals'=>$deals]); exit;
}
if ($action == 'update_commission') {
    header('Content-Type: application/json');
    $pdo->prepare("UPDATE filings SET commission_status=?, commission_amount=?, commission_proof=? WHERE id=?")->execute([$_POST['status'], $_POST['amount'], $_POST['proof'], $_POST['id']]);
    echo json_encode(['status'=>'success']); exit;
}

// --- [API] 人员架构 ---
if ($action == 'get_agents') { header('Content-Type: application/json'); echo json_encode($pdo->query("SELECT * FROM agents WHERE is_deleted=0 ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC)); exit; }
if ($action == 'get_departments') { header('Content-Type: application/json'); echo json_encode($pdo->query("SELECT * FROM departments ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)); exit; }
if ($action == 'save_department') { header('Content-Type: application/json'); $pdo->prepare("INSERT INTO departments (name) VALUES (?)")->execute([$_POST['name']]); echo json_encode(['status'=>'success']); exit; }
if ($action == 'delete_department') { header('Content-Type: application/json'); $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$_POST['id']]); echo json_encode(['status'=>'success']); exit; }
if ($action == 'save_agent') {
    header('Content-Type: application/json'); $d = $_POST;
    if($d['id']) $pdo->prepare("UPDATE agents SET username=?, phone=?, department=?, role=?, password=? WHERE id=?")->execute([$d['username'],$d['phone'],$d['department'],$d['role'],$d['password'],$d['id']]);
    else $pdo->prepare("INSERT INTO agents (username,phone,department,role,password) VALUES (?,?,?,?,?)")->execute([$d['username'],$d['phone'],$d['department'],$d['role'],$d['password']]);
    echo json_encode(['status'=>'success']); exit;
}
if ($action == 'delete_agent') { header('Content-Type: application/json'); $pdo->prepare("UPDATE agents SET is_deleted=1 WHERE id=?")->execute([$_POST['id']]); echo json_encode(['status'=>'success']); exit; }

?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>易城好房·总控台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.0/dist/echarts.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        /* 进度条样式 */
        .progress-bar-bg { background-color: #e2e8f0; border-radius: 999px; height: 6px; overflow: hidden; }
        .progress-bar-fill { height: 100%; transition: width 0.5s ease; border-radius: 999px; }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm z-10 flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">{{ pageTitle }}</h2>
            </div>
            <div class="flex items-center gap-2 text-sm text-slate-600"><span class="font-medium">你好，<?php echo htmlspecialchars($__admin_welcome, ENT_QUOTES, 'UTF-8'); ?></span></div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div v-if="view==='dashboard'" class="space-y-6 fade-in">
                <div class="grid grid-cols-4 gap-6">
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex justify-between"><div><div class="text-xs text-gray-400 font-bold uppercase">累计GMV</div><div class="text-2xl font-bold text-slate-800">¥{{ (stats.cards.gmv/10000).toFixed(1) }}w</div></div><i class="fas fa-chart-line text-3xl text-blue-100"></i></div>
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex justify-between"><div><div class="text-xs text-gray-400 font-bold uppercase">应收佣金</div><div class="text-2xl font-bold text-purple-600">¥{{ (stats.cards.comm/10000).toFixed(1) }}w</div></div><i class="fas fa-coins text-3xl text-purple-100"></i></div>
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex justify-between"><div><div class="text-xs text-gray-400 font-bold uppercase">总成交</div><div class="text-2xl font-bold text-green-600">{{ stats.cards.deal }}套</div></div><i class="fas fa-handshake text-3xl text-green-100"></i></div>
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-slate-100 flex justify-between"><div><div class="text-xs text-gray-400 font-bold uppercase">总报备</div><div class="text-2xl font-bold text-orange-600">{{ stats.cards.filing }}组</div></div><i class="fas fa-users text-3xl text-orange-100"></i></div>
                </div>
                <div class="grid grid-cols-3 gap-6">
                    <div class="col-span-2 bg-white p-6 rounded-2xl shadow-sm"><h3 class="font-bold text-slate-700 mb-4">佣金趋势</h3><div id="chartComm" class="w-full h-72"></div></div>
                    <div class="col-span-1 bg-white p-6 rounded-2xl shadow-sm"><h3 class="font-bold text-slate-700 mb-4">转化漏斗</h3><div id="chartFunnel" class="w-full h-72"></div></div>
                </div>
            </div>

            <div v-if="view==='companies'" class="space-y-4 fade-in">
                <div class="flex justify-between items-center bg-white p-4 rounded-xl shadow-sm">
                    <input v-model="compSearch" @input="loadCompanies" class="border rounded-lg px-3 py-2 text-sm w-1/3 outline-none" placeholder="搜索商户名、板块、店东...">
                    <a href="admin_company_edit.php" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">+ 新增商户</a>
                </div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700"><tr><th class="px-6 py-3">公司/门店</th><th class="px-6 py-3">板块/区域</th><th class="px-6 py-3">联系人</th><th class="px-6 py-3">跟进人</th><th class="px-6 py-3 text-right">操作</th></tr></thead>
                        <tbody>
                            <tr v-for="c in companies" :key="c.id" class="hover:bg-slate-50 border-b border-gray-50">
                                <td class="px-6 py-4"><div class="font-bold text-slate-800">{{ c.name }}</div><div class="text-xs text-gray-400">{{ c.store_name }}</div></td>
                                <td class="px-6 py-4"><span class="bg-slate-100 px-2 py-1 rounded text-xs">{{ c.region_main }} / {{ c.region_sub }}</span></td>
                                <td class="px-6 py-4"><div class="font-bold">{{ c.contact_name }}</div><div class="text-xs text-blue-500">{{ c.contact_phone }}</div></td>
                                <td class="px-6 py-4">{{ c.follower }}</td>
                                <td class="px-6 py-4 text-right">
                                    <a :href="'admin_company_edit.php?id='+c.id" target="_blank" class="text-blue-600 text-xs mr-3 hover:underline">编辑</a>
                                    <button @click="delComp(c.id)" class="text-red-400 text-xs hover:underline">删除</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="view==='projects'" class="space-y-4 fade-in">
                <div class="flex justify-between items-center"><div class="text-sm text-gray-500">共 {{ projects.length }} 个项目</div><a href="admin_project_edit.php" target="_blank" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm hover:bg-blue-700">+ 新增项目</a></div>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <div v-for="p in projects" :key="p.id" class="bg-white rounded-xl shadow-sm overflow-hidden group border border-slate-100 hover:shadow-lg transition">
                        <div class="h-40 bg-gray-200 relative">
                            <img :src="p.image || 'https://via.placeholder.com/400x200'" class="w-full h-full object-cover">
                            <div class="absolute top-2 right-2 px-2 py-1 rounded text-xs font-bold text-white shadow-sm" :class="p.is_agent ? 'bg-green-500' : 'bg-gray-500'">{{ p.is_agent ? '代理在售' : '市场数据' }}</div>
                        </div>
                        <div class="p-4">
                            <h3 class="font-bold text-lg mb-1 flex justify-between">{{ p.name }}<span class="text-red-500 text-base">¥{{ p.price }}</span></h3>
                            <div class="text-xs text-gray-500 mb-3 bg-gray-50 p-2 rounded" v-if="p.is_agent">
                                <i class="fas fa-user-tie mr-1 text-blue-500"></i> 管理员: {{ p.manager_name }} 
                                <span class="ml-2"><i class="fas fa-phone mr-1"></i> {{ p.manager_phone }}</span>
                            </div>
                            <div class="text-xs text-gray-400 mb-3 italic p-2" v-else>非代理项目，仅展示市场数据</div>
                            
                            <div class="flex justify-end gap-3 text-sm border-t border-gray-100 pt-3">
                                <a :href="'admin_project_edit.php?id='+p.id" target="_blank" class="text-blue-600 hover:underline">编辑详情</a>
                                <button @click="delProject(p.id)" class="text-red-400 hover:text-red-600">删除</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div v-if="view==='finance'" class="space-y-6 fade-in">
                <div class="grid grid-cols-4 gap-4">
                    <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-blue-500"><div class="text-xs text-gray-400 font-bold uppercase">累计 GMV</div><div class="text-xl font-bold text-slate-800 mt-1">¥{{ (financeData.stats.gmv/10000).toFixed(1) }}w</div></div>
                    <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-orange-400"><div class="text-xs text-gray-400 font-bold uppercase">待确认业绩</div><div class="text-xl font-bold text-orange-600 mt-1">¥{{ (financeData.stats.pending/10000).toFixed(2) }}w</div></div>
                    <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-purple-500"><div class="text-xs text-gray-400 font-bold uppercase">待发佣金</div><div class="text-xl font-bold text-purple-600 mt-1">¥{{ (financeData.stats.confirmed/10000).toFixed(2) }}w</div></div>
                    <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-green-500"><div class="text-xs text-gray-400 font-bold uppercase">已发佣金</div><div class="text-xl font-bold text-green-600 mt-1">¥{{ (financeData.stats.paid/10000).toFixed(2) }}w</div></div>
                </div>

                <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                    <div class="px-6 py-4 border-b border-gray-100 font-bold text-slate-700">佣金结算列表</div>
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700"><tr><th class="px-6 py-3">订单信息</th><th class="px-6 py-3">归属</th><th class="px-6 py-3">金额(成交/佣金)</th><th class="px-6 py-3 w-48">结算进度</th><th class="px-6 py-3 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="deal in financeData.deals" :key="deal.id" class="hover:bg-gray-50">
                                <td class="px-6 py-4"><div class="font-bold text-slate-800">{{ deal.project_name }} {{ deal.room_number }}</div><div class="text-xs text-gray-400">{{ deal.client_name }}</div></td>
                                <td class="px-6 py-4"><div>{{ deal.department || '无部门' }}</div><div class="text-xs text-gray-400">{{ deal.agent_name || deal.broker_name }}</div></td>
                                <td class="px-6 py-4"><div class="text-slate-800">¥{{ parseFloat(deal.deal_price).toLocaleString() }}</div><div class="text-blue-600 font-bold">佣: ¥{{ parseFloat(deal.commission_amount).toLocaleString() }}</div></td>
                                <td class="px-6 py-4">
                                    <div class="flex justify-between text-[10px] mb-1 text-gray-400">
                                        <span :class="{'text-blue-600 font-bold': deal.commission_status>=0}">待确</span>
                                        <span :class="{'text-purple-600 font-bold': deal.commission_status>=1}">待发</span>
                                        <span :class="{'text-green-600 font-bold': deal.commission_status>=2}">已结</span>
                                    </div>
                                    <div class="progress-bar-bg"><div class="progress-bar-fill bg-blue-500" :style="`width: ${(parseInt(deal.commission_status)+1)*33.3}%`"></div></div>
                                </td>
                                <td class="px-6 py-4 text-right space-x-2">
                                    <button v-if="deal.commission_status==0" @click="openCommModal(deal, 1)" class="bg-blue-600 text-white px-3 py-1 rounded text-xs hover:bg-blue-700">确认</button>
                                    <button v-if="deal.commission_status==1" @click="openCommModal(deal, 2)" class="bg-purple-600 text-white px-3 py-1 rounded text-xs hover:bg-purple-700">发放</button>
                                    <button v-if="deal.commission_proof" @click="viewProof(deal.commission_proof)" class="text-gray-400 hover:text-blue-600"><i class="fas fa-file-invoice"></i></button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="view === 'agents'" class="grid grid-cols-1 md:grid-cols-4 gap-6 fade-in">
                <div class="md:col-span-1 bg-white p-4 rounded-xl shadow-sm border border-gray-200 h-fit">
                    <div class="flex justify-between items-center mb-3"><h3 class="font-bold text-slate-700">部门</h3><button @click="showDeptModal=true" class="text-xs text-blue-600 hover:underline">+ 新增</button></div>
                    <ul class="space-y-1">
                        <li v-for="dept in departments" :key="dept.id" class="flex justify-between items-center p-2 rounded cursor-pointer hover:bg-gray-50 group transition" :class="{'bg-blue-50 text-blue-700 font-bold': filterDept===dept.name}" @click="filterDept = (filterDept===dept.name ? '' : dept.name)">
                            <span>{{ dept.name }}</span>
                            <button @click.stop="delDept(dept.id)" class="text-gray-300 hover:text-red-500 opacity-0 group-hover:opacity-100 transition"><i class="fas fa-trash"></i></button>
                        </li>
                    </ul>
                </div>
                <div class="md:col-span-3 bg-white rounded-xl shadow-sm border border-gray-200 p-6">
                    <div class="flex justify-between items-center mb-4"><h3 class="font-bold text-slate-700">{{ filterDept || '所有' }}人员 <span class="text-gray-400 text-xs ml-2">({{ filteredAgents.length }})</span></h3><button @click="openAgentModal()" class="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg text-sm transition"><i class="fas fa-user-plus mr-1"></i> 新增</button></div>
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700"><tr><th>姓名</th><th>部门</th><th>手机</th><th>角色</th><th class="text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="agent in filteredAgents" :key="agent.id" class="hover:bg-gray-50">
                                <td class="py-3 font-bold text-slate-700">{{ agent.username }}</td>
                                <td class="py-3"><span class="bg-gray-100 px-2 py-0.5 rounded text-xs">{{ agent.department }}</span></td>
                                <td class="py-3">{{ agent.phone }}</td>
                                <td class="py-3">{{ agent.role }}</td>
                                <td class="py-3 text-right">
                                    <button @click="openAgentModal(agent)" class="text-blue-600 text-xs mr-2 hover:underline">编辑</button>
                                    <button @click="delAgent(agent.id)" class="text-red-400 text-xs hover:underline">删除</button>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <div v-if="view==='filings'" class="space-y-4 fade-in">
                <div class="flex justify-between items-center"><h2 class="font-bold text-slate-700">报备列表</h2><button @click="loadFilings" class="text-blue-600 text-sm"><i class="fas fa-sync"></i> 刷新</button></div>
                <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                    <table class="w-full text-sm text-left text-gray-500">
                        <thead class="bg-gray-50 text-xs uppercase text-gray-700"><tr><th class="px-6 py-3">客户</th><th class="px-6 py-3">项目</th><th class="px-6 py-3">归属</th><th class="px-6 py-3">状态</th><th class="px-6 py-3 text-right">操作</th></tr></thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="item in filings" :key="item.id" class="hover:bg-slate-50">
                                <td class="px-6 py-4 font-bold text-slate-800">{{ item.client_name }} <div class="text-xs text-gray-400 font-normal">{{ item.client_phone }}</div></td>
                                <td class="px-6 py-4">{{ item.project_name }}</td>
                                <td class="px-6 py-4">{{ item.agent_name || item.broker_name }}</td>
                                <td class="px-6 py-4"><span class="px-2 py-1 rounded text-xs border bg-gray-50">{{ statusText(item.status) }}</span></td>
                                <td class="px-6 py-4 text-right"><a :href="'admin_filing_edit.php?id='+item.id" target="_blank" class="text-blue-600 hover:underline">详情</a></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </main>

    <div v-if="showCommModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl p-6 w-[450px] shadow-2xl">
            <h3 class="font-bold text-lg mb-4 text-slate-800">{{ commStep==1?'确认业绩 & 核算佣金':'发放佣金 & 上传凭证' }}</h3>
            <div class="space-y-4">
                <div v-if="commStep==1">
                    <label class="block text-xs text-gray-500 mb-1">成交总价 (元)</label>
                    <input v-model="commForm.dealPrice" type="number" disabled class="w-full bg-gray-100 border rounded p-2 text-sm text-gray-500">
                    <label class="block text-xs text-gray-500 mt-3 mb-1">确认佣金金额 (元)</label>
                    <input v-model="commForm.amount" type="number" class="w-full bg-white border border-blue-200 rounded p-2 text-sm font-bold text-blue-600 focus:ring-2 focus:ring-blue-100 outline-none">
                </div>
                <div v-if="commStep==2">
                    <label class="block text-xs text-gray-500 mb-1">发放金额 (元)</label>
                    <input v-model="commForm.amount" type="number" disabled class="w-full bg-gray-100 border rounded p-2 text-sm font-bold text-slate-700">
                </div>
                <div class="border-2 border-dashed border-gray-200 rounded-lg h-32 flex flex-col items-center justify-center relative bg-gray-50 hover:bg-white transition cursor-pointer">
                    <div v-if="!commForm.proof" class="text-center text-gray-400"><i class="fas fa-cloud-upload-alt text-2xl mb-1"></i><p class="text-xs">{{ commStep==1 ? '上传业绩确认单' : '上传银行回单' }}</p></div>
                    <img v-else :src="commForm.proof" class="h-full object-contain p-2">
                    <input type="file" @change="handleCommUpload" class="absolute inset-0 opacity-0 w-full h-full">
                </div>
            </div>
            <div class="flex justify-end gap-2 mt-6">
                <button @click="showCommModal=false" class="px-4 py-2 bg-gray-100 rounded text-sm text-gray-600">取消</button>
                <button @click="submitComm" class="px-4 py-2 bg-slate-900 rounded text-sm text-white hover:bg-slate-800">提交</button>
            </div>
        </div>
    </div>

    <div v-if="showAgentModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white rounded-xl p-6 w-[400px] shadow-2xl">
            <h3 class="font-bold text-lg mb-4">{{ agentForm.id ? '编辑' : '新增' }}人员</h3>
            <div class="space-y-3">
                <input v-model="agentForm.username" placeholder="姓名" class="w-full border rounded p-2 text-sm">
                <input v-model="agentForm.phone" placeholder="手机" class="w-full border rounded p-2 text-sm">
                <select v-model="agentForm.department" class="w-full border rounded p-2 text-sm bg-white"><option value="" disabled>选择部门</option><option v-for="d in departments" :value="d.name">{{ d.name }}</option></select>
                <select v-model="agentForm.role" class="w-full border rounded p-2 text-sm bg-white"><option value="channel">渠道经纪人</option><option value="staff">案场人员</option><option value="finance">财务</option><option value="admin">管理员</option></select>
                <input v-model="agentForm.password" placeholder="密码" class="w-full border rounded p-2 text-sm">
            </div>
            <div class="flex justify-end gap-2 mt-6"><button @click="showAgentModal=false" class="px-4 py-2 bg-gray-100 rounded text-sm">取消</button><button @click="saveAgent" class="px-4 py-2 bg-blue-600 rounded text-sm text-white">保存</button></div>
        </div>
    </div>

    <div v-if="showDeptModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm"><div class="bg-white rounded-xl p-6 w-80 shadow-2xl"><h3 class="font-bold text-lg mb-4">新增部门</h3><input v-model="newDeptName" placeholder="部门名称" class="w-full border rounded p-2 text-sm mb-4"><div class="flex justify-end gap-2"><button @click="showDeptModal=false" class="px-4 py-2 bg-gray-100 rounded text-sm">取消</button><button @click="saveDept" class="px-4 py-2 bg-blue-600 rounded text-sm text-white">保存</button></div></div></div>

</div>

<script>
const { createApp, ref, onMounted, watch, nextTick, computed } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('<?php echo $initView; ?>');
        const stats = ref({cards:{}, funnel:[], chart_comm:{months:[],data:[]}});
        const projects = ref([]);
        const companies = ref([]);
        const compSearch = ref('');
        const filings = ref([]);
        const financeData = ref({stats:{}, deals:[]});
        const agents = ref([]);
        const departments = ref([]);
        
        // 筛选与弹窗
        const filterDept = ref('');
        const filteredAgents = computed(() => filterDept.value ? agents.value.filter(a => a.department === filterDept.value) : agents.value);
        const showCommModal = ref(false);
        const commStep = ref(1);
        const commForm = ref({id:'', dealPrice:0, amount:0, proof:''});
        const showAgentModal = ref(false);
        const agentForm = ref({});
        const showDeptModal = ref(false);
        const newDeptName = ref('');
        const pageTitle = computed(() => ({dashboard:'数据驾驶舱', projects:'项目资源库', companies:'商户管理', filings:'报备审核', finance:'佣金结算', agents:'组织架构'}[view.value]));

        // API 
        const api = (act, params='') => fetch(`?action=${act}${params}`).then(r=>r.json());

        // 加载
        const loadDash = async () => {
            stats.value = await api('get_dashboard_stats');
            nextTick(() => {
                const fD = document.getElementById('chartFunnel'); if(fD) echarts.init(fD).setOption({series:[{type:'funnel',data:stats.value.funnel}]});
                const cD = document.getElementById('chartComm'); if(cD) echarts.init(cD).setOption({xAxis:{type:'category',data:stats.value.chart_comm.months},yAxis:{},series:[{type:'line',data:stats.value.chart_comm.data.pending,name:'待确'},{type:'line',data:stats.value.chart_comm.data.paid,name:'已发'}]});
            });
        };
        const loadCompanies = async () => { companies.value = await api('get_companies', '&kw='+encodeURIComponent(compSearch.value)); };
        const loadProjects = async () => { projects.value = await api('get_projects'); };
        const loadFilings = async () => { filings.value = await api('get_filings'); };
        const loadFinance = async () => { financeData.value = await api('get_finance_data'); };
        const loadAgents = async () => { agents.value = await api('get_agents'); };
        const loadDepts = async () => { departments.value = await api('get_departments'); };

        // 动作
        const delComp = async (id) => { if(confirm('确认删除?')) { await fetch('?action=delete_company',{method:'POST', body:new URLSearchParams({id})}); loadCompanies(); }};
        const delProject = async (id) => { if(confirm('确认删除?')) { await fetch('?action=delete_project',{method:'POST', body:new URLSearchParams({id})}); loadProjects(); }};
        
        // 佣金逻辑
        const openCommModal = (deal, step) => { commStep.value=step; commForm.value={id:deal.id, dealPrice:parseFloat(deal.deal_price), amount:parseFloat(deal.commission_amount), proof:''}; showCommModal.value=true; };
        const handleCommUpload = async (e) => { const fd=new FormData(); fd.append('file',e.target.files[0]); const res=await fetch('upload.php',{method:'POST',body:fd}); const d=await res.json(); if(d.status=='success') commForm.value.proof=d.url; };
        const submitComm = async () => { const fd=new FormData(); fd.append('id',commForm.value.id); fd.append('status',commStep.value); fd.append('amount',commForm.value.amount); fd.append('proof',commForm.value.proof); await fetch('?action=update_commission',{method:'POST', body:fd}); showCommModal.value=false; loadFinance(); };
        const viewProof = (url) => window.open(url);

        // 人员逻辑
        const saveDept = async () => { const fd=new FormData(); fd.append('name',newDeptName.value); await fetch('?action=save_department',{method:'POST',body:fd}); showDeptModal.value=false; newDeptName.value=''; loadDepts(); };
        const delDept = async (id) => { if(confirm('删除部门?')) { const fd=new FormData(); fd.append('id',id); await fetch('?action=delete_department',{method:'POST',body:fd}); loadDepts(); } };
        const openAgentModal = (a=null) => { agentForm.value = a ? {...a} : {id:'',username:'',phone:'',department:departments.value[0]?.name||'',role:'channel',password:'123456'}; showAgentModal.value=true; };
        const saveAgent = async () => { const fd=new FormData(); for(let k in agentForm.value) fd.append(k, agentForm.value[k]); await fetch('?action=save_agent',{method:'POST',body:fd}); showAgentModal.value=false; loadAgents(); };
        const delAgent = async (id) => { if(confirm('删除人员?')) { const fd=new FormData(); fd.append('id',id); await fetch('?action=delete_agent',{method:'POST',body:fd}); loadAgents(); } };

        const statusText = (s) => ['待审','有效','到访','下定','成交','无效'][s]||'';

        watch(view, (v) => {
            if(v==='dashboard') loadDash(); if(v==='companies') loadCompanies(); if(v==='projects') loadProjects();
            if(v==='filings') loadFilings(); if(v==='finance') loadFinance(); if(v==='agents') { loadAgents(); loadDepts(); }
        });
        onMounted(loadDash);

        return { 
            sidebarOpen, view, pageTitle, stats, projects, companies, compSearch, filings, financeData, agents, departments,
            filterDept, filteredAgents,
            loadCompanies, delComp, delProject, loadFilings, 
            showCommModal, commStep, commForm, openCommModal, handleCommUpload, submitComm, viewProof,
            showAgentModal, agentForm, openAgentModal, saveAgent, delAgent,
            showDeptModal, newDeptName, saveDept, delDept, statusText
        };
    }
}).mount('#app');
</script>
</body>
</html>