<?php
// api.php - 统一后端接口 (v3.0: 全功能完整版)
header('Content-Type: application/json; charset=utf-8');
session_start();

// === 1. 数据库配置 ===
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { die(json_encode(['status'=>'error','msg'=>'DB Error: '.$e->getMessage()])); }

require_once __DIR__ . '/project_image_url.php';
require_once __DIR__ . '/includes/agent_roles.php';

$action = $_GET['action'] ?? '';

function ensure_agent_employment_schema(PDO $pdo): void {
    static $ensured = false;
    if ($ensured) return;
    $ensured = true;

    // agents 在职状态字段
    try { $pdo->exec("ALTER TABLE agents ADD COLUMN employment_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '在职状态:1在职,0离职'"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE agents ADD COLUMN left_at DATETIME NULL COMMENT '离职时间'"); } catch (Exception $e) {}
    agent_roles_ensure_column($pdo);
    try {
        $pdo->exec("UPDATE agents SET roles_json = JSON_ARRAY(role) WHERE (roles_json IS NULL OR TRIM(roles_json) = '' OR roles_json = 'null') AND role IS NOT NULL AND TRIM(role) <> ''");
    } catch (Exception $e) {}

    // 永久保留的人员状态变更日志
    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_status_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        agent_id INT NOT NULL,
        from_status TINYINT(1) NULL,
        to_status TINYINT(1) NOT NULL,
        changed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        changed_by_id INT NULL,
        changed_by_name VARCHAR(100) NULL,
        remark VARCHAR(255) NULL,
        INDEX idx_agent_id (agent_id),
        INDEX idx_changed_at (changed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS agent_projects (
        agent_id INT NOT NULL,
        project_id INT NOT NULL,
        PRIMARY KEY (agent_id, project_id),
        INDEX idx_project_id (project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// ======================= 1. 仪表盘 (Dashboard) =======================
if ($action == 'get_dashboard_stats') {
    $total_filing = $pdo->query("SELECT count(*) FROM filings")->fetchColumn();
    $total_deal   = $pdo->query("SELECT count(*) FROM filings WHERE status = 4")->fetchColumn();
    $total_gmv    = $pdo->query("SELECT SUM(deal_price) FROM filings WHERE status = 4")->fetchColumn() ?: 0;
    $total_comm   = $pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status = 4")->fetchColumn() ?: 0;
    
    // 模拟趋势数据
    $months = ['8月', '9月', '10月', '11月', '12月', '1月'];
    $comm_trend = ['pending' => [12, 13, 10, 13, 9, 23], 'paid' => [8, 9, 11, 12, 14, 8]]; 
    $funnel = [
        ['name'=>'报备', 'value'=>$total_filing ?: 100], ['name'=>'有效', 'value'=>$pdo->query("SELECT count(*) FROM filings WHERE status >= 1")->fetchColumn() ?: 80],
        ['name'=>'到访', 'value'=>$pdo->query("SELECT count(*) FROM filings WHERE status >= 2")->fetchColumn() ?: 40], ['name'=>'认筹', 'value'=>$pdo->query("SELECT count(*) FROM filings WHERE status >= 3")->fetchColumn() ?: 20],
        ['name'=>'成交', 'value'=>$total_deal ?: 10]
    ];
    $recent = $pdo->query("SELECT f.client_name, p.name as project, f.deal_price, f.created_at FROM filings f LEFT JOIN projects p ON f.project_id=p.id WHERE f.status=4 ORDER BY f.id DESC LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode(['cards'=>['filing'=>$total_filing, 'deal'=>$total_deal, 'gmv'=>$total_gmv, 'comm'=>$total_comm], 'chart_comm'=>['months'=>$months, 'data'=>$comm_trend], 'funnel'=>$funnel, 'recent'=>$recent]); exit;
}

// ======================= 2. 商户管理 (Companies) =======================

// [查询] 支持分页与多条件筛选
if ($action == 'get_companies') {
    $kw = $_GET['kw'] ?? '';           
    $plate = $_GET['plate'] ?? '';     
    $area = $_GET['area'] ?? '';       
    $follower = $_GET['follower'] ?? ''; 
    $franchise_brand = $_GET['franchise_brand'] ?? ''; 
    
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    $params = [];

    if ($kw) {
        $where .= " AND (name LIKE ? OR store_name LIKE ? OR contact_name LIKE ? OR contact_phone LIKE ?)";
        $params[] = "%$kw%"; $params[] = "%$kw%"; $params[] = "%$kw%"; $params[] = "%$kw%";
    }
    if ($plate) { $where .= " AND region_main LIKE ?"; $params[] = "%$plate%"; }
    if ($area) { $where .= " AND region_sub LIKE ?"; $params[] = "%$area%"; }
    if ($follower) { $where .= " AND follower LIKE ?"; $params[] = "%$follower%"; }
    if ($franchise_brand) { $where .= " AND franchise_brand = ?"; $params[] = $franchise_brand; }

    $countStmt = $pdo->prepare("SELECT count(*) FROM companies $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT * FROM companies $where ORDER BY id DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    
    echo json_encode(['code' => 0, 'count' => $total, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
}



// [筛选项] 获取加盟品牌列表
if ($action == 'get_franchise_brands') {
    header('Content-Type: application/json');
    $rows = $pdo->query("SELECT DISTINCT franchise_brand FROM companies WHERE franchise_brand IS NOT NULL AND TRIM(franchise_brand) != '' ORDER BY franchise_brand ASC")->fetchAll(PDO::FETCH_COLUMN);
    echo json_encode($rows);
    exit;
}

// [详情] 获取单个商户
if ($action == 'get_company_detail') {
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ?");
    $stmt->execute([$_GET['id']]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
}

// [保存] 新增或编辑 (补全)
if ($action == 'save_company') {
    $d = $_POST;
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') {
        echo json_encode(['status' => 'error', 'msg' => '请填写公司全称']);
        exit;
    }
    $regionMain = trim((string)($d['region_main'] ?? ''));
    $regionSub = trim((string)($d['region_sub'] ?? ''));
    $storeName = trim((string)($d['store_name'] ?? ''));
    $storeType = trim((string)($d['store_type'] ?? ''));
    $businessStatus = trim((string)($d['business_status'] ?? ''));
    $relatedStore = trim((string)($d['related_store'] ?? ''));
    $franchiseBrand = trim((string)($d['franchise_brand'] ?? ''));
    $address = trim((string)($d['address'] ?? ''));
    $contactName = trim((string)($d['contact_name'] ?? ''));
    $contactPhone = trim((string)($d['contact_phone'] ?? ''));
    $follower = trim((string)($d['follower'] ?? ''));
    $id = isset($d['id']) ? (int)$d['id'] : 0;
    try {
        if ($id > 0) {
            $sql = "UPDATE companies SET name=?, region_main=?, region_sub=?, store_name=?, store_type=?, business_status=?, related_store=?, franchise_brand=?, address=?, contact_name=?, contact_phone=?, follower=? WHERE id=?";
            $pdo->prepare($sql)->execute([$name, $regionMain ?: null, $regionSub ?: null, $storeName ?: null, $storeType ?: null, $businessStatus ?: null, $relatedStore ?: null, $franchiseBrand ?: null, $address ?: null, $contactName ?: null, $contactPhone ?: null, $follower ?: null, $id]);
        } else {
            $sql = "INSERT INTO companies (name, region_main, region_sub, store_name, store_type, business_status, related_store, franchise_brand, address, contact_name, contact_phone, follower) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([$name, $regionMain ?: null, $regionSub ?: null, $storeName ?: null, $storeType ?: null, $businessStatus ?: null, $relatedStore ?: null, $franchiseBrand ?: null, $address ?: null, $contactName ?: null, $contactPhone ?: null, $follower ?: null]);
        }
    } catch (PDOException $e) {
        $code = $e->getCode();
        $msg = $e->getMessage();
        if ($code === '23000' || stripos($msg, 'Duplicate') !== false) {
            echo json_encode(['status' => 'error', 'msg' => '公司全称与门店名称组合已存在，请修改后保存']);
            exit;
        }
        echo json_encode(['status' => 'error', 'msg' => '保存失败：' . $msg]);
        exit;
    }
    echo json_encode(['status' => 'success']);
    exit;
}

// [删除] (补全)
if ($action == 'delete_company') {
    $pdo->prepare("DELETE FROM companies WHERE id=?")->execute([$_POST['id']]);
    echo json_encode(['status'=>'success']); exit;
}

// [批量修改跟进人]
if ($action == 'batch_update_company_follower') {
    $ids = $_POST['ids'] ?? '';
    $follower = $_POST['follower'] ?? '';
    
    if (empty($ids) || empty($follower)) {
        echo json_encode(['status'=>'error', 'msg'=>'参数不足']); exit;
    }
    
    $idArray = explode(',', $ids);
    $idArray = array_filter($idArray, function($id) { return is_numeric($id) && $id > 0; });
    
    if (empty($idArray)) {
        echo json_encode(['status'=>'error', 'msg'=>'无效的商户ID']); exit;
    }
    
    try {
        $placeholders = str_repeat('?,', count($idArray) - 1) . '?';
        $sql = "UPDATE companies SET follower=? WHERE id IN ($placeholders)";
        $params = array_merge([$follower], $idArray);
        
        $stmt = $pdo->prepare($sql);
        $result = $stmt->execute($params);
        
        if ($result) {
            echo json_encode(['status'=>'success', 'msg'=>'批量修改成功']);
        } else {
            echo json_encode(['status'=>'error', 'msg'=>'修改失败']);
        }
    } catch (Exception $e) {
        echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
    }
    exit;
}

// ======================= 3. 项目管理 (Projects) =======================
function ensure_project_commission_packages_schema(PDO $pdo): void
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

if ($action == 'get_projects') {
    $rows = $pdo->query("SELECT * FROM projects WHERE is_deleted=0 AND status=1 ORDER BY is_agent DESC, id DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        if (isset($r['image'])) {
            $r['image'] = project_image_public_url($r['image']);
        }
    }
    unset($r);
    echo json_encode($rows);
    exit;
}
if ($action == 'get_project_detail') {
    ensure_project_commission_packages_schema($pdo);
    $stmt = $pdo->prepare("SELECT * FROM projects WHERE id=?");
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && isset($row['image'])) {
        $row['image'] = project_image_public_url($row['image']);
    }
    $row['commission_packages'] = [];
    if ($row && !empty($row['id'])) {
        $pstmt = $pdo->prepare('SELECT id, package_name, commission_pct, cash_reward, jump_ratio, jump_reward, is_enabled, sort_order FROM project_commission_packages WHERE project_id = ? ORDER BY sort_order ASC, id ASC');
        $pstmt->execute([(int)$row['id']]);
        $row['commission_packages'] = $pstmt->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode($row);
    exit;
}
if ($action == 'save_project') {
    $d = $_POST;
    
    // 验证项目名称
    if(empty($d['name']) || strlen(trim($d['name'])) < 2) {
        echo json_encode(['status'=>'error', 'msg'=>'项目名称至少需要2个字符']); exit;
    }
    
    // 过滤项目名称，移除异常字符
    $d['name'] = trim($d['name']);
    if (!empty($d['image'])) {
        $d['image'] = project_image_public_url($d['image']);
    }

    // DECIMAL/INT 列不能接受空字符串；均价、保护期等允许前端留空
    $normDecimal = function ($v, $default = 0.0) {
        $s = isset($v) ? trim((string)$v) : '';
        if ($s === '' || !is_numeric($s)) {
            return $default;
        }
        return round((float)$s, 2);
    };
    $normInt = function ($v, $default = 0) {
        $s = isset($v) ? trim((string)$v) : '';
        if ($s === '' || !preg_match('/^-?\d+$/', $s)) {
            return (int)$default;
        }
        return (int)$s;
    };
    $normNullableVarchar = function ($v) {
        $s = isset($v) ? trim((string)$v) : '';
        return $s === '' ? null : $s;
    };

    $normDecimal4 = function ($v, $default = 0.0) {
        $s = isset($v) ? trim((string)$v) : '';
        if ($s === '' || !is_numeric($s)) {
            return $default;
        }
        return round((float)$s, 4);
    };

    $priceVal = $normDecimal($d['price'] ?? '', 0.0);
    $protectDaysVal = $normInt($d['protect_days'] ?? '', 30);
    $commissionRateVal = $normNullableVarchar($d['commission_rate'] ?? '');
    $taxRateVal = $normNullableVarchar($d['tax_rate'] ?? '');

    $packagesRaw = isset($d['commission_packages']) ? (string)$d['commission_packages'] : '[]';
    $packagesDecoded = json_decode($packagesRaw, true);
    if (!is_array($packagesDecoded)) {
        $packagesDecoded = [];
    }

    ensure_project_commission_packages_schema($pdo);

    try {
        $pdo->beginTransaction();

        $idRaw = isset($d['id']) ? trim((string)$d['id']) : '';
        if ($idRaw !== '' && ctype_digit($idRaw)) {
            $projectId = (int)$idRaw;
            $sql = "UPDATE projects SET name=?, price=?, commission_rate=?, tax_rate=?, protect_days=?, detail=?, image=?, manager_name=?, manager_phone=?, is_agent=?, status=? WHERE id=?";
            $pdo->prepare($sql)->execute([$d['name'], $priceVal, $commissionRateVal, $taxRateVal, $protectDaysVal, $d['detail'] ?? '', $d['image'] ?? '', $d['manager_name'] ?? '', $d['manager_phone'] ?? '', $d['is_agent'], $d['status'], $projectId]);
        } else {
            $sql = "INSERT INTO projects (name, price, commission_rate, tax_rate, protect_days, detail, image, manager_name, manager_phone, is_agent, status, is_deleted) VALUES (?,?,?,?,?,?,?,?,?,?,?,0)";
            $pdo->prepare($sql)->execute([$d['name'], $priceVal, $commissionRateVal, $taxRateVal, $protectDaysVal, $d['detail'] ?? '', $d['image'] ?? '', $d['manager_name'] ?? '', $d['manager_phone'] ?? '', $d['is_agent'], $d['status']]);
            $projectId = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM project_commission_packages WHERE project_id = ?')->execute([$projectId]);

        $insPkg = $pdo->prepare('INSERT INTO project_commission_packages (project_id, package_name, commission_pct, cash_reward, jump_ratio, jump_reward, is_enabled, sort_order) VALUES (?,?,?,?,?,?,?,?)');
        $sort = 0;
        foreach ($packagesDecoded as $pkg) {
            if (!is_array($pkg)) {
                continue;
            }
            $pname = isset($pkg['package_name']) ? trim((string)$pkg['package_name']) : '';
            if ($pname === '') {
                continue;
            }
            $pct = $normDecimal4($pkg['commission_pct'] ?? '', 0.0);
            $cash = $normDecimal($pkg['cash_reward'] ?? '', 0.0);
            $jr = $normDecimal4($pkg['jump_ratio'] ?? '', 0.0);
            $jrw = $normDecimal($pkg['jump_reward'] ?? '', 0.0);
            $en = 1;
            if (array_key_exists('is_enabled', $pkg)) {
                $ev = $pkg['is_enabled'];
                $en = ($ev === 0 || $ev === '0' || $ev === false || $ev === 'false') ? 0 : 1;
            }
            $insPkg->execute([$projectId, $pname, $pct, $cash, $jr, $jrw, $en, $sort]);
            $sort++;
        }

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $code = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0;
        if ($code === 1062) {
            echo json_encode(['status' => 'error', 'msg' => '项目名称已存在（与已有项目重名），请换一个名称']);
        } else {
            echo json_encode(['status' => 'error', 'msg' => '保存失败：' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['status' => 'success', 'id' => $projectId]);
    exit;
}
if ($action == 'delete_project') { $pdo->prepare("UPDATE projects SET is_deleted=1 WHERE id=?")->execute([$_POST['id']]); echo json_encode(['status'=>'success']); exit; }

// ======================= 4. 报备管理 (Filings) =======================

// [查询] 支持分页与筛选 (补全)
if ($action == 'get_filings') {
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
    $kw = $_GET['kw'] ?? '';
    $status = $_GET['status'] ?? '';
    $offset = ($page - 1) * $limit;

    $where = "WHERE 1=1";
    $params = [];

    if ($kw) {
        $where .= " AND (f.client_name LIKE ? OR f.client_phone LIKE ? OR f.broker_name LIKE ?)";
        $params[] = "%$kw%"; $params[] = "%$kw%"; $params[] = "%$kw%";
    }
    if ($status !== '') {
        $where .= " AND f.status = ?";
        $params[] = $status;
    }

    $countStmt = $pdo->prepare("SELECT count(*) FROM filings f $where");
    $countStmt->execute($params);
    $total = $countStmt->fetchColumn();

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
    echo json_encode(['code'=>0, 'count'=>$total, 'data'=>$stmt->fetchAll(PDO::FETCH_ASSOC)]); exit;
}

// [审核] 更改状态 (补全)
if ($action == 'audit_status') {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $reason = $_POST['reason'] ?? '';
    $__an = trim((string)($_SESSION['admin_name'] ?? ''));
    if ($__an === '') $__an = '管理员';
    $__an = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__an);
    $log = "\n" . date('Y-m-d H:i') . " [管理员·{$__an}] " . ($status == 1 ? '审核通过' : '审核驳回') . ($reason ? " ($reason)" : "");
    $pdo->prepare("UPDATE filings SET status=?, status_log=CONCAT(IFNULL(status_log,''), ?) WHERE id=?")->execute([$status, $log, $id]);
    echo json_encode(['status'=>'success']); exit;
}

// ======================= 5. 财务佣金 (Finance) =======================
if ($action == 'get_finance_data') {
    $stats = ['gmv'=>$pdo->query("SELECT SUM(deal_price) FROM filings WHERE status=4")->fetchColumn()?:0, 'pending'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=0")->fetchColumn()?:0, 'confirmed'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=1")->fetchColumn()?:0, 'paid'=>$pdo->query("SELECT SUM(commission_amount) FROM filings WHERE status=4 AND commission_status=2")->fetchColumn()?:0];
    $deals = $pdo->query("SELECT f.*, p.name as project_name FROM filings f LEFT JOIN projects p ON f.project_id = p.id WHERE f.status = 4 ORDER BY f.commission_status ASC, f.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode(['stats'=>$stats, 'deals'=>$deals]); exit;
}
if ($action == 'update_commission') { 
    $pdo->prepare("UPDATE filings SET commission_status=?, commission_amount=?, commission_proof=? WHERE id=?")->execute([$_POST['status'], $_POST['amount'], $_POST['proof'], $_POST['id']]); echo json_encode(['status'=>'success']); exit; 
}

// ======================= 6. 人员与架构 (Agents) =======================

// 辅助函数：构建树
function buildTree(array $elements, $parentId = 0) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['parent_id'] == $parentId) {
            $children = buildTree($elements, $element['id']);
            if ($children) $element['children'] = $children;
            $branch[] = $element;
        }
    }
    return $branch;
}

// [API] 获取完整架构树 (性能优化版)
if ($action == 'get_structure') {
    try {
        ensure_agent_employment_schema($pdo);
        // 1. 获取部门
        $depts = $pdo->query("SELECT * FROM departments ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
        $deptTree = buildTree($depts);
        
        // 2. 获取人员
        $sql = "SELECT a.*, d.name as dept_name, m.username as manager_name FROM agents a LEFT JOIN departments d ON a.department_id = d.id LEFT JOIN agents m ON a.manager_id = m.id WHERE a.is_deleted=0 ORDER BY a.id DESC";
        $agents = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        // 3. 按项目库管理员绑定统计（与 admin_projects.php 一致）
        $pm_sql = "SELECT id, name, manager_name FROM projects WHERE is_deleted = 0";
        $projectRows = $pdo->query($pm_sql)->fetchAll(PDO::FETCH_ASSOC);
        $projectCountByManager = [];
        $projectsByManager = [];
        foreach ($projectRows as $pr) {
            $mname = trim((string)($pr['manager_name'] ?? ''));
            if ($mname === '') continue;
            if (!isset($projectCountByManager[$mname])) $projectCountByManager[$mname] = 0;
            if (!isset($projectsByManager[$mname])) $projectsByManager[$mname] = [];
            $projectCountByManager[$mname]++;
            $projectsByManager[$mname][] = ['name' => $pr['name'], 'project_id' => (int)$pr['id']];
        }

        // 4. 商户数（与 admin_companies / companies 表一致）：按渠道负责人 TRIM 归一化，空/公池/公共池 合并为「公池」键
        $c_sql = "SELECT 
            CASE
                WHEN TRIM(IFNULL(follower,'')) = '' OR TRIM(follower) IN ('公池','公共池') THEN '公池'
                ELSE TRIM(follower)
            END AS fk,
            COUNT(*) AS cnt
            FROM companies
            GROUP BY CASE
                WHEN TRIM(IFNULL(follower,'')) = '' OR TRIM(follower) IN ('公池','公共池') THEN '公池'
                ELSE TRIM(follower)
            END";
        $followerCountByAgentName = $pdo->query($c_sql)->fetchAll(PDO::FETCH_KEY_PAIR);

        // 5. 构建上下级关系
        $childrenMap = [];
        foreach ($agents as $ag) {
            $mid = (int)($ag['manager_id'] ?? 0);
            if (!isset($childrenMap[$mid])) $childrenMap[$mid] = [];
            $childrenMap[$mid][] = (int)$ag['id'];
        }

        // 6. 统计口径：含「渠道经纪人」角色或部门为渠道经理/渠道人员 → 商户 companies（本人+下级 follower 求和）；服务中心等 → 项目绑定数
        $countModeForAgent = function ($deptName, array $roles) {
            if (in_array('channel', $roles, true)) {
                return 'follower';
            }
            $d = (string) $deptName;
            if (strpos($d, '服务中心') !== false) {
                return 'project';
            }
            if (strpos($d, '渠道经理') !== false || strpos($d, '渠道人员') !== false) {
                return 'follower';
            }
            return 'project';
        };

        // 7. 计算每个人员（本人+所有下级）的汇总
        $agentNameMap = [];
        foreach ($agents as $ag) $agentNameMap[(int)$ag['id']] = $ag['username'];
        $nameScopeCache = [];
        $collectScopeNames = function($agentId) use (&$collectScopeNames, &$childrenMap, &$agentNameMap, &$nameScopeCache) {
            if (isset($nameScopeCache[$agentId])) return $nameScopeCache[$agentId];
            $names = [];
            if (isset($agentNameMap[$agentId]) && $agentNameMap[$agentId] !== '') $names[] = $agentNameMap[$agentId];
            $children = $childrenMap[$agentId] ?? [];
            foreach ($children as $cid) {
                $names = array_merge($names, $collectScopeNames($cid));
            }
            $names = array_values(array_unique($names));
            $nameScopeCache[$agentId] = $names;
            return $names;
        };

        // 8. 组装
        foreach ($agents as &$agent) {
            $agent['roles'] = agent_roles_normalize_from_row($agent);
            $aid = (int)$agent['id'];
            $selfName = $agent['username'] ?? '';
            $agent['projects'] = isset($projectsByManager[$selfName]) ? $projectsByManager[$selfName] : [];
            $scopeNames = $collectScopeNames($aid);
            $mode = $countModeForAgent($agent['dept_name'] ?? '', $agent['roles']);
            $sum = 0;
            if ($mode === 'follower') {
                foreach ($scopeNames as $n) {
                    $nk = trim((string)$n);
                    if ($nk === '' || in_array($nk, ['公池', '公共池'], true)) {
                        $nk = '公池';
                    }
                    $sum += isset($followerCountByAgentName[$nk]) ? (int)$followerCountByAgentName[$nk] : 0;
                }
                $agent['responsible_count_unit'] = '家';
                $agent['responsible_label'] = '负责商户';
            } else {
                foreach ($scopeNames as $n) {
                    $sum += isset($projectCountByManager[$n]) ? (int)$projectCountByManager[$n] : 0;
                }
                $agent['responsible_count_unit'] = '个';
                $agent['responsible_label'] = '负责项目';
            }
            $agent['company_count'] = $sum;
        }

        echo json_encode(['departments' => $deptTree, 'agents' => $agents]); exit;
    } catch (Exception $e) {
        echo json_encode(['departments' => [], 'agents' => []]); exit;
    }
}

// [API] 获取单人详情 (编辑专用)
if ($action == 'get_agent_detail') {
    try {
        ensure_agent_employment_schema($pdo);
        agent_roles_ensure_column($pdo);
        $id = $_GET['id'];
        $agent = $pdo->query("SELECT * FROM agents WHERE id = $id")->fetch(PDO::FETCH_ASSOC);
        if (!$agent) {
            echo json_encode([]); exit;
        }
        // 关联项目ID
        $agent['project_ids'] = $pdo->query("SELECT project_id FROM agent_projects WHERE agent_id = $id")->fetchAll(PDO::FETCH_COLUMN);
        // 关联商户详情
        $agent['selected_companies'] = $pdo->query("SELECT c.id, c.name FROM agent_companies ac LEFT JOIN companies c ON ac.company_id = c.id WHERE ac.agent_id = $id")->fetchAll(PDO::FETCH_ASSOC);
        // 确保 selected_companies 是数组
        if (!is_array($agent['selected_companies'])) {
            $agent['selected_companies'] = [];
        }
        $agent['roles'] = agent_roles_normalize_from_row($agent);
        $agent['role'] = agent_roles_primary($agent['roles']);
        echo json_encode($agent); exit;
    } catch (Exception $e) {
        echo json_encode([]); exit;
    }
}

// [API] 保存人员 (含关联项目+商户)
if ($action == 'save_agent_full') {
    $d = $_POST;
    try {
        ensure_agent_employment_schema($pdo);
        $pdo->beginTransaction();
        $employmentStatusInput = array_key_exists('employment_status', $d) ? (int)$d['employment_status'] : null;
        $employmentStatusInput = ($employmentStatusInput === 0) ? 0 : (($employmentStatusInput === null) ? null : 1);
        $changerId = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
        $changerName = $_SESSION['admin_username'] ?? ($_SESSION['admin_name'] ?? '系统');
        $rolesArr = [];
        if (!empty($d['roles_json'])) {
            $decoded = json_decode((string)$d['roles_json'], true);
            if (is_array($decoded)) {
                $rolesArr = agent_roles_validate($decoded);
            }
        }
        if ($rolesArr === [] && !empty($d['role'])) {
            $rolesArr = agent_roles_validate([(string)$d['role']]);
        }
        if ($rolesArr === []) {
            throw new Exception('请至少选择一个系统角色');
        }
        $rolesJson = json_encode($rolesArr, JSON_UNESCAPED_UNICODE);
        $primaryRole = agent_roles_primary($rolesArr);

        if (!empty($d['id'])) {
            $beforeStmt = $pdo->prepare("SELECT employment_status FROM agents WHERE id=? LIMIT 1");
            $beforeStmt->execute([$d['id']]);
            $beforeStatus = $beforeStmt->fetchColumn();
            if ($beforeStatus === false) {
                throw new Exception('人员不存在');
            }
            $beforeStatus = (int)$beforeStatus;
            $employmentStatus = $employmentStatusInput === null ? $beforeStatus : $employmentStatusInput;

            $leftAtExpr = $employmentStatus === 0 ? "COALESCE(left_at, NOW())" : "NULL";
            $sql = "UPDATE agents SET username=?, phone=?, department_id=?, role=?, roles_json=?, password=?, manager_id=?, employment_status=?, left_at={$leftAtExpr} WHERE id=?";
            $pdo->prepare($sql)->execute([$d['username'], $d['phone'], $d['department_id'], $primaryRole, $rolesJson, $d['password'], $d['manager_id'] ?? 0, $employmentStatus, $d['id']]);
            $agent_id = $d['id'];

            if ($beforeStatus !== $employmentStatus) {
                $logStmt = $pdo->prepare("INSERT INTO agent_status_logs (agent_id, from_status, to_status, changed_by_id, changed_by_name, remark) VALUES (?,?,?,?,?,?)");
                $remark = $employmentStatus === 0 ? '人员离职' : '人员复职';
                $logStmt->execute([$agent_id, $beforeStatus, $employmentStatus, $changerId, $changerName, $remark]);
            }
        } else {
            $employmentStatus = $employmentStatusInput === null ? 1 : $employmentStatusInput;
            $leftAt = $employmentStatus === 0 ? date('Y-m-d H:i:s') : null;
            $sql = "INSERT INTO agents (username, phone, department_id, role, roles_json, password, manager_id, employment_status, left_at) VALUES (?,?,?,?,?,?,?,?,?)";
            $pdo->prepare($sql)->execute([$d['username'], $d['phone'], $d['department_id'], $primaryRole, $rolesJson, $d['password'], $d['manager_id'] ?? 0, $employmentStatus, $leftAt]);
            $agent_id = $pdo->lastInsertId();

            $logStmt = $pdo->prepare("INSERT INTO agent_status_logs (agent_id, from_status, to_status, changed_by_id, changed_by_name, remark) VALUES (?,?,?,?,?,?)");
            $logStmt->execute([$agent_id, null, $employmentStatus, $changerId, $changerName, '新建人员']);
        }

        // 保存负责项目
        $pdo->prepare("DELETE FROM agent_projects WHERE agent_id = ?")->execute([$agent_id]);
        if (!empty($d['project_ids'])) {
            $pids = explode(',', $d['project_ids']);
            $ins = $pdo->prepare("INSERT INTO agent_projects (agent_id, project_id) VALUES (?, ?)");
            foreach ($pids as $pid) { if($pid) $ins->execute([$agent_id, $pid]); }
        }

        // 保存负责商户
        $pdo->prepare("DELETE FROM agent_companies WHERE agent_id = ?")->execute([$agent_id]);
        if (!empty($d['company_ids'])) {
            $cids = explode(',', $d['company_ids']);
            $ins = $pdo->prepare("INSERT INTO agent_companies (agent_id, company_id) VALUES (?, ?)");
            foreach ($cids as $cid) { if($cid) $ins->execute([$agent_id, $cid]); }
        }

        $pdo->commit();
        echo json_encode(['status'=>'success']);
    } catch (Exception $e) {
        $pdo->rollBack();
        echo json_encode(['status'=>'error', 'msg'=>$e->getMessage()]);
    }
    exit;
}

// [API] 部门与简单人员操作
if ($action == 'save_department') {
    $d = $_POST;
    if(!empty($d['id'])) $pdo->prepare("UPDATE departments SET name=?, parent_id=? WHERE id=?")->execute([$d['name'], $d['parent_id'], $d['id']]);
    else $pdo->prepare("INSERT INTO departments (name, parent_id) VALUES (?, ?)")->execute([$d['name'], $d['parent_id']]);
    echo json_encode(['status'=>'success']); exit;
}
if ($action == 'delete_department') {
    $id = $_POST['id'];
    $hasChild = $pdo->query("SELECT count(*) FROM departments WHERE parent_id=$id")->fetchColumn();
    $hasAgent = $pdo->query("SELECT count(*) FROM agents WHERE department_id=$id AND is_deleted=0")->fetchColumn();
    if ($hasChild > 0 || $hasAgent > 0) echo json_encode(['status'=>'error', 'msg'=>'该部门下有子部门或人员，无法删除']);
    else { $pdo->prepare("DELETE FROM departments WHERE id=?")->execute([$id]); echo json_encode(['status'=>'success']); }
    exit;
}
if ($action == 'delete_agent') { 
    $pdo->prepare("UPDATE agents SET is_deleted=1 WHERE id=?")->execute([$_POST['id']]); echo json_encode(['status'=>'success']); exit; 
}
?>