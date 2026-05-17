<?php
/**
 * 渠道人员 roster + 渠道月度目标表（channel_monthly_targets）
 */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/agent_roles.php';

if (!function_exists('channel_efficiency_metric_int_keys')) {
    /** 与 channel_efficiency.php 表格可填目标列顺序一致（仅整数入库） */
    function channel_efficiency_metric_int_keys(): array
    {
        return [
            'store_count',
            'broker_count',
            'visit_broker_count',
            'visit_count',
            'deal_count',
        ];
    }
}

if (!function_exists('channel_monthly_targets_metric_labels')) {
    /** 每月目标表头（与 channel_efficiency_metric_int_keys 顺序一致） */
    function channel_monthly_targets_metric_labels(): array
    {
        return [
            'store_count' => '报备门店数',
            'broker_count' => '报备经纪人数',
            'visit_broker_count' => '带看经纪人数',
            'visit_count' => '带看组数',
            'deal_count' => '成交套数',
        ];
    }
}

if (!function_exists('channel_efficiency_metric_table_labels')) {
    /** 渠道效能表全部列名（顺序与 METRIC_KEYS 一致） */
    function channel_efficiency_metric_table_labels(): array
    {
        return [
            'store_count' => '报备门店数',
            'broker_count' => '报备经纪人数',
            'store_avg_report' => '店均人',
            'visit_broker_count' => '带看经纪人数',
            'report_to_visit_rate' => '报转带',
            'visit_count' => '带看组数',
            'avg_visit_batches_per_broker' => '人均带',
            'deal_conversion_rate' => '成交转化率',
            'deal_count' => '成交套数',
        ];
    }
}

if (!function_exists('channel_efficiency_load_roster')) {
    function channel_efficiency_load_roster(PDO $pdo): array
    {
        $chRole = agent_sql_has_role('a', 'channel');
        $sqlDept = "SELECT MIN(a.id) AS agent_id, a.username AS username
            FROM agents a
            INNER JOIN departments d ON d.id = a.department_id
            WHERE a.is_deleted = 0
              AND {$chRole}
              AND (d.name LIKE '%渠道人员%' OR d.name = '渠道人员')
              AND TRIM(a.username) NOT IN ('张婷婷', '公池', '公共池')
            GROUP BY a.username
            ORDER BY MIN(a.id) ASC";
        $stmt = $pdo->query($sqlDept);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        if (!is_array($rows) || count($rows) === 0) {
            $sqlFb = "SELECT MIN(a.id) AS agent_id, a.username AS username
                FROM agents a
                WHERE a.is_deleted = 0
                  AND {$chRole}
                  AND TRIM(a.username) NOT IN ('张婷婷', '公池', '公共池')
                GROUP BY a.username
                ORDER BY MIN(a.id) ASC";
            $rows = $pdo->query($sqlFb)->fetchAll(PDO::FETCH_ASSOC);
        }
        $roster = [];
        foreach ($rows as $r) {
            $name = trim((string)($r['username'] ?? ''));
            if ($name === '') {
                continue;
            }
            $roster[] = [
                'follower_key' => $name,
                'name' => $name,
                'agent_id' => isset($r['agent_id']) ? (int)$r['agent_id'] : null,
            ];
        }
        // 具体渠道人员在前，公池、合计（合计在 channel_efficiency.php 中追加）在后
        $roster[] = ['follower_key' => '__POOL__', 'name' => '公池', 'agent_id' => null];
        return $roster;
    }
}

if (!function_exists('channel_monthly_targets_ensure_table')) {
    function channel_monthly_targets_ensure_table(PDO $pdo): void
    {
        $sql = "CREATE TABLE IF NOT EXISTS channel_monthly_targets (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            follower_key VARCHAR(191) NOT NULL COMMENT 'username 或 __POOL__ 等',
            target_year SMALLINT UNSIGNED NOT NULL,
            target_month TINYINT UNSIGNED NOT NULL COMMENT '1-12',
            metric_key VARCHAR(64) NOT NULL,
            target_value INT NOT NULL DEFAULT 0,
            updated_by INT UNSIGNED NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_channel_target (follower_key, target_year, target_month, metric_key),
            KEY idx_ym (target_year, target_month)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $pdo->exec($sql);
    }
}

if (!function_exists('channel_monthly_targets_fetch_map')) {
    /**
     * @return array<string, array<string, int>> follower_key => metric_key => value
     */
    function channel_monthly_targets_fetch_map(PDO $pdo, int $year, int $month): array
    {
        channel_monthly_targets_ensure_table($pdo);
        $stmt = $pdo->prepare(
            'SELECT follower_key, metric_key, target_value FROM channel_monthly_targets WHERE target_year = ? AND target_month = ?'
        );
        $stmt->execute([$year, $month]);
        $out = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $fk = (string)$row['follower_key'];
            $mk = (string)$row['metric_key'];
            if (!isset($out[$fk])) {
                $out[$fk] = [];
            }
            $out[$fk][$mk] = (int)$row['target_value'];
        }
        return $out;
    }
}
