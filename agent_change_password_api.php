<?php
// agent_change_password_api.php - 经纪人/案场登录用户修改自己的密码
session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['agent_id'])) {
    echo json_encode(['status' => 'error', 'msg' => '请先登录']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['status' => 'error', 'msg' => '无效请求']);
    exit;
}

$old = $_POST['old_password'] ?? '';
$new = $_POST['new_password'] ?? '';

if ($old === '' || $new === '') {
    echo json_encode(['status' => 'error', 'msg' => '请填写原密码和新密码']);
    exit;
}

if (mb_strlen($new) < 6) {
    echo json_encode(['status' => 'error', 'msg' => '新密码至少 6 位']);
    exit;
}

$host = '127.0.0.1';
$db = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => '数据库连接失败']);
    exit;
}

$agentId = (int)$_SESSION['agent_id'];
$stmt = $pdo->prepare('SELECT password FROM agents WHERE id = ? AND is_deleted = 0');
$stmt->execute([$agentId]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    echo json_encode(['status' => 'error', 'msg' => '用户不存在']);
    exit;
}

// 与 agent_login_api.php 一致：明文比对
if ($row['password'] !== $old) {
    echo json_encode(['status' => 'error', 'msg' => '原密码错误']);
    exit;
}

$upd = $pdo->prepare('UPDATE agents SET password = ? WHERE id = ? AND is_deleted = 0');
$upd->execute([$new, $agentId]);

echo json_encode(['status' => 'success', 'msg' => '密码已修改，请牢记新密码']);
