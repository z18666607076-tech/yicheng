<?php
// admin_login_api.php - 后台管理员登录验证
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
try { $pdo->exec("ALTER TABLE agents ADD COLUMN employment_status TINYINT(1) NOT NULL DEFAULT 1 COMMENT '在职状态:1在职,0离职'"); } catch (Exception $e) {}
require_once __DIR__ . '/../includes/agent_roles.php';
require_once __DIR__ . '/../includes/compete_list_permissions.php';
try { agent_roles_ensure_column($pdo); } catch (Exception $e) {}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = $_POST['password'] ?? '';

    if ($username === '' || !$password) {
        echo json_encode(['status'=>'error', 'msg'=>'请输入用户名和密码']);
        exit;
    }

    // 2. 管理员：支持「姓名/用户名」或「手机号」；同一手机号多条账号时按密码命中（勿 LIMIT 1 否则总命中 id 最小那条）
    $jAdmin = agent_roles_json_for_sql_contains('admin');
    $stmt = $pdo->prepare("SELECT * FROM agents WHERE is_deleted = 0 AND (role = 'admin' OR (JSON_VALID(roles_json) AND JSON_CONTAINS(roles_json, ?, '$'))) AND (username = ? OR phone = ?) ORDER BY id ASC");
    $stmt->execute([$jAdmin, $username, $username]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $admin = null;
    foreach ($rows as $row) {
        if (($row['password'] ?? '') === $password) {
            $admin = $row;
            break;
        }
    }

    // 3. 验证已在循环中完成
    if ($admin) {
        if (isset($admin['employment_status']) && (int)$admin['employment_status'] === 0) {
            echo json_encode(['status' => 'error', 'msg' => '该账号已离职，无法登录']);
            exit;
        }
        // --- 登录成功，写入 Session（同步 agent_*，竞对列表等按管理员全量数据）---
        compete_list_sync_admin_session_from_agent_row($admin);

        // 返回成功状态
        echo json_encode(['status'=>'success']);
        
    } else {
        echo json_encode(['status'=>'error', 'msg'=>'用户名或密码错误']);
    }
}
?>