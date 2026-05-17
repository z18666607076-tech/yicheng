<?php
// agent.php - 经纪人端 (v26.0: 集成 DeepSeek AI 智能识别)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 0. 登录鉴权 ===
if (!isset($_SESSION['agent_id'])) { header('Location: login.php'); exit; }
$CURRENT_USER = [
    'id' => $_SESSION['agent_id'],
    'name' => $_SESSION['agent_name'],
    'phone' => $_SESSION['agent_phone'],
    'company' => $_SESSION['agent_company'],
    'role' => $_SESSION['agent_role'] ?? 'channel'
];

// 检查是否为渠道部门用户
$isChannelUser = false;
$channelDeptNames = ['渠道经理', '渠道人员'];
foreach ($channelDeptNames as $deptName) {
    if (strpos($CURRENT_USER['company'], $deptName) !== false) {
        $isChannelUser = true;
        break;
    }
}

// === 1. 数据库连接 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error: " . $e->getMessage()); }

require_once __DIR__ . '/includes/agent_roles.php';
require_once __DIR__ . '/includes/filings_sub_stages_normalize.php';

function filing_status_log_actor_name($name, $fallback = '渠道') {
    $n = trim((string)$name);
    if ($n === '') $n = $fallback;
    return str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $n);
}

/** 客户电话「前三+后四」签名，与前端 normalizePhoneForNewCustomer 一致；用于当日重复判断 */
function agent_client_phone_prefix_suffix_sig($phone) {
    $raw = trim((string)$phone);
    if ($raw === '') {
        return null;
    }
    if (preg_match('/^(\d{3})\*+(\d{4})$/u', $raw, $m)) {
        return $m[1] . '|' . $m[2];
    }
    $digits = preg_replace('/\D+/', '', $raw);
    if (strlen($digits) >= 7) {
        return substr($digits, 0, 3) . '|' . substr($digits, -4);
    }
    return null;
}

/** 当前输入号码与库中号码是否视为同一客户（前三后四；无法算签名时整串 trim 相等） */
function agent_client_phone_matches_prefix_suffix($incomingPhone, $storedPhone) {
    $want = agent_client_phone_prefix_suffix_sig($incomingPhone);
    if ($want !== null) {
        $have = agent_client_phone_prefix_suffix_sig($storedPhone);
        return $have !== null && $have === $want;
    }
    return trim((string)$storedPhone) === trim((string)$incomingPhone);
}

$action = $_GET['action'] ?? 'view';

function build_in_clause($field, $values, &$params) {
    if (empty($values)) return "1=0";
    $placeholders = implode(',', array_fill(0, count($values), '?'));
    foreach ($values as $v) $params[] = $v;
    return "$field IN ($placeholders)";
}

function get_descendant_agent_ids($pdo, $managerId) {
    $all = $pdo->query("SELECT id, manager_id FROM agents WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
    $childrenMap = [];
    foreach ($all as $a) {
        $pid = (int)($a['manager_id'] ?? 0);
        if (!isset($childrenMap[$pid])) $childrenMap[$pid] = [];
        $childrenMap[$pid][] = (int)$a['id'];
    }
    $result = [];
    $stack = [(int)$managerId];
    while (!empty($stack)) {
        $curr = array_pop($stack);
        if (empty($childrenMap[$curr])) continue;
        foreach ($childrenMap[$curr] as $cid) {
            if (in_array($cid, $result, true)) continue;
            $result[] = $cid;
            $stack[] = $cid;
        }
    }
    return $result;
}

function can_view_team_data($currentUser) {
    $dept = $currentUser['company'] ?? '';
    return (strpos($dept, '渠道经理') !== false) || (strpos($dept, '服务中心') !== false) || (strpos($dept, '总经办') !== false) || (strpos($dept, '数据中心') !== false);
}

function can_view_all_data($currentUser) {
    $dept = $currentUser['company'] ?? '';
    return (strpos($dept, '总经办') !== false) || (strpos($dept, '数据中心') !== false);
}

function get_scope_members($pdo, $currentUser) {
    if (can_view_all_data($currentUser)) {
        $stmt = $pdo->prepare("SELECT id, username, phone FROM agents WHERE is_deleted = 0");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    $selfId = (int)$currentUser['id'];
    $ids = [$selfId];
    if (can_view_team_data($currentUser)) {
        $ids = array_values(array_unique(array_merge($ids, get_descendant_agent_ids($pdo, $selfId))));
    }
    $params = [];
    $where = build_in_clause('id', $ids, $params);
    $stmt = $pdo->prepare("SELECT id, username, phone FROM agents WHERE is_deleted = 0 AND $where");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function get_scope_sql_and_params($scopeMembers, $memberId = 0, $currentUser = null) {
    if ($currentUser && can_view_all_data($currentUser) && $memberId <= 0) {
        return ['sql' => '1=1', 'params' => []];
    }
    $ids = array_values(array_unique(array_map('intval', array_column($scopeMembers, 'id'))));
    $phones = array_values(array_unique(array_filter(array_column($scopeMembers, 'phone'))));
    $names = array_values(array_unique(array_filter(array_column($scopeMembers, 'username'))));

    if ($memberId > 0 && in_array($memberId, $ids, true)) {
        $ids = [$memberId];
        $phones = [];
        $names = [];
        foreach ($scopeMembers as $m) {
            if ((int)$m['id'] === $memberId) {
                if (!empty($m['phone'])) $phones[] = $m['phone'];
                if (!empty($m['username'])) $names[] = $m['username'];
                break;
            }
        }
    }

    $params = [];
    $parts = [];
    $parts[] = build_in_clause('f.agent_id', $ids, $params);
    if (!empty($phones)) $parts[] = build_in_clause('f.broker_phone', $phones, $params);
    if (!empty($names)) $parts[] = build_in_clause('c.follower', $names, $params);
    return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
}

$SCOPE_MEMBERS = get_scope_members($pdo, $CURRENT_USER);

// [API] 提交报备
if ($action == 'submit') {
    header('Content-Type: application/json');
    $data = $_POST;
    $rawInputText = trim($data['raw_input_text'] ?? '');
    $pids = $data['project_ids']; 
    if (is_string($pids)) $pids = explode(',', $pids);
    $pids = array_values(array_unique(array_filter(array_map('intval', (array)$pids), function ($x) {
        return $x > 0;
    })));
    if (empty($pids)) {
        echo json_encode(['status' => 'error', 'msg' => '请至少选择一个楼盘']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $successCount = 0;
        foreach ($pids as $pid) {
            if (empty($pid)) {
                continue;
            }
            $pid = (int)$pid;
            $logSubmit = date('Y-m-d H:i') . " [经纪人·" . filing_status_log_actor_name($CURRENT_USER['name'] ?? '', '渠道') . "] 提交报备";

            $log = $logSubmit;
            $sql = "INSERT INTO filings (
                project_id, agent_id, company_name, follower, broker_name, broker_phone, broker_num,
                client_name, client_phone, client_num, visit_time, designated_sales, 
                remark, raw_input_text, status, status_log, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $pid, $CURRENT_USER['id'],
                $data['company_name'], $data['follower'] ?? '', $data['broker_name'], $data['broker_phone'], $data['broker_num'],
                $data['client_name'], $data['client_phone'], $data['client_num'], $data['visit_date'], $data['designated_sales'], $data['remark'], $rawInputText, $log
            ]);
            $successCount++;
        }
        $pdo->commit();
        if ($successCount > 0) {
            $msg = $successCount === 1 ? '提交成功：新建 1 条' : ('提交成功：新建 ' . $successCount . ' 条');
            echo json_encode(['status' => 'success', 'msg' => $msg]);
        } else {
            echo json_encode(['status' => 'error', 'msg' => '未选择有效项目']);
        }
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => '系统错误: ' . $e->getMessage()]);
    }
    exit;
}

// [API] 经纪人更新进度
if ($action == 'update_progress') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $subStages = filings_normalize_sub_stages_csv((string)($_POST['sub_stages'] ?? ''));
    $voice = $_POST['voice'] ?? '';
    
    // 权限验证：检查用户是否有权限更新此报备
    $checkStmt = $pdo->prepare("SELECT f.id FROM filings f LEFT JOIN companies c ON f.company_name = c.name WHERE f.id = ? AND (f.agent_id = ? OR f.broker_phone = ? OR c.follower = ?)");
    $checkStmt->execute([$id, $CURRENT_USER['id'], $CURRENT_USER['phone'], $CURRENT_USER['name']]);
    if (!$checkStmt->fetch()) {
        echo json_encode(['status'=>'error', 'msg'=>'无权限操作此报备']);
        exit;
    }
    
    $stagesArr = [];
    if(strpos($subStages, 'deposit')!==false) $stagesArr[] = '已交定';
    if(strpos($subStages, 'lock')!==false) $stagesArr[] = '已锁房';
    if(strpos($subStages, 'sign')!==false) $stagesArr[] = '已签认购书';
    $stageStr = implode('/', $stagesArr);
    
    $log = "\n" . date('Y-m-d H:i') . " [经纪人·" . filing_status_log_actor_name($CURRENT_USER['name'] ?? '', '渠道') . "] 更新进度: " . ($stageStr ?: '无');
    if($voice) $log .= " (备注: $voice)";

    $sql = "UPDATE filings SET sub_stages = ?, status_log = CONCAT(IFNULL(status_log,''), ?) WHERE id = ?";
    $pdo->prepare($sql)->execute([$subStages, $log, $id]);
    echo json_encode(['status'=>'success']); exit;
}

// [API] 获取列表
if ($action == 'get_list') {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $sql = "SELECT f.*, p.name as project_name, 
            COALESCE(NULLIF(f.broker_name,''), a.username) as agent_name, 
            COALESCE(NULLIF(f.broker_phone,''), a.phone) as agent_phone,
            c.name as company_full_name, c.store_name
            FROM filings f 
            LEFT JOIN projects p ON f.project_id = p.id 
            LEFT JOIN agents a ON f.agent_id = a.id
            LEFT JOIN companies c ON f.company_name = c.name 
            WHERE {$scope['sql']}
            ORDER BY f.created_at DESC LIMIT 1000";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 为每个订单添加跟进记录
    foreach ($list as &$item) {
        $followupStmt = $pdo->prepare("SELECT * FROM filing_followups WHERE filing_id = ? ORDER BY followup_count ASC");
        $followupStmt->execute([$item['id']]);
        $item['followups'] = $followupStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    echo json_encode($list);
    exit;
}

// [API] 修改报备信息
if ($action == 'update_filing') {
    header('Content-Type: application/json');
    $id = $_POST['id'] ?? 0;
    $client_name = $_POST['client_name'] ?? '';
    $client_phone = $_POST['client_phone'] ?? '';
    $company_name = $_POST['company_name'] ?? '';
    $follower = $_POST['follower'] ?? '';
    $broker_name = $_POST['broker_name'] ?? '';
    $broker_phone = $_POST['broker_phone'] ?? '';
    $visit_time = $_POST['visit_time'] ?? '';
    $designated_sales = $_POST['designated_sales'] ?? '';
    $remark = $_POST['remark'] ?? '';
    
    if(!$id) {
        echo json_encode(['status'=>'error', 'msg'=>'缺少报备ID']);
        exit;
    }
    
    // 权限验证：与列表可见范围保持一致，避免误判“无权限”
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, 0, $CURRENT_USER);
    $checkStmt = $pdo->prepare("SELECT 1 FROM filings f LEFT JOIN companies c ON f.company_name = c.name WHERE f.id = ? AND {$scope['sql']} LIMIT 1");
    $checkStmt->execute(array_merge([$id], $scope['params']));
    if (!$checkStmt->fetch()) {
        echo json_encode(['status'=>'error', 'msg'=>'无权限操作此报备']);
        exit;
    }
    
    // 仅允许报备无效(6)修改
    $statusStmt = $pdo->prepare("SELECT status FROM filings WHERE id = ?");
    $statusStmt->execute([$id]);
    $status = (int)$statusStmt->fetchColumn();
    if ($status !== 6) {
        echo json_encode(['status'=>'error', 'msg'=>'仅报备无效状态可修改']);
        exit;
    }
    
    $actor = filing_status_log_actor_name($CURRENT_USER['name'] ?? '', '渠道');
    $log = "\n" . date('Y-m-d H:i') . " [经纪人·{$actor}] 修改报备信息并重新提交为待处理";
    $sql = "UPDATE filings
            SET client_name = ?, client_phone = ?, company_name = ?, follower = ?, broker_name = ?, broker_phone = ?, visit_time = ?, designated_sales = ?, remark = ?,
                status = 1, visit_type = NULL, status_log = CONCAT(IFNULL(status_log,''), ?)
            WHERE id = ?";
    $pdo->prepare($sql)->execute([$client_name, $client_phone, $company_name, $follower, $broker_name, $broker_phone, $visit_time, $designated_sales, $remark, $log, $id]);
    echo json_encode(['status'=>'success']); exit;
}

