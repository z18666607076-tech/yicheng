<?php
// logout.php - 通用注销逻辑
session_start();

// 1. 清空 Session
$_SESSION = array();

// 2. 销毁 Session Cookie (彻底清除)
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 3. 销毁 Session
session_destroy();

// 4. 跳转回登录页
header("Location: login.php");
exit;
?>