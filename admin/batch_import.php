<?php
// batch_import.php - 批量导入报备 (v26.0: 支持Excel文件导入)
session_start();
header('Content-Type: text/html; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 1. 数据库连接 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

$action = $_GET['action'] ?? 'view';

// [API] 批量导入
if ($action == 'import') {
    header('Content-Type: application/json');
    
    try {
        // 检查文件上传
        if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('文件上传失败');
        }
        
        $file = $_FILES['file'];
        $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($fileExtension !== 'csv') {
            throw new Exception('仅支持CSV文件 (.csv)');
        }
        
        // 解析CSV文件
        $data = [];
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('文件打开失败');
        }
        
        // 读取表头
        $header = fgetcsv($handle);
        if (!$header) {
            throw new Exception('文件格式错误，无法读取表头');
        }
        
        // 读取数据（从第2行开始，跳过表头）
        $rowIndex = 2;
        while (($rowData = fgetcsv($handle)) !== FALSE) {
            // 跳过空行
            if (empty($rowData[0]) && empty($rowData[1]) && empty($rowData[2]) && empty($rowData[3])) {
                $rowIndex++;
                continue;
            }
            
            $data[] = [
                'project_name' => $rowData[0] ?? '',
                'visit_date' => $rowData[1] ?? '',
                'client_name' => $rowData[2] ?? '',
                'client_phone' => $rowData[3] ?? '',
                'company_name' => $rowData[4] ?? '',
                'broker_name' => $rowData[5] ?? '',
                'broker_phone' => $rowData[6] ?? '',
                'designated_sales' => $rowData[7] ?? ''
            ];
            
            $rowIndex++;
        }
        
        fclose($handle);
        
        if (empty($data)) {
            throw new Exception('未找到有效数据');
        }
        
        // 批量导入
        $pdo->beginTransaction();
        $successCount = 0;
        $errorCount = 0;
        $errors = [];
        
        foreach ($data as $index => $item) {
            try {
                // 数据验证
                if (empty($item['project_name'])) {
                    throw new Exception('报备项目不能为空');
                }
                if (empty($item['client_name'])) {
                    throw new Exception('客户姓名不能为空');
                }
                if (empty($item['client_phone'])) {
                    throw new Exception('客户号码不能为空');
                }
                if (empty($item['broker_name'])) {
                    throw new Exception('经纪人不能为空');
                }
                if (empty($item['broker_phone'])) {
                    throw new Exception('经纪人号码不能为空');
                }
                
                // 匹配项目
                $projectStmt = $pdo->prepare("SELECT id FROM projects WHERE name LIKE ?");
                $projectStmt->execute([$item['project_name'] . '%']);
                $project = $projectStmt->fetch();
                
                if (!$project) {
                    throw new Exception('项目不存在: ' . $item['project_name']);
                }
                
                $projectId = $project['id'];
                
                // 检查重复报备
                $checkStmt = $pdo->prepare("SELECT id FROM filings WHERE project_id=? AND client_phone=? AND broker_phone=? AND created_at > CURDATE()");
                $checkStmt->execute([$projectId, $item['client_phone'], $item['broker_phone']]);
                
                if ($checkStmt->fetch()) {
                    throw new Exception('今日已报备过此项目');
                }
                
                // 处理带看时间
                $visitDate = $item['visit_date'];
                if (empty($visitDate)) {
                    $visitDate = date('Y-m-d');
                } else {
                    // 处理不同格式的日期
                    if (strlen($visitDate) == 6) {
                        // 格式: 2026.1.30
                        $visitDate = preg_replace('/\./', '-', $visitDate);
                    }
                    // 确保日期格式正确
                    $dateParts = explode('-', $visitDate);
                    if (count($dateParts) == 3) {
                        $year = $dateParts[0];
                        $month = str_pad($dateParts[1], 2, '0', STR_PAD_LEFT);
                        $day = str_pad($dateParts[2], 2, '0', STR_PAD_LEFT);
                        $visitDate = "$year-$month-$day";
                    }
                }
                
                // 插入报备记录
                $log = date('Y-m-d H:i') . " [批量导入] 提交报备";
                $sql = "INSERT INTO filings (
                    project_id, agent_id, company_name, broker_name, broker_phone, broker_num,
                    client_name, client_phone, client_num, visit_time, designated_sales, 
                    remark, status, status_log, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";
                
                $stmt = $pdo->prepare($sql);
                $stmt->execute([
                    $projectId, 0,
                    $item['company_name'], $item['broker_name'], $item['broker_phone'], 1,
                    $item['client_name'], $item['client_phone'], 1, $visitDate, $item['designated_sales'],
                    '批量导入', $log
                ]);
                
                $successCount++;
                
            } catch (Exception $e) {
                $errorCount++;
                $errors[] = [
                    'row' => $index + 3,
                    'data' => $item,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $pdo->commit();
        
        echo json_encode([
            'status' => 'success',
            'data' => [
                'total' => count($data),
                'success' => $successCount,
                'error' => $errorCount,
                'errors' => $errors
            ]
        ]);
        
    } catch (Exception $e) {
        echo json_encode([
            'status' => 'error',
            'msg' => $e->getMessage()
        ]);
    }
    exit;
}

// [API] 获取项目列表
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    echo json_encode($pdo->query("SELECT id, name, status FROM projects ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

// [API] 获取导入历史
if ($action == 'get_history') {
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>批量导入报备</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
        
        .file-drop-area { border: 2px dashed #e2e8f0; border-radius: 1rem; padding: 3rem 2rem; text-align: center; cursor: pointer; transition: all 0.2s; }
        .file-drop-area:hover { border-color: #3b82f6; background-color: #f0f9ff; }
        .file-drop-area.active { border-color: #3b82f6; background-color: #dbeafe; }
        
        .progress-bar { height: 8px; background-color: #e2e8f0; border-radius: 4px; overflow: hidden; }
        .progress-fill { height: 100%; background-color: #3b82f6; border-radius: 4px; transition: width 0.3s ease; }
        
        .error-item { background-color: #fef2f2; border: 1px solid #fee2e2; border-radius: 0.5rem; padding: 1rem; margin-bottom: 0.5rem; }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm z-10 flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">批量导入报备</h2>
            </div>
            <div class="flex items-center gap-2"><div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold">A</div><span class="text-sm font-bold">Admin</span></div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div class="max-w-4xl mx-auto space-y-6 fade-in">
                
                <!-- 文件上传区域 -->
                <div class="fade-in">
                    <div class="bg-white rounded-xl p-6 shadow-sm space-y-4">
                        <div>
                            <label class="block text-xs font-bold text-slate-500 mb-2">上传CSV文件 <span class="text-red-500">*</span></label>
                            <div 
                                class="file-drop-area" 
                                :class="{active: isDragging}"
                                @dragover.prevent @dragenter.prevent @dragleave.prevent
                                @drop.prevent="handleDrop"
                                @click="triggerFileInput"
                            >
                                <input 
                                    ref="fileInput" 
                                    type="file" 
                                    accept=".csv" 
                                    class="hidden"
                                    @change="handleFileSelect"
                                >
                                <i class="fas fa-file-csv text-4xl text-green-400 mb-3"></i>
                                <p v-if="!selectedFile" class="text-sm text-gray-500">点击或拖拽CSV文件到此处上传</p>
                                <p v-else class="text-sm font-bold text-green-600">{{ selectedFile.name }}</p>
                                <p class="text-xs text-gray-400 mt-2">支持 .csv 格式</p>
                            </div>
                        </div>
                        
                        <div v-if="!selectedFile" class="bg-yellow-50 border border-yellow-200 rounded-xl p-4">
                            <div class="flex items-start gap-2">
                                <i class="fas fa-exclamation-triangle text-yellow-500 mt-0.5"></i>
                                <div>
                                    <h4 class="font-bold text-sm text-yellow-800">文件格式说明</h4>
                                    <p class="text-xs text-yellow-600 mt-1">请按照以下格式准备CSV文件：</p>
                                    <ul class="text-xs text-yellow-600 mt-2 space-y-1">
                                        <li>• 第1行：表头（必填）</li>
                                        <li>• 第2行开始：数据行</li>
                                        <li>• 列顺序：报备项目、带看时间、客户姓名、客户号码、经纪公司、经纪人、经纪人号码、指定销售</li>
                                        <li>• 编码格式：UTF-8</li>
                                        <li>• 分隔符：逗号 (,)</li>
                                        <li><a href="xx.csv" download="xx.csv"> csv模版文件，用 xls 打开编辑保存即可</a> </li>
                                    </ul>
                                    <p class="text-xs text-yellow-600 mt-2">提示：您可以将Excel文件另存为CSV格式后上传</p>
                                </div>
                            </div>
                        </div>
                        
                        <button 
                            @click="importData"
                            :disabled="!selectedFile || isImporting"
                            class="w-full bg-blue-600 text-white py-3 rounded-lg font-bold shadow-sm active:scale-95 transition mt-2"
                            :class="{ 'opacity-50 cursor-not-allowed': !selectedFile || isImporting }"
                        >
                            <i v-if="isImporting" class="fas fa-spinner fa-spin mr-2"></i>
                            {{ isImporting ? '导入中...' : '开始导入' }}
                        </button>
                    </div>
                </div>
                
                <!-- 导入进度 -->
                <div v-if="showProgress" class="fade-in">
                    <div class="bg-white rounded-xl p-6 shadow-sm space-y-4">
                        <h3 class="font-bold text-slate-800">导入进度</h3>
                        <div class="progress-bar">
                            <div class="progress-fill" :style="{ width: progress + '%' }"></div>
                        </div>
                        <p class="text-xs text-gray-500 text-center">{{ progressText }}</p>
                    </div>
                </div>
                
                <!-- 导入结果 -->
                <div v-if="importResult" class="fade-in">
                    <div class="bg-white rounded-xl p-6 shadow-sm space-y-4">
                        <h3 class="font-bold text-slate-800">导入结果</h3>
                        
                        <div class="grid grid-cols-3 gap-3 text-center">
                            <div class="bg-gray-50 rounded-lg p-3">
                                <div class="text-xs text-gray-500 mb-1">总数据</div>
                                <div class="font-bold text-slate-700">{{ importResult.total }}</div>
                            </div>
                            <div class="bg-green-50 rounded-lg p-3">
                                <div class="text-xs text-green-500 mb-1">成功</div>
                                <div class="font-bold text-green-700">{{ importResult.success }}</div>
                            </div>
                            <div class="bg-red-50 rounded-lg p-3">
                                <div class="text-xs text-red-500 mb-1">失败</div>
                                <div class="font-bold text-red-700">{{ importResult.error }}</div>
                            </div>
                        </div>
                        
                        <!-- 错误信息 -->
                        <div v-if="importResult.error > 0" class="space-y-2">
                            <h4 class="font-bold text-sm text-red-600">错误信息</h4>
                            <div class="max-h-60 overflow-y-auto">
                                <div 
                                    v-for="(error, index) in importResult.errors" 
                                    :key="index"
                                    class="error-item"
                                >
                                    <div class="flex justify-between items-start">
                                        <span class="text-xs font-bold text-red-600">行 {{ error.row }}</span>
                                        <span class="text-xs text-red-500">{{ error.error }}</span>
                                    </div>
                                    <div class="text-xs text-gray-600 mt-1">
                                        <div>项目: {{ error.data.project_name }}</div>
                                        <div>带看时间: {{ error.data.visit_date || '无' }}</div>
                                        <div>客户: {{ error.data.client_name }} ({{ error.data.client_phone }})</div>
                                        <div>经纪人: {{ error.data.broker_name }} ({{ error.data.broker_phone }})</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <button @click="resetImport" class="w-full bg-gray-100 text-gray-600 py-3 rounded-lg font-bold active:scale-95 transition">
                            重新导入
                        </button>
                    </div>
                </div>
                
                <!-- 导入历史 -->
                <div class="fade-in">
                    <div class="bg-white rounded-xl p-6 shadow-sm space-y-4">
                        <div class="flex justify-between items-center">
                            <h3 class="font-bold text-slate-800">导入历史</h3>
                            <button @click="loadHistory" class="text-xs text-blue-500 flex items-center gap-1">
                                <i class="fas fa-sync-alt"></i> 刷新
                            </button>
                        </div>
                        
                        <div v-if="history.length === 0" class="text-center text-gray-400 text-xs py-6">
                            暂无导入历史
                        </div>
                        
                        <div v-else class="space-y-3">
                            <div 
                                v-for="record in history" 
                                :key="record.id"
                                class="bg-gray-50 rounded-lg p-4"
                            >
                                <div class="flex justify-between items-start">
                                    <div class="font-bold text-sm text-slate-700">{{ record.created_at }}</div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs bg-green-100 text-green-600 px-2 py-0.5 rounded">成功 {{ record.success_count }}</span>
                                        <span class="text-xs bg-red-100 text-red-600 px-2 py-0.5 rounded">失败 {{ record.error_count }}</span>
                                    </div>
                                </div>
                                <div class="text-xs text-gray-500 mt-1">
                                    总数据: {{ record.total_count }}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

</div>

<script>
const { createApp, ref, onMounted } = Vue;

createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('batch_import');
        const fileInput = ref(null);
        const selectedFile = ref(null);
        const isDragging = ref(false);
        const isImporting = ref(false);
        const showProgress = ref(false);
        const progress = ref(0);
        const progressText = ref('');
        const importResult = ref(null);
        const history = ref([]);
        
        // 触发文件选择
        const triggerFileInput = () => {
            fileInput.value.click();
        };
        
        // 处理文件选择
        const handleFileSelect = (e) => {
            const file = e.target.files[0];
            if (file) {
                selectedFile.value = file;
            }
        };
        
        // 处理拖拽
        const handleDrop = (e) => {
            isDragging.value = false;
            const file = e.dataTransfer.files[0];
            if (file && file.name.toLowerCase().endsWith('.csv')) {
                selectedFile.value = file;
            } else {
                alert('请上传CSV格式的文件');
            }
        };
        
        // 导入数据
        const importData = async () => {
            if (!selectedFile.value) return;
            
            isImporting.value = true;
            showProgress.value = true;
            progress.value = 0;
            progressText.value = '准备导入...';
            importResult.value = null;
            
            try {
                const formData = new FormData();
                formData.append('file', selectedFile.value);
                
                const response = await fetch('?action=import', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    progress.value = 100;
                    progressText.value = '导入完成';
                    importResult.value = data.data;
                    loadHistory();
                } else {
                    alert('导入失败: ' + data.msg);
                }
            } catch (error) {
                alert('导入失败: ' + (error.message || '未知错误'));
                console.error(error);
            } finally {
                isImporting.value = false;
            }
        };
        
        // 重置导入
        const resetImport = () => {
            selectedFile.value = null;
            showProgress.value = false;
            progress.value = 0;
            importResult.value = null;
            fileInput.value.value = '';
        };
        
        // 加载历史
        const loadHistory = async () => {
            try {
                const response = await fetch('?action=get_history');
                const data = await response.json();
                history.value = data;
            } catch (error) {
                console.error('加载历史失败:', error);
            }
        };
        
        // 初始化
        onMounted(() => {
            loadHistory();
        });
        
        return {
            sidebarOpen,
            view,
            fileInput,
            selectedFile,
            isDragging,
            isImporting,
            showProgress,
            progress,
            progressText,
            importResult,
            history,
            triggerFileInput,
            handleFileSelect,
            handleDrop,
            importData,
            resetImport,
            loadHistory
        };
    }
}).mount('#app');
</script>
</body>
</html>