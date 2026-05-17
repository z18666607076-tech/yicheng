<?php
/**
 * 案场工作台 staff.php「效能摘要」：与 channel_efficiency / analytics_project 口径一致的聚合 SQL
 */
declare(strict_types=1);

require_once __DIR__ . '/../admin/includes/channel_targets_lib.php';

function staff_digest_metric_int_keys(): array
{
    return channel_efficiency_metric_int_keys();
}

function staff_digest_company_norm_key(string $alias = 'f'): string
{
    $c = "{$alias}.company_name";
    return "NULLIF(TRIM(BOTH ' ' FROM REPLACE(REPLACE(REPLACE(TRIM(IFNULL({$c},'')), CHAR(9), ' '), UNHEX('C2A0'), ' '), UNHEX('E38080'), ' ')), '')";
}

function staff_digest_follower_bucket_sql(string $alias = 'f'): string
{
    $a = $alias;
    return "CASE 
        WHEN TRIM(IFNULL({$a}.follower,'')) = '' THEN '__POOL__'
        WHEN TRIM({$a}.follower) IN ('公池','公共池') THEN '__POOL__'
        ELSE TRIM({$a}.follower)
    END";
}

function staff_digest_follower_display(string $fk): string
{
    if ($fk === '__POOL__') {
        return '公池';
    }
    return $fk;
}

function staff_digest_zero_int_metrics(array $keys): array
{
    $o = [];
    foreach ($keys as $k) {
        $o[$k] = 0;
    }
    return $o;
}

function staff_digest_apply_rates(array &$actual): void
{
    $bc = (int)($actual['broker_count'] ?? 0);
    $vbc = (int)($actual['visit_broker_count'] ?? 0);
    $vc = (int)($actual['visit_count'] ?? 0);
    $dc = (int)($actual['deal_count'] ?? 0);
    $sc = (int)($actual['store_count'] ?? 0);
    $actual['store_avg_report'] = $sc > 0 ? round($bc / $sc, 2) : '—';
    $actual['report_to_visit_rate'] = $bc > 0 ? round($vbc / $bc * 100, 1) . '%' : '—';
    $actual['avg_visit_batches_per_broker'] = $vbc > 0 ? round($vc / $vbc, 2) : '—';
    $actual['deal_conversion_rate'] = $vc > 0 ? round($dc / $vc * 100, 1) . '%' : '—';
}

function staff_digest_empty_actual(array $metricIntKeys): array
{
    $a = staff_digest_zero_int_metrics($metricIntKeys);
    staff_digest_apply_rates($a);
    $a['store_visit_distinct'] = 0;
    $a['store_deal_distinct'] = 0;
    $a['client_report_distinct'] = 0;
    $a['client_visit_distinct'] = 0;
    $a['client_deal_distinct'] = 0;
    $a['deal_broker_count'] = 0;
    return $a;
}

/** @param array<string, mixed> $row */
function staff_digest_row_from_aggregate_row(array $row, array $metricIntKeys): array
{
    $actual = staff_digest_zero_int_metrics($metricIntKeys);
    foreach ($metricIntKeys as $k) {
        $actual[$k] = (int)($row[$k] ?? 0);
    }
    staff_digest_apply_rates($actual);
    $actual['store_visit_distinct'] = (int)($row['store_visit_distinct'] ?? 0);
    $actual['store_deal_distinct'] = (int)($row['store_deal_distinct'] ?? 0);
    $actual['client_report_distinct'] = (int)($row['client_report_distinct'] ?? 0);
    $actual['client_visit_distinct'] = (int)($row['client_visit_distinct'] ?? 0);
    $actual['client_deal_distinct'] = (int)($row['client_deal_distinct'] ?? 0);
    $actual['deal_broker_count'] = (int)($row['deal_broker_count'] ?? 0);
    return $actual;
}

