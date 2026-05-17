<?php
// import.php - 全字段精准导入工具 (v3.0)
session_start();
header('Content-Type: text/html; charset=utf-8');
ini_set('max_execution_time', 600);

if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION); } catch (PDOException $e) { die("DB Error"); }

$msg = '';

// === 核心：读取一行并自动转码 ===
function get_csv_line($handle) {
    $line = fgets($handle);
    if ($line === false) return false;
    // 移除 BOM
    $bom = pack('H*','EFBBBF'); $line = preg_replace("/^$bom/", '', $line);
    // 转码 GBK -> UTF-8
    $enc = mb_detect_encoding($line, ['UTF-8', 'GBK', 'GB2312', 'BIG5'], true);
    if ($enc && $enc !== 'UTF-8') $line = mb_convert_encoding($line, 'UTF-8', $enc);
    return str_getcsv(trim($line)); // 解析 CSV
}

// === 处理上传 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $type = $_POST['import_type'];
    $file = $_FILES['csv_file']['tmp_name'];
    
    if (is_uploaded_file($file)) {
        $handle = fopen($file, "r");
        $succ = 0; $fail = 0; $row = 0;
        $pdo->beginTransaction();

        try {
            while (($data = get_csv_line($handle)) !== false) {
                $row++;
                // 跳过表头和空行（以板块与公司全称是否同时为空为准）
                if ($row === 1) continue;
                if (trim($data[0] ?? '') === '' && trim($data[2] ?? '') === '') continue;

                // === 1. 商户明细 ===
                // 新格式(≥14列): [0]板块…[6]加盟, [7]关系评级, [8]战力评级, [9]门店人数, [10]地址, [11]店东, [12]电话, [13]跟进人（后可跟变动、特殊对接等列）
                // 旧格式(11列): [0]板块…[6]加盟, [7]地址, [8]店东, [9]电话, [10]跟进人
                if ($type === 'company') {
                    $name = trim($data[2] ?? '');
                    if (!$name) { $fail++; continue; } // 公司名必填

                    $n = count($data);
                    if ($n >= 14) {
                        $rel = trim((string)($data[7] ?? ''));
                        $pow = trim((string)($data[8] ?? ''));
                        $cnt = trim((string)($data[9] ?? ''));
                        $parts = array_filter([$rel, $pow, $cnt], function ($x) { return $x !== ''; });
                        $business_status = implode('/', $parts);
                        if (function_exists('mb_strlen') && mb_strlen($business_status, 'UTF-8') > 50) {
                            $business_status = mb_substr($business_status, 0, 50, 'UTF-8');
                        } elseif (strlen($business_status) > 50) {
                            $business_status = substr($business_status, 0, 50);
                        }
                        $address = (string)($data[10] ?? '');
                        $contact_name = (string)($data[11] ?? '');
                        $contact_phone = (string)($data[12] ?? '');
                        $follower = (string)($data[13] ?? '');
                    } else {
                        $business_status = '';
                        $address = (string)($data[7] ?? '');
                        $contact_name = (string)($data[8] ?? '');
                        $contact_phone = (string)($data[9] ?? '');
                        $follower = (string)($data[10] ?? '');
                    }

                    $sql = "INSERT INTO companies (
                                region_main, region_sub, name, store_name, store_type, 
                                related_store, franchise_brand, business_status, address, contact_name, contact_phone, follower
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                region_main=VALUES(region_main), region_sub=VALUES(region_sub),
                                store_type=VALUES(store_type), related_store=VALUES(related_store),
                                franchise_brand=VALUES(franchise_brand), business_status=VALUES(business_status),
                                address=VALUES(address), contact_name=VALUES(contact_name),
                                contact_phone=VALUES(contact_phone), follower=VALUES(follower)";
                    
                    $stmt = $pdo->prepare($sql);
                    $stmt->execute([
                        $data[0] ?? '', // 板块
                        $data[1] ?? '', // 地区
                        $name,          // 公司全称
                        $data[3] ?? '', // 门店
                        $data[4] ?? '', // 有无门店
                        $data[5] ?? '', // 关联门店
                        $data[6] ?? '', // 加盟
                        $business_status,
                        $address,
                        $contact_name,
                        $contact_phone,
                        $follower
                    ]);
                    $succ++;
                }

                // === 2. 代理项目 (3个字段) ===
                // CSV顺序: [0]项目名称, [1]项目管理员, [2]管理员号码
                elseif ($type === 'agent_project') {
                    $name = trim($data[0] ?? '');
                    if (!$name) continue;

                    $sql = "INSERT INTO projects (name, manager_name, manager_phone, is_agent, status) 
                            VALUES (?, ?, ?, 1, 1)
                            ON DUPLICATE KEY UPDATE 
                            manager_name=VALUES(manager_name), manager_phone=VALUES(manager_phone), is_agent=1";
                    $pdo->prepare($sql)->execute([$name, $data[1]??'', $data[2]??'']);
                    $succ++;
                }

                // === 3. 市场项目 (1个字段) ===
                // CSV顺序: [0]项目名称
                elseif ($type === 'market_project') {
                    $name = trim($data[0] ?? '');
                    if (!$name) continue;

                    // 仅当项目不存在时插入，且标记 is_agent=0
                    $sql = "INSERT IGNORE INTO projects (name, is_agent, status) VALUES (?, 0, 1)";
                    $pdo->prepare($sql)->execute([$name]);
                    $succ++;
                }
            }
            $pdo->commit();
            $msg = "<div class='text-green-600 font-bold p-4 bg-green-50 rounded'>成功导入/更新：{$succ} 条数据</div>";
        } catch (Exception $e) {
            $pdo->rollBack();
            $msg = "<div class='text-red-600 font-bold p-4 bg-red-50 rounded'>第 {$row} 行出错: " . $e->getMessage() . "</div>";
        }
        fclose($handle);
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>全字段数据导入</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f1f5f9; font-family: sans-serif; }
        .fade-in { animation: fadeIn 0.3s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm z-10 flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">数据导入 (全字段匹配版)</h2>
            </div>
            <div class="flex items-center gap-2"><div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold">A</div><span class="text-sm font-bold">Admin</span></div>
        </header>

        <div class="flex-1 overflow-auto p-8">
            
            <div class="max-w-4xl mx-auto space-y-6 fade-in">
                
                <?= $msg ?>

                <div class="grid gap-6">
                    <div class="bg-white p-6 rounded-xl border border-slate-200">
                        <h3 class="font-bold mb-2">1. 导入商户明细</h3>
                        <p class="text-xs text-gray-400 mb-4">对应列：板块, 地区, 公司全称, 门店, 有无门店, 关联门店, 加盟, 地址, 店东, 电话, 跟进人</p>
                        <form method="POST" enctype="multipart/form-data" class="flex gap-4">
                            <input type="hidden" name="import_type" value="company">
                            <input type="file" name="csv_file" accept=".csv" required class="text-sm">
                            <button class="bg-blue-600 text-white px-4 py-1 rounded">上传</button>
                        </form>
                    </div>
                    <div class="bg-white p-6 rounded-xl border border-slate-200">
                        <h3 class="font-bold mb-2">2. 导入代理项目 (在售)</h3>
                        <p class="text-xs text-gray-400 mb-4">对应列：项目名称, 管理员姓名, 管理员电话</p>
                        <form method="POST" enctype="multipart/form-data" class="flex gap-4">
                            <input type="hidden" name="import_type" value="agent_project">
                            <input type="file" name="csv_file" accept=".csv" required class="text-sm">
                            <button class="bg-green-600 text-white px-4 py-1 rounded">上传</button>
                        </form>
                    </div>
                    <div class="bg-white p-6 rounded-xl border border-slate-200">
                        <h3 class="font-bold mb-2">3. 导入市场项目 (仅展示)</h3>
                        <p class="text-xs text-gray-400 mb-4">对应列：项目名称</p>
                        <form method="POST" enctype="multipart/form-data" class="flex gap-4">
                            <input type="hidden" name="import_type" value="market_project">
                            <input type="file" name="csv_file" accept=".csv" required class="text-sm">
                            <button class="bg-slate-600 text-white px-4 py-1 rounded">上传</button>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

</div>

<script>
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('import');
        
        return {
            sidebarOpen,
            view
        };
    }
}).mount('#app');
</script>
</body>
</html>