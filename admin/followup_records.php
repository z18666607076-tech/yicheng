<?php
// followup_records.php - 跟进记录管理页面
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 0. 登录鉴权（与其它后台页一致，须已登录 admin）===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}
$CURRENT_USER = [
    'id' => (int)$_SESSION['admin_id'],
    'name' => $_SESSION['admin_name'] ?? '管理员',
    'phone' => $_SESSION['admin_phone'] ?? '',
    'company' => $_SESSION['agent_company'] ?? '',
    'role' => 'admin',
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

$action = $_GET['action'] ?? 'view';

function extract_sales_remarks_from_status_log($statusLog) {
    $statusLog = (string)$statusLog;
    if ($statusLog === '') return '';
    $result = [];
    $lines = preg_split("/\r\n|\n|\r/", $statusLog);
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (mb_strpos($line, '备注:') === false && mb_strpos($line, '备注：') === false) continue;
        if (!preg_match('/备注[:：]\s*(.+)$/u', $line, $m)) continue;
        $remark = trim((string)$m[1]);
        if ($remark === '') continue;
        if (mb_strpos($remark, '->') !== false) {
            $parts = explode('->', $remark);
            $remark = trim((string)end($parts));
        }
        if ($remark === '' || $remark === '空' || $remark === '-') continue;
        $result[] = $remark;
    }
    $result = array_values(array_unique($result));
    return implode(' | ', $result);
}

function extract_staff_action_time_from_log($statusLog, array $keywords) {
    $statusLog = (string)$statusLog;
    if ($statusLog === '' || empty($keywords)) return '';
    $lines = preg_split("/\r\n|\n|\r/", $statusLog);
    $matchedTime = '';
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        $hit = false;
        foreach ($keywords as $kw) {
            if ($kw !== '' && mb_strpos($line, $kw) !== false) {
                $hit = true;
                break;
            }
        }
        if (!$hit) continue;
        if (preg_match('/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s+\[案场/u', $line, $m)) {
            $matchedTime = trim((string)$m[1]);
        }
    }
    return $matchedTime;
}

function staff_filing_info_text($status, $visitType) {
    $s = intval($status);
    $vt = ($visitType === null || $visitType === '' ? null : intval($visitType));
    if ($s === 0) return '待处理';
    if ($s === 1) return ($vt === 0 ? '有效报备' : '待处理');
    if ($s === 2) return '有效到访';
    if ($s === 3) return '已下定';
    if ($s === 4) return '已成交';
    if ($s === 5) {
        if ($vt === 2) return '无效到访';
        if ($vt === 3) return '重复到访';
        return '无效';
    }
    if ($s === 6) return '报备无效';
    if ($s === 7) return '退房';
    return '未知';
}

function mask_client_phone_for_export($phone) {
    $text = trim((string)$phone);
    if ($text === '') return '-';
    $digits = preg_replace('/\D+/', '', $text);
    if (strlen($digits) >= 7) {
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }
    return $text;
}

/**
 * 搜索词为列表脱敏样式（如 137****6728）时，生成匹配库内完整号码的 LIKE 模式；否则返回 null。
 */
function followup_sql_like_for_mask_style_phone_search($keyword) {
    $k = trim((string)$keyword);
    if ($k === '' || strpos($k, '*') === false) {
        return null;
    }
    $pieces = preg_split('/\*+/', $k, -1, PREG_SPLIT_NO_EMPTY);
    $groups = [];
    foreach ($pieces as $piece) {
        $d = preg_replace('/\D/', '', $piece);
        if ($d !== '') {
            $groups[] = $d;
        }
    }
    if ($groups === []) {
        return null;
    }
    if (count($groups) >= 2) {
        return implode('%', $groups);
    }
    $g = $groups[0];
    if (preg_match('/^\*+/', $k)) {
        return preg_match('/\*+$/', $k) ? ('%' . $g . '%') : ('%' . $g);
    }
    if (preg_match('/\*+$/', $k)) {
        return $g . '%';
    }
    return null;
}