function staff_digest_select_metrics_sql(string $companyKey, string $bucketExpr = ''): string
{
    $fk = $bucketExpr !== '' ? "{$bucketExpr} AS fk,\n        " : '';
    return "{$fk}COUNT(f.id) AS sort_weight,
        COUNT(DISTINCT {$companyKey}) AS store_count,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_visit_distinct,
        COUNT(DISTINCT CASE WHEN f.status = 4 AND {$companyKey} IS NOT NULL THEN {$companyKey} END) AS store_deal_distinct,
        COUNT(DISTINCT CASE
            WHEN TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> ''
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status >= 2 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS visit_broker_count,
        COUNT(DISTINCT CASE
            WHEN f.status = 4 AND (TRIM(IFNULL(f.broker_phone,'')) <> '' OR TRIM(IFNULL(f.broker_name,'')) <> '')
            THEN CONCAT_WS(0x1F, TRIM(IFNULL(f.broker_phone,'')), TRIM(IFNULL(f.broker_name,'')))
        END) AS deal_broker_count,
        SUM(CASE WHEN f.status >= 2 THEN 1 ELSE 0 END) AS visit_count,
        SUM(CASE WHEN f.status = 4 THEN 1 ELSE 0 END) AS deal_count,
        COUNT(DISTINCT CASE WHEN TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_report_distinct,
        COUNT(DISTINCT CASE WHEN f.status >= 2 AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_visit_distinct,
        COUNT(DISTINCT CASE WHEN f.status = 4 AND TRIM(IFNULL(f.client_phone,'')) <> '' THEN f.client_phone END) AS client_deal_distinct";
}

/**
 * @return array<string, array{actual: array<string, mixed>}>
 */
function staff_digest_fetch_follower_map(PDO $pdo, string $scopeSql, array $scopeParams, string $start, string $end, array $metricIntKeys): array
{
    $companyKey = staff_digest_company_norm_key('f');
    $bucket = staff_digest_follower_bucket_sql('f');
    $sel = staff_digest_select_metrics_sql($companyKey, $bucket);
    $sql = "SELECT {$sel}
        FROM filings f
        LEFT JOIN projects p ON f.project_id = p.id
        LEFT JOIN companies c ON f.company_name = c.name
        WHERE ({$scopeSql}) AND DATE(f.created_at) BETWEEN ? AND ?
        GROUP BY fk
        ORDER BY sort_weight DESC
        LIMIT 18";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scopeParams, [$start, $end]));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $fk = (string)($row['fk'] ?? '');
        if ($fk === '') {
            continue;
        }
        $out[$fk] = ['actual' => staff_digest_row_from_aggregate_row($row, $metricIntKeys)];
    }
    return $out;
}

/**
 * @return array<string, mixed>
 */
function staff_digest_fetch_scope_aggregate(PDO $pdo, string $scopeSql, array $scopeParams, string $start, string $end, array $metricIntKeys): array
{
    $companyKey = staff_digest_company_norm_key('f');
    $sel = staff_digest_select_metrics_sql($companyKey, '');
    $sql = "SELECT {$sel}
        FROM filings f
        LEFT JOIN projects p ON f.project_id = p.id
        LEFT JOIN companies c ON f.company_name = c.name
        WHERE ({$scopeSql}) AND DATE(f.created_at) BETWEEN ? AND ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scopeParams, [$start, $end]));
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    return staff_digest_row_from_aggregate_row($row, $metricIntKeys);
}

/**
 * @return array<int, array{name: string, sort: int, actual: array<string, mixed>}>
 */
function staff_digest_fetch_project_map(PDO $pdo, string $scopeSql, array $scopeParams, string $start, string $end, array $metricIntKeys): array
{
    $companyKey = staff_digest_company_norm_key('f');
    $sel = staff_digest_select_metrics_sql($companyKey, '');
    $sql = "SELECT
        p.id AS project_id,
        MAX(COALESCE(NULLIF(TRIM(p.name), ''), CONCAT('项目#', p.id))) AS project_name,
        {$sel}
        FROM filings f
        INNER JOIN projects p ON p.id = f.project_id
        LEFT JOIN companies c ON f.company_name = c.name
        WHERE ({$scopeSql}) AND DATE(f.created_at) BETWEEN ? AND ?
        GROUP BY p.id
        ORDER BY sort_weight DESC
        LIMIT 15";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge($scopeParams, [$start, $end]));
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $id = (int)($row['project_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $out[$id] = [
            'name' => (string)($row['project_name'] ?? ('项目#' . $id)),
            'sort' => (int)($row['sort_weight'] ?? 0),
            'actual' => staff_digest_row_from_aggregate_row($row, $metricIntKeys),
        ];
    }
    return $out;
}

function staff_digest_shift_yoy(string $start, string $end): array
{
    $ds = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if (!$ds || !$de) {
        return [$start, $end];
    }
    return [
        $ds->modify('-1 year')->format('Y-m-d'),
        $de->modify('-1 year')->format('Y-m-d'),
    ];
}

function staff_digest_shift_mom(string $start, string $end): array
{
    $ds = DateTimeImmutable::createFromFormat('Y-m-d', $start);
    $de = DateTimeImmutable::createFromFormat('Y-m-d', $end);
    if (!$ds || !$de) {
        return [$start, $end];
    }
    $days = $ds->diff($de)->days + 1;
    $momEnd = $ds->modify('-1 day');
    $momStart = $momEnd->modify('-' . ($days - 1) . ' days');
    return [$momStart->format('Y-m-d'), $momEnd->format('Y-m-d')];
}

