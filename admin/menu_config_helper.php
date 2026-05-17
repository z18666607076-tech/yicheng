<?php
// admin/menu_config_helper.php - 后台菜单配置读写助手

if (!function_exists('menu_config_file_path')) {
    function menu_config_file_path() {
        return __DIR__ . '/menu_config.json';
    }
}

if (!function_exists('menu_default_config')) {
    function menu_default_config() {
        return [
            [
                'id' => 'decision',
                'title' => '决策中心',
                'icon' => 'fas fa-chart-pie',
                'url' => '',
                'view' => '',
                'children' => [
                    ['id' => 'dashboard', 'title' => '驾驶舱', 'icon' => 'fas fa-chart-pie', 'url' => 'admin.php?v=dashboard', 'view' => 'dashboard'],
                    ['id' => 'statistics_demo', 'title' => '数据 demo 版', 'icon' => 'fas fa-chart-pie', 'url' => 'admin_statistics.php', 'view' => 'statistics'],
                    ['id' => 'analytics', 'title' => '启动维度分析', 'icon' => 'fas fa-chart-pie', 'url' => 'analytics.php', 'view' => 'statistics'],
                ],
            ],
            [
                'id' => 'biz',
                'title' => '业务管理',
                'icon' => 'fas fa-briefcase',
                'url' => '',
                'view' => '',
                'children' => [
                    ['id' => 'filings', 'title' => '报备审核', 'icon' => 'fas fa-list-check', 'url' => 'admin_filings.php', 'view' => 'filings'],
                    ['id' => 'followup_records', 'title' => '跟进记录', 'icon' => 'fas fa-file-alt', 'url' => 'followup_records.php', 'view' => 'followup_records'],
                    ['id' => 'finance', 'title' => '佣金结算', 'icon' => 'fas fa-file-invoice-dollar', 'url' => 'admin_finance.php', 'view' => 'finance'],
                    ['id' => 'compete_list', 'title' => '数据竞对列表', 'icon' => 'fas fa-list', 'url' => 'compete_list.php', 'view' => 'compete_list'],
                ],
            ],
            [
                'id' => 'resource_center',
                'title' => '资源中心',
                'icon' => 'fas fa-boxes',
                'url' => '',
                'view' => '',
                'children' => [
                    ['id' => 'projects', 'title' => '项目库', 'icon' => 'fas fa-city', 'url' => 'admin_projects.php', 'view' => 'projects'],
                ],
            ],
            [
                'id' => 'resource_manage',
                'title' => '资源管理',
                'icon' => 'fas fa-sitemap',
                'url' => '',
                'view' => '',
                'children' => [
                    ['id' => 'companies', 'title' => '商户明细', 'icon' => 'fas fa-store', 'url' => 'admin_companies.php', 'view' => 'companies'],
                    ['id' => 'agent_import', 'title' => '经纪人管理', 'icon' => 'fas fa-user-tie', 'url' => 'agent_import.php', 'view' => 'agent_import'],
                    ['id' => 'compete_admin', 'title' => '竞对项目管理', 'icon' => 'fas fa-chart-pie', 'url' => 'compete_admin.php', 'view' => 'compete_admin'],
                ],
            ],
            [
                'id' => 'system',
                'title' => '系统管理',
                'icon' => 'fas fa-cog',
                'url' => '',
                'view' => '',
                'children' => [
                    ['id' => 'agents', 'title' => '内部架构', 'icon' => 'fas fa-sitemap', 'url' => 'admin_agents.php', 'view' => 'agents'],
                    ['id' => 'admin_manage', 'title' => '管理员管理', 'icon' => 'fas fa-user-shield', 'url' => 'admin_manage_admins.php', 'view' => 'admin_manage'],
                    ['id' => 'import', 'title' => '数据导入', 'icon' => 'fas fa-file-import', 'url' => 'import.php', 'view' => 'import'],
                    ['id' => 'batch_import', 'title' => '批量报备导入', 'icon' => 'fas fa-file-import', 'url' => 'batch_import.php', 'view' => 'batch_import'],
                    ['id' => 'followup_summaries', 'title' => '跟进概述管理', 'icon' => 'fas fa-list', 'url' => 'admin_followup_summaries.php', 'view' => 'followup_summaries'],
                    ['id' => 'menu_builder', 'title' => '菜单配置', 'icon' => 'fas fa-sliders-h', 'url' => 'menu_builder.php', 'view' => 'menu_builder'],
                ],
            ],
        ];
    }
}

