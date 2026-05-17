<?php
// index.php - 房产营销移动端 (经典布局优化版 + 语音接待录入 + 全局AI)

// === 1. 数据库配置 ===
$host = '127.0.0.1';
$db   = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

try {
    $dsn = "mysql:host=$host;dbname=$db;charset=$charset";
    $pdo = new PDO($dsn, $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}

// === 2. 后端 API 逻辑 ===
$action = $_GET['action'] ?? 'view';

// [API] 获取统计
if ($action == 'get_stats') {
    $role = $_GET['role']; 
    if ($role == 'agent') {
        $total = $pdo->query("SELECT count(*) FROM filings")->fetchColumn();
        $deal  = $pdo->query("SELECT count(*) FROM filings WHERE status = 4")->fetchColumn();
        echo json_encode([
            'total_filing' => $total,
            'total_deal'   => $deal,
            'est_commission' => $deal * 30000,
            'conversion_rate' => $total > 0 ? round(($deal/$total)*100, 1) : 0,
            'chart_x' => ['7月','8月','9月','10月','11月','12月'],
            'chart_y' => [5, 12, 8, 15, 20, $total] 
        ]);
    } else {
        echo json_encode([
            'today_visit' => 12,
            'month_deal'  => $pdo->query("SELECT count(*) FROM filings WHERE status = 4")->fetchColumn(),
            'funnel' => [
                ['value' => 100, 'name' => '报备'],
                ['value' => 60,  'name' => '到访'],
                ['value' => 20,  'name' => '下定'],
                ['value' => 15,  'name' => '签约']
            ]
        ]);
    }
    exit;
}

// [API] 获取列表
if ($action == 'get_list') {
    $stmt = $pdo->query("SELECT f.*, p.name as project_name FROM filings f LEFT JOIN projects p ON f.project_id = p.id ORDER BY f.id DESC");
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// [API] 提交报备
if ($action == 'submit') {
    $data = $_POST;
    // 判重
    $stmt = $pdo->prepare("SELECT count(*) FROM filings WHERE client_phone = ? AND project_id = ? AND created_at > CURDATE()");
    $stmt->execute([$data['phone'], $data['project_id']]);
    if ($stmt->fetchColumn() > 0) {
        echo json_encode(['status' => 'error', 'msg' => '今日已报备']);
        exit;
    }
    $log = date('Y-m-d H:i') . " [经纪人] 发起报备";
    $sql = "INSERT INTO filings (project_id, agent_id, client_name, client_phone, visit_time, remark, status, status_log) VALUES (?, 1, ?, ?, ?, ?, 1, ?)";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$data['project_id'], $data['name'], $data['phone'], $data['time'], $data['remark'], $log]);
    echo json_encode(['status' => 'success', 'msg' => '报备成功']);
    exit;
}