/** 与 admin/compete_list.php 中 get_allowed_compete_project_ids 一致（案场 agent 会话） */
function staff_digest_allowed_compete_project_ids(PDO $pdo, int $agentId, string $agentRole): ?array
{
    if ($agentId <= 0) {
        return [];
    }
    if (in_array($agentRole, ['admin', 'finance'], true)) {
        return null;
    }
    require_once __DIR__ . '/compete_list_permissions.php';
    $stmt = $pdo->prepare('SELECT role, roles_json FROM agents WHERE id = ? AND is_deleted = 0 LIMIT 1');
    $stmt->execute([$agentId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && compete_list_agent_row_has_backend_role($row)) {
        return null;
    }
    $allAgents = $pdo->query('SELECT id, username, manager_id FROM agents WHERE is_deleted = 0')->fetchAll(PDO::FETCH_ASSOC);
    $usernameById = [];
    $childrenMap = [];
    foreach ($allAgents as $a) {
        $id = (int)($a['id'] ?? 0);
        if ($id <= 0) {
            continue;
        }
        $usernameById[$id] = (string)($a['username'] ?? '');
        $mid = (int)($a['manager_id'] ?? 0);
        if (!isset($childrenMap[$mid])) {
            $childrenMap[$mid] = [];
        }
        $childrenMap[$mid][] = $id;
    }
    $nameScope = [];
    $collectNames = function ($id) use (&$collectNames, &$childrenMap, &$usernameById, &$nameScope) {
        if (isset($nameScope[$id])) {
            return $nameScope[$id];
        }
        $names = [];
        if (isset($usernameById[$id]) && trim($usernameById[$id]) !== '') {
            $names[] = trim($usernameById[$id]);
        }
        foreach ($childrenMap[$id] ?? [] as $cid) {
            $names = array_merge($names, $collectNames($cid));
        }
        $names = array_values(array_unique($names));
        $nameScope[$id] = $names;
        return $names;
    };
    $scopeNames = $collectNames($agentId);
    $ids = [];
    if (!empty($scopeNames)) {
        $namePlaceholders = implode(',', array_fill(0, count($scopeNames), '?'));
        $sql = "SELECT cp.id
                FROM compete_projects cp
                INNER JOIN projects p ON p.name = cp.name
                WHERE cp.status = 1 AND p.is_deleted = 0 AND p.manager_name IN ($namePlaceholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($scopeNames);
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    $sql = "SELECT cp.id
            FROM compete_projects cp
            INNER JOIN projects p ON p.name = cp.name
            INNER JOIN agent_projects ap ON ap.project_id = p.id
            WHERE cp.status = 1 AND ap.agent_id = ?";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$agentId]);
    $ids = array_merge($ids, $stmt->fetchAll(PDO::FETCH_COLUMN));
    return array_values(array_unique(array_map('intval', $ids)));
}

/**
 * @return array{totals: array{visits:int,deals:int,locks:int}, rows: list<array{project_id:int,project_name:string,visits:int,deals:int,locks:int}>}
 */
function staff_digest_compete_summary(PDO $pdo, int $agentId, string $agentRole, string $start, string $end): array
{
    $allowed = staff_digest_allowed_compete_project_ids($pdo, $agentId, $agentRole);
    if (is_array($allowed) && $allowed === []) {
        return ['totals' => ['visits' => 0, 'deals' => 0, 'locks' => 0], 'rows' => []];
    }
    $params = [];
    $sql = 'SELECT cd.project_id, cp.name AS project_name,
            SUM(COALESCE(cd.visits,0)) AS v, SUM(COALESCE(cd.deals,0)) AS d, SUM(COALESCE(cd.locks,0)) AS l
            FROM compete_data cd
            INNER JOIN compete_projects cp ON cp.id = cd.project_id AND cp.status = 1
            WHERE cd.date BETWEEN ? AND ?';
    $params[] = $start;
    $params[] = $end;
    if (is_array($allowed)) {
        $ph = implode(',', array_fill(0, count($allowed), '?'));
        $sql .= " AND cd.project_id IN ($ph)";
        foreach ($allowed as $pid) {
            $params[] = $pid;
        }
    }
    $sql .= ' GROUP BY cd.project_id, cp.name ORDER BY (SUM(COALESCE(cd.visits,0)) + SUM(COALESCE(cd.deals,0))) DESC LIMIT 20';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    $tv = $td = $tl = 0;
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $v = (int)($r['v'] ?? 0);
        $d = (int)($r['d'] ?? 0);
        $l = (int)($r['l'] ?? 0);
        $tv += $v;
        $td += $d;
        $tl += $l;
        $rows[] = [
            'project_id' => (int)($r['project_id'] ?? 0),
            'project_name' => (string)($r['project_name'] ?? ''),
            'visits' => $v,
            'deals' => $d,
            'locks' => $l,
        ];
    }
    return [
        'totals' => ['visits' => $tv, 'deals' => $td, 'locks' => $tl],
        'rows' => $rows,
    ];
}

/**
 * @param array{sql: string, params: array} $scope
 * @return array<string, mixed>
 */
function staff_digest_build_payload(PDO $pdo, array $scope, string $rangeStart, string $rangeEnd, int $agentId, string $agentRole): array
{
    $metricIntKeys = staff_digest_metric_int_keys();
    [$yoyStart, $yoyEnd] = staff_digest_shift_yoy($rangeStart, $rangeEnd);
    [$momStart, $momEnd] = staff_digest_shift_mom($rangeStart, $rangeEnd);

    $effKeys = [
        'store_count',
        'store_avg_report',
        'report_to_visit_rate',
        'avg_visit_batches_per_broker',
        'deal_conversion_rate',
    ];

    $curF = staff_digest_fetch_follower_map($pdo, $scope['sql'], $scope['params'], $rangeStart, $rangeEnd, $metricIntKeys);
    $yoyF = staff_digest_fetch_follower_map($pdo, $scope['sql'], $scope['params'], $yoyStart, $yoyEnd, $metricIntKeys);
    $momF = staff_digest_fetch_follower_map($pdo, $scope['sql'], $scope['params'], $momStart, $momEnd, $metricIntKeys);

    $fks = array_keys($curF);
    usort($fks, static function (string $a, string $b) use ($curF): int {
        $pool = static fn(string $x) => $x === '__POOL__' ? 1 : 0;
        $pa = $pool($a);
        $pb = $pool($b);
        if ($pa !== $pb) {
            return $pa <=> $pb;
        }
        $sa = $curF[$a]['actual']['broker_count'] ?? 0;
        $sb = $curF[$b]['actual']['broker_count'] ?? 0;
        return $sb <=> $sa;
    });

    $empty = staff_digest_empty_actual($metricIntKeys);
    $channelRows = [];
    foreach ($fks as $fk) {
        $channelRows[] = [
            'follower_key' => $fk,
            'name' => staff_digest_follower_display($fk),
            'current' => $curF[$fk]['actual'],
            'yoy' => $yoyF[$fk]['actual'] ?? $empty,
            'mom' => $momF[$fk]['actual'] ?? $empty,
        ];
    }

    $totCur = staff_digest_fetch_scope_aggregate($pdo, $scope['sql'], $scope['params'], $rangeStart, $rangeEnd, $metricIntKeys);
    $totYoy = staff_digest_fetch_scope_aggregate($pdo, $scope['sql'], $scope['params'], $yoyStart, $yoyEnd, $metricIntKeys);
    $totMom = staff_digest_fetch_scope_aggregate($pdo, $scope['sql'], $scope['params'], $momStart, $momEnd, $metricIntKeys);
    $channelRows[] = [
        'follower_key' => '__TOTAL__',
        'name' => '合计',
        'current' => $totCur,
        'yoy' => $totYoy,
        'mom' => $totMom,
    ];

    $curP = staff_digest_fetch_project_map($pdo, $scope['sql'], $scope['params'], $rangeStart, $rangeEnd, $metricIntKeys);
    $yoyP = staff_digest_fetch_project_map($pdo, $scope['sql'], $scope['params'], $yoyStart, $yoyEnd, $metricIntKeys);
    $momP = staff_digest_fetch_project_map($pdo, $scope['sql'], $scope['params'], $momStart, $momEnd, $metricIntKeys);

    $projectList = [];
    foreach ($curP as $pid => $pack) {
        $projectList[] = [
            'project_id' => $pid,
            'name' => $pack['name'],
            'current' => $pack['actual'],
            'yoy' => ($yoyP[$pid]['actual'] ?? $empty),
            'mom' => ($momP[$pid]['actual'] ?? $empty),
        ];
    }

    $compete = staff_digest_compete_summary($pdo, $agentId, $agentRole, $rangeStart, $rangeEnd);

    return [
        'efficiency_dimension_keys' => $effKeys,
        'metric_labels' => channel_efficiency_metric_table_labels(),
        'periods' => [
            'current' => ['start' => $rangeStart, 'end' => $rangeEnd, 'label' => '本月（与上方统计区间一致）'],
            'yoy' => ['start' => $yoyStart, 'end' => $yoyEnd, 'label' => '同比'],
            'mom' => ['start' => $momStart, 'end' => $momEnd, 'label' => '环比'],
        ],
        'channel' => ['rows' => $channelRows],
        'projects' => ['rows' => $projectList],
        'compete' => $compete,
    ];
}