if (!function_exists('menu_normalize_item')) {
    function menu_infer_view_from_url($url) {
        $url = trim((string)$url);
        if ($url === '') return '';
        $parts = parse_url($url);
        if (!is_array($parts)) return '';
        $query = $parts['query'] ?? '';
        if ($query !== '') {
            parse_str($query, $queryArgs);
            if (!empty($queryArgs['v'])) {
                return trim((string)$queryArgs['v']);
            }
        }
        $path = trim((string)($parts['path'] ?? ''));
        if ($path === '') return '';
        $basename = basename($path);
        if ($basename === '') return '';
        return preg_replace('/\.php$/i', '', $basename);
    }
}

if (!function_exists('menu_default_icon_by_level')) {
    function menu_default_icon_by_level($depth) {
        if ((int)$depth <= 0) return 'fas fa-folder';
        if ((int)$depth === 1) return 'fas fa-circle';
        return 'fas fa-angle-right';
    }
}

if (!function_exists('menu_normalize_item')) {
    function menu_normalize_item($item, $isTopLevel = false, $depth = 0) {
        $title = trim((string)($item['title'] ?? ''));
        if ($title === '') return null;
        $url = trim((string)($item['url'] ?? ''));
        $view = trim((string)($item['view'] ?? ''));
        if ($view === '') {
            $view = menu_infer_view_from_url($url);
        }
        $normalized = [
            'id' => trim((string)($item['id'] ?? '')) ?: ('m_' . substr(md5($title . microtime(true) . rand(1, 9999)), 0, 10)),
            'title' => $title,
            'icon' => trim((string)($item['icon'] ?? '')) ?: menu_default_icon_by_level($depth),
            'url' => $url,
            'view' => $view,
            'children' => [],
        ];
        $children = $item['children'] ?? [];
        if (is_array($children)) {
            foreach ($children as $child) {
                $childItem = menu_normalize_item($child, false, $depth + 1);
                if ($childItem !== null) {
                    $normalized['children'][] = $childItem;
                }
            }
        }
        if (!$isTopLevel && $normalized['url'] === '' && count($normalized['children']) === 0) {
            return null;
        }
        return $normalized;
    }
}

if (!function_exists('menu_normalize_config')) {
    function menu_normalize_config($config) {
        if (!is_array($config)) return [];
        $result = [];
        foreach ($config as $item) {
            $normalized = menu_normalize_item($item, true);
            if ($normalized !== null) $result[] = $normalized;
        }
        return $result;
    }
}

if (!function_exists('menu_load_config')) {
    function menu_load_config() {
        $file = menu_config_file_path();
        if (!file_exists($file)) {
            return menu_default_config();
        }
        $raw = @file_get_contents($file);
        if ($raw === false || trim($raw) === '') {
            return menu_default_config();
        }
        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return menu_default_config();
        }
        $normalized = menu_normalize_config($data);
        return count($normalized) > 0 ? $normalized : menu_default_config();
    }
}

if (!function_exists('menu_save_config')) {
    function menu_save_config($config) {
        $normalized = menu_normalize_config($config);
        if (count($normalized) === 0) return [false, '菜单不能为空'];
        $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) return [false, '菜单数据编码失败'];
        $ok = @file_put_contents(menu_config_file_path(), $json, LOCK_EX);
        if ($ok === false) return [false, '保存失败，请检查写入权限'];
        return [true, 'success'];
    }
}

