<?php
// admin_filings.php - 报备审核独立管理页
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

require_once __DIR__ . '/../includes/agent_roles.php';

$action = $_GET['action'] ?? 'view';

/** 与「公池」展示语义等价、应合并为空的跟进人占位文案（不入下拉、保存时清空） */
function admin_follower_is_pool_placeholder($s) {
    $s = trim((string)$s);
    if ($s === '') {
        return false;
    }
    $norm = preg_replace('/\s+/u', '', $s);
    $aliases = ['公池', '公共池', '公池（无跟进人）', '公池(无跟进人)', '公共池（无跟进人）', '公共池(无跟进人)'];
    foreach ($aliases as $a) {
        if ($norm === preg_replace('/\s+/u', '', $a)) {
            return true;
        }
    }
    return false;
}

/** 写入库前的跟进人：公池类占位一律存空字符串 */
function admin_follower_normalize_saved($s) {
    $s = trim((string)$s);
    if (admin_follower_is_pool_placeholder($s)) {
        return '';
    }
    return $s;
}

$__filings_init_follower_kw = trim((string)($_GET['follower_kw'] ?? ''));
if ($__filings_init_follower_kw !== '' && $__filings_init_follower_kw !== '__pool__' && admin_follower_is_pool_placeholder($__filings_init_follower_kw)) {
    $__filings_init_follower_kw = '__pool__';
}

function admin_display_visit_time($row) {
    $status = intval($row['status'] ?? 0);
    $visitTypeRaw = $row['visit_type'] ?? null;
    $visitType = ($visitTypeRaw === null || $visitTypeRaw === '' ? null : intval($visitTypeRaw));
    $raw = trim((string)($row['visit_time'] ?? ''));
    if ($raw === '' || $raw === '0000-00-00 00:00:00') return '';
    // 有效到访后进入下定(3)/成交(4) 时库内 visit_time 仍在，导出应继续展示，避免误判「到访被清空」
    if ($status === 2 || $status === 3 || $status === 4) {
        return $raw;
    }
    if ($status === 5 && ($visitType === 2 || $visitType === 3)) {
        return $raw;
    }
    return '';
}

function admin_mask_phone_for_export($phone) {
    $text = trim((string)$phone);
    if ($text === '') return '';
    $digits = preg_replace('/\D+/', '', $text);
    if (strlen($digits) >= 7) {
        return substr($digits, 0, 3) . '****' . substr($digits, -4);
    }
    return $text;
}

function admin_report_status_text($row) {
    $status = intval($row['status'] ?? 0);
    if ($status === 6) return '无效报备';
    if ($status >= 1) return '有效报备';
    return '-';
}

function admin_visit_status_text($row) {
    $status = intval($row['status'] ?? 0);
    $visitTypeRaw = $row['visit_type'] ?? null;
    $visitType = ($visitTypeRaw === null || $visitTypeRaw === '' ? null : intval($visitTypeRaw));
    if ($status === 2) return '有效到访';
    if ($status === 5 && $visitType === 2) return '无效到访';
    if ($status === 5 && $visitType === 3) return '重复到访';
    return '-';
}

/** 综合搜索：137****5815 / 137＊＊＊＊5815 → SQL LIKE 用 前三%后四，匹配库内完整号码 */
function admin_filings_phone_mask_like_pattern($kw) {
    $s = preg_replace('/\s+/u', '', trim((string)$kw));
    if ($s === '') {
        return null;
    }
    if (preg_match('/^(\d{3})[\*＊]+(\d{4})$/u', $s, $m)) {
        return $m[1] . '%' . $m[2];
    }
    return null;
}

function build_filing_where_and_params($input) {
    $kw = $input['kw'] ?? '';
    $companyKw = isset($input['company_kw']) ? trim((string)$input['company_kw']) : '';
    $followerKw = isset($input['follower_kw']) ? trim((string)$input['follower_kw']) : '';
    if ($followerKw !== '' && $followerKw !== '__pool__' && admin_follower_is_pool_placeholder($followerKw)) {
        $followerKw = '__pool__';
    }
    $status = $input['status'] ?? '';
    $projectId = $input['project_id'] ?? '';
    $dateStart = $input['date_start'] ?? '';
    $dateEnd = $input['date_end'] ?? '';

    $where = "WHERE 1=1";
    $params = [];

    if ($kw !== '') {
        $kwLike = '%' . $kw . '%';
        $maskLike = admin_filings_phone_mask_like_pattern($kw);
        $where .= ' AND (f.client_name LIKE ? OR p.name LIKE ? OR f.broker_name LIKE ? OR f.broker_phone LIKE ? OR IFNULL(a.phone,\'\') LIKE ?';
        $params = array_merge($params, [$kwLike, $kwLike, $kwLike, $kwLike, $kwLike]);
        if ($maskLike !== null) {
            $where .= ' OR f.client_phone LIKE ? OR IFNULL(f.subscription_phone_full,\'\') LIKE ?';
            $params[] = $maskLike;
            $params[] = $maskLike;
        }
        $where .= ' OR f.client_phone LIKE ? OR IFNULL(f.subscription_phone_full,\'\') LIKE ?)';
        $params[] = $kwLike;
        $params[] = $kwLike;
    }
    if ($companyKw !== '') {
        $where .= " AND f.company_name LIKE ?";
        $params[] = "%$companyKw%";
    }
    if ($followerKw !== '') {
        if ($followerKw === '__pool__') {
            $where .= " AND (f.follower IS NULL OR TRIM(f.follower) = '')";
        } else {
            $where .= " AND TRIM(f.follower) = ?";
            $params[] = $followerKw;
        }
    }
    if ($status !== '') {
        if ($status === 'pending_review') {
            $where .= " AND f.status = 0";
        } elseif ($status === 'unvisited_report') {
            $where .= " AND f.status = 1";
        } else {
            $where .= " AND f.status = ?";
            $params[] = intval($status);
        }
    }
    if ($projectId !== '') {
        $where .= " AND f.project_id = ?";
        $params[] = $projectId;
    }
    if ($dateStart !== '') {
        $where .= " AND DATE(f.created_at) >= ?";
        $params[] = $dateStart;
    }
    if ($dateEnd !== '') {
        $where .= " AND DATE(f.created_at) <= ?";
        $params[] = $dateEnd;
    }
    return [$where, $params];
}

/** Excel 列字母 → 0-based 列索引（A=0, Z=25, AA=26） */
function admin_filings_col_to_index($letters) {
    $letters = strtoupper((string)$letters);
    $n = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $n = $n * 26 + (ord($letters[$i]) - 64);
    }
    return $n - 1;
}

/** xlsx 共享字符串表 */
function admin_filings_xlsx_read_shared_strings(ZipArchive $zip) {
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false || $xml === '') {
        return [];
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        return [];
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $out = [];
    foreach ($xp->query('//m:si') as $si) {
        $out[] = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $si->textContent)));
    }
    return $out;
}

/** xlsx 单元格取值 */
function admin_filings_xlsx_cell_value(DOMElement $c, array $shared) {
    $t = $c->getAttribute('t');
    $vEl = null;
    foreach ($c->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'v') as $x) {
        $vEl = $x;
        break;
    }
    if ($t === 's' && $vEl) {
        $i = (int)trim($vEl->textContent);
        return $shared[$i] ?? '';
    }
    if ($t === 'inlineStr') {
        $is = null;
        foreach ($c->getElementsByTagNameNS('http://schemas.openxmlformats.org/spreadsheetml/2006/main', 'is') as $x) {
            $is = $x;
            break;
        }
        return $is ? trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $is->textContent))) : '';
    }
    if ($t === 'b' && $vEl) {
        return ((int)trim($vEl->textContent)) ? 'TRUE' : 'FALSE';
    }
    if ($vEl) {
        return trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $vEl->textContent)));
    }
    return '';
}