function get_followup_records($pdo, $filters) {
    $where = [];
    $params = [];
    $exists = "EXISTS (SELECT 1 FROM filing_followups ff WHERE ff.filing_id = f.id";

    if (!empty($filters['date_start'])) {
        $exists .= " AND DATE(ff.created_at) >= ?";
        $params[] = $filters['date_start'];
    }
    if (!empty($filters['date_end'])) {
        $exists .= " AND DATE(ff.created_at) <= ?";
        $params[] = $filters['date_end'];
    }
    if (!empty($filters['summary'])) {
        $exists .= " AND ff.summary LIKE ?";
        $params[] = '%' . $filters['summary'] . '%';
    }
    if (!empty($filters['summary_list']) && is_array($filters['summary_list'])) {
        $holders = implode(',', array_fill(0, count($filters['summary_list']), '?'));
        $exists .= " AND ff.summary IN ($holders)";
        foreach ($filters['summary_list'] as $summaryValue) {
            $params[] = $summaryValue;
        }
    }
    $exists .= ")";
    $hasFollowupSummaryFilter = (!empty($filters['summary'])
        || (!empty($filters['summary_list']) && is_array($filters['summary_list']) && count(array_filter($filters['summary_list'])) > 0));
    $dealOrSql = '';
    $dealOrParams = [];
    if (!empty($filters['date_start'])) {
        $dealOrSql .= ' AND DATE(f.created_at) >= ?';
        $dealOrParams[] = $filters['date_start'];
    }
    if (!empty($filters['date_end'])) {
        $dealOrSql .= ' AND DATE(f.created_at) <= ?';
        $dealOrParams[] = $filters['date_end'];
    }
    // 案场工作台「来访客户 → 有效到访」为 status=2；多数尚未产生 filing_followups。
    // 原逻辑仅 EXISTS(跟进) 或已成交(4)，会把仅确认到访的报备漏掉。此处用到访日（无则用报备日）与日期筛选对齐 staff.php 来访 Tab。
    $visitBenchSql = '';
    $visitBenchParams = [];
    if (!empty($filters['date_start'])) {
        $visitBenchSql .= ' AND DATE(COALESCE(f.visit_time, f.created_at)) >= ?';
        $visitBenchParams[] = $filters['date_start'];
    }
    if (!empty($filters['date_end'])) {
        $visitBenchSql .= ' AND DATE(COALESCE(f.visit_time, f.created_at)) <= ?';
        $visitBenchParams[] = $filters['date_end'];
    }
    if ($hasFollowupSummaryFilter) {
        $where[] = $exists;
    } else {
        $where[] = '(' . $exists . ' OR (f.status = 4' . $dealOrSql . ') OR (f.status = 2' . $visitBenchSql . '))';
        $params = array_merge($params, $dealOrParams, $visitBenchParams);
    }

    if (!empty($filters['project_id'])) {
        $where[] = "f.project_id = ?";
        $params[] = $filters['project_id'];
    }
    if (!empty($filters['filing_info'])) {
        if ($filters['filing_info'] === 'valid_report') {
            $where[] = "f.status = 1 AND f.visit_type = 0";
        } elseif ($filters['filing_info'] === 'invalid_report') {
            $where[] = "f.status = 6";
        }
    }
    if (!empty($filters['visit_info'])) {
        if ($filters['visit_info'] === 'valid_visit') {
            // 与案场「有效到访」口径一致：含已下定(3)、已成交(4)，不能仅用 status=2 否则成交客户被筛掉
            $where[] = "((f.status >= 2 AND f.status < 5 AND (f.visit_type <=> 1)) OR (f.status = 4))";
        } elseif ($filters['visit_info'] === 'invalid_visit') {
            $where[] = "f.status = 5 AND f.visit_type = 2";
        } elseif ($filters['visit_info'] === 'repeat_visit') {
            $where[] = "f.status = 5 AND f.visit_type = 3";
        }
    }
    if (!empty($filters['deal_info'])) {
        if ($filters['deal_info'] === 'dealed') {
            $where[] = "f.status = 4";
        } elseif ($filters['deal_info'] === 'undeal') {
            $where[] = "f.status <> 4";
        }
    }
    if (!empty($filters['channel_name'])) {
        $where[] = "COALESCE(f.follower,'') LIKE ?";
        $params[] = '%' . $filters['channel_name'] . '%';
    }
    if (!empty($filters['keyword'])) {
        $kw = '%' . $filters['keyword'] . '%';
        $kwClause = "(f.client_name LIKE ? OR f.client_phone LIKE ? OR p.name LIKE ? OR COALESCE(NULLIF(f.broker_name,''), a.username) LIKE ? OR COALESCE(NULLIF(f.broker_phone,''), a.phone) LIKE ? OR COALESCE(f.follower,'') LIKE ? OR f.company_name LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(c.store_name,'') LIKE ?)";
        $phoneMaskLike = followup_sql_like_for_mask_style_phone_search($filters['keyword']);
        if ($phoneMaskLike !== null) {
            $where[] = '(' . $kwClause . " OR f.client_phone LIKE ? OR COALESCE(NULLIF(f.broker_phone,''), a.phone) LIKE ?)";
            for ($i = 0; $i < 9; $i++) {
                $params[] = $kw;
            }
            $params[] = $phoneMaskLike;
            $params[] = $phoneMaskLike;
        } else {
            $where[] = $kwClause;
            for ($i = 0; $i < 9; $i++) {
                $params[] = $kw;
            }
        }
    }
    if (!empty($filters['company_name'])) {
        $where[] = "(f.company_name LIKE ? OR COALESCE(c.name,'') LIKE ? OR COALESCE(c.store_name,'') LIKE ?)";
        $cn = '%' . $filters['company_name'] . '%';
        $params[] = $cn;
        $params[] = $cn;
        $params[] = $cn;
    }

    $sql = "SELECT f.*, p.name as project_name,
            COALESCE(NULLIF(f.broker_name,''), a.username) as agent_name,
            COALESCE(NULLIF(f.broker_phone,''), a.phone) as agent_phone,
            c.name as company_full_name, c.store_name, c.franchise_brand, c.region_main,
            COALESCE(f.follower, '') as channel_follower
            FROM filings f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN agents a ON f.agent_id = a.id
            LEFT JOIN companies c ON f.company_name = c.name
            WHERE " . implode(' AND ', $where) . "
            ORDER BY f.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $filings = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($filings as &$filing) {
        $followupStmt = $pdo->prepare("SELECT * FROM filing_followups WHERE filing_id = ? ORDER BY followup_count ASC");
        $followupStmt->execute([$filing['id']]);
        $filing['followups'] = $followupStmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $filings;
}

// [API] 获取跟进记录列表
if ($action == 'get_followup_list') {
    header('Content-Type: application/json');
    $filters = [
        'date_start' => $_GET['date_start'] ?? '',
        'date_end' => $_GET['date_end'] ?? '',
        'channel_name' => $_GET['channel_name'] ?? '',
        'project_id' => $_GET['project_id'] ?? '',
        'summary' => $_GET['summary'] ?? '',
        'summary_list' => array_values(array_filter(array_map('trim', explode(',', (string)($_GET['summary_list'] ?? ''))))),
        'keyword' => $_GET['keyword'] ?? '',
        'company_name' => $_GET['company_name'] ?? '',
        'filing_info' => $_GET['filing_info'] ?? '',
        'visit_info' => $_GET['visit_info'] ?? '',
        'deal_info' => $_GET['deal_info'] ?? ''
    ];
    echo json_encode(get_followup_records($pdo, $filters));
    exit;
}

// [API] 获取项目下拉
if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT id, name FROM projects WHERE is_deleted=0 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

// [API] 导出（按当前筛选）
if ($action == 'export_excel') {
    $filters = [
        'date_start' => $_GET['date_start'] ?? '',
        'date_end' => $_GET['date_end'] ?? '',
        'channel_name' => $_GET['channel_name'] ?? '',
        'project_id' => $_GET['project_id'] ?? '',
        'summary' => $_GET['summary'] ?? '',
        'summary_list' => array_values(array_filter(array_map('trim', explode(',', (string)($_GET['summary_list'] ?? ''))))),
        'keyword' => $_GET['keyword'] ?? '',
        'company_name' => $_GET['company_name'] ?? '',
        'filing_info' => $_GET['filing_info'] ?? '',
        'visit_info' => $_GET['visit_info'] ?? '',
        'deal_info' => $_GET['deal_info'] ?? ''
    ];
    $filings = get_followup_records($pdo, $filters);
    $visibleColumnsRaw = trim((string)($_GET['visible_columns'] ?? ''));
    $selectedColumns = [];
    if ($visibleColumnsRaw !== '') {
        $selectedColumns = array_values(array_filter(array_map('trim', explode(',', $visibleColumnsRaw))));
    }
    $allExportColumns = [
        'project_name' => '报备项目',
        'filing_time' => '报备时间',
        'visit_time' => '到访时间',
        'client_name' => '客户姓名',
        'client_phone' => '客户号码',
        'company_name' => '报备公司',
        'broker_name' => '经纪人',
        'broker_phone' => '经纪人号码',
        'region_main' => '板块',
        'filing_info' => '报备信息',
        'visit_info' => '带看信息',
        'deal_info' => '成交信息',
        'remark' => '备注',
        'sales_remark' => '案场备注',
        'channel_follower' => '渠道',
        'salesperson' => '现场销售',
        'client_intention' => '客户意愿',
        'franchise_brand' => '加盟',
        'followup_1' => '第1次跟进记录',
        'followup_2' => '第2次跟进记录',
        'followup_3' => '第3次跟进记录'
    ];
    if (empty($selectedColumns)) {
        $selectedColumns = array_keys($allExportColumns);
    } else {
        $selectedColumns = array_values(array_filter($selectedColumns, function ($k) use ($allExportColumns) {
            return array_key_exists($k, $allExportColumns);
        }));
        if (empty($selectedColumns)) $selectedColumns = array_keys($allExportColumns);
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment;filename="跟进记录_' . date('YmdHis') . '.csv"');
    header('Cache-Control: max-age=0');
    $output = fopen('php://output', 'w');
    fwrite($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    $clientIntentionText = function ($v) {
        $iv = intval($v);
        if ($iv === 4) return '强烈';
        if ($iv === 3) return '一般';
        if ($iv === 2) return '还行';
        if ($iv === 1) return '无意向';
        return '未设置';
    };

    $headers = [];
    foreach ($selectedColumns as $colKey) {
        $headers[] = $allExportColumns[$colKey];
    }
    fputcsv($output, $headers);

    foreach ($filings as $filing) {
        $f1 = ['content' => '', 'created_at' => '', 'summary' => ''];
        $f2 = ['content' => '', 'created_at' => '', 'summary' => ''];
        $f3 = ['content' => '', 'created_at' => '', 'summary' => ''];
        foreach ($filing['followups'] as $f) {
            if ((int)$f['followup_count'] === 1) $f1 = $f;
            if ((int)$f['followup_count'] === 2) $f2 = $f;
            if ((int)$f['followup_count'] === 3) $f3 = $f;
        }
        $statusVal = intval($filing['status'] ?? 0);
        $visitTypeVal = intval($filing['visit_type'] ?? -1);
        $filingInfo = staff_filing_info_text($statusVal, $visitTypeVal);
        $filingTime = $filing['created_at'] ?? '-';
        if ($statusVal === 6) {
            $filingTime = extract_staff_action_time_from_log($filing['status_log'] ?? '', ['标记为报备无效']);
            if ($filingTime === '') $filingTime = $filing['created_at'] ?? '-';
        } elseif ($statusVal >= 1) {
            $filingTime = extract_staff_action_time_from_log($filing['status_log'] ?? '', ['确认有效报备']);
            if ($filingTime === '') $filingTime = $filing['created_at'] ?? '-';
        }

        $visitInfo = '-';
        $visitTime = '-';
        if ($statusVal === 2) {
            $visitInfo = '有效到访';
            $visitTime = extract_staff_action_time_from_log($filing['status_log'] ?? '', ['确认有效到访']);
            if ($visitTime === '') $visitTime = $filing['visit_time'] ?? '-';
        } elseif ($statusVal === 5 && $visitTypeVal === 2) {
            $visitInfo = '无效到访';
            $visitTime = extract_staff_action_time_from_log($filing['status_log'] ?? '', ['标记为无效到访']);
            if ($visitTime === '') $visitTime = $filing['visit_time'] ?? '-';
        } elseif ($statusVal === 5 && $visitTypeVal === 3) {
            $visitInfo = '重复到访';
            $visitTime = extract_staff_action_time_from_log($filing['status_log'] ?? '', ['标记为重复到访']);
            if ($visitTime === '') $visitTime = $filing['visit_time'] ?? '-';
        }
        $dealInfo = '未成交';
        if ($statusVal === 4) {
            $room = trim((string)($filing['subscribed_room_number'] ?? $filing['room_number'] ?? ''));
            $price = floatval($filing['deal_price'] ?? 0);
            if ($room !== '' && $price > 0) $dealInfo = $room . ' / ¥' . number_format($price, 0, '.', ',');
            elseif ($room !== '') $dealInfo = $room;
            elseif ($price > 0) $dealInfo = '¥' . number_format($price, 0, '.', ',');
            else $dealInfo = '已成交';
        }
        $followupText = function ($followup) {
            $summary = trim((string)($followup['summary'] ?? ''));
            $content = trim((string)($followup['content'] ?? ''));
            if ($summary !== '' && $content !== '') return $summary . '：' . $content;
            if ($summary !== '') return $summary;
            if ($content !== '') return $content;
            return '-';
        };
        $rowMap = [
            'project_name' => $filing['project_name'] ?? '-',
            'visit_time' => $visitTime,
            'filing_time' => $filingTime,
            'client_name' => $filing['client_name'] ?? '-',
            'client_phone' => mask_client_phone_for_export($filing['client_phone'] ?? ''),
            'company_name' => ($filing['company_name'] ?? $filing['company_full_name'] ?? '-'),
            'broker_name' => ($filing['broker_name'] ?: $filing['agent_name'] ?: '-'),
            'broker_phone' => ($filing['broker_phone'] ?: $filing['agent_phone'] ?: '-'),
            'region_main' => $filing['region_main'] ?? '-',
            'filing_info' => $filingInfo,
            'visit_info' => $visitInfo,
            'deal_info' => $dealInfo,
            'remark' => $filing['remark'] ?? '-',
            'sales_remark' => extract_sales_remarks_from_status_log($filing['status_log'] ?? ''),
            'channel_follower' => $filing['channel_follower'] ?? '-',
            'salesperson' => $filing['salesperson'] ?? '-',
            'client_intention' => $clientIntentionText($filing['client_intention'] ?? 0),
            'franchise_brand' => $filing['franchise_brand'] ?? '-',
            'followup_1' => $followupText($f1),
            'followup_2' => $followupText($f2),
            'followup_3' => $followupText($f3)
        ];
        $row = [];
        foreach ($selectedColumns as $colKey) {
            $row[] = $rowMap[$colKey] ?? '-';
        }
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// [API] 概括设置 - 列表
if ($action == 'get_summary_options') {
    header('Content-Type: application/json');
    $keyword = trim($_GET['keyword'] ?? '');
    $count = intval($_GET['followup_count'] ?? 0);
    $where = "WHERE 1=1";
    $params = [];
    if ($count >= 1 && $count <= 3) {
        $where .= " AND followup_count = ?";
        $params[] = $count;
    }
    if ($keyword !== '') {
        $where .= " AND summary LIKE ?";
        $params[] = '%' . $keyword . '%';
    }
    $stmt = $pdo->prepare("SELECT * FROM followup_summaries $where ORDER BY followup_count ASC, sort_order ASC, id DESC");
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// [API] 概括设置 - 新增
if ($action == 'add_summary_option') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $count = intval($d['followup_count'] ?? 0);
    $summary = trim($d['summary'] ?? '');
    $sort = intval($d['sort_order'] ?? 0);
    if ($count < 1 || $count > 3 || $summary === '') { echo json_encode(['status' => 'error', 'msg' => '参数错误']); exit; }
    try {
        $stmt = $pdo->prepare("INSERT INTO followup_summaries (followup_count, summary, sort_order) VALUES (?, ?, ?)");
        $stmt->execute([$count, $summary, $sort]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// [API] 概括设置 - 修改
if ($action == 'update_summary_option') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = intval($d['id'] ?? 0);
    $count = intval($d['followup_count'] ?? 0);
    $summary = trim($d['summary'] ?? '');
    $sort = intval($d['sort_order'] ?? 0);
    if ($id <= 0 || $count < 1 || $count > 3 || $summary === '') { echo json_encode(['status' => 'error', 'msg' => '参数错误']); exit; }
    try {
        $stmt = $pdo->prepare("UPDATE followup_summaries SET followup_count=?, summary=?, sort_order=? WHERE id=?");
        $stmt->execute([$count, $summary, $sort, $id]);
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// [API] 概括设置 - 删除
if ($action == 'delete_summary_option') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = intval($d['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['status' => 'error', 'msg' => '参数错误']); exit; }
    $stmt = $pdo->prepare("DELETE FROM followup_summaries WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
    exit;
}

// [API] 跟进记录 - 新增/修改
if ($action == 'save_followup_record') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = intval($d['id'] ?? 0);
    $filingId = intval($d['filing_id'] ?? 0);
    $followupCount = intval($d['followup_count'] ?? 0);
    $content = trim($d['content'] ?? '');
    $summary = trim($d['summary'] ?? '');

    if ($filingId <= 0 || $followupCount < 1 || $followupCount > 3 || $content === '') {
        echo json_encode(['status' => 'error', 'msg' => '参数错误']);
        exit;
    }

    try {
        if ($id > 0) {
            $stmt = $pdo->prepare("UPDATE filing_followups SET content=?, summary=?, followup_count=? WHERE id=?");
            $stmt->execute([$content, $summary, $followupCount, $id]);
        } else {
            $agentStmt = $pdo->prepare("SELECT agent_id FROM filings WHERE id=?");
            $agentStmt->execute([$filingId]);
            $agentId = intval($agentStmt->fetchColumn() ?: 0);

            $existStmt = $pdo->prepare("SELECT id FROM filing_followups WHERE filing_id=? AND followup_count=? LIMIT 1");
            $existStmt->execute([$filingId, $followupCount]);
            $existId = intval($existStmt->fetchColumn() ?: 0);
            if ($existId > 0) {
                $stmt = $pdo->prepare("UPDATE filing_followups SET content=?, summary=? WHERE id=?");
                $stmt->execute([$content, $summary, $existId]);
            } else {
                $stmt = $pdo->prepare("INSERT INTO filing_followups (filing_id, followup_count, content, summary, agent_id) VALUES (?,?,?,?,?)");
                $stmt->execute([$filingId, $followupCount, $content, $summary, $agentId]);
            }
        }
        echo json_encode(['status' => 'success']);
    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// [API] 跟进记录 - 删除
if ($action == 'delete_followup_record') {
    header('Content-Type: application/json');
    $d = json_decode(file_get_contents('php://input'), true) ?: $_POST;
    $id = intval($d['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'msg' => '参数错误']);
        exit;
    }
    $stmt = $pdo->prepare("DELETE FROM filing_followups WHERE id=?");
    $stmt->execute([$id]);
    echo json_encode(['status' => 'success']);
    exit;
}

$view = $_GET['v'] ?? 'followup_records';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>跟进记录管理</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Vue 3 -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Noto+Sans+SC:wght@300;400;500;700&display=swap');
        body { font-family: 'Noto Sans SC', sans-serif; }
        .card-shadow { box-shadow: 0 4px 20px rgba(0,0,0,0.05); }
        .nav-item:hover { background-color: rgba(59, 130, 246, 0.1); }
        .nav-item.active { background-color: #2563eb; color: white; }
        .nav-item.active i { color: white; }
        .bg-gray-50 { background-color: #f9fafb; }
        .border-gray-200 { border-color: #e5e7eb; }
        .text-gray-600 { color: #4b5563; }
        .text-gray-500 { color: #6b7280; }
        .text-gray-400 { color: #9ca3af; }
        .text-blue-600 { color: #2563eb; }
        .bg-blue-50 { background-color: #eff6ff; }
        .border-blue-200 { border-color: #bfdbfe; }
        .bg-green-50 { background-color: #ecfdf5; }
        .text-green-600 { color: #10b981; }
        .bg-orange-50 { background-color: #fff7ed; }
        .text-orange-600 { color: #f97316; }
        .bg-red-50 { background-color: #fef2f2; }
        .text-red-500 { color: #ef4444; }
        .bg-gray-100 { background-color: #f3f4f6; }
        .modal-overlay { position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); display: flex; align-items: center; justify-content: center; z-index: 1000; }
        .modal-content { background: white; border-radius: 16px; width: 90%; max-width: 500px; max-height: 80vh; overflow-y: auto; position: relative; padding: 24px; box-shadow: 0 20px 25px -5px rgba(0,0,0,0.1), 0 10px 10px -5px rgba(0,0,0,0.04); }
        .close-btn { position: absolute; top: 16px; right: 20px; font-size: 24px; cursor: pointer; color: #9ca3af; }
        .close-btn:hover { color: #4b5563; }
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
            <div class="flex items-center gap-4">
                <button @click="openSummaryModal()" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-sliders-h"></i> 概括设置
                </button>
                <button @click="exportExcel" class="bg-green-600 text-white px-4 py-2 rounded-lg text-sm font-bold flex items-center gap-2">
                    <i class="fas fa-file-excel"></i> 导出Excel
                </button>
                <div class="flex items-center gap-2"><div class="w-8 h-8 bg-blue-100 rounded-full flex items-center justify-center text-blue-600 font-bold">A</div><span class="text-sm font-bold">{{ userInfo.name }}</span></div>
            </div>
        </header>

        <div class="flex-1 overflow-auto p-8">
                <!-- 搜索和筛选 -->
                <div ref="filterBarRef" class="bg-white rounded-2xl p-4 shadow-sm mb-4 relative">
                    <div class="flex flex-wrap gap-3 items-end">
                        <div class="flex-1 min-w-[220px]">
                            <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-wide">综合搜索</label>
                            <input v-model="search.keyword" type="text" placeholder="客户、电话、项目、经纪人、报备公司等" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @keyup.enter="searchRecords">
                        </div>
                        <div class="flex-1 min-w-[200px]">
                            <label class="block text-[10px] font-bold text-gray-400 mb-1 uppercase tracking-wide">报备公司</label>
                            <input v-model="search.company_name" type="text" placeholder="公司全称 / 商户简称" class="w-full px-4 py-2 border border-gray-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" @keyup.enter="searchRecords">
                        </div>
                        <input v-model="search.date_start" type="date" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <input v-model="search.date_end" type="date" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                        <div class="flex items-center gap-1 bg-gray-50 border border-gray-200 rounded-lg p-1">
                            <button
                                @click="applyQuickDate('today')"
                                class="px-2 py-1 rounded text-xs font-bold"
                                :class="quickDatePreset==='today' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-white'"
                            >当天</button>
                            <button
                                @click="applyQuickDate('week')"
                                class="px-2 py-1 rounded text-xs font-bold"
                                :class="quickDatePreset==='week' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-white'"
                            >当周</button>
                            <button
                                @click="applyQuickDate('month')"
                                class="px-2 py-1 rounded text-xs font-bold"
                                :class="quickDatePreset==='month' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-white'"
                            >当月</button>
                            <button
                                @click="applyQuickDate('all')"
                                class="px-2 py-1 rounded text-xs font-bold"
                                :class="quickDatePreset==='all' ? 'bg-blue-600 text-white' : 'text-gray-600 hover:bg-white'"
                            >全部</button>
                        </div>
                        <input v-model="search.channel_name" type="text" placeholder="渠道姓名" class="px-3 py-2 border border-gray-200 rounded-lg text-sm w-36">
                        <select v-model="search.project_id" class="px-3 py-2 border border-gray-200 rounded-lg text-sm w-44">
                            <option value="">全部项目</option>
                            <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                        </select>
                        <select v-model="search.filing_info" class="px-3 py-2 border border-gray-200 rounded-lg text-sm w-32">
                            <option value="">报备信息</option>
                            <option value="valid_report">有效报备</option>
                            <option value="invalid_report">报备无效</option>
                        </select>
                        <select v-model="search.visit_info" class="px-3 py-2 border border-gray-200 rounded-lg text-sm w-32">
                            <option value="">带看信息</option>
                            <option value="valid_visit">有效到访</option>
                            <option value="invalid_visit">无效到访</option>
                            <option value="repeat_visit">重复到访</option>
                        </select>
                        <button
                            @click="toggleValidVisitOnly"
                            class="px-3 py-2 rounded-lg text-sm font-bold border"
                            :class="onlyValidVisit ? 'bg-green-50 text-green-700 border-green-200' : 'bg-white text-gray-600 border-gray-200'"
                        >
                            只看有效到访
                        </button>
                        <select v-model="search.deal_info" class="px-3 py-2 border border-gray-200 rounded-lg text-sm w-32">
                            <option value="">成交信息</option>
                            <option value="undeal">未成交</option>
                            <option value="dealed">已成交</option>
                        </select>
                        <div class="relative">
                            <button @click.stop="toggleSummaryFilterPanel" class="bg-white border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-bold min-w-[120px] text-left">
                                概括筛选<span v-if="selectedSummaryFilters.length">（{{ selectedSummaryFilters.length }}）</span>
                            </button>
                            <div v-if="showSummaryFilterPanel" class="absolute left-0 top-full mt-2 z-20 w-[360px] max-w-[90vw] bg-white border border-gray-200 rounded-xl shadow-lg p-3">
                                <div class="text-xs font-bold text-gray-500 mb-2">概括多选筛选</div>
                                <input v-model="summaryFilterKeyword" @input="loadFilterSummaryOptions" type="text" placeholder="筛选关键词" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm mb-2">
                                <div class="max-h-56 overflow-y-auto border border-gray-100 rounded-lg">
                                    <label v-for="item in filterSummaryOptions" :key="item.id" class="px-3 py-2 text-sm border-b border-gray-100 flex items-center gap-2 cursor-pointer hover:bg-gray-50">
                                        <input type="checkbox" :checked="selectedSummaryFilters.includes(item.summary)" @change="toggleSummaryFilter(item.summary)">
                                        <span>第{{ item.followup_count }}次 · {{ item.summary }}</span>
                                    </label>
                                    <div v-if="filterSummaryOptions.length===0" class="px-3 py-4 text-xs text-gray-400 text-center">暂无可选概括</div>
                                </div>
                                <div class="flex justify-between items-center mt-3">
                                    <button @click="clearSummaryFilter" class="text-xs bg-gray-100 text-gray-600 px-3 py-1.5 rounded-lg">清空</button>
                                    <div class="flex items-center gap-2">
                                        <button @click="showSummaryFilterPanel=false" class="text-xs bg-gray-100 text-gray-600 px-3 py-1.5 rounded-lg">收起</button>
                                        <button @click="applySummaryFilter" class="text-xs bg-blue-600 text-white px-3 py-1.5 rounded-lg">确定筛选</button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="relative">
                            <button @click.stop="showColumnPanel = !showColumnPanel" class="bg-white border border-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-bold">
                                自定义列表显示
                            </button>
                            <div v-if="showColumnPanel" class="absolute left-0 top-full mt-2 z-20 w-[520px] max-w-[90vw] bg-white border border-gray-200 rounded-xl shadow-lg p-3">
                                <div class="flex items-center justify-between mb-2">
                                    <div class="text-xs font-bold text-gray-500">勾选要显示的列（可拖动排序）</div>
                                    <button
                                        type="button"
                                        @click="resetColumnSettings"
                                        class="text-xs bg-gray-100 text-gray-600 px-2.5 py-1 rounded-md hover:bg-gray-200"
                                    >
                                        恢复默认
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 gap-2 max-h-72 overflow-y-auto">
                                    <label
                                        v-for="col in orderedColumnOptions"
                                        :key="col.key"
                                        class="text-xs text-gray-700 flex items-center gap-2 p-2 border border-gray-100 rounded-lg bg-white cursor-move select-none"
                                        draggable="true"
                                        @dragstart="onColumnDragStart(col.key)"
                                        @dragover.prevent
                                        @drop.prevent="onColumnDrop(col.key)"
                                        @dragend="onColumnDragEnd"
                                    >
                                        <i class="fas fa-grip-vertical text-gray-300"></i>
                                        <input type="checkbox" v-model="visibleColumns[col.key]">
                                        <span>{{ col.label }}</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <button @click="resetSearch" class="bg-gray-100 text-gray-500 px-4 py-2 rounded-lg text-sm font-bold">重置</button>
                        <button @click="searchRecords" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm font-bold">搜索</button>
                        <div class="flex items-center gap-2 ml-auto">
                            <span class="text-xs text-gray-500">每页</span>
                            <select v-model.number="pageSize" class="px-2 py-2 border border-gray-200 rounded-lg text-xs bg-white">
                                <option :value="20">20</option>
                                <option :value="50">50</option>
                                <option :value="100">100</option>
                                <option :value="200">200</option>
                            </select>
                        </div>
                    </div>
                    
                </div>

                <!-- 数据列表 -->
                <div class="bg-white rounded-2xl shadow-sm overflow-auto">
                    <table class="w-full text-xs text-left text-gray-600 table-fixed">
                        <thead class="bg-gray-50 text-xs text-gray-700">
                            <tr>
                                <th
                                    v-for="col in orderedVisibleColumnOptions"
                                    :key="col.key"
                                    :class="getHeaderClass(col.key)"
                                    draggable="true"
                                    @dragstart="onColumnDragStart(col.key)"
                                    @dragover.prevent
                                    @drop.prevent="onColumnDrop(col.key)"
                                    @dragend="onColumnDragEnd"
                                >
                                    <span class="inline-flex items-center gap-1 cursor-move select-none">
                                        <i class="fas fa-grip-vertical text-gray-300"></i>
                                        {{ col.label }}
                                    </span>
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-if="records.length === 0">
                                <td :colspan="displayedColumnCount" class="px-2 py-8 text-center text-gray-400">暂无跟进记录</td>
                            </tr>
                            <tr v-for="record in pagedRecords" :key="record.id" class="hover:bg-gray-50">
                                <td
                                    v-for="col in orderedVisibleColumnOptions"
                                    :key="col.key"
                                    :class="getCellClass(col.key)"
                                    :title="getCellText(record, col.key)"
                                >
                                    {{ getCellText(record, col.key) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="records.length > 0" class="flex items-center justify-between mt-3 text-xs text-gray-500">
                    <div>共 {{ records.length }} 条，第 {{ currentPage }} / {{ totalPages }} 页</div>
                    <div class="flex items-center gap-2">
                        <button @click="prevPage" :disabled="currentPage<=1" class="px-3 py-1.5 rounded border border-gray-200 bg-white disabled:opacity-40 disabled:cursor-not-allowed">上一页</button>
                        <button @click="nextPage" :disabled="currentPage>=totalPages" class="px-3 py-1.5 rounded border border-gray-200 bg-white disabled:opacity-40 disabled:cursor-not-allowed">下一页</button>
                    </div>
                </div>
            </div>
    <div v-if="showSummaryModal" class="modal-overlay" @click.self="showSummaryModal=false">
        <div class="modal-content">
            <span class="close-btn" @click="showSummaryModal=false">&times;</span>
            <h3 class="font-bold text-lg mb-4">{{ summaryForm.id ? '修改概括' : '新增概括' }}</h3>
            <div class="grid grid-cols-3 gap-2 mb-3">
                <input v-model="summaryFilter.keyword" type="text" placeholder="筛选关键词" class="col-span-2 px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <select v-model="summaryFilter.followup_count" class="px-3 py-2 border border-gray-200 rounded-lg text-sm">
                    <option value="">全部次数</option>
                    <option value="1">第1次</option>
                    <option value="2">第2次</option>
                    <option value="3">第3次</option>
                </select>
            </div>
            <button @click="loadSummaryOptions" class="mb-3 bg-gray-100 text-gray-700 px-3 py-1.5 rounded-lg text-xs font-bold">筛选列表</button>
            <div class="max-h-40 overflow-y-auto border border-gray-100 rounded-lg mb-4">
                <div v-for="item in summaryOptions" :key="item.id" class="px-3 py-2 text-sm border-b border-gray-100 flex justify-between items-center">
                    <div>第{{ item.followup_count }}次 · {{ item.summary }}（{{ item.sort_order }}）</div>
                    <div>
                        <button @click="openSummaryModal(item)" class="text-blue-600 mr-2 text-xs">修改</button>
                        <button @click="deleteSummary(item.id)" class="text-red-500 text-xs">删除</button>
                    </div>
                </div>
            </div>
            <div class="space-y-3">
                <select v-model="summaryForm.followup_count" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                    <option value="1">第1次</option>
                    <option value="2">第2次</option>
                    <option value="3">第3次</option>
                </select>
                <input v-model="summaryForm.summary" type="text" placeholder="概括内容" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <input v-model="summaryForm.sort_order" type="number" placeholder="排序" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showSummaryModal=false" class="bg-gray-100 text-gray-600 px-4 py-2 rounded-lg text-sm">取消</button>
                    <button @click="saveSummary" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">保存</button>
                </div>
            </div>
        </div>
    </div>

    <div v-if="showFollowupModal" class="modal-overlay" @click.self="showFollowupModal=false">
        <div class="modal-content">
            <span class="close-btn" @click="showFollowupModal=false">&times;</span>
            <h3 class="font-bold text-lg mb-4">{{ followupForm.id ? '修改跟进' : '新增跟进' }}</h3>
            <div class="space-y-3">
                <div class="text-sm text-gray-500">第{{ followupForm.followup_count }}次跟进</div>
                <input v-model="followupForm.summary" type="text" placeholder="概括（选填）" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                <textarea v-model="followupForm.content" placeholder="跟进内容（必填）" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm min-h-[120px]"></textarea>
                <div class="flex justify-end gap-2 pt-2">
                    <button @click="showFollowupModal=false" class="bg-gray-100 text-gray-600 px-4 py-2 rounded-lg text-sm">取消</button>
                    <button @click="saveFollowup" class="bg-blue-600 text-white px-4 py-2 rounded-lg text-sm">保存</button>
                </div>
            </div>
        </div>
    </div>
</main>
</div>

    <script>
    const { createApp, ref, onMounted, onBeforeUnmount, computed, watch } = Vue;
    createApp({
        setup() {
            const userInfo = <?php echo json_encode($CURRENT_USER); ?>;
            const records = ref([]);
            const projects = ref([]);
            const search = ref({
                keyword: <?php echo json_encode((string)($_GET['keyword'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
                company_name: <?php echo json_encode((string)($_GET['company_name'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
                date_start: '',
                date_end: '',
                channel_name: '',
                project_id: '',
                filing_info: '',
                visit_info: 'valid_visit',
                deal_info: '',
                summary: '',
                summary_list: ''
            });
            const sidebarOpen = ref(false);
            const summaryOptions = ref([]);
            const summaryFilter = ref({ keyword: '', followup_count: '' });
            const showSummaryModal = ref(false);
            const summaryForm = ref({ id: '', followup_count: '1', summary: '', sort_order: 0 });
            const showFollowupModal = ref(false);
            const followupForm = ref({ id: '', filing_id: '', followup_count: 1, summary: '', content: '' });
            const showColumnPanel = ref(false);
            const filterBarRef = ref(null);
            const showSummaryFilterPanel = ref(false);
            const filterSummaryOptions = ref([]);
            const summaryFilterKeyword = ref('');
            const selectedSummaryFilters = ref([]);
            const onlyValidVisit = ref(true);
            const quickDatePreset = ref('all');
            const pageSize = ref(50);
            const currentPage = ref(1);
            const columnOptions = [
                { key: 'project_name', label: '报备项目' },
                { key: 'filing_time', label: '报备时间' },
                { key: 'visit_time', label: '到访时间' },
                { key: 'client_name', label: '客户' },
                { key: 'client_phone', label: '客户号码' },
                { key: 'company_name', label: '报备公司' },
                { key: 'broker_name', label: '经纪人' },
                { key: 'broker_phone', label: '经纪人号码' },
                { key: 'region_main', label: '板块' },
                { key: 'filing_info', label: '报备信息' },
                { key: 'visit_info', label: '带看信息' },
                { key: 'deal_info', label: '成交信息' },
                { key: 'remark', label: '备注' },
                { key: 'sales_remark', label: '案场备注' },
                { key: 'channel_follower', label: '渠道' },
                { key: 'salesperson', label: '销售' },
                { key: 'client_intention', label: '意愿' },
                { key: 'franchise_brand', label: '加盟' },
                { key: 'followup_1', label: '第1次跟进' },
                { key: 'followup_2', label: '第2次跟进' },
                { key: 'followup_3', label: '第3次跟进' }
            ];
            const defaultVisibleColumns = Object.fromEntries(columnOptions.map(col => [col.key, col.key !== 'remark']));
            const visibleColumns = ref({ ...defaultVisibleColumns });
            const defaultColumnOrder = columnOptions.map(col => col.key);
            const columnOrder = ref([...defaultColumnOrder]);
            const draggingColumnKey = ref('');
            const columnMap = Object.fromEntries(columnOptions.map(col => [col.key, col]));
            const orderedColumnOptions = computed(() => {
                const keys = Array.isArray(columnOrder.value) && columnOrder.value.length > 0
                    ? columnOrder.value
                    : defaultColumnOrder;
                return keys
                    .map(key => columnMap[key])
                    .filter(Boolean);
            });
            const orderedVisibleColumnOptions = computed(() => {
                return orderedColumnOptions.value.filter(col => visibleColumns.value[col.key]);
            });
            const displayedColumnCount = computed(() => {
                const count = orderedVisibleColumnOptions.value.length;
                return count > 0 ? count : 1;
            });
            const totalPages = computed(() => {
                const total = Math.ceil((records.value.length || 0) / (pageSize.value || 1));
                return total > 0 ? total : 1;
            });
            const pagedRecords = computed(() => {
                const start = (currentPage.value - 1) * pageSize.value;
                return records.value.slice(start, start + pageSize.value);
            });
            const maskPhone = (phone) => {
                const text = String(phone || '').trim();
                if (!text) return '-';
                const digits = text.replace(/\D/g, '');
                if (digits.length < 7) return text;
                return digits.slice(0, 3) + '****' + digits.slice(-4);
            };
            
            const getHeaderClass = (key) => {
                const widthMap = {
                    project_name: 'w-24',
                    visit_time: 'w-24',
                    filing_time: 'w-24',
                    client_name: 'w-16',
                    client_phone: 'w-24',
                    company_name: 'w-32',
                    broker_name: 'w-16',
                    broker_phone: 'w-24',
                    region_main: 'w-16',
                    filing_info: 'w-20',
                    visit_info: 'w-20',
                    deal_info: 'w-28',
                    remark: 'w-28',
                    sales_remark: 'w-36',
                    channel_follower: 'w-16',
                    salesperson: 'w-16',
                    client_intention: 'w-16',
                    franchise_brand: 'w-20',
                    followup_1: 'w-36',
                    followup_2: 'w-36',
                    followup_3: 'w-36'
                };
                return `px-2 py-2 ${widthMap[key] || 'w-24'}`;
            };
            const getCellClass = (key) => {
                const classMap = {
                    project_name: 'px-2 py-2 font-bold text-blue-700 truncate',
                    visit_time: 'px-2 py-2 truncate',
                    client_name: 'px-2 py-2 font-bold text-gray-800 truncate',
                    client_phone: 'px-2 py-2 font-mono text-gray-500 truncate',
                    broker_phone: 'px-2 py-2 font-mono truncate'
                };
                return classMap[key] || 'px-2 py-2 truncate';
            };
            const getCellText = (record, key) => {
                if (!record) return '-';
                if (key === 'project_name') return record.project_name || '-';
                if (key === 'visit_time') return getVisitTimeText(record);
                if (key === 'filing_time') return getFilingTimeText(record);
                if (key === 'client_name') return record.client_name || '-';
                if (key === 'client_phone') return maskPhone(record.client_phone);
                if (key === 'company_name') return record.company_name || record.company_full_name || '-';
                if (key === 'broker_name') return record.broker_name || record.agent_name || '-';
                if (key === 'broker_phone') return record.broker_phone || record.agent_phone || '-';
                if (key === 'region_main') return record.region_main || '-';
                if (key === 'filing_info') return getFilingStatusText(record);
                if (key === 'visit_info') return getVisitStatusText(record);
                if (key === 'deal_info') return getDealInfoText(record);
                if (key === 'remark') return record.remark || '-';
                if (key === 'sales_remark') return extractSalesRemarkText(record);
                if (key === 'channel_follower') return record.channel_follower || '-';
                if (key === 'salesperson') return record.salesperson || '-';
                if (key === 'client_intention') return getClientIntentionText(record);
                if (key === 'franchise_brand') return record.franchise_brand || '-';
                if (key === 'followup_1') return getFollowupText(record, 1);
                if (key === 'followup_2') return getFollowupText(record, 2);
                if (key === 'followup_3') return getFollowupText(record, 3);
                return '-';
            };

            // 页面标题
            const pageTitle = computed(() => '跟进记录管理');
            
            // 加载跟进记录
            const loadRecords = async () => {
                try {
                    const params = new URLSearchParams({ action: 'get_followup_list' });
                    Object.keys(search.value).forEach(k => {
                        if (search.value[k] !== '') params.append(k, search.value[k]);
                    });
                    const res = await fetch('followup_records.php?' + params.toString());
                    const data = await res.json();
                    records.value = Array.isArray(data) ? data : [];
                    currentPage.value = 1;
                } catch (e) {
                    console.error('加载记录失败:', e);
                }
            };

            const getDateStr = (dateObj) => {
                const y = dateObj.getFullYear();
                const m = String(dateObj.getMonth() + 1).padStart(2, '0');
                const d = String(dateObj.getDate()).padStart(2, '0');
                return `${y}-${m}-${d}`;
            };
            const applyQuickDate = (preset) => {
                const now = new Date();
                quickDatePreset.value = preset;
                if (preset === 'today') {
                    const day = getDateStr(now);
                    search.value.date_start = day;
                    search.value.date_end = day;
                } else if (preset === 'week') {
                    const day = now.getDay();
                    const diffToMonday = day === 0 ? 6 : day - 1;
                    const monday = new Date(now);
                    monday.setDate(now.getDate() - diffToMonday);
                    search.value.date_start = getDateStr(monday);
                    search.value.date_end = getDateStr(now);
                } else if (preset === 'month') {
                    const monthStart = new Date(now.getFullYear(), now.getMonth(), 1);
                    search.value.date_start = getDateStr(monthStart);
                    search.value.date_end = getDateStr(now);
                } else if (preset === 'all') {
                    search.value.date_start = '';
                    search.value.date_end = '';
                }
                loadRecords();
            };

            const loadProjects = async () => {
                const res = await fetch('followup_records.php?action=get_projects');
                projects.value = await res.json();
            };
            const loadFilterSummaryOptions = async () => {
                try {
                    const params = new URLSearchParams({ action: 'get_summary_options' });
                    if (summaryFilterKeyword.value) params.append('keyword', summaryFilterKeyword.value);
                    const res = await fetch('followup_records.php?' + params.toString());
                    const data = await res.json();
                    filterSummaryOptions.value = Array.isArray(data) ? data : [];
                } catch (e) {
                    filterSummaryOptions.value = [];
                }
            };
            const toggleSummaryFilterPanel = () => {
                showSummaryFilterPanel.value = !showSummaryFilterPanel.value;
                showColumnPanel.value = false;
                if (showSummaryFilterPanel.value) loadFilterSummaryOptions();
            };
            const toggleSummaryFilter = (summary) => {
                const s = String(summary || '').trim();
                if (!s) return;
                if (selectedSummaryFilters.value.includes(s)) {
                    selectedSummaryFilters.value = selectedSummaryFilters.value.filter(v => v !== s);
                } else {
                    selectedSummaryFilters.value.push(s);
                }
            };
            const applySummaryFilter = () => {
                search.value.summary_list = selectedSummaryFilters.value.join(',');
                showSummaryFilterPanel.value = false;
                loadRecords();
            };
            const clearSummaryFilter = () => {
                selectedSummaryFilters.value = [];
                summaryFilterKeyword.value = '';
                search.value.summary_list = '';
                showSummaryFilterPanel.value = false;
                loadRecords();
            };
            
            // 搜索记录
            const searchRecords = () => {
                quickDatePreset.value = '';
                loadRecords();
            };
            const toggleValidVisitOnly = () => {
                onlyValidVisit.value = !onlyValidVisit.value;
                search.value.visit_info = onlyValidVisit.value ? 'valid_visit' : '';
                loadRecords();
            };
            
            // 重置搜索
            const resetSearch = () => {
                search.value = {
                    keyword: '',
                    company_name: '',
                    date_start: '',
                    date_end: '',
                    channel_name: '',
                    project_id: '',
                    filing_info: '',
                    visit_info: onlyValidVisit.value ? 'valid_visit' : '',
                    deal_info: '',
                    summary: '',
                    summary_list: ''
                };
                selectedSummaryFilters.value = [];
                summaryFilterKeyword.value = '';
                showSummaryFilterPanel.value = false;
                applyQuickDate('all');
            };
            
            // 导出Excel
            const exportExcel = () => {
                const params = new URLSearchParams({ action: 'export_excel' });
                Object.keys(search.value).forEach(k => {
                    if (search.value[k] !== '') params.append(k, search.value[k]);
                });
                // 严格按当前表格“可见+顺序”导出
                const selected = orderedVisibleColumnOptions.value.map(col => col.key);
                if (selected.length === 0) {
                    alert('请至少勾选1个显示列后再导出');
                    return;
                }
                params.append('visible_columns', selected.join(','));
                window.location.href = 'followup_records.php?' + params.toString();
            };
            
            // 切换侧边栏
            const toggleSidebar = () => {
                sidebarOpen.value = !sidebarOpen.value;
            };
            
            // 跳转到后台管理
            const goToAdmin = () => {
                window.location.href = 'admin.php';
            };
            
            const loadSummaryOptions = async () => {
                const params = new URLSearchParams({ action: 'get_summary_options' });
                if (summaryFilter.value.keyword) params.append('keyword', summaryFilter.value.keyword);
                if (summaryFilter.value.followup_count) params.append('followup_count', summaryFilter.value.followup_count);
                const res = await fetch('followup_records.php?' + params.toString());
                summaryOptions.value = await res.json();
            };

            const openSummaryModal = (item = null) => {
                if (item) {
                    summaryForm.value = {
                        id: item.id,
                        followup_count: String(item.followup_count),
                        summary: item.summary,
                        sort_order: item.sort_order
                    };
                } else {
                    summaryForm.value = { id: '', followup_count: '1', summary: '', sort_order: 0 };
                }
                showSummaryModal.value = true;
            };

            const saveSummary = async () => {
                const action = summaryForm.value.id ? 'update_summary_option' : 'add_summary_option';
                const res = await fetch('followup_records.php?action=' + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(summaryForm.value)
                });
                const d = await res.json();
                if (d.status === 'success') {
                    showSummaryModal.value = false;
                    loadSummaryOptions();
                } else {
                    alert(d.msg || '保存失败');
                }
            };

            const deleteSummary = async (id) => {
                if (!confirm('确定删除该概括吗？')) return;
                const res = await fetch('followup_records.php?action=delete_summary_option', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const d = await res.json();
                if (d.status === 'success') loadSummaryOptions();
                else alert(d.msg || '删除失败');
            };

            const getFollowup = (record, count) => {
                if (!record || !record.followups) return null;
                return record.followups.find(f => parseInt(f.followup_count) === parseInt(count)) || null;
            };
            const getFilingStatusText = (record) => {
                const status = parseInt(record?.status || 0, 10);
                if (status === 6) return '报备无效';
                const visitTypeRaw = record?.visit_type;
                const visitType = (visitTypeRaw === null || visitTypeRaw === undefined || visitTypeRaw === '') ? null : parseInt(visitTypeRaw, 10);
                if (status === 0) return '待处理';
                if (status === 1) return visitType === 0 ? '有效报备' : '待处理';
                if (status === 2) return '有效到访';
                if (status === 3) return '已下定';
                if (status === 4) return '已成交';
                if (status === 5) {
                    if (visitType === 2) return '无效到访';
                    if (visitType === 3) return '重复到访';
                    return '无效';
                }
                if (status === 7) return '退房';
                return '未知';
            };
            const getVisitStatusText = (record) => {
                const status = parseInt(record?.status || 0, 10);
                const visitType = parseInt(record?.visit_type ?? -1, 10);
                if (status === 2) return '有效到访';
                if (status === 5 && visitType === 2) return '无效到访';
                if (status === 5 && visitType === 3) return '重复到访';
                return '-';
            };
            const extractStaffActionTime = (record, keywords) => {
                const statusLog = String(record?.status_log || '');
                if (!statusLog || !Array.isArray(keywords) || keywords.length === 0) return '';
                const lines = statusLog.split(/\r?\n/);
                let matched = '';
                lines.forEach(line => {
                    const l = String(line || '').trim();
                    if (!l) return;
                    const hit = keywords.some(kw => kw && l.includes(kw));
                    if (!hit) return;
                    const m = l.match(/^(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2})\s+\[案场/);
                    if (m && m[1]) matched = m[1];
                });
                return matched;
            };
            const getFilingTimeText = (record) => {
                const status = parseInt(record?.status || 0, 10);
                if (status === 6) {
                    return extractStaffActionTime(record, ['标记为报备无效']) || (record?.created_at || '-');
                }
                if (status >= 1) {
                    return extractStaffActionTime(record, ['确认有效报备']) || (record?.created_at || '-');
                }
                return record?.created_at || '-';
            };
            const getVisitTimeText = (record) => {
                const status = parseInt(record?.status || 0, 10);
                const visitType = parseInt(record?.visit_type ?? -1, 10);
                if (status === 2) {
                    return extractStaffActionTime(record, ['确认有效到访']) || (record?.visit_time || '-');
                }
                if (status === 5 && visitType === 2) {
                    return extractStaffActionTime(record, ['标记为无效到访']) || (record?.visit_time || '-');
                }
                if (status === 5 && visitType === 3) {
                    return extractStaffActionTime(record, ['标记为重复到访']) || (record?.visit_time || '-');
                }
                return '-';
            };
            const getDealInfoText = (record) => {
                const status = parseInt(record?.status || 0, 10);
                if (status !== 4) return '未成交';
                const room = String(record?.subscribed_room_number || record?.room_number || '').trim();
                const price = parseFloat(record?.deal_price || 0);
                if (room && price > 0) return room + ' / ¥' + price.toLocaleString();
                if (room) return room;
                if (price > 0) return '¥' + price.toLocaleString();
                return '已成交';
            };
            const getClientIntentionText = (record) => {
                const intention = parseInt(record?.client_intention || 0, 10);
                if (intention === 4) return '强烈';
                if (intention === 3) return '一般';
                if (intention === 2) return '还行';
                if (intention === 1) return '无意向';
                return '-';
            };
            const getFollowupText = (record, count) => {
                const f = getFollowup(record, count);
                if (!f) return '-';
                const summary = String(f.summary || '').trim();
                const content = String(f.content || '').trim();
                if (summary && content) return summary + '：' + content;
                return summary || content || '-';
            };
            const extractSalesRemarkText = (record) => {
                const text = String(record?.status_log || '').trim();
                if (!text) return '-';
                const lines = text.split(/\r?\n/);
                const result = [];
                lines.forEach(line => {
                    const l = String(line || '').trim();
                    if (!l) return;
                    if (l.indexOf('备注:') === -1 && l.indexOf('备注：') === -1) return;
                    const m = l.match(/备注[:：]\s*(.+)$/);
                    if (!m || !m[1]) return;
                    let r = String(m[1]).trim();
                    if (r.includes('->')) {
                        const parts = r.split('->');
                        r = String(parts[parts.length - 1] || '').trim();
                    }
                    if (!r || r === '空' || r === '-') return;
                    if (!result.includes(r)) result.push(r);
                });
                return result.length ? result.join(' | ') : '-';
            };
            const saveVisibleColumns = () => {
                try {
                    localStorage.setItem('followup_records_visible_columns', JSON.stringify(visibleColumns.value));
                } catch (e) {}
            };
            const loadVisibleColumns = () => {
                try {
                    const raw = localStorage.getItem('followup_records_visible_columns');
                    if (!raw) return;
                    const parsed = JSON.parse(raw);
                    if (!parsed || typeof parsed !== 'object') return;
                    visibleColumns.value = { ...defaultVisibleColumns, ...parsed };
                    // 备注列固定隐藏，不参与默认展示
                    visibleColumns.value.remark = false;
                } catch (e) {}
            };
            const saveColumnOrder = () => {
                try {
                    localStorage.setItem('followup_records_column_order', JSON.stringify(columnOrder.value));
                } catch (e) {}
            };
            const normalizeColumnOrderPlacement = (order) => {
                const current = Array.isArray(order) ? [...order] : [...defaultColumnOrder];
                const projectIdx = current.indexOf('project_name');
                const filingIdx = current.indexOf('filing_time');
                if (projectIdx < 0 || filingIdx < 0) return current;
                if (filingIdx === projectIdx + 1) return current;
                current.splice(filingIdx, 1);
                const targetProjectIdx = current.indexOf('project_name');
                current.splice(targetProjectIdx + 1, 0, 'filing_time');
                return current;
            };
            const loadColumnOrder = () => {
                try {
                    const raw = localStorage.getItem('followup_records_column_order');
                    if (!raw) {
                        columnOrder.value = normalizeColumnOrderPlacement(defaultColumnOrder);
                        return;
                    }
                    const parsed = JSON.parse(raw);
                    if (!Array.isArray(parsed)) return;
                    const valid = parsed.filter(k => Object.prototype.hasOwnProperty.call(columnMap, k));
                    const missing = defaultColumnOrder.filter(k => !valid.includes(k));
                    columnOrder.value = normalizeColumnOrderPlacement([...valid, ...missing]);
                } catch (e) {}
            };
            const onColumnDragStart = (key) => {
                draggingColumnKey.value = key;
            };
            const onColumnDrop = (targetKey) => {
                const sourceKey = draggingColumnKey.value;
                if (!sourceKey || sourceKey === targetKey) return;
                const current = [...columnOrder.value];
                const sourceIdx = current.indexOf(sourceKey);
                const targetIdx = current.indexOf(targetKey);
                if (sourceIdx < 0 || targetIdx < 0) return;
                current.splice(sourceIdx, 1);
                current.splice(targetIdx, 0, sourceKey);
                columnOrder.value = current;
                saveColumnOrder();
            };
            const onColumnDragEnd = () => {
                draggingColumnKey.value = '';
            };
            const prevPage = () => {
                if (currentPage.value > 1) currentPage.value -= 1;
            };
            const nextPage = () => {
                if (currentPage.value < totalPages.value) currentPage.value += 1;
            };
            const resetColumnSettings = () => {
                visibleColumns.value = { ...defaultVisibleColumns };
                columnOrder.value = normalizeColumnOrderPlacement(defaultColumnOrder);
                saveVisibleColumns();
                saveColumnOrder();
            };
            const handleGlobalClick = (event) => {
                const root = filterBarRef.value;
                if (!root) return;
                const target = event.target;
                if (target && !root.contains(target)) {
                    showSummaryFilterPanel.value = false;
                    showColumnPanel.value = false;
                }
            };

            const openFollowupModal = (record, count, followup = null) => {
                followupForm.value = {
                    id: followup ? followup.id : '',
                    filing_id: record.id,
                    followup_count: count,
                    summary: followup ? (followup.summary || '') : '',
                    content: followup ? (followup.content || '') : ''
                };
                showFollowupModal.value = true;
            };

            const saveFollowup = async () => {
                if (!followupForm.value.content.trim()) {
                    alert('请填写跟进内容');
                    return;
                }
                const res = await fetch('followup_records.php?action=save_followup_record', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(followupForm.value)
                });
                const d = await res.json();
                if (d.status === 'success') {
                    showFollowupModal.value = false;
                    loadRecords();
                } else {
                    alert(d.msg || '保存失败');
                }
            };

            const deleteFollowup = async (id) => {
                if (!confirm('确定删除该跟进记录吗？')) return;
                const res = await fetch('followup_records.php?action=delete_followup_record', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ id })
                });
                const d = await res.json();
                if (d.status === 'success') loadRecords();
                else alert(d.msg || '删除失败');
            };
            
            onMounted(() => {
                loadVisibleColumns();
                loadColumnOrder();
                applyQuickDate('all');
                loadProjects();
                loadSummaryOptions();
                document.addEventListener('click', handleGlobalClick);
            });
            onBeforeUnmount(() => {
                document.removeEventListener('click', handleGlobalClick);
            });
            watch(visibleColumns, () => {
                saveVisibleColumns();
            }, { deep: true });
            watch(columnOrder, () => {
                saveColumnOrder();
            }, { deep: true });
            watch(pageSize, () => {
                currentPage.value = 1;
            });
            watch(() => search.value.visit_info, (v) => {
                onlyValidVisit.value = v === 'valid_visit';
            });
            
            return {
                userInfo,
                records,
                projects,
                search,
                filterBarRef,
                showSummaryFilterPanel,
                filterSummaryOptions,
                summaryFilterKeyword,
                selectedSummaryFilters,
                toggleSummaryFilterPanel,
                toggleSummaryFilter,
                applySummaryFilter,
                clearSummaryFilter,
                loadFilterSummaryOptions,
                sidebarOpen,
                pageTitle,
                loadRecords,
                searchRecords,
                toggleValidVisitOnly,
                onlyValidVisit,
                applyQuickDate,
                quickDatePreset,
                resetSearch,
                exportExcel,
                pageSize,
                currentPage,
                totalPages,
                pagedRecords,
                prevPage,
                nextPage,
                summaryOptions,
                summaryFilter,
                showSummaryModal,
                summaryForm,
                showColumnPanel,
                columnOptions,
                orderedColumnOptions,
                orderedVisibleColumnOptions,
                visibleColumns,
                displayedColumnCount,
                getHeaderClass,
                getCellClass,
                getCellText,
                onColumnDragStart,
                onColumnDrop,
                onColumnDragEnd,
                resetColumnSettings,
                loadSummaryOptions,
                openSummaryModal,
                saveSummary,
                deleteSummary,
                showFollowupModal,
                followupForm,
                maskPhone,
                getFollowup,
                getFilingStatusText,
                getVisitStatusText,
                getDealInfoText,
                getClientIntentionText,
                getFollowupText,
                extractSalesRemarkText,
                openFollowupModal,
                saveFollowup,
                deleteFollowup
            };
        }
    }).mount('#app');
    </script>
</body>
</html>