<?php
// admin/includes/admin_menu_permissions.php — 按管理员勾选控制侧栏可见菜单项

if (!function_exists('admin_menu_ensure_column')) {
    function admin_menu_ensure_column(PDO $pdo): void {
        try {
            $pdo->exec(
                "ALTER TABLE agents ADD COLUMN admin_menu_allow_ids TEXT NULL DEFAULT NULL COMMENT 'JSON array of menu item ids; NULL=全部可见'"
            );
        } catch (Throwable $e) {
            // 列已存在
        }
    }
}

/**
 * 收集所有「可点击跳转」的菜单项（含一级直链、各级带子 url 的叶子）。
 * @return list<array{id:string,label:string,url:string}>
 */
if (!function_exists('admin_menu_collect_link_entries')) {
    function admin_menu_collect_link_entries(array $items, string $pathPrefix = ''): array {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = trim((string)($item['title'] ?? ''));
            $id = trim((string)($item['id'] ?? ''));
            $url = trim((string)($item['url'] ?? ''));
            $children = $item['children'] ?? [];
            if (!is_array($children)) {
                $children = [];
            }
            $labelBase = $pathPrefix === '' ? $title : $pathPrefix . ' › ' . $title;
            if ($children !== []) {
                $out = array_merge($out, admin_menu_collect_link_entries($children, $pathPrefix === '' ? $title : $labelBase));
                continue;
            }
            if ($id === '' || $url === '' || $url === '#') {
                continue;
            }
            $out[] = [
                'id' => $id,
                'label' => $labelBase,
                'url' => $url,
            ];
        }
        return $out;
    }
}

/**
 * @return list<string> 所有可配置 id（去重）
 */
if (!function_exists('admin_menu_all_link_ids')) {
    function admin_menu_all_link_ids(array $menuConfig): array {
        $ids = [];
        foreach (admin_menu_collect_link_entries($menuConfig) as $row) {
            $ids[$row['id']] = true;
        }
        return array_keys($ids);
    }
}

/**
 * 按允许 id 过滤菜单树。$allowedIds === null 表示不限制（全部可见）。
 * $allowedIds 为空数组表示不允许任何菜单项（仅侧栏逻辑外可再处理）。
 *
 * @param list<array> $menuConfig menu_load_config() 结果
 * @param list<string>|null $allowedIds
 * @return list<array>
 */
if (!function_exists('admin_menu_filter_config')) {
    function admin_menu_filter_config(array $menuConfig, ?array $allowedIds): array {
        if ($allowedIds === null) {
            return $menuConfig;
        }
        $set = [];
        foreach ($allowedIds as $x) {
            $x = trim((string)$x);
            if ($x !== '') {
                $set[$x] = true;
            }
        }
        $filterBranch = function (array $items) use (&$filterBranch, $set): array {
            $out = [];
            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $children = $item['children'] ?? [];
                if (!is_array($children)) {
                    $children = [];
                }
                if ($children !== []) {
                    $fc = $filterBranch($children);
                    if ($fc !== []) {
                        $copy = $item;
                        $copy['children'] = $fc;
                        $out[] = $copy;
                    }
                    continue;
                }
                $id = trim((string)($item['id'] ?? ''));
                $url = trim((string)($item['url'] ?? ''));
                if ($id !== '' && $url !== '' && $url !== '#' && isset($set[$id])) {
                    $out[] = $item;
                }
            }
            return $out;
        };
        return $filterBranch($menuConfig);
    }
}

/**
 * @return list<string>|null null = 全部可见
 */
if (!function_exists('admin_menu_decode_allowed_ids')) {
    /**
     * null：未配置或无效 → 表示「全部可见」；[]：显式配置为空 → 无菜单项。
     */
    function admin_menu_decode_allowed_ids(?string $raw): ?array {
        if ($raw === null) {
            return null;
        }
        $raw = trim((string)$raw);
        if ($raw === '') {
            return null;
        }
        $arr = json_decode($raw, true);
        if (!is_array($arr)) {
            return null;
        }
        $ids = [];
        foreach ($arr as $v) {
            $s = trim((string)$v);
            if ($s !== '') {
                $ids[] = $s;
            }
        }
        return array_values(array_unique($ids));
    }
}

if (!function_exists('admin_menu_encode_allowed_ids')) {
    /** @param list<string>|null $ids null 表示存库为全可见 */
    function admin_menu_encode_allowed_ids(?array $ids): ?string {
        if ($ids === null) {
            return null;
        }
        $ids = array_values(array_unique(array_filter(array_map('trim', $ids), static function ($s) {
            return $s !== '';
        })));
        if ($ids === []) {
            return '[]';
        }
        $j = json_encode($ids, JSON_UNESCAPED_UNICODE);
        return $j === false ? null : $j;
    }
}