/** 首个工作表路径（xl/worksheets/sheet1.xml 或 workbook+rels） */
function admin_filings_xlsx_first_sheet_path(ZipArchive $zip) {
    if ($zip->locateName('xl/worksheets/sheet1.xml') !== false) {
        return 'xl/worksheets/sheet1.xml';
    }
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($wb === false || $rels === false) {
        return null;
    }
    $domWb = new DOMDocument();
    if (!@$domWb->loadXML($wb)) {
        return null;
    }
    $xw = new DOMXPath($domWb);
    $xw->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $first = $xw->query('//m:sheets/m:sheet')->item(0);
    if (!$first) {
        return null;
    }
    $relNs = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $rid = $first->getAttributeNS($relNs, 'id');
    if ($rid === '') {
        $rid = $first->getAttribute('r:id');
    }
    if ($rid === '') {
        return null;
    }
    $domR = new DOMDocument();
    if (!@$domR->loadXML($rels)) {
        return null;
    }
    $xr = new DOMXPath($domR);
    $ridEsc = str_replace("'", "''", $rid);
    $rel = $xr->query("//*[local-name()='Relationship' and @Id='" . $ridEsc . "']")->item(0);
    if (!$rel) {
        return null;
    }
    $target = str_replace('\\', '/', (string)$rel->getAttribute('Target'));
    if ($target === '') {
        return null;
    }
    if (strpos($target, 'xl/') === 0) {
        return ltrim($target, '/');
    }
    return 'xl/' . ltrim($target, '/');
}

/** xlsx → 二维字符串表（行从 1 起与 Excel 一致；列为 0..n 稠密数组） */
function admin_filings_xlsx_to_matrix($path, &$errMsg) {
    $errMsg = '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        $errMsg = '无法以 Zip 打开文件（请确认是 .xlsx）';
        return null;
    }
    $sheetPath = admin_filings_xlsx_first_sheet_path($zip);
    if ($sheetPath === null || $zip->locateName($sheetPath) === false) {
        $zip->close();
        $errMsg = '未找到工作表';
        return null;
    }
    $shared = admin_filings_xlsx_read_shared_strings($zip);
    $xml = $zip->getFromName($sheetPath);
    $zip->close();
    if ($xml === false || $xml === '') {
        $errMsg = '工作表内容为空';
        return null;
    }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) {
        $errMsg = '工作表 XML 解析失败';
        return null;
    }
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $matrix = [];
    foreach ($xp->query('//m:sheetData/m:row') as $rowEl) {
        $rAttr = $rowEl->getAttribute('r');
        $rNum = $rAttr !== '' ? (int)$rAttr : (count($matrix) > 0 ? max(array_keys($matrix)) + 1 : 1);
        $rowSparse = [];
        $colIdx = 0;
        foreach ($xp->query('m:c', $rowEl) as $c) {
            /** @var DOMElement $c */
            $ref = $c->getAttribute('r');
            if ($ref !== '' && preg_match('/^([A-Z]+)/i', $ref, $m)) {
                $colIdx = admin_filings_col_to_index(strtoupper($m[1]));
            }
            $rowSparse[$colIdx] = admin_filings_xlsx_cell_value($c, $shared);
            $colIdx++;
        }
        if (empty($rowSparse)) {
            continue;
        }
        ksort($rowSparse, SORT_NUMERIC);
        $maxC = max(array_keys($rowSparse));
        $dense = [];
        for ($i = 0; $i <= $maxC; $i++) {
            $dense[] = (string)($rowSparse[$i] ?? '');
        }
        $matrix[$rNum] = $dense;
    }
    ksort($matrix, SORT_NUMERIC);
    return array_values($matrix);
}

/** HTML 表格（含本页「导出 XLS」的伪 xls）→ 二维表 */
function admin_filings_html_table_to_matrix($html, &$errMsg) {
    $errMsg = '';
    $enc = 'UTF-8';
    if (preg_match('/charset=([\w-]+)/i', $html, $m)) {
        $enc = strtoupper($m[1]) === 'UTF8' ? 'UTF-8' : $m[1];
    }
    if (!mb_detect_encoding($html, 'UTF-8', true)) {
        $html = @mb_convert_encoding($html, 'UTF-8', $enc);
    }
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $wrapped = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' . $html . '</body></html>';
    @$dom->loadHTML($wrapped);
    libxml_clear_errors();
    $tables = $dom->getElementsByTagName('table');
    if ($tables->length === 0) {
        $errMsg = '未找到 HTML 表格（若为本系统导出，请直接上传下载的 .xls 文件）';
        return null;
    }
    $matrix = [];
    $table = $tables->item(0);
    foreach ($table->getElementsByTagName('tr') as $tr) {
        $row = [];
        foreach ($tr->childNodes as $cell) {
            if ($cell->nodeType !== XML_ELEMENT_NODE) {
                continue;
            }
            $tag = strtolower($cell->nodeName);
            if ($tag !== 'td' && $tag !== 'th') {
                continue;
            }
            $row[] = trim(preg_replace('/\s+/u', ' ', str_replace("\xC2\xA0", ' ', $cell->textContent)));
        }
        if (!empty($row)) {
            $matrix[] = $row;
        }
    }
    return $matrix;
}

/** 矩阵首行匹配表头后，返回关联行数组列表 */
function admin_filings_matrix_to_assoc_rows(array $matrix, &$errMsg) {
    $errMsg = '';
    $want = [
        '项目名称' => 'project_name',
        '创建时间' => 'created_at',
        '报备时间' => 'filing_time_dup',
        '客户姓名' => 'client_name',
        '客户号码' => 'client_phone',
        '经纪公司' => 'company_name',
        '经纪人' => 'broker_name',
        '经纪人号码' => 'broker_phone',
        '到访时间' => 'visit_time',
        '报备状态' => 'report_status',
        '到访状态' => 'visit_status',
        '认购状态' => 'subscribe_status',
        '成交时间' => 'deal_time',
        '渠道跟进人' => 'follower',
        '现场销售' => 'salesperson',
        '经纪人反馈' => 'broker_feedback',
        '销售反馈' => 'sales_feedback',
        '客户意见' => 'customer_opinion',
        '图片附件' => 'attachments_dup',
    ];
    $headerRowIdx = -1;
    $colMap = [];
    foreach ($matrix as $ri => $row) {
        $map = [];
        foreach ($row as $ci => $h) {
            $h = trim((string)$h);
            if (isset($want[$h])) {
                $map[$ci] = $want[$h];
            }
        }
        if (isset($map[0]) && $map[0] === 'project_name' && count($map) >= 5) {
            $headerRowIdx = $ri;
            $colMap = $map;
            break;
        }
    }
    if ($headerRowIdx < 0) {
        $errMsg = '未识别表头：需包含「项目名称」等导出列（支持本页导出或标准 17 列模板）';
        return null;
    }
    $out = [];
    for ($i = $headerRowIdx + 1; $i < count($matrix); $i++) {
        $row = $matrix[$i];
        $assoc = [];
        foreach ($colMap as $ci => $key) {
            $assoc[$key] = isset($row[$ci]) ? trim((string)$row[$ci]) : '';
        }
        if ($assoc['project_name'] === '' && ($assoc['client_name'] ?? '') === '' && ($assoc['client_phone'] ?? '') === '') {
            continue;
        }
        if (($assoc['created_at'] ?? '') === '' && ($assoc['filing_time_dup'] ?? '') !== '') {
            $assoc['created_at'] = $assoc['filing_time_dup'];
        }
        unset($assoc['filing_time_dup'], $assoc['attachments_dup']);
        $out[] = $assoc;
    }
    return $out;
}

function admin_filings_parse_import_datetime($s) {
    $s = trim((string)$s);
    if ($s === '' || $s === '-') {
        return null;
    }
    $s = str_replace('T', ' ', $s);
    if (preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}$/', $s)) {
        $s .= ':00';
    }
    $ts = strtotime($s);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $ts);
}

function admin_filings_parse_import_date($s) {
    $s = trim((string)$s);
    if ($s === '' || $s === '-') {
        return null;
    }
    $s = str_replace('T', ' ', $s);
    $ts = strtotime($s);
    if ($ts === false) {
        return null;
    }
    return date('Y-m-d', $ts);
}

/** 认购状态文案 → sub_stages 片段（不含已成交整单） */
function admin_filings_sub_stages_from_subscribe_text($sub) {
    $sub = trim((string)$sub);
    if ($sub === '' || $sub === '-') {
        return '';
    }
    if (mb_strpos($sub, '已认购/已成交') !== false) {
        return 'sign';
    }
    if (mb_strpos($sub, '已认购') !== false && mb_strpos($sub, '未认购') === false) {
        return 'sign';
    }
    if (mb_strpos($sub, '锁筹') !== false) {
        return 'lock';
    }
    if (mb_strpos($sub, '已交定') !== false || mb_strpos($sub, '交定') !== false) {
        return 'deposit';
    }
    return '';
}

