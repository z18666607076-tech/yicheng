<?php
// staff.php - 案场端 (v15.0: 历史记录全能搜索)
session_start();
header('Content-Type: text/html; charset=utf-8');

// === 0. 登录鉴权 ===
if (!isset($_SESSION['agent_id'])) { header('Location: login.php'); exit; }
$CURRENT_USER = [
    'id' => $_SESSION['agent_id'],
    'name' => $_SESSION['agent_name'] ?? '案场经理',
    'phone' => $_SESSION['agent_phone'] ?? '',
    'role' => $_SESSION['agent_role'] ?? 'staff',
    'company' => $_SESSION['agent_company'] ?? ''
];

// === 配置 ===
$COMMISSION_RATE = 0.03; 

// === 数据库 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); } catch (PDOException $e) { die("DB Error"); }

try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN biz_confirm_at DATETIME NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN refund_submitted_at DATETIME NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN commission_package_id INT UNSIGNED NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_finance_rejected_at DATETIME NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN performance_finance_reject_reason MEDIUMTEXT NULL');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN biz_confirm_attachments TEXT NULL COMMENT "业确附件图片URL逗号分隔"');
} catch (Throwable $e) { /* 已存在 */ }
try {
    $pdo->exec('ALTER TABLE filings MODIFY COLUMN sub_stages VARCHAR(255) NULL DEFAULT NULL');
} catch (Throwable $e) { /* 已是足够长度或权限 */ }
try {
    $pdo->exec('ALTER TABLE filings ADD COLUMN pre_lock_sign_bundle_at DATETIME NULL COMMENT "锁房签约认购书整步完成时间"');
} catch (Throwable $e) { /* 已存在 */ }

$action = $_GET['action'] ?? 'view';

require_once __DIR__ . '/includes/staff_scope_sql.php';
require_once __DIR__ . '/includes/agent_roles.php';
require_once __DIR__ . '/includes/filings_sub_stages_normalize.php';

/** 同部门案场同事在 agent_projects 里绑过的在售项目，并入楼盘下拉 */
function staff_merge_projects_by_dept_staff_bindings(PDO $pdo, int $departmentId, array &$byId) {
    if ($departmentId <= 0) {
        return;
    }
    $staffSql = agent_sql_has_role('ag_sd', 'staff');
    $stmt = $pdo->prepare(
        "SELECT DISTINCT p.id, p.name FROM projects p
         INNER JOIN agent_projects ap ON ap.project_id = p.id
         INNER JOIN agents ag_sd ON ag_sd.id = ap.agent_id
         WHERE p.is_deleted = 0 AND p.status = 1 AND ag_sd.department_id = ? AND ag_sd.is_deleted = 0 AND {$staffSql}"
    );
    $stmt->execute([$departmentId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byId[(int)$r['id']] = $r;
    }
}

/** status_log 的 CONCAT 占位符在 $params 中的下标，与 $setClauses 下标不一定一致（因部分 SET 项无 ?），拼接备注/凭证时必须按 ? 计数定位 */
function staff_append_to_status_log_placeholder(array $setClauses, array &$params, $fragment) {
    if ($fragment === '' || $setClauses === []) {
        return;
    }
    $pIdx = 0;
    foreach ($setClauses as $clause) {
        if (strpos((string)$clause, 'status_log') !== false) {
            if (array_key_exists($pIdx, $params)) {
                $params[$pIdx] .= $fragment;
            }
            return;
        }
        $pIdx += substr_count((string)$clause, '?');
    }
}

function staff_remove_status_log_placeholder(array &$setClauses, array &$params) {
    if ($setClauses === []) return;
    $pIdx = 0;
    foreach ($setClauses as $i => $clause) {
        $clauseText = (string)$clause;
        $qCount = substr_count($clauseText, '?');
        if (strpos($clauseText, 'status_log') !== false) {
            if ($qCount > 0) {
                array_splice($params, $pIdx, $qCount);
            }
            array_splice($setClauses, $i, 1);
            return;
        }
        $pIdx += $qCount;
    }
}

function staff_is_nochange_log_recent($statusLog, $actorName, $withinMinutes = 5) {
    $statusLog = (string)$statusLog;
    if ($statusLog === '') return false;
    $actorName = trim((string)$actorName);
    if ($actorName === '') return false;
    $lines = preg_split('/\r\n|\n|\r/', $statusLog);
    $nowTs = time();
    $needle = "更新下定进度: 无变化";
    foreach (array_reverse($lines) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (strpos($line, $needle) === false) continue;
        if (strpos($line, "[案场·{$actorName}]") === false) continue;
        if (!preg_match('/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2})\s+\[案场·/u', $line, $m)) continue;
        $ts = strtotime($m[1] . ':00');
        if (!$ts) continue;
        if (($nowTs - $ts) <= ($withinMinutes * 60)) return true;
        return false;
    }
    return false;
}

/**
 * 下定子阶段相对上次保存新勾选时，写入 status_log 的片段。
 * 诚意金+认筹+签认购书在同一笔中首次同时勾选时，合并记为「锁房 签约认购书」一条。
 * $timeOverrides：键为 deposit|lock|sign|subscription|contract|biz_confirm|refund_submit，值为已通过 staff_normalize_datetime_input 的字符串，缺省则用当前服务器时间。
 */
/** 已勾选阶段的时间被修改时写入 status_log（与库字段一致，供列表回显） */
function staff_deal_stage_time_revision_log_fragment(array $old, string $subStages, array $normTimes): string
{
    $map = [
        'subscription' => ['label' => '认购', 'col' => 'subscription_at'],
        'contract' => ['label' => '签约', 'col' => 'contract_signed_at'],
        'biz_confirm' => ['label' => '业确', 'col' => 'biz_confirm_at'],
        'refund_submit' => ['label' => '退房', 'col' => 'refund_submitted_at'],
    ];
    $parts = [];
    foreach ($map as $key => $meta) {
        if (strpos($subStages, $key) === false) {
            continue;
        }
        $newV = trim((string)($normTimes[$key] ?? ''));
        if ($newV === '') {
            continue;
        }
        $oldV = trim((string)($old[$meta['col']] ?? ''));
        if ($oldV === $newV) {
            continue;
        }
        $parts[] = $meta['label'] . ' ' . $newV;
    }
    $hasBundle = strpos($subStages, 'deposit') !== false && strpos($subStages, 'lock') !== false && strpos($subStages, 'sign') !== false;
    if ($hasBundle) {
        $newBundle = trim((string)($normTimes['pre_lock_sign_bundle'] ?? ''));
        if ($newBundle !== '') {
            $oldBundle = trim((string)($old['pre_lock_sign_bundle_at'] ?? ''));
            if ($oldBundle !== $newBundle) {
                $parts[] = '锁房 签约认购书 ' . $newBundle;
            }
        }
    }
    return $parts === [] ? '' : ' [阶段时间修订: ' . implode('；', $parts) . ']';
}

function staff_substage_newly_checked_log_fragment(string $oldSubStages, string $newSubStages, array $timeOverrides = []): string {
    $map = ['deposit' => '诚意金', 'lock' => '认筹', 'sign' => '签认购书', 'subscription' => '认购', 'contract' => '签约', 'biz_confirm' => '业确', 'refund_submit' => '退房'];
    $newly = [];
    foreach ($map as $key => $label) {
        $had = strpos($oldSubStages, $key) !== false;
        $has = strpos($newSubStages, $key) !== false;
        if ($has && !$had) {
            $ts = date('Y-m-d H:i:s');
            if (array_key_exists($key, $timeOverrides)) {
                $norm = staff_normalize_datetime_input($timeOverrides[$key]);
                if ($norm !== false && $norm !== null) {
                    $ts = $norm;
                }
            }
            $newly[$key] = $ts;
        }
    }
    if ($newly === []) {
        return '';
    }
    $bundleKeys = ['deposit', 'lock', 'sign'];
    $bundleHit = true;
    foreach ($bundleKeys as $bk) {
        if (!array_key_exists($bk, $newly)) {
            $bundleHit = false;
            break;
        }
    }
    $parts = [];
    if ($bundleHit) {
        $tsShow = $newly['sign'];
        $parts[] = '锁房 签约认购书 ' . $tsShow;
        foreach ($newly as $key => $ts) {
            if (!in_array($key, $bundleKeys, true)) {
                $parts[] = $map[$key] . ' ' . $ts;
            }
        }
    } else {
        foreach ($newly as $key => $ts) {
            $parts[] = $map[$key] . ' ' . $ts;
        }
    }
    return ' [勾选时间: ' . implode('；', $parts) . ']';
}

/** 案场标记退房时写入 status_log，供工作台按退房操作时间筛选 */
function staff_refund_operation_time_log_fragment(): string
{
    return ' [退房操作时间:' . date('Y-m-d H:i:s') . ']';
}

function staff_normalize_datetime_input($value) {
    $value = trim((string)$value);
    if ($value === '') return null;
    $value = str_replace('T', ' ', $value);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value)) {
        $value .= ':00';
    }
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $value);
    if (!$dt || $dt->format('Y-m-d H:i:s') !== $value) return false;
    return $value;
}

/** 客户主号码：取第一段，尽量保留数字，写入 client_phone */
function staff_normalize_client_phone_input($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    // 脱敏展示（含 *）勿入库，避免 158****9187 被当成 1589187
    if (strpos($text, '*') !== false) {
        return '';
    }
    $parts = preg_split('/[,，、;；\/|]/u', $text, 2);
    $first = trim((string)($parts[0] ?? ''));
    $first = preg_replace('/\s+/', '', $first);
    if ($first === '') return '';
    $digits = preg_replace('/\D+/', '', $first);
    if ($digits !== '') {
        return mb_substr($digits, 0, 32, 'UTF-8');
    }
    return mb_substr($first, 0, 255, 'UTF-8');
}

/** 认购/补全多号码：逗号分隔去重，最长 255 */
function staff_normalize_multi_phone_input($value) {
    $text = trim((string)$value);
    if ($text === '') return '';
    $text = str_replace(["\r\n", "\r", "\n", "，", "、", ";", "；", "/", "|"], ',', $text);
    $parts = explode(',', $text);
    $result = [];
    foreach ($parts as $part) {
        $one = trim((string)$part);
        if ($one === '') continue;
        $one = preg_replace('/\s+/', '', $one);
        if ($one === '') continue;
        if (!in_array($one, $result, true)) {
            $result[] = $one;
        }
    }
    $normalized = implode(',', $result);
    if (mb_strlen($normalized, 'UTF-8') > 255) {
        $normalized = mb_substr($normalized, 0, 255, 'UTF-8');
    }
    return $normalized;
}

/** 批量挂跟进记录，避免 get_list 在大量报备下 N+1 查询 */
function staff_attach_followups_batch(PDO $pdo, array &$list): void {
    if ($list === []) {
        return;
    }
    $ids = [];
    foreach ($list as $row) {
        if (isset($row['id'])) {
            $ids[] = (int)$row['id'];
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        foreach ($list as &$item) {
            $item['followups'] = [];
        }
        unset($item);
        return;
    }
    $byFiling = [];
    $chunkSize = 500;
    for ($off = 0, $n = count($ids); $off < $n; $off += $chunkSize) {
        $chunk = array_slice($ids, $off, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $fuSql = "SELECT * FROM filing_followups WHERE filing_id IN ($ph) ORDER BY filing_id ASC, followup_count ASC";
        $fuStmt = $pdo->prepare($fuSql);
        $fuStmt->execute($chunk);
        foreach ($fuStmt->fetchAll(PDO::FETCH_ASSOC) as $fu) {
            $fid = (int)$fu['filing_id'];
            if (!isset($byFiling[$fid])) {
                $byFiling[$fid] = [];
            }
            $byFiling[$fid][] = $fu;
        }
    }
    foreach ($list as &$item) {
        $fid = (int)($item['id'] ?? 0);
        $item['followups'] = $byFiling[$fid] ?? [];
    }
    unset($item);
}

/** 财务业绩驳回历史（多条），供案场列表展示 */
function staff_ensure_filing_finance_performance_rejects_table(PDO $pdo): void
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

function staff_attach_finance_reject_history_batch(PDO $pdo, array &$list): void
{
    if ($list === []) {
        return;
    }
    staff_ensure_filing_finance_performance_rejects_table($pdo);
    $ids = [];
    foreach ($list as $row) {
        if (isset($row['id'])) {
            $ids[] = (int)$row['id'];
        }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    if ($ids === []) {
        foreach ($list as &$item) {
            $item['finance_reject_history'] = [];
        }
        unset($item);
        return;
    }
    $byFiling = [];
    $chunkSize = 500;
    for ($off = 0, $n = count($ids); $off < $n; $off += $chunkSize) {
        $chunk = array_slice($ids, $off, $chunkSize);
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $sql = "SELECT filing_id, reject_reason, rejected_at FROM filing_finance_performance_rejects WHERE filing_id IN ($ph) ORDER BY filing_id ASC, rejected_at DESC";
        $st = $pdo->prepare($sql);
        $st->execute($chunk);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $h) {
            $fid = (int)$h['filing_id'];
            if (!isset($byFiling[$fid])) {
                $byFiling[$fid] = [];
            }
            $byFiling[$fid][] = [
                'reject_reason' => $h['reject_reason'],
                'rejected_at' => $h['rejected_at'],
            ];
        }
    }
    foreach ($list as &$item) {
        $fid = (int)($item['id'] ?? 0);
        $item['finance_reject_history'] = $byFiling[$fid] ?? [];
    }
    unset($item);
}

/** 与 api.php 中 ensure_project_commission_packages_schema 一致，供案场拉取项目佣金套餐 */
function staff_ensure_project_commission_packages_schema(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
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
}

$SCOPE_MEMBERS = get_scope_members_staff($pdo, $CURRENT_USER);

// [API] 项目启用中的佣金套餐（案场录入下定签约区块下拉）
if ($action == 'get_commission_packages') {
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int)($_GET['project_id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode([]);
        exit;
    }
    staff_ensure_project_commission_packages_schema($pdo);
    $stmt = $pdo->prepare('SELECT id, package_name, commission_pct, cash_reward, jump_ratio, jump_reward FROM project_commission_packages WHERE project_id = ? AND is_enabled = 1 ORDER BY sort_order ASC, id ASC');
    $stmt->execute([$pid]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit;
}

// [API] 获取列表
if ($action == 'get_list') {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $sql = "SELECT f.*, p.name as project_name, 
            COALESCE(NULLIF(f.broker_name,''), a.username) as agent_name, 
            COALESCE(NULLIF(f.broker_phone,''), a.phone) as agent_phone,
            c.name as company_full_name, c.store_name
            FROM filings f 
            LEFT JOIN projects p ON f.project_id = p.id 
            LEFT JOIN agents a ON f.agent_id = a.id
            LEFT JOIN companies c ON f.company_name = c.name
            WHERE {$scope['sql']}
            ORDER BY f.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    staff_attach_followups_batch($pdo, $list);
    staff_attach_finance_reject_history_batch($pdo, $list);
    echo json_encode($list);
    exit;
}

// [API] 搜索历史记录
if ($action == 'search_history') {
    header('Content-Type: application/json');
    $keyword = $_GET['keyword'] ?? '';
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $today = date('Y-m-d');
    
    if (empty($keyword)) {
        echo json_encode([]);
        exit;
    }

    $kw = trim((string)$keyword);
    $like = '%' . $kw . '%';
    $phoneSqlParts = ['f.client_phone LIKE ?'];
    $phoneBinds = [$like];
    $starClean = preg_replace('/[\s\-]+/u', '', $kw);
    if (preg_match('/^(\d{2,4})\*+(\d{4})$/u', $starClean, $m)) {
        $phoneSqlParts[] = '(f.client_phone LIKE ? AND f.client_phone LIKE ?)';
        $phoneBinds[] = $m[1] . '%';
        $phoneBinds[] = '%' . $m[2];
    }
    if (preg_match('/^\d{4}$/', $kw)) {
        $phoneSqlParts[] = 'f.client_phone LIKE ?';
        $phoneBinds[] = '%' . $kw;
    }
    $phoneSql = '(' . implode(' OR ', $phoneSqlParts) . ')';

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
            AND (f.client_name LIKE ? OR $phoneSql OR p.name LIKE ? OR f.broker_name LIKE ? OR f.broker_phone LIKE ? OR a.username LIKE ? OR a.phone LIKE ? OR f.company_name LIKE ? OR c.name LIKE ? OR c.store_name LIKE ?)
            ORDER BY f.created_at DESC";
    $stmt = $pdo->prepare($sql);
    $params = array_merge(
        $scope['params'],
        [$today, $like],
        $phoneBinds,
        array_fill(0, 9, $like)
    );
    $stmt->execute($params);
    $list = $stmt->fetchAll(PDO::FETCH_ASSOC);
    staff_attach_followups_batch($pdo, $list);
    staff_attach_finance_reject_history_batch($pdo, $list);
    echo json_encode($list);
    exit;
}

// [API] 获取项目（纯项目驻场：仅 admin_projects 管理员关联盘；其余角色为报备可见盘 + 管理员盘）
if ($action == 'get_projects') {
    header('Content-Type: application/json; charset=utf-8');
    $memberId = intval($_GET['member_id'] ?? 0);

    if ($memberId > 0) {
        $mgrNames = [];
        $mgrPhones = [];
        foreach ($SCOPE_MEMBERS as $m) {
            if ((int)$m['id'] === $memberId) {
                if (!empty($m['username'])) {
                    $mgrNames[] = $m['username'];
                }
                if (!empty($m['phone'])) {
                    $mgrPhones[] = $m['phone'];
                }
                break;
            }
        }
    } else {
        $mgrNames = array_values(array_unique(array_filter(array_column($SCOPE_MEMBERS, 'username'))));
        $mgrPhones = array_values(array_unique(array_filter(array_column($SCOPE_MEMBERS, 'phone'))));
    }
    $mgrParts = [];
    $mgrParams = [];
    if (!empty($mgrNames)) {
        $mgrParts[] = 'manager_name IN (' . implode(',', array_fill(0, count($mgrNames), '?')) . ')';
        foreach ($mgrNames as $n) {
            $mgrParams[] = $n;
        }
    }
    if (!empty($mgrPhones)) {
        $mgrParts[] = 'manager_phone IN (' . implode(',', array_fill(0, count($mgrPhones), '?')) . ')';
        foreach ($mgrPhones as $ph) {
            $mgrParams[] = $ph;
        }
    }

    if (staff_is_strict_project_site_scope($CURRENT_USER)) {
        $byIdStrict = [];
        if (!empty($mgrParts)) {
            $sql2 = 'SELECT id, name FROM projects WHERE is_deleted = 0 AND status = 1 AND (' . implode(' OR ', $mgrParts) . ')';
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($mgrParams);
            foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byIdStrict[(int)$r['id']] = $r;
            }
        }
        $bindAid = $memberId > 0 ? $memberId : (int)($CURRENT_USER['id'] ?? 0);
        if ($bindAid > 0) {
            $stmtAp = $pdo->prepare('SELECT p.id, p.name FROM projects p INNER JOIN agent_projects ap ON ap.project_id = p.id WHERE p.is_deleted = 0 AND p.status = 1 AND ap.agent_id = ?');
            $stmtAp->execute([$bindAid]);
            foreach ($stmtAp->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $byIdStrict[(int)$r['id']] = $r;
            }
        }
        if (empty($byIdStrict)) {
            echo json_encode([]);
            exit;
        }
        $list = array_values($byIdStrict);
        usort($list, function ($a, $b) {
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });
        echo json_encode($list);
        exit;
    }

    $scope = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, $memberId, $CURRENT_USER);

    $sql = "SELECT DISTINCT p.id, p.name
            FROM filings f
            LEFT JOIN projects p ON f.project_id = p.id
            LEFT JOIN agents a ON f.agent_id = a.id
            LEFT JOIN companies c ON f.company_name = c.name
            WHERE p.id IS NOT NULL AND p.status = 1 AND ({$scope['sql']})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($scope['params']);
    $byId = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $byId[(int)$r['id']] = $r;
    }

    if (!empty($mgrParts)) {
        $sql2 = 'SELECT id, name FROM projects WHERE is_deleted = 0 AND status = 1 AND (' . implode(' OR ', $mgrParts) . ')';
        $stmt2 = $pdo->prepare($sql2);
        $stmt2->execute($mgrParams);
        foreach ($stmt2->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $pid = (int)$r['id'];
            if (!isset($byId[$pid])) {
                $byId[$pid] = $r;
            }
        }
    }
    $bindAidProj = $memberId > 0 ? $memberId : (int)($CURRENT_USER['id'] ?? 0);
    staff_merge_projects_by_dept_staff_bindings($pdo, staff_agent_department_id($pdo, $bindAidProj), $byId);

    $list = array_values($byId);
    usort($list, function ($a, $b) {
        return strcmp($a['name'] ?? '', $b['name'] ?? '');
    });
    echo json_encode($list);
    exit;
}

if ($action == 'get_team_members') {
    header('Content-Type: application/json');
    echo json_encode([
        'can_filter' => can_view_team_data_staff($CURRENT_USER),
        'members' => $SCOPE_MEMBERS
    ]);
    exit;
}