// [API] 状态流转 (支持语音文本)
if ($action == 'update_status') {
    $id = $_POST['id']; 
    $status = $_POST['status']; 
    $attach = $_POST['attachment'] ?? ''; 
    $voiceText = $_POST['voice_text'] ?? ''; // 接收语音转的文字

    $statusMap = [2=>'确认到访', 3=>'客户下定', 4=>'正式签约'];
    $logContent = "\n" . date('Y-m-d H:i') . " [案场] " . $statusMap[$status];
    
    // 如果有语音备注，拼接到日志中，供AI后续读取
    if($voiceText) {
        $logContent .= " (接待备注: " . $voiceText . ")"; 
    }

    $sql = "UPDATE filings SET status = ?, status_log = CONCAT(IFNULL(status_log, ''), ?), attachments = ? WHERE id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$status, $logContent, $attach, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

// [API] OCR/文本解析
if ($action == 'parse_text') {
    $text = $_POST['raw_text'];
    preg_match('/1[3-9]\d{9}/', $text, $phone_matches);
    preg_match('/([\x{4e00}-\x{9fa5}]{2,4})/u', $text, $name_matches);
    echo json_encode(['name' => $name_matches[0]??'', 'phone' => $phone_matches[0]??'', 'original' => $text]);
    exit;
}

// [API] 全局 AI 分析 (顶部按钮触发)
if ($action == 'ai_analyze_global') {
    // 模拟全盘分析
    $content = "🤖 **案场今日智能简报**\n\n" .
               "📈 **流量趋势**：今日到访 12 组，较昨日环比上涨 20%。\n\n" .
               "🔥 **接待热点**：根据语音记录分析，60% 客户关注“得房率”和“学区”，建议统一话术。\n\n" .
               "⚠️ **风险提示**：手机尾号 8821 的客户提到竞品降价，存在流失风险。\n\n" .
               "🎯 **跟进策略**：建议对今日未下定的高意向客户，在今晚 20:00 前进行一次关怀回访。";
    echo json_encode(['status'=>'success', 'content' => $content]);
    exit;
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>房产营销通</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.0/dist/echarts.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <style>
        body { -webkit-tap-highlight-color: transparent; background-color: #f3f4f6; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif; }
        
        /* 角色按钮激活态 */
        .role-btn { transition: all 0.3s; opacity: 0.6; transform: scale(0.95); filter: grayscale(100%); }
        .role-btn.active { opacity: 1; transform: scale(1); filter: grayscale(0%); }
        
        /* 录音波纹动画 */
        .mic-active { animation: pulse-red 1.5s infinite; }
        @keyframes pulse-red { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.7); } 70% { box-shadow: 0 0 0 15px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        
        /* 打字机光标 */
        .typing-cursor::after { content: '|'; animation: blink 1s infinite; }
        @keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0; } }
        
        /* 优化卡片 */
        .clean-card { background: white; border-radius: 16px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); border: 1px solid #f1f5f9; }
    </style>
</head>
<body>

<div id="app" class="max-w-md mx-auto min-h-screen relative pb-24 bg-gray-100">
    
    <div class="sticky top-0 z-40 bg-slate-900 p-3 shadow-lg rounded-b-2xl">
        <div class="flex justify-between items-center mb-2 px-2">
            <div class="text-white font-bold text-lg flex items-center gap-2"><i class="fas fa-building text-blue-500"></i> 营销通</div>
            <button v-if="role === 'staff'" @click="openGlobalAi" class="bg-gradient-to-r from-violet-600 to-fuchsia-600 text-white px-3 py-1.5 rounded-full text-xs font-bold shadow-lg flex items-center gap-1 active:scale-95 transition">
                <i class="fas fa-sparkles"></i> AI 全盘分析
            </button>
        </div>
        
        <div class="flex justify-center gap-8">
            <button @click="switchRole('agent')" :class="{active: role==='agent'}" class="role-btn flex flex-col items-center text-white">
                <div class="w-10 h-10 rounded-full bg-blue-600 flex items-center justify-center text-lg mb-1 border-2 border-slate-800">👨‍💼</div>
                <span class="text-xs font-medium">经纪人</span>
            </button>
            <button @click="switchRole('staff')" :class="{active: role==='staff'}" class="role-btn flex flex-col items-center text-white relative">
                <div class="w-10 h-10 rounded-full bg-purple-600 flex items-center justify-center text-lg mb-1 border-2 border-slate-800">👩‍💼</div>
                <span class="text-xs font-medium">案场端</span>
                <span v-if="pendingCount > 0" class="absolute top-0 right-0 w-4 h-4 bg-red-500 rounded-full text-[10px] flex items-center justify-center border border-slate-900">{{ pendingCount }}</span>
            </button>
        </div>
    </div>

    <div v-if="role === 'agent'" class="p-4 space-y-4">
        <div class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-white border-t flex justify-around py-2 text-xs text-gray-400 z-30">
            <div @click="agentTab='dash'" :class="{'text-blue-600': agentTab==='dash'}" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-chart-pie text-xl"></i> 数据</div>
            <div @click="agentTab='form'" :class="{'text-blue-600': agentTab==='form'}" class="flex flex-col items-center gap-1 cursor-pointer">
                <div class="bg-blue-600 text-white rounded-full w-12 h-12 flex items-center justify-center -mt-6 border-4 border-gray-100 shadow-lg"><i class="fas fa-plus text-xl"></i></div> 报备
            </div>
            <div @click="agentTab='list'" :class="{'text-blue-600': agentTab==='list'}" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-list-alt text-xl"></i> 记录</div>
        </div>

        <div v-show="agentTab === 'dash'" class="space-y-4">
            <div class="clean-card p-4">
                <div class="text-gray-400 text-xs mb-1">本月预估佣金</div>
                <div class="text-3xl font-bold text-slate-800">¥{{ (stats.est_commission || 0).toLocaleString() }}</div>
            </div>
            <div class="clean-card p-4"><h3 class="font-bold text-sm mb-4">近半年趋势</h3><div id="agentTrendChart" class="w-full h-40"></div></div>
        </div>

        <div v-show="agentTab === 'form'">
            <div class="clean-card overflow-hidden">
                <div class="bg-blue-600 p-4 text-white font-bold">新客户报备</div>
                <div class="p-4 space-y-4">
                    <div class="flex bg-gray-100 p-1 rounded-lg text-xs font-bold text-gray-500">
                        <button v-for="m in ['text','voice','ocr']" :key="m" @click="reportMode=m" class="flex-1 py-2 rounded transition-all" :class="reportMode===m?'bg-white text-blue-600 shadow-sm':''">{{ {'text':'文字','voice':'语音','ocr':'拍照'}[m] }}</button>
                    </div>

                    <div v-if="reportMode==='voice'" class="bg-blue-50 border-2 border-dashed border-blue-200 rounded-xl p-6 text-center h-32 flex flex-col items-center justify-center">
                        <button @mousedown="startRecord" @touchstart.prevent="startRecord" @mouseup="stopRecord" @touchend="stopRecord" class="w-14 h-14 rounded-full bg-blue-600 text-white text-2xl flex items-center justify-center shadow-lg active:scale-95"><i class="fas fa-microphone"></i></button>
                        <p class="text-xs text-blue-400 mt-2">{{ isRecording ? '正在听...' : '长按录入' }}</p>
                    </div>

                    <div v-if="reportMode==='text'">
                        <textarea v-model="pasteText" placeholder="粘贴：王总 13800001111 明天下午看房" class="w-full h-24 p-3 bg-gray-50 rounded-xl text-sm border-none focus:ring-2 focus:ring-blue-100"></textarea>
                        <button @click="smartParse" class="text-xs text-blue-600 font-bold mt-2">✨ 智能解析</button>
                    </div>
                    
                    <div v-if="reportMode==='ocr'" class="bg-gray-50 border-2 border-dashed border-gray-200 rounded-xl h-32 flex flex-col items-center justify-center relative">
                        <i class="fas fa-camera text-gray-300 text-2xl"></i><span class="text-xs text-gray-400 mt-1">上传名片</span>
                        <input type="file" @change="handleOCRUpload" class="absolute inset-0 opacity-0 w-full h-full">
                    </div>

                    <div class="space-y-3 pt-2">
                        <select v-model="form.project_id" class="w-full p-3 bg-gray-50 rounded-xl font-bold text-sm"><option value="1">滨江一号</option><option value="2">未来科技城</option></select>
                        <div class="flex gap-3"><input v-model="form.name" type="text" placeholder="姓名" class="flex-1 p-3 bg-gray-50 rounded-xl text-sm font-bold"><input v-model="form.phone" type="tel" placeholder="手机" class="flex-1 p-3 bg-gray-50 rounded-xl text-sm font-bold"></div>
                        <input type="datetime-local" v-model="form.time" class="w-full p-3 bg-gray-50 rounded-xl text-sm">
                        <input type="text" v-model="form.remark" placeholder="备注" class="w-full p-3 bg-gray-50 rounded-xl text-sm">
                    </div>
                    <button @click="submitFiling" class="w-full bg-slate-800 text-white py-4 rounded-xl font-bold shadow-lg mt-2">提交报备</button>
                </div>
            </div>
        </div>

        <div v-show="agentTab === 'list'" class="space-y-4">
             <div class="flex items-center bg-white p-2 rounded-xl shadow-sm"><i class="fas fa-search text-gray-300 ml-2"></i><input v-model="agentSearchName" type="text" placeholder="搜客户..." class="flex-1 p-2 text-sm outline-none"></div>
             <div v-for="item in filteredAgentFilings" :key="item.id" class="clean-card p-4 border-l-4" :class="statusColor(item.status)">
                <div class="flex justify-between items-start mb-3">
                    <div><div class="font-bold text-slate-800">{{ item.client_name }} <span class="text-xs font-normal text-gray-400">{{ item.client_phone }}</span></div><div class="text-xs text-gray-400">{{ item.project_name }}</div></div>
                    <span class="px-2 py-1 rounded text-xs font-bold" :class="statusBg(item.status)">{{ statusText(item.status) }}</span>
                </div>
                <div class="flex justify-between items-center relative px-2">
                    <div class="absolute top-1.5 left-0 w-full h-0.5 bg-gray-100 -z-10"></div>
                    <div v-for="s in 4" :key="s" class="flex flex-col items-center gap-1"><div class="w-3 h-3 rounded-full border-2 border-white box-content" :class="item.status>=s?'bg-blue-600':'bg-gray-200'"></div><span class="text-[10px]" :class="item.status>=s?'text-blue-600 font-bold':'text-gray-300'">{{ ['报备','到访','下定','成交'][s-1] }}</span></div>
                </div>
             </div>
        </div>
    </div>

    <div v-if="role === 'staff'" class="p-4 space-y-6">
        <div class="fixed bottom-0 left-0 right-0 max-w-md mx-auto bg-white border-t flex justify-around py-2 text-xs text-gray-500 z-30">
            <div @click="staffTab='dash'" :class="{'text-purple-600': staffTab==='dash'}" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-chart-line text-xl"></i> 总控</div>
            <div @click="staffTab='work'" :class="{'text-purple-600': staffTab==='work'}" class="flex flex-col items-center gap-1 cursor-pointer relative">
                <div class="bg-purple-600 text-white rounded-full w-12 h-12 flex items-center justify-center -mt-6 border-4 border-gray-100 shadow-lg"><i class="fas fa-check text-xl"></i></div> 工作台 <span v-if="pendingCount > 0" class="absolute top-0 right-4 bg-red-500 w-2 h-2 rounded-full"></span>
            </div>
            <div @click="staffTab='history'" :class="{'text-purple-600': staffTab==='history'}" class="flex flex-col items-center gap-1 cursor-pointer"><i class="fas fa-clock text-xl"></i> 记录</div>
        </div>

        <div v-if="staffTab === 'work'">
            <div class="flex bg-gray-200 p-1 rounded-xl mb-4 text-xs font-bold text-gray-500">
                <div @click="workSubTab=1" class="flex-1 py-2 text-center rounded-lg transition-all" :class="{'bg-white text-purple-600 shadow-sm': workSubTab==1}">待接待 ({{ status1List.length }})</div>
                <div @click="workSubTab=2" class="flex-1 py-2 text-center rounded-lg transition-all" :class="{'bg-white text-purple-600 shadow-sm': workSubTab==2}">待下定 ({{ status2List.length }})</div>
                <div @click="workSubTab=3" class="flex-1 py-2 text-center rounded-lg transition-all" :class="{'bg-white text-purple-600 shadow-sm': workSubTab==3}">待签约 ({{ status3List.length }})</div>
            </div>

            <div class="space-y-3 pb-20">
                <div v-if="currentWorkList.length==0" class="text-center py-10 text-gray-400 text-xs">暂无任务</div>
                <div v-for="item in currentWorkList" :key="item.id" class="clean-card p-5 relative">
                    <div class="flex justify-between items-start mb-2">
                        <div><h3 class="font-bold text-slate-800">{{ item.client_name }} <span class="text-xs text-gray-400 font-normal">{{ item.client_phone }}</span></h3></div>
                        <span class="text-[10px] bg-blue-50 text-blue-600 px-2 py-1 rounded font-bold">预计 {{ item.visit_time.substring(11,16) }}</span>
                    </div>
                    <div class="flex gap-3 mt-4">
                        <button @click="showTimeline(item)" class="flex-1 bg-gray-50 text-gray-500 py-3 rounded-xl text-xs font-bold">📜 记录</button>
                        <button @click="openModal(nextActionType, item)" class="flex-[2] bg-slate-900 text-white py-3 rounded-xl text-xs font-bold shadow-lg active:scale-95 transition">
                            {{ nextActionText }}
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="staffTab === 'dash'" class="space-y-4">
            <div class="grid grid-cols-2 gap-3"><div class="clean-card p-4 text-center"><div class="text-gray-400 text-xs">今日到访</div><div class="text-3xl font-bold">{{ stats.today_visit }}</div></div><div class="clean-card p-4 text-center"><div class="text-gray-400 text-xs">本月成交</div><div class="text-3xl font-bold text-green-600">{{ stats.month_deal }}</div></div></div>
            <div class="clean-card p-4"><h3 class="font-bold text-sm mb-2">转化漏斗</h3><div id="staffFunnelChart" class="w-full h-48"></div></div>
        </div>
        <div v-if="staffTab === 'history'" class="space-y-3 pb-20">
             <div v-for="item in filings" :key="item.id" @click="showTimeline(item)" class="clean-card p-4 flex justify-between items-center active:bg-gray-50">
                <div><div class="font-bold text-sm">{{ item.client_name }}</div><div class="text-xs text-gray-400">{{ item.project_name }}</div></div>
                <span class="text-[10px] px-2 py-1 rounded bg-gray-100 text-gray-500 font-bold">{{ statusText(item.status) }}</span>
             </div>
        </div>
    </div>

    <div v-if="showModal && modalType!='timeline'" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white w-full rounded-t-3xl p-6 animate-slide-up">
            <h3 class="text-lg font-bold mb-1">{{ modalTitle }}</h3>
            <p class="text-xs text-gray-400 mb-4">客户: {{ currentItem.client_name }}</p>

            <div class="space-y-4">
                <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                    <div class="flex justify-between items-center mb-2">
                        <span class="text-xs font-bold text-slate-500">接待语音备注 (AI转文字)</span>
                        <div v-if="isRecording" class="flex gap-1"><div class="w-1.5 h-1.5 bg-red-500 rounded-full animate-bounce"></div><div class="w-1.5 h-1.5 bg-red-500 rounded-full animate-bounce delay-100"></div></div>
                    </div>
                    <div class="flex gap-3">
                        <button @click="toggleRecordInModal" class="w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center transition-all" :class="isRecording ? 'bg-red-500 text-white shadow-lg shadow-red-500/30 mic-active' : 'bg-white border border-slate-200 text-slate-400'">
                            <i class="fas" :class="isRecording ? 'fa-stop' : 'fa-microphone'"></i>
                        </button>
                        <textarea v-model="voiceNote" placeholder="点击麦克风说话，例如：客户对价格满意，主要顾虑是楼层..." class="flex-1 bg-transparent text-sm outline-none resize-none h-16 placeholder:text-slate-300"></textarea>
                    </div>
                </div>

                <div class="border-2 border-dashed border-slate-200 rounded-2xl h-24 flex flex-col items-center justify-center relative bg-white">
                    <div v-if="!uploadImg" class="text-center"><i class="fas fa-camera text-slate-300 text-xl mb-1"></i><p class="text-[10px] text-slate-400">上传凭证 (可选)</p></div>
                    <img v-else :src="uploadImg" class="h-full object-contain p-2">
                    <input type="file" @change="handleFile" class="absolute inset-0 opacity-0 w-full h-full">
                </div>
            </div>

            <div class="flex gap-3 mt-6">
                <button @click="showModal=false" class="flex-1 py-3 bg-gray-100 rounded-xl text-gray-500 font-bold text-sm">取消</button>
                <button @click="submitAction" class="flex-[2] bg-slate-900 text-white py-3 rounded-xl font-bold text-sm shadow-lg active:scale-95 transition">确认提交</button>
            </div>
        </div>
    </div>

    <div v-if="showAiModal" class="fixed inset-0 z-50 flex items-center justify-center px-6 bg-black/70 backdrop-blur-md">
        <div class="bg-slate-900 w-full rounded-3xl border border-slate-700 shadow-2xl relative overflow-hidden">
            <div class="bg-slate-800 p-4 flex justify-between items-center border-b border-slate-700">
                <div class="flex items-center gap-2"><i class="fas fa-sparkles text-emerald-400"></i><span class="text-emerald-400 font-bold text-xs tracking-widest">AI INTELLIGENCE</span></div>
                <button @click="showAiModal=false" class="text-slate-500"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 min-h-[250px] text-slate-300 text-sm leading-relaxed font-mono relative">
                <div v-if="aiLoading" class="absolute inset-0 flex flex-col items-center justify-center"><i class="fas fa-circle-notch fa-spin text-2xl text-emerald-500 mb-2"></i><p class="text-xs text-emerald-500">正在分析...</p></div>
                <div v-else v-html="aiOutput" class="typing-cursor whitespace-pre-wrap"></div>
            </div>
        </div>
    </div>

    <div v-if="showModal && modalType=='timeline'" class="fixed inset-0 z-50 flex items-end justify-center bg-black/50 backdrop-blur-sm">
        <div class="bg-white w-full rounded-t-3xl p-6 max-h-[70vh] overflow-y-auto">
            <h3 class="text-lg font-bold mb-4 text-slate-800">流转记录</h3>
            <div class="space-y-6 pl-2 border-l-2 border-slate-100 ml-2">
                <div v-for="(log, idx) in parseLog(currentItem.status_log)" :key="idx" class="relative pl-6">
                    <div class="absolute -left-[5px] top-1.5 w-2.5 h-2.5 rounded-full border-2 border-white box-content" :class="idx===0?'bg-slate-900':'bg-slate-300'"></div>
                    <div class="text-xs text-slate-400 font-mono mb-0.5">{{ log.time }}</div>
                    <div class="text-sm font-bold text-slate-700">{{ log.action }}</div>
                    <div v-if="log.action.includes('备注:')" class="mt-1 text-xs text-slate-500 bg-slate-50 p-2 rounded">{{ log.action.split('备注:')[1] }}</div>
                </div>
            </div>
            <button @click="showModal=false" class="w-full mt-6 py-3 bg-slate-100 rounded-xl text-slate-500 font-bold text-sm">关闭</button>
        </div>
    </div>

</div>

<script>
    const { createApp, ref, computed, onMounted, nextTick, watch } = Vue;

    createApp({
        setup() {
            const role = ref('staff'); // 默认看案场
            const agentTab = ref('dash');
            const staffTab = ref('work');
            const workSubTab = ref(1);
            const reportMode = ref('voice');
            
            const agentSearchName = ref('');
            const filings = ref([]);
            const stats = ref({});
            const form = ref({ project_id: 1, name: '', phone: '', time: '', remark: '' });
            const pasteText = ref('');
            
            const showModal = ref(false);
            const modalType = ref('');
            const modalTitle = ref('');
            const currentItem = ref({});
            const uploadImg = ref('');
            const isRecording = ref(false);
            const voiceNote = ref(''); // 弹窗内的语音备注
            
            const showAiModal = ref(false);
            const aiLoading = ref(false);
            const aiOutput = ref('');

            const initTime = () => { const now = new Date(); now.setMinutes(now.getMinutes() - now.getTimezoneOffset()); form.value.time = now.toISOString().slice(0, 16); };
            const loadData = async () => { const res = await fetch('?action=get_list'); filings.value = await res.json(); loadStats(); };
            const loadStats = async () => { const res = await fetch(`?action=get_stats&role=${role.value}`); stats.value = await res.json(); renderCharts(); };

            const status1List = computed(() => filings.value.filter(i => i.status == 1));
            const status2List = computed(() => filings.value.filter(i => i.status == 2));
            const status3List = computed(() => filings.value.filter(i => i.status == 3));
            const pendingCount = computed(() => status1List.value.length);
            const currentWorkList = computed(() => workSubTab.value===1 ? status1List.value : (workSubTab.value===2 ? status2List.value : status3List.value));
            const nextActionType = computed(() => ['visit','deposit','deal'][workSubTab.value-1]);
            const nextActionText = computed(() => ['确认到访','录入下定','录入成交'][workSubTab.value-1]);
            const filteredAgentFilings = computed(() => filings.value.filter(i => !agentSearchName.value || i.client_name.includes(agentSearchName.value)));

            const switchRole = (r) => { role.value = r; loadData(); };
            
            // 语音模拟
            const startRecord = () => { isRecording.value = true; };
            const stopRecord = () => { 
                isRecording.value = false; 
                setTimeout(() => { 
                    const t = "客户对户型很满意，觉得89平米正好，就是楼层想高一点。";
                    if(role.value==='agent') form.value.remark = t; 
                    else voiceNote.value = t; 
                }, 800); 
            };
            const toggleRecordInModal = () => { if(isRecording.value) stopRecord(); else startRecord(); };

            // OCR
            const handleOCRUpload = async (e) => {
                const fd = new FormData(); fd.append('file', e.target.files[0]);
                await fetch('upload.php?action=ocr', {method:'POST', body:fd}); form.value.name='张先生'; form.value.phone='13912345678';
            };
            const smartParse = async () => {
                if(!pasteText.value) return; const fd = new FormData(); fd.append('raw_text', pasteText.value);
                const res = await fetch('?action=parse_text', {method:'POST', body:fd}); const d = await res.json();
                form.value.name = d.name; form.value.phone = d.phone; form.value.remark = d.original;
            };

            // 全局 AI
            const openGlobalAi = async () => {
                showAiModal.value = true; aiLoading.value = true; aiOutput.value = '';
                const res = await fetch('?action=ai_analyze_global');
                const data = await res.json();
                const fullText = marked.parse(data.content);
                setTimeout(() => { aiLoading.value = false; aiOutput.value = fullText; }, 1000);
            };

            // Modal Action
            const openModal = (type, item) => {
                modalType.value = type; currentItem.value = item; uploadImg.value = ''; voiceNote.value = ''; showModal.value = true;
                if(type=='visit') modalTitle.value = '确认到访'; if(type=='deposit') modalTitle.value = '录入下定'; if(type=='deal') modalTitle.value = '录入成交';
            };
            const submitAction = async () => {
                let s=0; if(modalType.value=='visit')s=2; if(modalType.value=='deposit')s=3; if(modalType.value=='deal')s=4;
                const fd=new FormData(); fd.append('id',currentItem.value.id); fd.append('status',s); 
                fd.append('attachment',uploadImg.value); fd.append('voice_text', voiceNote.value);
                await fetch('?action=update_status',{method:'POST',body:fd}); showModal.value=false; loadData();
            };
            const handleFile = async (e) => { const fd=new FormData(); fd.append('file',e.target.files[0]); const res=await fetch('upload.php',{method:'POST',body:fd}); const d=await res.json(); uploadImg.value=d.url; };

            const submitFiling = async () => {
                const fd = new FormData(); for(let k in form.value) fd.append(k, form.value[k]);
                await fetch('?action=submit', {method:'POST', body:fd}); alert('报备成功'); agentTab.value='list'; loadData(); form.value={project_id:1,name:'',phone:'',time:'',remark:''};
            };
            const showTimeline = (item) => { currentItem.value = item; modalType.value = 'timeline'; showModal.value = true; };
            const parseLog = (l) => l ? l.split('\n').filter(s=>s.trim()).map(s=>{ const p=s.split(']'); return {time:p[0]?.replace('[','').trim().substring(0,16), action:s}; }).reverse() : [];
            
            const renderCharts = () => {
                nextTick(() => {
                    const dom = role.value==='agent' ? document.getElementById('agentTrendChart') : document.getElementById('staffFunnelChart');
                    if(dom) echarts.init(dom).setOption({tooltip:{},xAxis:{show:false,data:stats.value.chart_x||[]},yAxis:{show:false},series:[role.value==='agent'?{type:'line',smooth:true,areaStyle:{color:'#3b82f6'},data:stats.value.chart_y}:{type:'funnel',data:stats.value.funnel}]});
                });
            };
            const statusText = (s) => ['待审','有效','到访','下定','成交','无效'][s]||'';
            const statusBg = (s) => ['bg-slate-100 text-slate-500','bg-blue-50 text-blue-600','bg-yellow-50 text-yellow-600','bg-orange-50 text-orange-600','bg-green-50 text-green-600','bg-red-50 text-red-600'][s];
            const statusColor = (s, line=false) => line ? `bg-${['slate','blue','yellow','orange','green','red'][s]}-500` : `border-${['slate','blue','yellow','orange','green','red'][s]}-500`;

            watch([agentTab, staffTab], () => renderCharts());
            onMounted(() => { initTime(); loadData(); });

            return {
                role, agentTab, staffTab, workSubTab, reportMode,
                agentSearchName, filteredAgentFilings, currentWorkList, nextActionType, nextActionText,
                filings, stats, form, pasteText, isRecording, voiceNote,
                showModal, modalType, modalTitle, currentItem, uploadImg,
                status1List, status2List, status3List, pendingCount,
                showAiModal, aiLoading, aiOutput,
                switchRole, startRecord, stopRecord, toggleRecordInModal, submitFiling, smartParse, handleOCRUpload,
                openModal, handleFile, submitAction, showTimeline, parseLog, 
                openGlobalAi,
                statusText, statusBg, statusColor
            };
        }
    }).mount('#app');
</script>
</body>
</html>