/** 根据导入列推导 status / visit_type / sub_stages */
function admin_filings_import_status_bundle(array $assoc) {
    $rep = trim((string)($assoc['report_status'] ?? ''));
    $vis = trim((string)($assoc['visit_status'] ?? ''));
    $sub = trim((string)($assoc['subscribe_status'] ?? ''));
    $subStages = admin_filings_sub_stages_from_subscribe_text($sub);

    if ($vis === '无效到访' || mb_strpos($vis, '无效到访') !== false) {
        return [5, 2, $subStages];
    }
    if ($vis === '重复到访' || mb_strpos($vis, '重复到访') !== false) {
        return [5, 3, $subStages];
    }
    if (mb_strpos($sub, '已认购/已成交') !== false || (mb_strpos($sub, '已成交') !== false && mb_strpos($sub, '未') === false && mb_strpos($sub, '未成交') === false)) {
        return [4, 0, 'sign'];
    }
    if ($vis === '有效到访' || mb_strpos($vis, '有效到访') !== false) {
        return [2, 0, $subStages];
    }
    if (mb_strpos($sub, '已认购') !== false && mb_strpos($sub, '未认购') === false) {
        return [3, 0, 'sign'];
    }
    if (mb_strpos($sub, '锁筹') !== false) {
        return [3, 0, 'lock'];
    }
    if (mb_strpos($sub, '已交定') !== false || mb_strpos($sub, '交定') !== false) {
        return [3, 0, 'deposit'];
    }
    if (mb_strpos($rep, '无效报备') !== false) {
        return [6, 0, $subStages];
    }
    if (mb_strpos($rep, '待审核') !== false || mb_strpos($rep, '未审核') !== false || mb_strpos($rep, '报备未审核') !== false) {
        return [0, 0, $subStages];
    }
    if (mb_strpos($rep, '驳回') !== false || mb_strpos($rep, '失效') !== false) {
        return [5, 0, $subStages];
    }
    return [1, 0, $subStages];
}

function admin_filings_client_intention_from_text($s) {
    $s = trim((string)$s);
    $map = ['一般' => 1, '中等' => 2, '较强' => 3, '强烈' => 4];
    return $map[$s] ?? null;
}

// [API] 导入 XLS/XLSX/CSV（表头与导出一致；创建时间=报备时间写入 created_at）
if ($action === 'import_xls') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['status' => 'error', 'msg' => '请使用 POST 上传文件']);
        exit;
    }
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'] ?? '')) {
        echo json_encode(['status' => 'error', 'msg' => '请选择文件']);
        exit;
    }
    $tmp = $_FILES['file']['tmp_name'];
    $orig = (string)($_FILES['file']['name'] ?? '');
    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $head = @file_get_contents($tmp, false, null, 0, 4096);
    if ($head === false) {
        echo json_encode(['status' => 'error', 'msg' => '无法读取上传文件']);
        exit;
    }
    $matrix = null;
    $parseErr = '';
    if (strncmp($head, "PK\x03\x04", 4) === 0 || $ext === 'xlsx') {
        $matrix = admin_filings_xlsx_to_matrix($tmp, $parseErr);
    } elseif ($ext === 'csv') {
        $raw = file_get_contents($tmp);
        if ($raw === false) {
            $parseErr = '读取 CSV 失败';
        } else {
            if (strncmp($raw, "\xEF\xBB\xBF", 3) === 0) {
                $raw = substr($raw, 3);
            }
            $lines = preg_split("/\r\n|\n|\r/", $raw);
            $matrix = [];
            foreach ($lines as $line) {
                if ($line === '') {
                    continue;
                }
                $matrix[] = str_getcsv($line);
            }
        }
    } else {
        $isHtml = (stripos($head, '<table') !== false || stripos($head, '<html') !== false || stripos($head, '<tr') !== false);
        if ($isHtml || $ext === 'xls' || $ext === 'htm' || $ext === 'html') {
            $full = file_get_contents($tmp);
            if ($full === false) {
                $parseErr = '读取文件失败';
            } else {
                $matrix = admin_filings_html_table_to_matrix($full, $parseErr);
            }
        } else {
            echo json_encode([
                'status' => 'error',
                'msg' => '无法解析该文件。Excel 2003 二进制 .xls 请用 Excel「另存为」.xlsx 后上传；或上传本页「导出 XLS」的文件；或 CSV（UTF-8）。',
            ]);
            exit;
        }
    }
    if ($matrix === null) {
        echo json_encode(['status' => 'error', 'msg' => $parseErr ?: '解析失败']);
        exit;
    }
    $rows = admin_filings_matrix_to_assoc_rows($matrix, $parseErr);
    if ($rows === null) {
        echo json_encode(['status' => 'error', 'msg' => $parseErr]);
        exit;
    }
    if (empty($rows)) {
        echo json_encode(['status' => 'error', 'msg' => '没有数据行']);
        exit;
    }

    $ok = 0;
    $fail = 0;
    $errors = [];
    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO filings (
                project_id, agent_id, company_name, follower, broker_name, broker_phone, broker_num,
                client_name, client_phone, client_num, visit_time, designated_sales,
                remark, status, status_log, visit_type, sub_stages,
                salesperson, subscription_date, client_intention,
                raw_input_text, created_at
            ) VALUES (
                ?, 0, ?, ?, ?, ?, 1,
                ?, ?, 1, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, ?,
                ?, ?
            )'
        );
        $dupStmt = $pdo->prepare(
            'SELECT id FROM filings WHERE project_id = ? AND client_phone = ? AND broker_phone = ? AND DATE(created_at) = DATE(?) LIMIT 1'
        );
        $projStmtExact = $pdo->prepare('SELECT id FROM projects WHERE is_deleted = 0 AND name = ? LIMIT 1');
        $projStmtLike = $pdo->prepare('SELECT id FROM projects WHERE is_deleted = 0 AND name LIKE ? ORDER BY LENGTH(name) ASC LIMIT 1');

        foreach ($rows as $idx => $assoc) {
            $excelRow = $idx + 2;
            try {
                $pname = trim((string)($assoc['project_name'] ?? ''));
                if ($pname === '') {
                    throw new Exception('项目名称为空');
                }
                $projStmtExact->execute([$pname]);
                $pid = $projStmtExact->fetchColumn();
                if (!$pid) {
                    $projStmtLike->execute([$pname . '%']);
                    $pid = $projStmtLike->fetchColumn();
                }
                if (!$pid) {
                    throw new Exception('项目不存在: ' . $pname);
                }
                $cname = trim((string)($assoc['client_name'] ?? ''));
                $cphone = trim((string)($assoc['client_phone'] ?? ''));
                if ($cname === '') {
                    throw new Exception('客户姓名为空');
                }
                if ($cphone === '' || $cphone === '-') {
                    throw new Exception('客户号码为空');
                }
                $bname = trim((string)($assoc['broker_name'] ?? ''));
                $bphone = trim((string)($assoc['broker_phone'] ?? ''));
                if ($bname === '') {
                    throw new Exception('经纪人为空');
                }
                if ($bphone === '' || $bphone === '-') {
                    throw new Exception('经纪人号码为空');
                }

                $createdAt = admin_filings_parse_import_datetime($assoc['created_at'] ?? '');
                if ($createdAt === null) {
                    $createdAt = date('Y-m-d H:i:s');
                }
                $dupStmt->execute([(int)$pid, $cphone, $bphone, $createdAt]);
                if ($dupStmt->fetchColumn()) {
                    throw new Exception('同日同项目同客户电话与经纪人电话已存在，跳过');
                }

                list($st, $vType, $subStages) = admin_filings_import_status_bundle($assoc);
                $visitRaw = trim((string)($assoc['visit_time'] ?? ''));
                $visitDt = admin_filings_parse_import_datetime($visitRaw);
                if ($visitDt !== null) {
                    $visitTime = $visitDt;
                } else {
                    $visitTime = substr($createdAt, 0, 10) . ' 00:00:00';
                }

                $follower = admin_follower_normalize_saved((string)($assoc['follower'] ?? ''));
                $remarkFb = trim((string)($assoc['broker_feedback'] ?? ''));
                if (preg_match('/^报备备注[:：]\s*/u', $remarkFb)) {
                    $remarkFb = trim(preg_replace('/^报备备注[:：]\s*/u', '', $remarkFb));
                }
                $remark = $remarkFb;

                $salesFb = trim((string)($assoc['sales_feedback'] ?? ''));
                $__an = trim((string)($_SESSION['admin_name'] ?? ''));
                if ($__an === '') {
                    $__an = '管理员';
                }
                $__an = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__an);
                $log = "\n" . date('Y-m-d H:i') . " [管理员·{$__an}] Excel导入建档";
                if ($salesFb !== '') {
                    $log .= "\n" . date('Y-m-d H:i') . " [管理员·{$__an}] 导入销售反馈: " . $salesFb;
                }

                $dealDate = admin_filings_parse_import_date((string)($assoc['deal_time'] ?? ''));
                $subDate = $dealDate;
                $ci = admin_filings_client_intention_from_text((string)($assoc['customer_opinion'] ?? ''));
                $salesperson = trim((string)($assoc['salesperson'] ?? ''));
                if ($salesperson === '' || $salesperson === '-') {
                    $salesperson = null;
                }

                $raw = '[Excel导入] ' . json_encode($assoc, JSON_UNESCAPED_UNICODE);

                $ins->execute([
                    (int)$pid,
                    trim((string)($assoc['company_name'] ?? '')),
                    $follower,
                    $bname,
                    $bphone,
                    $cname,
                    $cphone,
                    $visitTime,
                    '',
                    $remark,
                    $st,
                    $log,
                    $vType,
                    $subStages,
                    $salesperson,
                    $subDate,
                    $ci,
                    $raw,
                    $createdAt,
                ]);
                $ok++;
            } catch (Throwable $e) {
                $fail++;
                $errors[] = ['row' => $excelRow, 'msg' => $e->getMessage()];
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }

    echo json_encode([
        'status' => 'success',
        'ok' => $ok,
        'fail' => $fail,
        'errors' => $errors,
        'msg' => "成功 {$ok} 条" . ($fail ? "，失败 {$fail} 条" : ''),
    ]);
    exit;
}

