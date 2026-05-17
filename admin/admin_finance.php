<?php
// admin_finance.php - 佣金结算独立管理页 (修复版)
// 开启输出缓冲，防止 PHP 警告/错误破坏 JSON 数据结构
ob_start();

session_start();
header('Content-Type: text/html; charset=utf-8');

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

// === 调试设置 ===
// 默认开启错误显示，但在 API 响应时我们会自动关闭它
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// === 数据库配置 ===
$host = '127.0.0.1';
$db = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

$debug_info = []; // 全局调试数组

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $debug_info['db_status'] = "数据库连接成功";
} catch (PDOException $e) {
    // 如果是 API 请求，返回 JSON 错误
    if (isset($_GET['action'])) {
        ob_clean();
        header('Content-Type: application/json');
        echo json_encode(['status' => 'error', 'message' => "数据库连接失败: " . $e->getMessage()]);
        exit;
    }
    die("数据库连接失败: " . $e->getMessage());
}

require_once dirname(__DIR__) . '/includes/staff_scope_sql.php';
require_once dirname(__DIR__) . '/includes/agent_roles.php';

/** 与案场 staff 一致：确保佣金套餐表存在 */
function admin_finance_ensure_project_commission_packages_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS project_commission_packages (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            project_id INT UNSIGNED NOT NULL,
            package_name VARCHAR(191) NOT NULL DEFAULT '' COMMENT '套餐名称',
            commission_pct DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '佣金比例%',
            cash_reward DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '现金奖励',
            jump_ratio DECIMAL(10,4) NOT NULL DEFAULT 0 COMMENT '跳点比例%',
            jump_reward DECIMAL(14,2) NOT NULL DEFAULT 0 COMMENT '跳点奖励',
            is_enabled TINYINT(1) NOT NULL DEFAULT 1 COMMENT '是否启用',
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_project_id (project_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        // ignore
    }
}

/**
 * 列表展示佣金：成交金额优先（>0），否则成交价；有套餐则 基数×佣比%+现金+基数×跳点%+跳点奖；否则沿用库内 commission_amount。
 * @return array{amount: float, detail: string}
 */
function admin_finance_package_commission_for_row(array $deal): array
{
    $dp = isset($deal['deal_price']) && is_numeric($deal['deal_price']) ? (float) $deal['deal_price'] : 0.0;
    $taRaw = $deal['transaction_amount'] ?? null;
    $ta = ($taRaw !== null && $taRaw !== '' && is_numeric($taRaw)) ? (float) $taRaw : null;
    $baseAmt = ($ta !== null && $ta > 0) ? $ta : $dp;
    $pkgName = trim((string) ($deal['finance_pkg_name'] ?? ''));
    $pkgId = (int) ($deal['commission_package_id'] ?? 0);
    if ($pkgId <= 0 || $pkgName === '') {
        $legacy = isset($deal['commission_amount']) && is_numeric($deal['commission_amount']) ? (float) $deal['commission_amount'] : 0.0;
        $detail = "【套餐】未绑定佣金套餐\n";
        $detail .= '当前显示为系统已存佣金金额：¥' . number_format($legacy, 2, '.', ',') . "\n";
        $detail .= '说明：案场在「签约」中绑定启用套餐并填写成交金额后，本页将按套餐公式自动核算。';
        return ['amount' => $legacy, 'detail' => $detail];
    }
    $p = isset($deal['finance_pkg_pct']) && is_numeric($deal['finance_pkg_pct']) ? (float) $deal['finance_pkg_pct'] : 0.0;
    $c = isset($deal['finance_pkg_cash']) && is_numeric($deal['finance_pkg_cash']) ? (float) $deal['finance_pkg_cash'] : 0.0;
    $jp = isset($deal['finance_pkg_jump_pct']) && is_numeric($deal['finance_pkg_jump_pct']) ? (float) $deal['finance_pkg_jump_pct'] : 0.0;
    $jr = isset($deal['finance_pkg_jump_cash']) && is_numeric($deal['finance_pkg_jump_cash']) ? (float) $deal['finance_pkg_jump_cash'] : 0.0;
    $part1 = $baseAmt * ($p / 100.0);
    $part2 = $baseAmt * ($jp / 100.0);
    $total = round($part1 + $c + $part2 + $jr, 2);
    $hasJump = abs($jp) > 1e-12 || abs($jr) > 1e-12;
    $baseLabel = ($ta !== null && $ta > 0) ? '成交金额（案场签约录入）' : '成交价（deal_price）';
    $detail = '【套餐】' . $pkgName . "\n";
    $detail .= '计算基数：¥' . number_format($baseAmt, 2, '.', ',') . '（' . $baseLabel . "）\n";
    $detail .= '佣金比例%：' . $p . '　现金奖励：¥' . number_format($c, 2, '.', ',') . "\n";
    if ($hasJump) {
        $detail .= '跳点比例%：' . $jp . '　跳点奖励：¥' . number_format($jr, 2, '.', ',') . "\n";
    }
    $expr = '¥' . number_format($baseAmt, 2, '.', ',') . ' × ' . $p . '% + ¥' . number_format($c, 2, '.', ',');
    if ($hasJump) {
        $expr .= ' + ¥' . number_format($baseAmt, 2, '.', ',') . ' × ' . $jp . '% + ¥' . number_format($jr, 2, '.', ',');
    }
    $detail .= $expr . "\n";
    $detail .= '= ¥' . number_format($total, 2, '.', ',') . '（合计）';
    return ['amount' => $total, 'detail' => $detail];
}