// [API] 搜索历史记录
if ($action == 'search_history') {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $keyword = $_GET['keyword'] ?? '';
    $today = date('Y-m-d');
    
    if (empty($keyword)) {
        echo json_encode([]);
        exit;
    }
    
    $sql = "SELECT f.*, p.name as project_name, 
            COALESCE(NULLIF(f.broker_name,''), a.username) as agent_name, 
            COALESCE(NULLIF(f.broker_phone,''), a.phone) as agent_phone,
            c.name as company_full_name, c.store_name
            FROM filings f 
            LEFT JOIN projects p ON f.project_id = p.id 
            LEFT JOIN agents a ON f.agent_id = a.id
            LEFT JOIN companies c ON f.company_name = c.name 
            WHERE {$scope['sql']}
            AND DATE(f.created_at) < ? 
            AND (
                f.client_name LIKE ? OR f.client_phone LIKE ? OR p.name LIKE ? OR 
                f.broker_name LIKE ? OR f.broker_phone LIKE ? OR 
                a.username LIKE ? OR a.phone LIKE ? OR 
                (f.company_name LIKE ? OR INSTR(?, f.company_name) > 0) OR 
                (c.name LIKE ? OR INSTR(?, c.name) > 0) OR 
                (c.store_name LIKE ? OR INSTR(?, c.store_name) > 0)
            )
            ORDER BY f.created_at DESC LIMIT 100";
    $stmt = $pdo->prepare($sql);
    $params = array_merge($scope['params'], [$today,
        "%$keyword%", "%$keyword%", "%$keyword%", 
        "%$keyword%", "%$keyword%", 
        "%$keyword%", "%$keyword%", 
        "%$keyword%", $keyword, 
        "%$keyword%", $keyword, 
        "%$keyword%", $keyword
    ]);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// [API] 统计
if ($action == 'get_stats') {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, $memberId);
    $baseSql = "FROM filings f LEFT JOIN companies c ON f.company_name = c.name WHERE {$scope['sql']}";

    // 商户口径：跟进商户量=归属渠道商户数（不按日期变）；已启动商户量=所选区间内产生报备的去重商户数
    $scopeMembersForCompany = $SCOPE_MEMBERS;
    if ($memberId > 0) {
        $scopeMembersForCompany = array_values(array_filter($SCOPE_MEMBERS, function($m) use ($memberId) {
            return intval($m['id']) === $memberId;
        }));
    }
    $memberNames = [];
    foreach ($scopeMembersForCompany as $m) {
        $n = trim($m['username'] ?? '');
        if ($n !== '') $memberNames[] = $n;
    }
    $memberNames = array_values(array_unique($memberNames));

    $merchantTotal = 0;
    if (!empty($memberNames)) {
        $nameParams = [];
        $inClause = build_in_clause('follower', $memberNames, $nameParams);
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE $inClause");
        $stmt->execute($nameParams);
        $merchantTotal = intval($stmt->fetchColumn() ?: 0);
    }

    // 图表与顶部「总报备 / 总来访 / 总成交 / 转化率 / 已启动商户量」共用同一时间区间
    $rangeType = $_GET['range_type'] ?? 'month';
    $customStart = $_GET['custom_start'] ?? '';
    $customEnd = $_GET['custom_end'] ?? '';

    $rangeStart = date('Y-m-d');
    $rangeEnd = date('Y-m-d');
    if ($rangeType === 'week') {
        $rangeStart = date('Y-m-d', strtotime('monday this week'));
        $rangeEnd = date('Y-m-d', strtotime('sunday this week'));
    } elseif ($rangeType === 'month') {
        $rangeStart = date('Y-m-01');
        $rangeEnd = date('Y-m-t');
    } elseif ($rangeType === 'quarter') {
        $month = intval(date('n'));
        $quarter = intval(floor(($month - 1) / 3) + 1);
        $quarterStartMonth = ($quarter - 1) * 3 + 1;
        $year = intval(date('Y'));
        $rangeStart = sprintf('%04d-%02d-01', $year, $quarterStartMonth);
        $rangeEnd = date('Y-m-t', strtotime($rangeStart . ' +2 month'));
    } elseif ($rangeType === 'custom' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customStart) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $customEnd)) {
        $rangeStart = $customStart;
        $rangeEnd = $customEnd;
    }
    if ($rangeStart > $rangeEnd) {
        $tmp = $rangeStart;
        $rangeStart = $rangeEnd;
        $rangeEnd = $tmp;
    }

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.company_name) $baseSql AND f.company_name <> '' AND DATE(f.created_at) BETWEEN ? AND ?");
    $stmt->execute(array_merge($scope['params'], [$rangeStart, $rangeEnd]));
    $merchantStarted = intval($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.client_phone) $baseSql AND f.client_phone <> '' AND DATE(f.created_at) BETWEEN ? AND ?");
    $stmt->execute(array_merge($scope['params'], [$rangeStart, $rangeEnd]));
    $monthReportUnique = intval($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.client_phone) $baseSql AND f.client_phone <> '' AND f.status >= 2 AND DATE(f.created_at) BETWEEN ? AND ?");
    $stmt->execute(array_merge($scope['params'], [$rangeStart, $rangeEnd]));
    $monthVisitUnique = intval($stmt->fetchColumn() ?: 0);

    $stmt = $pdo->prepare("SELECT COUNT(*) $baseSql AND f.status = 4 AND DATE(f.created_at) BETWEEN ? AND ?");
    $stmt->execute(array_merge($scope['params'], [$rangeStart, $rangeEnd]));
    $monthDealTotal = intval($stmt->fetchColumn() ?: 0);

    $monthConversionRate = $monthVisitUnique > 0 ? round(($monthDealTotal / $monthVisitUnique) * 100, 2) : 0;

    $labels = [];
    $chartReport = [];
    $chartVisit = [];
    $chartDeal = [];
    $chartDays = [];

    $period = new DatePeriod(
        new DateTime($rangeStart),
        new DateInterval('P1D'),
        (new DateTime($rangeEnd))->modify('+1 day')
    );
    foreach ($period as $dt) {
        $date = $dt->format('Y-m-d');
        $labels[] = $dt->format('m-d');

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.client_phone) $baseSql AND f.client_phone <> '' AND DATE(f.created_at)=?");
        $stmt->execute(array_merge($scope['params'], [$date]));
        $report = intval($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.client_phone) $baseSql AND f.client_phone <> '' AND f.status >= 2 AND DATE(f.created_at)=?");
        $stmt->execute(array_merge($scope['params'], [$date]));
        $visitDay = intval($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) $baseSql AND f.status = 4 AND DATE(f.created_at)=?");
        $stmt->execute(array_merge($scope['params'], [$date]));
        $dealDay = intval($stmt->fetchColumn() ?: 0);

        $chartDays[] = ['date' => $date, 'report' => $report, 'visit' => $visitDay, 'deal' => $dealDay];
        $chartReport[] = $report;
        $chartVisit[] = $visitDay;
        $chartDeal[] = $dealDay;
    }

    echo json_encode([
        'total' => $monthReportUnique,
        'deal' => $monthDealTotal,
        'visit' => $monthVisitUnique,
        'comm' => 0,
        'store_count' => $merchantStarted,
        'merchant_total' => $merchantTotal,
        'merchant_started' => $merchantStarted,
        'month_report_unique' => $monthReportUnique,
        'month_visit_unique' => $monthVisitUnique,
        'month_deal_total' => $monthDealTotal,
        'month_conversion_rate' => $monthConversionRate,
        'range_type' => $rangeType,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd,
        'last7days' => $chartDays,
        'chart_labels' => $labels,
        'chart_data' => [
            'report' => $chartReport,
            'visit' => $chartVisit,
            'deal' => $chartDeal
        ]
    ]); exit;
}