if ($action == 'get_projects') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT id, name FROM projects WHERE is_deleted=0 ORDER BY sort_order ASC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows);
    exit;
}

// [API] 渠道跟进人下拉选项（公司默认跟进人 + 历史报备 + 渠道经纪人）
if ($action == 'get_follower_options') {
    header('Content-Type: application/json; charset=utf-8');
    $names = [];
    $push = function ($s) use (&$names) {
        $s = trim((string)$s);
        if ($s === '' || $s === '-' || $s === '空') {
            return;
        }
        if (admin_follower_is_pool_placeholder($s)) {
            return;
        }
        $names[$s] = true;
    };
    try {
        $stmt = $pdo->query("SELECT DISTINCT follower FROM companies WHERE follower IS NOT NULL AND TRIM(follower) <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $push($v);
        }
    } catch (Throwable $e) { /* ignore */ }
    try {
        $stmt = $pdo->query("SELECT DISTINCT follower FROM filings WHERE follower IS NOT NULL AND TRIM(follower) <> '' LIMIT 800");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $push($v);
        }
    } catch (Throwable $e) { /* ignore */ }
    try {
        $chSql = agent_sql_has_role('a', 'channel');
        $stmt = $pdo->query("SELECT DISTINCT username FROM agents a WHERE {$chSql} AND a.is_deleted = 0 AND TRIM(a.username) <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $v) {
            $push($v);
        }
    } catch (Throwable $e) { /* ignore */ }
    $list = array_keys($names);
    sort($list, SORT_STRING);
    echo json_encode(['status' => 'success', 'options' => $list]);
    exit;
}

// [API] 将库内公池类占位跟进人统一清空（幂等；POST 一次即可）
if ($action == 'normalize_pool_followers') {
    header('Content-Type: application/json; charset=utf-8');
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        echo json_encode(['status' => 'error', 'msg' => '请使用 POST']);
        exit;
    }
    $labels = ['公池', '公共池', '公池（无跟进人）', '公池(无跟进人)', '公共池（无跟进人）', '公共池(无跟进人)'];
    $filingsUpdated = 0;
    $companiesUpdated = 0;
    try {
        foreach ($labels as $lab) {
            $st = $pdo->prepare('UPDATE filings SET follower = ? WHERE TRIM(follower) = ?');
            $st->execute(['', $lab]);
            $filingsUpdated += $st->rowCount();
        }
        foreach ($labels as $lab) {
            $st = $pdo->prepare('UPDATE companies SET follower = ? WHERE TRIM(follower) = ?');
            $st->execute(['', $lab]);
            $companiesUpdated += $st->rowCount();
        }
        echo json_encode([
            'status' => 'success',
            'msg' => '已统一：公池类文案已清空为无跟进人',
            'filings_updated' => $filingsUpdated,
            'companies_updated' => $companiesUpdated,
        ]);
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
    }
    exit;
}

// [API] 快捷修改渠道跟进人
if ($action == 'update_follower') {
    header('Content-Type: application/json; charset=utf-8');
    $id = intval($_POST['id'] ?? 0);
    $follower = admin_follower_normalize_saved((string)($_POST['follower'] ?? ''));
    if ($id <= 0) {
        echo json_encode(['status' => 'error', 'msg' => '参数错误']);
        exit;
    }
    $oldStmt = $pdo->prepare('SELECT id, follower, status_log FROM filings WHERE id = ? LIMIT 1');
    $oldStmt->execute([$id]);
    $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) {
        echo json_encode(['status' => 'error', 'msg' => '记录不存在']);
        exit;
    }
    $rawOldFollower = trim((string)($old['follower'] ?? ''));
    $oldFollower = admin_follower_normalize_saved((string)($old['follower'] ?? ''));
    if ($oldFollower === $follower) {
        echo json_encode(['status' => 'success']);
        exit;
    }
    $__an = trim((string)($_SESSION['admin_name'] ?? ''));
    if ($__an === '') $__an = '管理员';
    $__an = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__an);
    $fromText = $rawOldFollower !== '' ? $rawOldFollower : '空';
    $toText = $follower !== '' ? $follower : '空';
    $log = "\n" . date('Y-m-d H:i') . " [管理员·{$__an}] 修改渠道跟进人: {$fromText} -> {$toText}";
    $pdo->prepare("UPDATE filings SET follower = ?, status_log = CONCAT(IFNULL(status_log,''), ?) WHERE id = ?")->execute([$follower, $log, $id]);
    echo json_encode(['status' => 'success']);
    exit;
}

// [API] 获取报备列表 (含分页与筛选)
if ($action == 'get_filings') {
    header('Content-Type: application/json');
    
    // 参数
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    list($where, $params) = build_filing_where_and_params($_GET);

    // 查总数
    $countStmt = $pdo->prepare("SELECT count(*) FROM filings f LEFT JOIN projects p ON f.project_id = p.id LEFT JOIN agents a ON f.agent_id = a.id $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    // 查数据
    $sql = "SELECT f.*, p.name as project_name, 
            COALESCE(NULLIF(f.broker_name,''), a.username) as display_broker_name,
            COALESCE(NULLIF(f.broker_phone,''), a.phone) as display_broker_phone
            FROM filings f 
            LEFT JOIN projects p ON f.project_id = p.id 
            LEFT JOIN agents a ON f.agent_id = a.id
            $where 
            ORDER BY f.id DESC LIMIT $limit OFFSET $offset";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['code'=>0, 'count'=>$total, 'data'=>$list]); 
    exit;
}

// [API] 快捷审核状态
if ($action == 'audit_status') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    $status = $_POST['status']; // 1=有效, 5=无效/驳回
    $reason = $_POST['reason'] ?? '';
    
    // 记录日志
    $statusText = ($status == 1) ? '审核通过' : '审核驳回';
    $__an = trim((string)($_SESSION['admin_name'] ?? ''));
    if ($__an === '') $__an = '管理员';
    $__an = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__an);
    $log = "\n" . date('Y-m-d H:i') . " [管理员·{$__an}] " . $statusText . ($reason ? " (原因: $reason)" : "");
    
    $sql = "UPDATE filings SET status = ?, status_log = CONCAT(IFNULL(status_log,''), ?) WHERE id = ?";
    $pdo->prepare($sql)->execute([$status, $log, $id]);
    
    echo json_encode(['status'=>'success']);
    exit;
}