// [API] 状态更新
if ($action == 'update') {
    header('Content-Type: application/json');
    global $COMMISSION_RATE;

    $id = $_POST['id']; 
    $currStatus = intval($_POST['curr_status']); 
    $saveType = $_POST['save_type'] ?? 'submit'; 
    
    $visitType = $_POST['visit_type'] ?? 0;
    $visitType = $visitType === 'null' ? null : intval($visitType);
    $clientIntention = intval($_POST['client_intention'] ?? 0);
    $subStages = filings_normalize_sub_stages_csv((string)($_POST['sub_stages'] ?? '')); 
    $voice = $_POST['voice'] ?? ''; 
    $attach = $_POST['attachment'] ?? '';
    $visitTimeRaw = $_POST['visit_time'] ?? '';
    $visitTime = staff_normalize_datetime_input($visitTimeRaw);
    if ($visitTime === false) { echo json_encode(['status'=>'error', 'msg'=>'到访时间格式不正确']); exit; }
    $room = $_POST['room_number'] ?? ''; 
    $price = $_POST['deal_price'] ?? 0;
    $clientPhone = staff_normalize_client_phone_input($_POST['client_phone'] ?? '');
    
    $subscriberName = trim((string)($_POST['subscriber_name'] ?? ''));
    $subscribedRoomNumber = $_POST['subscribed_room_number'] ?? '';
    $transactionArea = $_POST['transaction_area'] ?? 0;
    $salesperson = $_POST['salesperson'] ?? '';
    $subscriptionPhoneFull = staff_normalize_multi_phone_input($_POST['subscription_phone_full'] ?? '');
    $subscriptionDate = $_POST['subscription_date'] ?? '';
    $transactionRecorder = $_POST['transaction_recorder'] ?? '';
    $subscriptionAmountRaw = trim((string)($_POST['subscription_amount'] ?? ''));
    $subscriptionAtRaw = $_POST['subscription_at'] ?? '';
    $contractTotalPriceRaw = trim((string)($_POST['contract_total_price'] ?? ''));
    $contractSignedAtRaw = $_POST['contract_signed_at'] ?? '';
    $transactionAmountRaw = trim((string)($_POST['transaction_amount'] ?? ''));
    $bizConfirmAtRaw = $_POST['biz_confirm_at'] ?? '';
    $refundSubmittedAtRaw = $_POST['refund_submitted_at'] ?? '';
    $preLockSignBundleAtRaw = $_POST['pre_lock_sign_bundle_at'] ?? '';
    $commissionPackageIdPost = (int)($_POST['commission_package_id'] ?? 0);

    /** 仅以 sub_stages 勾选为准：取消勾选后即使前端仍带时间 POST，也不入库，避免「未勾退房但库里仍有退房时间」 */
    if (strpos($subStages, 'subscription') === false) {
        $subscriptionAtRaw = '';
        $subscriptionAmountRaw = '';
    }
    if (strpos($subStages, 'contract') === false) {
        $contractSignedAtRaw = '';
        $contractTotalPriceRaw = '';
        $transactionAmountRaw = '';
        $commissionPackageIdPost = 0;
    }
    if (strpos($subStages, 'biz_confirm') === false) {
        $bizConfirmAtRaw = '';
    }
    if (strpos($subStages, 'refund_submit') === false) {
        $refundSubmittedAtRaw = '';
    }
    $hasPreLockBundlePost = strpos($subStages, 'deposit') !== false && strpos($subStages, 'lock') !== false && strpos($subStages, 'sign') !== false;
    if (!$hasPreLockBundlePost) {
        $preLockSignBundleAtRaw = '';
    }
    $bizConfirmAttachRaw = trim((string)($_POST['biz_confirm_attachment'] ?? ''));
    if (strpos($subStages, 'biz_confirm') === false) {
        $bizConfirmAttachRaw = '';
    }
    $bizConfirmAttachNorm = implode(',', array_values(array_filter(array_map('trim', explode(',', preg_replace('/[\r\n\t]+/', '', $bizConfirmAttachRaw))))));

    $substageTimeOverrides = [];
    foreach (['deposit', 'lock', 'sign', 'subscription', 'contract', 'biz_confirm', 'refund_submit'] as $sk) {
        if (strpos($subStages, $sk) === false) {
            continue;
        }
        $raw = $_POST['substage_at_' . $sk] ?? null;
        if ($raw === null || $raw === '') {
            continue;
        }
        $norm = staff_normalize_datetime_input(is_string($raw) ? $raw : '');
        if ($norm !== false && $norm !== null) {
            $substageTimeOverrides[$sk] = $norm;
        }
    }
    
    $__staffActor = trim((string)($CURRENT_USER['name'] ?? ''));
    if ($__staffActor === '') $__staffActor = '案场';
    $__staffActor = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__staffActor);
    $logPrefix = "\n" . date('Y-m-d H:i') . " [案场·{$__staffActor}] ";
    $sql = ""; $params = []; $setClauses = [];
    $oldStmt = $pdo->prepare("SELECT * FROM filings WHERE id = ? LIMIT 1");
    $oldStmt->execute([$id]);
    $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
    if (!$old) { echo json_encode(['status'=>'error', 'msg'=>'记录不存在']); exit; }

    if (staff_is_strict_project_site_scope($CURRENT_USER)) {
        $scopeUpd = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, 0, $CURRENT_USER);
        $chkScope = $pdo->prepare("SELECT 1 FROM filings f LEFT JOIN projects p ON f.project_id = p.id LEFT JOIN companies c ON f.company_name = c.name WHERE f.id = ? AND ({$scopeUpd['sql']}) LIMIT 1");
        // 占位符顺序：先是 f.id=?，再是 scope 内各 ?，参数须与此一致
        $chkScope->execute(array_merge([(int)$id], $scopeUpd['params']));
        if (!$chkScope->fetchColumn()) {
            echo json_encode(['status' => 'error', 'msg' => '无权限操作此报备']);
            exit;
        }
    }

    if ($currStatus == 1) {
            if($visitType === null) {
                // 取消到访类型选择，变回待处理
                $log = $logPrefix . "取消到访类型，变回待处理"; 
                $targetStatus = 1;
                $setClauses = ["status = ?", "visit_type = NULL", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
                $params = [$targetStatus, $log];
            } else {
                // 只有当不是报备无效时，才检查客户意向
                if($visitType != 4 && empty($clientIntention)) { echo json_encode(['status'=>'error', 'msg'=>'请选择客户意向']); exit; }
                $needsVisitTime = in_array($visitType, [1, 2, 3], true);
                if ($needsVisitTime && $visitTime === null) { echo json_encode(['status'=>'error', 'msg'=>'请选择到访时间']); exit; }
                if ($visitType == 0) { $log = $logPrefix . "确认有效报备"; $targetStatus = 1; } 
                elseif ($visitType == 1) { $log = $logPrefix . "确认有效到访"; $targetStatus = 2; } 
                elseif ($visitType == 4) { 
                    $log = $logPrefix . "标记为报备无效"; 
                    $targetStatus = 6; 
                }
                else { 
                    $desc = ($visitType == 2) ? '无效到访' : '重复到访'; 
                    $log = $logPrefix . "标记为" . $desc; 
                    $targetStatus = 5; 
                }
                if ($needsVisitTime) $log .= " [到访时间:$visitTime]";
                // 只有当不是报备无效时，才设置client_intention
                if($visitType == 4) {
                    $setClauses = ["status = ?", "visit_type = ?", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
                    $params = [$targetStatus, $visitType, $log];
                } else {
                    $setClauses = ["status = ?", "visit_type = ?", "client_intention = ?", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
                    $params = [$targetStatus, $visitType, $clientIntention, $log];
                    if ($needsVisitTime) {
                        $setClauses[] = "visit_time = ?";
                        $params[] = $visitTime;
                    }
                }
                if ($attach) { $setClauses[] = "attachments = ?"; $params[] = $attach; }
                if ($salesperson !== '') {
                    $setClauses[] = "salesperson = ?";
                    $params[] = $salesperson;
                }
            }
    }
    elseif ($currStatus == 5 || $currStatus == 6) {
        // 从无效/重复/报备无效状态修改为待处理状态
        $log = $logPrefix . "重新标记为待处理";
        $targetStatus = 1;
        $setClauses = ["status = ?", "visit_type = 0", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
        $params = [$targetStatus, $log];
        if ($attach) { $setClauses[] = "attachments = ?"; $params[] = $attach; }
    }
    elseif ($currStatus == 2 || ($currStatus == 3 && $saveType === 'save') || (($currStatus == 4 || $currStatus == 7) && $saveType === 'save')) {
        // 处理无效到访情况（仅 status=2）
        if ($currStatus == 2 && $visitType == 2) {
            $log = $logPrefix . "标记为无效到访";
            $targetStatus = 5;
            $setClauses = ["status = ?", "visit_type = ?", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
            $params = [$targetStatus, $visitType, $log];
            if ($attach) { $setClauses[] = "attachments = ?"; $params[] = $attach; }
        } else {
            $stagesArr = [];
            if(strpos($subStages, 'deposit')!==false) $stagesArr[] = '已交定';
            if(strpos($subStages, 'lock')!==false) $stagesArr[] = '已认筹';
            if(strpos($subStages, 'sign')!==false) $stagesArr[] = '已认购';
            if(strpos($subStages, 'subscription')!==false) $stagesArr[] = '已认购登记';
            if(strpos($subStages, 'contract')!==false) $stagesArr[] = '已签约';
            if(strpos($subStages, 'biz_confirm')!==false) $stagesArr[] = '已业确';
            if(strpos($subStages, 'refund_submit')!==false) $stagesArr[] = '已退房';
            $stageStr = implode('/', $stagesArr);

            if ($saveType === 'save') {
                $targetStatus = ($currStatus == 4) ? 4 : (($currStatus == 7) ? 7 : (($currStatus == 3) ? 3 : 2));
                $revertedRefundCompleteToDeal = false;
                /** 已退房完成(7)但本次未勾选「退房」进度：清空退房时间（见下）并退出「退房完成」状态，回到已成交/待录入 */
                if ($currStatus == 7 && strpos($subStages, 'refund_submit') === false) {
                    $revertedRefundCompleteToDeal = true;
                    $dealVal = (float)($old['deal_price'] ?? 0);
                    if ($price !== '' && is_numeric($price)) {
                        $dealVal = max($dealVal, (float) $price);
                    }
                    $targetStatus = ($dealVal > 0) ? 4 : 3;
                }
                if ($currStatus == 4) {
                    $log = $logPrefix . "已成交补充进度: " . ($stageStr ?: '无变化');
                } elseif ($currStatus == 7) {
                    if ($revertedRefundCompleteToDeal) {
                        $log = $logPrefix . '未勾选退房进度，已清空退房时间并恢复为' . ($targetStatus == 4 ? '已成交' : '待录入成交') . ($stageStr !== '' ? " ({$stageStr})" : '');
                    } else {
                        $log = $logPrefix . "退房维护进度: " . ($stageStr ?: '无变化');
                    }
                } elseif ($currStatus == 3) {
                    $log = $logPrefix . "待录入成交维护进度: " . ($stageStr ?: '无变化');
                } else {
                    $log = $logPrefix . "更新下定进度: " . ($stageStr ?: '无变化');
                }
            } else {
                if ($currStatus == 4 || $currStatus == 7) {
                    echo json_encode(['status' => 'error', 'msg' => '请使用「保存进度」维护进度']);
                    exit;
                }
                $targetStatus = 3;
                $log = $logPrefix . "完成下定流程 ($stageStr)，进入成交客户";
            }
            if($room) $log .= " [房号:$room]";
            $log .= staff_substage_newly_checked_log_fragment((string)($old['sub_stages'] ?? ''), (string)$subStages, $substageTimeOverrides);
            $setClauses = ["status = ?", "room_number = ?", "attachments = ?", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
            $params = [$targetStatus, $room, $attach, $log];
            // 即使subStages为空字符串，也应该保存到数据库中
            $setClauses[] = "sub_stages = ?";
            $params[] = $subStages;
            $setClauses[] = "subscriber_name = ?";
            $params[] = ($subscriberName === '') ? null : $subscriberName;
            if ($transactionArea !== '') {
                $setClauses[] = "transaction_area = ?";
                $params[] = $transactionArea;
            }
            if ($price !== '' && is_numeric($price) && floatval($price) > 0) {
                $setClauses[] = "deal_price = ?";
                $params[] = $price;
            }
            $hasSignStage = strpos($subStages, 'sign') !== false;
            $hasSubscriptionStage = strpos($subStages, 'subscription') !== false;
            $hasContractStage = strpos($subStages, 'contract') !== false;
            // 已勾选「签认购书」时须写入客户号码（含清空）；认购联系方式在勾选「认购」时同样须落库
            if ($hasSignStage) {
                $setClauses[] = "client_phone = ?";
                $params[] = $clientPhone;
            } else {
                if ($clientPhone !== '') {
                    $setClauses[] = "client_phone = ?";
                    $params[] = $clientPhone;
                }
            }
            if ($hasSignStage || $hasSubscriptionStage) {
                $setClauses[] = "subscription_phone_full = ?";
                $params[] = $subscriptionPhoneFull;
            } else {
                if ($subscriptionPhoneFull !== '') {
                    $setClauses[] = "subscription_phone_full = ?";
                    $params[] = $subscriptionPhoneFull;
                }
            }
            $subscriptionAtNorm = staff_normalize_datetime_input(is_string($subscriptionAtRaw) ? str_replace('T', ' ', trim($subscriptionAtRaw)) : '');
            if ($subscriptionAtNorm === false) {
                echo json_encode(['status' => 'error', 'msg' => '认购时间格式不正确']);
                exit;
            }
            $contractSignedNorm = staff_normalize_datetime_input(is_string($contractSignedAtRaw) ? str_replace('T', ' ', trim($contractSignedAtRaw)) : '');
            if ($contractSignedNorm === false) {
                echo json_encode(['status' => 'error', 'msg' => '签约时间格式不正确']);
                exit;
            }
            $bizConfirmNorm = staff_normalize_datetime_input(is_string($bizConfirmAtRaw) ? str_replace('T', ' ', trim($bizConfirmAtRaw)) : '');
            if ($bizConfirmNorm === false) {
                echo json_encode(['status' => 'error', 'msg' => '业确时间格式不正确']);
                exit;
            }
            $refundSubmittedNorm = staff_normalize_datetime_input(is_string($refundSubmittedAtRaw) ? str_replace('T', ' ', trim($refundSubmittedAtRaw)) : '');
            if ($refundSubmittedNorm === false) {
                echo json_encode(['status' => 'error', 'msg' => '退房时间格式不正确']);
                exit;
            }
            $preLockBundleNorm = staff_normalize_datetime_input(is_string($preLockSignBundleAtRaw) ? str_replace('T', ' ', trim($preLockSignBundleAtRaw)) : '');
            if ($preLockBundleNorm === false) {
                echo json_encode(['status' => 'error', 'msg' => '锁房签约认购书时间格式不正确']);
                exit;
            }
            $hasPreLockBundle = strpos($subStages, 'deposit') !== false && strpos($subStages, 'lock') !== false && strpos($subStages, 'sign') !== false;
            $hasBizConfirmStage = strpos($subStages, 'biz_confirm') !== false;
            $hasRefundSubmitStage = strpos($subStages, 'refund_submit') !== false;
            if ($hasSubscriptionStage) {
                if ($subscriptionAtNorm !== null && $subscriptionAtNorm !== '') {
                    $setClauses[] = 'subscription_at = ?';
                    $params[] = $subscriptionAtNorm;
                } elseif (trim((string)$subscriptionAtRaw) === '' && !empty($old['subscription_at'])) {
                    /* 保留原认购时间 */
                } else {
                    $setClauses[] = 'subscription_at = NULL';
                }
                if ($subscriptionAmountRaw !== '' && is_numeric($subscriptionAmountRaw)) {
                    $setClauses[] = 'subscription_amount = ?';
                    $params[] = round(floatval($subscriptionAmountRaw), 2);
                } else {
                    $setClauses[] = 'subscription_amount = NULL';
                }
            } else {
                $setClauses[] = 'subscription_at = NULL';
                $setClauses[] = 'subscription_amount = NULL';
            }
            if ($hasContractStage) {
                if ($contractSignedNorm !== null && $contractSignedNorm !== '') {
                    $setClauses[] = 'contract_signed_at = ?';
                    $params[] = $contractSignedNorm;
                } elseif (trim((string)$contractSignedAtRaw) === '' && !empty($old['contract_signed_at'])) {
                    /* 保留原签约时间 */
                } else {
                    $setClauses[] = 'contract_signed_at = NULL';
                }
                if ($contractTotalPriceRaw !== '' && is_numeric($contractTotalPriceRaw)) {
                    $setClauses[] = 'contract_total_price = ?';
                    $params[] = round(floatval($contractTotalPriceRaw), 2);
                } else {
                    $setClauses[] = 'contract_total_price = NULL';
                }
                if ($transactionAmountRaw !== '' && is_numeric($transactionAmountRaw)) {
                    $setClauses[] = 'transaction_amount = ?';
                    $params[] = round(floatval($transactionAmountRaw), 2);
                } else {
                    $setClauses[] = 'transaction_amount = NULL';
                }
                staff_ensure_project_commission_packages_schema($pdo);
                if ($commissionPackageIdPost > 0) {
                    $pchk = $pdo->prepare('SELECT id FROM project_commission_packages WHERE id = ? AND project_id = ? AND is_enabled = 1 LIMIT 1');
                    $pchk->execute([$commissionPackageIdPost, (int)($old['project_id'] ?? 0)]);
                    if (!$pchk->fetchColumn()) {
                        echo json_encode(['status' => 'error', 'msg' => '佣金套餐无效或已停用']);
                        exit;
                    }
                    $setClauses[] = 'commission_package_id = ?';
                    $params[] = $commissionPackageIdPost;
                } else {
                    $setClauses[] = 'commission_package_id = NULL';
                }
            } else {
                $setClauses[] = 'contract_signed_at = NULL';
                $setClauses[] = 'contract_total_price = NULL';
                $setClauses[] = 'transaction_amount = NULL';
                $setClauses[] = 'commission_package_id = NULL';
            }
            if ($hasBizConfirmStage) {
                if ($bizConfirmNorm !== null && $bizConfirmNorm !== '') {
                    $setClauses[] = 'biz_confirm_at = ?';
                    $params[] = $bizConfirmNorm;
                } elseif (trim((string)$bizConfirmAtRaw) === '' && !empty($old['biz_confirm_at'])) {
                    /* 保留原业确时间 */
                } else {
                    $setClauses[] = 'biz_confirm_at = NULL';
                }
            } else {
                $setClauses[] = 'biz_confirm_at = NULL';
            }
            if ($hasBizConfirmStage) {
                $setClauses[] = 'biz_confirm_attachments = ?';
                $params[] = ($bizConfirmAttachNorm === '') ? null : $bizConfirmAttachNorm;
            } else {
                $setClauses[] = 'biz_confirm_attachments = NULL';
            }
            if ($hasRefundSubmitStage) {
                if ($refundSubmittedNorm !== null && $refundSubmittedNorm !== '') {
                    $setClauses[] = 'refund_submitted_at = ?';
                    $params[] = $refundSubmittedNorm;
                } elseif (trim((string)$refundSubmittedAtRaw) === '' && !empty($old['refund_submitted_at'])) {
                    /* 保留原退房时间 */
                } else {
                    $setClauses[] = 'refund_submitted_at = NULL';
                }
            } else {
                $setClauses[] = 'refund_submitted_at = NULL';
            }
            if ($hasPreLockBundle) {
                $bundleAt = $preLockBundleNorm;
                if ($bundleAt === null || $bundleAt === '') {
                    foreach (['sign', 'lock', 'deposit'] as $bk) {
                        if (!empty($substageTimeOverrides[$bk])) {
                            $bundleAt = $substageTimeOverrides[$bk];
                            break;
                        }
                    }
                }
                if ($bundleAt !== null && $bundleAt !== '') {
                    $setClauses[] = 'pre_lock_sign_bundle_at = ?';
                    $params[] = $bundleAt;
                } elseif (trim((string)$preLockSignBundleAtRaw) === '' && !empty($old['pre_lock_sign_bundle_at'])) {
                    /* POST 未带上时间时保留库内原值，避免误清空 */
                } else {
                    $setClauses[] = 'pre_lock_sign_bundle_at = NULL';
                }
            } else {
                $setClauses[] = 'pre_lock_sign_bundle_at = NULL';
            }
            $revisionNorm = [
                'subscription' => ($hasSubscriptionStage && $subscriptionAtNorm) ? (string)$subscriptionAtNorm : '',
                'contract' => ($hasContractStage && $contractSignedNorm) ? (string)$contractSignedNorm : '',
                'biz_confirm' => ($hasBizConfirmStage && $bizConfirmNorm) ? (string)$bizConfirmNorm : '',
                'refund_submit' => ($hasRefundSubmitStage && $refundSubmittedNorm) ? (string)$refundSubmittedNorm : '',
                'pre_lock_sign_bundle' => ($hasPreLockBundle && isset($bundleAt) && $bundleAt !== null && $bundleAt !== '') ? (string)$bundleAt : '',
            ];
            $logRevision = staff_deal_stage_time_revision_log_fragment($old, $subStages, $revisionNorm);
            if ($logRevision !== '') {
                staff_append_to_status_log_placeholder($setClauses, $params, $logRevision);
            }
            $setClauses[] = "client_intention = ?";
            $params[] = $clientIntention;
        }
    }
    elseif ($currStatus == 3) {
        if ($saveType === 'refund') {
            $log = $logPrefix . "标记为退房" . staff_refund_operation_time_log_fragment();
            $targetStatus = 7;
            $setClauses = ["status = ?", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
            $params = [$targetStatus, $log];
            if ($attach) { $setClauses[] = "attachments = ?"; $params[] = $attach; }
        } else {
            if (empty($price) || $price <= 0) { echo json_encode(['status'=>'error', 'msg'=>'请填写认购总价']); exit; } 
            $comm_amt = $price * $COMMISSION_RATE;
            $log = $logPrefix . "正式签约 [总价:¥{$price}, 佣:¥{$comm_amt}]";
            $setClauses = ["status = 4", "deal_price = ?", "commission_amount = ?", "commission_status = 0", "status_log = CONCAT(IFNULL(status_log,''), ?)"];
            $params = [$price, $comm_amt, $log];
            if ($attach) { $setClauses[] = "attachments = ?"; $params[] = $attach; }
            if ($clientPhone) {
                $setClauses[] = "client_phone = ?";
                $params[] = $clientPhone;
            }
            $setClauses[] = "subscriber_name = ?";
            $params[] = ($subscriberName === '') ? null : $subscriberName;
            if ($subscribedRoomNumber !== '') {
                $setClauses[] = "subscribed_room_number = ?";
                $params[] = $subscribedRoomNumber;
            }
            if ($transactionArea !== '') {
                $setClauses[] = "transaction_area = ?";
                $params[] = $transactionArea;
            }
            if ($salesperson !== '') {
                $setClauses[] = "salesperson = ?";
                $params[] = $salesperson;
            }
            if ($subscriptionPhoneFull !== '') {
                $setClauses[] = "subscription_phone_full = ?";
                $params[] = $subscriptionPhoneFull;
            }
            if ($subscriptionDate !== '') {
                $setClauses[] = "subscription_date = ?";
                $params[] = $subscriptionDate;
            }
            if ($transactionRecorder !== '') {
                $setClauses[] = "transaction_recorder = ?";
                $params[] = $transactionRecorder;
            }
        }
    }

    // 本次操作若有上传凭证，把 URL 写入本条 status_log 片段，供案场「订单全流程」时间轴展示缩略图（与 filings.attachments 全量字段互补）
    if (!empty($attach)) {
        $urls = array_values(array_filter(array_map('trim', explode(',', $attach))));
        $safeUrls = [];
        foreach ($urls as $u) {
            $u = str_replace(["\r", "\n", "\t"], '', $u);
            if ($u !== '') {
                $safeUrls[] = $u;
            }
        }
        if (!empty($safeUrls)) {
            $attachMarker = ' [凭证] ' . implode('|', $safeUrls);
            staff_append_to_status_log_placeholder($setClauses, $params, $attachMarker);
        }
    }
    if ($currStatus == 2 && strpos((string)$subStages, 'biz_confirm') !== false && $bizConfirmAttachNorm !== '') {
        $bizUrls = array_values(array_filter(array_map('trim', explode(',', $bizConfirmAttachNorm))));
        $bizSafe = [];
        foreach ($bizUrls as $u) {
            $u = str_replace(["\r", "\n", "\t"], '', $u);
            if ($u !== '') {
                $bizSafe[] = $u;
            }
        }
        if (!empty($bizSafe)) {
            staff_append_to_status_log_placeholder($setClauses, $params, ' [业确附件] ' . implode('|', $bizSafe));
        }
    }

    if ($voice) {
        staff_append_to_status_log_placeholder($setClauses, $params, " (备注: $voice)");
    }

    // 无变更不写日志：先比较关键字段的新旧值，再决定是否保留 status_log 追加
    if (($currStatus == 2 || $currStatus == 3 || $currStatus == 4 || $currStatus == 7) && $saveType === 'save') {
        $stagesArr = [];
        if (strpos($subStages, 'deposit') !== false) $stagesArr[] = '已交定';
        if (strpos($subStages, 'lock') !== false) $stagesArr[] = '已认筹';
        if (strpos($subStages, 'sign') !== false) $stagesArr[] = '已认购';
        if (strpos($subStages, 'subscription') !== false) $stagesArr[] = '已认购登记';
        if (strpos($subStages, 'contract') !== false) $stagesArr[] = '已签约';
        if (strpos($subStages, 'biz_confirm') !== false) $stagesArr[] = '已业确';
        if (strpos($subStages, 'refund_submit') !== false) $stagesArr[] = '已退房';
        $stageStr = implode('/', $stagesArr);

        $subAtNormSave = staff_normalize_datetime_input(str_replace('T', ' ', trim((string)$subscriptionAtRaw)));
        if ($subAtNormSave === false) {
            $subAtNormSave = '';
        }
        $conSignedNormSave = staff_normalize_datetime_input(str_replace('T', ' ', trim((string)$contractSignedAtRaw)));
        if ($conSignedNormSave === false) {
            $conSignedNormSave = '';
        }
        $bizConfirmNormSave = staff_normalize_datetime_input(str_replace('T', ' ', trim((string)$bizConfirmAtRaw)));
        if ($bizConfirmNormSave === false) {
            $bizConfirmNormSave = '';
        }
        $refundSubmittedNormSave = staff_normalize_datetime_input(str_replace('T', ' ', trim((string)$refundSubmittedAtRaw)));
        if ($refundSubmittedNormSave === false) {
            $refundSubmittedNormSave = '';
        }
        $preLockBundleNormSave = staff_normalize_datetime_input(str_replace('T', ' ', trim((string)$preLockSignBundleAtRaw)));
        if ($preLockBundleNormSave === false) {
            $preLockBundleNormSave = '';
        }
        $hasPreLockBundleCmp = strpos($subStages, 'deposit') !== false && strpos($subStages, 'lock') !== false && strpos($subStages, 'sign') !== false;

        $comparePairs = [
            ['status', (string)$targetStatus],
            ['room_number', (string)$room],
            ['sub_stages', (string)$subStages],
            ['client_intention', (string)$clientIntention],
            ['attachments', (string)$attach],
            ['subscriber_name', (string)$subscriberName]
        ];
        if ($transactionArea !== '') $comparePairs[] = ['transaction_area', (string)$transactionArea];
        if ($price !== '' && is_numeric($price) && floatval($price) > 0) $comparePairs[] = ['deal_price', (string)$price];
        $cmpSign = strpos($subStages, 'sign') !== false;
        $cmpSubS = strpos($subStages, 'subscription') !== false;
        if ($cmpSign) {
            $comparePairs[] = ['client_phone', (string)$clientPhone];
        } else {
            if ($clientPhone !== '') {
                $comparePairs[] = ['client_phone', (string)$clientPhone];
            }
        }
        if ($cmpSign || $cmpSubS) {
            $comparePairs[] = ['subscription_phone_full', (string)$subscriptionPhoneFull];
        } else {
            if ($subscriptionPhoneFull !== '') {
                $comparePairs[] = ['subscription_phone_full', (string)$subscriptionPhoneFull];
            }
        }
        if ($cmpSubS) {
            $comparePairs[] = ['subscription_at', (string)($subAtNormSave ?? '')];
            $comparePairs[] = ['subscription_amount', ($subscriptionAmountRaw !== '' && is_numeric($subscriptionAmountRaw)) ? (string) round(floatval($subscriptionAmountRaw), 2) : ''];
        } else {
            $comparePairs[] = ['subscription_at', ''];
            $comparePairs[] = ['subscription_amount', ''];
        }
        $cmpContract = strpos($subStages, 'contract') !== false;
        if ($cmpContract) {
            $comparePairs[] = ['contract_signed_at', (string)($conSignedNormSave ?? '')];
            $comparePairs[] = ['contract_total_price', ($contractTotalPriceRaw !== '' && is_numeric($contractTotalPriceRaw)) ? (string) round(floatval($contractTotalPriceRaw), 2) : ''];
            $comparePairs[] = ['transaction_amount', ($transactionAmountRaw !== '' && is_numeric($transactionAmountRaw)) ? (string) round(floatval($transactionAmountRaw), 2) : ''];
            $comparePairs[] = ['commission_package_id', $commissionPackageIdPost > 0 ? (string) $commissionPackageIdPost : ''];
        } else {
            $comparePairs[] = ['contract_signed_at', ''];
            $comparePairs[] = ['contract_total_price', ''];
            $comparePairs[] = ['transaction_amount', ''];
            $comparePairs[] = ['commission_package_id', ''];
        }
        $cmpBizConfirm = strpos($subStages, 'biz_confirm') !== false;
        if ($cmpBizConfirm) {
            $comparePairs[] = ['biz_confirm_at', (string)($bizConfirmNormSave ?? '')];
            $comparePairs[] = ['biz_confirm_attachments', (string)$bizConfirmAttachNorm];
        } else {
            $comparePairs[] = ['biz_confirm_at', ''];
            $comparePairs[] = ['biz_confirm_attachments', ''];
        }
        $cmpRefundSubmit = strpos($subStages, 'refund_submit') !== false;
        if ($cmpRefundSubmit) {
            $comparePairs[] = ['refund_submitted_at', (string)($refundSubmittedNormSave ?? '')];
        } else {
            $comparePairs[] = ['refund_submitted_at', ''];
        }
        if ($hasPreLockBundleCmp) {
            $bundleCmp = (string)($preLockBundleNormSave ?? '');
            if ($bundleCmp === '') {
                foreach (['sign', 'lock', 'deposit'] as $bk) {
                    if (!empty($substageTimeOverrides[$bk])) {
                        $bundleCmp = (string) $substageTimeOverrides[$bk];
                        break;
                    }
                }
            }
            $comparePairs[] = ['pre_lock_sign_bundle_at', $bundleCmp];
        } else {
            $comparePairs[] = ['pre_lock_sign_bundle_at', ''];
        }

        $hasDataChange = false;
        foreach ($comparePairs as $pair) {
            $k = $pair[0];
            $newV = trim((string)$pair[1]);
            $oldV = trim((string)($old[$k] ?? ''));
            if ($oldV !== $newV) {
                $hasDataChange = true;
                break;
            }
        }

        // 短时间去重：5分钟内同操作人重复写入“无变化”不再追加
        $isNoChangeText = ($stageStr === '');
        $recentNoChange = $isNoChangeText ? staff_is_nochange_log_recent($old['status_log'] ?? '', $__staffActor, 5) : false;
        if ((!$hasDataChange && !$voice && !$attach && !$bizConfirmAttachNorm) || $recentNoChange) {
            staff_remove_status_log_placeholder($setClauses, $params);
        }
    }
    
    if (!empty($setClauses)) {
        $sql = "UPDATE filings SET " . implode(', ', $setClauses) . " WHERE id = ?";
        $params[] = $_POST['id'];
        try { $pdo->prepare($sql)->execute($params); echo json_encode(['status'=>'success']); } catch (Exception $e) { echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]); }
    } else { echo json_encode(['status'=>'success']); }
    exit;
}

/** 与 get_stats / get_staff_digest 共用：统计区间起止日 */
function staff_resolve_stats_range(string $rangeType, string $customStart, string $customEnd): array
{
    $rangeStart = date('Y-m-d');
    $rangeEnd = date('Y-m-d');
    if ($rangeType === 'week') {
        $rangeStart = date('Y-m-d', strtotime('monday last week'));
        $rangeEnd = date('Y-m-d', strtotime('sunday last week'));
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
    return [$rangeStart, $rangeEnd];
}

// [API] 统计
if ($action == 'get_stats') {
    header('Content-Type: application/json');
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $baseSql = "FROM filings f LEFT JOIN projects p ON f.project_id = p.id LEFT JOIN companies c ON f.company_name = c.name WHERE {$scope['sql']}";

    $rangeType = $_GET['range_type'] ?? 'today';
    $customStart = $_GET['custom_start'] ?? '';
    $customEnd = $_GET['custom_end'] ?? '';
    [$rangeStart, $rangeEnd] = staff_resolve_stats_range($rangeType, $customStart, $customEnd);

    // 顶部卡片与图表同一统计区间（日/周/月/季/自定义），非固定自然月
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
        $chartReport[] = intval($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT COUNT(DISTINCT f.client_phone) $baseSql AND f.client_phone <> '' AND f.status >= 2 AND DATE(f.created_at)=?");
        $stmt->execute(array_merge($scope['params'], [$date]));
        $chartVisit[] = intval($stmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) $baseSql AND f.status = 4 AND DATE(f.created_at)=?");
        $stmt->execute(array_merge($scope['params'], [$date]));
        $chartDeal[] = intval($stmt->fetchColumn() ?: 0);
    }

    echo json_encode([
        'month_report_unique' => $monthReportUnique,
        'month_visit_unique' => $monthVisitUnique,
        'month_deal_total' => $monthDealTotal,
        'month_conversion_rate' => $monthConversionRate,
        'range_type' => $rangeType,
        'range_start' => $rangeStart,
        'range_end' => $rangeEnd,
        'chart_labels' => $labels,
        'chart_data' => [
            'report' => $chartReport,
            'visit' => $chartVisit,
            'deal' => $chartDeal
        ]
    ]);
    exit;
}

// [API] 数据页摘要：渠道 / 项目 / 竞对（与统计区间、人员筛选一致；口径对齐 channel_efficiency、analytics_project、compete_list）
if ($action === 'get_staff_digest') {
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/includes/staff_digest_metrics.php';
    $memberId = intval($_GET['member_id'] ?? 0);
    $scope = get_scope_sql_and_params_staff($pdo, $SCOPE_MEMBERS, $memberId, $CURRENT_USER);
    $rangeType = $_GET['range_type'] ?? 'today';
    $customStart = $_GET['custom_start'] ?? '';
    $customEnd = $_GET['custom_end'] ?? '';
    [$rangeStart, $rangeEnd] = staff_resolve_stats_range($rangeType, $customStart, $customEnd);
    $agentRole = (string)($_SESSION['agent_role'] ?? ($CURRENT_USER['role'] ?? ''));
    $payload = staff_digest_build_payload($pdo, $scope, $rangeStart, $rangeEnd, (int)$CURRENT_USER['id'], $agentRole);
    echo json_encode(array_merge(['code' => 0], $payload), JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>案场工作台</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/echarts@5.4.0/dist/echarts.min.js"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif; }
        .glass-nav {
            background: #6d28d9;
            border-top: 1px solid #5b21b6;
            padding-bottom: env(safe-area-inset-bottom);
            box-shadow: 0 -6px 24px rgba(91, 33, 182, 0.38);
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
        .mic-active { animation: pulse 1.5s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4); } 70% { box-shadow: 0 0 0 10px rgba(239, 68, 68, 0); } 100% { box-shadow: 0 0 0 0 rgba(239, 68, 68, 0); } }
        @keyframes slideUp { from { transform: translateY(100%); } to { transform: translateY(0); } }
        .card-shadow { box-shadow: 0 4px 20px -2px rgba(0,0,0,0.05); }
        .purple-gradient { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); }
        .timeline-item { position: relative; padding-left: 24px; padding-bottom: 24px; border-left: 2px solid #e2e8f0; }
        .timeline-item:last-child { border-left: 2px solid transparent; }
        .timeline-dot { position: absolute; left: -6px; top: 0; width: 10px; height: 10px; border-radius: 50%; background: #cbd5e1; border: 2px solid #fff; box-shadow: 0 0 0 2px #fff; }
        .timeline-dot.active { background: #8b5cf6; }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
        .filter-input { width: 100%; background-color: #f9fafb; border: 1px solid #e5e7eb; border-radius: 0.5rem; padding: 0.5rem; font-size: 0.75rem; outline: none; transition: all 0.2s; }
        .filter-input:focus { border-color: #e9d5ff; ring: 1px solid #e9d5ff; }
        .filter-label { font-size: 10px; font-weight: bold; color: #9ca3af; margin-bottom: 4px; display: block; }
        
        .stage-box { border: 1px solid #e2e8f0; border-radius: 0.75rem; padding: 0.75rem; display: flex; align-items: center; cursor: pointer; background: white; transition: all 0.2s; }
        .stage-box.checked { border-color: #8b5cf6; background-color: #f5f3ff; color: #6d28d9; }
        .stage-icon { width: 1.25rem; height: 1.25rem; border-radius: 9999px; border: 2px solid #cbd5e1; margin-right: 0.5rem; display: flex; align-items: center; justify-content: center; font-size: 0.6rem; color: white; transition: all 0.2s; }
        .stage-box.checked .stage-icon { background-color: #8b5cf6; border-color: #8b5cf6; }
        
        .sub-chip { padding: 6px 12px; border-radius: 12px; font-size: 12px; font-weight: bold; cursor: pointer; border: 1px solid transparent; transition: all 0.2s; display: inline-flex; flex-direction: column; align-items: center; gap: 4px; min-width: 72px; flex-shrink: 0; }
        .sub-chip.active { background-color: #9333ea; color: white; border-color: #9333ea; box-shadow: 0 4px 6px -1px rgba(147, 51, 234, 0.3); }
        .sub-chip.inactive { background-color: #f1f5f9; color: #475569; border-color: #e2e8f0; }
        .count-pill { min-width: 30px; height: 22px; padding: 0 8px; border-radius: 9999px; font-size: 13px; line-height: 22px; font-weight: 800; text-align: center; }
        .count-pill-main-active { background: #d8b4fe; color: #5b21b6; }
        .count-pill-main-inactive { background: #eef2ff; color: #64748b; }
        .count-pill-sub-active { background: rgba(255, 255, 255, 0.24); color: #ffffff; }
        .count-pill-sub-inactive { background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; }
        /* 成交/完成：子 Tab 单行铺满，免横向拖动 */
        .workbench-subtabs-deal {
            display: flex;
            flex-wrap: nowrap;
            gap: 3px;
            width: 100%;
            padding-bottom: 4px;
            box-sizing: border-box;
        }
        .workbench-subtabs-deal .sub-chip {
            flex: 1 1 0;
            min-width: 0;
            padding: 3px 1px;
            border-radius: 8px;
            font-size: 10px;
            font-weight: 700;
            gap: 2px;
            flex-shrink: 1;
            text-align: center;
            justify-content: center;
        }
        .workbench-subtabs-deal .count-pill {
            min-width: 0;
            height: 15px;
            padding: 0 2px;
            font-size: 9px;
            line-height: 15px;
            font-weight: 800;
        }
        .digest-tabs { display: flex; gap: 6px; margin-bottom: 10px; overflow-x: auto; -webkit-overflow-scrolling: touch; }
        .digest-tab { flex: 1; min-width: 92px; text-align: center; padding: 8px 6px; border-radius: 12px; font-size: 11px; font-weight: 700; border: 1px solid #e9d5ff; background: #faf5ff; color: #6b21a8; white-space: nowrap; transition: all 0.15s; }
        .digest-tab-active { background: linear-gradient(135deg, #8b5cf6 0%, #6d28d9 100%); color: #fff; border-color: #6d28d9; box-shadow: 0 2px 8px rgba(109, 40, 217, 0.35); }
        .digest-scroll { overflow-x: auto; -webkit-overflow-scrolling: touch; border-radius: 12px; border: 1px solid #ede9fe; background: #fafafa; --digest-sticky-1-w: 5.5rem; --digest-sticky-2-w: 3rem; }
        .digest-table { min-width: 720px; width: 100%; border-collapse: collapse; font-size: 10px; }
        .digest-table th { background: #6d28d9; color: #fff; font-weight: 700; padding: 6px 3px; border: 1px solid #5b21b6; text-align: center; }
        .digest-table th.sub { background: #7c3aed; font-size: 9px; font-weight: 600; padding: 4px 2px; }
        .digest-table td { padding: 5px 3px; border: 1px solid #e5e7eb; text-align: center; color: #334155; }
        .digest-row-label { background: #f8fafc; color: #64748b; font-weight: 600; white-space: nowrap; }
        .digest-row-m { background: #fff; }
        .digest-row-y { background: #ecfccb; }
        .digest-row-p { background: #d9f99d; }
        .digest-proj { font-weight: 700; background: #f1f5f9; cursor: pointer; max-width: 100px; }
        /* 效能表：横向滚动时固定左侧两列（项目/渠道人员 + 分项） */
        .digest-table.digest-table-sticky th.digest-sticky-1,
        .digest-table.digest-table-sticky td.digest-sticky-1 {
            position: sticky;
            left: 0;
            z-index: 4;
            width: var(--digest-sticky-1-w);
            min-width: var(--digest-sticky-1-w);
            max-width: 7rem;
            box-sizing: border-box;
            box-shadow: 3px 0 8px -2px rgba(15, 23, 42, 0.14);
        }
        .digest-table.digest-table-sticky th.digest-sticky-2,
        .digest-table.digest-table-sticky td.digest-sticky-2 {
            position: sticky;
            left: var(--digest-sticky-1-w);
            z-index: 3;
            width: var(--digest-sticky-2-w);
            min-width: var(--digest-sticky-2-w);
            box-sizing: border-box;
            box-shadow: 3px 0 8px -2px rgba(15, 23, 42, 0.1);
        }
        .digest-table.digest-table-sticky thead th.digest-sticky-1,
        .digest-table.digest-table-sticky thead th.digest-sticky-2 {
            z-index: 6;
            background: #6d28d9;
        }
        .digest-table.digest-table-sticky thead th.digest-sticky-2 {
            z-index: 5;
        }
        .digest-table.digest-table-sticky tbody td.digest-sticky-1.digest-proj { background: #f1f5f9; }
        .digest-table.digest-table-sticky tbody tr.digest-row-m td.digest-sticky-2 { background: #f8fafc; }
        .digest-table.digest-table-sticky tbody tr.digest-row-y td.digest-sticky-2 { background: #ecfccb; }
        .digest-table.digest-table-sticky tbody tr.digest-row-p td.digest-sticky-2 { background: #d9f99d; }
        /* 竞对摘要：列少，取消 720px 最小宽与横向滚动，一屏内压缩显示 */
        .digest-scroll.digest-scroll-compete {
            overflow-x: visible;
        }
        .digest-table.digest-table-compete {
            min-width: 0 !important;
            width: 100%;
            max-width: 100%;
            table-layout: fixed;
            font-size: 9px;
        }
        .digest-table.digest-table-compete th,
        .digest-table.digest-table-compete td {
            padding: 4px 3px;
            vertical-align: middle;
        }
        .digest-table.digest-table-compete th:nth-child(1),
        .digest-table.digest-table-compete td.digest-compete-proj {
            width: 38%;
            text-align: left;
            word-break: break-word;
        }
        .digest-table.digest-table-compete th:nth-child(2),
        .digest-table.digest-table-compete th:nth-child(3),
        .digest-table.digest-table-compete th:nth-child(4),
        .digest-table.digest-table-compete td:nth-child(2),
        .digest-table.digest-table-compete td:nth-child(3),
        .digest-table.digest-table-compete td:nth-child(4) {
            width: 20.666%;
            text-align: center;
        }
    </style>
</head>
<body>
<div id="app" class="max-w-md mx-auto min-h-screen pb-24 relative bg-gray-50">
    
    <div v-if="tab==='dash'" class="bg-white p-5 pt-8 pb-16 rounded-b-[2rem] shadow-sm relative z-0">
        <div class="flex justify-between items-center mb-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-purple-100 flex items-center justify-center text-purple-600 font-bold border-2 border-white shadow-sm"><i class="fas fa-user-shield"></i></div>
                <div><div class="text-xs text-gray-400">{{ currentDate }}</div><h1 class="font-bold text-lg text-slate-800">你好，<?= $CURRENT_USER['name'] ?></h1></div>
            </div>
            <div class="flex flex-wrap gap-2 justify-end items-center">
                <button type="button" @click="openPwdModal" class="text-xs bg-purple-50 text-purple-600 px-3 py-1.5 rounded-full font-bold flex items-center gap-1 border border-purple-100"><i class="fas fa-key"></i> 改密码</button>
                <a href="logout.php" class="text-xs bg-gray-100 text-gray-500 px-3 py-1.5 rounded-full font-bold flex items-center gap-1"><i class="fas fa-sign-out-alt"></i> 退出</a>
            </div>
        </div>
    </div>
    
    <div v-else class="sticky top-0 z-40 bg-white/95 backdrop-blur p-4 shadow-sm flex justify-between items-center gap-2">
        <h2 class="font-bold text-lg text-slate-800 flex-1 min-w-0 truncate">{{ tab==='work'?'工作台':'历史记录' }}</h2>
        <div class="flex flex-shrink-0 gap-2 items-center">
            <button type="button" @click="openPwdModal" class="text-xs bg-purple-50 text-purple-600 px-2.5 py-1.5 rounded-full font-bold flex items-center gap-1 border border-purple-100 whitespace-nowrap"><i class="fas fa-key"></i> 改密码</button>
            <a href="logout.php" class="text-xs bg-gray-100 text-gray-500 px-2.5 py-1.5 rounded-full font-bold flex items-center gap-1 whitespace-nowrap"><i class="fas fa-sign-out-alt"></i> 退出</a>
        </div>
    </div>

    <div :class="{'px-4 -mt-10 relative z-10': tab==='dash', 'p-4': tab!=='dash'}" class="space-y-5">
        
        <div v-if="tab==='dash'" class="space-y-5 fade-in">
            <div class="purple-gradient rounded-3xl p-6 text-white shadow-lg shadow-purple-500/20 relative overflow-hidden">
                <div class="relative z-10">
                    <div class="grid grid-cols-4 gap-3">
                        <div><div class="text-purple-100 text-xs mb-1">总报备</div><div class="text-2xl font-bold">{{ stats.month_report_unique || 0 }}</div></div>
                        <div><div class="text-purple-100 text-xs mb-1">总来访</div><div class="text-2xl font-bold">{{ stats.month_visit_unique || 0 }}</div></div>
                        <div><div class="text-purple-100 text-xs mb-1">总成交</div><div class="text-2xl font-bold">{{ stats.month_deal_total || 0 }}</div></div>
                        <div><div class="text-purple-100 text-xs mb-1">成交转化率</div><div class="text-2xl font-bold">{{ stats.month_conversion_rate || 0 }}%</div></div>
                    </div>
                </div>
            </div>
            <div class="bg-white rounded-3xl p-5 card-shadow">
                <div class="flex flex-wrap justify-between items-start gap-2 mb-2">
                    <h3 class="font-bold text-sm text-slate-700">数据统计</h3>
                    <span class="text-[10px] text-gray-400">{{ stats.range_start }} ~ {{ stats.range_end }}</span>
                </div>
                <div v-if="teamFilterEnabled" class="mb-3">
                    <label class="filter-label">数据范围（渠道/项目/竞对摘要同步）</label>
                    <select v-model="selectedMemberId" @change="onMemberChange" class="filter-input w-full text-xs">
                        <option value="">全部人员（本人及下级）</option>
                        <option v-for="m in teamMembers" :key="'dash-m-' + m.id" :value="String(m.id)">{{ m.username }}</option>
                    </select>
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
                    <button @click="applyStatsCustomRange" class="col-span-2 bg-purple-600 text-white py-2 rounded-lg text-xs font-bold">应用自定义时间</button>
                </div>
                <div id="trendChart" class="w-full h-48"></div>

                <div class="mt-5 pt-4 border-t border-slate-100">
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="font-bold text-xs text-slate-700">效能摘要</h4>
                        <span v-if="digestData && digestData.periods" class="text-[9px] text-gray-400 leading-tight text-right max-w-[55%]">与上方区间一致</span>
                    </div>
                    <div class="digest-tabs">
                        <button type="button" class="digest-tab" :class="digestTab==='project' ? 'digest-tab-active' : ''" @click="digestTab='project'">项目效能</button>
                        <button type="button" class="digest-tab" :class="digestTab==='channel' ? 'digest-tab-active' : ''" @click="digestTab='channel'">渠道效能</button>
                        <button type="button" class="digest-tab" :class="digestTab==='compete' ? 'digest-tab-active' : ''" @click="digestTab='compete'">竞对数据</button>
                    </div>
                    <div v-if="digestLoading" class="text-center py-6 text-xs text-gray-400"><i class="fas fa-circle-notch fa-spin mr-1"></i>加载摘要…</div>
                    <template v-else-if="digestData && digestData.code === 0">
                        <div v-show="digestTab==='project'" class="digest-scroll">
                            <table class="digest-table digest-table-sticky">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-middle digest-sticky-1">项目</th>
                                        <th rowspan="2" class="align-middle digest-sticky-2">分项</th>
                                        <th colspan="5">效能维度</th>
                                        <th colspan="3">店</th>
                                        <th colspan="3">人</th>
                                        <th colspan="3">客户</th>
                                    </tr>
                                    <tr>
                                        <th v-for="k in digestEffKeys" :key="'dp-h-'+k" class="sub">{{ digestMetricLabels[k] || k }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'dp-s-'+ti" class="sub">{{ lab }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'dp-p-'+ti" class="sub">{{ lab }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'dp-c-'+ti" class="sub">{{ lab }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="row in (digestData.projects && digestData.projects.rows) ? digestData.projects.rows : []" :key="'pr-'+row.project_id">
                                        <tr class="digest-row-m">
                                            <td class="digest-proj text-left pl-1 digest-sticky-1" :rowspan="digestExpandProj[row.project_id] ? 3 : 1" @click="toggleDigestProj(row.project_id)">
                                                <span class="inline-flex items-center gap-0.5"><i :class="digestExpandProj[row.project_id]?'fa-chevron-down':'fa-chevron-right'" class="fas text-[8px] text-violet-600"></i><span class="truncate">{{ row.name }}</span></span>
                                            </td>
                                            <td class="digest-row-label digest-sticky-2">本月</td>
                                            <td v-for="k in digestEffKeys" :key="'pr-c-'+k">{{ row.current[k] }}</td>
                                            <td>{{ row.current.store_count }}</td><td>{{ row.current.store_visit_distinct }}</td><td>{{ row.current.store_deal_distinct }}</td>
                                            <td>{{ row.current.broker_count }}</td><td>{{ row.current.visit_broker_count }}</td><td>{{ row.current.deal_broker_count }}</td>
                                            <td>{{ row.current.client_report_distinct }}</td><td>{{ row.current.client_visit_distinct }}</td><td>{{ row.current.client_deal_distinct }}</td>
                                        </tr>
                                        <tr v-if="digestExpandProj[row.project_id]" class="digest-row-y">
                                            <td class="digest-row-label digest-sticky-2">同比</td>
                                            <td v-for="k in digestEffKeys" :key="'pr-y-'+k">{{ row.yoy[k] }}</td>
                                            <td>{{ row.yoy.store_count }}</td><td>{{ row.yoy.store_visit_distinct }}</td><td>{{ row.yoy.store_deal_distinct }}</td>
                                            <td>{{ row.yoy.broker_count }}</td><td>{{ row.yoy.visit_broker_count }}</td><td>{{ row.yoy.deal_broker_count }}</td>
                                            <td>{{ row.yoy.client_report_distinct }}</td><td>{{ row.yoy.client_visit_distinct }}</td><td>{{ row.yoy.client_deal_distinct }}</td>
                                        </tr>
                                        <tr v-if="digestExpandProj[row.project_id]" class="digest-row-p">
                                            <td class="digest-row-label digest-sticky-2">环比</td>
                                            <td v-for="k in digestEffKeys" :key="'pr-p-'+k">{{ row.mom[k] }}</td>
                                            <td>{{ row.mom.store_count }}</td><td>{{ row.mom.store_visit_distinct }}</td><td>{{ row.mom.store_deal_distinct }}</td>
                                            <td>{{ row.mom.broker_count }}</td><td>{{ row.mom.visit_broker_count }}</td><td>{{ row.mom.deal_broker_count }}</td>
                                            <td>{{ row.mom.client_report_distinct }}</td><td>{{ row.mom.client_visit_distinct }}</td><td>{{ row.mom.client_deal_distinct }}</td>
                                        </tr>
                                    </template>
                                    <tr v-if="!(digestData.projects && digestData.projects.rows && digestData.projects.rows.length)"><td colspan="16" class="py-4 text-gray-400">暂无项目维度数据</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div v-show="digestTab==='channel'" class="digest-scroll">
                            <table class="digest-table digest-table-sticky">
                                <thead>
                                    <tr>
                                        <th rowspan="2" class="align-middle digest-sticky-1">渠道人员</th>
                                        <th rowspan="2" class="align-middle digest-sticky-2">分项</th>
                                        <th colspan="5">效能维度</th>
                                        <th colspan="3">店</th>
                                        <th colspan="3">人</th>
                                        <th colspan="3">客户</th>
                                    </tr>
                                    <tr>
                                        <th v-for="k in digestEffKeys" :key="'ch-h-'+k" class="sub">{{ digestMetricLabels[k] || k }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'ch-s-'+ti" class="sub">{{ lab }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'ch-p-'+ti" class="sub">{{ lab }}</th>
                                        <th v-for="(lab,ti) in digestTriple" :key="'ch-c-'+ti" class="sub">{{ lab }}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <template v-for="row in (digestData.channel && digestData.channel.rows) ? digestData.channel.rows : []" :key="'ch-'+row.follower_key">
                                        <tr class="digest-row-m">
                                            <td class="digest-proj digest-sticky-1" :rowspan="digestExpandCh[row.follower_key] ? 3 : 1" @click="toggleDigestCh(row.follower_key)">
                                                <span class="inline-flex items-center justify-center gap-0.5"><i :class="digestExpandCh[row.follower_key]?'fa-chevron-down':'fa-chevron-right'" class="fas text-[8px] text-violet-600"></i>{{ row.name }}</span>
                                            </td>
                                            <td class="digest-row-label digest-sticky-2">本月</td>
                                            <td v-for="k in digestEffKeys" :key="'ch-c-'+k">{{ row.current[k] }}</td>
                                            <td>{{ row.current.store_count }}</td><td>{{ row.current.store_visit_distinct }}</td><td>{{ row.current.store_deal_distinct }}</td>
                                            <td>{{ row.current.broker_count }}</td><td>{{ row.current.visit_broker_count }}</td><td>{{ row.current.deal_broker_count }}</td>
                                            <td>{{ row.current.client_report_distinct }}</td><td>{{ row.current.client_visit_distinct }}</td><td>{{ row.current.client_deal_distinct }}</td>
                                        </tr>
                                        <tr v-if="digestExpandCh[row.follower_key]" class="digest-row-y">
                                            <td class="digest-row-label digest-sticky-2">同比</td>
                                            <td v-for="k in digestEffKeys" :key="'ch-y-'+k">{{ row.yoy[k] }}</td>
                                            <td>{{ row.yoy.store_count }}</td><td>{{ row.yoy.store_visit_distinct }}</td><td>{{ row.yoy.store_deal_distinct }}</td>
                                            <td>{{ row.yoy.broker_count }}</td><td>{{ row.yoy.visit_broker_count }}</td><td>{{ row.yoy.deal_broker_count }}</td>
                                            <td>{{ row.yoy.client_report_distinct }}</td><td>{{ row.yoy.client_visit_distinct }}</td><td>{{ row.yoy.client_deal_distinct }}</td>
                                        </tr>
                                        <tr v-if="digestExpandCh[row.follower_key]" class="digest-row-p">
                                            <td class="digest-row-label digest-sticky-2">环比</td>
                                            <td v-for="k in digestEffKeys" :key="'ch-p-'+k">{{ row.mom[k] }}</td>
                                            <td>{{ row.mom.store_count }}</td><td>{{ row.mom.store_visit_distinct }}</td><td>{{ row.mom.store_deal_distinct }}</td>
                                            <td>{{ row.mom.broker_count }}</td><td>{{ row.mom.visit_broker_count }}</td><td>{{ row.mom.deal_broker_count }}</td>
                                            <td>{{ row.mom.client_report_distinct }}</td><td>{{ row.mom.client_visit_distinct }}</td><td>{{ row.mom.client_deal_distinct }}</td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </div>
                        <div v-show="digestTab==='compete'" class="digest-scroll digest-scroll-compete">
                            <table class="digest-table digest-table-compete">
                                <thead>
                                    <tr>
                                        <th>项目</th>
                                        <th>带看</th>
                                        <th>成交</th>
                                        <th>留筹</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr class="font-bold bg-violet-50">
                                        <td class="digest-compete-proj">总计</td>
                                        <td>{{ (digestData.compete && digestData.compete.totals) ? digestData.compete.totals.visits : 0 }}</td>
                                        <td>{{ (digestData.compete && digestData.compete.totals) ? digestData.compete.totals.deals : 0 }}</td>
                                        <td>{{ (digestData.compete && digestData.compete.totals) ? digestData.compete.totals.locks : 0 }}</td>
                                    </tr>
                                    <tr v-for="(cr,ci) in (digestData.compete && digestData.compete.rows) ? digestData.compete.rows : []" :key="'cp-'+ci+'-'+cr.project_id">
                                        <td class="digest-compete-proj">{{ cr.project_name }}</td>
                                        <td>{{ cr.visits }}</td>
                                        <td>{{ cr.deals }}</td>
                                        <td>{{ cr.locks }}</td>
                                    </tr>
                                    <tr v-if="!(digestData.compete && digestData.compete.rows && digestData.compete.rows.length)"><td colspan="4" class="py-3 px-2 text-gray-400 text-center text-[9px] leading-snug">暂无竞对录入（区间与上方一致）</td></tr>
                                </tbody>
                            </table>
                            <div class="text-center py-2 border-t border-violet-100 bg-violet-50/50">
                                <a href="compete_mobile.php" class="text-[10px] font-bold text-purple-700 underline">打开完整竞对列表</a>
                            </div>
                        </div>
                    </template>
                    <div v-else-if="digestLoadFailed" class="text-center py-4 text-[10px] text-red-500">摘要加载失败，请点刷新重试</div>
                    <div v-else class="h-2"></div>
                </div>
            </div>
            
            <div class="bg-white rounded-3xl p-6 card-shadow">
                <button type="button" @click="goToCompete" class="w-full bg-purple-600 hover:bg-purple-700 text-white font-bold py-4 px-6 rounded-2xl transition-all duration-300 shadow-lg shadow-purple-500/20 flex items-center justify-center gap-2">
                    <i class="fas fa-chart-pie text-xl"></i>
                    <span class="text-lg">录入竞对</span>
                </button>
            </div>
        </div>

        <div v-if="tab==='work'">
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-2 mb-3 flex gap-2">
                <div class="flex-1 flex items-center bg-gray-50 rounded-lg px-3">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                    <input v-model="workSearch" type="text" class="w-full p-2 bg-transparent text-sm outline-none" placeholder="全能搜索/姓名/手机/项目/门店/公司名/销售等">
                </div>
                <select v-if="teamFilterEnabled" v-model="selectedMemberId" @change="onMemberChange" class="bg-white border border-slate-200 rounded-lg px-2 text-xs text-slate-600 min-w-[108px]">
                    <option value="">全部人员</option>
                    <option v-for="m in teamMembers" :key="m.id" :value="String(m.id)">{{ m.username }}</option>
                </select>
                <button @click="searchHistory" class="w-10 h-10 bg-purple-600 text-white rounded-lg flex items-center justify-center shadow-lg active:scale-95 transition" :class="{'bg-purple-700': isSearchingHistory}"><i class="fas fa-history text-lg"></i></button>
                <button @click="simulateScan" class="w-10 h-10 bg-slate-800 text-white rounded-lg flex items-center justify-center shadow-lg active:scale-95 transition"><i class="fas fa-qrcode text-lg"></i></button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden mb-3">
                <div class="p-3 flex justify-between items-center cursor-pointer bg-gray-50 border-b border-gray-100" @click="showFilters = !showFilters">
                    <div class="text-xs font-bold text-slate-700"><i class="fas fa-filter text-purple-500 mr-1"></i> 高级筛选</div>
                    <div class="text-xs text-gray-400"><i class="fas" :class="showFilters ? 'fa-chevron-up' : 'fa-chevron-down'"></i></div>
                </div>
                <div v-if="showFilters" class="p-4 bg-white animate-[slideUp_0.2s_ease-out]">
                    <p v-if="tab === 'work' && subTab == 3 && workbenchDealDateFilterHint" class="text-[10px] text-purple-600 mb-2 leading-relaxed">{{ workbenchDealDateFilterHint }}</p>
                    <div class="grid grid-cols-2 gap-3 mb-3"><div><label class="filter-label">开始日期</label><input v-model="filters.dateStart" type="date" class="filter-input"></div><div><label class="filter-label">结束日期</label><input v-model="filters.dateEnd" type="date" class="filter-input"></div></div>
                    <div class="mb-3">
                        <label class="filter-label">所属楼盘（可多选）</label>
                        <p v-if="isStrictProjectSite" class="text-[10px] text-gray-400 mb-2 leading-relaxed">与后台「项目库」管理员或「人员档案-关联项目」一致；仅显示上述方式关联到您的楼盘，不含仅因本人经纪人身份产生的报备盘。</p>
                        <p v-else class="text-[10px] text-gray-400 mb-2 leading-relaxed">仅列出您权限范围内的楼盘（可见报备涉及的项目，以及后台设为本人/本组驻场负责人的项目）</p>
                        <input v-model="projectFilterKeyword" type="text" class="filter-input mb-2" placeholder="输入关键字筛选楼盘…" autocomplete="off">
                        <div class="flex items-center justify-between gap-2 mb-2 text-[10px]">
                            <span class="text-gray-400">已选 <b class="text-slate-600">{{ (filters.projectIds || []).length }}</b> 个，不选表示全部</span>
                            <button type="button" @click="clearProjectFilter" class="text-purple-600 font-bold shrink-0">清空已选</button>
                        </div>
                        <div class="border border-gray-200 rounded-lg max-h-44 overflow-y-auto bg-slate-50/80 p-1.5 space-y-0.5">
                            <label v-for="p in filteredProjectsForFilter" :key="p.id" class="flex items-center gap-2 text-xs py-2 px-2 rounded-lg hover:bg-white cursor-pointer border border-transparent hover:border-gray-100">
                                <input type="checkbox" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" :checked="isProjectFilterChecked(p.id)" @change="toggleProjectFilter(p.id)">
                                <span class="flex-1 min-w-0 truncate text-slate-700">{{ p.name }}</span>
                            </label>
                            <div v-if="filteredProjectsForFilter.length===0" class="text-xs text-gray-400 py-4 text-center">无匹配楼盘</div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="filter-label">状态</label><select v-model="filters.statusFilter" class="filter-input"><option v-for="opt in historyStatusFilterOptions" :key="opt.value === '' ? '_all' : opt.value" :value="opt.value">{{ opt.label }}</option></select></div>
                    <div class="mb-3"><label class="filter-label">客户意向度</label><select v-model="filters.intentionFilter" class="filter-input"><option v-for="opt in clientIntentionFilterOptions" :key="'intent_work_' + (opt.value === '' ? '_all' : opt.value)" :value="opt.value">{{ opt.label }}</option></select></div>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="resetFilters" class="bg-gray-100 text-gray-500 py-2 rounded-lg text-xs font-bold">重置条件</button>
                        <button type="button" @click="applyFilters" class="bg-purple-600 text-white py-2 rounded-lg text-xs font-bold">确定筛选</button>
                    </div>
                </div>
            </div>

            <div class="flex bg-white p-1 rounded-xl mb-3 text-xs font-bold text-gray-500 shadow-sm border border-gray-100 overflow-x-auto no-scrollbar">
                <button v-for="t in tabs" :key="t.id" @click="changeMainTab(t.id)" class="flex-1 min-w-[76px] py-2 rounded-lg transition-all whitespace-nowrap flex flex-col items-center justify-center gap-1 border" :class="subTab===t.id ? 'bg-purple-600 text-white border-purple-600 shadow-sm' : 'bg-slate-50 text-slate-600 border-slate-200'">
                    <span>{{ t.name }}</span>
                    <span class="count-pill tabular-nums" :class="subTab===t.id ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ counts[t.id] || 0 }}</span>
                </button>
            </div>

            <div v-if="subTab===1 || subTab===2 || subTab===3" class="mb-4 animate-[fadeIn_0.3s_ease-out]">
                <div v-if="subTab===1" class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                    <span @click="childFilter='reception_all'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='reception_all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='reception_all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.reception_all }}</span></span>
                    <span @click="childFilter='valid_report'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='valid_report'?'active':'inactive'">有效报备<span class="count-pill tabular-nums" :class="childFilter==='valid_report' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.valid_report }}</span></span>
                    <span @click="childFilter='valid'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='valid'?'active':'inactive'">有效到访<span class="count-pill tabular-nums" :class="childFilter==='valid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.valid }}</span></span>
                    <span @click="childFilter='invalid'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='invalid'?'active':'inactive'">无效<span class="count-pill tabular-nums" :class="childFilter==='invalid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.invalid }}</span></span>
                    <span @click="childFilter='repeat'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='repeat'?'active':'inactive'">新客<span class="count-pill tabular-nums" :class="childFilter==='repeat' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ receptionChildCounts.repeat }}</span></span>
                </div>
                <div v-if="subTab===2" class="flex gap-2 overflow-x-auto no-scrollbar pb-1">
                    <span @click="childFilter='visit_all'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='visit_all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='visit_all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_all }}</span></span>
                    <span @click="childFilter='visit_valid'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='visit_valid'?'active':'inactive'">有效到访<span class="count-pill tabular-nums" :class="childFilter==='visit_valid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_valid }}</span></span>
                    <span @click="childFilter='visit_invalid'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='visit_invalid'?'active':'inactive'">无效到访<span class="count-pill tabular-nums" :class="childFilter==='visit_invalid' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ visitChildCounts.visit_invalid }}</span></span>
                </div>
                <div v-if="subTab===3" class="workbench-subtabs-deal">
                    <span @click="childFilter='all'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='all'?'active':'inactive'">全部<span class="count-pill tabular-nums" :class="childFilter==='all' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.all }}</span></span>
                    <span @click="childFilter='lock'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='lock'?'active':'inactive'">锁房<span class="count-pill tabular-nums" :class="childFilter==='lock' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.lock }}</span></span>
                    <span @click="childFilter='subscription'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='subscription' ? 'active' : 'inactive'">认购<span class="count-pill tabular-nums" :class="childFilter==='subscription' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.subscription }}</span></span>
                    <span @click="childFilter='contract'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='contract'?'active':'inactive'">签约<span class="count-pill tabular-nums" :class="childFilter==='contract' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.contract }}</span></span>
                    <span @click="childFilter='biz_confirm'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='biz_confirm'?'active':'inactive'">业确<span class="count-pill tabular-nums" :class="childFilter==='biz_confirm' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.biz_confirm }}</span></span>
                    <span @click="childFilter='refund'" class="sub-chip inline-flex items-center gap-1" :class="childFilter==='refund'?'active':'inactive'">退房<span class="count-pill tabular-nums" :class="childFilter==='refund' ? 'count-pill-sub-active' : 'count-pill-sub-inactive'">{{ depositChildCounts.refund }}</span></span>
                </div>
            </div>

            <div class="space-y-4 pb-20">
                <div v-if="groupedWorkList.length==0" class="text-center py-16 text-gray-400 text-xs bg-white rounded-2xl border border-dashed border-gray-200">暂无任务</div>
                <div v-for="(group, idx) in groupedWorkList" :key="group._groupKey || idx" class="bg-white rounded-2xl card-shadow overflow-hidden max-w-[800px] mx-auto">
                    <div class="bg-gray-50 p-3 border-b border-gray-100 flex justify-between items-center">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                            <div class="flex-1 min-w-0 grid grid-cols-2 gap-x-2 gap-y-0.5">
                                <div class="text-[10px] text-gray-400 truncate">客户：<span class="font-bold text-slate-800">{{ group.client_name }}</span> <span v-html="formatPhone(group.client_phone)"></span></div>
                                <div class="text-[10px] text-gray-400 truncate text-right">渠道人员: {{ ((group.items[0].follower || '') + '').trim() || '公池' }}</div>
                                <div class="text-[10px] text-gray-400 truncate">经纪人: {{ group.items[0].agent_name || '未知' }} <span v-html="formatPhone(group.items[0].agent_phone, true)"></span></div>
                                <div class="text-[10px] text-gray-400 truncate text-right cursor-pointer hover:text-slate-500" :title="group.items[0].company_name || '未知'" @click="showFullText(group.items[0].company_name || '未知')">{{ truncateText(group.items[0].company_name || '未知', 15) }}</div>
                            </div>
                        </div>
                        <span class="bg-white border border-gray-200 text-gray-500 text-[10px] px-2 py-1 rounded-full ml-2">{{ group.items.length }} 单</span>
                    </div>
                    <div class="divide-y divide-gray-50">
                        <div v-for="item in group.items" :key="item.id" class="p-3 relative">
                            <div class="absolute left-0 top-0 bottom-0 w-1 bg-purple-600"></div>
                            <div class="flex justify-between items-start mb-2">
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 min-w-0">
                                        <div class="font-bold text-sm text-slate-700 truncate">{{ item.project_name }}</div>
                                        <span v-if="item.status == 1 && (!item.visit_type || item.visit_type != 0)" class="inline-block whitespace-nowrap text-[10px] bg-gray-100 text-gray-500 px-2 py-0.5 rounded border border-gray-200">待处理</span>
                                        <span v-if="item.status == 1 && item.visit_type == 0" class="inline-block whitespace-nowrap text-[10px] bg-blue-100 text-blue-700 px-2 py-0.5 rounded border border-blue-200">有效报备</span>
                                        <span v-if="subTab !== 3 && item.status >= 2 && item.visit_type==1" class="inline-block whitespace-nowrap text-[10px] bg-green-100 text-green-700 px-2 py-0.5 rounded border border-green-200">有效到访</span>
                                        <span v-if="subTab !== 3 && item.status == 5 && item.visit_type==2" class="inline-block whitespace-nowrap text-[10px] bg-red-100 text-red-700 px-2 py-0.5 rounded border border-red-200">无效到访</span>
                                        <span v-if="subTab !== 3 && item.status == 5 && item.visit_type==3" class="inline-block whitespace-nowrap text-[10px] bg-orange-100 text-orange-700 px-2 py-0.5 rounded border border-orange-200">重复到访</span>
                                        <span v-if="item.status == 6" class="inline-block whitespace-nowrap text-[10px] bg-gray-200 text-gray-600 px-2 py-0.5 rounded border border-gray-300">报备无效</span>
                                    </div>
                                    <div class="text-[10px] text-slate-500 mt-1.5 leading-5">客户意向: {{ clientIntentionLabel(item) }}</div>
                                    <template v-if="subTab===3 && ['all','lock','subscription','contract','biz_confirm','refund'].includes(childFilter)">
                                        <div v-if="workbenchDealStageTimeText(item, childFilter)" class="text-[10px] mt-0.5 font-medium text-purple-700">{{ workbenchDealStageCardTitle(childFilter) }}: {{ workbenchDealStageTimeText(item, childFilter) }}</div>
                                        <div class="text-[10px] text-gray-400 mt-0.5">报备: {{ item.created_at.substring(5,19) }}</div>
                                    </template>
                                    <div v-else class="text-[10px] text-gray-400 mt-0.5">报备: {{ item.created_at.substring(5,19) }}</div>
                                    <div v-if="item.visit_time && (item.status >= 2 || item.visit_type==1 || item.visit_type==2 || item.visit_type==3)" class="text-[10px] text-gray-400 mt-0.5">到访: {{ formatDateTime(item.visit_time) }}</div>
                                    <div v-if="(item.status==2 || item.status==3 || item.status==4 || item.status==7) && workbenchDealFiveStageLabel(item)" class="flex flex-wrap gap-1 mt-1">
                                        <span
                                            class="text-[10px] px-1.5 py-0.5 rounded max-w-full leading-tight font-medium"
                                            :class="{
                                                'bg-purple-50 text-purple-700': workbenchDealFiveStageMax(item) <= 3,
                                                'bg-amber-50 text-amber-800': workbenchDealFiveStageMax(item) === 4,
                                                'bg-rose-50 text-rose-700': workbenchDealFiveStageMax(item) === 5
                                            }"
                                        >{{ workbenchDealFiveStageLabel(item) }}</span>
                                    </div>
                                    <div v-if="item.finance_reject_history && item.finance_reject_history.length > 0" class="mt-2 text-[10px] text-red-800 bg-red-50 border border-red-200 rounded px-2 py-1.5 leading-snug space-y-1.5">
                                        <div class="font-bold border-b border-red-200/60 pb-1">财务驳回记录（{{ item.finance_reject_history.length }} 次）</div>
                                        <div v-for="(ev, evIdx) in item.finance_reject_history" :key="evIdx" class="border-b border-red-100/80 last:border-0 last:pb-0 pb-1.5">
                                            <div class="text-red-700/90 tabular-nums">驳回时间：{{ formatDateTime(ev.rejected_at) }}</div>
                                            <div class="mt-0.5 text-red-700 whitespace-pre-wrap break-words">{{ (ev.reject_reason || '').slice(0, 400) }}{{ (ev.reject_reason || '').length > 400 ? '…' : '' }}</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="ml-3 flex flex-col items-end gap-1 min-w-[170px]">
                                    <div v-if="item.status < 4 || item.status == 5 || item.status == 6 || item.status == 7" class="flex items-start">
                                        <div v-if="item.status == 1" class="flex gap-2">
                                            <button @click="markAsInvalid(item)" :disabled="item.status != 1" class="bg-gray-500 text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1" :class="item.status != 1 ? 'opacity-50 cursor-not-allowed' : ''">
                                                <span>无效报备</span>
                                            </button>
                                            <button @click="openModal(item)" :disabled="item.status != 1" class="bg-orange-500 text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1" :class="item.status != 1 ? 'opacity-50 cursor-not-allowed' : ''">
                                                <span>确认到访</span>
                                            </button>
                                        </div>
                                        <div v-else-if="item.status == 2" class="flex gap-2">
                                            <button v-if="!(subTab===3 && childFilter==='subscription')" @click="markAsInvalidVisit(item)" class="bg-red-500 text-white py-1 px-2 rounded-lg text-[11px] font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1 whitespace-nowrap">
                                                <span>改为无效到访</span>
                                            </button>
                                            <button @click="openModal(item)" :class="{
                                                'bg-purple-600': item.status == 2 || item.status == 3,
                                                'bg-slate-700': item.status != 2 && item.status != 3
                                            }" class="text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1">
                                                <span>{{ item.status == 5 || item.status == 6 ? '修改状态' : getActionText(item.status, { dealWorkbench: subTab === 3 }) }}</span>
                                                <i class="fas fa-arrow-right opacity-50"></i>
                                            </button>
                                        </div>
                                        <div v-else-if="item.status == 7" class="flex items-start">
                                            <button type="button" @click="openModal(item)" class="bg-purple-600 text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1">
                                                <span>成交进度</span>
                                                <i class="fas fa-arrow-right opacity-50"></i>
                                            </button>
                                        </div>
                                        <button v-else-if="item.status != 2 && item.status != 7" @click="openModal(item)" :class="{
                                            'bg-purple-600': item.status == 3,
                                            'bg-slate-700': item.status != 3
                                        }" class="text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1">
                                            <span>{{ item.status == 5 || item.status == 6 ? '修改状态' : getActionText(item.status, { dealWorkbench: subTab === 3 }) }}</span>
                                            <i class="fas fa-arrow-right opacity-50"></i>
                                        </button>
                                    </div>
                                    <div v-else-if="item.status==4" class="flex items-start">
                                        <button type="button" @click="openModal(item)" class="bg-purple-600 text-white py-1 px-3 rounded-lg text-xs font-bold shadow active:scale-95 transition-transform flex items-center justify-center gap-1">
                                            <span>成交进度</span>
                                            <i class="fas fa-arrow-right opacity-50"></i>
                                        </button>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <button v-if="getRemarkCount(item) > 0" type="button" @click.stop="showRemarkNotes(item)" class="flex items-center gap-1 text-sm font-extrabold leading-none">
                                            <span class="text-orange-500">{{ getRemarkCount(item) }}</span>
                                            <i class="fas fa-book-open text-yellow-500 text-sm"></i>
                                        </button>
                                        <button @click="showTimeline(item)" class="text-blue-500 text-xs bg-blue-50 w-6 h-6 rounded flex items-center justify-center active:bg-blue-100">
                                            <i class="fas fa-history"></i>
                                        </button>
                                        <button @click="copyFilingData(item)" class="text-purple-500 text-xs bg-purple-50 w-6 h-6 rounded flex items-center justify-center active:bg-purple-100">
                                            <i class="fas fa-copy"></i>
                                        </button>
                                    </div>
                                    <button
                                        type="button"
                                        @click.stop="itemHasFollowup123Preview(item) && openFollowupPreview(item)"
                                        :class="itemHasFollowup123Preview(item)
                                            ? 'mt-1 text-[10px] font-bold text-purple-700 bg-purple-50 border border-purple-200 rounded-lg px-2.5 py-1 shadow-sm active:opacity-90 whitespace-nowrap'
                                            : 'mt-1 text-[10px] font-bold text-gray-400 bg-gray-50 border border-gray-200 rounded-lg px-2.5 py-1 whitespace-nowrap cursor-default'"
                                        :aria-disabled="!itemHasFollowup123Preview(item)"
                                    >{{ itemHasFollowup123Preview(item) ? '查看跟进' : '暂无跟进' }}</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div v-if="tab==='list'" class="space-y-4">
            
            <div class="bg-white rounded-xl shadow-sm border border-slate-100 p-2 flex gap-2">
                <div class="flex-1 flex items-center bg-gray-50 rounded-lg px-3">
                    <i class="fas fa-search text-gray-400 text-sm"></i>
                    <input v-model="historySearch" type="text" class="w-full p-2 bg-transparent text-sm outline-none" placeholder="搜姓名/电话/项目/进度/状态...">
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-3 flex justify-between items-center cursor-pointer bg-gray-50 border-b border-gray-100" @click="showFilters = !showFilters">
                    <div class="text-xs font-bold text-slate-700"><i class="fas fa-filter text-purple-500 mr-1"></i> 高级筛选</div>
                    <div class="text-xs text-gray-400"><i class="fas" :class="showFilters ? 'fa-chevron-up' : 'fa-chevron-down'"></i></div>
                </div>
                <div v-if="showFilters" class="p-4 bg-white animate-[slideUp_0.2s_ease-out]">
                    <p class="text-[10px] text-slate-600 mb-2 leading-relaxed">开始/结束日期：历史记录按每条<strong class="text-slate-700">报备创建时间</strong>筛选。下定各阶段时间并集规则仅在工作台「成交/完成」Tab 生效。</p>
                    <div class="grid grid-cols-2 gap-3 mb-3"><div><label class="filter-label">开始日期</label><input v-model="filters.dateStart" type="date" class="filter-input"></div><div><label class="filter-label">结束日期</label><input v-model="filters.dateEnd" type="date" class="filter-input"></div></div>
                    <div class="mb-3">
                        <label class="filter-label">所属楼盘（可多选）</label>
                        <p v-if="isStrictProjectSite" class="text-[10px] text-gray-400 mb-2 leading-relaxed">与后台「项目库」管理员或「人员档案-关联项目」一致；仅显示上述方式关联到您的楼盘，不含仅因本人经纪人身份产生的报备盘。</p>
                        <p v-else class="text-[10px] text-gray-400 mb-2 leading-relaxed">仅列出您权限范围内的楼盘（可见报备涉及的项目，以及后台设为本人/本组驻场负责人的项目）</p>
                        <input v-model="projectFilterKeyword" type="text" class="filter-input mb-2" placeholder="输入关键字筛选楼盘…" autocomplete="off">
                        <div class="flex items-center justify-between gap-2 mb-2 text-[10px]">
                            <span class="text-gray-400">已选 <b class="text-slate-600">{{ (filters.projectIds || []).length }}</b> 个，不选表示全部</span>
                            <button type="button" @click="clearProjectFilter" class="text-purple-600 font-bold shrink-0">清空已选</button>
                        </div>
                        <div class="border border-gray-200 rounded-lg max-h-44 overflow-y-auto bg-slate-50/80 p-1.5 space-y-0.5">
                            <label v-for="p in filteredProjectsForFilter" :key="p.id" class="flex items-center gap-2 text-xs py-2 px-2 rounded-lg hover:bg-white cursor-pointer border border-transparent hover:border-gray-100">
                                <input type="checkbox" class="rounded border-gray-300 text-purple-600 focus:ring-purple-500" :checked="isProjectFilterChecked(p.id)" @change="toggleProjectFilter(p.id)">
                                <span class="flex-1 min-w-0 truncate text-slate-700">{{ p.name }}</span>
                            </label>
                            <div v-if="filteredProjectsForFilter.length===0" class="text-xs text-gray-400 py-4 text-center">无匹配楼盘</div>
                        </div>
                    </div>
                    <div class="mb-3"><label class="filter-label">状态</label><select v-model="filters.statusFilter" class="filter-input"><option v-for="opt in historyStatusFilterOptions" :key="opt.value === '' ? '_all' : opt.value" :value="opt.value">{{ opt.label }}</option></select></div>
                    <div class="mb-3"><label class="filter-label">客户意向度</label><select v-model="filters.intentionFilter" class="filter-input"><option v-for="opt in clientIntentionFilterOptions" :key="'intent_list_' + (opt.value === '' ? '_all' : opt.value)" :value="opt.value">{{ opt.label }}</option></select></div>
                    <div class="grid grid-cols-2 gap-3">
                        <button type="button" @click="resetFilters" class="bg-gray-100 text-gray-500 py-2 rounded-lg text-xs font-bold">重置条件</button>
                        <button type="button" @click="applyFilters" class="bg-purple-600 text-white py-2 rounded-lg text-xs font-bold">确定筛选</button>
                    </div>
                </div>
            </div>
            
            <div v-if="groupedHistoryList.length===0" class="text-center py-10 text-gray-300 text-xs">没有找到符合条件的记录</div>
            <div v-for="(group, idx) in groupedHistoryList" :key="group._groupKey || idx" class="bg-white rounded-2xl card-shadow overflow-hidden mb-3">
                <div class="bg-slate-50 p-3 border-b border-gray-100 flex justify-between items-center"><div class="min-w-0 font-bold text-sm text-slate-800"><span>{{ group.client_name }}</span> <span class="text-xs font-normal text-gray-400 font-mono"><span v-html="formatPhone(group.client_phone)"></span></span><span v-if="(group.items[0].company_name || group.items[0].company_full_name || '').trim()" class="block text-[10px] font-normal text-slate-500 truncate mt-0.5" :title="(group.items[0].company_name || group.items[0].company_full_name || '').trim()">{{ truncateText((group.items[0].company_name || group.items[0].company_full_name || '').trim(), 22) }}</span></div><span class="text-[10px] text-gray-400 shrink-0">{{ group.items.length }} 记录</span></div>
                <div class="divide-y divide-gray-50"><div v-for="item in group.items" :key="item.id" class="p-3 flex justify-between items-center gap-2"><div class="min-w-0 flex-1"><div class="font-bold text-xs text-slate-700">{{ item.project_name }}</div><div class="text-[10px] text-slate-500 mt-0.5">客户意向: {{ clientIntentionLabel(item) }}</div><div class="text-[10px] text-gray-400 mt-0.5">{{ item.created_at.substring(5,19) }} · {{ item.agent_name }}</div></div><div class="text-right shrink-0"><span class="text-[10px] px-2 py-0.5 rounded font-bold mb-1 inline-block" :class="statusClass(item.status)">{{ statusText(item.status) }}</span><button @click="showTimeline(item)" class="block text-[10px] text-blue-500 mt-1">查看详情</button></div></div></div>
            </div>
        </div>
    </div>

    <div class="fixed bottom-0 w-full max-w-md flex justify-around py-3 pt-2.5 text-[10px] z-40 glass-nav">
        <div @click="tab='dash'" :class="tab=='dash' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-chart-line text-xl"></i><span>数据</span></div>
        <div @click="goToFiling" class="glass-nav-item flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-file-alt text-xl"></i><span>报备</span></div>
        <div @click="tab='work'" :class="tab=='work' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-check-double text-xl"></i><span>工作台</span></div>
        <div @click="tab='list'" :class="tab=='list' ? 'glass-nav-item-active' : 'glass-nav-item'" class="flex flex-col items-center gap-1 cursor-pointer min-w-[56px]"><i class="fas fa-clock text-xl"></i><span>记录</span></div>
    </div>

    <div v-if="showModal" class="fixed inset-0 z-50 flex items-end justify-center bg-black/60 backdrop-blur-sm">
        <div class="bg-white w-full rounded-t-[2rem] p-6 animate-[slideUp_0.3s_ease-out] max-h-[90vh] overflow-y-auto">
            <div class="w-12 h-1.5 bg-gray-200 rounded-full mx-auto mb-6"></div>
            <h3 class="font-bold text-xl mb-1" :class="currentItem.status == 4 ? 'text-rose-600' : (currentItem.status == 3 ? 'text-green-700' : 'text-slate-800')">{{ currentItem.status == 5 || currentItem.status == 6 ? '修改状态' : getActionText(currentItem.status, { dealWorkbench: subTab === 3 }) }}</h3>
            <p class="text-xs text-gray-400 mb-6">客户: {{ currentItem.client_name }} - {{ currentItem.project_name }}</p>
            <div class="space-y-4 mb-6">
                <div v-if="currentItem.status==1">
                    <label class="text-xs font-bold text-slate-500 block mb-3">到访类型 (必选)</label>
                    <div class="grid grid-cols-2 gap-3 mb-4">
                            <div @click="toggleVisitType(0)" class="border rounded-xl p-3 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="visitType===0?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><i class="fas fa-file-alt text-xl mb-1"></i><span class="text-xs font-bold">有效报备</span></div>
                            <div @click="toggleVisitType(1)" class="border rounded-xl p-3 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="visitType===1?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><i class="fas fa-check-circle text-xl mb-1"></i><span class="text-xs font-bold">有效到访</span></div>
                            <div @click="toggleVisitType(2)" class="border rounded-xl p-3 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="visitType===2?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><i class="fas fa-times-circle text-xl mb-1"></i><span class="text-xs font-bold">无效到访</span></div>
                            <div @click="toggleVisitType(3)" class="border rounded-xl p-3 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="visitType===3?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><i class="fas fa-clone text-xl mb-1"></i><span class="text-xs font-bold">重复到访</span></div>
                        </div>
                    <div v-if="[1,2,3].includes(visitType)" class="mb-3">
                        <label class="block text-xs font-bold text-slate-500 mb-1"><i class="fas fa-clock text-purple-500 mr-1"></i>到访时间 (必填)</label>
                        <input v-model="visitTime" type="datetime-local" class="w-full bg-white border border-purple-100 rounded-xl p-3 text-sm outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100">
                    </div>
                    <div class="bg-purple-50 rounded-2xl p-4 border border-purple-100 mb-3"><div class="flex justify-between text-xs text-purple-500 font-bold mb-2"><span><i class="fas fa-microphone mr-1"></i> 备注说明 (选填)</span></div><div class="flex gap-3 items-center"><button @click="toggleRecord" class="w-10 h-10 rounded-full flex-shrink-0 flex items-center justify-center transition-all shadow-md" :class="recording?'bg-red-500 text-white mic-active':'bg-white text-purple-500'" ><i class="fas" :class="recording?'fa-stop':'fa-microphone'"></i></button><textarea v-model="voiceText" placeholder="客户意向、关注点..." class="flex-1 bg-white rounded-xl p-2 h-10 border border-purple-100 text-xs resize-none"></textarea></div></div>
                    <div class="mb-3">
                        <label class="block text-xs font-bold text-slate-500 mb-1"><i class="fas fa-user-tie text-purple-500 mr-1"></i>销售 (选填)</label>
                        <input v-model="salesperson" type="text" class="w-full bg-white border border-purple-100 rounded-xl p-3 text-sm outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100" placeholder="请输入销售" autocomplete="off">
                    </div>
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-4 bg-slate-50"><div v-if="attachmentList.length === 0" class="text-center py-4 relative cursor-pointer"><i class="fas fa-camera text-slate-300 text-2xl mb-2"></i><p class="text-xs text-slate-400">上传凭证 (选填)</p><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div><div v-else class="grid grid-cols-3 gap-2"><div v-for="(url, idx) in attachmentList" :key="idx" class="relative aspect-square rounded-lg overflow-hidden border border-gray-200"><img @click="openAttachmentPreview(url)" :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90"><div @click.stop="removeAttachment(idx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl-lg text-[10px] cursor-pointer">×</div></div><div class="relative aspect-square rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center bg-white"><i class="fas fa-plus text-gray-300"></i><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div></div></div>
                </div>
                <div v-if="currentItem.status==5 || currentItem.status==6">
                    <label class="text-xs font-bold text-slate-500 block mb-3">状态修改 (必选)</label>
                    <div class="grid grid-cols-1 gap-3 mb-4">
                        <div @click="visitType=1" class="border rounded-xl p-4 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50 border-purple-500 bg-purple-50 text-purple-700" ><i class="fas fa-undo text-xl mb-1"></i><span class="text-xs font-bold">重新标记为待处理</span></div>
                    </div>
                    <div class="bg-purple-50 rounded-2xl p-4 border border-purple-100 mb-3"><div class="flex justify-between text-xs text-purple-500 font-bold mb-2"><span><i class="fas fa-microphone mr-1"></i> 备注说明 (选填)</span></div><textarea v-model="voiceText" placeholder="修改原因..." class="w-full bg-white rounded-xl p-2 h-10 border border-purple-100 text-xs resize-none"></textarea></div>
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-4 bg-slate-50"><div v-if="attachmentList.length === 0" class="text-center py-4 relative cursor-pointer"><i class="fas fa-camera text-slate-300 text-2xl mb-2"></i><p class="text-xs text-slate-400">上传凭证 (选填)</p><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div><div v-else class="grid grid-cols-3 gap-2"><div v-for="(url, idx) in attachmentList" :key="idx" class="relative aspect-square rounded-lg overflow-hidden border border-gray-200"><img @click="openAttachmentPreview(url)" :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90"><div @click.stop="removeAttachment(idx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl-lg text-[10px] cursor-pointer">×</div></div><div class="relative aspect-square rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center bg-white"><i class="fas fa-plus text-gray-300"></i><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div></div></div>
                </div>
                <div v-if="currentItem.status==2 || currentItem.status==4 || currentItem.status==7">
                    <div class="mb-4">
                        <label class="text-xs font-bold text-slate-500 block mb-3">客户意向 (必选)</label>
                        <div class="grid grid-cols-5 gap-2">
                            <div @click="clientIntention=clientIntention===5?0:5" class="border rounded-lg p-2 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="clientIntention===5?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><span class="text-[10px] font-bold">非常强烈</span></div>
                            <div @click="clientIntention=clientIntention===4?0:4" class="border rounded-lg p-2 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="clientIntention===4?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><span class="text-[10px] font-bold">强烈</span></div>

                            <div @click="clientIntention=clientIntention===2?0:2" class="border rounded-lg p-2 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="clientIntention===2?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><span class="text-[10px] font-bold">还行</span></div>
                            <div @click="clientIntention=clientIntention===1?0:1" class="border rounded-lg p-2 flex flex-col items-center justify-center cursor-pointer transition hover:bg-gray-50" :class="clientIntention===1?'border-purple-500 bg-purple-50 text-purple-700':'border-gray-200 text-gray-400'" ><span class="text-[10px] font-bold">无意向</span></div>
                        </div>
                    </div>
                    <label class="text-xs font-bold text-orange-600 block mb-2"><i class="fas fa-tasks mr-1"></i> 下定进度 (可多选/暂存)</label>
                    <div class="grid grid-cols-6 gap-2 mb-4">
                        <div @click="togglePreLockSignBundle" class="stage-box col-span-6 min-h-[3.25rem]" :class="{checked: preLockSignBundleChecked}"><div class="stage-icon"><i class="fas fa-check" v-if="preLockSignBundleChecked"></i></div><span class="text-xs font-bold leading-tight text-center px-1">锁房 签约认购书</span></div>
                        <div @click="toggleStage('subscription')" class="stage-box col-span-3" :class="{checked: subStages.includes('subscription')}"><div class="stage-icon"><i class="fas fa-check" v-if="subStages.includes('subscription')"></i></div><span class="text-xs font-bold">认购</span></div>
                        <div @click="toggleStage('contract')" class="stage-box col-span-3" :class="{checked: subStages.includes('contract')}"><div class="stage-icon"><i class="fas fa-check" v-if="subStages.includes('contract')"></i></div><span class="text-xs font-bold">签约</span></div>
                        <div @click="toggleStage('biz_confirm')" class="stage-box col-span-3" :class="{checked: subStages.includes('biz_confirm')}"><div class="stage-icon"><i class="fas fa-check" v-if="subStages.includes('biz_confirm')"></i></div><span class="text-xs font-bold">业确</span></div>
                        <div @click="toggleStage('refund_submit')" class="stage-box col-span-3 border-rose-200" :class="{checked: subStages.includes('refund_submit'), 'bg-rose-50 border-rose-300': subStages.includes('refund_submit')}"><div class="stage-icon"><i class="fas fa-check" v-if="subStages.includes('refund_submit')"></i></div><span class="text-xs font-bold text-rose-700">退房</span></div>
                    </div>
                    <div v-if="subStages.includes('sign')" class="mb-3 space-y-3">
                        <div>
                            <label class="text-xs font-bold text-slate-500 block mb-2">客户号码 (必填)</label>
                            <input v-model="clientPhone" type="text" inputmode="numeric" autocomplete="tel" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-3 text-sm outline-none font-mono tracking-wide" placeholder="请输入客户号码" required @blur="onClientPhoneBlur">
                            <p class="text-[10px] text-slate-400 mt-1">默认展示前三位 + **** + 后四位；修改请清空后输入完整号码，失焦后自动脱敏</p>
                        </div>
                    </div>
                    <label class="text-xs font-bold text-slate-500 block mb-2">房号 (选填)</label><input v-model="roomNumber" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-3 text-sm outline-none mb-3" placeholder="如: 1栋2单元301">
                    <div v-if="subStages.includes('sign')" class="bg-purple-50 rounded-xl p-4 border border-purple-100 mb-3">
                        <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-clock mr-1"></i> 锁房 签约认购书时间</label>
                        <input v-model="signSubstageDatetimeLocal" type="datetime-local" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-mono outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100">
                    </div>
                    <div v-if="subStages.includes('subscription')" class="space-y-3 mb-3">
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-coins mr-1"></i> 认购金额 (元，选填)</label>
                            <input v-model="subscriptionAmount" type="number" step="0.01" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm outline-none" placeholder="认购金额">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-clock mr-1"></i> 认购时间 (精确到分钟，选填)</label>
                            <input :key="'sub-at-' + subscriptionDatetimeInputKey" v-model="subscriptionAtDatetimeLocal" type="datetime-local" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-mono outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-user mr-1"></i> 认购人姓名 (可多人)</label>
                            <input v-model="subscriberName" type="text" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="姓名/备注信息">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-phone mr-1"></i> 认购联系方式（可多个）</label>
                            <input v-model="subscriptionPhoneFull" type="text" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm outline-none" placeholder="多个号码请用逗号分隔">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-ruler-combined mr-1"></i> 认购面积 (㎡，选填)</label>
                            <input v-model="transactionArea" type="number" step="0.01" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="面积">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-yen-sign mr-1"></i> 认购总价 (元，选填)</label>
                            <input v-model="dealPrice" type="number" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="认购总价">
                        </div>
                    </div>
                    <div v-if="subStages.includes('contract')" class="space-y-3 mb-3">
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-yen-sign mr-1"></i> 签约总价 (元，选填)</label>
                            <input v-model="contractTotalPrice" type="number" step="0.01" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm outline-none" placeholder="签约总价">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-clock mr-1"></i> 签约时间 (精确到分钟，选填)</label>
                            <input :key="'con-at-' + contractSignedDatetimeInputKey" v-model="contractSignedAtDatetimeLocal" type="datetime-local" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm font-mono outline-none focus:border-purple-400 focus:ring-2 focus:ring-purple-100">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-hand-holding-usd mr-1"></i> 成交金额 (元，选填)</label>
                            <input v-model="transactionAmount" type="number" step="0.01" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm outline-none" placeholder="成交金额">
                        </div>
                        <div class="bg-purple-50 rounded-xl p-4 border border-purple-100">
                            <label class="text-xs font-bold text-purple-700 block mb-2"><i class="fas fa-gift mr-1"></i> 请选择佣金【佣金套餐】</label>
                            <select v-model="commissionPackageId" class="w-full bg-white border border-purple-200 rounded-lg p-3 text-sm outline-none">
                                <option value="">请选择</option>
                                <option v-for="p in commissionPackagesList" :key="'pkg'+p.id" :value="String(p.id)">{{ p.package_name }}（佣比 {{ p.commission_pct }}%，现金奖 {{ p.cash_reward }}<template v-if="Number(p.jump_ratio) != 0 || Number(p.jump_reward) != 0">；跳点 {{ p.jump_ratio }}%，跳点奖 {{ p.jump_reward }}</template>）</option>
                            </select>
                            <p v-if="staffCommissionPreview && staffCommissionPreview.pending" class="text-[10px] text-slate-600 mt-2 leading-relaxed">填写「成交金额」后自动计算：<span class="font-medium text-slate-700">成交金额×佣金比例% + 现金奖励 + 成交金额×跳点比例% + 跳点奖励</span>。</p>
                            <div v-else-if="staffCommissionPreview" class="mt-2 space-y-1">
                                <p class="text-sm font-bold text-red-600 tracking-tight">佣金合计：<span class="text-base">¥{{ staffCommissionPreview.total }}</span></p>
                                <p class="text-[10px] text-slate-600 leading-relaxed break-words">{{ staffCommissionPreview.formulaLine }}</p>
                            </div>
                            <p v-else-if="commissionPackagesList.length === 0" class="text-[10px] text-slate-500 mt-2 leading-relaxed">本项目暂无启用中的佣金套餐，请在管理端「项目编辑」中配置并启用。</p>
                        </div>
                    </div>
                    <div v-if="subStages.includes('biz_confirm')" class="bg-amber-50 rounded-xl p-4 border border-amber-200 mb-3">
                        <label class="text-xs font-bold text-amber-800 block mb-2"><i class="fas fa-stamp mr-1"></i> 业确时间 (精确到分钟)</label>
                        <input v-model="bizConfirmAtDatetimeLocal" type="datetime-local" class="w-full bg-white border border-amber-200 rounded-lg p-3 text-sm font-mono outline-none focus:border-amber-500 focus:ring-2 focus:ring-amber-100">
                    </div>
                    <div v-if="subStages.includes('refund_submit')" class="bg-rose-50 rounded-xl p-4 border border-rose-200 mb-3">
                        <label class="text-xs font-bold text-rose-800 block mb-2"><i class="fas fa-door-open mr-1"></i> 退房时间 (精确到分钟)</label>
                        <input :key="'refund-dt-' + refundDatetimeInputKey" v-model="refundSubmittedAtDatetimeLocal" type="datetime-local" class="w-full bg-white border border-rose-200 rounded-lg p-3 text-sm font-mono outline-none focus:border-rose-400 focus:ring-2 focus:ring-rose-100">
                    </div>
                    <div class="bg-purple-50 rounded-2xl p-4 border border-purple-100 mb-3"><div class="flex justify-between text-xs text-purple-500 font-bold mb-2"><span><i class="fas fa-microphone mr-1"></i> 备注 (选填)</span></div><textarea v-model="voiceText" placeholder="补充说明..." class="w-full bg-white rounded-xl p-2 h-16 border border-purple-100 text-xs resize-none outline-none"></textarea></div>
                    <div class="mb-3 bg-slate-50 border border-slate-200 rounded-xl p-3">
                        <div class="text-[11px] font-bold text-slate-600 mb-2"><i class="fas fa-history text-purple-500 mr-1"></i> 历史备注</div>
                        <div v-if="historyRemarks.length === 0" class="text-[11px] text-slate-400">暂无历史备注</div>
                        <div v-else class="max-h-24 overflow-y-auto space-y-2 pr-1">
                            <div v-for="(r, idx) in historyRemarks" :key="'remark_' + idx" class="bg-white border border-slate-200 rounded-lg p-2">
                                <div class="text-[10px] text-slate-400 mb-1">{{ r.time }}</div>
                                <div class="text-[11px] text-slate-700 leading-relaxed break-words">{{ r.text }}</div>
                            </div>
                        </div>
                    </div>
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-4 bg-slate-50"><div v-if="attachmentList.length === 0" class="text-center py-4 relative cursor-pointer"><i class="fas fa-file-invoice text-slate-300 text-xl mb-1"></i><p class="text-xs text-slate-400">上传单据 (选填)</p><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div><div v-else class="grid grid-cols-3 gap-2"><div v-for="(url, idx) in attachmentList" :key="idx" class="relative aspect-square rounded-lg overflow-hidden border border-gray-200"><img @click="openAttachmentPreview(url)" :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90"><div @click.stop="removeAttachment(idx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl-lg text-[10px] cursor-pointer">×</div></div><div class="relative aspect-square rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center bg-white"><i class="fas fa-plus text-gray-300"></i><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div></div></div>
                    <div v-if="subStages.includes('biz_confirm')" class="mb-3 rounded-xl border border-amber-300 bg-amber-50/90 p-4">
                        <div class="flex items-center justify-between gap-2 mb-2">
                            <span class="text-xs font-bold text-amber-900"><i class="fas fa-images mr-1"></i>业确附件（仅图片，可多张）</span>
                            <button type="button" @click="showBizConfirmUploadModal = true" class="text-[10px] font-bold text-amber-800 bg-white border border-amber-300 rounded-lg px-2 py-1 shrink-0">上传 / 管理</button>
                        </div>
                        <p v-if="bizConfirmAttachmentList.length === 0" class="text-[11px] text-amber-800/85 leading-relaxed">勾选「业确」时会弹出上传；也可点此区域右上角随时补充。</p>
                        <div v-else class="flex flex-wrap gap-2">
                            <div v-for="(url, bidx) in bizConfirmAttachmentList" :key="'bizatt_'+bidx" class="relative w-[4.5rem] h-[4.5rem] rounded-lg overflow-hidden border border-amber-200 bg-white shrink-0">
                                <img :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90" @click="openAttachmentPreview(url)" alt="">
                                <div @click.stop="removeBizConfirmAttachment(bidx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl text-[10px] cursor-pointer font-bold">×</div>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="currentItem.status==3">
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-user mr-1"></i> 认购人信息</label><input v-model="subscriberName" type="text" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="姓名/备注信息"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-home mr-1"></i> 认购房号</label><input v-model="subscribedRoomNumber" type="text" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" :placeholder="currentItem.room_number"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-ruler-combined mr-1"></i> 购房面积 (㎡)</label><input v-model="transactionArea" type="number" step="0.01" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="请输入购房面积"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-user-tie mr-1"></i> 销售人员</label><input v-model="salesperson" type="text" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="请输入销售人员姓名"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-phone mr-1"></i> 认购电话全号（可多个）</label><input v-model="subscriptionPhoneFull" type="text" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="多个号码请用逗号分隔"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-calendar mr-1"></i> 认购日期</label><input v-model="subscriptionDate" type="date" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-user-edit mr-1"></i> 成交录入人</label><input v-model="transactionRecorder" type="text" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none" placeholder="请输入录入人姓名"></div>
                    <div class="bg-green-50 rounded-xl p-4 border border-green-100 mb-3"><label class="text-xs font-bold text-green-600 block mb-2"><i class="fas fa-yen-sign mr-1"></i> 认购总价/成交总价 (必填)</label><input v-model="dealPrice" type="number" class="w-full bg-white border border-green-200 rounded-lg p-3 text-sm font-bold outline-none"></div>
                    <div class="border-2 border-dashed border-slate-200 rounded-xl p-4 bg-slate-50"><div v-if="attachmentList.length === 0" class="text-center py-4 relative cursor-pointer"><i class="fas fa-file-contract text-slate-300 text-xl mb-1"></i><p class="text-xs text-slate-400">上传购房合同 (必填)</p><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div><div v-else class="grid grid-cols-3 gap-2"><div v-for="(url, idx) in attachmentList" :key="idx" class="relative aspect-square rounded-lg overflow-hidden border border-gray-200"><img @click="openAttachmentPreview(url)" :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90"><div @click.stop="removeAttachment(idx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl-lg text-[10px] cursor-pointer">×</div></div><div class="relative aspect-square rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center bg-white"><i class="fas fa-plus text-gray-300"></i><input type="file" @change="handleFile" multiple class="absolute inset-0 opacity-0 w-full h-full"></div></div></div>
                </div>
            </div>
            
            <div v-if="currentItem.status==2" class="flex gap-3"><button @click="submitAction('save')" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl font-bold shadow-sm text-sm active:scale-95 transition">保存进度</button><button @click="submitAction('submit')" class="flex-1 bg-slate-900 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">确认去签约</button></div>
            <div v-if="currentItem.status==4 || currentItem.status==7" class="flex gap-3"><button @click="submitAction('save')" class="flex-1 bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">保存进度</button></div>
            <div v-if="currentItem.status==3" class="flex flex-col gap-2 mb-3">
                <div class="flex gap-2">
                    <button type="button" @click="submitAction('save')" class="flex-1 bg-white border border-slate-200 text-slate-700 py-3 rounded-xl font-bold shadow-sm text-sm active:scale-95 transition">保存进度</button>
                    <button type="button" @click="submitAction('submit')" class="flex-1 bg-slate-900 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">录入成交</button>
                </div>
                <button type="button" @click="submitAction('refund')" class="w-full bg-red-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">退房</button>
            </div>
            <button v-else-if="currentItem.status==1 || currentItem.status==5 || currentItem.status==6" @click="submitAction('submit')" class="w-full bg-slate-900 text-white py-3 rounded-xl font-bold shadow-lg text-sm mb-3 active:scale-95 transition">确认提交</button>
            <button @click="showModal=false" class="w-full text-gray-400 text-xs py-3 mt-1">取消</button>
        </div>
    </div>
    
    <div v-if="showTime" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-6" @click.self="showTime=false"><div class="bg-white w-full rounded-2xl p-6 shadow-2xl relative max-h-[70vh] overflow-y-auto"><button @click="showTime=false" class="absolute top-4 right-4 text-gray-400"><i class="fas fa-times"></i></button><h3 class="font-bold text-lg mb-6 text-slate-800">订单全流程</h3><div class="bg-gray-50 p-4 rounded-xl mb-6"><h4 class="font-bold text-sm text-slate-700 mb-3">报备详情</h4><div class="space-y-4">
                <div class="grid grid-cols-1 gap-2">
                    <div><span class="text-gray-500 text-sm">客户姓名:</span> <span class="font-medium text-base ml-2">{{ currentItem.client_name }}</span></div>
                    <div><span class="text-gray-500 text-sm">客户电话:</span> <span class="ml-2 text-base" v-html="formatPhone(currentItem.client_phone)"></span></div>
                </div>
                <div class="grid grid-cols-2 gap-3 text-xs">
                    <div><span class="text-gray-500">经纪人:</span> <span class="font-medium">{{ currentItem.agent_name || '未知' }}</span></div>
                    <div><span class="text-gray-500">经纪人号码:</span> <a :href="'tel:' + currentItem.agent_phone" class="text-blue-500 font-medium">{{ currentItem.agent_phone || '未知' }}</a></div>
                    <div class="col-span-2"><span class="text-gray-500">经纪公司:</span> <span class="font-medium">{{ ((currentItem.company_name || '').trim() || (currentItem.company_full_name || '').trim()) || '未知' }}</span></div>
                    <div class="col-span-2"><span class="text-gray-500">渠道人员:</span> <span class="font-medium">{{ ((currentItem.follower || '') + '').trim() || '公池' }}</span></div>
                    <div><span class="text-gray-500">项目:</span> <span class="font-medium text-[10px]">{{ currentItem.project_name }}</span></div>
                    <div><span class="text-gray-500">客户意向:</span> <span class="font-medium">
                        {{ currentItem.client_intention == 5 ? '非常强烈' : 
                           currentItem.client_intention == 4 ? '强烈' : 
                           currentItem.client_intention == 2 ? '还行' : 
                           currentItem.client_intention == 1 ? '无意向' : '未设置' }}
                    </span></div>
                    <div><span class="text-gray-500">报备时间:</span> <span class="font-medium">{{ currentItem.created_at }}</span></div>
                </div>
                <div v-if="orderFlowAttachmentUrls.length && !timelineHasInlineAttachments" class="pt-3 mt-3 border-t border-gray-200">
                    <div class="text-xs font-bold text-slate-500 mb-2">上传凭证</div>
                    <div class="flex flex-wrap gap-2">
                        <img v-for="(u, ui) in orderFlowAttachmentUrls" :key="ui" :src="u" alt="" class="w-16 h-16 object-cover rounded-lg border border-slate-200 cursor-pointer active:opacity-90 shrink-0" @click="openAttachmentPreview(u)">
                    </div>
                </div>
            </div></div><div class="pl-2">
                <!-- 合并状态日志和跟进记录 -->
                <div v-for="(event, idx) in combinedTimeline" :key="idx" class="timeline-item">
                    <div class="timeline-dot active"></div>
                    <div class="text-xs text-gray-400 mb-1">{{ event.time }}</div>
                    <div class="text-sm font-bold text-slate-700">{{ event.title }}</div>
                    <div v-if="event.desc" class="text-xs text-slate-500 bg-slate-50 p-2 rounded mt-1">{{ event.desc }}</div>
                    <div v-if="event.attachmentUrls && event.attachmentUrls.length" class="flex flex-wrap gap-2 mt-2">
                        <img v-for="(u, ai) in event.attachmentUrls" :key="ai" :src="u" alt="" class="w-16 h-16 object-cover rounded-lg border border-slate-200 cursor-pointer active:opacity-90 shrink-0" @click="openAttachmentPreview(u)">
                    </div>
                </div>
            </div></div></div>

    <teleport to="body">
    <div v-if="showFollowupPreview" class="fixed inset-0 z-[56] flex items-end sm:items-center justify-center bg-black/60 backdrop-blur-sm p-4" @click.self="closeFollowupPreview">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl relative max-h-[82vh] overflow-y-auto p-5 border border-slate-100" @click.stop>
            <button type="button" @click="closeFollowupPreview" class="absolute top-3 right-3 text-gray-400 p-2" aria-label="关闭"><i class="fas fa-times text-lg"></i></button>
            <h3 class="font-bold text-base text-slate-800 pr-8">跟进预览</h3>
            <p class="text-[11px] text-slate-500 mt-1 mb-3">第 1～3 次跟进摘要</p>
            <div v-if="followupPreviewItem" class="text-[11px] text-slate-600 mb-3 pb-3 border-b border-slate-100 leading-snug">
                <span class="font-bold text-slate-800">{{ followupPreviewItem.client_name }}</span>
                <span class="text-slate-400 mx-1">·</span>
                <span class="text-slate-700">{{ followupPreviewItem.project_name }}</span>
            </div>
            <div v-if="followupPreviewItem" class="space-y-3">
                <template v-if="!followupPreviewAllEmpty123">
                    <div v-for="slot in followupPreviewSlotsList" :key="'fp_' + slot.n" class="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
                        <div class="flex items-center justify-between gap-2 mb-1">
                            <span class="text-xs font-bold text-slate-900">第{{ slot.n }}次跟进</span>
                            <span v-if="slot.rec && slot.rec.created_at" class="text-[10px] text-slate-400 tabular-nums shrink-0">{{ formatDateTime(slot.rec.created_at) }}</span>
                        </div>
                        <div v-if="slot.rec" class="text-xs text-slate-600 leading-relaxed whitespace-pre-wrap break-words">{{ followupPreviewText(slot.rec) }}</div>
                        <div v-else class="text-xs text-gray-400">暂无记录</div>
                    </div>
                </template>
                <p v-else class="text-center text-sm text-gray-400 py-10 px-2 leading-relaxed">暂无跟进</p>
            </div>
            <button type="button" @click="closeFollowupPreview" class="w-full mt-4 bg-slate-900 text-white py-2.5 rounded-xl text-sm font-bold active:opacity-95">关闭</button>
        </div>
    </div>
    </teleport>
    
    <!-- 待处理提示弹窗（Teleport 到 body，避免被外层 DOM/样式影响编译与层级） -->
    <teleport to="body">
    <div v-if="showPendingModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/60 backdrop-blur-sm p-6" @click.self="handlePendingModal('confirm')">
        <div class="bg-white w-full rounded-2xl p-6 shadow-2xl max-h-[80vh] overflow-y-auto">
            <div class="text-center mb-4">
                <h3 class="font-bold text-lg text-slate-800 mb-2">您有 <span v-text="pendingItems.length"></span> 个待处理项目</h3>
                <p class="text-xs text-gray-400">请尽快处理以下项目</p>
            </div>
            <div class="space-y-3 mb-6 max-h-[40vh] overflow-y-auto">
                <div v-for="item in pendingItems" :key="item.id" class="bg-gray-50 p-3 rounded-xl">
                    <div class="flex justify-between items-start">
                        <div class="flex-1">
                            <div class="font-bold text-sm text-slate-800">{{ item.project_name }}</div>
                            <div class="text-xs text-gray-400 mt-1">客户: {{ item.client_name }}</div>
                        </div>
                        <div class="text-right">
                            <div class="text-xs text-orange-500 font-bold">{{ calculateTimeAgo(item.created_at) }}</div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="space-y-3">
                <button @click="handlePendingModal('confirm')" class="w-full bg-purple-600 text-white py-3 rounded-xl font-bold shadow-lg text-sm active:scale-95 transition">确定</button>
                <div class="grid grid-cols-2 gap-3">
                    <button @click="handlePendingModal('1hour')" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl font-bold shadow-sm text-sm active:scale-95 transition">1小时不再提醒</button>
                    <button @click="handlePendingModal('day')" class="flex-1 bg-white border border-slate-200 text-slate-600 py-3 rounded-xl font-bold shadow-sm text-sm active:scale-95 transition">当天不再提醒</button>
                </div>
            </div>
        </div>
    </div>
    </teleport>

    <teleport to="body">
    <div v-if="showBizConfirmUploadModal" class="fixed inset-0 z-[65] flex items-center justify-center bg-black/60 backdrop-blur-sm p-4" @click.self="showBizConfirmUploadModal=false">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl p-5 border border-amber-100 max-h-[85vh] overflow-y-auto" @click.stop>
            <div class="flex items-start justify-between gap-2 mb-3">
                <div class="min-w-0">
                    <h3 class="font-bold text-base text-slate-800">业确附件</h3>
                    <p class="text-[11px] text-slate-500 mt-1 leading-relaxed">仅支持图片格式，可多选上传；完成后点底部「完成」关闭。</p>
                </div>
                <button type="button" class="text-slate-400 p-1 shrink-0" @click="showBizConfirmUploadModal=false" aria-label="关闭"><i class="fas fa-times text-lg"></i></button>
            </div>
            <div class="border-2 border-dashed border-amber-200 rounded-xl p-3 bg-amber-50/50 min-h-[120px]">
                <div v-if="bizConfirmAttachmentList.length === 0" class="flex flex-col items-center justify-center py-10 relative">
                    <i class="fas fa-images text-amber-300 text-3xl mb-2"></i>
                    <p class="text-xs text-amber-900/85 font-medium">点击此区域选择图片</p>
                    <input type="file" accept="image/*" multiple @change="handleBizConfirmFile" class="absolute inset-0 opacity-0 w-full h-full cursor-pointer">
                </div>
                <div v-else class="grid grid-cols-3 gap-2">
                    <div v-for="(url, idx) in bizConfirmAttachmentList" :key="'bizmod_'+idx" class="relative aspect-square rounded-lg overflow-hidden border border-amber-200 bg-white">
                        <img :src="url" class="w-full h-full object-cover cursor-pointer active:opacity-90" @click="openAttachmentPreview(url)" alt="">
                        <div @click.stop="removeBizConfirmAttachment(idx)" class="absolute top-0 right-0 bg-red-500 text-white w-5 h-5 flex items-center justify-center rounded-bl text-[10px] cursor-pointer font-bold">×</div>
                    </div>
                    <div class="relative aspect-square rounded-lg border-2 border-dashed border-amber-300 flex items-center justify-center bg-white">
                        <i class="fas fa-plus text-amber-400 text-xl"></i>
                        <input type="file" accept="image/*" multiple @change="handleBizConfirmFile" class="absolute inset-0 opacity-0 w-full h-full cursor-pointer">
                    </div>
                </div>
            </div>
            <button type="button" @click="showBizConfirmUploadModal=false" class="w-full mt-4 bg-amber-600 hover:bg-amber-700 text-white py-3 rounded-xl font-bold text-sm transition">完成</button>
        </div>
    </div>
    </teleport>

    <teleport to="body">
    <div v-if="showPwdModal" class="fixed inset-0 z-[60] flex items-end justify-center bg-black/60 backdrop-blur-sm" @click.self="showPwdModal=false">
        <div class="bg-white w-full rounded-t-[2rem] p-6 animate-[slideUp_0.3s_ease-out] max-h-[90vh] overflow-y-auto">
            <div class="w-12 h-1.5 bg-gray-200 rounded-full mx-auto mb-4"></div>
            <h3 class="font-bold text-xl mb-1 text-slate-800">修改密码</h3>
            <p class="text-xs text-gray-400 mb-5">验证原密码后设置新密码（至少 6 位）</p>
            <div class="space-y-3 mb-6">
                <div>
                    <label class="filter-label">原密码</label>
                    <input v-model="pwdOld" type="password" autocomplete="current-password" class="filter-input py-3" placeholder="当前登录密码">
                </div>
                <div>
                    <label class="filter-label">新密码</label>
                    <input v-model="pwdNew" type="password" autocomplete="new-password" class="filter-input py-3" placeholder="至少 6 位">
                </div>
                <div>
                    <label class="filter-label">确认新密码</label>
                    <input v-model="pwdConfirm" type="password" autocomplete="new-password" class="filter-input py-3" placeholder="再次输入">
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <button type="button" @click="showPwdModal=false" class="bg-slate-100 text-slate-600 py-3 rounded-xl font-bold text-sm">取消</button>
                <button type="button" @click="submitPwdChange" :disabled="pwdSubmitting" class="bg-purple-600 text-white py-3 rounded-xl font-bold text-sm disabled:opacity-50"><span v-text="pwdSubmitting ? '提交中…' : '保存'"></span></button>
            </div>
        </div>
    </div>
    </teleport>
    <teleport to="body">
        <div v-if="previewAttachmentUrl" class="fixed inset-0 z-[70] flex flex-col bg-black/95" @click="closeAttachmentPreview">
            <button type="button" @click.stop="closeAttachmentPreview" class="absolute top-3 right-3 z-10 w-10 h-10 rounded-full bg-white/15 text-white text-2xl leading-none flex items-center justify-center" aria-label="关闭">×</button>
            <div class="flex-1 flex items-center justify-center p-4 pt-14 min-h-0 overflow-auto" @click="closeAttachmentPreview">
                <img :src="previewAttachmentUrl" alt="" class="max-w-full max-h-full w-auto h-auto object-contain select-none" @click.stop>
            </div>
        </div>
    </teleport>
</div>

<script>
const { createApp, ref, computed, onMounted, nextTick, watch } = Vue;
createApp({
    setup() {
        const userInfo = <?php echo json_encode($CURRENT_USER, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS); ?>;
        const shouldShowPendingReminder = ((userInfo.company || '').indexOf('项目驻场') !== -1);
        /** 与 PHP staff_is_strict_project_site_scope 一致：纯项目驻场（不含服务中心） */
        const isStrictProjectSite = computed(() => {
            const c = userInfo.company || '';
            return c.indexOf('项目驻场') !== -1 && c.indexOf('服务中心') === -1;
        });
        const tab = ref('work');
        const subTab = ref(0);
        const childFilter = ref('reception_all');
        const list = ref([]);
        const stats = ref({
            month_report_unique: 0,
            month_visit_unique: 0,
            month_deal_total: 0,
            month_conversion_rate: 0,
            range_start: '',
            range_end: '',
            chart_labels: [],
            chart_data: { report: [], visit: [], deal: [] }
        });
        const todayStr = new Date().toISOString().split('T')[0];
        const statsRange = ref('today');
        const statsCustomStart = ref('');
        const statsCustomEnd = ref('');
        const projects = ref([]);
        const workSearch = ref('');
        const historySearch = ref(''); // 新增：历史记录搜索
        const showModal = ref(false);
        const showTime = ref(false);
        const showFollowupPreview = ref(false);
        const followupPreviewItem = ref(null);
        const showPendingModal = ref(false);
        const pendingItems = ref([]);
        const currentItem = ref({});
        const voiceText = ref('');
        const roomNumber = ref('');
        const dealPrice = ref('');
        const visitType = ref(1);
        const visitTime = ref('');
        const clientIntention = ref(0);
        const subStages = ref([]);
        /** 签认购书勾选时间（datetime-local），与「客户号码」同一行右侧展示 */
        const signSubstageDatetimeLocal = ref('');
        const subscriptionAmount = ref('');
        const subscriptionAtDatetimeLocal = ref('');
        const contractTotalPrice = ref('');
        const contractSignedAtDatetimeLocal = ref('');
        const transactionAmount = ref('');
        const commissionPackagesList = ref([]);
        const commissionPackageId = ref('');
        const bizConfirmAtDatetimeLocal = ref('');
        const refundSubmittedAtDatetimeLocal = ref('');
        const refundDatetimeInputKey = ref(0);
        const subscriptionDatetimeInputKey = ref(0);
        const contractSignedDatetimeInputKey = ref(0);
        const attachmentList = ref([]);
        const bizConfirmAttachmentList = ref([]);
        const showBizConfirmUploadModal = ref(false);
        const previewAttachmentUrl = ref(null);
        const openAttachmentPreview = (url) => { previewAttachmentUrl.value = url; };
        const closeAttachmentPreview = () => { previewAttachmentUrl.value = null; };
        const recording = ref(false);
        const clientPhone = ref('');
        /** 客户号码完整数字（仅 digits），与输入框脱敏展示配对 */
        const rawClientPhone = ref('');
        const maskClientDigitsForDisplay = (digitsStr) => {
            const d = String(digitsStr || '').replace(/\D/g, '');
            if (d.length >= 7) return d.slice(0, 3) + '****' + d.slice(-4);
            return d || '';
        };
        const normalizeClientPhoneForSave = (inputValue, rawDigitsFallback) => {
            const text = String(inputValue ?? '').trim();
            if (!text) return '';
            const rawDigits = String(rawDigitsFallback || '').replace(/\D/g, '');
            if (!text.includes('*')) {
                const d = text.replace(/\D/g, '');
                return d.length ? d : '';
            }
            const expected = rawDigits.length >= 7 ? maskClientDigitsForDisplay(rawDigits) : '';
            const compact = text.replace(/\s/g, '');
            if (expected && (compact === expected || text.replace(/\s/g, '') === expected)) return rawDigits;
            return rawDigits;
        };
        /** 失焦：含 * 时保持脱敏展示，不展开为纯数字；纯数字输入则更新并脱敏 */
        const onClientPhoneBlur = () => {
            const t = String(clientPhone.value || '').trim();
            if (!t) {
                rawClientPhone.value = '';
                clientPhone.value = '';
                return;
            }
            const rawDigits = String(rawClientPhone.value || '').replace(/\D/g, '');
            if (t.includes('*')) {
                if (rawDigits.length >= 7) {
                    clientPhone.value = maskClientDigitsForDisplay(rawDigits);
                }
                return;
            }
            let d = t.replace(/\D/g, '');
            if (d.length > 11) d = d.slice(0, 11);
            if (d.length >= 7) {
                rawClientPhone.value = d;
                clientPhone.value = maskClientDigitsForDisplay(d);
            }
        };
        const subscriberName = ref('');
        const subscribedRoomNumber = ref('');
        const transactionArea = ref('');
        const salesperson = ref('');
        const subscriptionPhoneFull = ref('');
        const subscriptionDate = ref('');
        const transactionRecorder = ref('');
        const currentDate = ref(new Date().toLocaleDateString('zh-CN', {month:'long', day:'numeric', weekday:'long'}));
        const showFilters = ref(false);
        const isSearchingHistory = ref(false);
        const teamMembers = ref([]);
        const selectedMemberId = ref('');
        const teamFilterEnabled = ref(false);
        const digestTab = ref('project');
        const digestData = ref(null);
        const digestLoading = ref(false);
        const digestLoadFailed = ref(false);
        const digestExpandCh = ref({});
        const digestExpandProj = ref({});
        const DIGEST_EFF_DEFAULT = ['store_count', 'store_avg_report', 'report_to_visit_rate', 'avg_visit_batches_per_broker', 'deal_conversion_rate'];
        const digestEffKeys = computed(() => (digestData.value && Array.isArray(digestData.value.efficiency_dimension_keys) && digestData.value.efficiency_dimension_keys.length)
            ? digestData.value.efficiency_dimension_keys
            : DIGEST_EFF_DEFAULT);
        const digestMetricLabels = computed(() => (digestData.value && digestData.value.metric_labels) ? digestData.value.metric_labels : {});
        const digestTriple = ['报备', '带看', '成交'];
        const filters = ref({ dateStart: todayStr, dateEnd: todayStr, projectIds: [], statusFilter: '', intentionFilter: '' });
        const projectFilterKeyword = ref('');
        const filteredProjectsForFilter = computed(() => {
            const kw = projectFilterKeyword.value.trim();
            const arr = projects.value || [];
            if (!kw) return arr;
            return arr.filter((p) => (p.name || '').includes(kw));
        });
        const isProjectFilterChecked = (id) => (filters.value.projectIds || []).map(String).includes(String(id));
        const toggleProjectFilter = (id) => {
            const sid = String(id);
            const cur = [...(filters.value.projectIds || [])].map(String);
            const idx = cur.indexOf(sid);
            if (idx >= 0) cur.splice(idx, 1);
            else cur.push(sid);
            filters.value = { ...filters.value, projectIds: cur };
        };
        const clearProjectFilter = () => {
            filters.value = { ...filters.value, projectIds: [] };
        };

        const historyStatusFilterOptions = [
            { value: '', label: '全部状态' },
            { value: 'pending', label: '待处理' },
            { value: 'valid_report', label: '有效报备' },
            { value: 'valid_visit', label: '有效到访' },
            { value: 'stage_3', label: '成交客户' },
            { value: 'deal', label: '成交' },
            { value: 'invalid_visit', label: '到访无效' },
            { value: 'repeat', label: '重复到访' },
            { value: 'invalid_report', label: '报备无效' },
            { value: 'refund', label: '退房' }
        ];
        const clientIntentionFilterOptions = [
            { value: '', label: '全部意向度' },
            { value: '0', label: '未设置' },
            { value: '5', label: '非常强烈' },
            { value: '4', label: '强烈' },
            { value: '3', label: '一般' },
            { value: '2', label: '还行' },
            { value: '1', label: '无意向' }
        ];

        const itemMatchesClientIntentionFilter = (item, key) => {
            if (key === '') return true;
            const v = parseInt(item && item.client_intention, 10);
            const normalized = isNaN(v) ? 0 : v;
            return String(normalized) === String(key);
        };

        const itemMatchesHistoryStatusFilter = (item, key) => {
            if (!key) return true;
            const s = parseInt(item.status, 10);
            const vtRaw = item.visit_type;
            const vt = vtRaw === null || vtRaw === undefined || vtRaw === '' ? null : parseInt(vtRaw, 10);
            switch (key) {
                case 'pending':
                    return s === 1 && (vt === null || isNaN(vt) || vt !== 0);
                case 'valid_report':
                    return s === 1 && vt === 0;
                case 'valid_visit':
                    return s >= 2 && s < 5 && vt === 1;
                case 'stage_2':
                    return s === 2;
                case 'stage_3':
                    return s === 3;
                case 'deal':
                    return s === 4;
                case 'invalid_visit':
                    return s === 5 && vt === 2;
                case 'repeat':
                    return s === 5 && vt === 3;
                case 'invalid_report':
                    return s === 6;
                case 'refund':
                    return s === 7;
                default:
                    return true;
            }
        };

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

        const pad2 = (n) => String(n).padStart(2, '0');
        const currentDatetimeLocalValue = () => {
            const d = new Date();
            return `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}T${pad2(d.getHours())}:${pad2(d.getMinutes())}`;
        };
        /** 解析 datetime-local / 库内时间字符串，统一为 yyyy-MM-ddTHH:mm */
        const parseDatetimeLocalInput = (raw) => {
            const s = String(raw || '').trim().replace(/\//g, '-');
            if (!s) return null;
            const m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T\s](\d{2}):(\d{2})(?::\d{2})?/);
            if (!m) return null;
            return { y: m[1], mo: m[2], d: m[3], h: m[4], mi: m[5] };
        };
        const datetimeLocalToSql = (raw) => {
            const p = parseDatetimeLocalInput(raw);
            return p ? `${p.y}-${p.mo}-${p.d} ${p.h}:${p.mi}:00` : '';
        };
        const toDatetimeLocalValue = (value) => {
            const p = parseDatetimeLocalInput(value);
            return p ? `${p.y}-${p.mo}-${p.d}T${p.h}:${p.mi}` : '';
        };
        const DEAL_STAGE_DB_COL = {
            subscription: 'subscription_at',
            contract: 'contract_signed_at',
            biz_confirm: 'biz_confirm_at',
            refund_submit: 'refund_submitted_at',
        };
        /** 展示/筛选：优先库字段，其次 status_log 勾选时间 */
        const dealStageTimeFromItem = (item, stageKey) => {
            if (!item) return '';
            if (stageKey === 'lock' || stageKey === 'sign' || stageKey === 'deposit') {
                if (item.pre_lock_sign_bundle_at) return String(item.pre_lock_sign_bundle_at);
            }
            const col = DEAL_STAGE_DB_COL[stageKey];
            if (col && item[col]) return String(item[col]);
            return getDealStageTimeFromLog(item, stageKey) || '';
        };
        const formatDateTime = (value) => {
            const text = String(value || '').trim();
            if (!text) return '-';
            return text.substring(5, 16);
        };

        /** 与 PHP staff_substage_newly_checked_log_fragment 写入的文案一致；含「锁房 签约认购书」合并记录 */
        const SUBSTAGE_LOG_LABELS = { deposit: '诚意金', lock: '认筹', sign: '签认购书', subscription: '认购', contract: '签约', biz_confirm: '业确', refund_submit: '退房' };
        const BUNDLE_LOG_LABEL = '锁房 签约认购书';
        const parseSubStageCheckTimesFromLog = (statusLog) => {
            const out = { deposit: null, lock: null, sign: null, subscription: null, contract: null, biz_confirm: null, refund_submit: null };
            const log = String(statusLog || '');
            const re = /\[(?:勾选时间|阶段时间修订):\s*([^\]]+)\]/g;
            let m;
            while ((m = re.exec(log)) !== null) {
                const block = m[1];
                const parts = block.split(/；|;/);
                for (const raw of parts) {
                    const seg = String(raw || '').trim();
                    if (seg.startsWith(BUNDLE_LOG_LABEL + ' ')) {
                        const rest = seg.slice(BUNDLE_LOG_LABEL.length + 1).trim();
                        const mm = rest.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
                        if (mm) {
                            out.deposit = mm[1];
                            out.lock = mm[1];
                            out.sign = mm[1];
                        }
                        continue;
                    }
                    const legacyRefund = '提交退房 ';
                    if (seg.startsWith(legacyRefund)) {
                        const rest = seg.slice(legacyRefund.length).trim();
                        const mm = rest.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
                        if (mm) out.refund_submit = mm[1];
                        continue;
                    }
                    for (const key of Object.keys(SUBSTAGE_LOG_LABELS)) {
                        const lab = SUBSTAGE_LOG_LABELS[key];
                        if (seg.startsWith(lab + ' ')) {
                            const rest = seg.slice(lab.length + 1).trim();
                            const mm = rest.match(/^(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})/);
                            if (mm) out[key] = mm[1];
                        }
                    }
                }
            }
            return out;
        };
        const getDealStageTimeFromLog = (item, stageKey) => {
            const t = parseSubStageCheckTimesFromLog(item && item.status_log);
            return t[stageKey] || '';
        };
        /** 与 PHP staff_refund_operation_time_log_fragment 一致，取最后一次退房操作时间 */
        const getRefundOperationTimeFromLog = (item) => {
            const log = String(item && item.status_log ? item.status_log : '');
            let last = '';
            const re = /\[退房操作时间:\s*(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]/g;
            let m;
            while ((m = re.exec(log)) !== null) {
                last = m[1];
            }
            return last;
        };
        const workbenchDealStageTimeText = (item, cf) => {
            if (cf === 'refund') {
                if (item && item.status == 7) {
                    const raw = getRefundOperationTimeFromLog(item);
                    return raw ? raw.substring(5, 19) : '';
                }
                const rawR = dealStageTimeFromItem(item, 'refund_submit');
                return rawR ? String(rawR).substring(5, 19) : '';
            }
            if (cf === 'subscription') {
                const raw = dealStageTimeFromItem(item, 'subscription') || dealStageTimeFromItem(item, 'sign');
                return raw ? String(raw).substring(5, 19) : '';
            }
            if (cf === 'contract') {
                const raw = dealStageTimeFromItem(item, 'contract');
                return raw ? String(raw).substring(5, 19) : '';
            }
            if (cf === 'biz_confirm') {
                const raw = dealStageTimeFromItem(item, 'biz_confirm');
                return raw ? String(raw).substring(5, 19) : '';
            }
            if (cf === 'lock') {
                const raw = dealStageTimeFromItem(item, 'sign') || dealStageTimeFromItem(item, 'lock') || dealStageTimeFromItem(item, 'deposit');
                return raw ? String(raw).substring(5, 19) : '';
            }
            if (cf === 'all') {
                const raw =
                    dealStageTimeFromItem(item, 'sign') ||
                    dealStageTimeFromItem(item, 'subscription') ||
                    dealStageTimeFromItem(item, 'contract') ||
                    dealStageTimeFromItem(item, 'biz_confirm') ||
                    dealStageTimeFromItem(item, 'refund_submit');
                return raw ? String(raw).substring(5, 19) : '';
            }
            if (cf === 'deposit' || cf === 'lock' || cf === 'sign') {
                const raw = getDealStageTimeFromLog(item, cf);
                return raw ? raw.substring(5, 19) : '';
            }
            return '';
        };
        const workbenchDealStageCardTitle = (cf) => {
            if (cf === 'all') return '阶段时间';
            if (cf === 'lock') return '锁房';
            if (cf === 'subscription') return '认购';
            if (cf === 'contract') return '签约';
            if (cf === 'biz_confirm') return '业确';
            if (cf === 'refund') return '退房';
            return '';
        };
        const workbenchDealDateFilterHint = computed(() => {
            if (subTab.value != 3) return '';
            const unionTail =
                '「成交/完成」下各子 Tab 与「全部」一致：任一下定相关时间（含库内 pre_lock_sign_bundle_at / subscription_at / contract_signed_at / biz_confirm_at / refund_submitted_at 及日志勾选时间）落在区间内即显示；无记录时按报备时间。';
            return '开始/结束日期：' + unionTail;
        });

        /** 工作台：日期 / 楼盘 / 高级状态 / 搜索（不含主 Tab、子 Tab）——与列表 narrowing 一致，供 Tab 数字统计复用 */
        /** 工作台/历史搜索：支持完整号码、尾号 4 位、以及 173****8379 等脱敏展示格式与库内号码匹配 */
        const workbenchPhoneSearchMatch = (phoneRaw, kwRaw) => {
            const phone = String(phoneRaw ?? '');
            const kw = String(kwRaw ?? '').trim();
            if (!kw || !phone) {
                return false;
            }
            if (phone.includes(kw)) {
                return true;
            }
            const pd = phone.replace(/\D/g, '');
            if (!pd) {
                return false;
            }
            if (kw.includes('*')) {
                const noSpace = kw.replace(/\s+/g, '');
                const chunks = noSpace.split(/\*+/).map((s) => s.replace(/\D/g, '')).filter(Boolean);
                if (chunks.length >= 2) {
                    return pd.startsWith(chunks[0]) && pd.endsWith(chunks[chunks.length - 1]);
                }
                if (chunks.length === 1 && chunks[0].length >= 4) {
                    return pd.includes(chunks[0]);
                }
            }
            const kd = kw.replace(/\D/g, '');
            if (kd && pd.includes(kd)) {
                return true;
            }
            if (kd && /^\d{3,4}$/.test(kd) && pd.endsWith(kd)) {
                return true;
            }
            return false;
        };

        const getWorkbenchDateValue = (item, options = {}) => {
            if (options.useVisitDate) {
                const vt = item && item.visit_time ? String(item.visit_time).trim() : '';
                if (vt) return vt;
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate === 'refund') {
                if (item && item.status == 7) {
                    const ts = getRefundOperationTimeFromLog(item);
                    if (ts) return ts;
                }
                const tsSub = getDealStageTimeFromLog(item, 'refund_submit');
                if (tsSub) return tsSub;
                if (item && item.refund_submitted_at) return String(item.refund_submitted_at);
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate === 'subscription') {
                const ts = dealStageTimeFromItem(item, 'subscription') || dealStageTimeFromItem(item, 'sign');
                if (ts) return ts;
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate === 'contract') {
                const ts = dealStageTimeFromItem(item, 'contract');
                if (ts) return ts;
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate === 'biz_confirm') {
                const ts = dealStageTimeFromItem(item, 'biz_confirm');
                if (ts) return ts;
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate === 'lock_bundle') {
                const ts = dealStageTimeFromItem(item, 'sign') || dealStageTimeFromItem(item, 'lock') || dealStageTimeFromItem(item, 'deposit');
                if (ts) return ts;
                return String(item && item.created_at ? item.created_at : '');
            }
            if (options.dealStageDate) {
                const ts = getDealStageTimeFromLog(item, options.dealStageDate);
                if (ts) return ts;
            }
            return String(item && item.created_at ? item.created_at : '');
        };
        const applyWorkbenchGlobalFilters = (items, options = {}) => {
            let res = items;
            const f = filters.value;
            const useVisitDate = !!options.useVisitDate;
            const dealStageDate = options.dealStageDate || null;
            const dealDateUnionModes = ['deposit', 'lock_bundle', 'subscription', 'contract', 'biz_confirm', 'refund'];
            if (f.dateStart && res.length > 0) {
                res = res.filter((item) => {
                    if (dealStageDate === '__deal_date_union__') {
                        return dealDateUnionModes.some((mode) => {
                            const dateValue = getWorkbenchDateValue(item, { useVisitDate, dealStageDate: mode });
                            return dateValue && dateValue >= f.dateStart;
                        });
                    }
                    const dateValue = getWorkbenchDateValue(item, { useVisitDate, dealStageDate });
                    return dateValue && dateValue >= f.dateStart;
                });
            }
            if (f.dateEnd && res.length > 0) {
                res = res.filter((item) => {
                    if (dealStageDate === '__deal_date_union__') {
                        return dealDateUnionModes.some((mode) => {
                            const dateValue = getWorkbenchDateValue(item, { useVisitDate, dealStageDate: mode });
                            return dateValue && dateValue <= f.dateEnd + ' 23:59:59';
                        });
                    }
                    const dateValue = getWorkbenchDateValue(item, { useVisitDate, dealStageDate });
                    return dateValue && dateValue <= f.dateEnd + ' 23:59:59';
                });
            }
            const pids = f.projectIds || [];
            if (pids.length > 0 && res.length > 0) {
                const set = new Set(pids.map(String));
                res = res.filter((item) => set.has(String(item.project_id)));
            }
            if (f.statusFilter && res.length > 0) {
                res = res.filter((item) => itemMatchesHistoryStatusFilter(item, f.statusFilter));
            }
            if (f.intentionFilter !== '' && res.length > 0) {
                res = res.filter((item) => itemMatchesClientIntentionFilter(item, f.intentionFilter));
            }
            const kw = workSearch.value.trim();
            if (kw && res.length > 0) {
                res = res.filter(
                    (i) =>
                        (i.client_phone && workbenchPhoneSearchMatch(i.client_phone, kw)) ||
                        (i.client_name && i.client_name.includes(kw)) ||
                        (i.project_name && i.project_name.includes(kw)) ||
                        (i.broker_name && i.broker_name.includes(kw)) ||
                        (i.broker_phone && workbenchPhoneSearchMatch(i.broker_phone, kw)) ||
                        (i.agent_name && i.agent_name.includes(kw)) ||
                        (i.agent_phone && workbenchPhoneSearchMatch(i.agent_phone, kw)) ||
                        (i.company_name && i.company_name.includes(kw)) ||
                        (i.company_full_name && i.company_full_name.includes(kw)) ||
                        (i.store_name && i.store_name.includes(kw))
                );
            }
            return res;
        };

        const workbenchBaseFilteredList = computed(() => applyWorkbenchGlobalFilters(list.value, {}));
        const workbenchVisitDateFilteredList = computed(() => applyWorkbenchGlobalFilters(list.value, { useVisitDate: true }));

        const normalizePhoneForNewCustomer = (phone) => {
            const raw = String(phone || '').trim();
            if (!raw) return '';
            const digits = raw.replace(/\D/g, '');
            if (digits.length >= 7) {
                return digits.substring(0, 3) + '****' + digits.substring(digits.length - 4);
            }
            const maskedMatch = raw.match(/^(\d{3})\*+(\d{4})$/);
            if (maskedMatch) {
                return maskedMatch[1] + '****' + maskedMatch[2];
            }
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

        /** 待接待子筛选：在全局筛选后的数据上统计各子项数量 */
        const receptionChildCounts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const visitBase = workbenchVisitDateFilteredList.value;
            const n = (pred) => base.filter(pred).length;
            const vn = (pred) => visitBase.filter(pred).length;
            const vtNum = (i) => {
                const r = i.visit_type;
                if (r === null || r === undefined || r === '') return null;
                const v = parseInt(r, 10);
                return Number.isNaN(v) ? null : v;
            };
            return {
                reception_all: n((i) => {
                    return i.status == 1;
                }),
                todo: n((i) => i.status == 1 && (!i.visit_type || i.visit_type != 0)),
                valid_report: n((i) => i.status == 1 && i.visit_type == 0),
                valid: vn((i) => isStatus2VisitPool(i)),
                invalid: n((i) => i.status == 6),
                invalid_report: n((i) => i.status == 6),
                invalid_visit: n((i) => i.status == 5 && i.visit_type == 2),
                repeat: n((i) => isNewCustomer(i)),
            };
        });

        const visitChildCounts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const n = (pred) => base.filter(pred).length;
            return {
                visit_all: n((i) => isStatus2VisitPool(i) || (i.status == 5 && i.visit_type == 2)),
                visit_valid: n((i) => isStatus2VisitPool(i)),
                visit_invalid: n((i) => i.status == 5 && i.visit_type == 2),
            };
        });

        const workbenchDealStageSet = (i) => {
            const raw = String(i && i.sub_stages ? i.sub_stages : '');
            return new Set(raw.split(',').map((s) => s.trim()).filter(Boolean));
        };
        /** 与卡片「有效到访」标签一致：status=2 且 visit_type=1（与是否勾选下定进度无关；判断口径与模板 v-if 相同） */
        const isWorkbenchValidVisitRow = (i) => i.status == 2 && i.visit_type == 1;
        const isStatus2VisitPool = (i) => isWorkbenchValidVisitRow(i);
        /** 成交/完成：待录入/已成交/退房；status=2 时仅「非有效到访」且已勾选下定进度 */
        const isWorkbenchDealTabRow = (i) => {
            if (i.status == 7 || i.status == 3 || i.status == 4) return true;
            if (i.status != 2) return false;
            if (isWorkbenchValidVisitRow(i)) return false;
            return workbenchDealStageSet(i).size > 0;
        };
        /** 认购子 Tab：与 workbenchDealExclusiveChildKey 一致，每条只在「当前最高阶段」占一格，避免与签约/锁房重复计数 */
        const isWorkbenchSubscriptionStageRow = (i) => workbenchDealExclusiveChildKey(i) === 'subscription';
        /** 签约子 Tab：同上 */
        const isWorkbenchContractStageRow = (i) => workbenchDealExclusiveChildKey(i) === 'contract';
        const isWorkbenchBizConfirmStageRow = (i) => workbenchDealExclusiveChildKey(i) === 'biz_confirm';
        /** 退房：status=7 或勾选了退房（不以 refund_submitted_at 孤儿时间） */
        const isWorkbenchRefundStageRow = (i) => i.status == 7 || workbenchDealStageSet(i).has('refund_submit');
        /** 锁房：仅当前最高阶段为锁房（诚意金/认筹/签认购书）的客户 */
        const isWorkbenchLockTabRow = (i) => workbenchDealExclusiveChildKey(i) === 'lock';

        /** 卡片进度：顺序 1锁房→2认购→3签约→4业确→5退房；多选时只显示序号最大的一档；status=3 视同认购档、status=4 视同签约档，与列表子 Tab 一致 */
        const WORKBENCH_DEAL_FIVE_STAGE_LABELS = ['', '锁房 签约认购书', '认购', '签约', '业确', '退房'];
        const workbenchDealFiveStageMax = (item) => {
            const set = workbenchDealStageSet(item);
            const st = parseInt(item && item.status, 10);
            let max = 0;
            const s1 = set.has('deposit') || set.has('lock') || set.has('sign');
            const s2 = !set.has('refund_submit') && (set.has('subscription') || st === 3);
            const s3 = set.has('contract') || st === 4;
            const s4 = set.has('biz_confirm');
            const s5 = set.has('refund_submit') || st === 7;
            if (s1) max = 1;
            if (s2) max = 2;
            if (s3) max = 3;
            if (s4) max = 4;
            if (s5) max = 5;
            return max;
        };
        /** 「成交/完成」子 Tab 互斥：客户只归入当前漏斗最高一档（与角标、列表筛选一致） */
        const workbenchDealExclusiveChildKey = (i) => {
            const mx = workbenchDealFiveStageMax(i);
            if (mx === 5) return 'refund';
            if (mx === 4) return 'biz_confirm';
            if (mx === 3) return 'contract';
            if (mx === 2) return 'subscription';
            if (mx === 1) return 'lock';
            return '';
        };
        const workbenchDealFiveStageLabel = (item) => {
            const n = workbenchDealFiveStageMax(item);
            return n > 0 ? WORKBENCH_DEAL_FIVE_STAGE_LABELS[n] : '';
        };

        /** 成交子 Tab 角标：与「全部」同一套日期并集筛选，避免 lock/contract 等单列日期与子 Tab 漏计 */
        const depositChildCounts = computed(() => {
            const baseCreated = applyWorkbenchGlobalFilters(list.value, {});
            const baseAllUnion = applyWorkbenchGlobalFilters(list.value, { dealStageDate: '__deal_date_union__' });
            const n = (arr, pred) => arr.filter(pred).length;
            return {
                all: n(baseAllUnion, (i) => isWorkbenchDealTabRow(i)),
                lock: n(baseAllUnion, (i) => isWorkbenchLockTabRow(i)),
                subscription: n(baseAllUnion, (i) => isWorkbenchSubscriptionStageRow(i)),
                contract: n(baseAllUnion, (i) => isWorkbenchContractStageRow(i)),
                biz_confirm: n(baseAllUnion, (i) => isWorkbenchBizConfirmStageRow(i)),
                refund: n(baseAllUnion, (i) => isWorkbenchRefundStageRow(i)),
                todo: n(baseCreated, (i) => i.status == 2 && i.client_intention == 0 && !i.sub_stages),
            };
        });

        const counts = computed(() => {
            const base = workbenchBaseFilteredList.value;
            const c = { 0: 0, 1: 0, 2: 0, 3: 0 };
            base.forEach((i) => {
                c[0]++;
                if (i.status != 2 && c[i.status] !== undefined) c[i.status]++;
            });
            c[2] = base.filter((i) => isStatus2VisitPool(i) || (i.status == 5 && i.visit_type == 2)).length;
            // 主 Tab「成交/完成」角标与子 Tab「全部」一致（见 depositChildCounts.all / __deal_date_union__）
            c[3] = depositChildCounts.value.all;
            return c;
        });

        // 工作台筛选：主 Tab / 子筛选始终生效，再叠加日期、楼盘、高级状态、搜索（与角标统计口径一致）
        const filteredWorkList = computed(() => {
            let res = list.value;

            if (subTab.value === 1) {
                if (childFilter.value === 'reception_all') {
                    res = res.filter((i) => i.status == 1);
                } else if (childFilter.value === 'todo') {
                    res = res.filter((i) => i.status == 1 && (!i.visit_type || i.visit_type != 0));
                } else if (childFilter.value === 'valid_report') {
                    res = res.filter((i) => i.status == 1 && i.visit_type == 0);
                } else if (childFilter.value === 'valid') {
                    res = res.filter((i) => isStatus2VisitPool(i));
                } else if (childFilter.value === 'invalid') {
                    res = res.filter((i) => i.status == 6);
                } else if (childFilter.value === 'repeat') {
                    res = res.filter((i) => isNewCustomer(i));
                }
            } else if (subTab.value === 2) {
                res = res.filter((i) => isStatus2VisitPool(i) || (i.status == 5 && i.visit_type == 2));
                if (childFilter.value === 'visit_valid') {
                    res = res.filter((i) => isStatus2VisitPool(i));
                } else if (childFilter.value === 'visit_invalid') {
                    res = res.filter((i) => i.status == 5 && i.visit_type == 2);
                }
            } else if (subTab.value !== 0 && !(subTab.value === 2 && childFilter.value === 'todo')) {
                if (subTab.value === 2 && childFilter.value === 'refund') {
                    res = res.filter((i) => i.status == 7);
                } else if (subTab.value === 3) {
                    // 成交/完成：下定中 + 确认去签约后(status3) + 已成交(status4) + 退房
                    res = res.filter((i) => isWorkbenchDealTabRow(i));
                } else {
                    res = res.filter((i) => i.status == subTab.value);
                }
            }

            if (subTab.value === 3) {
                if (childFilter.value !== 'all') {
                    if (childFilter.value === 'todo') {
                        res = res.filter((i) => i.status == 2 && i.client_intention == 0 && !i.sub_stages);
                    } else if (subTab.value === 3) {
                        if (childFilter.value === 'refund') {
                            res = res.filter((i) => isWorkbenchRefundStageRow(i));
                        } else {
                            res = res.filter((i) => {
                                if (childFilter.value === 'lock') return isWorkbenchLockTabRow(i);
                                if (childFilter.value === 'subscription') return isWorkbenchSubscriptionStageRow(i);
                                if (childFilter.value === 'contract') return isWorkbenchContractStageRow(i);
                                if (childFilter.value === 'biz_confirm') return isWorkbenchBizConfirmStageRow(i);
                                return false;
                            });
                        }
                    }
                }
            }

            /** 来访客户列表与角标同口径：日期按报备时间筛；报备子 Tab「有效到访」仍按到访时间 */
            const useVisitDate = subTab.value === 1 && childFilter.value === 'valid';
            let dealStageDate = null;
            if (subTab.value === 3) {
                /** 成交/完成下各子 Tab 与角标一致：按各阶段日期并集筛，避免「全部」与子 Tab 数量对不上 */
                dealStageDate = '__deal_date_union__';
            }
            return applyWorkbenchGlobalFilters(res, { useVisitDate, dealStageDate });
        });

        /** 工作台/记录列表分组：同电话不同经纪公司（门店）拆开，避免并成一张卡片 */
        const staffGroupKey = (item) => {
            const phone = String(item && item.client_phone != null ? item.client_phone : '').trim();
            const company =
                (item && item.company_name && String(item.company_name).trim()) ||
                (item && item.company_full_name && String(item.company_full_name).trim()) ||
                '';
            return `${phone}\x1e${company}`;
        };
        const groupItems = (items, sortTimeFn = null) => {
            const pick = sortTimeFn || ((it) => new Date(it.created_at).getTime());
            const groups = {};
            items.forEach((item) => {
                const k = staffGroupKey(item);
                if (!groups[k]) {
                    groups[k] = {
                        _groupKey: k,
                        client_name: item.client_name,
                        client_phone: item.client_phone,
                        items: [],
                    };
                }
                groups[k].items.push(item);
            });
            Object.values(groups).forEach((group) => {
                group.items.sort((a, b) => pick(b) - pick(a));
            });
            return Object.values(groups).sort((a, b) => pick(b.items[0]) - pick(a.items[0]));
        };

        const pendingCount = computed(() => depositChildCounts.value.todo);

        const groupedWorkList = computed(() => {
            const rows = filteredWorkList.value;
            let sortFn = null;
            if (subTab.value === 3) {
                if (childFilter.value === 'all') {
                    sortFn = (it) => {
                        const ts = getDealStageTimeFromLog(it, 'deposit');
                        if (ts) return new Date(ts.replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                } else if (childFilter.value === 'refund') {
                    sortFn = (it) => {
                        if (it.status == 7) {
                            const ts = getRefundOperationTimeFromLog(it);
                            if (ts) return new Date(ts.replace(' ', 'T')).getTime();
                        }
                        const ts2 = getDealStageTimeFromLog(it, 'refund_submit') || (it.refund_submitted_at ? String(it.refund_submitted_at) : '');
                        if (ts2) return new Date(String(ts2).replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                } else if (childFilter.value === 'subscription') {
                    sortFn = (it) => {
                        const ts = dealStageTimeFromItem(it, 'subscription') || dealStageTimeFromItem(it, 'sign');
                        if (ts) return new Date(String(ts).replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                } else if (childFilter.value === 'contract') {
                    sortFn = (it) => {
                        const ts = dealStageTimeFromItem(it, 'contract');
                        if (ts) return new Date(String(ts).replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                } else if (childFilter.value === 'biz_confirm') {
                    sortFn = (it) => {
                        const ts = dealStageTimeFromItem(it, 'biz_confirm');
                        if (ts) return new Date(String(ts).replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                } else if (childFilter.value === 'lock') {
                    sortFn = (it) => {
                        const ts = dealStageTimeFromItem(it, 'sign') || dealStageTimeFromItem(it, 'lock') || dealStageTimeFromItem(it, 'deposit');
                        if (ts) return new Date(String(ts).replace(' ', 'T')).getTime();
                        return new Date(it.created_at).getTime();
                    };
                }
            }
            return groupItems(rows, sortFn);
        });
        
        // 核心：历史记录全能搜索逻辑
        const filteredList = computed(() => {
            return list.value.filter(item => {
                // 1. 全能搜索 (Keyword)
                const k = historySearch.value.trim();
                if (k) {
                    const statusStr = statusText(item.status);
                    const fiveLab = workbenchDealFiveStageLabel(item);
                    const stageText =
                        (fiveLab ? fiveLab + ' ' : '') +
                        (item.sub_stages || '')
                            .replace('deposit', '交定')
                            .replace('lock', '锁房')
                            .replace('sign', '签约')
                            .replace('subscription', '认购')
                            .replace('contract', '签约')
                            .replace('biz_confirm', '业确')
                            .replace('refund_submit', '退房');
                    const match = item.client_name.includes(k) || workbenchPhoneSearchMatch(item.client_phone, k) ||
                                  item.project_name.includes(k) || (item.agent_name && item.agent_name.includes(k)) ||
                                  statusStr.includes(k) || stageText.includes(k) ||
                                  (item.broker_name && item.broker_name.includes(k)) ||
                                  (item.broker_phone && workbenchPhoneSearchMatch(item.broker_phone, k)) ||
                                  (item.agent_phone && workbenchPhoneSearchMatch(item.agent_phone, k)) ||
                                  (item.company_name && item.company_name.includes(k)) ||
                                  (item.company_full_name && item.company_full_name.includes(k)) ||
                                  (item.store_name && item.store_name.includes(k));
                    if (!match) return false;
                }

                // 2. 既有筛选 (Advanced Filter)
                const f = filters.value;
                if (f.dateStart && item.created_at < f.dateStart) return false;
                if (f.dateEnd && item.created_at > f.dateEnd + ' 23:59:59') return false;
                const pids = f.projectIds || [];
                if (pids.length) {
                    const set = new Set(pids.map(String));
                    if (!set.has(String(item.project_id))) return false;
                }
                if (f.statusFilter && !itemMatchesHistoryStatusFilter(item, f.statusFilter)) return false;
                if (f.intentionFilter !== '' && !itemMatchesClientIntentionFilter(item, f.intentionFilter)) return false;
                return true;
            });
        });
        const groupedHistoryList = computed(() => groupItems(filteredList.value));

        const getActionText = (status, opts = {}) => {
            if (status == 4 || status == 7) return '成交进度';
            if (status == 3) return '录入成交';
            if (status == 2 && opts.dealWorkbench) return '成交进度';
            const m = ['', '确认到访', '录入下定', '录入成交'];
            return m[status] || '';
        };

        const calculateTimeAgo = (dateString) => {
            const now = new Date();
            const past = new Date(dateString);
            const diffInSeconds = Math.floor((now - past) / 1000);
            
            if (diffInSeconds < 60) {
                return `${diffInSeconds}秒前`;
            } else if (diffInSeconds < 3600) {
                const minutes = Math.floor(diffInSeconds / 60);
                return `${minutes}分钟前`;
            } else if (diffInSeconds < 86400) {
                const hours = Math.floor(diffInSeconds / 3600);
                return `${hours}小时前`;
            } else {
                const days = Math.floor(diffInSeconds / 86400);
                return `${days}天前`;
            }
        };
        
        const checkPendingItems = () => {
            if (!shouldShowPendingReminder) {
                showPendingModal.value = false;
                return;
            }
            if (showPwdModal.value) return;
            // 检查本地存储中的提醒设置
            const lastReminder = localStorage.getItem('lastPendingReminder');
            const reminderType = localStorage.getItem('pendingReminderType');
            
            // 如果有提醒设置，检查是否应该显示
            if (lastReminder && reminderType) {
                const now = new Date().getTime();
                const lastTime = parseInt(lastReminder);
                
                if (reminderType === '1hour' && (now - lastTime) < 3600000) {
                    return; // 1小时内不再提醒
                }
                if (reminderType === 'day' && (now - lastTime) < 86400000) {
                    return; // 当天不再提醒
                }
            }
            
            // 过滤出待处理项目（status == 1）
            const pending = list.value.filter(item => item.status == 1);
            if (pending.length > 0) {
                pendingItems.value = pending;
                showPendingModal.value = true;
            }
        };
        
        const handlePendingModal = (action) => {
            const now = new Date().getTime();
            
            switch (action) {
                case '1hour':
                    localStorage.setItem('lastPendingReminder', now.toString());
                    localStorage.setItem('pendingReminderType', '1hour');
                    break;
                case 'day':
                    localStorage.setItem('lastPendingReminder', now.toString());
                    localStorage.setItem('pendingReminderType', 'day');
                    break;
                default:
                    // 确定按钮，清除提醒设置
                    localStorage.removeItem('lastPendingReminder');
                    localStorage.removeItem('pendingReminderType');
            }
            
            showPendingModal.value = false;
        };
        
        const loadDigest = async () => {
            digestLoading.value = true;
            digestLoadFailed.value = false;
            try {
                const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
                let q = '?action=get_staff_digest' + memberQuery + '&range_type=' + encodeURIComponent(statsRange.value);
                if (statsRange.value === 'custom') {
                    q += '&custom_start=' + encodeURIComponent(statsCustomStart.value || '');
                    q += '&custom_end=' + encodeURIComponent(statsCustomEnd.value || '');
                }
                const res = await fetch(q);
                const data = await res.json();
                if (data.code === 0) {
                    digestData.value = data;
                    const pch = {};
                    (data.channel && data.channel.rows ? data.channel.rows : []).forEach((r, i) => {
                        pch[r.follower_key] = r.follower_key !== '__TOTAL__' && i === 0;
                    });
                    digestExpandCh.value = pch;
                    const ppj = {};
                    (data.projects && data.projects.rows ? data.projects.rows : []).forEach((r, i) => {
                        ppj[r.project_id] = i === 0;
                    });
                    digestExpandProj.value = ppj;
                } else {
                    digestData.value = null;
                    digestLoadFailed.value = true;
                }
            } catch (e) {
                console.error(e);
                digestData.value = null;
                digestLoadFailed.value = true;
            } finally {
                digestLoading.value = false;
            }
        };

        const toggleDigestCh = (fk) => {
            digestExpandCh.value = { ...digestExpandCh.value, [fk]: !digestExpandCh.value[fk] };
        };
        const toggleDigestProj = (pid) => {
            digestExpandProj.value = { ...digestExpandProj.value, [pid]: !digestExpandProj.value[pid] };
        };

        const loadStats = async () => {
            const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
            let statsQuery = memberQuery + '&range_type=' + encodeURIComponent(statsRange.value);
            if (statsRange.value === 'custom') {
                statsQuery += '&custom_start=' + encodeURIComponent(statsCustomStart.value || '');
                statsQuery += '&custom_end=' + encodeURIComponent(statsCustomEnd.value || '');
            }
            const sRes = await fetch('?action=get_stats' + statsQuery);
            stats.value = await sRes.json();
            await loadDigest();
            if (tab.value === 'dash') {
                nextTick(() => drawChart());
            }
        };

        const loadData = async () => {
            const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
            const res = await fetch('?action=get_list' + memberQuery); list.value = await res.json();
            await loadStats();
            const pRes = await fetch('?action=get_projects' + memberQuery); projects.value = await pRes.json();
            // 加载数据后检查待处理项目
            checkPendingItems();
        };

        const changeStatsRange = async (range) => {
            statsRange.value = range;
            if (range !== 'custom') {
                await loadStats();
            }
        };

        const applyStatsCustomRange = async () => {
            if (!statsCustomStart.value || !statsCustomEnd.value) {
                alert('请选择完整的自定义起止日期');
                return;
            }
            await loadStats();
        };

        const searchHistory = async () => {
            const keyword = workSearch.value.trim();
            
            isSearchingHistory.value = true;
            try {
                if(keyword) {
                    const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
                    const res = await fetch('?action=search_history&keyword=' + encodeURIComponent(keyword) + memberQuery);
                    const data = await res.json();
                    list.value = Array.isArray(data) ? data : [];
                    
                    if(list.value.length === 0) {
                        alert('未找到匹配的历史记录');
                    } else {
                        alert(`找到 ${list.value.length} 条历史记录`);
                    }
                } else {
                    // 搜索框为空时，加载全部数据
                    const memberQuery = selectedMemberId.value ? '&member_id=' + encodeURIComponent(selectedMemberId.value) : '';
                    const res = await fetch('?action=get_list' + memberQuery);
                    list.value = await res.json();
                }
            } catch (e) {
                alert('搜索失败，请重试');
            } finally {
                isSearchingHistory.value = false;
            }
        };

        const resetFilters = () => {
            projectFilterKeyword.value = '';
            filters.value = { dateStart: todayStr, dateEnd: todayStr, projectIds: [], statusFilter: '', intentionFilter: '' };
            historySearch.value = '';
            isSearchingHistory.value = false;
            loadData();
        };
        
        const applyFilters = () => {
            // 筛选逻辑已经在computed属性中实现，这里只需要关闭筛选面板
            showFilters.value = false;
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
            filters.value = { ...filters.value, projectIds: [] };
            loadData();
        };

        const DEAL_SUB_FILTERS = ['all', 'lock', 'subscription', 'contract', 'biz_confirm', 'refund'];
        const changeMainTab = (id) => {
            subTab.value = id;
            if (id === 1) childFilter.value = 'reception_all';
            else if(id === 2) childFilter.value = 'visit_all';
            else if (id === 3) {
                if (!DEAL_SUB_FILTERS.includes(childFilter.value)) childFilter.value = 'all';
            }
        };

        watch(showModal, (open) => { if (!open) closeAttachmentPreview(); });
        watch(showTime, (open) => { if (!open) closeAttachmentPreview(); });
        watch(showFollowupPreview, (open) => { if (!open) closeAttachmentPreview(); });
        watch(childFilter, (v) => {
            if (v === 'sign') childFilter.value = 'subscription';
            if (v === 'deposit') childFilter.value = 'all';
        });

        const loadCommissionPackages = async (projectId) => {
            commissionPackagesList.value = [];
            const pid = parseInt(String(projectId ?? ''), 10);
            if (!Number.isFinite(pid) || pid <= 0) {
                return;
            }
            try {
                const r = await fetch(`?action=get_commission_packages&project_id=${pid}`);
                const d = await r.json();
                commissionPackagesList.value = Array.isArray(d) ? d : [];
            } catch (e) {
                commissionPackagesList.value = [];
            }
        };

        const staffCommissionPreview = computed(() => {
            const id = String(commissionPackageId.value || '').trim();
            if (!id) return null;
            const pkg = commissionPackagesList.value.find((p) => String(p.id) === id);
            if (!pkg) return null;
            const raw = String(transactionAmount.value ?? '').trim();
            const amt = parseFloat(raw);
            const pct = parseFloat(pkg.commission_pct);
            const cash = parseFloat(pkg.cash_reward);
            const jumpPct = parseFloat(pkg.jump_ratio);
            const jumpCash = parseFloat(pkg.jump_reward);
            const p = Number.isFinite(pct) ? pct : 0;
            const c = Number.isFinite(cash) ? cash : 0;
            const jp = Number.isFinite(jumpPct) ? jumpPct : 0;
            const jr = Number.isFinite(jumpCash) ? jumpCash : 0;
            const amtStr = Number.isFinite(amt) ? amt.toFixed(2) : '';
            if (!Number.isFinite(amt) || raw === '') {
                return { pending: true, pctStr: String(p), cashStr: c.toFixed(2), jumpPctStr: String(jp), jumpCashStr: jr.toFixed(2) };
            }
            const partBasePct = amt * (p / 100);
            const partJumpPct = amt * (jp / 100);
            const total = Math.round((partBasePct + c + partJumpPct + jr) * 100) / 100;
            const hasJump = jp !== 0 || jr !== 0;
            let formulaLine;
            if (!hasJump) {
                formulaLine = `计算：¥${amtStr} × ${p}% + ¥${c.toFixed(2)} = ¥${total.toFixed(2)}（合计）`;
            } else {
                formulaLine = `计算：¥${amtStr} × ${p}% + ¥${c.toFixed(2)} + ¥${amtStr} × ${jp}% + ¥${jr.toFixed(2)} = (¥${partBasePct.toFixed(2)}+¥${c.toFixed(2)}) + (¥${partJumpPct.toFixed(2)}+¥${jr.toFixed(2)}) = ¥${total.toFixed(2)}（合计）`;
            }
            return {
                pending: false,
                total: total.toFixed(2),
                amtStr,
                pctStr: String(p),
                cashStr: c.toFixed(2),
                jumpPctStr: String(jp),
                jumpCashStr: jr.toFixed(2),
                formulaLine,
            };
        });

        /** 确认到访：勾选有效/无效/重复到访时到访时间默认当前时刻；取消勾选清空 */
        const toggleVisitType = (vt) => {
            if (visitType.value === vt) {
                visitType.value = null;
                visitTime.value = '';
                return;
            }
            visitType.value = vt;
            if ([1, 2, 3].includes(vt)) {
                visitTime.value = currentDatetimeLocalValue();
            } else {
                visitTime.value = '';
            }
        };

        const openModal = async (item) => { 
            closeAttachmentPreview();
            currentItem.value = item; 
            const s = parseInt(item.status);
            currentItem.value.status = s;
            voiceText.value = ''; roomNumber.value = item.room_number || ''; dealPrice.value = item.deal_price || ''; 
            visitType.value = item.visit_type ? parseInt(item.visit_type) : null;
            if (s === 1) {
                visitTime.value = currentDatetimeLocalValue();
            } else {
                visitTime.value = toDatetimeLocalValue(item.visit_time) || currentDatetimeLocalValue();
            }
            clientIntention.value = item.client_intention ? parseInt(item.client_intention) : 0;
            subStages.value = item.sub_stages ? item.sub_stages.split(',') : [];
            const parsedSubTimes = parseSubStageCheckTimesFromLog(item.status_log || '');
            const bundleKeysOk = ['deposit', 'lock', 'sign'].every((k) => subStages.value.includes(k));
            if (bundleKeysOk) {
                const bundleRaw = dealStageTimeFromItem(item, 'sign');
                signSubstageDatetimeLocal.value = bundleRaw ? toDatetimeLocalValue(bundleRaw) : currentDatetimeLocalValue();
            } else if (subStages.value.includes('sign')) {
                const signRaw = dealStageTimeFromItem(item, 'sign');
                signSubstageDatetimeLocal.value = signRaw ? toDatetimeLocalValue(signRaw) : currentDatetimeLocalValue();
            } else {
                signSubstageDatetimeLocal.value = '';
            }
            if (subStages.value.includes('subscription')) {
                const subRaw = dealStageTimeFromItem(item, 'subscription');
                const subTv = subRaw ? toDatetimeLocalValue(subRaw) : '';
                subscriptionAtDatetimeLocal.value = subTv;
                if (!subTv) subscriptionDatetimeInputKey.value += 1;
            } else {
                subscriptionAtDatetimeLocal.value = '';
                subscriptionDatetimeInputKey.value += 1;
            }
            subscriptionAmount.value = item.subscription_amount != null && item.subscription_amount !== '' ? String(item.subscription_amount) : '';
            if (subStages.value.includes('contract')) {
                const conRaw = dealStageTimeFromItem(item, 'contract');
                const conTv = conRaw ? toDatetimeLocalValue(conRaw) : '';
                contractSignedAtDatetimeLocal.value = conTv;
                if (!conTv) contractSignedDatetimeInputKey.value += 1;
            } else {
                contractSignedAtDatetimeLocal.value = '';
                contractSignedDatetimeInputKey.value += 1;
            }
            contractTotalPrice.value = item.contract_total_price != null && item.contract_total_price !== '' ? String(item.contract_total_price) : '';
            transactionAmount.value = item.transaction_amount != null && item.transaction_amount !== '' ? String(item.transaction_amount) : '';
            if (subStages.value.includes('biz_confirm')) {
                const bizRaw = dealStageTimeFromItem(item, 'biz_confirm');
                bizConfirmAtDatetimeLocal.value = bizRaw ? toDatetimeLocalValue(bizRaw) : currentDatetimeLocalValue();
            } else {
                bizConfirmAtDatetimeLocal.value = '';
            }
            if (subStages.value.includes('refund_submit')) {
                const refRaw = dealStageTimeFromItem(item, 'refund_submit');
                refundSubmittedAtDatetimeLocal.value = refRaw ? toDatetimeLocalValue(refRaw) : currentDatetimeLocalValue();
            } else {
                refundSubmittedAtDatetimeLocal.value = '';
                refundDatetimeInputKey.value += 1;
            }
            const rawDigits = String(item.client_phone || '').replace(/\D/g, '');
            rawClientPhone.value = rawDigits;
            clientPhone.value = rawDigits.length >= 7 ? maskClientDigitsForDisplay(rawDigits) : (item.client_phone || '');
            subscriberName.value = item.subscriber_name || '';
            subscribedRoomNumber.value = item.subscribed_room_number || item.room_number || '';
            transactionArea.value = item.transaction_area || '';
            salesperson.value = item.salesperson || '';
            subscriptionPhoneFull.value = item.subscription_phone_full || '';
            subscriptionDate.value = item.subscription_date || new Date().toISOString().split('T')[0];
            transactionRecorder.value = item.transaction_recorder || '<?= $CURRENT_USER['name'] ?>';
            if (item.attachments) attachmentList.value = item.attachments.split(',').filter(x => x); else attachmentList.value = [];
            if (item.biz_confirm_attachments) bizConfirmAttachmentList.value = item.biz_confirm_attachments.split(',').filter(x => x); else bizConfirmAttachmentList.value = [];
            showBizConfirmUploadModal.value = false;
            await loadCommissionPackages(item.project_id);
            const cp = item.commission_package_id;
            commissionPackageId.value = cp != null && cp !== '' && String(cp) !== '0' ? String(cp) : '';
            showModal.value = true; 
        };
        const showTimeline = (item) => { closeAttachmentPreview(); currentItem.value = item; showTime.value = true; };
        const buildFollowupPreviewSlots = (item) => {
            const map = {};
            if (item && Array.isArray(item.followups)) {
                for (const f of item.followups) {
                    const c = parseInt(String(f.followup_count ?? ''), 10);
                    if (c >= 1 && c <= 3) {
                        map[c] = f;
                    }
                }
            }
            return [1, 2, 3].map((n) => ({ n, rec: map[n] || null }));
        };
        const itemHasFollowup123Preview = (item) => buildFollowupPreviewSlots(item).some((s) => s.rec);
        const followupPreviewText = (rec) => {
            let t = String(rec.content || '').trim();
            const sum = String(rec.summary || '').trim();
            if (sum) {
                t = '[' + sum + '] ' + t;
            }
            if (!t) {
                return '（无文字内容）';
            }
            if (t.length > 400) {
                return t.slice(0, 400) + '…';
            }
            return t;
        };
        const openFollowupPreview = (item) => {
            closeAttachmentPreview();
            followupPreviewItem.value = item || null;
            showFollowupPreview.value = true;
        };
        const closeFollowupPreview = () => {
            showFollowupPreview.value = false;
            followupPreviewItem.value = null;
        };
        const followupPreviewSlotsList = computed(() => buildFollowupPreviewSlots(followupPreviewItem.value));
        const followupPreviewAllEmpty123 = computed(() => !followupPreviewSlotsList.value.some((s) => s.rec));
        const toggleStage = (stage) => {
            if (stage === 'biz_confirm') {
                if (subStages.value.includes('biz_confirm')) {
                    subStages.value = subStages.value.filter((s) => s !== 'biz_confirm');
                    bizConfirmAtDatetimeLocal.value = '';
                    bizConfirmAttachmentList.value = [];
                    showBizConfirmUploadModal.value = false;
                } else {
                    bizConfirmAtDatetimeLocal.value = currentDatetimeLocalValue();
                    subStages.value.push('biz_confirm');
                    showBizConfirmUploadModal.value = true;
                }
                return;
            }
            if (stage === 'refund_submit') {
                if (subStages.value.includes('refund_submit')) {
                    subStages.value = subStages.value.filter((s) => s !== 'refund_submit');
                    refundSubmittedAtDatetimeLocal.value = '';
                    refundDatetimeInputKey.value += 1;
                    nextTick(() => {
                        refundSubmittedAtDatetimeLocal.value = '';
                    });
                } else {
                    subStages.value.push('refund_submit');
                    refundDatetimeInputKey.value += 1;
                    nextTick(() => {
                        refundSubmittedAtDatetimeLocal.value = currentDatetimeLocalValue();
                    });
                }
                return;
            }
            if (stage === 'sign') {
                if (subStages.value.includes('sign')) {
                    subStages.value = subStages.value.filter((s) => s !== 'sign');
                    signSubstageDatetimeLocal.value = '';
                } else {
                    signSubstageDatetimeLocal.value = currentDatetimeLocalValue();
                    subStages.value.push('sign');
                }
                return;
            }
            if (stage === 'subscription') {
                if (subStages.value.includes('subscription')) {
                    subStages.value = subStages.value.filter((s) => s !== 'subscription');
                    subscriptionAtDatetimeLocal.value = '';
                    subscriptionDatetimeInputKey.value += 1;
                    nextTick(() => {
                        subscriptionAtDatetimeLocal.value = '';
                    });
                } else {
                    subStages.value.push('subscription');
                    subscriptionDatetimeInputKey.value += 1;
                    nextTick(() => {
                        subscriptionAtDatetimeLocal.value = currentDatetimeLocalValue();
                    });
                }
                return;
            }
            if (stage === 'contract') {
                if (subStages.value.includes('contract')) {
                    subStages.value = subStages.value.filter((s) => s !== 'contract');
                    contractSignedAtDatetimeLocal.value = '';
                    commissionPackageId.value = '';
                    contractSignedDatetimeInputKey.value += 1;
                    nextTick(() => {
                        contractSignedAtDatetimeLocal.value = '';
                    });
                } else {
                    subStages.value.push('contract');
                    contractSignedDatetimeInputKey.value += 1;
                    nextTick(() => {
                        contractSignedAtDatetimeLocal.value = currentDatetimeLocalValue();
                    });
                }
                return;
            }
            if (subStages.value.includes(stage)) {
                subStages.value = subStages.value.filter((s) => s !== stage);
            } else {
                subStages.value.push(stage);
            }
        };
        const preLockSignBundleKeys = ['deposit', 'lock', 'sign'];
        const preLockSignBundleChecked = computed(() => preLockSignBundleKeys.every((k) => subStages.value.includes(k)));
        const togglePreLockSignBundle = () => {
            if (preLockSignBundleChecked.value) {
                subStages.value = subStages.value.filter((s) => !preLockSignBundleKeys.includes(s));
                signSubstageDatetimeLocal.value = '';
            } else {
                signSubstageDatetimeLocal.value = currentDatetimeLocalValue();
                const set = new Set(subStages.value);
                preLockSignBundleKeys.forEach((k) => set.add(k));
                subStages.value = Array.from(set);
            }
        };
        const markAsInvalid = (item) => {
            if (confirm('确定标记为无效报备吗？')) {
                fetch('?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        id: item.id,
                        curr_status: 1,
                        visit_type: 4,
                        save_type: 'submit'
                    })
                }).then(r => r.json()).then(d => {
                    if (d.status !== 'error') {
                        alert('标记成功');
                        loadData();
                    } else {
                        alert('标记失败: ' + (d.msg || '未知错误'));
                    }
                }).catch(error => {
                    console.error('标记无效报备时出错:', error);
                    alert('标记失败: 网络错误');
                });
            }
        };

        const markAsInvalidVisit = (item) => {
            if (confirm('确定标记为无效到访吗？')) {
                fetch('?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: new URLSearchParams({
                        id: item.id,
                        curr_status: 2,
                        visit_type: 2,
                        save_type: 'submit'
                    })
                }).then(r => r.json()).then(d => {
                    if (d.status !== 'error') {
                        alert('标记成功');
                        loadData();
                    } else {
                        alert('标记失败: ' + (d.msg || '未知错误'));
                    }
                }).catch(error => {
                    console.error('标记无效到访时出错:', error);
                    alert('标记失败: 网络错误');
                });
            }
        };
        
        const handleFile = async (e) => { 
            for (let i = 0; i < e.target.files.length; i++) {
                const fd=new FormData(); fd.append('file', e.target.files[i]); 
                const res=await fetch('upload.php',{method:'POST',body:fd}); const d=await res.json(); 
                if(d.status=='success') attachmentList.value.push(d.url); 
            }
        };
        const removeAttachment = (idx) => attachmentList.value.splice(idx, 1);
        const handleBizConfirmFile = async (e) => {
            const files = e.target.files;
            if (!files || !files.length) return;
            for (let i = 0; i < files.length; i++) {
                const f = files[i];
                if (!f.type || !f.type.startsWith('image/')) {
                    alert('业确附件仅支持图片格式');
                    e.target.value = '';
                    return;
                }
                const fd = new FormData();
                fd.append('file', f);
                const res = await fetch('upload.php', { method: 'POST', body: fd });
                const d = await res.json();
                if (d.status === 'success') {
                    bizConfirmAttachmentList.value.push(d.url);
                } else {
                    alert(d.msg || '上传失败');
                }
            }
            e.target.value = '';
        };
        const removeBizConfirmAttachment = (idx) => bizConfirmAttachmentList.value.splice(idx, 1);

        const submitAction = async (saveType = 'submit') => {
            const s = parseInt(currentItem.value.status);
            let nextStatus = s + 1;
            if (s === 4 || s === 7) nextStatus = s;
            if (s === 2 || s === 4 || s === 7) {
                if (subStages.value.includes('sign') && !normalizeClientPhoneForSave(clientPhone.value, rawClientPhone.value)) return alert('请填写客户号码');
                if (subStages.value.includes('sign') && !datetimeLocalToSql(signSubstageDatetimeLocal.value)) {
                    return alert('请选择锁房 签约认购书时间（精确到分钟）');
                }
                if (subStages.value.includes('biz_confirm') && !datetimeLocalToSql(bizConfirmAtDatetimeLocal.value)) {
                    return alert('请选择业确时间（精确到分钟）');
                }
                if (subStages.value.includes('refund_submit') && !datetimeLocalToSql(refundSubmittedAtDatetimeLocal.value)) {
                    return alert('请选择退房时间（精确到分钟）');
                }
            }
            if (s === 2) {
                const coreOk = ['deposit', 'lock', 'sign'].every((k) => subStages.value.includes(k));
                if (saveType === 'submit' && !coreOk) return alert('须勾选「锁房 签约认购书」后才能确认去签约');
                nextStatus = 3;
            }
            if ((s === 4 || s === 7) && saveType !== 'save') {
                return;
            }
            if (s === 3 && saveType === 'save') {
                nextStatus = 3;
            }
            if (s === 1 && [1, 2, 3].includes(parseInt(visitType.value, 10)) && !visitTime.value) {
                return alert('请选择到访时间');
            }
            if (s === 3) {
                if (saveType === 'submit') {
                    if (!dealPrice.value) return alert('请填写认购总价（可在上方「认购总价」或成交总价字段填写）');
                    if (attachmentList.value.length === 0) return alert('请上传购房合同（上传单据区域）');
                    nextStatus = 4;
                } else if (saveType === 'refund') {
                    if (!confirm('确定要退房吗？')) return;
                    nextStatus = 7;
                }
            }
            
            const fd = new FormData(); 
            fd.append('id', currentItem.value.id); fd.append('curr_status', s); fd.append('status', nextStatus); fd.append('save_type', saveType); 
            if(visitType.value !== null) {
                fd.append('visit_type', visitType.value); 
            } else {
                fd.append('visit_type', 'null');
            }
            fd.append('client_intention', clientIntention.value); fd.append('sub_stages', subStages.value.join(',')); fd.append('voice', voiceText.value); 
            fd.append('visit_time', visitTime.value);
            fd.append('room_number', roomNumber.value); fd.append('deal_price', dealPrice.value); fd.append('attachment', attachmentList.value.join(','));
            fd.append('biz_confirm_attachment', bizConfirmAttachmentList.value.join(','));
            fd.append('client_phone', normalizeClientPhoneForSave(clientPhone.value, rawClientPhone.value));
            fd.append('subscriber_name', subscriberName.value);
            fd.append('subscribed_room_number', subscribedRoomNumber.value);
            fd.append('transaction_area', transactionArea.value);
            fd.append('salesperson', salesperson.value);
            fd.append('subscription_phone_full', subscriptionPhoneFull.value);
            fd.append('subscription_date', subscriptionDate.value);
            fd.append('transaction_recorder', transactionRecorder.value);
            const signSqlTs = datetimeLocalToSql(signSubstageDatetimeLocal.value);
            if (subStages.value.includes('sign') && signSqlTs) {
                fd.append('substage_at_sign', signSqlTs);
                if (['deposit', 'lock', 'sign'].every((k) => subStages.value.includes(k))) {
                    fd.append('pre_lock_sign_bundle_at', signSqlTs);
                }
            }
            const subAtSql = datetimeLocalToSql(subscriptionAtDatetimeLocal.value);
            fd.append('subscription_amount', subscriptionAmount.value);
            fd.append('subscription_at', subAtSql);
            fd.append('contract_total_price', contractTotalPrice.value);
            const conSqlTs = datetimeLocalToSql(contractSignedAtDatetimeLocal.value);
            fd.append('contract_signed_at', conSqlTs);
            fd.append('transaction_amount', transactionAmount.value);
            fd.append('commission_package_id', commissionPackageId.value || '');
            if (subStages.value.includes('subscription') && subAtSql) {
                fd.append('substage_at_subscription', subAtSql);
            }
            if (subStages.value.includes('contract') && conSqlTs) {
                fd.append('substage_at_contract', conSqlTs);
            }
            const bizSqlTs = datetimeLocalToSql(bizConfirmAtDatetimeLocal.value);
            fd.append('biz_confirm_at', bizSqlTs);
            const refSqlTs = datetimeLocalToSql(refundSubmittedAtDatetimeLocal.value);
            fd.append('refund_submitted_at', refSqlTs);
            if (subStages.value.includes('biz_confirm') && bizSqlTs) {
                fd.append('substage_at_biz_confirm', bizSqlTs);
            }
            if (subStages.value.includes('refund_submit') && refSqlTs) {
                fd.append('substage_at_refund_submit', refSqlTs);
            }
            
            const res = await fetch('?action=update', {method:'POST', body:fd}); const d = await res.json();
            if(d.status === 'success') { showModal.value = false; showBizConfirmUploadModal.value = false; loadData(); } else { alert(d.msg || '操作失败'); }
        };

        const toggleRecord = () => { recording.value = !recording.value; if(!recording.value) voiceText.value="客户反馈良好。"; };
        const simulateScan = () => { workSearch.value=''; document.querySelector('input[placeholder*="输入手机"]').focus(); };
        const goToFiling = () => {
            window.location.href = 'agent.php';
        };
        const goToCompete = () => { window.location.href = 'compete.php'; };
        const parseLog = (logStr) => {
            if(!logStr) return [];
            const marker = ' [凭证] ';
            return logStr.split('\n')
                .filter(l => l.trim())
                .map(l => {
                    const parts = l.split('] ');
                    if (parts.length < 2) return { time: '', title: l, desc: '', attachmentUrls: [] };
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
                    // 正文里会出现「[凭证] https://…」等，`] ` 会在「证]」后再次 split，仅用 parts[1] 会丢掉后面整段 URL
                    let contentPart = parts.slice(1).join('] ') || '';
                    const attachmentUrls = [];
                    const mi = contentPart.indexOf(marker);
                    if (mi !== -1) {
                        let rest = contentPart.slice(mi + marker.length);
                        let cut = rest.length;
                        const idxNote = rest.indexOf(' (备注:');
                        if (idxNote !== -1) cut = Math.min(cut, idxNote);
                        const urlChunk = rest.slice(0, cut).trim();
                        if (urlChunk) {
                            urlChunk.split('|').forEach((s) => {
                                const t = s.trim();
                                if (t) attachmentUrls.push(t);
                            });
                        }
                        contentPart = contentPart.slice(0, mi) + rest.slice(cut);
                    }
                    const markerBiz = ' [业确附件] ';
                    const miB = contentPart.indexOf(markerBiz);
                    if (miB !== -1) {
                        let restB = contentPart.slice(miB + markerBiz.length);
                        let cutB = restB.length;
                        const idxNoteB = restB.indexOf(' (备注:');
                        if (idxNoteB !== -1) cutB = Math.min(cutB, idxNoteB);
                        const idxCredB = restB.indexOf(' [凭证]');
                        if (idxCredB !== -1) cutB = Math.min(cutB, idxCredB);
                        const urlChunkB = restB.slice(0, cutB).trim();
                        if (urlChunkB) {
                            urlChunkB.split('|').forEach((s) => {
                                const t = s.trim();
                                if (t) attachmentUrls.push(t);
                            });
                        }
                        contentPart = contentPart.slice(0, miB) + restB.slice(cutB);
                    }
                    let title = contentPart;
                    let desc = '';
                    // Handle follow-up format: "第1次跟进: [在路上] 中介带上客户了，已经在路上"
                    if (contentPart.includes('次跟进: ')) {
                        const followupParts = contentPart.split('次跟进: ');
                        if (followupParts.length > 1) {
                            title = followupParts[0] + '次跟进';
                            desc = followupParts[1];
                        }
                    }
                    // Handle regular format with parentheses
                    else if (contentPart.includes('(')) {
                        title = contentPart.split(' (')[0];
                        desc = contentPart.split('(')[1].replace(')', '');
                    }
                    return { time: timePart, title: title, desc: desc, attachmentUrls };
                })
                .reverse();
        };
        
        // 合并状态日志和跟进记录为统一时间线
        const combinedTimeline = computed(() => {
            if (!currentItem.value) return [];
            
            // 解析状态日志
            const statusLogs = parseLog(currentItem.value.status_log || '');
            
            // 转换跟进记录为相同格式
            const followups = (currentItem.value.followups || []).map(followup => {
                let desc = followup.content;
                if (followup.summary) {
                    desc = `[${followup.summary}] ${followup.content}`;
                }
                return {
                    time: followup.created_at,
                    title: `第${followup.followup_count}次跟进`,
                    desc: desc,
                    attachmentUrls: []
                };
            });
            
            // 合并并按时间排序
            const allEvents = [...statusLogs, ...followups];
            allEvents.sort((a, b) => new Date(a.time) - new Date(b.time));

            // 去重：按 时间+标题+内容 保留一条
            const uniqMap = new Map();
            allEvents.forEach((e) => {
                const key = `${String(e.time || '').trim()}|${String(e.title || '').trim()}|${String(e.desc || '').trim()}`;
                if (!uniqMap.has(key)) uniqMap.set(key, e);
            });
            return Array.from(uniqMap.values());
        });
        const historyRemarks = computed(() => {
            if (!currentItem.value) return [];
            const logs = parseLog(currentItem.value.status_log || '');
            return logs
                .map((e) => {
                    const raw = `${e.title || ''} ${e.desc || ''}`;
                    const m = raw.match(/备注[:：]\s*([^)]*)/);
                    if (!m || !m[1]) return null;
                    return { time: e.time || '', text: m[1].trim() };
                })
                .filter(Boolean)
                .reverse();
        });

        const orderFlowAttachmentUrls = computed(() => {
            const item = currentItem.value;
            if (!item) return [];
            const a = (item.attachments && typeof item.attachments === 'string') ? item.attachments.split(',').map((s) => s.trim()).filter(Boolean) : [];
            const b = (item.biz_confirm_attachments && typeof item.biz_confirm_attachments === 'string') ? item.biz_confirm_attachments.split(',').map((s) => s.trim()).filter(Boolean) : [];
            const seen = new Set();
            const out = [];
            [...a, ...b].forEach((u) => {
                if (u && !seen.has(u)) {
                    seen.add(u);
                    out.push(u);
                }
            });
            return out;
        });
        const timelineHasInlineAttachments = computed(() =>
            combinedTimeline.value.some((e) => e.attachmentUrls && e.attachmentUrls.length)
        );
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

        const statusText = (s) => ['待审','有效','到访','下定','成交','无效','报备无效','退房'][s]||'';
        const statusClass = (s) => ['bg-gray-100 text-gray-500 border-gray-200','bg-blue-50 text-blue-600 border-blue-200','bg-yellow-50 text-yellow-600 border-yellow-200','bg-orange-50 text-orange-600 border-orange-200','bg-green-50 text-green-600 border-green-200','bg-red-50 text-red-500 border-red-200','bg-gray-200 text-gray-600 border-gray-300','bg-purple-50 text-purple-600 border-purple-200'][s];
        const commText = (s, item) => {
            const hasHist = item && item.finance_reject_history && item.finance_reject_history.length > 0;
            if (item && String(item.commission_status) === '0' && (hasHist || item.performance_finance_rejected_at)) {
                return '驳回中';
            }
            return ['待确认', '已确认', '已发放'][s] || '未知';
        };
        const commColor = (s, item) => {
            const hasHist = item && item.finance_reject_history && item.finance_reject_history.length > 0;
            if (item && String(item.commission_status) === '0' && (hasHist || item.performance_finance_rejected_at)) {
                return 'text-red-600 font-bold';
            }
            return ['text-gray-400', 'text-blue-500', 'text-green-500'][s] || '';
        };
        const drawChart = () => {
            nextTick(() => {
                const d = document.getElementById('trendChart');
                if (!d || !stats.value.chart_data) return;
                const myChart = echarts.init(d);
                myChart.setOption({
                    tooltip: { trigger: 'axis', axisPointer: { type: 'shadow' } },
                    legend: { data: ['报备', '到访', '成交'], textStyle: { fontSize: 10 }, bottom: 0 },
                    grid: { left: '3%', right: '4%', bottom: '16%', top: '15%', containLabel: true },
                    xAxis: { type: 'category', data: stats.value.chart_labels || [], axisLabel: { fontSize: 10 } },
                    yAxis: { type: 'value', axisLabel: { fontSize: 10 } },
                    series: [
                        { name: '报备', type: 'bar', itemStyle: { color: '#3b82f6' }, data: (stats.value.chart_data && stats.value.chart_data.report) ? stats.value.chart_data.report : [] },
                        { name: '到访', type: 'bar', itemStyle: { color: '#22c55e' }, data: (stats.value.chart_data && stats.value.chart_data.visit) ? stats.value.chart_data.visit : [] },
                        { name: '成交', type: 'bar', itemStyle: { color: '#f97316' }, data: (stats.value.chart_data && stats.value.chart_data.deal) ? stats.value.chart_data.deal : [] }
                    ]
                });
            });
        };
        const copyFilingData = async (item) => {
            const text = (item && item.raw_input_text ? item.raw_input_text : '').trim();
            if (!text) {
                alert('该记录没有 raw_input_text 可复制');
                return;
            }
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
        
        // 格式化电话号码显示
        const formatPhone = (phone, isAgent = false) => {
            if(!phone) return '';
            
            // 经纪人电话不需要隐藏，直接显示并可点击拨打
            if(isAgent) {
                const cleanPhone = phone.replace(/\D/g, '');
                if(cleanPhone.length >= 11) {
                    return `<a href="tel:${cleanPhone}" class="text-blue-500">${phone}</a>`;
                } else {
                    return `<a href="tel:${cleanPhone}" class="text-blue-500">${phone}</a>`;
                }
            }
            
            // 客户电话：前三 + **** + 后四；链接仍拨完整号码
            const raw = String(phone).trim();
            if (raw.includes('*')) {
                const m = raw.match(/^(\d{3})\*+(\d{4})$/);
                const display = m ? `${m[1]}****${m[2]}` : raw;
                const digits = raw.replace(/\D/g, '');
                if (digits.length >= 7) {
                    const tel = digits;
                    return `<a href="tel:${tel}" class="text-blue-500">${display}</a>`;
                }
                return display;
            }
            const cleanPhone = phone.replace(/\D/g, '');
            if(cleanPhone.length >= 7) {
                const masked = cleanPhone.substring(0, 3) + '****' + cleanPhone.substring(cleanPhone.length - 4);
                return `<a href="tel:${cleanPhone}" class="text-blue-500">${masked}</a>`;
            }
            return phone;
        };

        // 文本截断（按字符计数，超出后追加 ...）
        const truncateText = (text, maxLen = 15) => {
            const chars = Array.from(String(text || ''));
            if (chars.length <= maxLen) return String(text || '');
            return chars.slice(0, maxLen).join('') + '...';
        };
        const showFullText = (text) => {
            const v = String(text || '').trim();
            if (!v) return;
            alert(v);
        };

        // 备注计数：与“历史备注”口径保持一致，仅统计状态日志中的备注条数
        const getRemarkCount = (item) => {
            if (!item) return 0;
            const log = String(item.status_log || '');
            if (!log) return 0;
            // 一行日志只算一条备注，避免同一行里多个“备注:”被重复计数
            return log
                .split('\n')
                .filter((line) => /备注[:：]/.test(line))
                .length;
        };
        const extractRemarkNotes = (item) => {
            const log = String(item && item.status_log ? item.status_log : '');
            if (!log) return [];
            return log
                .split('\n')
                .map((line) => String(line || '').trim())
                .filter((line) => /备注[:：]/.test(line))
                .map((line) => {
                    const timeMatch = line.match(/^\[([^\]]+)\]/);
                    const time = timeMatch ? timeMatch[1] : '';
                    const text = line.replace(/^.*备注[:：]\s*/u, '').trim();
                    return { time, text: text || line };
                });
        };
        const showRemarkNotes = (item) => {
            const notes = extractRemarkNotes(item);
            if (!notes.length) {
                alert('暂无备注信息');
                return;
            }
            const content = notes
                .map((n, idx) => `${idx + 1}. ${n.time ? `[${n.time}] ` : ''}${n.text}`)
                .join('\n');
            alert(content);
        };

        watch(tab, (v) => { 
            if(v==='dash') loadData(); 
            else if(v==='work' && !isSearchingHistory.value) loadData();
        });
        watch(subTab, (v) => {
            if (!shouldShowPendingReminder) return;
            if(v===2 && pendingCount.value > 0) {
                alert(`您有${pendingCount.value}条待办事项需要处理！`);
                const audioContext = new (window.AudioContext || window.webkitAudioContext)();
                const oscillator = audioContext.createOscillator();
                const gainNode = audioContext.createGain();
                oscillator.connect(gainNode);
                gainNode.connect(audioContext.destination);
                oscillator.frequency.value = 800;
                oscillator.type = 'sine';
                gainNode.gain.value = 0.1;
                oscillator.start();
                setTimeout(() => {
                    oscillator.stop();
                    audioContext.close();
                }, 200);
            }
        });
        onMounted(() => {
            childFilter.value = 'reception_all';
            loadTeamMembers();
            loadData();
        });

        return {
            tab, subTab, childFilter, list, groupedWorkList, filteredList, groupedHistoryList, showModal, showTime, showFollowupPreview, followupPreviewItem, showPendingModal, pendingItems, pendingCount, currentItem, 
            voiceText, roomNumber, dealPrice, visitTime, workSearch, historySearch, stats, currentDate, clientPhone, rawClientPhone, onClientPhoneBlur,
            subscriberName, subscribedRoomNumber, transactionArea, salesperson, subscriptionPhoneFull, subscriptionDate, transactionRecorder,
            subscriptionAmount, subscriptionAtDatetimeLocal, contractTotalPrice, contractSignedAtDatetimeLocal, transactionAmount,
            commissionPackagesList, commissionPackageId, staffCommissionPreview,
            bizConfirmAtDatetimeLocal, refundSubmittedAtDatetimeLocal, refundDatetimeInputKey, subscriptionDatetimeInputKey, contractSignedDatetimeInputKey,
            showFilters, filters, projects, visitType, clientIntention, subStages, signSubstageDatetimeLocal, attachmentList, bizConfirmAttachmentList, showBizConfirmUploadModal, previewAttachmentUrl, recording, isSearchingHistory,
            projectFilterKeyword, filteredProjectsForFilter, isProjectFilterChecked, toggleProjectFilter, clearProjectFilter,
            historyStatusFilterOptions, clientIntentionFilterOptions,
            teamMembers, selectedMemberId, teamFilterEnabled, onMemberChange,
            digestTab, digestData, digestLoading, digestLoadFailed, digestExpandCh, digestExpandProj,
            digestEffKeys, digestMetricLabels, digestTriple, toggleDigestCh, toggleDigestProj,
            statsRange, statsCustomStart, statsCustomEnd, changeStatsRange, applyStatsCustomRange,
            openModal, toggleVisitType, showTimeline, openFollowupPreview, closeFollowupPreview, buildFollowupPreviewSlots, itemHasFollowup123Preview, followupPreviewSlotsList, followupPreviewAllEmpty123, followupPreviewText, submitAction, handleFile, handleBizConfirmFile, simulateScan, goToFiling, goToCompete, parseLog, commText, commColor, toggleRecord, removeAttachment, removeBizConfirmAttachment, openAttachmentPreview, closeAttachmentPreview, copyFilingData, searchHistory, markAsInvalid, markAsInvalidVisit,
            tabs, counts, receptionChildCounts, depositChildCounts, getActionText, clientIntentionLabel, statusText, statusClass, resetFilters, applyFilters, toggleStage, togglePreLockSignBundle, preLockSignBundleChecked, changeMainTab,
            visitChildCounts,
            calculateTimeAgo, checkPendingItems, handlePendingModal, formatPhone, formatDateTime, truncateText, showFullText, getRemarkCount, showRemarkNotes, combinedTimeline, orderFlowAttachmentUrls, timelineHasInlineAttachments, historyRemarks,
            workbenchDealDateFilterHint, workbenchDealStageTimeText, workbenchDealStageCardTitle,
            workbenchDealFiveStageMax, workbenchDealFiveStageLabel,
            showPwdModal, pwdOld, pwdNew, pwdConfirm, pwdSubmitting, openPwdModal, submitPwdChange,
            isStrictProjectSite
        }
    }
}).mount('#app');
</script>
</body>
</html>

