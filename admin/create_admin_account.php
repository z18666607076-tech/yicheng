<?php
// create_admin_account.php - 创建管理员账号
// 访问此文件一次后即可删除

// 数据库连接
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    die("数据库连接失败: " . $e->getMessage()); 
}

// 检查是否已存在管理员账号
$stmt = $pdo->prepare("SELECT * FROM agents WHERE role = 'admin' AND is_deleted = 0");
$stmt->execute();
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

if ($admin) {
    echo "管理员账号已存在，用户名: " . $admin['username'] . "，手机号: " . $admin['phone'];
} else {
    // 创建管理员账号
    $username = 'admin';
    $password = 'admin123'; // 建议生产环境修改此密码
    $phone = '13800138000';
    $role = 'admin';
    $department_id = 0;
    $is_deleted = 0;
    
    $stmt = $pdo->prepare("INSERT INTO agents (username, password, phone, role, department_id, is_deleted) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$username, $password, $phone, $role, $department_id, $is_deleted]);
    
    echo "管理员账号创建成功！<br>";
    echo "用户名: $username<br>";
    echo "密码: $password<br>";
    echo "手机号: $phone<br>";
    echo "请登录后修改密码以确保安全。";
}
?>