// [API] 导出 XLS
if ($action == 'export_xls') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="报备数据_' . date('YmdHis') . '.xls"');
    header('Pragma: no-cache');
    header('Expires: 0');
    
    list($where, $params) = build_filing_where_and_params($_GET);
    
    $sql = "SELECT f.*, p.name as project_name,
            COALESCE(NULLIF(f.broker_name,''), a.username) AS display_broker_name,
            COALESCE(NULLIF(f.broker_phone,''), a.phone) AS display_broker_phone
            FROM filings f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN agents a ON f.agent_id = a.id
            $where
            ORDER BY f.id DESC";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $extractSalesRemarks = function ($statusLog) {
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
    };
    // 经纪人反馈：仅导出报备备注；status_log 内「第1/2/3次跟进」不在此列导出（按业务要求）
    $extractBrokerFeedback = function ($row) {
        $remark = trim((string)($row['remark'] ?? ''));
        if ($remark === '' || $remark === '-' || $remark === '空') {
            return '';
        }
        return '报备备注: ' . $remark;
    };
    
    echo "<table border='1' cellspacing='0' cellpadding='4' style='border-collapse:collapse;font-size:12px;'>";
    echo "<tr>";
    echo "<th>项目名称</th>";
    echo "<th>创建时间</th>";
    echo "<th>客户姓名</th>";
    echo "<th>客户号码</th>";
    echo "<th>经纪公司</th>";
    echo "<th>经纪人</th>";
    echo "<th>经纪人号码</th>";
    echo "<th>到访时间</th>";
    echo "<th>报备状态</th>";
    echo "<th>到访状态</th>";
    echo "<th>认购状态</th>";
    echo "<th>成交时间</th>";
    echo "<th>渠道跟进人</th>";
    echo "<th>现场销售</th>";
    echo "<th>经纪人反馈</th>";
    echo "<th>销售反馈</th>";
    echo "<th>客户意见</th>";
    echo "<th>报备时间</th>";
    echo "<th>图片附件</th>";
    echo "</tr>";
    
    foreach ($list as $row) {
        $subStages = (string)($row['sub_stages'] ?? '');
        $subscribeStatus = '未认购';
        if ((int)($row['status'] ?? 0) === 4) {
            $subscribeStatus = '已认购/已成交';
        } elseif (strpos($subStages, 'sign') !== false) {
            $subscribeStatus = '已认购';
        } elseif (strpos($subStages, 'lock') !== false) {
            $subscribeStatus = '锁筹中';
        } elseif (strpos($subStages, 'deposit') !== false) {
            $subscribeStatus = '已交定';
        }
        $dealTime = $row['transaction_time'] ?? ($row['subscription_date'] ?? '');
        $clientIntentionMap = [1 => '一般', 2 => '中等', 3 => '较强', 4 => '强烈'];
        $clientIntentionText = $clientIntentionMap[(int)($row['client_intention'] ?? 0)] ?? '';
        echo "<tr>";
        echo "<td>" . ($row['project_name'] ?? '') . "</td>";
        echo "<td>" . ($row['created_at'] ?? '') . "</td>";
        echo "<td>" . ($row['client_name'] ?? '') . "</td>";
        echo "<td>" . admin_mask_phone_for_export($row['client_phone'] ?? '') . "</td>";
        echo "<td>" . ($row['company_name'] ?? '') . "</td>";
        echo "<td>" . ($row['display_broker_name'] ?? ($row['broker_name'] ?? '')) . "</td>";
        $exportBrokerPhone = trim((string)($row['display_broker_phone'] ?? ''));
        echo "<td>" . ($exportBrokerPhone !== '' ? $exportBrokerPhone : '-') . "</td>";
        echo "<td>" . admin_display_visit_time($row) . "</td>";
        echo "<td>" . admin_report_status_text($row) . "</td>";
        echo "<td>" . admin_visit_status_text($row) . "</td>";
        echo "<td>" . $subscribeStatus . "</td>";
        echo "<td>" . ($dealTime ?? '') . "</td>";
        $exportFollower = trim((string)($row['follower'] ?? ''));
        echo "<td>" . ($exportFollower !== '' ? $exportFollower : '公池') . "</td>";
        echo "<td>" . ($row['salesperson'] ?? '') . "</td>";
        echo "<td>" . $extractBrokerFeedback($row) . "</td>";
        $salesFeedbackExport = $extractSalesRemarks($row['status_log'] ?? '');
        echo "<td>" . $salesFeedbackExport . "</td>";
        // 客户意见：仅当销售反馈非空时导出客户意向；销售反馈空白则本列为空
        $clientOpinionExport = ($salesFeedbackExport !== '') ? $clientIntentionText : '';
        echo "<td>" . $clientOpinionExport . "</td>";
        echo "<td>" . $row['created_at'] . "</td>";
        echo "<td>" . ($row['attachments'] ?? '') . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit;
}