function admin_finance_ensure_filing_finance_performance_rejects(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS filing_finance_performance_rejects (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            filing_id INT UNSIGNED NOT NULL,
            reject_reason MEDIUMTEXT NOT NULL,
            rejected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_id INT UNSIGNED NULL,
            INDEX idx_filing_rejected (filing_id, rejected_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
    }
}

function admin_finance_attach_reject_history_counts(PDO $pdo, array &$deals): void
{
    if ($deals === []) {
        return;
    }
    admin_finance_ensure_filing_finance_performance_rejects($pdo);
    $ids = [];
    foreach ($deals as $d) {
        if (isset($d['id'])) {
            $ids[] = (int)$d['id'];
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        return;
    }
    $counts = array_fill_keys($ids, 0);
    $chunkSize = 500;
    for ($off = 0, $n = count($ids); $off < $n; $off += $chunkSize) {
        $chunk = array_slice($ids, $off, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT filing_id, COUNT(*) AS c FROM filing_finance_performance_rejects WHERE filing_id IN ($ph) GROUP BY filing_id");
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $counts[(int)$r['filing_id']] = (int)$r['c'];
        }
    }
    foreach ($deals as &$d) {
        $fid = (int)($d['id'] ?? 0);
        $d['finance_reject_history_count'] = $counts[$fid] ?? 0;
    }
    unset($d);
}

function admin_finance_agent_as_scope_user(PDO $pdo, int $agentId): ?array {
    if ($agentId <= 0) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT a.id, a.username, a.phone, a.role, COALESCE(d.name, "") AS company
         FROM agents a
         LEFT JOIN departments d ON d.id = a.department_id
         WHERE a.id = ? AND a.is_deleted = 0 LIMIT 1'
    );
    $stmt->execute([$agentId]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        return null;
    }
    return [
        'id' => (int)$r['id'],
        'name' => $r['username'],
        'phone' => (string)($r['phone'] ?? ''),
        'role' => (string)($r['role'] ?? 'staff'),
        'company' => (string)($r['company'] ?? ''),
    ];
}

/**
 * 列表展示用客户名：
 * 1) 优先 filings.subscriber_name（认购登记真实姓名，如「黄金换」），且与 client_name 不同时才采用（避免仍是「黄先生」重复）；
 * 2) client_name 已是全名（≥3 字且非先生/女士称谓）则直接用；
 * 3) 否则从 raw_input_text 解析「客户姓名：」等。
 *
 * 数据库字段说明：client_name = 报备界面常用称谓；subscriber_name = 认购/成交侧登记的全名或共有人写法。
 *
 * 展示时把多人姓名分隔统一为「/」：空白（如「李智豪 戴维颖」）、顿号「、」及两侧空白（如「张雄、黄慧珊」「陈志军、 范水秀」）。
 */
function admin_finance_format_client_name_spaces_to_slash(string $s): string {
    $t = trim($s);
    if ($t === '' || $t === '未知客户') {
        return $t;
    }
    $t = preg_replace('/\s*、\s*/u', '/', $t);
    $t = preg_replace('/\s+/u', '/', $t);
    $t = preg_replace('/\/+/u', '/', $t);
    return trim($t);
}

function admin_finance_display_client_name(array $row): string {
    $name = trim((string)($row['client_name'] ?? ''));
    $sub = trim((string)($row['subscriber_name'] ?? ''));
    $raw = trim((string)($row['raw_input_text'] ?? ''));

    if ($name === '' && $sub === '') {
        return '未知客户';
    }
    if ($name === '') {
        return admin_finance_format_client_name_spaces_to_slash($sub);
    }
    if ($sub !== '' && $sub !== $name) {
        return admin_finance_format_client_name_spaces_to_slash($sub);
    }

    $mbLen = mb_strlen($name, 'UTF-8');
    if ($mbLen >= 3 && !preg_match('/(先生|女士|小姐|老师)$/u', $name)) {
        return admin_finance_format_client_name_spaces_to_slash($name);
    }
    if ($raw === '') {
        return admin_finance_format_client_name_spaces_to_slash($name);
    }
    $patterns = [
        '/客户姓名\s*[:：]\s*([^\s\n\r，,、]+)/u',
        '/客户\s*[:：]\s*([^\s\n\r，,、]+)/u',
        '/姓名\s*[:：]\s*([^\s\n\r，,、]+)/u',
    ];
    foreach ($patterns as $re) {
        if (preg_match($re, $raw, $m)) {
            $got = trim((string)$m[1]);
            $gl = mb_strlen($got, 'UTF-8');
            if ($got !== '' && $gl >= 2 && $gl <= 32) {
                return admin_finance_format_client_name_spaces_to_slash($got);
            }
        }
    }
    if (preg_match('/^([\x{4e00}-\x{9fa5}·•]{2,20})\s+[\d\-\s\+]{6,}/u', $raw, $m)) {
        return admin_finance_format_client_name_spaces_to_slash(trim((string)$m[1]));
    }
    return admin_finance_format_client_name_spaces_to_slash($name);
}

/**
 * 列表展示用客户电话：仅数字计位。
 * - 11 位：原样展示（完整手机号）。
 * - 两个「全号」连写：用 / 分隔、不加 *。常见 22 位（11+11）；或 21 位（10+11，后一段为 1 开头 11 位号）。
 * - 其它非 11 位短号：中间插入 **** 脱敏。
 */
function admin_finance_display_client_phone(?string $phone): string {
    $t = trim((string)$phone);
    if ($t === '') {
        return '-';
    }
    $digits = preg_replace('/\D/u', '', $t);
    if ($digits === '') {
        return $t;
    }
    $len = strlen($digits);
    if ($len === 11) {
        return $t;
    }
    if ($len === 22) {
        $a = substr($digits, 0, 11);
        $b = substr($digits, 11, 11);
        if (preg_match('/^1\d{10}$/', $a) && preg_match('/^1\d{10}$/', $b)) {
            return $a . '/' . $b;
        }
    }
    if ($len === 21) {
        $a10 = substr($digits, 0, 10);
        $b11 = substr($digits, 10, 11);
        if (preg_match('/^1\d{9}$/', $a10) && preg_match('/^1\d{10}$/', $b11)) {
            return $a10 . '/' . $b11;
        }
        $a11 = substr($digits, 0, 11);
        $b10 = substr($digits, 11, 10);
        if (preg_match('/^1\d{10}$/', $a11) && preg_match('/^1\d{9}$/', $b10)) {
            return $a11 . '/' . $b10;
        }
    }
    $mid = (int)floor($len / 2);
    return substr($digits, 0, $mid) . '****' . substr($digits, $mid);
}

$action = $_GET['action'] ?? '';

if (
    $action === ''
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && isset($_GET['perf_reject_ok'])
    && (string) $_GET['perf_reject_ok'] === '1'
    && isset($_GET['go_reject'])
) {
    $rid = (int) ($_GET['go_reject'] ?? 0);
    if ($rid > 0) {
        header('Location: admin_finance_performance_confirm.php?id=' . $rid . '&open_reject=1');
        exit;
    }
}

// === API 处理逻辑 ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' || !empty($action)) {
    // 清除缓冲区中的任何 PHP 警告或 HTML，确保只输出纯 JSON
    ob_clean();
    header('Content-Type: application/json');
    
    // API 模式下关闭错误打印，防止破坏 JSON 格式
    ini_set('display_errors', 0);
    
    $debug_info['action'] = $action;
    $debug_info['request_method'] = $_SERVER['REQUEST_METHOD'];

    // 1. 获取财务数据（与案场工作台列表同一套 scope_sql：项目驻场仅看自己绑定项目等）
    if ($action == 'get_finance_data') {
        try {
            $total_count = $pdo->query("SELECT COUNT(*) FROM filings")->fetchColumn();
            $debug_info['total_filings_count'] = $total_count;

            // 与 staff workbenchDealStageSet 一致：英文逗号分段 + trim；并归一空格、中文逗号、顿号（FIND_IN_SET 按英文逗号 token）
            $subStagesNorm = "REPLACE(REPLACE(REPLACE(IFNULL(sub_stages,''), ' ', ''), '，', ','), '、', ',')";
            $subStagesNormF = "REPLACE(REPLACE(REPLACE(IFNULL(f.sub_stages,''), ' ', ''), '，', ','), '、', ',')";
            $stage2Signed = "(status = 2 AND FIND_IN_SET('deposit', $subStagesNorm) AND FIND_IN_SET('lock', $subStagesNorm) AND FIND_IN_SET('sign', $subStagesNorm))";
            $stage2SignedF = "(f.status = 2 AND FIND_IN_SET('deposit', $subStagesNormF) AND FIND_IN_SET('lock', $subStagesNormF) AND FIND_IN_SET('sign', $subStagesNormF))";
            $dealWherePlain = "(status IN (3,4) OR $stage2Signed)";
            $dealWhereAlias = "(f.status IN (3,4) OR $stage2SignedF)";

            $status_4_count = (int)$pdo->query("SELECT COUNT(*) FROM filings WHERE status=4")->fetchColumn();
            $status_3_count = (int)$pdo->query("SELECT COUNT(*) FROM filings WHERE status=3")->fetchColumn();
            $status_deal_count = (int)$pdo->query("SELECT COUNT(*) FROM filings WHERE status IN (3,4)")->fetchColumn();
            $status2_signed_count = (int)$pdo->query("SELECT COUNT(*) FROM filings WHERE $stage2Signed")->fetchColumn();
            $deal_list_count_unscoped = (int)$pdo->query("SELECT COUNT(*) FROM filings WHERE $dealWherePlain")->fetchColumn();
            $debug_info['status_4_count'] = $status_4_count;
            $debug_info['status_3_count'] = $status_3_count;
            $debug_info['status_deal_count'] = $status_deal_count;
            $debug_info['status2_signed_count'] = $status2_signed_count;
            $debug_info['deal_list_count_unscoped'] = $deal_list_count_unscoped;

            $scopeRequestedExplicit = isset($_GET['scope_agent_id']);
            $financeScopeAgentId = 0;
            if ($scopeRequestedExplicit) {
                $financeScopeAgentId = (int)($_GET['scope_agent_id'] ?? 0);
            } else {
                $financeScopeAgentId = 0;
            }
            $debug_info['finance_scope_agent_id'] = $financeScopeAgentId;
            $debug_info['finance_scope_param_explicit'] = $scopeRequestedExplicit;

            $scopeClause = '1=1';
            $scopeParams = [];
            $scopeLabel = '全平台（未按案场账号过滤）';
            if ($financeScopeAgentId > 0) {
                $su = admin_finance_agent_as_scope_user($pdo, $financeScopeAgentId);
                if ($su) {
                    $SCOPE_MEMBERS_FIN = get_scope_members_staff($pdo, $su);
                    $scFin = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS_FIN, 0, $su);
                    $scopeClause = $scFin['sql'];
                    $scopeParams = $scFin['params'];
                    $scopeLabel = '案场：' . ($su['name'] ?? '') . '（与 staff get_list 同范围）';
                } else {
                    $financeScopeAgentId = 0;
                    $debug_info['finance_scope_agent_id'] = 0;
                }
            }

            $debug_info['finance_scope_label'] = $scopeLabel;

            $page = max(1, (int)($_GET['page'] ?? 1));
            $perPage = (int)($_GET['per_page'] ?? 20);
            $allowedPerPage = [10, 20, 50, 100];
            if (!in_array($perPage, $allowedPerPage, true)) {
                $perPage = 20;
            }
            $debug_info['finance_per_page'] = $perPage;

            // 报备日期：与 Vue 一致；不传即不限日期（与案场高级筛选清空起止后「所有时间」一致）
            $dateStart = isset($_GET['date_start']) ? trim((string)$_GET['date_start']) : '';
            $dateEnd = isset($_GET['date_end']) ? trim((string)$_GET['date_end']) : '';
            $debug_info['finance_date_start'] = $dateStart;
            $debug_info['finance_date_end'] = $dateEnd;

            $projectIdsRaw = isset($_GET['project_ids']) ? trim((string)$_GET['project_ids']) : '';
            $debug_info['finance_project_ids'] = $projectIdsRaw;

            admin_finance_ensure_project_commission_packages_schema($pdo);

            // companies 按 name 可能有多条，直接 JOIN 会把一行报备乘倍数导致 COUNT/SUM 翻倍；与 staff 列表（每 filing 一行）对齐：每 name 只取一条
            $baseFrom = 'filings f
                    LEFT JOIN projects p ON f.project_id = p.id
                    LEFT JOIN project_commission_packages cpkg ON cpkg.id = f.commission_package_id
                    LEFT JOIN (
                        SELECT c1.id, c1.name, c1.store_name, c1.follower
                        FROM companies c1
                        INNER JOIN (
                            SELECT MIN(id) AS id FROM companies GROUP BY name
                        ) cuniq ON c1.id = cuniq.id
                    ) c ON c.name <=> f.company_name
                    LEFT JOIN agents a ON f.agent_id = a.id';
            $whereList = '(' . $dealWhereAlias . ') AND (' . $scopeClause . ')';
            $extraParams = [];
            // 与日期控件「自然日」一致：按 DATE(created_at) 落在区间内（避免会话时区下 datetime 与 staff 字符串比较的边界偏差）
            if ($dateStart !== '') {
                $whereList .= ' AND DATE(f.created_at) >= ?';
                $extraParams[] = $dateStart;
            }
            if ($dateEnd !== '') {
                $whereList .= ' AND DATE(f.created_at) <= ?';
                $extraParams[] = $dateEnd;
            }
            if ($projectIdsRaw !== '') {
                $pids = array_values(array_filter(array_map('intval', explode(',', $projectIdsRaw)), function ($x) {
                    return $x > 0;
                }));
                if ($pids !== []) {
                    $ph = implode(',', array_fill(0, count($pids), '?'));
                    $whereList .= " AND f.project_id IN ($ph)";
                    foreach ($pids as $pid) {
                        $extraParams[] = $pid;
                    }
                }
            }
            // 与 staff itemMatchesHistoryStatusFilter / itemMatchesClientIntentionFilter 一致（applyWorkbenchGlobalFilters）
            $statusFilter = isset($_GET['status_filter']) ? trim((string)$_GET['status_filter']) : '';
            $intentionFilter = isset($_GET['intention_filter']) ? trim((string)$_GET['intention_filter']) : '';
            $debug_info['finance_status_filter'] = $statusFilter;
            $debug_info['finance_intention_filter'] = $intentionFilter;
            if ($statusFilter !== '') {
                switch ($statusFilter) {
                    case 'pending':
                        $whereList .= ' AND f.status = 1 AND (f.visit_type IS NULL OR NOT (f.visit_type <=> 0))';
                        break;
                    case 'valid_report':
                        $whereList .= ' AND f.status = 1 AND (f.visit_type <=> 0)';
                        break;
                    case 'valid_visit':
                        $whereList .= ' AND f.status >= 2 AND f.status < 5 AND (f.visit_type <=> 1)';
                        break;
                    case 'stage_2':
                        $whereList .= ' AND f.status = 2';
                        break;
                    case 'stage_3':
                        $whereList .= ' AND f.status = 3';
                        break;
                    case 'deal':
                        $whereList .= ' AND f.status = 4';
                        break;
                    case 'invalid_visit':
                        $whereList .= ' AND f.status = 5 AND (f.visit_type <=> 2)';
                        break;
                    case 'repeat':
                        $whereList .= ' AND f.status = 5 AND (f.visit_type <=> 3)';
                        break;
                    case 'invalid_report':
                        $whereList .= ' AND f.status = 6';
                        break;
                    case 'refund':
                        $whereList .= ' AND f.status = 7';
                        break;
                    default:
                        break;
                }
            }
            if ($intentionFilter !== '') {
                // staff：parseInt 失败当 0，与「未设置」键 '0' 一致
                $whereList .= ' AND (COALESCE(f.client_intention, 0) = ?)';
                $extraParams[] = (int)$intentionFilter;
            }

            $listParams = array_merge($scopeParams, $extraParams);

            $stmtCnt = $pdo->prepare("SELECT COUNT(DISTINCT f.id) FROM $baseFrom WHERE $whereList");
            $stmtCnt->execute($listParams);
            $deal_list_count = (int)$stmtCnt->fetchColumn();
            $debug_info['deal_list_count'] = $deal_list_count;

            $totalPages = $deal_list_count > 0 ? (int)ceil($deal_list_count / $perPage) : 1;
            $page = min($page, max(1, $totalPages));
            $offset = ($page - 1) * $perPage;
            $debug_info['finance_page'] = $page;

            $runSum = function ($extraAnd) use ($pdo, $baseFrom, $whereList, $listParams) {
                $sql = "SELECT COALESCE(SUM(f.deal_price),0) FROM $baseFrom WHERE $whereList $extraAnd";
                $st = $pdo->prepare($sql);
                $st->execute($listParams);
                return $st->fetchColumn() ?: 0;
            };
            $runSumComm = function ($cs) use ($pdo, $baseFrom, $whereList, $listParams) {
                $sql = "SELECT COALESCE(SUM(f.commission_amount),0) FROM $baseFrom WHERE $whereList AND f.commission_status = ?";
                $st = $pdo->prepare($sql);
                $st->execute(array_merge($listParams, [$cs]));
                return $st->fetchColumn() ?: 0;
            };

            $stats = [
                'gmv' => $runSum(''),
                'pending' => $runSumComm(0),
                'confirmed' => $runSumComm(1),
                'paid' => $runSumComm(2),
            ];

            $sql = "SELECT f.*, 
                           p.name as project_name, 
                           p.manager_name AS project_onsite_name,
                           a.username as agent_name, a.department,
                           cpkg.package_name AS finance_pkg_name,
                           cpkg.commission_pct AS finance_pkg_pct,
                           cpkg.cash_reward AS finance_pkg_cash,
                           cpkg.jump_ratio AS finance_pkg_jump_pct,
                           cpkg.jump_reward AS finance_pkg_jump_cash
                    FROM $baseFrom
                    WHERE $whereList
                    ORDER BY f.status DESC, f.id DESC
                    LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

            $debug_info['sql_query'] = $sql;

            $stmt = $pdo->prepare($sql);
            $stmt->execute($listParams);
            $deals = $stmt->fetchAll(PDO::FETCH_ASSOC);

            // 数据清洗：防止前端出现 null 报错
            foreach ($deals as &$deal) {
                $deal['project_name'] = $deal['project_name'] ?? '未知项目';
                $deal['client_name'] = $deal['client_name'] ?? '未知客户';
                $deal['display_client_name'] = admin_finance_display_client_name($deal);
                $deal['display_client_phone'] = admin_finance_display_client_phone($deal['client_phone'] ?? null);
                $deal['project_onsite_name'] = trim((string)($deal['project_onsite_name'] ?? ''));
                $deal['room_number'] = $deal['room_number'] ?? '';
                $deal['deal_price'] = $deal['deal_price'] ?? 0;
                $deal['commission_amount'] = $deal['commission_amount'] ?? 0;
                $deal['commission_status'] = $deal['commission_status'] ?? 0; // 0=待确认, 1=待发放, 2=已发
                $calc = admin_finance_package_commission_for_row($deal);
                $deal['finance_commission_amount'] = $calc['amount'];
                $deal['finance_commission_detail'] = $calc['detail'];
            }
            unset($deal);
            admin_finance_attach_reject_history_counts($pdo, $deals);

            echo json_encode([
                'status' => 'success',
                'stats' => $stats,
                'deals' => $deals,
                'pagination' => [
                    'total' => $deal_list_count,
                    'page' => $page,
                    'per_page' => $perPage,
                    'total_pages' => max(1, $totalPages),
                ],
                'debug' => $debug_info
            ]);
            exit;

        } catch (Exception $e) {
            echo json_encode([
                'status' => 'error',
                'message' => $e->getMessage(),
                'debug' => $debug_info
            ]);
            exit;
        }
    }

    if ($action == 'list_finance_scope_agents') {
        $staffSql = agent_sql_has_role('a', 'staff');
        $rows = $pdo->query(
            "SELECT a.id, a.username, COALESCE(d.name, '') AS department
             FROM agents a
             INNER JOIN departments d ON d.id = a.department_id
             WHERE a.is_deleted = 0 AND {$staffSql}
               AND d.name = '项目驻场'
               AND TRIM(a.username) <> '李娜'
             ORDER BY a.username ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode([
            'status' => 'success',
            'agents' => $rows,
            'suggested_default_scope_id' => 0,
        ]);
        exit;
    }

    // 2. 更新佣金状态
    if ($action == 'update_commission') {
        $id = $_POST['id'] ?? 0;
        $status = $_POST['status'] ?? 0;
        $amount = $_POST['amount'] ?? 0;
        $proof = $_POST['proof'] ?? '';

        if ((int) $status === 1) {
            echo json_encode(['status' => 'error', 'msg' => '业绩确认请使用「认购业绩确认单」页面完成']);
            exit;
        }

        try {
            $stmt = $pdo->prepare("UPDATE filings SET commission_status=?, commission_amount=?, commission_proof=? WHERE id=?");
            $result = $stmt->execute([$status, $amount, $proof, $id]);
            
            echo json_encode([
                'status' => 'success', 
                'msg' => '更新成功',
                'affected_rows' => $stmt->rowCount()
            ]);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        }
        exit;
    }

    // 未知 API
    echo json_encode(['status' => 'error', 'msg' => 'Unknown action']);
    exit;
}
// 结束缓冲，开始输出 HTML
ob_end_flush();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>佣金结算 - 易城好房Pro</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <style>
        .fade-in { animation: fadeIn 0.5s ease-in; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
        /* 调试面板样式 */
        .debug-box {
            background: #1e293b; color: #818cf8; font-family: monospace;
            padding: 15px; margin-bottom: 20px; border-radius: 8px; font-size: 12px;
            white-space: pre-wrap; word-break: break-all;
            max-height: 200px; overflow-y: auto;
        }
        [v-cloak] { display: none; } /* 防止 Vue 加载前闪烁 */
        .finance-pagination-bar {
            position: fixed;
            z-index: 45;
            left: 0;
            right: 0;
            bottom: 0;
            pointer-events: none;
            padding: 0.5rem 1rem 0.75rem;
            background: linear-gradient(to top, rgba(15, 23, 42, 0.06), transparent);
        }
        @media (min-width: 768px) {
            .finance-pagination-bar { left: 16rem; }
        }
        .finance-pagination-inner {
            pointer-events: auto;
            max-width: 72rem;
            margin-left: auto;
            margin-right: auto;
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800 min-h-screen">

    <div id="app" class="flex h-screen overflow-hidden" v-cloak>
        <?php include 'sidebar.php'; ?>

        <div class="flex-1 flex flex-col overflow-hidden min-w-0">
            <header class="sticky top-0 bg-white border-b border-gray-200 shadow-sm z-40 shrink-0">
                <div class="flex flex-wrap justify-between items-center gap-3 px-6 py-3">
                    <div class="flex items-center gap-3 min-w-0">
                        <button type="button" @click="sidebarOpen = !sidebarOpen" class="md:hidden text-gray-500 shrink-0 p-1 -ml-1 rounded hover:bg-gray-100" aria-label="打开菜单">
                            <i class="fas fa-bars"></i>
                        </button>
                        <h1 class="text-xl font-bold text-slate-800 truncate">
                            <i class="fas fa-file-invoice-dollar mr-2 text-blue-600"></i>佣金结算中心
                        </h1>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <select v-model="scopeSelect" @change="persistFiltersAndLoad" class="text-xs border border-gray-200 rounded-lg px-2 py-1.5 max-w-[260px] bg-white">
                            <option value="0">全平台</option>
                            <option v-for="ag in scopeAgents" :key="ag.id" :value="String(ag.id)">{{ ag.username }} · {{ ag.department || '—' }}</option>
                        </select>
                        <label class="text-xs text-gray-500 flex items-center gap-1">报备始
                            <input type="date" v-model="filterDateStart" @change="persistFiltersAndLoad" class="border border-gray-200 rounded px-1 py-0.5 text-xs">
                        </label>
                        <label class="text-xs text-gray-500 flex items-center gap-1">报备止
                            <input type="date" v-model="filterDateEnd" @change="persistFiltersAndLoad" class="border border-gray-200 rounded px-1 py-0.5 text-xs">
                        </label>
                        <input v-model="filterProjectIds" type="text" placeholder="楼盘 id（逗号分隔）" class="text-xs border border-gray-200 rounded px-2 py-1 w-40 max-w-[35vw]" @keyup.enter="persistFiltersAndLoad">
                        <select v-model="filterStatus" @change="persistFiltersAndLoad" class="text-xs border border-gray-200 rounded px-1 py-1 max-w-[100px]">
                            <option v-for="opt in historyStatusFilterOptions" :key="'st_' + (opt.value || '_all')" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <select v-model="filterIntention" @change="persistFiltersAndLoad" class="text-xs border border-gray-200 rounded px-1 py-1 max-w-[100px]">
                            <option v-for="opt in clientIntentionFilterOptions" :key="'in_' + (opt.value || '_all')" :value="opt.value">{{ opt.label }}</option>
                        </select>
                        <button type="button" @click="applyUnlimitedReportDates" class="text-xs bg-white hover:bg-gray-50 text-gray-700 px-2 py-1 rounded border border-gray-300">全部</button>
                        <button type="button" @click="applyThisMonthCalendar" class="text-xs bg-indigo-50 hover:bg-indigo-100 text-indigo-800 px-2 py-1 rounded border border-indigo-200">本月</button>
                        <button type="button" @click="applyThisWeekCalendar" class="text-xs bg-purple-50 hover:bg-purple-100 text-purple-800 px-2 py-1 rounded border border-purple-200">本周</button>
                        <button type="button" @click="applyStaffDefaultToday" class="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 px-2 py-1 rounded">仅今日</button>
                        <button type="button" @click="clearDateFilters" class="text-xs text-gray-600 hover:text-blue-600 px-1">重置</button>
                        <button type="button" @click="loadFinance" class="text-sm text-blue-600 hover:text-blue-800">
                            <i class="fas fa-sync-alt mr-1"></i>刷新
                        </button>
                    </div>
                </div>
            </header>
            <?php if (!empty($_GET['perf_reject_ok'])): ?>
            <div class="shrink-0 bg-amber-50 border-b border-amber-200 px-4 py-2 text-center text-sm text-amber-900">已提交财务驳回，驻场工作台将显示提醒。</div>
            <?php endif; ?>

            <main class="p-6 pb-24 flex-1 overflow-y-auto min-h-0">
                <!-- <div class="mb-4 text-right">
                    <button @click="showDebug = !showDebug" class="text-xs text-gray-400 hover:text-gray-600">
                        {{ showDebug ? '隐藏调试信息' : '显示调试信息' }}
                    </button>
                </div>
                <div v-if="showDebug" class="debug-box">
                    API Response Debug:
                    {{ debugData }}
                </div> -->

                <div class="space-y-6 fade-in">
                    <div class="grid grid-cols-4 gap-4">
                        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-blue-500">
                            <div class="text-xs text-gray-400 font-bold uppercase">累计 GMV (待录入+已成交)</div>
                            <div class="text-xl font-bold text-slate-800 mt-1">
                                ¥{{ formatMoney(financeData.stats.gmv) }}
                            </div>
                        </div>
                        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-orange-400">
                            <div class="text-xs text-gray-400 font-bold uppercase">待确认业绩</div>
                            <div class="text-xl font-bold text-orange-600 mt-1">
                                ¥{{ formatMoney(financeData.stats.pending) }}
                            </div>
                        </div>
                        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-purple-500">
                            <div class="text-xs text-gray-400 font-bold uppercase">待发佣金</div>
                            <div class="text-xl font-bold text-purple-600 mt-1">
                                ¥{{ formatMoney(financeData.stats.confirmed) }}
                            </div>
                        </div>
                        <div class="bg-white p-4 rounded-xl shadow-sm border-l-4 border-green-500">
                            <div class="text-xs text-gray-400 font-bold uppercase">已发佣金</div>
                            <div class="text-xl font-bold text-green-600 mt-1">
                                ¥{{ formatMoney(financeData.stats.paid) }}
                            </div>
                        </div>
                    </div>

                    <div class="bg-white rounded-xl shadow-sm border border-gray-200">
                        <div class="px-6 py-4 border-b border-gray-100 flex justify-between items-center">
                            <span class="font-bold text-slate-700">成交/待结佣订单</span>
                            <span class="text-xs text-gray-400">共 {{ financeData.pagination.total }} 条</span>
                        </div>
                        
                        <div class="overflow-x-auto">
                            <table class="w-full text-sm text-left text-gray-500">
                                <thead class="bg-gray-50 text-xs uppercase text-gray-700">
                                    <tr>
                                        <th class="px-4 py-3">项目</th>
                                        <th class="px-4 py-3">房号</th>
                                        <th class="px-4 py-3">客户姓名</th>
                                        <th class="px-4 py-3 whitespace-nowrap">客户全号</th>
                                        <th class="px-4 py-3 min-w-[6rem]">中介公司经纪人</th>
                                        <th class="px-6 py-3">成交价</th>
                                        <th class="px-6 py-3">佣金金额</th>
                                        <th class="px-6 py-3 whitespace-nowrap">报备状态</th>
                                        <th class="px-6 py-3 whitespace-nowrap">结佣进度</th>
                                        <th class="px-6 py-3 min-w-[8rem]">成交附件(合同图)</th>
                                        <th class="px-4 py-3 min-w-[5rem]">渠道</th>
                                        <th class="px-4 py-3 min-w-[5rem]">驻场</th>
                                        <th class="px-6 py-3 text-right">操作</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                    <tr v-for="deal in financeData.deals" :key="deal.id" class="hover:bg-gray-50 transition-colors">
                                        <td class="px-4 py-3 font-bold text-slate-800">{{ deal.project_name || '-' }}</td>
                                        <td class="px-4 py-3 text-blue-600">{{ deal.room_number || '-' }}</td>
                                        <td class="px-4 py-3 text-slate-700" :title="((deal.client_name || '').trim() || '-') + ((deal.subscriber_name || '').trim() && (deal.subscriber_name || '').trim() !== (deal.client_name || '').trim() ? ' · 认购登记：' + (deal.subscriber_name || '').trim() : '')">{{ deal.display_client_name || deal.client_name || '-' }}</td>
                                        <td class="px-4 py-3 text-slate-800 font-mono text-xs whitespace-nowrap" :title="(deal.client_phone || '').trim() || '-'">{{ deal.display_client_phone ?? ((deal.client_phone || '').trim() || '-') }}</td>
                                        <td class="px-4 py-3 text-slate-700 max-w-[7rem] break-words" :title="(deal.broker_phone || '').trim() ? ('电话：' + (deal.broker_phone || '').trim()) : ''">{{ (deal.broker_name || '').trim() || '无' }}</td>
                                        <td class="px-6 py-4">
                                            <div class="text-slate-800">¥{{ parseFloat(deal.deal_price).toLocaleString() }}</div>
                                        </td>
                                        <td class="px-6 py-4">
                                            <button
                                                type="button"
                                                class="text-red-600 font-bold underline decoration-red-300 hover:text-red-800 text-left"
                                                title="点击查看计算详情"
                                                @click="showCommissionDetail(deal)"
                                            >¥{{ parseFloat(deal.finance_commission_amount != null && deal.finance_commission_amount !== '' ? deal.finance_commission_amount : (deal.commission_amount || 0)).toLocaleString() }}</button>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap align-middle">
                                            <span v-if="Number(deal.status) === 2" class="inline-block whitespace-nowrap px-2 py-1 bg-blue-100 text-blue-800 text-xs rounded-full font-medium">下定·已签认购</span>
                                            <span v-else-if="Number(deal.status) === 3" class="inline-block whitespace-nowrap px-2 py-1 bg-amber-100 text-amber-800 text-xs rounded-full font-medium">待录入成交</span>
                                            <span v-else-if="Number(deal.status) === 4" class="inline-block whitespace-nowrap px-2 py-1 bg-green-100 text-green-800 text-xs rounded-full font-medium">已成交</span>
                                            <span v-else class="inline-block whitespace-nowrap px-2 py-1 bg-gray-100 text-gray-600 text-xs rounded-full">—</span>
                                        </td>
                                        <td class="px-6 py-4 whitespace-nowrap align-middle">
                                            <span v-if="deal.commission_status == 0 && (deal.performance_finance_rejected_at || (deal.finance_reject_history_count && deal.finance_reject_history_count > 0))" class="inline-block whitespace-nowrap px-2 py-1 bg-rose-100 text-rose-800 text-xs rounded-full font-medium">驳回中</span>
                                            <span v-else-if="deal.commission_status == 0" class="inline-block whitespace-nowrap px-2 py-1 bg-orange-100 text-orange-700 text-xs rounded-full font-medium">待确认</span>
                                            <span v-else-if="deal.commission_status == 1" class="inline-block whitespace-nowrap px-2 py-1 bg-purple-100 text-purple-700 text-xs rounded-full font-medium">待发放</span>
                                            <span v-else-if="deal.commission_status == 2" class="inline-block whitespace-nowrap px-2 py-1 bg-green-100 text-green-700 text-xs rounded-full font-medium">已发放</span>
                                            <span v-else class="inline-block whitespace-nowrap px-2 py-1 bg-gray-100 text-gray-700 text-xs rounded-full">未知</span>
                                        </td>
                                        <td class="px-6 py-2 align-middle">
                                            <button
                                                v-if="dealContractAttachments(deal).length"
                                                type="button"
                                                @click="openContractGallery(deal)"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg border border-violet-200 bg-violet-50 text-violet-800 text-xs font-bold hover:bg-violet-100 transition whitespace-nowrap"
                                                title="新页面查看，左右切换，避免列表加载多图卡顿"
                                            >
                                                <i class="fas fa-images"></i>
                                                查看合同({{ dealContractAttachments(deal).length }})
                                            </button>
                                            <span v-else class="text-gray-400 text-xs">—</span>
                                        </td>
                                        <td class="px-4 py-3 text-slate-700 text-xs max-w-[6rem] break-words" :title="(deal.follower || '').trim() || '公池'">{{ (deal.follower || '').trim() || '公池' }}</td>
                                        <td class="px-4 py-3 text-slate-700 text-xs max-w-[6rem] break-words" :title="(deal.project_onsite_name || '').trim() || '-'">{{ (deal.project_onsite_name || '').trim() || '-' }}</td>
                                        <td class="px-6 py-4 text-right">
                                            <div class="inline-flex flex-wrap items-center justify-end gap-2">
                                                <a
                                                    v-if="deal.commission_status==0 && (deal.performance_finance_rejected_at || (deal.finance_reject_history_count && deal.finance_reject_history_count > 0))"
                                                    :href="'admin_finance.php?perf_reject_ok=1&go_reject=' + deal.id"
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    class="inline-flex items-center border-2 border-red-600 text-red-600 bg-white hover:bg-red-50 px-3 py-1 rounded text-xs font-bold transition"
                                                    title="打开该单业绩确认并弹出驳回（与提交驳回成功后返回列表的 perf_reject_ok=1 同一入口域名）"
                                                >驳回</a>
                                                <a v-if="deal.commission_status==0" :href="'admin_finance_performance_confirm.php?id=' + deal.id" target="_blank" rel="noopener noreferrer" class="inline-flex bg-blue-600 hover:bg-blue-700 text-white px-3 py-1 rounded text-xs transition">确认业绩</a>
                                                <a v-else :href="'admin_finance_performance_confirm.php?id=' + deal.id + '&view=1'" target="_blank" rel="noopener noreferrer" class="inline-flex bg-slate-100 hover:bg-slate-200 text-slate-800 border border-slate-300 px-3 py-1 rounded text-xs transition font-medium">已确认业绩</a>
                                                <a v-if="deal.commission_status==1" :href="'admin_finance_commission_settle.php?id=' + deal.id" target="_blank" rel="noopener noreferrer" class="inline-flex bg-purple-600 hover:bg-purple-700 text-white px-3 py-1 rounded text-xs transition">结算佣金</a>
                                                <a v-if="deal.commission_status==2" :href="'admin_finance_commission_settle.php?id=' + deal.id + '&view=1'" target="_blank" rel="noopener noreferrer" class="inline-flex bg-emerald-50 hover:bg-emerald-100 text-emerald-900 border border-emerald-300 px-3 py-1 rounded text-xs transition font-medium">已结算佣金</a>
                                                <button v-if="deal.commission_proof" type="button" @click="viewProof(deal.commission_proof)" class="text-gray-400 hover:text-blue-600" title="查看凭证"><i class="fas fa-file-invoice"></i></button>
                                            </div>
                                        </td>
                                    </tr>
                                    <tr v-if="financeData.deals.length === 0">
                                        <td colspan="13" class="px-6 py-12 text-center">
                                            <div class="text-gray-300 text-4xl mb-3"><i class="fas fa-inbox"></i></div>
                                            <div class="text-gray-500">暂无待录入/已成交订单</div>
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            </main>

            <div class="finance-pagination-bar" v-show="financeData.pagination.total > 0">
                <div class="finance-pagination-inner flex flex-wrap items-center justify-center gap-x-3 gap-y-2 rounded-xl border border-slate-200 bg-white/95 backdrop-blur-sm px-3 py-2 shadow-lg text-xs text-slate-700">
                    <span class="text-slate-500 whitespace-nowrap">每页</span>
                    <select v-model.number="listPerPage" @change="onPerPageChange" class="border border-slate-200 rounded-lg px-2 py-1 bg-white text-xs font-medium">
                        <option :value="10">10</option>
                        <option :value="20">20</option>
                        <option :value="50">50</option>
                        <option :value="100">100</option>
                    </select>
                    <span class="text-slate-400">|</span>
                    <button type="button" @click="goListPage(1)" :disabled="listPage <= 1" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed">首页</button>
                    <button type="button" @click="goListPage(listPage - 1)" :disabled="listPage <= 1" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed">上一页</button>
                    <span class="tabular-nums font-medium text-slate-800">第 {{ listPage }} / {{ financeData.pagination.total_pages }} 页</span>
                    <button type="button" @click="goListPage(listPage + 1)" :disabled="listPage >= financeData.pagination.total_pages" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed">下一页</button>
                    <button type="button" @click="goListPage(financeData.pagination.total_pages)" :disabled="listPage >= financeData.pagination.total_pages" class="px-2 py-1 rounded border border-slate-200 bg-white hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed">末页</button>
                    <span class="text-slate-400">|</span>
                    <label class="flex items-center gap-1 whitespace-nowrap">
                        <span class="text-slate-500">跳转</span>
                        <input v-model.number="jumpPageInput" type="number" min="1" :max="financeData.pagination.total_pages" class="w-14 border border-slate-200 rounded px-1 py-0.5 text-center text-xs" @keyup.enter="applyJumpPage">
                    </label>
                    <button type="button" @click="applyJumpPage" class="px-2 py-1 rounded bg-blue-600 text-white hover:bg-blue-700 font-medium">跳转</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const { createApp, ref, onMounted } = Vue;

        const app = createApp({
            setup() {
                // 数据状态
                const financeData = ref({
                    stats: { gmv: 0, pending: 0, confirmed: 0, paid: 0 },
                    deals: [],
                    pagination: { total: 0, page: 1, per_page: 20, total_pages: 1 },
                });
                const debugData = ref(null);
                const showDebug = ref(true); // 默认开启调试方便您看

                const LS_SCOPE = 'admin_finance_scope_select';
                const LS_PER_PAGE = 'admin_finance_list_per_page';
                const scopeAgents = ref([]);
                const readInitialScopeSelect = () => {
                    try {
                        const v = localStorage.getItem(LS_SCOPE);
                        if (v === '0' || v === '__omit__' || v == null) {
                            return '0';
                        }
                        if (v && /^\d+$/.test(v)) {
                            return String(parseInt(v, 10));
                        }
                    } catch (e) {}
                    return '0';
                };
                const scopeSelect = ref(readInitialScopeSelect());
                const sidebarOpen = ref(false);
                const listPage = ref(1);
                const listPerPage = ref(20);
                try {
                    const pp = localStorage.getItem(LS_PER_PAGE);
                    const n = parseInt(pp, 10);
                    if ([10, 20, 50, 100].includes(n)) {
                        listPerPage.value = n;
                    }
                } catch (e) {}
                const jumpPageInput = ref(1);

                /** 与 staff historyStatusFilterOptions / clientIntentionFilterOptions 一致 */
                const historyStatusFilterOptions = [
                    { value: '', label: '全部状态' },
                    { value: 'pending', label: '待处理' },
                    { value: 'valid_report', label: '有效报备' },
                    { value: 'valid_visit', label: '有效到访' },
                    { value: 'stage_2', label: '下定中' },
                    { value: 'stage_3', label: '成交客户' },
                    { value: 'deal', label: '成交' },
                    { value: 'invalid_visit', label: '到访无效' },
                    { value: 'repeat', label: '重复到访' },
                    { value: 'invalid_report', label: '报备无效' },
                    { value: 'refund', label: '退房' },
                ];
                const clientIntentionFilterOptions = [
                    { value: '', label: '全部意向' },
                    { value: '0', label: '未设置' },
                    { value: '5', label: '非常强烈' },
                    { value: '4', label: '强烈' },
                    { value: '3', label: '一般' },
                    { value: '2', label: '还行' },
                    { value: '1', label: '无意向' },
                ];

                const pad2 = (n) => String(n).padStart(2, '0');
                const formatLocalYmd = (d) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`;
                /** 含 date 的当周周一（本地，周一为一周之首） */
                const mondayOfLocalWeek = (d) => {
                    const x = new Date(d.getFullYear(), d.getMonth(), d.getDate());
                    const dow = x.getDay();
                    const delta = dow === 0 ? -6 : 1 - dow;
                    x.setDate(x.getDate() + delta);
                    return x;
                };
                const sundayAfterMonday = (mon) => {
                    const s = new Date(mon.getFullYear(), mon.getMonth(), mon.getDate());
                    s.setDate(s.getDate() + 6);
                    return s;
                };
                const staffTodayLocal = () => formatLocalYmd(new Date());
                // 默认不限报备日期，与案场「高级筛选」清空起止后一致
                const filterDateStart = ref('');
                const filterDateEnd = ref('');
                const filterProjectIds = ref('');
                const filterStatus = ref('');
                const filterIntention = ref('');

                const loadScopeAgents = async () => {
                    try {
                        const res = await fetch('?action=list_finance_scope_agents');
                        const data = await res.json();
                        if (data.status === 'success' && Array.isArray(data.agents)) {
                            scopeAgents.value = data.agents;
                        }
                    } catch (e) {
                        console.error(e);
                    }
                };

                const buildFinanceQuery = () => {
                    const q = new URLSearchParams();
                    q.set('action', 'get_finance_data');
                    q.set('scope_agent_id', String(scopeSelect.value));
                    if (filterDateStart.value) {
                        q.set('date_start', filterDateStart.value);
                    }
                    if (filterDateEnd.value) {
                        q.set('date_end', filterDateEnd.value);
                    }
                    const pids = filterProjectIds.value.replace(/\s+/g, '').trim();
                    if (pids) {
                        q.set('project_ids', pids);
                    }
                    if (filterStatus.value) {
                        q.set('status_filter', filterStatus.value);
                    }
                    if (filterIntention.value !== '' && filterIntention.value !== null && filterIntention.value !== undefined) {
                        q.set('intention_filter', String(filterIntention.value));
                    }
                    q.set('page', String(listPage.value));
                    q.set('per_page', String(listPerPage.value));
                    return '?' + q.toString();
                };

                const persistFiltersAndLoad = () => {
                    try {
                        localStorage.setItem(LS_SCOPE, scopeSelect.value);
                    } catch (e) {}
                    listPage.value = 1;
                    loadFinance();
                };

                const goListPage = (p) => {
                    const max = Math.max(1, financeData.value.pagination.total_pages || 1);
                    const n = Math.min(max, Math.max(1, parseInt(p, 10) || 1));
                    listPage.value = n;
                    loadFinance();
                };

                const onPerPageChange = () => {
                    try {
                        localStorage.setItem(LS_PER_PAGE, String(listPerPage.value));
                    } catch (e) {}
                    listPage.value = 1;
                    loadFinance();
                };

                const applyJumpPage = () => {
                    goListPage(jumpPageInput.value);
                };

                /** 仅今日（本地），与 staff 点「重置」里今日起止类似 */
                const applyStaffDefaultToday = () => {
                    const t = staffTodayLocal();
                    filterDateStart.value = t;
                    filterDateEnd.value = t;
                    filterProjectIds.value = '';
                    filterStatus.value = '';
                    filterIntention.value = '';
                    persistFiltersAndLoad();
                };

                /** 本周：当周周一 ～ 周日（本地自然日） */
                const applyThisWeekCalendar = () => {
                    const today = new Date();
                    const mon = mondayOfLocalWeek(today);
                    const sun = sundayAfterMonday(mon);
                    filterDateStart.value = formatLocalYmd(mon);
                    filterDateEnd.value = formatLocalYmd(sun);
                    filterProjectIds.value = '';
                    filterStatus.value = '';
                    filterIntention.value = '';
                    persistFiltersAndLoad();
                };

                /** 本月：当月 1 号 ～ 月末（本地自然日） */
                const applyThisMonthCalendar = () => {
                    const today = new Date();
                    const y = today.getFullYear();
                    const m = today.getMonth();
                    const first = new Date(y, m, 1);
                    const last = new Date(y, m + 1, 0);
                    filterDateStart.value = formatLocalYmd(first);
                    filterDateEnd.value = formatLocalYmd(last);
                    filterProjectIds.value = '';
                    filterStatus.value = '';
                    filterIntention.value = '';
                    persistFiltersAndLoad();
                };

                /** 不限报备日期，保留楼盘/状态/意向 */
                const applyUnlimitedReportDates = () => {
                    filterDateStart.value = '';
                    filterDateEnd.value = '';
                    persistFiltersAndLoad();
                };

                /** 不限报备时间 + 清空楼盘/状态/意向 */
                const clearDateFilters = () => {
                    filterDateStart.value = '';
                    filterDateEnd.value = '';
                    filterProjectIds.value = '';
                    filterStatus.value = '';
                    filterIntention.value = '';
                    persistFiltersAndLoad();
                };

                // 工具函数：金额格式化
                const formatMoney = (val) => {
                    if (!val) return '0.00';
                    return (parseFloat(val) / 10000).toFixed(2) + 'w';
                };

                // 加载数据
                const loadFinance = async () => {
                    try {
                        const res = await fetch(buildFinanceQuery());
                        // 尝试解析 JSON，如果 PHP 报错返回了 HTML，这里会抛出错误
                        const text = await res.text();
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (e) {
                            console.error("JSON 解析失败，后端返回了:", text);
                            alert("数据加载失败，请按 F12 查看控制台中的详细错误");
                            return;
                        }

                        // 保存调试信息
                        if (data.debug) debugData.value = data.debug;

                        if (data.status === 'success') {
                            const pag = data.pagination && typeof data.pagination === 'object'
                                ? data.pagination
                                : { total: (data.deals || []).length, page: 1, per_page: listPerPage.value, total_pages: 1 };
                            financeData.value = {
                                stats: data.stats,
                                deals: data.deals || [],
                                pagination: {
                                    total: pag.total != null ? Number(pag.total) : 0,
                                    page: pag.page != null ? Number(pag.page) : 1,
                                    per_page: pag.per_page != null ? Number(pag.per_page) : listPerPage.value,
                                    total_pages: pag.total_pages != null ? Number(pag.total_pages) : 1,
                                },
                            };
                            listPage.value = financeData.value.pagination.page;
                            jumpPageInput.value = financeData.value.pagination.page;
                        } else {
                            alert('错误: ' + data.message);
                        }
                    } catch (error) {
                        console.error('API 请求错误:', error);
                    }
                };

                const showCommissionDetail = (deal) => {
                    const t = (deal && deal.finance_commission_detail) ? String(deal.finance_commission_detail) : '暂无计算说明';
                    alert(t);
                };

                const viewProof = (url) => {
                    window.open(url, '_blank');
                };

                const dealContractAttachments = (deal) => {
                    const raw = deal && deal.attachments != null ? String(deal.attachments) : '';
                    return raw.split(',').map((s) => s.trim()).filter(Boolean);
                };
                const openContractGallery = (deal) => {
                    if (!deal || !deal.id) return;
                    if (!dealContractAttachments(deal).length) return;
                    const url = 'admin_finance_attachments.php?id=' + encodeURIComponent(String(deal.id));
                    window.open(url, '_blank', 'noopener,noreferrer');
                };

                onMounted(async () => {
                    await loadScopeAgents();
                    const v = String(scopeSelect.value);
                    if (v !== '0') {
                        const ok = scopeAgents.value.some((a) => String(a.id) === v);
                        if (!ok) {
                            scopeSelect.value = '0';
                            try {
                                localStorage.setItem(LS_SCOPE, '0');
                            } catch (e) {}
                        }
                    }
                    loadFinance();
                });

                return {
                    financeData,
                    debugData,
                    showDebug,
                    sidebarOpen,
                    scopeAgents,
                    scopeSelect,
                    filterDateStart,
                    filterDateEnd,
                    filterProjectIds,
                    filterStatus,
                    filterIntention,
                    historyStatusFilterOptions,
                    clientIntentionFilterOptions,
                    persistFiltersAndLoad,
                    applyUnlimitedReportDates,
                    applyThisMonthCalendar,
                    applyThisWeekCalendar,
                    applyStaffDefaultToday,
                    clearDateFilters,
                    formatMoney,
                    loadFinance,
                    showCommissionDetail,
                    viewProof,
                    dealContractAttachments,
                    openContractGallery,
                    listPage,
                    listPerPage,
                    jumpPageInput,
                    goListPage,
                    onPerPageChange,
                    applyJumpPage
                };
            }
        });

        app.mount('#app');
    </script>
</body>
</html>