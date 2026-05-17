<?php
/**
 * 人员多系统角色：roles_json 存 JSON 数组，role 列同步为最高优先级单值以兼容旧逻辑。
 */

if (!defined('AGENT_ROLES_ORDER')) {
    /** 权限优先级（高 → 低），用于同步 agents.role 与登录后默认入口 */
    define('AGENT_ROLES_ORDER', ['admin', 'finance', 'staff', 'channel']);
}

if (!function_exists('agent_roles_ensure_column')) {
    function agent_roles_ensure_column(PDO $pdo): void {
        try {
            $pdo->exec("ALTER TABLE agents ADD COLUMN roles_json TEXT NULL COMMENT 'JSON数组: channel,staff,finance,admin'");
        } catch (Throwable $e) {
            // 已存在
        }
    }
}

if (!function_exists('agent_roles_normalize_from_row')) {
    /**
     * @return list<string>
     */
    function agent_roles_normalize_from_row(array $agent): array {
        $allowed = ['channel', 'staff', 'finance', 'admin'];
        $j = $agent['roles_json'] ?? null;
        if (is_string($j)) {
            $j = trim($j);
            if ($j !== '' && $j !== 'null') {
                $a = json_decode($j, true);
                if (is_array($a)) {
                    $out = [];
                    foreach ($a as $x) {
                        $s = trim((string)$x);
                        if (in_array($s, $allowed, true)) {
                            $out[$s] = true;
                        }
                    }
                    if ($out !== []) {
                        return array_keys($out);
                    }
                }
            }
        }
        $r = trim((string)($agent['role'] ?? ''));
        if ($r !== '' && in_array($r, $allowed, true)) {
            return [$r];
        }
        return ['channel'];
    }
}

if (!function_exists('agent_roles_validate')) {
    /**
     * @param list<mixed> $input
     * @return list<string>
     */
    function agent_roles_validate(array $input): array {
        $allowed = ['channel', 'staff', 'finance', 'admin'];
        $out = [];
        foreach ($input as $x) {
            $s = trim((string)$x);
            if (in_array($s, $allowed, true)) {
                $out[$s] = true;
            }
        }
        return array_keys($out);
    }
}

if (!function_exists('agent_roles_primary')) {
    /** 同步写入 agents.role 的单值（取 roles 中优先级最高者） */
    function agent_roles_primary(array $roles): string {
        foreach (AGENT_ROLES_ORDER as $p) {
            if (in_array($p, $roles, true)) {
                return $p;
            }
        }
        return 'channel';
    }
}

if (!function_exists('agent_has_role')) {
    function agent_has_role(array $agent, string $role): bool {
        $role = trim($role);
        return in_array($role, agent_roles_normalize_from_row($agent), true);
    }
}

if (!function_exists('agent_roles_json_for_sql_contains')) {
    /** 供 PDO 绑定 JSON_CONTAINS 第二参数，如 admin → '"admin"' */
    function agent_roles_json_for_sql_contains(string $role): string {
        return json_encode($role, JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('agent_sql_has_role')) {
    /**
     * 生成 SQL 片段：单列 role 或 roles_json 中含某角色（用于 WHERE）。
     * $alias 仅允许字母数字下划线。
     */
    function agent_sql_has_role(string $alias, string $role): string {
        $a = preg_replace('/[^a-zA-Z0-9_]/', '', $alias);
        if ($a === '') {
            $a = 'a';
        }
        $role = trim($role);
        if (!in_array($role, ['channel', 'staff', 'finance', 'admin'], true)) {
            return '0';
        }
        $roleEsc = str_replace("'", "''", $role);
        return "({$a}.role = '{$roleEsc}' OR (JSON_VALID({$a}.roles_json) AND JSON_CONTAINS({$a}.roles_json, JSON_QUOTE('{$roleEsc}'), '$')))";
    }
}