// [API] 删除记录
if ($action == 'delete') {
    header('Content-Type: application/json');
    $id = $_POST['id'];
    
    $stmt = $pdo->prepare("DELETE FROM filings WHERE id = ?");
    $stmt->execute([$id]);
    
    echo json_encode(['status'=>'success']);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报备审核管理</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: sans-serif; }
        .nav-item.active { background: #2563eb; color: white; box-shadow: 0 4px 6px -1px rgba(37, 99, 235, 0.5); }
        /* 状态标签色 */
        .status-0 { @apply bg-gray-100 text-gray-500 border-gray-200; }
        .status-1 { @apply bg-blue-50 text-blue-600 border-blue-200; }
        .status-2 { @apply bg-yellow-50 text-yellow-600 border-yellow-200; }
        .status-3 { @apply bg-orange-50 text-orange-600 border-orange-200; }
        .status-4 { @apply bg-green-50 text-green-600 border-green-200; }
        .status-5 { @apply bg-red-50 text-red-500 border-red-200; }
        /* 时间轴 */
        .timeline-item { position: relative; padding-left: 20px; padding-bottom: 25px; border-left: 2px solid #e2e8f0; }
        .timeline-item:last-child { border-left: 2px solid transparent; }
        .timeline-dot { position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; border: 2px solid #fff; }
        .timeline-dot.active { background: #3b82f6; }
        /* 分页（页码高亮用模板内 Tailwind 类，CDN 不编译此处 @apply） */
        .page-btn { padding: 0.25rem 0.75rem; border: 1px solid #e5e7eb; border-radius: 0.25rem; background: #fff; color: #4b5563; font-size: 0.875rem; transition: background 0.15s, opacity 0.15s; }
        .page-btn:hover:not(:disabled) { background: #f9fafb; }
        .page-btn:disabled { opacity: 0.45; cursor: not-allowed; }
        /* 报备列表：控制列宽、长文折行 */
        .filings-scroll { -webkit-overflow-scrolling: touch; scrollbar-gutter: stable; }
        .filings-table { min-width: 1180px; width: max(100%, 1180px); border-collapse: separate; border-spacing: 0; }
        .filings-table thead th { letter-spacing: 0.02em; box-shadow: inset 0 -1px 0 #e5e7eb; }
        .filings-table tbody td { vertical-align: top; }
        .filings-clamp-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            word-break: break-word;
        }
    </style>
</head>
<body>
<div id="app" class="flex h-screen overflow-hidden">
    
    <?php include 'sidebar.php'; ?>

    <main class="flex-1 flex flex-col overflow-hidden bg-slate-50">
        <header class="h-16 bg-white border-b border-gray-200 flex items-center justify-between px-8 shadow-sm flex-shrink-0">
            <div class="flex items-center gap-4">
                <button @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500"><i class="fas fa-bars"></i></button>
                <h2 class="text-lg font-bold text-slate-800">报备审核管理</h2>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-sm text-gray-500">共 {{ pagination.total }} 条记录</span>
                <button @click="loadData" class="text-blue-600 hover:bg-blue-50 px-3 py-1 rounded transition"><i class="fas fa-sync-alt mr-1"></i> 刷新</button>
                <input ref="importFileInput" type="file" accept=".xlsx,.xls,.csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,text/csv" class="hidden" @change="onImportFile">
                <button type="button" @click="triggerImport" :disabled="importBusy" class="bg-amber-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-amber-700 transition disabled:opacity-50 disabled:cursor-not-allowed"><i class="fas fa-file-upload mr-1"></i> {{ importBusy ? '导入中…' : '导入' }}</button>
                <button @click="exportXls" class="bg-green-600 text-white px-4 py-1.5 rounded-lg text-sm hover:bg-green-700 transition"><i class="fas fa-file-excel mr-1"></i> 导出XLS</button>
            </div>
        </header>

        <div class="flex-1 overflow-hidden p-4 md:p-6 flex flex-col min-h-0 gap-4">
            
            <div class="bg-white p-4 rounded-xl shadow-sm border border-gray-100 shrink-0">
                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-12 gap-3 items-end">
                    <div class="relative lg:col-span-3">
                        <label class="block text-[10px] font-bold text-gray-400 mb-1">综合</label>
                        <i class="fas fa-search absolute left-3 bottom-2.5 text-gray-400 text-sm pointer-events-none"></i>
                        <input v-model="filters.kw" @keyup.enter="handleSearch" class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-100 outline-none" placeholder="客户 / 客户电话(支持137****5815) / 项目 / 经纪人 / 经纪人电话">
                    </div>
                    <div class="relative lg:col-span-3">
                        <label class="block text-[10px] font-bold text-gray-400 mb-1">报备公司</label>
                        <i class="fas fa-building absolute left-3 bottom-2.5 text-gray-400 text-sm pointer-events-none"></i>
                        <input v-model="filters.company_kw" @keyup.enter="handleSearch" class="w-full pl-9 pr-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-blue-100 outline-none" placeholder="公司全称关键词">
                    </div>
                    <div class="lg:col-span-2">
                        <label class="block text-[10px] font-bold text-gray-400 mb-1">渠道跟进人</label>
                        <select v-model="filters.follower_kw" @change="handleSearch" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm outline-none bg-white focus:ring-2 focus:ring-blue-100 min-w-0">
                            <option value="">全部</option>
                            <option value="__pool__">公池</option>
                            <option v-for="(opt, idx) in followerOptions" :key="'ff_' + idx + '_' + opt" :value="opt">{{ opt }}</option>
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="block text-[10px] font-bold text-gray-400 mb-1">项目</label>
                        <select v-model="filters.project_id" @change="handleSearch" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm outline-none bg-white focus:ring-2 focus:ring-blue-100 min-w-0">
                            <option value="">全部项目</option>
                            <option v-for="p in projects" :key="p.id" :value="String(p.id)">{{ p.name }}</option>
                        </select>
                    </div>
                    <div class="lg:col-span-2">
                        <label class="block text-[10px] font-bold text-gray-400 mb-1">状态</label>
                        <select v-model="filters.status" @change="handleSearch" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-sm outline-none bg-white focus:ring-2 focus:ring-blue-100 min-w-0">
                            <option value="">全部状态</option>
                            <option value="pending_review">⏳ 报备未审核</option>
                            <option value="unvisited_report">📄 报备未带看</option>
                            <option value="0">⏳ 报备未审核(原值)</option>
                            <option value="1">✅ 报备未带看(原值)</option>
                            <option value="2">👣 已到访</option>
                            <option value="3">📝 已认筹</option>
                            <option value="4">💰 已成交</option>
                            <option value="5">❌ 已失效/驳回</option>
                        </select>
                    </div>
                    <div class="flex gap-2 sm:col-span-2 lg:col-span-2">
                        <div class="flex-1 min-w-0">
                            <label class="block text-[10px] font-bold text-gray-400 mb-1">开始</label>
                            <input v-model="filters.date_start" type="date" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-xs outline-none bg-white focus:ring-2 focus:ring-blue-100">
                        </div>
                        <div class="flex-1 min-w-0">
                            <label class="block text-[10px] font-bold text-gray-400 mb-1">结束</label>
                            <input v-model="filters.date_end" type="date" class="w-full border border-gray-200 rounded-lg px-2 py-2 text-xs outline-none bg-white focus:ring-2 focus:ring-blue-100">
                        </div>
                    </div>
                    <div class="sm:col-span-2 lg:col-span-1 flex justify-end">
                        <button type="button" @click="handleSearch" class="w-full sm:w-auto bg-blue-600 text-white px-5 py-2 rounded-lg text-sm font-bold hover:bg-blue-700 transition shadow-sm">查询</button>
                    </div>
                </div>
            </div>

            <div class="flex-1 min-h-0 flex flex-col bg-white rounded-xl shadow-sm border border-gray-200 overflow-hidden">
                <div class="filings-scroll overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="filings-table text-left text-gray-600 text-xs">
                    <thead class="bg-slate-50 text-[10px] font-bold text-slate-600 sticky top-0 z-10">
                        <tr>
                            <th class="px-2.5 py-2.5 w-[6.5rem] max-w-[7.5rem]">报备项目</th>
                            <th class="px-2.5 py-2.5 whitespace-nowrap">报备时间</th>
                            <th class="px-2.5 py-2.5 w-20">客户</th>
                            <th class="px-2.5 py-2.5 w-[5.5rem] whitespace-nowrap">客户号码</th>
                            <th class="px-2.5 py-2.5 min-w-[8rem] max-w-[11rem]">报备公司</th>
                            <th class="px-2.5 py-2.5 w-20">经纪人</th>
                            <th class="px-2.5 py-2.5 w-[6.5rem] whitespace-nowrap">经纪人号码</th>
                            <th class="px-2.5 py-2.5 min-w-[8.5rem]">渠道跟进人</th>
                            <th class="px-2.5 py-2.5 whitespace-nowrap">到访时间</th>
                            <th class="px-2.5 py-2.5 whitespace-nowrap">报备状态</th>
                            <th class="px-2.5 py-2.5 whitespace-nowrap">到访状态</th>
                            <th class="px-2.5 py-2.5 w-[4.5rem]">认购</th>
                            <th class="px-2.5 py-2.5 w-[5rem] max-w-[5.5rem]">认购人</th>
                            <th class="px-2.5 py-2.5 w-14 text-right whitespace-nowrap">面积㎡</th>
                            <th class="px-2.5 py-2.5 w-20 text-right whitespace-nowrap">总价(元)</th>
                            <th class="px-2.5 py-2.5 text-right w-[5.5rem] sticky right-0 z-20 bg-slate-50 shadow-[-4px_0_8px_-4px_rgba(0,0,0,0.08)]">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 bg-white">
                        <tr v-if="list.length===0"><td colspan="16" class="px-6 py-10 text-center text-gray-400">暂无数据</td></tr>
                        <tr v-for="item in list" :key="item.id" class="hover:bg-slate-50/80 transition group">
                            <td class="px-2.5 py-2 max-w-[7.5rem]">
                                <span class="bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded text-[11px] font-bold filings-clamp-2 inline-block align-top max-w-full" :title="item.project_name">{{ item.project_name }}</span>
                            </td>
                            <td class="px-2.5 py-2 text-[11px] text-gray-500 whitespace-nowrap tabular-nums">{{ item.created_at || '-' }}</td>
                            <td class="px-2.5 py-2 font-semibold text-slate-800 max-w-[5rem] filings-clamp-2" :title="item.client_name">{{ item.client_name || '-' }}</td>
                            <td class="px-2.5 py-2 text-[11px] text-gray-500 font-mono whitespace-nowrap">{{ maskPhone(item.client_phone) }}</td>
                            <td class="px-2.5 py-2 max-w-[11rem]">
                                <div class="text-slate-700 text-[11px] leading-snug filings-clamp-2" :title="item.company_name">{{ item.company_name || '-' }}</div>
                            </td>
                            <td class="px-2.5 py-2 text-slate-700 text-[11px] max-w-[5rem] filings-clamp-2" :title="item.display_broker_name">{{ item.display_broker_name || '未知' }}</td>
                            <td class="px-2.5 py-2 text-[11px] text-gray-500 font-mono whitespace-nowrap">{{ item.display_broker_phone || '-' }}</td>
                            <td class="px-2.5 py-2 min-w-[8rem] max-w-[10rem]">
                                <div class="flex flex-col gap-1">
                                    <span class="text-slate-700 text-[11px] leading-tight break-words">{{ (item.follower || '').trim() || '公池' }}</span>
                                    <select
                                        class="text-[10px] border border-dashed border-slate-300 rounded py-0.5 px-1 bg-slate-50 hover:bg-white w-full max-w-full"
                                        title="快捷修改渠道跟进人"
                                        @change="quickSetFollower(item, $event)"
                                    >
                                        <option value="">快捷</option>
                                        <option value="__clear__">清空</option>
                                        <option v-for="opt in followerOptions" :key="'fo_' + item.id + '_' + opt" :value="opt">{{ opt }}</option>
                                    </select>
                                </div>
                            </td>
                            <td class="px-2.5 py-2 text-[11px] text-gray-500 whitespace-nowrap tabular-nums">{{ getVisitTimeDisplay(item) }}</td>
                            <td class="px-2.5 py-2">
                                <span class="px-1.5 py-0.5 rounded-md text-[10px] font-bold border inline-block whitespace-nowrap" :class="'status-'+item.status">{{ getReportStatusText(item) }}</span>
                            </td>
                            <td class="px-2.5 py-2">
                                <span class="px-1.5 py-0.5 rounded-md text-[10px] font-bold border inline-block max-w-[5rem] filings-clamp-2 align-top" :title="getVisitStatusText(item)">{{ getVisitStatusText(item) }}</span>
                            </td>
                            <td class="px-2.5 py-2 text-[10px] text-gray-600 leading-tight">{{ getSubscribeStatus(item) }}</td>
                            <td class="px-2.5 py-2 text-[11px] text-slate-700 max-w-[5.5rem] filings-clamp-2" :title="item.subscriber_name">{{ item.subscriber_name || '-' }}</td>
                            <td class="px-2.5 py-2 text-[11px] text-slate-700 text-right tabular-nums">{{ item.transaction_area || '-' }}</td>
                            <td class="px-2.5 py-2 text-[11px] text-slate-700 text-right tabular-nums whitespace-nowrap">{{ item.deal_price || '-' }}</td>
                            <td class="px-2 py-2 text-right sticky right-0 z-10 bg-white group-hover:bg-slate-50/80 shadow-[-6px_0_10px_-6px_rgba(0,0,0,0.12)] border-l border-slate-100">
                                <div class="flex flex-col items-stretch gap-0.5 min-w-[4.25rem]">
                                    <template v-if="item.status==0">
                                        <button type="button" @click="audit(item.id, 1)" class="text-green-600 hover:bg-green-50 px-1.5 py-0.5 rounded text-[10px] font-bold border border-transparent hover:border-green-200 text-right">✓ 通过</button>
                                        <button type="button" @click="audit(item.id, 5)" class="text-red-500 hover:bg-red-50 px-1.5 py-0.5 rounded text-[10px] font-bold border border-transparent hover:border-red-200 text-right">✗ 驳回</button>
                                    </template>
                                    <button type="button" @click="showTimeline(item)" class="text-gray-500 hover:text-blue-600 text-[10px] font-bold text-right">进度</button>
                                    <button type="button" @click="openDetail(item.id)" class="text-blue-600 hover:underline text-[10px] font-bold text-right">详情</button>
                                    <button type="button" @click="deleteItem(item.id)" class="text-red-600 hover:bg-red-50 px-1 py-0.5 rounded text-[10px] font-bold text-right">删除</button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
                </div>
            </div>

            <div class="bg-white border border-gray-200 rounded-xl shadow-sm p-3 flex flex-wrap justify-between items-center gap-2 mt-auto flex-shrink-0">
                <div class="text-xs text-gray-500">
                    第 <span class="font-bold text-blue-600">{{ pagination.page }}</span> / {{ totalPages }} 页
                </div>
                <div class="flex flex-wrap items-center justify-end gap-1">
                    <button type="button" @click="changePage(pagination.page - 1)" :disabled="pagination.page === 1" class="page-btn">上一页</button>
                    <template v-for="(pg, idx) in visiblePages" :key="'pg-' + idx">
                        <span v-if="pg === 'ellipsis'" class="px-1 text-gray-400 select-none text-sm">…</span>
                        <button
                            v-else
                            type="button"
                            @click="changePage(pg)"
                            class="inline-flex min-w-[2.25rem] items-center justify-center rounded-md border px-2 py-1 text-sm font-bold transition shadow-sm"
                            :class="Number(pagination.page) === Number(pg)
                                ? 'border-blue-700 bg-blue-600 text-white ring-2 ring-blue-400 ring-offset-1'
                                : 'border-gray-200 bg-white text-gray-700 hover:bg-slate-50 hover:border-gray-300'"
                        >{{ pg }}</button>
                    </template>
                    <button type="button" @click="changePage(pagination.page + 1)" :disabled="pagination.page >= totalPages" class="page-btn">下一页</button>
                </div>
            </div>

        </div>
    </main>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm" @click.self="showModal=false">
        <div class="bg-white w-[500px] max-h-[80vh] rounded-xl shadow-2xl flex flex-col overflow-hidden animate-fade-in">
            <div class="p-4 border-b border-gray-100 flex justify-between items-center bg-gray-50">
                <h3 class="font-bold text-slate-800">订单进度详情</h3>
                <button @click="showModal=false" class="text-gray-400 hover:text-slate-800"><i class="fas fa-times"></i></button>
            </div>
            <div class="p-6 overflow-y-auto flex-1">
                <div class="mb-4">
                    <div class="text-sm font-bold text-slate-700">{{ currentItem.client_name }} - {{ currentItem.project_name }}</div>
                    <div class="text-xs text-gray-400 mt-1">报备单号: #{{ currentItem.id }}</div>
                </div>
                <div class="pl-2">
                    <div v-for="(log, idx) in parseLog(currentItem.status_log)" :key="idx" class="timeline-item">
                        <div class="timeline-dot" :class="idx===0?'active':''"></div>
                        <div class="text-xs text-gray-400 mb-1">{{ log.time }}</div>
                        <div class="text-sm font-bold text-slate-700">{{ log.title }}</div>
                        <div v-if="log.desc" class="text-xs text-gray-500 mt-1 bg-gray-50 p-2 rounded">{{ log.desc }}</div>
                        <div v-if="log.imageUrl" class="mt-2">
                            <img
                                :src="log.imageUrl"
                                class="w-24 h-24 object-cover rounded border border-gray-200 cursor-zoom-in hover:opacity-90 transition"
                                @click="openImagePreview(log.imageUrl)"
                            >
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <div v-if="previewImageUrl" class="fixed inset-0 z-[60] bg-black/80 flex items-center justify-center p-6" @click.self="previewImageUrl=''">
        <button @click="previewImageUrl=''" class="absolute top-4 right-4 text-white text-2xl leading-none hover:text-gray-300">&times;</button>
        <img :src="previewImageUrl" class="max-w-full max-h-full rounded shadow-2xl">
    </div>

</div>

<script>
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const sidebarOpen = ref(false);
        const view = ref('filings'); // 用于侧边栏高亮
        const list = ref([]);
        const projects = ref([]);
        const filters = ref({
            kw: <?php echo json_encode((string)($_GET['kw'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            company_kw: <?php echo json_encode((string)($_GET['company_kw'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            follower_kw: <?php echo json_encode($__filings_init_follower_kw, JSON_UNESCAPED_UNICODE); ?>,
            status: <?php echo json_encode((string)($_GET['status'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            project_id: <?php echo json_encode((string)($_GET['project_id'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            date_start: <?php echo json_encode((string)($_GET['date_start'] ?? ''), JSON_UNESCAPED_UNICODE); ?>,
            date_end: <?php echo json_encode((string)($_GET['date_end'] ?? ''), JSON_UNESCAPED_UNICODE); ?>
        });
        const pagination = ref({ page: 1, limit: 20, total: 0 });
        
        const showModal = ref(false);
        const currentItem = ref({});
        const previewImageUrl = ref('');
        const followerOptions = ref([]);
        const importFileInput = ref(null);
        const importBusy = ref(false);

        const totalPages = computed(() => Math.ceil(pagination.value.total / pagination.value.limit) || 1);

        /** 页码列表：当前页附近 + 首尾，多页时用省略号 */
        const visiblePages = computed(() => {
            const total = totalPages.value;
            const cur = pagination.value.page;
            if (total <= 0) return [];
            if (total <= 9) {
                return Array.from({ length: total }, (_, i) => i + 1);
            }
            const set = new Set([1, total, cur, cur - 1, cur + 1, cur - 2, cur + 2]);
            const sorted = [...set].filter((n) => n >= 1 && n <= total).sort((a, b) => a - b);
            const out = [];
            for (let i = 0; i < sorted.length; i++) {
                if (i > 0 && sorted[i] - sorted[i - 1] > 1) {
                    out.push('ellipsis');
                }
                out.push(sorted[i]);
            }
            return out;
        });
        const maskPhone = (phone) => {
            const text = String(phone || '').trim();
            if (!text) return '-';
            const digits = text.replace(/\D/g, '');
            if (digits.length < 7) return text;
            return digits.slice(0, 3) + '****' + digits.slice(-4);
        };
        const getSubscribeStatus = (item) => {
            const stages = String(item.sub_stages || '');
            if (parseInt(item.status, 10) === 4) return '已认购/已成交';
            if (stages.includes('sign')) return '已认购';
            if (stages.includes('lock')) return '锁筹中';
            if (stages.includes('deposit')) return '已交定';
            return '未认购';
        };
        const getReportStatusText = (item) => {
            const status = parseInt(item?.status || 0, 10);
            if (status === 6) return '无效报备';
            if (status >= 1) return '有效报备';
            return '-';
        };
        const getVisitStatusText = (item) => {
            const status = parseInt(item?.status || 0, 10);
            const visitTypeRaw = item?.visit_type;
            const visitType = (visitTypeRaw === null || visitTypeRaw === undefined || visitTypeRaw === '') ? null : parseInt(visitTypeRaw, 10);
            if (status === 2) return '有效到访';
            if (status === 5 && visitType === 2) return '无效到访';
            if (status === 5 && visitType === 3) return '重复到访';
            return '-';
        };
        const getVisitTimeDisplay = (item) => {
            const status = parseInt(item?.status || 0, 10);
            const visitTypeRaw = item?.visit_type;
            const visitType = (visitTypeRaw === null || visitTypeRaw === undefined || visitTypeRaw === '') ? null : parseInt(visitTypeRaw, 10);
            const raw = String(item?.visit_time || '').trim();
            if (!raw || raw === '0000-00-00 00:00:00') return '-';
            if (status === 2) return raw;
            if (status === 5 && (visitType === 2 || visitType === 3)) return raw;
            return '-';
        };

        const loadData = async () => {
            const params = new URLSearchParams({
                action: 'get_filings',
                page: pagination.value.page,
                limit: pagination.value.limit,
                kw: filters.value.kw,
                company_kw: filters.value.company_kw,
                follower_kw: filters.value.follower_kw,
                status: filters.value.status,
                project_id: filters.value.project_id,
                date_start: filters.value.date_start,
                date_end: filters.value.date_end
            });
            const res = await fetch('?' + params.toString());
            const json = await res.json();
            list.value = json.data;
            pagination.value.total = parseInt(json.count);
        };

        const handleSearch = () => { pagination.value.page = 1; loadData(); };
        const changePage = (p) => { if(p>=1 && p<=totalPages.value) { pagination.value.page = p; loadData(); } };

        const audit = async (id, status) => {
            let reason = '';
            if (status === 5) {
                reason = prompt("请输入驳回/无效原因 (选填):");
                if (reason === null) return; // 取消
            } else {
                if(!confirm('确定审核通过该报备吗？')) return;
            }

            const fd = new FormData();
            fd.append('id', id);
            fd.append('status', status);
            fd.append('reason', reason);

            const res = await fetch('?action=audit_status', {method:'POST', body:fd});
            const d = await res.json();
            if (d.status === 'success') loadData();
        };

        const showTimeline = (item) => { currentItem.value = item; showModal.value = true; };
        
        const parseLog = (logStr) => {
            if(!logStr) return [];
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
                const imageMatch = body.match(/(https?:\/\/\S+\.(?:jpg|jpeg|png|gif|webp)|\/uploads\/\S+\.(?:jpg|jpeg|png|gif|webp))/i);
                const imageUrl = imageMatch ? imageMatch[1] : '';
                const fullImageUrl = imageUrl
                    ? (imageUrl.startsWith('http') ? imageUrl : (window.location.origin + imageUrl))
                    : '';
                return { 
                    time: timePart, 
                    title: body?.split(' (')[0] || body, 
                    desc: body?.includes('(') ? body.split('(')[1].replace(')', '') : '',
                    imageUrl: fullImageUrl
                };
            }).reverse();
        };
        const openImagePreview = (url) => {
            previewImageUrl.value = url || '';
        };

        const openDetail = (id) => {
            window.open('admin_filing_edit.php?id=' + id, 'filing_edit', 'width=800,height=700');
        };

        const exportXls = () => {
            const params = new URLSearchParams({
                action: 'export_xls',
                kw: filters.value.kw,
                company_kw: filters.value.company_kw,
                follower_kw: filters.value.follower_kw,
                status: filters.value.status,
                project_id: filters.value.project_id,
                date_start: filters.value.date_start,
                date_end: filters.value.date_end
            });
            window.location.href = '?' + params.toString();
        };

        const triggerImport = () => {
            if (importBusy.value) return;
            importFileInput.value && importFileInput.value.click();
        };

        const onImportFile = async (e) => {
            const input = e.target;
            const file = input.files && input.files[0];
            input.value = '';
            if (!file) return;
            importBusy.value = true;
            try {
                const fd = new FormData();
                fd.append('file', file);
                const res = await fetch('?action=import_xls', { method: 'POST', body: fd });
                const d = await res.json();
                if (d.status === 'success') {
                    let msg = d.msg || ('成功 ' + (d.ok || 0) + ' 条');
                    if ((d.fail || 0) > 0 && Array.isArray(d.errors) && d.errors.length) {
                        const lines = d.errors.slice(0, 8).map((x) => '第' + x.row + '行: ' + x.msg);
                        if (d.errors.length > 8) lines.push('…共 ' + d.errors.length + ' 条失败明细');
                        msg += '\n\n' + lines.join('\n');
                    }
                    alert(msg);
                    loadData();
                } else {
                    alert(d.msg || '导入失败');
                }
            } catch (err) {
                alert('导入请求失败');
            } finally {
                importBusy.value = false;
            }
        };

        const loadProjects = async () => {
            const res = await fetch('?action=get_projects');
            const json = await res.json();
            projects.value = Array.isArray(json) ? json : [];
        };

        const loadFollowerOptions = async () => {
            try {
                const res = await fetch('?action=get_follower_options');
                const d = await res.json();
                followerOptions.value = Array.isArray(d.options) ? d.options : [];
            } catch (e) {
                followerOptions.value = [];
            }
        };

        const quickSetFollower = async (item, event) => {
            const sel = event && event.target;
            if (!sel) return;
            const v = sel.value;
            sel.selectedIndex = 0;
            if (v === '') return;
            const follower = v === '__clear__' ? '' : v;
            const cur = String(item.follower || '').trim();
            if (follower === cur) return;
            const fd = new FormData();
            fd.append('id', item.id);
            fd.append('follower', follower);
            try {
                const res = await fetch('?action=update_follower', { method: 'POST', body: fd });
                const d = await res.json();
                if (d.status === 'success') {
                    item.follower = follower;
                } else {
                    alert(d.msg || '保存失败');
                }
            } catch (e) {
                alert('网络错误');
            }
        };

        const deleteItem = async (id) => {
            if (!confirm('确定要删除这条记录吗？此操作不可恢复！')) return;
            
            const fd = new FormData();
            fd.append('id', id);
            
            const res = await fetch('?action=delete', {method:'POST', body:fd});
            const d = await res.json();
            if (d.status === 'success') {
                alert('删除成功');
                loadData();
            } else {
                alert('删除失败');
            }
        };

        onMounted(() => { loadData(); loadProjects(); loadFollowerOptions(); });

        return {
            sidebarOpen, view, list, filters, projects, pagination, totalPages, visiblePages,
            showModal, currentItem, previewImageUrl, followerOptions,
            importFileInput, importBusy,
            loadData, handleSearch, changePage, audit, showTimeline, parseLog, openDetail, exportXls, triggerImport, onImportFile, deleteItem, getSubscribeStatus, getVisitTimeDisplay, getReportStatusText, getVisitStatusText, openImagePreview, maskPhone,
            quickSetFollower
        };
    }
}).mount('#app');
</script>
</body>
</html>
