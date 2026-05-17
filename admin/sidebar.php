<?php
require_once __DIR__ . '/menu_config_helper.php';
require_once __DIR__ . '/includes/admin_menu_permissions.php';
$__menuConfig = menu_load_config();
if (isset($_SESSION['admin_id'])) {
    try {
        $host = '127.0.0.1';
        $db = 'ychf';
        $user = 'ychf';
        $pass = 'rjX5DESSbGXbewfa';
        $charset = 'utf8mb4';
        $__mp_pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
        $__mp_pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        admin_menu_ensure_column($__mp_pdo);
        $__st = $__mp_pdo->prepare('SELECT admin_menu_allow_ids FROM agents WHERE id = ? AND is_deleted = 0 LIMIT 1');
        $__st->execute([(int)$_SESSION['admin_id']]);
        $__row = $__st->fetch(PDO::FETCH_ASSOC);
        if ($__row && array_key_exists('admin_menu_allow_ids', $__row)) {
            $__menuConfig = admin_menu_filter_config(
                $__menuConfig,
                admin_menu_decode_allowed_ids($__row['admin_menu_allow_ids'] !== null ? (string)$__row['admin_menu_allow_ids'] : null)
            );
        }
    } catch (Throwable $e) {
        // 数据库异常时仍显示完整菜单，避免无法操作后台
    }
}
$__escape = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
$__welcomeName = trim((string)($_SESSION['admin_name'] ?? ''));
if ($__welcomeName === '') {
    $__welcomeName = '管理员';
}

/**
 * 当前页是否与菜单链接一致（用于侧栏高亮，不依赖各页 Vue 的 view 变量）。
 * 支持 admin.php?v=xxx 与任意 *.php；与 menu_config 里的 view 字段无关。
 */
$__sidebarUrlIsCurrent = function (string $url): bool {
    $url = trim($url);
    if ($url === '' || $url === '#') {
        return false;
    }
    $script = basename(str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '')));
    if ($script === '') {
        return false;
    }
    $parts = @parse_url($url);
    if (!is_array($parts)) {
        $parts = [];
    }
    $path = (string)($parts['path'] ?? '');
    if ($path === '' && strpos($url, '://') === false) {
        $path = preg_replace('/[?#].*$/', '', $url);
    }
    $linkFile = basename(str_replace('\\', '/', $path));
    if ($linkFile === '') {
        return false;
    }
    if (strcasecmp($linkFile, $script) !== 0) {
        return false;
    }
    $query = isset($parts['query']) ? (string)$parts['query'] : '';
    if ($query === '' && strpos($url, '?') !== false) {
        parse_str((string)substr($url, strpos($url, '?') + 1), $q);
    } else {
        parse_str($query, $q);
    }
    if (isset($q['v'])) {
        $have = isset($_GET['v']) ? trim((string)$_GET['v']) : '';
        return strcasecmp(trim((string)$q['v']), $have) === 0;
    }
    return true;
};

$__navActiveClass = ' bg-blue-600 text-white shadow-lg';

$__renderMenuItems = function ($items, $level = 0) use (&$__renderMenuItems, $__escape, $__sidebarUrlIsCurrent, $__navActiveClass) {
    if (!is_array($items) || empty($items)) return;
    $containerClass = $level === 0 ? 'px-2 py-1.5' : 'mt-1 pl-4';
    $itemClass = 'nav-item flex items-center rounded-lg cursor-pointer hover:text-white transition';
    if ($level === 0) {
        $itemClass .= ' px-4 py-2.5 text-base font-semibold';
    } elseif ($level === 1) {
        $itemClass .= ' px-3 py-2 text-sm';
    } else {
        $itemClass .= ' px-3 py-1.5 text-xs';
    }
    echo '<div class="' . $__escape($containerClass) . '">';
    foreach ($items as $idx => $item) {
        $children = is_array($item['children'] ?? null) ? $item['children'] : [];
        $url = trim((string)($item['url'] ?? ''));
        if ($url === '') $url = '#';
        $mtClass = $idx > 0 ? ' mt-1' : '';
        $active = ($url !== '#' && $__sidebarUrlIsCurrent($url)) ? $__navActiveClass : '';
        echo '<a href="' . $__escape($url) . '" class="' . $__escape($itemClass . $mtClass . $active) . '">';
        echo '<i class="' . $__escape($item['icon'] ?? 'fas fa-circle') . ' w-5 mr-3"></i> ' . $__escape($item['title'] ?? '');
        echo '</a>';
        if (!empty($children)) {
            $lvl = $level + 1;
            $lvl = $lvl > 2 ? 2 : $lvl;
            $__renderMenuItems($children, $lvl);
        }
    }
    echo '</div>';
};
?>
<aside class="fixed inset-y-0 left-0 z-50 w-64 bg-slate-900 text-slate-400 flex flex-col shadow-xl transition-transform duration-300 max-md:-translate-x-full md:static md:translate-x-0 flex-shrink-0" :class="sidebarOpen ? 'max-md:!translate-x-0' : ''">
    <div class="border-b border-slate-800">
        <div class="h-14 flex items-center justify-between gap-2 px-4 sm:px-6 font-bold text-white text-lg tracking-wider">
            <div class="flex items-center min-w-0">
                <i class="fas fa-building text-blue-500 mr-2 shrink-0"></i>
                <span class="truncate">易城好房Pro</span>
            </div>
            <button type="button" @click="sidebarOpen = false" class="md:hidden shrink-0 p-2 rounded-lg text-slate-400 hover:text-white hover:bg-slate-800 transition" aria-label="关闭菜单">
                <i class="fas fa-times text-lg" aria-hidden="true"></i>
            </button>
        </div>
        <div class="px-6 pb-3 text-sm text-slate-300 leading-snug">你好，<?= $__escape($__welcomeName) ?></div>
    </div>
    <nav class="flex-1 p-4 space-y-2 overflow-y-auto">
        <?php foreach ($__menuConfig as $__group): ?>
            <?php $__children = is_array($__group['children'] ?? null) ? $__group['children'] : []; ?>
            <?php if (!empty($__children)): ?>
                <div class="text-sm font-semibold text-slate-300 px-4 mt-6 mb-2"><?= $__escape($__group['title'] ?? '') ?></div>
                <?php $__renderMenuItems($__children, 0); ?>
            <?php else: ?>
                <?php
                $__gUrl = trim((string)($__group['url'] ?? ''));
                if ($__gUrl === '') {
                    $__gUrl = '#';
                }
                $__gActive = ($__gUrl !== '#' && $__sidebarUrlIsCurrent($__gUrl)) ? $__navActiveClass : '';
                ?>
                <a href="<?= $__escape($__gUrl) ?>" class="nav-item flex items-center px-4 py-3 rounded-lg cursor-pointer hover:text-white transition<?= $__escape($__gActive) ?>">
                    <i class="<?= $__escape($__group['icon'] ?? 'fas fa-circle') ?> w-5 mr-3"></i> <?= $__escape($__group['title'] ?? '') ?>
                </a>
            <?php endif; ?>
        <?php endforeach; ?>
        <div class="mt-8 border-t border-slate-800 pt-4">
            <a href="logout.php" class="nav-item flex items-center px-4 py-3 rounded-lg cursor-pointer hover:text-white transition text-red-400 hover:text-red-300">
                <i class="fas fa-sign-out-alt w-5 mr-3"></i> 登出系统
            </a>
        </div>
    </nav>
</aside>
<div v-show="sidebarOpen" @click="sidebarOpen = false" class="fixed inset-0 z-40 bg-slate-900/50 md:hidden" aria-hidden="true"></div>