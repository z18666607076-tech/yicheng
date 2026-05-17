<?php
/**
 * 竞对列表数据权限：后台管理员 / 财务与 admin 账号一样查看全部竞对项目。
 */

require_once __DIR__ . '/agent_roles.php';

function compete_list_resolve_session_user_id(): int
{
    if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
        return (int)$_SESSION['admin_id'];
    }
    if (isset($_SESSION['agent_id']) && (int)$_SESSION['agent_id'] > 0) {
        return (int)$_SESSION['agent_id'];
    }
    return 0;
}

function compete_list_session_roles_include_admin_or_finance(): bool
{
    if (in_array($_SESSION['agent_role'] ?? '', ['admin', 'finance'], true)) {
        return true;
    }
    $csv = trim((string)($_SESSION['agent_roles'] ?? ''));
    if ($csv === '') {
        return false;
    }
    foreach (explode(',', $csv) as $part) {
        if (in_array(trim($part), ['admin', 'finance'], true)) {
            return true;
        }
    }
    return false;
}

function compete_list_agent_row_has_backend_role(array $row): bool
{
    $roles = agent_roles_normalize_from_row($row);
    foreach (['admin', 'finance'] as $r) {
        if (in_array($r, $roles, true)) {
            return true;
        }
    }
    return false;
}

/** 是否应按后台管理员看待（竞对数据不限制在本人代理项目） */
function compete_list_user_is_backend_admin(PDO $pdo): bool
{
    if (isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] > 0) {
        return true;
    }
    if (compete_list_session_roles_include_admin_or_finance()) {
        return true;
    }
    $uid = compete_list_resolve_session_user_id();
    if ($uid <= 0) {
        return false;
    }
    static $memo = [];
    if (array_key_exists($uid, $memo)) {
        return $memo[$uid];
    }
    $stmt = $pdo->prepare('SELECT role, roles_json FROM agents WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$uid]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    $memo[$uid] = $row ? compete_list_agent_row_has_backend_role($row) : false;
    return $memo[$uid];
}

/** 后台管理员登录成功后，同步 agent_* 会话，避免仅有 admin_id 时其它接口按案场权限收窄 */
function compete_list_sync_admin_session_from_agent_row(array $agent): void
{
    $roles = agent_roles_normalize_from_row($agent);
    $id = (int)($agent['id'] ?? 0);
    if ($id <= 0) {
        return;
    }
    $_SESSION['admin_id'] = $id;
    $_SESSION['admin_name'] = (string)($agent['username'] ?? '');
    $_SESSION['admin_phone'] = (string)($agent['phone'] ?? '');
    $_SESSION['agent_id'] = $id;
    $_SESSION['agent_name'] = (string)($agent['username'] ?? '');
    $_SESSION['agent_phone'] = (string)($agent['phone'] ?? '');
    $_SESSION['agent_roles'] = implode(',', $roles);
    if (in_array('admin', $roles, true)) {
        $_SESSION['agent_role'] = 'admin';
    } elseif (in_array('finance', $roles, true)) {
        $_SESSION['agent_role'] = 'finance';
    } else {
        $_SESSION['agent_role'] = agent_roles_primary($roles);
    }
}
