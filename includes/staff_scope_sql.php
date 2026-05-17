<?php
/**
 * 案场工作台 listings 与 admin 佣金页共用的「数据可见范围」SQL 片段（与 staff.php 逻辑一致）
 */

require_once __DIR__ . '/agent_roles.php';

if (!function_exists('build_in_clause_staff')) {
    function build_in_clause_staff($field, $values, &$params) {
        if (empty($values)) {
            return "1=0";
        }
        $placeholders = implode(',', array_fill(0, count($values), '?'));
        foreach ($values as $v) {
            $params[] = $v;
        }
        return "$field IN ($placeholders)";
    }
}

if (!function_exists('get_descendant_agent_ids_staff')) {
    function get_descendant_agent_ids_staff($pdo, $managerId) {
        $all = $pdo->query("SELECT id, manager_id FROM agents WHERE is_deleted = 0")->fetchAll(PDO::FETCH_ASSOC);
        $childrenMap = [];
        foreach ($all as $a) {
            $pid = (int)($a['manager_id'] ?? 0);
            if (!isset($childrenMap[$pid])) {
                $childrenMap[$pid] = [];
            }
            $childrenMap[$pid][] = (int)$a['id'];
        }
        $result = [];
        $stack = [(int)$managerId];
        while (!empty($stack)) {
            $curr = array_pop($stack);
            if (empty($childrenMap[$curr])) {
                continue;
            }
            foreach ($childrenMap[$curr] as $cid) {
                if (in_array($cid, $result, true)) {
                    continue;
                }
                $result[] = $cid;
                $stack[] = $cid;
            }
        }
        return $result;
    }
}

if (!function_exists('can_view_team_data_staff')) {
    function can_view_team_data_staff($currentUser) {
        $dept = $currentUser['company'] ?? '';
        return (strpos($dept, '服务中心') !== false) || (strpos($dept, '渠道经理') !== false) || (strpos($dept, '总经办') !== false) || (strpos($dept, '数据中心') !== false);
    }
}

if (!function_exists('can_view_all_data_staff')) {
    function can_view_all_data_staff($currentUser) {
        $dept = $currentUser['company'] ?? '';
        return (strpos($dept, '总经办') !== false) || (strpos($dept, '数据中心') !== false);
    }
}

if (!function_exists('staff_is_strict_project_site_scope')) {
    function staff_is_strict_project_site_scope($currentUser) {
        if (!$currentUser || can_view_all_data_staff($currentUser)) {
            return false;
        }
        $co = $currentUser['company'] ?? '';
        if (strpos($co, '项目驻场') === false) {
            return false;
        }
        if (strpos($co, '服务中心') !== false) {
            return false;
        }
        return true;
    }
}

if (!function_exists('staff_agent_department_id')) {
    function staff_agent_department_id(PDO $pdo, int $agentId) {
        if ($agentId <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare('SELECT department_id FROM agents WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $stmt->execute([$agentId]);
        $v = $stmt->fetchColumn();
        return ($v !== false && $v !== null && (int)$v > 0) ? (int)$v : 0;
    }
}

if (!function_exists('get_scope_members_staff')) {
    function get_scope_members_staff($pdo, $currentUser) {
        if (can_view_all_data_staff($currentUser)) {
            $stmt = $pdo->prepare("SELECT id, username, phone FROM agents WHERE is_deleted = 0");
            $stmt->execute();
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        $selfId = (int)$currentUser['id'];
        $ids = [$selfId];
        if (can_view_team_data_staff($currentUser)) {
            $ids = array_values(array_unique(array_merge($ids, get_descendant_agent_ids_staff($pdo, $selfId))));
        }
        $params = [];
        $where = build_in_clause_staff('id', $ids, $params);
        $stmt = $pdo->prepare("SELECT id, username, phone FROM agents WHERE is_deleted = 0 AND $where");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('get_scope_sql_and_params_staff')) {
    function get_scope_sql_and_params_staff(PDO $pdo, $scopeMembers, $memberId = 0, $currentUser = null) {
        if ($currentUser && can_view_all_data_staff($currentUser) && $memberId <= 0) {
            return ['sql' => '1=1', 'params' => []];
        }
        $ids = array_values(array_unique(array_map('intval', array_column($scopeMembers, 'id'))));
        $phones = array_values(array_unique(array_filter(array_column($scopeMembers, 'phone'))));
        $names = array_values(array_unique(array_filter(array_column($scopeMembers, 'username'))));
        if ($memberId > 0 && in_array($memberId, $ids, true)) {
            $ids = [$memberId];
            $phones = [];
            $names = [];
            foreach ($scopeMembers as $m) {
                if ((int)$m['id'] === $memberId) {
                    if (!empty($m['phone'])) {
                        $phones[] = $m['phone'];
                    }
                    if (!empty($m['username'])) {
                        $names[] = $m['username'];
                    }
                    break;
                }
            }
        }
        if ($currentUser && staff_is_strict_project_site_scope($currentUser)) {
            $params = [];
            $parts = [];
            $bindAgentId = (int)($currentUser['id'] ?? 0);
            if ($memberId > 0 && in_array($memberId, $ids, true)) {
                $bindAgentId = $memberId;
            }
            if ($bindAgentId > 0) {
                $parts[] = 'EXISTS (SELECT 1 FROM agent_projects ap WHERE ap.agent_id = ? AND ap.project_id = f.project_id)';
                $params[] = $bindAgentId;
            }
            if (!empty($names)) {
                $parts[] = build_in_clause_staff('p.manager_name', $names, $params);
            }
            if (!empty($phones)) {
                $parts[] = build_in_clause_staff('p.manager_phone', $phones, $params);
            }
            if (empty($parts)) {
                return ['sql' => '0=1', 'params' => []];
            }
            return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
        }
        $params = [];
        $parts = [];
        $parts[] = build_in_clause_staff('f.agent_id', $ids, $params);
        if (!empty($phones)) {
            $parts[] = build_in_clause_staff('f.broker_phone', $phones, $params);
        }
        if (!empty($names)) {
            $parts[] = build_in_clause_staff('c.follower', $names, $params);
        }
        if (!empty($names) && $currentUser) {
            $co = $currentUser['company'] ?? '';
            if (strpos($co, '服务中心') !== false || strpos($co, '项目驻场') !== false) {
                $parts[] = build_in_clause_staff('p.manager_name', $names, $params);
            }
        }
        $uidDept = (int)($currentUser['id'] ?? 0);
        if ($memberId > 0 && in_array($memberId, $ids, true)) {
            $uidDept = $memberId;
        }
        $deptIdWide = staff_agent_department_id($pdo, $uidDept);
        if ($deptIdWide > 0) {
            $staffRoleSql = agent_sql_has_role('ag_sd', 'staff');
            $parts[] = "EXISTS (SELECT 1 FROM agent_projects ap INNER JOIN agents ag_sd ON ag_sd.id = ap.agent_id WHERE ap.project_id = f.project_id AND ag_sd.department_id = ? AND ag_sd.is_deleted = 0 AND {$staffRoleSql})";
            $params[] = $deptIdWide;
        }
        return ['sql' => '(' . implode(' OR ', $parts) . ')', 'params' => $params];
    }
}