// [API] 辅助
if ($action == 'search_company') {
    header('Content-Type: application/json');
    $kw = trim($_GET['kw'] ?? '');
    if (mb_strlen($kw, 'UTF-8') < 1) { echo json_encode([]); exit; }

    // 归一化关键词：去掉常见公司后缀/行政前缀，提升模糊命中率（如“君阅房产”->“君阅房”）
    $normalize = function($s) {
        $s = trim((string)$s);
        $s = preg_replace('/\s+/u', '', $s);
        $s = str_replace(['（', '）', '(', ')', '-', '_', '·', '.', '。', '，', ','], '', $s);
        $removeWords = [
            '有限责任公司', '有限公司', '股份有限公司', '集团', '公司',
            '房地产中介服务部', '房地产中介', '房地产', '房产中介', '房产',
            '中介服务部', '中介服务', '中介', '服务部', '服务',
            '博罗县', '惠州市', '惠州', '县', '市'
        ];
        $s = str_replace($removeWords, '', $s);
        return trim((string)$s);
    };

    $kwNorm = $normalize($kw);
    $patternRaw = '%' . $kw . '%';
    $patternNorm = '%' . ($kwNorm !== '' ? $kwNorm : $kw) . '%';

    $sql = "SELECT name, follower
            FROM companies
            WHERE name LIKE ?
               OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, '有限责任公司', ''), '有限公司', ''), '房地产中介服务部', ''), '房地产中介', ''), '房地产', ''), '房产中介', ''), '房产', ''), '中介服务部', ''), '中介', ''), '服务部', '') LIKE ?
            ORDER BY
                CASE
                    WHEN name = ? THEN 0
                    WHEN name LIKE ? THEN 1
                    WHEN name LIKE ? THEN 2
                    ELSE 3
                END,
                CHAR_LENGTH(name) ASC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $patternRaw,
        $patternNorm,
        $kw,
        $patternRaw,
        $patternNorm
    ]);
    $companies = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 兜底：支持乱序关键词匹配（如“阅君”也可命中“君阅...”）
    if (empty($companies)) {
        $chars = preg_split('//u', ($kwNorm !== '' ? $kwNorm : $kw), -1, PREG_SPLIT_NO_EMPTY);
        $chars = array_values(array_unique(array_filter($chars, function($ch) {
            return trim((string)$ch) !== '';
        })));
        if (count($chars) >= 2) {
            $charConds = [];
            $charParams = [];
            foreach ($chars as $ch) {
                $charConds[] = "name LIKE ?";
                $charParams[] = '%' . $ch . '%';
            }
            $sql2 = "SELECT name, follower
                     FROM companies
                     WHERE " . implode(' AND ', $charConds) . "
                     ORDER BY CHAR_LENGTH(name) ASC
                     LIMIT 10";
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($charParams);
            $companies = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    echo json_encode($companies); exit;
}
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    echo json_encode($pdo->query("SELECT id, name, status FROM projects ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC)); exit;
}

if ($action == 'get_agents') {
    header('Content-Type: application/json');
    // 只返回渠道人员，排除渠道经理和公池相关用户，统一使用"公池"作为公共池名称
    $chSql = agent_sql_has_role('agents', 'channel');
    $stmt = $pdo->prepare("SELECT MIN(id) AS id, username, MAX(phone) AS phone FROM agents WHERE {$chSql} AND is_deleted = 0 AND username != '张婷婷' AND username != '公池' AND username != '公共池' GROUP BY username ORDER BY username ASC");
    $stmt->execute();
    $agents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($agents); exit;
}

if ($action == 'get_broker_directory') {
    header('Content-Type: application/json');
    $result = [];
    try {
        // 优先使用后台“经纪人数据管理”维护的号码（agents_data）
        $stmt = $pdo->query("
            SELECT d.agent_name AS username, d.agent_phone AS phone
            FROM agents_data d
            INNER JOIN (
                SELECT agent_name, MAX(id) AS max_id
                FROM agents_data
                WHERE agent_name <> '' AND agent_phone <> ''
                GROUP BY agent_name
            ) latest ON latest.max_id = d.id
            ORDER BY d.agent_name ASC
        ");
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (is_array($rows) && count($rows) > 0) {
            echo json_encode($rows);
            exit;
        }
    } catch (Exception $e) {
        // 若表不存在或查询失败，回退到账号表
    }

    $chSql2 = agent_sql_has_role('agents', 'channel');
    $stmt = $pdo->prepare("SELECT MIN(id) AS id, username, MAX(phone) AS phone FROM agents WHERE {$chSql2} AND is_deleted = 0 AND username != '张婷婷' AND username != '公池' AND username != '公共池' GROUP BY username ORDER BY username ASC");
    $stmt->execute();
    $result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($result);
    exit;
}

if ($action == 'get_team_members') {
    header('Content-Type: application/json');
    echo json_encode([
        'can_filter' => can_view_team_data($CURRENT_USER),
        'members' => $SCOPE_MEMBERS
    ]);
    exit;
}
// [API] 获取跟进记录
if ($action == 'get_followups') {
    header('Content-Type: application/json');
    $filingId = $_GET['filing_id'] ?? 0;
    
    // 权限验证
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, 0);
    $checkStmt = $pdo->prepare("SELECT 1 FROM filings f LEFT JOIN companies c ON f.company_name = c.name WHERE f.id = ? AND {$scope['sql']}");
    $checkStmt->execute(array_merge([$filingId], $scope['params']));
    if (!$checkStmt->fetch()) {
        echo json_encode(['status'=>'error', 'msg'=>'无权限操作此报备']);
        exit;
    }
    
    $stmt = $pdo->prepare("SELECT * FROM filing_followups WHERE filing_id = ? ORDER BY followup_count ASC");
    $stmt->execute([$filingId]);
    $followups = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['status'=>'success', 'data'=>$followups]);
    exit;
}

// [API] 提交跟进记录
if ($action == 'submit_followup') {
    header('Content-Type: application/json');
    $filingId = $_POST['filing_id'] ?? 0;
    $followupCount = $_POST['followup_count'] ?? 0;
    $content = $_POST['content'] ?? '';
    $summary = $_POST['summary'] ?? '';
    
    if(!$filingId || !$followupCount || empty($content)) {
        echo json_encode(['status'=>'error', 'msg'=>'缺少必要参数']);
        exit;
    }
    
    if($followupCount < 1 || $followupCount > 3) {
        echo json_encode(['status'=>'error', 'msg'=>'跟进次数必须在1-3之间']);
        exit;
    }
    
    // 权限验证
    $scope = get_scope_sql_and_params($SCOPE_MEMBERS, 0);
    $checkStmt = $pdo->prepare("SELECT 1 FROM filings f LEFT JOIN companies c ON f.company_name = c.name WHERE f.id = ? AND {$scope['sql']}");
    $checkStmt->execute(array_merge([$filingId], $scope['params']));
    if (!$checkStmt->fetch()) {
        echo json_encode(['status'=>'error', 'msg'=>'无权限操作此报备']);
        exit;
    }
    
    // 检查是否已经存在该次数的跟进记录
    $existStmt = $pdo->prepare("SELECT 1 FROM filing_followups WHERE filing_id = ? AND followup_count = ?");
    $existStmt->execute([$filingId, $followupCount]);
    if ($existStmt->fetch()) {
        echo json_encode(['status'=>'error', 'msg'=>'该次数的跟进记录已存在']);
        exit;
    }
    
    // 检查是否按顺序提交
    if($followupCount > 1) {
        $prevStmt = $pdo->prepare("SELECT 1 FROM filing_followups WHERE filing_id = ? AND followup_count = ?");
        $prevStmt->execute([$filingId, $followupCount - 1]);
        if (!$prevStmt->fetch()) {
            echo json_encode(['status'=>'error', 'msg'=>'请先完成前一次跟进']);
            exit;
        }
    }
    
    try {
        $sql = "INSERT INTO filing_followups (filing_id, followup_count, content, summary, agent_id) VALUES (?, ?, ?, ?, ?)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$filingId, $followupCount, $content, $summary, $CURRENT_USER['id']]);
        
        // 同时更新filings表的status_log
        $logContent = $content;
        if ($summary) {
            $logContent = "[$summary] " . $content;
        }
        $log = "\n" . date('Y-m-d H:i') . " [经纪人·" . filing_status_log_actor_name($CURRENT_USER['name'] ?? '', '渠道') . "] 第" . $followupCount . "次跟进: " . $logContent;
        $updateStmt = $pdo->prepare("UPDATE filings SET status_log = CONCAT(IFNULL(status_log,''), ?) WHERE id = ?");
        $updateStmt->execute([$log, $filingId]);
        
        echo json_encode(['status'=>'success', 'msg'=>'跟进记录提交成功']);
    } catch (Exception $e) {
        echo json_encode(['status'=>'error', 'msg'=>'提交失败: ' . $e->getMessage()]);
    }
    exit;
}

// [API] 检查重复报备
if ($action == 'check_duplicate') {
    header('Content-Type: application/json');
    $data = $_POST;
    $clientPhone = $data['client_phone'] ?? '';
    $projectIds = $data['project_ids'] ?? [];
    if (is_string($projectIds)) $projectIds = explode(',', $projectIds);
    if (empty($clientPhone) || empty($projectIds)) {
        echo json_encode(['status' => 'success', 'data' => [], 'msg' => '']);
        exit;
    }
    
    $duplicates = [];
    $seenFilingIds = [];
    $aid = (int)($CURRENT_USER['id'] ?? 0);
    $aphone = trim((string)($CURRENT_USER['phone'] ?? ''));
    $aname = trim((string)($CURRENT_USER['name'] ?? ''));
    foreach ($projectIds as $pid) {
        if (empty($pid)) {
            continue;
        }
        // 同盘 + 进行中(1/2/3) + 本人可维护；客户电话前三后四比对（与提交前提醒口径一致，不再服务端合并）
        $stmt = $pdo->prepare(
            'SELECT f.*, p.name as project_name FROM filings f
             LEFT JOIN projects p ON f.project_id = p.id
             LEFT JOIN companies c ON f.company_name = c.name
             WHERE f.project_id = ?
               AND f.status IN (1, 2, 3)
               AND (f.agent_id = ? OR f.broker_phone = ? OR c.follower = ?)
             ORDER BY f.id DESC'
        );
        $stmt->execute([(int)$pid, $aid, $aphone, $aname]);
        $existing = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($existing as $row) {
            if (!agent_client_phone_matches_prefix_suffix($clientPhone, $row['client_phone'] ?? '')) {
                continue;
            }
            $fid = (int)($row['id'] ?? 0);
            if ($fid > 0 && isset($seenFilingIds[$fid])) {
                continue;
            }
            if ($fid > 0) {
                $seenFilingIds[$fid] = true;
            }
            $duplicates[] = $row;
        }
    }
    
    echo json_encode([
        'status' => 'success',
        'data' => $duplicates,
        'msg' => !empty($duplicates) ? '同项目、同客户（前三后四）下已有进行中的报备，请核验' : ''
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>经纪人端</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.0/dist/echarts.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .glass-nav {
            background: #2563eb;
            border-top: 1px solid #1d4ed8;
            padding-bottom: env(safe-area-inset-bottom);
            box-shadow: 0 -6px 24px rgba(30, 64, 175, 0.35);
        }
        .glass-nav-item {
            color: rgba(255, 255, 255, 0.78);
            transition: color 0.15s, background 0.15s;
            padding: 6px 10px;
            border-radius: 12px;
        }
        .glass-nav-item:hover {
            color: #ffffff;
            background: rgba(255, 255, 255, 0.12);
        }
        .glass-nav-item-active {
            color: #ffffff;
            font-weight: 700;
            background: rgba(255, 255, 255, 0.22);
            padding: 6px 10px;
            border-radius: 12px;
            box-shadow: 0 1px 0 rgba(255, 255, 255, 0.25) inset;
        }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
        .input-group { width: 100%; background-color: #f9fafb; padding: 0.75rem; border-radius: 0.75rem; border: 1px solid #e5e7eb; font-size: 0.875rem; outline: none; transition: all 0.2s; }
        .input-group:focus { border-color: #3b82f6; ring: 2px solid #93c5fd; }
        .label-text { font-size: 0.75rem; font-weight: bold; color: #6b7280; margin-bottom: 0.25rem; display: block; margin-left: 0.25rem; }
        .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 50; display: flex; align-items: flex-end; justify-content: center; }
        .modal-content { background: white; width: 100%; max-width: 480px; border-radius: 20px 20px 0 0; padding: 20px; max-height: 80vh; overflow-y: auto; animation: slideUp 0.3s ease-out; position: relative; }
        .close-btn { position: absolute; top: 15px; right: 15px; color: #94a3b8; font-size: 20px; cursor: pointer; padding: 5px; }
        .filter-input { width: 100%; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.5rem; font-size: 0.75rem; outline: none; }
        .filter-label { font-size: 10px; font-weight: bold; color: #9ca3af; margin-bottom: 4px; display: block; }
        
        .checkbox-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 8px; }
        .checkbox-label { display: flex; align-items: center; padding: 10px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; cursor: pointer; transition: all 0.2s; }
        .checkbox-label.checked { background: #eff6ff; border-color: #3b82f6; color: #2563eb; }
.checkbox-label.disabled { background: #f9fafb; border-color: #e5e7eb; color: #9ca3af; cursor: not-allowed; }
.checkbox-label.disabled .check-circle { border-color: #d1d5db; }
        .check-circle { width: 16px; height: 16px; border: 2px solid #cbd5e1; border-radius: 50%; margin-right: 8px; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
        .checkbox-label.checked .check-circle { border-color: #3b82f6; background: #3b82f6; }
        .checkbox-label.checked .check-circle::after { content:'✓'; color:#fff; font-size:10px; }
        
        .dropdown-list { position: absolute; z-index: 50; width: 100%; background: white; border: 1px solid #e2e8f0; border-radius: 0.75rem; box-shadow: 0 10px 20px -3px rgba(0, 0, 0, 0.15); max-height: 200px; overflow-y: auto; margin-top: 4px; left: 0; right: 0; }
        .dropdown-item { padding: 12px 16px; font-size: 14px; color: #334155; cursor: pointer; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; }
        .dropdown-item:hover { background-color: #f1f5f9; }

        .timeline-item { position: relative; padding-left: 20px; padding-bottom: 25px; border-left: 2px solid #e2e8f0; }
        .timeline-item:last-child { border-left: 2px solid transparent; }
        .timeline-dot { position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; border: 2px solid #fff; }
        .timeline-dot.active { background: #3b82f6; }
        
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .fade-in { animation: fadeIn 0.5s ease-out; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        
        .prog-badge { display: flex; align-items: center; justify-content: center; gap: 4px; padding: 4px 10px; border-radius: 999px; font-size: 10px; font-weight: bold; border: 1px solid; transition: all 0.2s; }
        .prog-badge.active { background: #f3e8ff; color: #7e22ce; border-color: #d8b4fe; } 
        .prog-badge.inactive { background: #f8fafc; color: #94a3b8; border-color: #e2e8f0; }

        .sub-chip { padding: 5px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; display: inline-flex; align-items: center; justify-content: center; white-space: nowrap; flex-shrink: 0; }
        .sub-chip.active { background-color: #2563eb; color: white; border-color: #2563eb; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.28); }
        .sub-chip.inactive { background-color: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .count-pill { min-width: 24px; height: 20px; padding: 0 6px; border-radius: 9999px; font-size: 12px; line-height: 20px; font-weight: 800; text-align: center; }
        .count-pill-main-active { background: rgba(255, 255, 255, 0.24); color: #ffffff; }
        .count-pill-main-inactive { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }
        .count-pill-sub-active { background: rgba(255, 255, 255, 0.24); color: #ffffff; }
        .count-pill-sub-inactive { background: #e2e8f0; color: #334155; border: 1px solid #cbd5e1; }
    </style>
</head>
<body>
<div id="app" class="max-w-md mx-auto min-h-screen pb-24 relative bg-gray-50">
    
    <div v-if="tab==='dash'" class="bg-blue-600 p-5 pt-8 pb-16 rounded-b-[2rem] shadow-sm relative z-0">
            <div class="flex justify-between items-center mb-6 text-white">
                    <div class="flex items-center gap-3">
                        <div class="w-10 h-10 rounded-full bg-white/20 flex items-center justify-center font-bold border-2 border-white/30 backdrop-blur-sm"><i class="fas fa-user-circle text-2xl"></i></div>
                        <div><div class="text-xs text-blue-100 opacity-80">当前用户</div><h1 class="font-bold text-lg">{{ form.broker_name }}</h1></div>
                    </div>
                    <div class="flex flex-wrap gap-2 justify-end">
                        <?php if (!$isChannelUser): ?>
                        <a href="staff.php" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-user-shield"></i> 切换到案场</a>
                        <?php endif; ?>
                        <button type="button" @click="openPwdModal" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-key"></i> 改密码</button>
                        <a href="logout.php" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-sign-out-alt"></i> 退出</a>
                    </div>
                </div>
            <div class="bg-white/10 backdrop-blur-md rounded-2xl p-4 text-white border border-white/10 relative overflow-hidden mb-4">
                <div class="grid grid-cols-2 gap-3 mb-3">
                    <div class="bg-white/10 rounded-xl p-3">
                        <div class="text-blue-100 text-[11px] opacity-80">跟进商户量</div>
                        <div class="text-2xl font-bold mt-1">{{ stats.merchant_total || 0 }}</div>
                    </div>
                    <div class="bg-white/10 rounded-xl p-3">
                        <div class="text-blue-100 text-[11px] opacity-80">已启动商户量</div>
                        <div class="text-2xl font-bold mt-1">{{ stats.merchant_started || 0 }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-4 gap-2 border-t border-white/10 pt-3">
                    <div><div class="text-blue-100 text-[10px] opacity-80 mb-1">总报备</div><div class="text-base font-bold">{{ stats.month_report_unique || 0 }}</div></div>
                    <div><div class="text-blue-100 text-[10px] opacity-80 mb-1">总来访</div><div class="text-base font-bold">{{ stats.month_visit_unique || 0 }}</div></div>
                    <div><div class="text-blue-100 text-[10px] opacity-80 mb-1">总成交</div><div class="text-base font-bold">{{ stats.month_deal_total || 0 }}</div></div>
                    <div><div class="text-blue-100 text-[10px] opacity-80 mb-1">成交转化率</div><div class="text-base font-bold">{{ (stats.month_conversion_rate || 0) }}%</div></div>
                </div>
            </div>
        </div>

    <div v-else class="bg-blue-600 p-6 pt-10 pb-20 rounded-b-[2.5rem] shadow-xl relative overflow-hidden">
        <div class="flex justify-between items-start text-white relative z-10">
            <div><h1 class="text-2xl font-bold">报备工作台</h1><p class="text-blue-100 text-xs mt-1">快速录入 · 智能识别</p></div>
            <div class="flex flex-wrap gap-2 justify-end">
                <?php if (!$isChannelUser): ?>
                <a href="staff.php" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-user-shield"></i> 切换到案场</a>
                <?php endif; ?>
                <button type="button" @click="openPwdModal" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-key"></i> 改密码</button>
                <a href="logout.php" class="text-xs bg-white/20 hover:bg-white/30 backdrop-blur-sm px-3 py-1.5 rounded-full font-bold transition flex items-center gap-1"><i class="fas fa-sign-out-alt"></i> 退出</a>
            </div>
        </div>
    </div>

    <div :class="{'px-4 -mt-10 relative z-10': tab==='dash', 'px-4 -mt-16 relative z-10': tab!=='dash'}" class="space-y-5">
        
        <div v-if="tab==='dash'" class="fade-in space-y-4">
            <div class="bg-white rounded-2xl p-5 card-shadow">
                <div class="flex justify-between items-center mb-4">
                    <h3 class="font-bold text-sm text-slate-700">数据统计</h3>
                    <div class="flex gap-2 text-xs">
                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-blue-500"></span> 报备</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-green-500"></span> 到访</div>
                        <div class="flex items-center gap-1"><span class="w-2 h-2 rounded-full bg-orange-500"></span> 成交</div>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2 mb-3">
                    <button @click="changeStatsRange('today')" class="sub-chip !text-[11px] !py-1 !px-3" :class="statsRange==='today'?'active':'inactive'">今日</button>
                    <button @click="changeStatsRange('week')" class="sub-chip !text-[11px] !py-1 !px-3" :class="statsRange==='week'?'active':'inactive'">本周</button>
                    <button @click="changeStatsRange('month')" class="sub-chip !text-[11px] !py-1 !px-3" :class="statsRange==='month'?'active':'inactive'">本月</button>
                    <button @click="changeStatsRange('quarter')" class="sub-chip !text-[11px] !py-1 !px-3" :class="statsRange==='quarter'?'active':'inactive'">本季度</button>
                    <button @click="changeStatsRange('custom')" class="sub-chip !text-[11px] !py-1 !px-3" :class="statsRange==='custom'?'active':'inactive'">自定义</button>
                </div>
                <div v-if="statsRange==='custom'" class="grid grid-cols-2 gap-2 mb-3">
                    <input v-model="statsCustomStart" type="date" class="filter-input">
                    <input v-model="statsCustomEnd" type="date" class="filter-input">
                    <button @click="applyStatsCustomRange" class="col-span-2 bg-blue-600 text-white py-2 rounded-lg text-xs font-bold">应用自定义时间</button>
                </div>
                <div id="chart" class="w-full h-60"></div>
            </div>
        </div>

        <div v-if="tab==='form'" class="fade-in space-y-5">
            <div class="bg-white rounded-3xl p-5 card-shadow border border-blue-50">
                <div class="flex justify-between items-center mb-2"><h3 class="font-bold text-slate-800 text-xs"><i class="fas fa-magic text-blue-500 mr-1"></i> AI 智能识别 (支持微信文本)</h3></div>
                <div class="relative">
                    <textarea v-model="smartText" placeholder="粘贴如：报备楼盘：壹城中心... " class="w-full bg-gray-50 rounded-xl p-3 text-xs h-24 outline-none border border-gray-200 focus:ring-2 focus:ring-blue-100 resize-none"></textarea>
                    <button @click="smartParse" class="absolute right-2 bottom-2 bg-slate-800 text-white text-xs px-3 py-1.5 rounded-lg shadow-lg active:scale-95 transition flex items-center gap-1">
                        <i v-if="isParsing" class="fas fa-spinner fa-spin"></i> {{ isParsing ? '识别中...' : '一键AI填充' }}
                    </button>
                </div>
            </div>
            <div class="bg-white rounded-3xl p-6 card-shadow space-y-4">
                <div>
                    <label class="label-text">报备楼盘 (可多选) <span class="text-red-500">*</span></label>
                    <div class="checkbox-grid">
                        <label v-for="(p, index) in projects" :key="p.id" class="checkbox-label" :class="{checked: form.project_ids.includes(p.id), disabled: p.status != 1}" v-show="index < 8 || showAllProjects || form.project_ids.includes(p.id)">
                            <input type="checkbox" :value="p.id" v-model="form.project_ids" class="hidden" :disabled="p.status != 1"><span class="check-circle"></span><span class="text-xs font-bold truncate">{{ p.name }}</span>
                        </label>
                    </div>
                    <div v-if="projects.length > 8" class="text-center mt-2">
                        <button @click="showAllProjects = !showAllProjects" class="text-xs text-blue-500 bg-blue-50 px-3 py-1 rounded-full">{{ showAllProjects ? '收起列表' : '显示更多楼盘' }} <i class="fas" :class="showAllProjects?'fa-chevron-up':'fa-chevron-down'"></i></button>
                    </div>
                </div>
                
                <div class="relative">
                    <label class="label-text">报备公司 (支持关键词模糊搜索)</label>
                    <input type="text" v-model="form.company_name" @input="searchCompany" @focus="onCompanyFocus" placeholder="输入关键字..." class="input-group">
                    <div v-if="showDropdown && companyResults.length > 0" class="dropdown-list">
                        <div v-for="company in companyResults" @click="selectCompany(company)" class="dropdown-item">
                            <span>{{ company.name }}</span>
                            <i class="fas fa-building text-gray-300 text-xs"></i>
                            <span v-if="company.follower" class="text-xs text-gray-500 ml-2">跟进人: {{ company.follower }}</span>
                        </div>
                    </div>
                </div>
                
                <div v-if="form.company_name">
                    <label class="label-text">跟进人</label>
                    <select v-model="form.follower" class="input-group">
                        <option value="">公池</option>
                        <option v-for="agent in agents" :key="agent.username" :value="agent.username">{{ agent.username }}</option>
                    </select>
                </div>

                <div class="p-3 bg-gray-50 rounded-xl border border-gray-200 space-y-3">
                    <div class="text-xs font-bold text-slate-500">业务员信息</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div><label class="label-text">姓名</label><input v-model="form.broker_name" class="input-group font-bold text-slate-700"></div>
                        <div><label class="label-text">电话</label><input v-model="form.broker_phone" type="tel" class="input-group font-bold text-slate-700"></div>
                        <div class="col-span-2 flex justify-between items-center bg-white p-2 rounded-lg border border-gray-200">
                            <span class="text-xs font-bold text-gray-500 ml-1">业务员人数</span>
                            <div class="flex items-center gap-3">
                                <button @click="form.broker_num > 1 && form.broker_num--" class="w-6 h-6 bg-gray-100 rounded flex items-center justify-center text-gray-500">-</button>
                                <span class="font-bold text-sm w-4 text-center">{{ form.broker_num }}</span>
                                <button @click="form.broker_num++" class="w-6 h-6 bg-blue-50 text-blue-600 rounded flex items-center justify-center font-bold">+</button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="p-3 bg-blue-50/30 rounded-xl border border-blue-100 space-y-3">
                    <div class="text-xs font-bold text-blue-800">客户信息</div>
                    <div class="grid grid-cols-2 gap-3">
                        <div class="col-span-2"><input v-model="form.client_phone" @input="formatPhone" placeholder="客户手机 (如: 153****4640)" class="input-group font-bold text-slate-700"></div>
                        <div><input v-model="form.client_name" placeholder="客户姓名" class="input-group"></div>
                        <div class="flex items-center justify-end bg-white border rounded-xl px-2">
                            <span class="text-xs text-gray-400 mr-2">人数</span>
                            <button @click="form.client_num>1 && form.client_num--" class="px-2 py-1 text-gray-400">-</button>
                            <input type="number" v-model="form.client_num" class="w-6 text-center text-xs outline-none">
                            <button @click="form.client_num++" class="px-2 py-1 text-gray-400">+</button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-2 gap-3"><div><label class="label-text">带看日期</label><input type="date" v-model="form.visit_date" class="input-group"></div><div><label class="label-text">指定销售</label><input v-model="form.designated_sales" placeholder="选填" class="input-group"></div></div>
                <div><label class="label-text">备注</label><input v-model="form.remark" placeholder="其他说明" class="input-group"></div>
                <button @click="submit" class="w-full bg-blue-600 text-white py-4 rounded-xl font-bold shadow-lg shadow-blue-500/30 active:scale-95 transition mt-2">立即报备</button>
            </div>
        </div>

        <div v-if="tab==='list'" class="fade-in space-y-4 pb-24">
            <div class="flex bg-white p-1 rounded-xl mb-2 text-xs font-bold text-gray-500 shadow-sm border border-gray-100 overflow-x-auto no-scrollbar">
                <button v-for="t in tabs" :key="t.id" @click="changeMainTab(t.id)" class="flex-1 min-w-[76px] py-2 rounded-lg transition-all whitespace-nowrap flex flex-col items-center justify-center gap-1 border" :class="subTab===t.id ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'bg-slate-50 text-slate-600 border-slate-200'">
                    <span>{{ t.name }}</span>
                    <span class="count-pill tabular-nums" :class="subTab===t.id ? 'count-pill-main-active' : 'count-pill-main-inactive'">{{ counts[t.id] || 0 }}</span>
                </button>
            </div>

            <div v-if="subTab===1 || subTab===2 || subTab===3" class="mb-4 animate-[fadeIn_0.3s_ease-out]">
                <div v-if="subTab===1" class="flex gap-1 overflow-x-auto no-scrollbar pb-1 w-full pl-0.5 pr-3">
                    <span @click="childFilter='reception_all'" class="sub-chip" :class="childFilter==='reception_all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='reception_all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.reception_all }}</span></span>
                    <span @click="childFilter='valid_report'" class="sub-chip" :class="childFilter==='valid_report'?'active':'inactive'">有效报备<span class="count-pill tabular-nums" :class="childFilter==='valid_report' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.valid_report }}</span></span>
                    <span @click="childFilter='valid'" class="sub-chip" :class="childFilter==='valid'?'active':'inactive'">有效到访<span class="count-pill tabular-nums" :class="childFilter==='valid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.valid }}</span></span>
                    <span @click="childFilter='invalid'" class="sub-chip" :class="childFilter==='invalid'?'active':'inactive'">无效<span class="count-pill tabular-nums" :class="childFilter==='invalid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.invalid }}</span></span>
                    <span @click="childFilter='repeat'" class="sub-chip" :class="childFilter==='repeat'?'active':'inactive'">新客<span class="count-pill tabular-nums" :class="childFilter==='repeat' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.repeat }}</span></span>
                </div>
                <div v-if="subTab===2" class="flex gap-1 overflow-x-auto no-scrollbar pb-1 w-full pl-0.5 pr-3">
                    <span @click="childFilter='visit_all'" class="sub-chip" :class="childFilter==='visit_all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='visit_all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_all }}</span></span>
                    <span @click="childFilter='visit_valid'" class="sub-chip" :class="childFilter==='visit_valid'?'active':'inactive'">有效到访<span class="count-pill tabular-nums" :class="childFilter==='visit_valid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_valid }}</span></span>
                    <span @click="childFilter='visit_invalid'" class="sub-chip" :class="childFilter==='visit_invalid'?'active':'inactive'">无效到访<span class="count-pill tabular-nums" :class="childFilter==='visit_invalid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_invalid }}</span></span>
                </div>
                <div v-if="subTab===3" class="flex gap-1 overflow-x-auto no-scrollbar pb-1 w-full pl-0.5 pr-3">
                    <span @click="childFilter='all'" class="sub-chip" :class="childFilter==='all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.all }}</span></span>
                    <span @click="childFilter='deposit'" class="sub-chip" :class="childFilter==='deposit'?'active':'inactive'">诚意金<span class="count-pill tabular-nums" :class="childFilter==='deposit' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.deposit }}</span></span>
                    <span @click="childFilter='lock'" class="sub-chip" :class="childFilter==='lock'?'active':'inactive'">锁房<span class="count-pill tabular-nums" :class="childFilter==='lock' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.lock }}</span></span>
                    <span @click="childFilter='sign'" class="sub-chip" :class="childFilter==='sign'?'active':'inactive'">认购<span class="count-pill tabular-nums" :class="childFilter==='sign' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.sign }}</span></span>
                    <span @click="childFilter='refund'" class="sub-chip" :class="childFilter==='refund'?'active':'inactive'">退房<span class="count-pill tabular-nums" :class="childFilter==='refund' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.refund }}</span></span>
                </div>
            </div>

            <div class="flex gap-2">
                <div class="flex-1 bg-white p-3 rounded-2xl shadow-sm flex items-center gap-2">
                    <i class="fas fa-search text-gray-400 ml-2"></i>
                    <input v-model="search.keyword" class="flex-1 text-sm outline-none" placeholder="搜客户姓名/电话/项目/经纪人/公司/门店...">
                </div>
                <select v-if="teamFilterEnabled" v-model="selectedMemberId" @change="onMemberChange" class="bg-white rounded-2xl shadow-sm px-3 text-xs text-slate-600 outline-none border border-gray-100 min-w-[108px]">
                    <option value="">全部人员</option>
                    <option v-for="m in teamMembers" :key="m.id" :value="String(m.id)">{{ m.username }}</option>
                </select>
                <button @click="searchHistory" class="bg-white w-12 rounded-2xl shadow-sm flex items-center justify-center text-gray-500" :class="{'text-blue-600 bg-blue-50': isSearchingHistory}"><i class="fas fa-history"></i></button>
                <button @click="showFilters=!showFilters" class="bg-white w-12 rounded-2xl shadow-sm flex items-center justify-center text-gray-500" :class="{'text-blue-600 bg-blue-50': showFilters}"><i class="fas fa-filter"></i></button>
            </div>

            <div v-if="showFilters" class="bg-white p-4 rounded-2xl shadow-sm border border-gray-100 animate-[slideUp_0.2s_ease-out] mb-16">
                    <div class="grid grid-cols-2 gap-3 mb-3"><div><label class="filter-label">开始日期</label><input v-model="search.dateStart" type="date" class="filter-input"></div><div><label class="filter-label">结束日期</label><input v-model="search.dateEnd" type="date" class="filter-input"></div></div>
                    <div class="mb-3">
                        <label class="filter-label">所属楼盘（可多选）</label>
                        <input v-model="projectFilterKeyword" type="text" class="filter-input mb-2" placeholder="输入关键字筛选楼盘…" autocomplete="off">
                        <div class="flex items-center justify-between gap-2 mb-2 text-[10px]">
                            <span class="text-gray-400">已选 <b class="text-slate-600">{{ (search.projectIds || []).length }}</b> 个，不选表示全部</span>
                            <button type="button" @click="clearProjectFilter" class="text-blue-600 font-bold shrink-0">清空已选</button>
                        </div>
                        <div class="border border-gray-200 rounded-lg max-h-44 overflow-y-auto bg-slate-50/80 p-1.5 space-y-0.5">
                            <label v-for="p in filteredProjectsForFilter" :key="p.id" class="flex items-center gap-2 text-xs py-2 px-2 rounded-lg hover:bg-white cursor-pointer border border-transparent hover:border-gray-100">
                                <input type="checkbox" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500" :checked="isProjectFilterChecked(p.id)" @change="toggleProjectFilter(p.id)">
                                <span class="flex-1 min-w-0 truncate text-slate-700">{{ p.name }}</span>
                            </label>
                            <div v-if="filteredProjectsForFilter.length===0" class="text-xs text-gray-400 py-4 text-center">无匹配楼盘</div>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <button @click="resetFilter(); showFilters=false" class="bg-gray-100 text-gray-500 py-2 rounded-lg text-xs font-bold">重置条件</button>
                        <button @click="applyFilter" class="bg-blue-600 text-white py-2 rounded-lg text-xs font-bold">确定筛选</button>
                    </div>
                </div>

            <div v-if="groupedList.length===0" class="text-center text-gray-400 text-xs py-10">暂无相关记录</div>
            
            <div v-for="(group, idx) in groupedList" :key="idx" class="bg-white rounded-2xl p-5 card-shadow mb-3 relative overflow-hidden">
                <div class="mb-3 pb-3 border-b border-gray-50">
                    <div class="flex justify-between items-center gap-2 mb-2">
                        <span class="bg-gray-100 text-gray-500 px-2 py-1 rounded text-xs">{{ group.items.length }} 项目</span>
                        <div class="flex items-center gap-2 flex-wrap justify-end">
                        <button type="button" @click="copyFilingData(group.items[0])" class="text-green-600 text-xs bg-white border border-green-200 px-3 py-1.5 rounded shadow-sm hover:bg-green-50 flex items-center gap-1"><i class="fas fa-copy"></i> 复制</button>
                        <button type="button" @click="copyGroupSummaryToClipboard(group)" class="text-slate-600 text-xs bg-white border border-slate-200 px-3 py-1.5 rounded shadow-sm hover:bg-slate-50 flex items-center gap-1"><i class="fas fa-clipboard"></i> 复制文本</button>
                        </div>
                    </div>
                    <div class="grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div class="text-gray-500">
                            客户:
                            <span class="font-bold text-slate-700">{{ group.client_name }}</span>
                            <span class="text-gray-400 ml-1">{{ maskPhone(group.client_phone) }}</span>
                        </div>
                        <div class="text-right text-gray-500">
                            渠道人:
                            <span class="font-bold text-slate-700">{{ (group.items[0].follower || '').trim() || '公池' }}</span>
                        </div>
                        <div class="text-gray-500">
                            经纪人:
                            <span class="font-bold text-slate-700">{{ group.items[0].broker_name || '-' }}</span>
                            <a
                                v-if="group.items[0].broker_phone"
                                :href="'tel:' + group.items[0].broker_phone"
                                class="text-blue-500 ml-1 hover:underline"
                            >
                                {{ group.items[0].broker_phone }}
                            </a>
                        </div>
                        <div class="text-right text-gray-500 truncate" :title="group.items[0].company_name || ''">
                            <span class="font-bold text-slate-700">{{ group.items[0].company_name || '-' }}</span>
                        </div>
                    </div>
                </div>

                <div class="space-y-3">
                    <div v-for="item in group.items" :key="item.id" class="bg-slate-50 p-3 rounded-xl border border-slate-100 relative">
                        <div
                            v-if="item.status >= 2 && item.status < 5 && item.visit_type==1"
                            class="absolute left-0 top-0 bottom-0 w-1 bg-lime-400 rounded-l-xl"
                        ></div>
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex-1 min-w-0">
                                <div class="font-bold text-sm text-slate-700 truncate">{{ item.project_name }}</div>
                                <div class="text-[10px] text-slate-500 mt-0.5">客户意向: {{ clientIntentionLabel(item) }}</div>
                            </div>
                            <div class="flex flex-col items-end shrink-0">
                                <span v-if="item.status == 1 && (!item.visit_type || item.visit_type != 0)" class="text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded border border-gray-200 font-bold flex items-center gap-1"><i class="far fa-clock"></i> 待处理</span>
                                <span v-if="item.status == 1 && item.visit_type == 0" class="text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded border border-blue-200 font-bold flex items-center gap-1"><i class="fas fa-file-alt"></i> 有效报备</span>
                                <span v-if="item.status >= 2 && item.status < 5 && item.visit_type==1" class="text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded border border-green-200 font-bold flex items-center gap-1"><i class="fas fa-check-circle"></i> 有效到访</span>
                                <span v-if="item.status == 5 && item.visit_type==2" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded border border-red-200 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> 无效</span>
                                <span v-if="item.status == 6" class="text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded border border-red-200 font-bold flex items-center gap-1"><i class="fas fa-times-circle"></i> 报备无效</span>
                                <span v-if="item.status == 5 && item.visit_type==3" class="text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded border border-orange-200 font-bold flex items-center gap-1"><i class="fas fa-clone"></i> 重复</span>
                                <span v-if="item.status > 1 && item.status < 5 && !item.visit_type" class="px-2 py-0.5 rounded text-[10px] font-bold border" :class="statusClass(item.status)">{{ statusText(item.status) }}</span>
                                <div class="mt-2 flex justify-end gap-2">
                                    <button v-if="item.status == 6" @click="openEditFilingModal(item)" class="text-orange-600 text-[10px] bg-white border border-orange-200 px-2 py-1 rounded shadow-sm hover:bg-orange-50 flex items-center gap-1"><i class="fas fa-edit"></i> 修改报备</button>
                                    <button @click="showFilingInfo(item)" class="text-blue-500 text-[10px] bg-white border border-blue-200 px-2 py-1 rounded shadow-sm hover:bg-blue-50 flex items-center gap-1"><i class="fas fa-file-alt"></i> 报备信息</button>
                                    <button @click="showTimeline(item)" class="text-green-500 text-[10px] bg-white border border-green-200 px-2 py-1 rounded shadow-sm hover:bg-green-50 flex items-center gap-1"><i class="fas fa-tasks"></i> 进度信息</button>
                                </div>
                            </div>
                        </div>
                        
                        <div class="text-[10px] text-gray-400 flex gap-2 items-center mb-2">
                            <span><i class="fas fa-hashtag"></i> {{ item.id }}</span>
                            <span v-if="item.status==4" class="text-green-600 font-bold">佣金: ¥{{ parseFloat(item.commission_amount).toLocaleString() }}</span>
                        </div>

                        <div v-if="item.status==2" class="flex gap-2 mb-2 border-t border-slate-200 pt-2">
                            <div class="prog-badge" :class="item.sub_stages && item.sub_stages.includes('deposit') ? 'active' : 'inactive'"><i class="fas" :class="item.sub_stages && item.sub_stages.includes('deposit') ? 'fa-check-circle' : 'fa-circle'"></i> 交定金</div>
                            <div class="prog-badge" :class="item.sub_stages && item.sub_stages.includes('lock') ? 'active' : 'inactive'"><i class="fas" :class="item.sub_stages && item.sub_stages.includes('lock') ? 'fa-check-circle' : 'fa-circle'"></i> 锁房号</div>
                            <div class="prog-badge" :class="item.sub_stages && item.sub_stages.includes('sign') ? 'active' : 'inactive'"><i class="fas" :class="item.sub_stages && item.sub_stages.includes('sign') ? 'fa-check-circle' : 'fa-circle'"></i> 签认购</div>
                        </div>
                        
                        
                        <!-- 跟进功能 - 在待处理和有效状态显示 -->
                        <div v-if="item.status == 1 || item.status == 2" class="mt-3 pt-3 border-t border-slate-200">
                            <div class="text-xs text-gray-500 mb-2">
                                <span v-if="!item.followups || item.followups.length === 0">
                                    记录变成有效的时间: {{ item.created_at }}
                                    <span class="text-blue-600 font-bold ml-2">{{ calculateFollowupTime(item, 1) }} 了</span>
                                </span>
                                <span v-else-if="item.followups.length === 1">
                                    第一次跟进时间: {{ item.followups[0].created_at }}
                                    <span class="text-blue-600 font-bold ml-2">{{ calculateFollowupTime(item, 2) }} 了</span>
                                </span>
                                <span v-else-if="item.followups.length === 2">
                                    第二次跟进时间: {{ item.followups[1].created_at }}
                                    <span class="text-blue-600 font-bold ml-2">{{ calculateFollowupTime(item, 3) }} 了</span>
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <button 
                                    v-if="getNextFollowupCount(item) === 1"
                                    @click="openFollowupModal(item, 1)"
                                    class="text-blue-600 text-[10px] bg-white border border-blue-200 px-2 py-1 rounded shadow-sm hover:bg-blue-50 flex items-center gap-1"
                                >
                                    <i class="fas fa-phone"></i> 第一次跟进
                                </button>
                                <button 
                                    v-else-if="getNextFollowupCount(item) === 2"
                                    @click="openFollowupModal(item, 2)"
                                    class="text-blue-600 text-[10px] bg-white border border-blue-200 px-2 py-1 rounded shadow-sm hover:bg-blue-50 flex items-center gap-1"
                                >
                                    <i class="fas fa-phone"></i> 第二次跟进
                                </button>
                                <button 
                                    v-else-if="getNextFollowupCount(item) === 3"
                                    @click="openFollowupModal(item, 3)"
                                    class="text-blue-600 text-[10px] bg-white border border-blue-200 px-2 py-1 rounded shadow-sm hover:bg-blue-50 flex items-center gap-1"
                                >
                                    <i class="fas fa-phone"></i> 第三次跟进
                                </button>
                                <button 
                                    v-else-if="item.followups && item.followups.length >= 3"
                                    disabled
                                    class="text-gray-400 text-[10px] bg-gray-100 border border-gray-200 px-2 py-1 rounded shadow-sm flex items-center gap-1"
                                >
                                    <i class="fas fa-check-circle"></i> 已完成三次跟进
                                </button>
                            </div>
                            
                            <!-- 已有的跟进记录 -->
                            <div v-if="item.followups && item.followups.length > 0" class="mt-2">
                                <div v-for="(followup, index) in item.followups" :key="index" class="text-xs bg-gray-50 p-2 rounded mb-1">
                                    <div class="font-bold text-gray-700">第{{ followup.followup_count }}次跟进 ({{ followup.created_at }})</div>
                                    <div class="text-gray-600" v-if="followup.summary">
                                        <span class="bg-blue-100 text-blue-600 px-1 rounded mr-1">{{ followup.summary }}</span>
                                        {{ followup.content }}
                                    </div>
                                    <div class="text-gray-600" v-else>{{ followup.content }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="fixed bottom-0 w-full max-w-md flex justify-around py-3 pt-2.5 text-[10px] z-50 glass-nav">
        <div @click="tab='dash'" :class="tab=='dash' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-chart-pie text-lg"></i><span>数据</span></div>
        <div @click="tab='form'" :class="tab=='form' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-pen-to-square text-lg"></i><span>报备</span></div>
        <div v-if="userInfo.role == 'admin' || userInfo.role == 'staff'" @click="goToWorkbench" class="glass-nav-item flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-check-double text-lg"></i><span>工作台</span></div>
        <div @click="tab='list'" :class="tab=='list' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-clock-rotate-left text-lg"></i><span>记录</span></div>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">进度信息</h3>
            <div class="flex-1 overflow-y-auto pr-2">
                <div class="pl-2">
                    <div v-for="(log, idx) in parseLog(currentItem.status_log)" :key="idx" class="timeline-item">
                        <div class="timeline-dot" :class="idx===0?'active':''"></div>
                        <div class="text-xs text-gray-400 mb-1">{{ log.time }}</div>
                        <div class="text-sm font-bold text-slate-700">{{ log.title }}</div>
                        <div v-if="log.desc" class="text-xs text-gray-500 bg-gray-50 p-2 rounded mt-1">{{ log.desc }}</div>
                    </div>
                </div>
            </div>
            <button @click="showModal=false" class="w-full bg-slate-100 text-slate-500 py-3 rounded-xl font-bold mt-4 text-sm">关闭进度</button>
        </div>
    </div>

    <div v-if="showFilingModal" class="modal-overlay" @click.self="showFilingModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showFilingModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">报备信息</h3>
            <div class="flex-1 overflow-y-auto pr-2 space-y-3">
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">报备楼盘</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.project_name }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">门店名称</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.company_name }}</div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">经纪人姓名</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.broker_name }}</div>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">经纪人电话</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.broker_phone }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">客户姓名</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.client_name }}</div>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">客户电话</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.client_phone }}</div>
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">经纪人人数</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.broker_num }} 人</div>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3">
                        <div class="text-xs text-gray-500 font-bold mb-1">客户人数</div>
                        <div class="text-sm font-bold text-slate-700">{{ currentItem.client_num }} 人</div>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">带看日期</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.visit_time || '-' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">指定销售</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.designated_sales || '无' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">客户意向度</div>
                    <div class="text-sm font-bold text-slate-700">
                        {{ currentItem.client_intention == 5 ? '非常强烈' : 
                           currentItem.client_intention == 4 ? '强烈' : 
                           currentItem.client_intention == 3 ? '一般' : 
                           currentItem.client_intention == 2 ? '还行' : 
                           currentItem.client_intention == 1 ? '无意向' : '未设置' }}
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">备注</div>
                    <div class="text-sm text-slate-700">{{ currentItem.remark || '无' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">提交时间</div>
                    <div class="text-sm text-slate-700">{{ currentItem.created_at }}</div>
                </div>
            </div>
            <button @click="showFilingModal=false" class="w-full bg-slate-100 text-slate-500 py-3 rounded-xl font-bold mt-4 text-sm">关闭</button>
        </div>
    </div>

    <div v-if="showEditFilingModal" class="modal-overlay" @click.self="showEditFilingModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showEditFilingModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">修改报备信息</h3>
            <div class="flex-1 overflow-y-auto pr-2 space-y-3">
                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">客户姓名</label>
                        <input v-model="editFilingForm.client_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入客户姓名">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">客户电话</label>
                        <input v-model="editFilingForm.client_phone" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入客户电话">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">公司名称</label>
                        <input v-model="editFilingForm.company_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入公司名称">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">跟进人</label>
                        <select v-model="editFilingForm.follower" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white">
                            <option value="">公池</option>
                            <option v-for="agent in agents" :key="agent.username" :value="agent.username">{{ agent.username }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">经纪人姓名</label>
                        <input v-model="editFilingForm.broker_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入经纪人姓名">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">经纪人电话</label>
                        <input v-model="editFilingForm.broker_phone" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入经纪人电话">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">待看日期</label>
                        <input type="date" v-model="editFilingForm.visit_date" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">备注</label>
                        <textarea v-model="editFilingForm.remark" rows="3" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入备注信息"></textarea>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button @click="showEditFilingModal=false" class="bg-slate-100 text-slate-500 py-3 rounded-xl font-bold text-sm">取消</button>
                <button @click="submitEditFiling" class="bg-blue-600 text-white py-3 rounded-xl font-bold text-sm">保存修改</button>
            </div>
        </div>
    </div>

    <div v-if="showModal" class="modal-overlay" @click.self="showModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">进度信息</h3>
            <div class="flex-1 overflow-y-auto pr-2">
                <div class="pl-2">
                    <div v-for="(log, idx) in parseLog(currentItem.status_log)" :key="idx" class="timeline-item">
                        <div class="timeline-dot" :class="idx===0?'active':''"></div>
                        <div class="text-xs text-gray-400 mb-1">{{ log.time }}</div>
                        <div class="text-sm font-bold text-slate-700">{{ log.title }}</div>
                        <div v-if="log.desc" class="text-xs text-gray-500 bg-gray-50 p-2 rounded mt-1">{{ log.desc }}</div>
                    </div>
                </div>
            </div>
            <button @click="showModal=false" class="w-full bg-slate-100 text-slate-500 py-3 rounded-xl font-bold mt-4 text-sm">关闭进度</button>
        </div>
    </div>

    <div v-if="showFilingModal" class="modal-overlay" @click.self="showFilingModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showFilingModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">报备信息</h3>
            <div class="flex-1 overflow-y-auto pr-2 space-y-3">
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="grid grid-cols-2 gap-2 text-xs">
                        <div class="text-gray-400">项目名称</div><div class="font-bold">{{ currentItem.project_name }}</div>
                        <div class="text-gray-400">客户姓名</div><div class="font-bold">{{ currentItem.client_name }}</div>
                        <div class="text-gray-400">客户电话</div><div class="font-mono">{{ currentItem.client_phone }}</div>
                        <div class="text-gray-400">公司名称</div><div class="font-bold">{{ currentItem.company_name || '-' }}</div>
                        <div class="text-gray-400">经纪人</div><div class="font-bold">{{ currentItem.broker_name || '-' }}</div>
                        <div class="text-gray-400">经纪人电话</div><div class="font-mono">{{ currentItem.broker_phone || '-' }}</div>
                        <div class="text-gray-400">报备时间</div><div>{{ currentItem.created_at }}</div>
                        <div class="text-gray-400">状态</div><div :class="statusClass(currentItem.status)">{{ statusText(currentItem.status) }}</div>
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">带看人数</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.client_num || 1 }} 人</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">带看日期</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.visit_time || '-' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">指定销售</div>
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.designated_sales || '无' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">客户意向度</div>
                    <div class="text-sm font-bold text-slate-700">
                        {{ currentItem.client_intention == 5 ? '非常强烈' : 
                           currentItem.client_intention == 4 ? '强烈' : 
                           currentItem.client_intention == 3 ? '一般' : 
                           currentItem.client_intention == 2 ? '还行' : 
                           currentItem.client_intention == 1 ? '无意向' : '未设置' }}
                    </div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">备注</div>
                    <div class="text-sm text-slate-700">{{ currentItem.remark || '无' }}</div>
                </div>
                <div class="bg-gray-50 rounded-xl p-3">
                    <div class="text-xs text-gray-500 font-bold mb-1">提交时间</div>
                    <div class="text-sm text-slate-700">{{ currentItem.created_at }}</div>
                </div>
            </div>
            <button @click="showFilingModal=false" class="w-full bg-slate-100 text-slate-500 py-3 rounded-xl font-bold mt-4 text-sm">关闭</button>
        </div>
    </div>

    <div v-if="showEditFilingModal" class="modal-overlay" @click.self="showEditFilingModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showEditFilingModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">修改报备信息</h3>
            <div class="flex-1 overflow-y-auto pr-2 space-y-3">
                <div class="grid grid-cols-1 gap-3">
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">客户姓名</label>
                        <input v-model="editFilingForm.client_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入客户姓名">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">客户电话</label>
                        <input v-model="editFilingForm.client_phone" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入客户电话">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">公司名称</label>
                        <input v-model="editFilingForm.company_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入公司名称">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">跟进人</label>
                        <select v-model="editFilingForm.follower" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none bg-white">
                            <option value="">公池</option>
                            <option v-for="agent in agents" :key="agent.username" :value="agent.username">{{ agent.username }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">经纪人姓名</label>
                        <input v-model="editFilingForm.broker_name" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入经纪人姓名">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">经纪人电话</label>
                        <input v-model="editFilingForm.broker_phone" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入经纪人电话">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">带看日期</label>
                        <input type="date" v-model="editFilingForm.visit_time" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">指定销售</label>
                        <input v-model="editFilingForm.designated_sales" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="选填">
                    </div>
                    <div>
                        <label class="text-xs text-gray-500 mb-1 block">备注</label>
                        <textarea v-model="editFilingForm.remark" rows="3" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none" placeholder="请输入备注信息"></textarea>
                    </div>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-4">
                <button @click="showEditFilingModal=false" class="bg-slate-100 text-slate-500 py-3 rounded-xl font-bold text-sm">取消</button>
                <button @click="submitEditFiling" class="bg-blue-600 text-white py-3 rounded-xl font-bold text-sm">保存修改</button>
            </div>
        </div>
    </div>

    <div v-if="showEdit" class="modal-overlay" @click.self="showEdit=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showEdit=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">跟进进度录入</h3>
            <div class="space-y-4">
                <label class="text-xs font-bold text-slate-500 block">下定进度 (可多选)</label>
                <div class="grid grid-cols-3 gap-3 mb-2">
                    <div @click="toggleStage('deposit')" class="stage-box" :class="{checked: editSubStages.includes('deposit')}"><div class="stage-icon"><i class="fas fa-check" v-if="editSubStages.includes('deposit')"></i></div><span class="text-xs font-bold">交定金</span></div>
                    <div @click="toggleStage('lock')" class="stage-box" :class="{checked: editSubStages.includes('lock')}"><div class="stage-icon"><i class="fas fa-check" v-if="editSubStages.includes('lock')"></i></div><span class="text-xs font-bold">锁房号</span></div>
                    <div @click="toggleStage('sign')" class="stage-box" :class="{checked: editSubStages.includes('sign')}"><div class="stage-icon"><i class="fas fa-check" v-if="editSubStages.includes('sign')"></i></div><span class="text-xs font-bold">签认购书</span></div>
                </div>
                <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100">
                    <div class="text-xs text-gray-500 font-bold mb-2">备注说明</div>
                    <textarea v-model="editVoice" placeholder="填写跟进情况..." class="w-full bg-white rounded-xl p-2 h-16 border border-gray-200 text-xs resize-none outline-none"></textarea>
                </div>
            </div>
            <button @click="submitUpdate" class="w-full bg-blue-600 text-white py-3 rounded-xl font-bold shadow-lg mt-6 text-sm active:scale-95 transition">保存进度</button>
        </div>
    </div>

    <div v-if="showFollowupModal" class="modal-overlay" @click.self="showFollowupModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showFollowupModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">第{{ currentFollowupCount }}次跟进</h3>
            <div class="space-y-4">
                <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100">
                    <div class="text-xs text-gray-500 font-bold mb-2">跟进概述</div>
                    <select v-model="followupSummary" class="w-full bg-white rounded-xl p-2 border border-gray-200 text-xs outline-none">
                        <option value="">请选择跟进概述</option>
                        <option v-for="summary in followupSummaries[currentFollowupCount]" :key="summary" :value="summary">{{ summary }}</option>
                    </select>
                </div>
                <div class="bg-gray-50 rounded-2xl p-4 border border-gray-100">
                    <div class="text-xs text-gray-500 font-bold mb-2">跟进内容</div>
                    <textarea v-model="followupContent" placeholder="请详细描述跟进情况..." class="w-full bg-white rounded-xl p-2 h-32 border border-gray-200 text-xs resize-none outline-none"></textarea>
                </div>
                <div class="text-xs text-gray-400">
                    <span v-if="currentFollowupCount === 1">
                        距离记录变成有效时间已过去 {{ calculateFollowupTime(currentFollowupItem, 1) }}
                    </span>
                    <span v-else-if="currentFollowupCount === 2">
                        距离第一次跟进已过去 {{ calculateFollowupTime(currentFollowupItem, 2) }}
                    </span>
                    <span v-else-if="currentFollowupCount === 3">
                        距离第二次跟进已过去 {{ calculateFollowupTime(currentFollowupItem, 3) }}
                    </span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-6">
                <button @click="showFollowupModal=false" class="bg-slate-100 text-slate-500 py-3 rounded-xl font-bold text-sm">取消</button>
                <button @click="submitFollowup" class="bg-blue-600 text-white py-3 rounded-xl font-bold text-sm">提交跟进</button>
            </div>
        </div>
    </div>

    <div v-if="showPwdModal" class="modal-overlay z-[60]" @click.self="showPwdModal=false">
        <div class="modal-content flex flex-col">
            <div class="close-btn" @click="showPwdModal=false">&times;</div>
            <h3 class="font-bold text-lg mb-4 text-slate-800">修改密码</h3>
            <div class="space-y-3">
                <div>
                    <div class="label-text">原密码</div>
                    <input v-model="pwdOld" type="password" autocomplete="current-password" class="input-group" placeholder="请输入当前密码">
                </div>
                <div>
                    <div class="label-text">新密码</div>
                    <input v-model="pwdNew" type="password" autocomplete="new-password" class="input-group" placeholder="至少 6 位">
                </div>
                <div>
                    <div class="label-text">确认新密码</div>
                    <input v-model="pwdConfirm" type="password" autocomplete="new-password" class="input-group" placeholder="再次输入新密码">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-2 mt-6">
                <button type="button" @click="showPwdModal=false" class="bg-slate-100 text-slate-500 py-3 rounded-xl font-bold text-sm">取消</button>
                <button type="button" @click="submitPwdChange" :disabled="pwdSubmitting" class="bg-blue-600 text-white py-3 rounded-xl font-bold text-sm disabled:opacity-50">{{ pwdSubmitting ? '提交中…' : '保存' }}</button>
            </div>
        </div>
    </div>

</div>

<script>
const { createApp, ref, onMounted, computed, nextTick, watch } = Vue;
createApp({
    setup() {
        const tab = ref('form');
        const list = ref([]);
        const stats = ref({
            comm:0, total:0, deal:0, visit:0,
            merchant_total: 0, merchant_started: 0,
            month_report_unique: 0, month_visit_unique: 0, month_deal_total: 0, month_conversion_rate: 0,
            chart_labels: [], chart_data: { report: [], visit: [], deal: [] }
        });
        const statsRange = ref('month');
        const statsCustomStart = ref('');
        const statsCustomEnd = ref('');
        const projects = ref([]);
        const smartText = ref('');
        const subTab = ref(0); 
        const childFilter = ref('reception_all'); 
        const getTodayDateStr = () => new Date().toISOString().slice(0, 10);
        const search = ref({ keyword: '', projectIds: [], dateStart: getTodayDateStr(), dateEnd: getTodayDateStr() });
        const projectFilterKeyword = ref('');
        const filteredProjectsForFilter = computed(() => {
            const kw = projectFilterKeyword.value.trim();
            const arr = projects.value || [];
            if (!kw) return arr;
            return arr.filter((p) => (p.name || '').includes(kw));
        });
        const isProjectFilterChecked = (id) => (search.value.projectIds || []).map(String).includes(String(id));
        const toggleProjectFilter = (id) => {
            const sid = String(id);
            const cur = [...(search.value.projectIds || [])].map(String);
            const idx = cur.indexOf(sid);
            if (idx >= 0) cur.splice(idx, 1);
            else cur.push(sid);
            search.value = { ...search.value, projectIds: cur };
        };
        const clearProjectFilter = () => {
            search.value = { ...search.value, projectIds: [] };
        };
        const isParsing = ref(false); // AI加载状态
        
        const userInfo = <?php echo json_encode($CURRENT_USER); ?>;
        
        const form = ref({
            project_ids: [],
            company_name: '', follower: '', broker_name: userInfo.name, broker_phone: userInfo.phone, 
            broker_num: 2, client_name: '', client_phone: '', client_num: 2, 
            visit_date: new Date().toISOString().slice(0, 10), designated_sales: '', remark: ''
        });
        const showAllProjects = ref(false);

        const companyResults = ref([]);
        const showDropdown = ref(false);
        const showModal = ref(false);
        const showFilingModal = ref(false);
        const showEditFilingModal = ref(false);
        const showEdit = ref(false);
        const showFilters = ref(false);
        const isSearchingHistory = ref(false);
        const teamMembers = ref([]);
        const selectedMemberId = ref('');
        const teamFilterEnabled = ref(false);
        const currentItem = ref({});
        const editFilingForm = ref({});
        const editSubStages = ref([]);
        const editVoice = ref('');
        let searchTimer = null;
        
        // 跟进相关状态
        const showFollowupModal = ref(false);
        const currentFollowupItem = ref({});
        const followupContent = ref('');
        const currentFollowupCount = ref(1);
        const followups = ref([]);
        const followupLoading = ref(false);

        const showPwdModal = ref(false);
        const pwdOld = ref('');
        const pwdNew = ref('');
        const pwdConfirm = ref('');
        const pwdSubmitting = ref(false);
        const openPwdModal = () => {
            pwdOld.value = '';
            pwdNew.value = '';
            pwdConfirm.value = '';
            showPwdModal.value = true;
        };
        const submitPwdChange = async () => {
            if (!pwdOld.value || !pwdNew.value || !pwdConfirm.value) {
                alert('请填写完整');
                return;
            }
            if (pwdNew.value !== pwdConfirm.value) {
                alert('两次输入的新密码不一致');
                return;
            }
            if (pwdNew.value.length < 6) {
                alert('新密码至少 6 位');
                return;
            }
            pwdSubmitting.value = true;
            try {
                const fd = new FormData();
                fd.append('old_password', pwdOld.value);
                fd.append('new_password', pwdNew.value);
                const r = await fetch('agent_change_password_api.php', { method: 'POST', body: fd });
                const d = await r.json();
                if (d.status === 'success') {
                    alert(d.msg || '修改成功');
                    showPwdModal.value = false;
                } else {
                    alert(d.msg || '修改失败');
                }
            } catch (e) {
                alert('网络错误，请稍后重试');
            } finally {
                pwdSubmitting.value = false;
            }
        };

        const tabs = [{id: 0, name: '全部'}, {id: 1, name: '报备客户'}, {id: 2, name: '来访客户'}, {id: 3, name: '成交/完成'}];
        const applyWorkbenchGlobalFilters = (items) => {
            let res = items;
            const f = search.value;
            const k = (f.keyword || '').trim();
            if (k && res.length > 0) {
                res = res.filter((item) => {
                    const statusStr = statusText(item.status);
                    const stageText = (item.sub_stages || '').replace('deposit','交定').replace('lock','锁房').replace('sign','签约');
                    return (
                        (item.client_name && item.client_name.includes(k)) ||
                        (item.client_phone && item.client_phone.includes(k)) ||
                        (item.project_name && item.project_name.includes(k)) ||
                        statusStr.includes(k) ||
                        stageText.includes(k) ||
                        (item.broker_name && item.broker_name.includes(k)) ||
                        (item.broker_phone && item.broker_phone.includes(k)) ||
                        (item.agent_name && item.agent_name.includes(k)) ||
                        (item.agent_phone && item.agent_phone.includes(k)) ||
                        (item.company_name && (item.company_name.includes(k) || k.includes(item.company_name))) ||
                        (item.company_full_name && (item.company_full_name.includes(k) || k.includes(item.company_full_name))) ||
                        (item.store_name && (item.store_name.includes(k) || k.includes(item.store_name)))
                    );
                });
            }
            const pids = f.projectIds || [];
            if (pids.length && res.length > 0) {
                const set = new Set(pids.map(String));
                res = res.filter((item) => set.has(String(item.project_id)));
            }
            if (f.dateStart && res.length > 0) res = res.filter((item) => item.created_at >= f.dateStart);
            if (f.dateEnd && res.length > 0) res = res.filter((item) => item.created_at <= f.dateEnd + ' 23:59:59');
            return res;
        };
        const workbenchBaseFilteredList = computed(() => applyWorkbenchGlobalFilters(list.value));
        const normalizePhoneForNewCustomer = (phone) => {
            const raw = String(phone || '').trim();
            if (!raw) return '';
            const digits = raw.replace(/\D/g, '');
            if (digits.length >= 7) return digits.substring(0, 3) + '****' + digits.substring(digits.length - 4);
            const maskedMatch = raw.match(/^(\d{3})\*+(\d{4})$/);
            if (maskedMatch) return maskedMatch[1] + '****' + maskedMatch[2];
            return digits || raw;
        };
        const getNewCustomerKey = (item) => {
            const name = String(item && item.client_name ? item.client_name : '').trim();
            const surname = name ? name.charAt(0) : '';
            const phoneMask = normalizePhoneForNewCustomer(item && item.client_phone ? item.client_phone : '');
            return `${surname}|${phoneMask}`;
        };
        const newCustomerKeyCounts = computed(() => {
            const map = {};
            (list.value || []).forEach((i) => {
                const key = getNewCustomerKey(i);
                if (!key || key === '|') return;
                map[key] = (map[key] || 0) + 1;
            });
            return map;
        });
        const isNewCustomer = (item) => {
            const key = getNewCustomerKey(item);
            if (!key || key === '|') return false;
            return (newCustomerKeyCounts.value[key] || 0) === 1;
        };
        const receptionChildCounts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const n = (pred) => base.filter(pred).length;
            return {
                reception_all: n((i) => i.status == 1),
                valid_report: n((i) => i.status == 1 && i.visit_type == 0),
                valid: n((i) => i.status == 2),
                invalid: n((i) => i.status == 6),
                repeat: n((i) => isNewCustomer(i)),
            };
        });
        const visitChildCounts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const n = (pred) => base.filter(pred).length;
            return {
                visit_all: n((i) => i.status == 2 || (i.status == 5 && i.visit_type == 2)),
                visit_valid: n((i) => i.status == 2),
                visit_invalid: n((i) => i.status == 5 && i.visit_type == 2),
            };
        });
        const depositChildCounts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const n = (pred) => base.filter(pred).length;
            const getStageSet = (i) => {
                const raw = String(i && i.sub_stages ? i.sub_stages : '');
                return new Set(raw.split(',').map((s) => s.trim()).filter(Boolean));
            };
            const isRefund = (i) => i.status == 7;
            const isDepositOnly = (i) => {
                if (i.status != 2) return false;
                const set = getStageSet(i);
                return set.has('deposit') && !set.has('lock') && !set.has('sign');
            };
            const isDepositLock = (i) => {
                if (i.status != 2) return false;
                const set = getStageSet(i);
                return set.has('deposit') && set.has('lock') && !set.has('sign');
            };
            const isDepositLockSign = (i) => {
                if (i.status != 2) return false;
                const set = getStageSet(i);
                return set.has('deposit') && set.has('lock') && set.has('sign');
            };
            return {
                all: n((i) => isDepositOnly(i) || isDepositLock(i) || isDepositLockSign(i) || isRefund(i)),
                deposit: n((i) => isDepositOnly(i)),
                lock: n((i) => isDepositLock(i)),
                sign: n((i) => isDepositLockSign(i)),
                refund: n((i) => isRefund(i)),
            };
        });
        const counts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const c = { 0: 0, 1: 0, 2: 0, 3: 0 };
            base.forEach((i) => {
                c[0]++;
                if (i.status == 2 || (i.status == 5 && i.visit_type == 2)) c[2]++;
                else if (c[i.status] !== undefined) c[i.status]++;
            });
            c[3] = depositChildCounts.value.all;
            return c;
        });
        const filteredList = computed(() => {
            let res = list.value;
            if (subTab.value === 1) {
                if (childFilter.value === 'reception_all') res = res.filter((i) => i.status == 1);
                else if (childFilter.value === 'valid_report') res = res.filter((i) => i.status == 1 && i.visit_type == 0);
                else if (childFilter.value === 'valid') res = res.filter((i) => i.status == 2);
                else if (childFilter.value === 'invalid') res = res.filter((i) => i.status == 6);
                else if (childFilter.value === 'repeat') res = res.filter((i) => isNewCustomer(i));
            } else if (subTab.value === 2) {
                res = res.filter((i) => i.status == 2 || (i.status == 5 && i.visit_type == 2));
                if (childFilter.value === 'visit_valid') res = res.filter((i) => i.status == 2);
                else if (childFilter.value === 'visit_invalid') res = res.filter((i) => i.status == 5 && i.visit_type == 2);
            } else if (subTab.value === 3) {
                res = res.filter((i) => {
                    const raw = String(i && i.sub_stages ? i.sub_stages : '');
                    const set = new Set(raw.split(',').map((s) => s.trim()).filter(Boolean));
                    const isDepositOnly = i.status == 2 && set.has('deposit') && !set.has('lock') && !set.has('sign');
                    const isDepositLock = i.status == 2 && set.has('deposit') && set.has('lock') && !set.has('sign');
                    const isDepositLockSign = i.status == 2 && set.has('deposit') && set.has('lock') && set.has('sign');
                    const isRefund = i.status == 7;
                    return isDepositOnly || isDepositLock || isDepositLockSign || isRefund;
                });
                if (childFilter.value !== 'all') {
                    if (childFilter.value === 'refund') res = res.filter((i) => i.status == 7);
                    else {
                        res = res.filter((i) => {
                            if (i.status != 2) return false;
                            const raw = String(i && i.sub_stages ? i.sub_stages : '');
                            const set = new Set(raw.split(',').map((s) => s.trim()).filter(Boolean));
                            if (childFilter.value === 'deposit') return set.has('deposit') && !set.has('lock') && !set.has('sign');
                            if (childFilter.value === 'lock') return set.has('deposit') && set.has('lock') && !set.has('sign');
                            if (childFilter.value === 'sign') return set.has('deposit') && set.has('lock') && set.has('sign');
                            return false;
                        });
                    }
                }
            }
            return applyWorkbenchGlobalFilters(res);
        });

        const groupedList = computed(() => {
            const groups = {};
            filteredList.value.forEach(item => {
                const key = item.client_phone;
                if (!groups[key]) groups[key] = { client_name: item.client_name, client_phone: item.client_phone, items: [] };
                groups[key].items.push(item);
            });
            // 对每个分组内的项目按时间倒序排列
            Object.values(groups).forEach(group => {
                group.items.sort((a, b) => new Date(b.created_at) - new Date(a.created_at));
            });
            // 对分组本身按最新项目时间倒序排列
            return Object.values(groups).sort((a, b) => new Date(b.items[0].created_at) - new Date(a.items[0].created_at));
        });

        const loadProjects = () => {
            fetch('?action=get_projects').then(r=>r.json()).then(d=>projects.value=d);
        };

        const loadStats = () => {
            if(!form.value.broker_phone) return;
            const query = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
            let statsQuery = query + '&range_type=' + encodeURIComponent(statsRange.value);
            if (statsRange.value === 'custom') {
                statsQuery += '&custom_start=' + encodeURIComponent(statsCustomStart.value || '');
                statsQuery += '&custom_end=' + encodeURIComponent(statsCustomEnd.value || '');
            }
            fetch('?action=get_stats&phone=' + form.value.broker_phone + statsQuery)
                .then(r=>r.json())
                .then(d=> { stats.value = d; if(tab.value==='dash') drawChart(); });
        };

        const loadAllData = () => {
            if(!form.value.broker_phone) return;
            const query = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
            fetch('?action=get_list&phone=' + form.value.broker_phone + query).then(r=>r.json()).then(d=>list.value=d);
            loadStats();
        };

        const changeStatsRange = (range) => {
            statsRange.value = range;
            if (range !== 'custom') {
                loadStats();
            }
        };

        const applyStatsCustomRange = () => {
            if (!statsCustomStart.value || !statsCustomEnd.value) {
                alert('请选择完整的自定义起止日期');
                return;
            }
            loadStats();
        };
        
        const searchHistory = async () => {
            const keyword = search.value.keyword.trim();
            if(!keyword) {
                // 当搜索条件为空时，加载全部内容
                loadAllData();
                return;
            }
            
            isSearchingHistory.value = true;
            try {
                const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
                const res = await fetch('?action=search_history&keyword=' + encodeURIComponent(keyword) + memberQuery);
                const data = await res.json();
                list.value = Array.isArray(data) ? data : [];
                
                if(list.value.length === 0) {
                    alert('未找到匹配的历史记录');
                } else {
                    alert(`找到 ${list.value.length} 条历史记录`);
                }
            } catch (e) {
                alert('搜索失败');
            } finally {
                isSearchingHistory.value = false;
            }
        };

        const searchCompany = () => {
            if (searchTimer) clearTimeout(searchTimer);
            searchTimer = setTimeout(async () => {
                const kw = form.value.company_name;
                if (!kw) {
                    companyResults.value = [];
                    return;
                }
                const res = await fetch('?action=search_company&kw=' + encodeURIComponent(kw));
                const data = await res.json();
                companyResults.value = Array.isArray(data) ? data : [];
                if(companyResults.value.length > 0) showDropdown.value = true;
            }, 300);
        };

        const normalizeFollowerForForm = (follower) => {
            const name = String(follower || '').trim();
            // 下拉中“公池”使用空值表示；张婷婷也按公池处理
            if (!name || name === '公池' || name === '公共池' || name === '张婷婷') return '';
            return name;
        };

        // 选择公司
        const selectCompany = (company) => {
            form.value.company_name = company.name;
            form.value.follower = normalizeFollowerForForm(company.follower);
            showDropdown.value = false;
        };

        const pickBestCompanyMatch = (keyword, list) => {
            const kw = String(keyword || '').trim();
            if (!kw || !Array.isArray(list) || list.length === 0) return null;
            const kwLower = kw.toLowerCase();

            const scoreCompany = (name) => {
                const n = String(name || '').trim();
                const nLower = n.toLowerCase();
                if (!n) return -1;
                if (nLower === kwLower) return 100000;
                if (nLower.startsWith(kwLower)) return 90000 - Math.abs(n.length - kw.length);
                if (nLower.includes(kwLower) || kwLower.includes(nLower)) return 80000 - Math.abs(n.length - kw.length);

                // 连续字符命中分（越高越接近）
                let match = 0;
                for (let i = 0; i < kw.length; i++) {
                    if (n.includes(kw[i])) match++;
                }
                return match * 100 - Math.abs(n.length - kw.length);
            };

            const sorted = [...list].sort((a, b) => {
                const sa = scoreCompany(a && a.name);
                const sb = scoreCompany(b && b.name);
                if (sb !== sa) return sb - sa;
                return String(a && a.name || '').length - String(b && b.name || '').length;
            });
            return sorted[0] || null;
        };

        const autoSelectCompanyFromKeyword = async (keyword) => {
            const kw = String(keyword || '').trim();
            if (!kw) return false;
            try {
                const res = await fetch(`?action=search_company&kw=${encodeURIComponent(kw)}`);
                const data = await res.json();
                const list = Array.isArray(data) ? data : [];
                companyResults.value = list;
                const best = pickBestCompanyMatch(kw, list);
                if (best) {
                    selectCompany(best); // 复用手动选择逻辑，自动带出跟进人
                    return true;
                }
            } catch (e) {
                // 忽略自动匹配异常，保留AI原始识别结果
            }
            return false;
        };
        
        // 格式化手机号为前三后四中间星号
        const formatPhone = () => {
            let phone = form.value.client_phone;
            // 移除所有非数字字符
            phone = phone.replace(/\D/g, '');
            // 如果手机号长度大于等于7，格式化为前三后四中间星号
            if (phone.length >= 7) {
                form.value.client_phone = phone.substring(0, 3) + '****' + phone.substring(phone.length - 4);
            }
        };

        // 统一手机号展示：至少7位数字时转为前三后四脱敏格式
        const normalizeMaskedPhone = (phone) => {
            const digits = String(phone || '').replace(/\D/g, '');
            if (!digits) return '';
            if (digits.length >= 7) {
                return digits.substring(0, 3) + '****' + digits.substring(digits.length - 4);
            }
            return digits;
        };
        
        // 加载所有可能的跟进人（从agents表）
        const agents = ref([]);
        const brokerDirectory = ref([]);
        const loadAgents = async () => {
            try {
                const res = await fetch('?action=get_agents');
                const data = await res.json();
                agents.value = Array.isArray(data) ? data : [];
            } catch (e) {
                console.error('加载跟进人失败:', e);
            }
        };

        const loadBrokerDirectory = async () => {
            try {
                const res = await fetch('?action=get_broker_directory&_t=' + Date.now(), { cache: 'no-store' });
                const data = await res.json();
                brokerDirectory.value = Array.isArray(data) ? data : [];
            } catch (e) {
                console.error('加载经纪人通讯录失败:', e);
                brokerDirectory.value = [];
            }
        };

        const sanitizeBrokerName = (name) => {
            let n = String(name || '').trim();
            // 兼容 AI 可能返回的“经纪人姓名:张三 / 姓名：张三 / 经纪人:张三”格式
            n = n.replace(/^[\s,，;；。]*?(经纪人姓名|经纪人|姓名)\s*[:：]\s*/u, '');
            // 去除首尾可能残留的标点
            n = n.replace(/^[\s,，;；。]+|[\s,，;；。]+$/gu, '');
            // 常见异体字归一，避免“何守徳/何守德”匹配失败
            const charMap = {
                '徳': '德'
            };
            n = n.split('').map(ch => charMap[ch] || ch).join('');
            return n;
        };

        const normalizePersonName = (name) => {
            return sanitizeBrokerName(name).replace(/\s+/g, '').trim();
        };

        const resolveBrokerPhoneByName = (name) => {
            const target = normalizePersonName(name);
            if (!target) return '';
            // 优先匹配后台“经纪人数据管理”维护的号码
            const fromDirectory = brokerDirectory.value.find(a => normalizePersonName(a.username) === target);
            if (fromDirectory && fromDirectory.phone) return String(fromDirectory.phone).trim();
            const fromDirectoryFuzzy = brokerDirectory.value.find(a => {
                const n = normalizePersonName(a.username);
                return n && (n.includes(target) || target.includes(n));
            });
            if (fromDirectoryFuzzy && fromDirectoryFuzzy.phone) return String(fromDirectoryFuzzy.phone).trim();
            // 兜底匹配账号表号码
            const matched = agents.value.find(a => normalizePersonName(a.username) === target);
            return matched && matched.phone ? String(matched.phone).trim() : '';
        };

        const ensureBrokerPhoneFromName = async (force = false) => {
            if (!force && String(form.value.broker_phone || '').trim()) return;
            const brokerName = String(form.value.broker_name || '').trim();
            if (!brokerName) return;
            if (!Array.isArray(brokerDirectory.value) || brokerDirectory.value.length === 0) {
                await loadBrokerDirectory();
            }
            if (!Array.isArray(agents.value) || agents.value.length === 0) {
                await loadAgents();
            }
            const matchedPhone = resolveBrokerPhoneByName(brokerName);
            if (matchedPhone) {
                form.value.broker_phone = matchedPhone;
                return;
            }
            // 再兜底：直接去 agent_import.php 搜索手机号并回填
            const importPhone = await resolveBrokerPhoneFromImport(brokerName);
            if (importPhone) {
                form.value.broker_phone = importPhone;
            }
        };

        const resolveBrokerPhoneFromImport = async (name) => {
            const cleanName = sanitizeBrokerName(name);
            if (!cleanName) return '';
            try {
                const url = new URL('admin/agent_import.php', window.location.origin + window.location.pathname);
                url.searchParams.set('action', 'get_agents_list');
                url.searchParams.set('keyword', cleanName);
                url.searchParams.set('page', '1');
                url.searchParams.set('pageSize', '20');
                url.searchParams.set('_t', String(Date.now()));
                const res = await fetch(url.toString(), { cache: 'no-store' });
                const data = await res.json();
                const rows = Array.isArray(data && data.data) ? data.data : [];
                if (rows.length === 0) return '';
                const target = normalizePersonName(cleanName);
                const exact = rows.find(r => normalizePersonName(r.agent_name) === target);
                if (exact && exact.agent_phone) return String(exact.agent_phone).trim();
                const fuzzy = rows.find(r => {
                    const n = normalizePersonName(r.agent_name);
                    return n && (n.includes(target) || target.includes(n));
                });
                return fuzzy && fuzzy.agent_phone ? String(fuzzy.agent_phone).trim() : '';
            } catch (e) {
                console.error('从 agent_import 搜索手机号失败:', e);
                return '';
            }
        };

        const onCompanyFocus = () => {
            // 当公司名称输入框获得焦点时的处理
            if (form.value.company_name && companyResults.value.length > 0) {
                showDropdown.value = true;
            }
        };

        const loadTeamMembers = async () => {
            try {
                const res = await fetch('?action=get_team_members');
                const data = await res.json();
                teamMembers.value = Array.isArray(data.members) ? data.members : [];
                teamFilterEnabled.value = !!data.can_filter && teamMembers.value.length > 0;
            } catch (e) {
                teamMembers.value = [];
                teamFilterEnabled.value = false;
            }
        };

        const onMemberChange = () => {
            loadAllData();
        };

        const submit = async () => {
            await ensureBrokerPhoneFromName();
            if(form.value.project_ids.length === 0) return alert('请至少选择一个楼盘');
            if(!form.value.client_phone) return alert('请填写客户手机号');
            
            // 检查是否存在重复报备
            try {
                const checkFd = new FormData();
                checkFd.append('client_phone', form.value.client_phone);
                checkFd.append('project_ids', form.value.project_ids.join(','));
                const checkRes = await fetch('?action=check_duplicate', {method:'POST', body:checkFd});
                const checkData = await checkRes.json();
                if(checkData.status === 'success' && checkData.data && checkData.data.length > 0) {
                    if(!confirm('同项目、同客户（前三后四）下已有进行中的报备，确定再提交一条新的吗？')) return;
                }
            } catch (e) {
                console.error('检查重复失败:', e);
            }
            
            const appendSubmitFields = (fd) => {
                fd.append('project_ids', form.value.project_ids.join(','));
                fd.append('company_name', form.value.company_name);
                fd.append('follower', form.value.follower);
                fd.append('broker_name', form.value.broker_name);
                fd.append('broker_phone', form.value.broker_phone);
                fd.append('broker_num', form.value.broker_num);
                fd.append('client_name', form.value.client_name);
                fd.append('client_phone', form.value.client_phone);
                fd.append('client_num', form.value.client_num);
                fd.append('visit_date', form.value.visit_date);
                fd.append('designated_sales', form.value.designated_sales);
                fd.append('remark', form.value.remark);
                fd.append('raw_input_text', smartText.value || '');
            };
            const fd = new FormData();
            appendSubmitFields(fd);
            const res = await fetch('?action=submit', { method: 'POST', body: fd });
            const d = await res.json();
            if (d.status === 'success') {
                alert(d.msg);
                loadAllData();
                tab.value = 'list';
                form.value.project_ids = [];
                form.value.company_name = '';
                form.value.follower = '';
                form.value.client_name = '';
                form.value.client_phone = '';
                form.value.designated_sales = '';
                form.value.remark = '';
                smartText.value = '';
            } else {
                alert(d.msg || '提交失败');
            }
        };

        const changeMainTab = (id) => {
            subTab.value = id;
            if(id === 1) childFilter.value = 'reception_all';
            else if(id === 2) childFilter.value = 'visit_all';
            else if(id === 3) childFilter.value = 'all';
        };

        const resetFilter = () => {
            projectFilterKeyword.value = '';
            const today = getTodayDateStr();
            search.value = { keyword: '', projectIds: [], dateStart: today, dateEnd: today };
            showFilters.value = false;
        };

        const applyFilter = () => {
            // 筛选逻辑已经在computed属性中实现，这里只需要关闭筛选面板
            showFilters.value = false;
        };

        const maskPhone = (phone) => {
            if(!phone) return '';
            return phone.replace(/(\d{3})\d{4}(\d{4})/, '$1****$2');
        };

        const toggleStage = (stage) => { if (editSubStages.value.includes(stage)) editSubStages.value = editSubStages.value.filter(s => s !== stage); else editSubStages.value.push(stage); };
        const submitUpdate = async () => { const fd = new FormData(); fd.append('id', currentItem.value.id); fd.append('sub_stages', editSubStages.value.join(',')); fd.append('voice', editVoice.value); const res = await fetch('?action=update_progress', {method:'POST', body:fd}); const d = await res.json(); if(d.status==='success') { alert('进度更新成功'); showEdit.value = false; loadAllData(); } else { alert('更新失败'); } };
        // 打开修改报备模态框
        const openEditFilingModal = (item) => {
            currentItem.value = item;
            editFilingForm.value = {
                id: item.id,
                client_name: item.client_name || '',
                client_phone: item.client_phone || '',
                company_name: item.company_name || '',
                follower: item.follower || '',
                broker_name: item.broker_name || userInfo.name,
                broker_phone: item.broker_phone || userInfo.phone,
                visit_time: item.visit_time || new Date().toISOString().slice(0, 10),
                designated_sales: item.designated_sales || '',
                remark: item.remark || ''
            };
            showEditFilingModal.value = true;
        };
        // 提交修改报备
        const submitEditFiling = async () => {
            if(!editFilingForm.value.client_name) return alert('请填写客户姓名');
            if(!editFilingForm.value.client_phone) return alert('请填写客户电话');
            
            const fd = new FormData();
            fd.append('id', editFilingForm.value.id);
            fd.append('client_name', editFilingForm.value.client_name);
            fd.append('client_phone', editFilingForm.value.client_phone);
            fd.append('company_name', editFilingForm.value.company_name);
            fd.append('follower', editFilingForm.value.follower);
            fd.append('broker_name', editFilingForm.value.broker_name);
            fd.append('broker_phone', editFilingForm.value.broker_phone);
            fd.append('visit_time', editFilingForm.value.visit_time);
            fd.append('designated_sales', editFilingForm.value.designated_sales);
            fd.append('remark', editFilingForm.value.remark);
            
            try {
                const res = await fetch('?action=update_filing', {method:'POST', body:fd});
                const d = await res.json();
                if(d.status==='success') {
                    const tip = currentItem.value && Number(currentItem.value.status) === 6
                        ? '修改并重新提交成功'
                        : '修改成功';
                    alert(tip);
                    showEditFilingModal.value = false;
                    loadAllData();
                } else {
                    alert('修改失败: ' + (d.msg || '未知错误'));
                }
            } catch (e) {
                alert('修改失败: 网络错误');
            }
        };
        
        const goToWorkbench = () => {
            window.location.href = 'staff.php';
        };
        // 复制报备资料到表单
        const copyFilingData = (item) => {
            // 复制除项目名称外的所有字段
            form.value.company_name = item.company_name || '';
            form.value.follower = item.follower || '';
            form.value.broker_name = item.broker_name || userInfo.name;
            form.value.broker_phone = item.broker_phone || userInfo.phone;
            form.value.broker_num = item.broker_num || 2;
            form.value.client_name = item.client_name || '';
            form.value.client_phone = item.client_phone || '';
            form.value.client_num = item.client_num || 2;
            form.value.visit_date = new Date().toISOString().slice(0, 10);
            form.value.designated_sales = item.designated_sales || '';
            form.value.remark = item.remark || '';
            smartText.value = item.raw_input_text || '';
            
            // 切换到报备表单页面
            tab.value = 'form';
            
            // 显示提示
            alert('报备资料已复制到表单，可修改后提交');
        };

        const clipStatusLabel = (item) => {
            const s = parseInt(item.status, 10);
            const vt = item.visit_type === null || item.visit_type === undefined || item.visit_type === '' ? null : parseInt(item.visit_type, 10);
            if (s === 1 && (vt === null || isNaN(vt) || vt !== 0)) return '待处理';
            if (s === 1 && vt === 0) return '有效报备';
            if (s >= 2 && s < 5 && vt === 1) return '有效到访';
            if (s === 5 && vt === 2) return '无效到访';
            if (s === 6) return '报备无效';
            if (s === 5 && vt === 3) return '重复到访';
            if (s === 7) return '退房';
            const names = ['待审', '有效', '到访', '下定', '成交', '无效'];
            return names[s] || ('状态' + s);
        };

        const inferClientGenderForCopy = (name) => {
            const s = String(name || '').trim();
            if (!s) {
                return '未知';
            }
            if (/(先生)$/u.test(s)) {
                return '男';
            }
            if (/(女士|小姐)$/u.test(s)) {
                return '女';
            }
            return '未知';
        };

        /** 与渠道/案场常用报备粘贴格式一致；一组多盘时每个项目一段，空行分隔 */
        const buildFilingStandardCopyText = (item, group) => {
            const project = String(item && item.project_name != null ? item.project_name : '').trim() || '-';
            const clientName = String((group && group.client_name) || (item && item.client_name) || '').trim() || '-';
            const rawPhone = String((group && group.client_phone) || (item && item.client_phone) || '').trim();
            const clientPhone = maskPhone(rawPhone) || (rawPhone || '-');
            const brokerPhone = String(item && item.broker_phone != null ? item.broker_phone : '').trim() || '-';
            const brokerName = String(item && item.broker_name != null ? item.broker_name : '').trim() || '-';
            const company = String(item && item.company_name != null ? item.company_name : '').trim() || '-';
            const gender = inferClientGenderForCopy(clientName);
            return [
                '报备项目：' + project,
                '客户姓名：' + clientName,
                '客户性别：' + gender,
                '客户手机：' + clientPhone,
                '经纪人手机：' + brokerPhone,
                '经纪人：' + brokerName,
                '经纪人公司：' + company,
            ].join('\n');
        };

        const copyGroupSummaryToClipboard = async (group) => {
            if (!group || !group.items || !group.items.length) {
                return;
            }
            const text = group.items.map((it) => buildFilingStandardCopyText(it, group)).join('\n\n');
            try {
                if (navigator.clipboard && window.isSecureContext) {
                    await navigator.clipboard.writeText(text);
                } else {
                    const ta = document.createElement('textarea');
                    ta.value = text;
                    ta.style.position = 'fixed';
                    ta.style.left = '-9999px';
                    document.body.appendChild(ta);
                    ta.focus();
                    ta.select();
                    document.execCommand('copy');
                    document.body.removeChild(ta);
                }
                alert('已复制到剪贴板');
            } catch (e) {
                alert('复制失败，请长按手动选择或检查浏览器权限');
            }
        };

        /** 原文含「房仕通」时纠正 AI 误识为「百仕通」 */
        const fixCompanyNameFromSourceText = (sourceText, companyName) => {
            let name = String(companyName || '').trim();
            if (!name || !sourceText) return name;
            if (sourceText.includes('房仕通') && (name.includes('百仕通') || name.includes('百仕'))) {
                name = name.replace(/百仕通/g, '房仕通').replace(/百仕/g, '房仕');
            }
            return name;
        };

        // 核心：AI 智能识别对接
        const smartParse = async () => {
            let txt = smartText.value; if(!txt) return alert('请输入文本');
            isParsing.value = true;
            try {
                const res = await fetch('api_smart_parse.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ text: txt }) });
                const d = await res.json();
                if (d.status === 'success') {
                    const data = d.data;
                    if (data.company_name) {
                        data.company_name = fixCompanyNameFromSourceText(txt, data.company_name);
                    }
                    if (data.company_name) {
                        if (data.company_matched) {
                            form.value.company_name = data.company_name;
                            if (data.follower != null && data.follower !== '') {
                                form.value.follower = normalizeFollowerForForm(data.follower);
                            }
                        } else {
                            const matched = await autoSelectCompanyFromKeyword(data.company_name);
                            if (!matched) form.value.company_name = data.company_name;
                        }
                    }
                    if (data.broker_name) form.value.broker_name = sanitizeBrokerName(data.broker_name);
                    if (data.broker_phone) form.value.broker_phone = data.broker_phone;
                    // AI若只识别到姓名未识别手机号，则按后台通讯录强制回填手机号
                    await ensureBrokerPhoneFromName(!!data.broker_name && !data.broker_phone);
                    if (data.broker_num) form.value.broker_num = parseInt(data.broker_num) || 1;
                    if (data.client_name) form.value.client_name = data.client_name;
                    if (data.client_phone) form.value.client_phone = normalizeMaskedPhone(data.client_phone);
                    if (data.client_num) form.value.client_num = parseInt(data.client_num) || 1;
                    if (data.visit_date) form.value.visit_date = data.visit_date;
                    if (data.designated_sales) form.value.designated_sales = data.designated_sales;
                    if (data.remark) form.value.remark = data.remark;
                    if (data.project_keywords && data.project_keywords.length > 0) {
                        const matchedIds = [];
                        data.project_keywords.forEach(kw => {
                            // 找出所有匹配的项目
                            const allMatches = projects.value.filter(p => p.name.includes(kw) || kw.includes(p.name));
                            if (allMatches.length > 0) {
                                // 按优先级排序：不带"商铺"的排在前面
                                allMatches.sort((a, b) => {
                                    const aHasShop = a.name.includes('商铺');
                                    const bHasShop = b.name.includes('商铺');
                                    if (aHasShop && !bHasShop) return 1;
                                    if (!aHasShop && bHasShop) return -1;
                                    return 0;
                                });
                                // 选择排序后的第一个项目（优先选择不带商铺的）
                                const bestMatch = allMatches[0];
                                if (bestMatch && !matchedIds.includes(bestMatch.id)) {
                                    matchedIds.push(bestMatch.id);
                                }
                            }
                        });
                        if (matchedIds.length > 0) form.value.project_ids = matchedIds;
                    }
                    alert('识别成功！请核对信息');
                } else { alert(d.msg || '识别失败'); }
            } catch (e) { alert('AI 接口连接失败'); } finally { isParsing.value = false; }
        };

        const clientIntentionLabel = (item) => {
            const v = parseInt(item && item.client_intention, 10);
            if (!v || isNaN(v)) return '未设置';
            if (v === 5) return '非常强烈';
            if (v === 4) return '强烈';
            if (v === 3) return '一般';
            if (v === 2) return '还行';
            if (v === 1) return '无意向';
            return '未设置';
        };

        const statusText = (s) => ['待审','有效','到访','下定','成交','无效'][s]||'';
        const statusClass = (s) => ['bg-gray-100 text-gray-500 border-gray-200','bg-blue-50 text-blue-600 border-blue-200','bg-yellow-50 text-yellow-600 border-yellow-200','bg-orange-50 text-orange-600 border-orange-200','bg-green-50 text-green-600 border-green-200','bg-red-50 text-red-500 border-red-200'][s];
        const commText = (s) => ['待确认','待发放','已发放'][s] || '未知';
        const commColor = (s) => ['bg-gray-100 text-gray-500 border-gray-200','bg-yellow-50 text-yellow-600 border-yellow-200','bg-green-50 text-green-600 border-green-200'][s];

        const drawChart = () => {
            if(!stats.value.chart_data) return;
            const chartDom = document.getElementById('chart');
            if(!chartDom) return;
            const myChart = echarts.init(chartDom);
            const option = {
                tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                legend: { data: ['报备', '到访', '成交'], textStyle: { fontSize: 10 }, bottom: 0 },
                grid: { left: '3%', right: '4%', bottom: '15%', top: '15%', containLabel: true },
                xAxis: { type: 'category', data: stats.value.chart_labels, axisLabel: { fontSize: 10 } },
                yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
                series: [
                    { name: '报备', type: 'bar', itemStyle: { color: '#3b82f6' }, data: stats.value.chart_data.report },
                    { name: '到访', type: 'bar', itemStyle: { color: '#22c55e' }, data: stats.value.chart_data.visit },
                    { name: '成交', type: 'bar', itemStyle: { color: '#f97316' }, data: stats.value.chart_data.deal }
                ]
            };
            option && myChart.setOption(option);
            window.addEventListener('resize', () => myChart.resize());
        };

        const parseLog = (logStr) => {
            if (!logStr) return [];
            return logStr.split('\n').filter(l => l.trim()).map(l => {
                const parts = l.split('] ');
                const body = parts.length < 2 ? l : parts.slice(1).join('] ');
                let timePart = parts[0] ? parts[0].replace('[', '').trim() : '';
                if (/案场$/.test(timePart) && !/案场·/.test(timePart)) {
                    timePart = timePart.replace(/案场$/, '案场（操作人未记录）');
                }
                if (/经纪人$/.test(timePart) && !/经纪人·/.test(timePart)) {
                    timePart = timePart.replace(/经纪人$/, '经纪人（操作人未记录）');
                }
                if (/管理员$/.test(timePart) && !/管理员·/.test(timePart)) {
                    timePart = timePart.replace(/管理员$/, '管理员（操作人未记录）');
                }
                return {
                    time: timePart,
                    title: body?.split(' (')[0] || body,
                    desc: body?.includes('(') ? body.split('(')[1].replace(')', '') : ''
                };
            }).reverse();
        };
        const showTimeline = (item) => { currentItem.value = item; showModal.value = true; };
        const showFilingInfo = (item) => { currentItem.value = item; showFilingModal.value = true; };
        const openEditModal = (item) => { currentItem.value = item; editSubStages.value = item.sub_stages ? item.sub_stages.split(',') : []; editVoice.value = ''; showEdit.value = true; };
        
        // 计算跟进时间
        const calculateFollowupTime = (item, followupCount) => {
            const itemFollowups = item.followups || [];
            let minutes = 0;
            if (followupCount === 1) {
                // 第一次跟进，计算从有效时间到现在的分钟数
                const validTime = new Date(item.created_at);
                const now = new Date();
                minutes = Math.floor((now - validTime) / (1000 * 60));
            } else {
                // 后续跟进，计算从上一次跟进到现在的分钟数
                const targetCount = followupCount - 1;
                const lastFollowup = itemFollowups.find(f => parseInt(f.followup_count) === targetCount);
                if (lastFollowup) {
                    const lastTime = new Date(lastFollowup.created_at);
                    const now = new Date();
                    minutes = Math.floor((now - lastTime) / (1000 * 60));
                }
            }
            
            // 超过24小时显示"超过 24 小时"
            if (minutes >= 24 * 60) {
                return "超过 24 小时";
            }
            
            // 转换为小时和分钟格式
            const hours = Math.floor(minutes / 60);
            const mins = minutes % 60;
            
            if (hours > 0) {
                return `${hours}小时${mins}分`;
            } else {
                return `${mins}分`;
            }
        };
        
        // 加载跟进记录
        const loadFollowups = async (filingId) => {
            followupLoading.value = true;
            try {
                const res = await fetch('?action=get_followups&filing_id=' + filingId);
                const data = await res.json();
                if (data.status === 'success') {
                    followups.value = data.data;
                }
            } catch (e) {
                console.error('加载跟进记录失败:', e);
            } finally {
                followupLoading.value = false;
            }
        };
        
        // 跟进概述选项
        const followupSummaries = ref({
            1: ['飞机客', '在路上', '看二手房', '失联', '已到项目'],
            2: ['看完二手走了', '已到项目', '失联'],
            3: ['持续跟进', '要价低', '不买了']
        });
        
        // 加载跟进概述选项
        const loadFollowupSummaries = async () => {
            try {
                const response = await fetch('admin/admin_followup_summaries.php?action=get_all&_t=' + Date.now(), {
                    cache: 'no-store'
                });
                const data = await response.json();
                if (data && typeof data === 'object') {
                    // 处理API返回的数据，只提取summary字段
                    const processedData = { 1: [], 2: [], 3: [] };
                    for (let count = 1; count <= 3; count++) {
                        const rows = Array.isArray(data[count]) ? data[count] : [];
                        processedData[count] = rows
                            .map(item => (item && item.summary ? String(item.summary).trim() : ''))
                            .filter(Boolean);
                    }
                    followupSummaries.value = processedData;
                }
            } catch (error) {
                console.error('加载跟进概述失败:', error);
            }
        };
        
        // 跟进概述
        const followupSummary = ref('');
        
        // 打开跟进模态框
        const openFollowupModal = (item, followupCount) => {
            currentFollowupItem.value = item;
            currentFollowupCount.value = followupCount;
            followupContent.value = '';
            followupSummary.value = '';
            showFollowupModal.value = true;
        };
        
        // 提交跟进记录
        const submitFollowup = async () => {
            if (!followupContent.value.trim()) {
                return alert('请填写跟进内容');
            }
            
            const fd = new FormData();
            fd.append('filing_id', currentFollowupItem.value.id);
            fd.append('followup_count', currentFollowupCount.value);
            fd.append('content', followupContent.value);
            fd.append('summary', followupSummary.value);
            
            try {
                const res = await fetch('?action=submit_followup', {method: 'POST', body: fd});
                const data = await res.json();
                if (data.status === 'success') {
                    alert('跟进记录提交成功');
                    showFollowupModal.value = false;
                    // 重新加载数据，确保跟进记录更新
                    setTimeout(() => {
                        loadAllData();
                    }, 500);
                } else {
                    alert('提交失败: ' + (data.msg || '未知错误'));
                }
            } catch (e) {
                alert('提交失败: 网络错误');
            }
        };
        
        // 获取下一次跟进次数
        const getNextFollowupCount = (item) => {
            const itemFollowups = item.followups || [];
            const existingCounts = itemFollowups.map(f => parseInt(f.followup_count));
            for (let i = 1; i <= 3; i++) {
                if (!existingCounts.includes(i)) {
                    return i;
                }
            }
            return null;
        };

        watch(tab, (v) => {
            if(v==='dash') { 
                // 从其他页切到数据页时，先等DOM渲染完成再绘图，避免图表容器尚未挂载
                nextTick(() => {
                    loadStats();
                    if (stats.value.chart_data && stats.value.chart_data.report) drawChart();
                });
            } else if(v==='list' && !isSearchingHistory.value) {
                loadAllData();
            }
        });
        onMounted(() => {
            childFilter.value = 'reception_all';
            loadProjects();
            loadTeamMembers();
            loadAllData();
            loadAgents();
            loadBrokerDirectory();
            loadFollowupSummaries();
            
            // 检查是否有复制的报备数据
            const copyData = localStorage.getItem('copyFilingData');
            if (copyData) {
                try {
                    const item = JSON.parse(copyData);
                    // 复制数据到表单
                    form.value.company_name = item.company_name || '';
                    form.value.follower = item.follower || '';
                    form.value.broker_name = item.broker_name || userInfo.name;
                    form.value.broker_phone = item.broker_phone || userInfo.phone;
                    form.value.broker_num = item.broker_num || 2;
                    form.value.client_name = item.client_name || '';
                    form.value.client_phone = item.client_phone || '';
                    form.value.client_num = item.client_num || 2;
                    form.value.visit_date = new Date().toISOString().slice(0, 10);
                    form.value.designated_sales = item.designated_sales || '';
                    form.value.remark = item.remark || '';
                    smartText.value = item.raw_input_text || '';
                    
                    // 切换到报备表单页面
                    tab.value = 'form';
                    
                    // 显示提示
                    alert('报备资料已复制到表单，可修改后提交');
                } catch (e) {
                    console.error('解析复制数据失败:', e);
                } finally {
                    // 清除localStorage中的数据
                    localStorage.removeItem('copyFilingData');
                }
            }
        });

        return {
            tab, list, projects, form, smartText, companyResults, showDropdown, showModal, showFilingModal, showEditFilingModal, currentItem, editFilingForm, search, filteredList, stats,
            projectFilterKeyword, filteredProjectsForFilter, isProjectFilterChecked, toggleProjectFilter, clearProjectFilter,
            statsRange, statsCustomStart, statsCustomEnd, changeStatsRange, applyStatsCustomRange,
            subTab, tabs, counts, receptionChildCounts, visitChildCounts, depositChildCounts, showFilters, resetFilter, applyFilter, groupedList, childFilter, showEdit, editSubStages, editVoice, isParsing, isSearchingHistory, agents,
            searchCompany, selectCompany, submit, smartParse, showTimeline, showFilingInfo, parseLog, loadAllData, onCompanyFocus, loadAgents, formatPhone,
            clientIntentionLabel, statusText, statusClass, commText, commColor, showAllProjects, maskPhone, changeMainTab, openEditModal, toggleStage, submitUpdate,
            copyFilingData, copyGroupSummaryToClipboard, searchHistory, openEditFilingModal, submitEditFiling, goToWorkbench, userInfo,
            teamMembers, selectedMemberId, teamFilterEnabled, onMemberChange,
            showPwdModal, pwdOld, pwdNew, pwdConfirm, pwdSubmitting, openPwdModal, submitPwdChange,
            // 跟进相关
            showFollowupModal, currentFollowupItem, followupContent, followupSummary, followupSummaries, currentFollowupCount, followups, followupLoading, loadFollowupSummaries,
            calculateFollowupTime, openFollowupModal, submitFollowup, getNextFollowupCount, loadFollowups
        }
    }
}).mount('#app');
</script>
</body>
</html>