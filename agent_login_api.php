<?php
// agent_login_api.php - 登录验证与角色分流
session_start();
header('Content-Type: application/json');

// 1. 数据库连接
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    die(json_encode(['status'=>'error','msg'=>'数据库连接失败'])); 
}

require_once __DIR__ . '/includes/agent_roles.php';
try { agent_roles_ensure_column($pdo); } catch (Exception $e) {}

// 确保在职状态字段存在（兼容老库）
try { $pdo->exec("ALTER TABLE agents ADD COLUMN employment_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '在职状态:1在职,0离职'"); } catch (Exception $e) {}
try { $pdo->exec("ALTER TABLE agents ADD COLUMN left_at DATETIME NULL COMMENT '离职时间'"); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $phone = $_POST['phone'] ?? '';
    $password = $_POST['password'] ?? '';

    if (!$phone || !$password) {
        echo json_encode(['status'=>'error', 'msg'=>'请输入手机号和密码']);
        exit;
    }

    // 2. 同一手机号可能对应多条 agents（如历史测试号与正式人员），按密码命中正确那条
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE phone = ? AND is_deleted = 0 ORDER BY id ASC");
    $stmt->execute([$phone]);
    $agent = null;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (($row['password'] ?? '') === $password) {
            $agent = $row;
            break;
        }
    }

    // 3. 验证已在循环中完成
    if ($agent) {
        if (isset($agent['employment_status']) && (int)$agent['employment_status'] === 0) {
            echo json_encode(['status'=>'error', 'msg'=>'该账号已离职，无法登录']);
            exit;
        }
        
        // --- 登录成功，写入 Session ---
        $roles = agent_roles_normalize_from_row($agent);
        // 根目录手机号登录：不写入 admin_* Session，进后台须走 admin/login.php
        unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_phone']);

        // 当前门户角色：优先案场、再渠道（与「全盘通」手机号入口一致）；仅后台类角色时给 admin 仅作展示，跳转后台登录页
        $portalRole = 'channel';
        if (in_array('staff', $roles, true)) {
            $portalRole = 'staff';
        } elseif (in_array('channel', $roles, true)) {
            $portalRole = 'channel';
        } elseif (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            $portalRole = in_array('admin', $roles, true) ? 'admin' : 'finance';
        }

        $_SESSION['agent_id'] = $agent['id'];
        $_SESSION['agent_name'] = $agent['username'];
        $_SESSION['agent_phone'] = $agent['phone'];
        $_SESSION['agent_role'] = $portalRole;
        $_SESSION['agent_roles'] = implode(',', $roles);
        
        // 获取部门/公司名称 (用于前端显示)
        $deptName = '未知部门';
        if ($agent['department_id']) {
            $d = $pdo->query("SELECT name FROM departments WHERE id=".$agent['department_id'])->fetchColumn();
            if($d) $deptName = $d;
        }
        $_SESSION['agent_company'] = $deptName;

        // --- 4. 根目录登录跳转：优先案场 / 渠道；仅管理员或财务且无案场/渠道身份时 → 后台登录页 ---
        $redirectUrl = 'agent.php';
        if (in_array('staff', $roles, true)) {
            $redirectUrl = 'staff.php';
        } elseif (in_array('channel', $roles, true)) {
            $redirectUrl = 'agent.php';
        } elseif (in_array('admin', $roles, true) || in_array('finance', $roles, true)) {
            $redirectUrl = 'admin/login.php';
        }

        // 返回成功状态和跳转地址
        echo json_encode(['status'=>'success', 'redirect' => $redirectUrl]);
        
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'账号或密码错误']);
    }
}
?>