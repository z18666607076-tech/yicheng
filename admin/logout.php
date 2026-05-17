<?php
// admin/logout.php - 后台管理员登出
session_start();

// 清除所有会话变量
$_SESSION = array();

// 销毁会话
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 3600, '/');
}

session_destroy();

// 跳转到登录页面
header('Location: login.php');
exit;
?>