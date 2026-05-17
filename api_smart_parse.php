<?php
// api_smart_parse.php - 调用 DeepSeek 进行智能识别（含公司名校正与库内匹配）
header('Content-Type: application/json; charset=utf-8');

// === 配置区 ===
$apiKey = 'sk-6e18559e352b48eca4e1c5b340938c15';
$apiUrl = 'https://api.deepseek.com/chat/completions';

$host = '127.0.0.1';
$db = 'ychf';
$dbUser = 'ychf';
$dbPass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $dbUser, $dbPass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    echo json_encode(['status' => 'error', 'msg' => '数据库连接失败']);
    exit;
}

/** 与 agent.php search_company 一致：去掉常见后缀便于模糊匹配 */
function smart_parse_normalize_company_keyword(string $s): string
{
    $s = trim($s);
    $s = preg_replace('/\s+/u', '', $s);
    $s = str_replace(['（', '）', '(', ')', '-', '_', '·', '.', '。', '，', ','], '', $s);
    $removeWords = [
        '有限责任公司', '有限公司', '股份有限公司', '集团', '公司',
        '房地产中介服务部', '房地产中介', '房地产', '房产中介', '房产',
        '中介服务部', '中介服务', '中介', '服务部', '服务',
        '博罗县', '惠州市', '惠州', '县', '市',
    ];
    $s = str_replace($removeWords, '', $s);
    return trim((string)$s);
}

/** 常见 OCR/语音识别误字：仅当原文含正确写法时才替换 */
function smart_parse_apply_confusion_fixes(string $sourceText, string $companyName): string
{
    $name = trim($companyName);
    if ($name === '') {
        return $name;
    }
    $pairs = [
        ['correct' => '房仕通', 'wrong' => ['百仕通', '百仕', '佰仕通']],
        ['correct' => '房仕', 'wrong' => ['百仕', '佰仕']],
    ];
    foreach ($pairs as $pair) {
        if (mb_strpos($sourceText, $pair['correct'], 0, 'UTF-8') === false) {
            continue;
        }
        foreach ($pair['wrong'] as $w) {
            if (mb_strpos($name, $w, 0, 'UTF-8') !== false) {
                $name = str_replace($w, $pair['correct'], $name);
            }
        }
    }
    return $name;
}

/** 从原文预提取公司/门店线索（优先于 AI 易错字段） */
function smart_parse_extract_company_hints(string $text, array $companies): array
{
    $hints = [];
    $patterns = [
        '/(?:报备公司|报备门店|门店名称|门店|经纪公司|所属公司|公司|店面|商户)[:：\s]*([^\n\r,，;；|]+)/u',
        '/(?:来自|出处)[:：\s]*([^\n\r,，;；|]+)/u',
    ];
    foreach ($patterns as $re) {
        if (preg_match_all($re, $text, $m)) {
            foreach ($m[1] as $seg) {
                $seg = trim((string)$seg);
                if ($seg !== '' && mb_strlen($seg, 'UTF-8') <= 40) {
                    $hints[] = $seg;
                }
            }
        }
    }

    foreach ($companies as $row) {
        $full = trim((string)($row['name'] ?? ''));
        $store = trim((string)($row['store_name'] ?? ''));
        foreach ([$store, $full] as $cand) {
            if ($cand === '' || mb_strlen($cand, 'UTF-8') < 2) {
                continue;
            }
            if (mb_strpos($text, $cand, 0, 'UTF-8') !== false) {
                $hints[] = $cand;
                if ($full !== '') {
                    $hints[] = $full;
                }
            }
        }
    }

    $uniq = [];
    foreach ($hints as $h) {
        $h = trim((string)$h);
        if ($h !== '' && !in_array($h, $uniq, true)) {
            $uniq[] = $h;
        }
    }
    return $uniq;
}

/** 库内模糊匹配公司（返回标准全称 + follower） */
function smart_parse_match_company(PDO $pdo, string $keyword): ?array
{
    $kw = trim($keyword);
    if ($kw === '') {
        return null;
    }
    $kwNorm = smart_parse_normalize_company_keyword($kw);
    $patternRaw = '%' . $kw . '%';
    $patternNorm = '%' . ($kwNorm !== '' ? $kwNorm : $kw) . '%';

    $sql = "SELECT name, follower, store_name
            FROM companies
            WHERE name LIKE ?
               OR (store_name IS NOT NULL AND store_name <> '' AND store_name LIKE ?)
               OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, '有限责任公司', ''), '有限公司', ''), '房地产中介服务部', ''), '房地产中介', ''), '房地产', ''), '房产中介', ''), '房产', ''), '中介服务部', ''), '中介', ''), '服务部', '') LIKE ?
            ORDER BY
                CASE
                    WHEN name = ? THEN 0
                    WHEN store_name = ? THEN 1
                    WHEN name LIKE ? THEN 2
                    WHEN store_name LIKE ? THEN 3
                    WHEN name LIKE ? THEN 4
                    ELSE 5
                END,
                CHAR_LENGTH(name) ASC
            LIMIT 10";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        $patternRaw,
        $patternRaw,
        $patternNorm,
        $kw,
        $kw,
        $patternRaw,
        $patternRaw,
        $patternNorm,
    ]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($rows)) {
        $chars = preg_split('//u', ($kwNorm !== '' ? $kwNorm : $kw), -1, PREG_SPLIT_NO_EMPTY);
        $chars = array_values(array_unique(array_filter($chars, static function ($ch) {
            return trim((string)$ch) !== '';
        })));
        if (count($chars) >= 2) {
            $charConds = [];
            $charParams = [];
            foreach ($chars as $ch) {
                $charConds[] = '(name LIKE ? OR store_name LIKE ?)';
                $charParams[] = '%' . $ch . '%';
                $charParams[] = '%' . $ch . '%';
            }
            $sql2 = 'SELECT name, follower, store_name FROM companies WHERE ' . implode(' AND ', $charConds)
                . ' ORDER BY CHAR_LENGTH(name) ASC LIMIT 10';
            $stmt2 = $pdo->prepare($sql2);
            $stmt2->execute($charParams);
            $rows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        }
    }
    if (empty($rows)) {
        return null;
    }

    $kwLower = mb_strtolower($kw, 'UTF-8');
    $best = null;
    $bestScore = -1;
    foreach ($rows as $row) {
        $name = (string)($row['name'] ?? '');
        $store = (string)($row['store_name'] ?? '');
        foreach ([$name, $store] as $n) {
            if ($n === '') {
                continue;
            }
            $nLower = mb_strtolower($n, 'UTF-8');
            $score = 0;
            if ($nLower === $kwLower) {
                $score = 100000;
            } elseif (mb_strpos($nLower, $kwLower, 0, 'UTF-8') === 0) {
                $score = 90000 - abs(mb_strlen($n, 'UTF-8') - mb_strlen($kw, 'UTF-8'));
            } elseif (mb_strpos($nLower, $kwLower, 0, 'UTF-8') !== false || mb_strpos($kwLower, $nLower, 0, 'UTF-8') !== false) {
                $score = 80000 - abs(mb_strlen($n, 'UTF-8') - mb_strlen($kw, 'UTF-8'));
            } else {
                $match = 0;
                $len = mb_strlen($kw, 'UTF-8');
                for ($i = 0; $i < $len; $i++) {
                    $ch = mb_substr($kw, $i, 1, 'UTF-8');
                    if (mb_strpos($n, $ch, 0, 'UTF-8') !== false) {
                        $match++;
                    }
                }
                $score = $match * 100 - abs(mb_strlen($n, 'UTF-8') - mb_strlen($kw, 'UTF-8'));
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = $row;
            }
        }
    }
    return $best;
}

function smart_parse_resolve_company_name(PDO $pdo, string $sourceText, ?string $aiCompany, array $hints, array $companies): array
{
    $candidates = [];
    if (is_string($aiCompany) && trim($aiCompany) !== '') {
        $candidates[] = smart_parse_apply_confusion_fixes($sourceText, trim($aiCompany));
    }
    foreach ($hints as $h) {
        $candidates[] = smart_parse_apply_confusion_fixes($sourceText, trim((string)$h));
    }
    if (mb_strpos($sourceText, '房仕通', 0, 'UTF-8') !== false) {
        $candidates[] = '房仕通';
        $candidates[] = '房仕通地产';
    }

    $uniq = [];
    foreach ($candidates as $c) {
        $c = trim((string)$c);
        if ($c !== '' && !in_array($c, $uniq, true)) {
            $uniq[] = $c;
        }
    }

    foreach ($uniq as $kw) {
        $matched = smart_parse_match_company($pdo, $kw);
        if ($matched && !empty($matched['name'])) {
            return [
                'company_name' => $matched['name'],
                'company_store_name' => $matched['store_name'] ?? '',
                'follower' => $matched['follower'] ?? '',
                'company_matched' => true,
                'company_match_keyword' => $kw,
            ];
        }
    }

    $fallback = $uniq[0] ?? '';
    return [
        'company_name' => $fallback,
        'company_store_name' => '',
        'follower' => '',
        'company_matched' => false,
        'company_match_keyword' => $fallback,
    ];
}

if (basename($_SERVER['SCRIPT_FILENAME'] ?? '') !== basename(__FILE__)) {
    return;
}

// 1. 获取前端输入
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);
$text = trim((string)($input['text'] ?? ''));

if ($text === '') {
    echo json_encode(['status' => 'error', 'msg' => '请粘贴文本内容']);
    exit;
}

// 预加载公司（门店简称用于原文命中）
$companyRows = $pdo->query(
    "SELECT name, store_name, follower FROM companies WHERE name IS NOT NULL AND TRIM(name) <> '' ORDER BY CHAR_LENGTH(name) ASC"
)->fetchAll(PDO::FETCH_ASSOC);
$hints = smart_parse_extract_company_hints($text, $companyRows);

// 2. 构建 Prompt
$today = date('Y-m-d');
$hintLine = $hints !== [] ? implode('、', array_slice($hints, 0, 8)) : '（无）';
$prompt = <<<EOT
你是一个房地产报备信息提取助手。今天是 {$today}。
请从以下文本中提取关键信息，并严格按照 JSON 格式返回。
如果某个字段在文本中没有提及，请返回 null 或空字符串，不要杜撰。

【重要】公司名称识别规则：
1. 必须逐字照抄原文中的公司/门店名称，不要替换形似字。
2. 「房仕通」与「百仕通」是完全不同的品牌：原文是「房」字就必须写「房仕通」，绝不能写成「百仕通」。
3. 若原文出现「房仕通」「房仕通地产」，company_name 应提取为「房仕通」或「房仕通地产」，不要臆造其他品牌。
4. 常见字段：报备公司、门店、经纪公司、店面 等后面的名称即 company_name。

系统已从原文预提取的公司线索（请优先参考）：{$hintLine}

需要提取的字段及说明：
- company_name: 报备公司/门店名称（简称即可，如「房仕通地产」）
- broker_name: 业务员/经纪人姓名
- broker_phone: 业务员电话
- broker_num: 业务员人数 (整数，默认1)
- client_name: 客户姓名
- client_phone: 客户电话 (必须提取手机号)
- client_num: 客户人数 (整数，默认1)
- project_keywords: 报备的楼盘/项目名称 (数组，因为可能报备多个)
- visit_date: 带看日期 (格式 YYYY-MM-DD，如果说是"明天"请基于今天计算)
- designated_sales: 指定销售/对接人
- remark: 其他备注信息

待提取文本：
{$text}

返回格式要求：
仅返回纯 JSON 字符串，不要包含 Markdown 格式（如 ```json），不要包含其他解释。
EOT;

$data = [
    'model' => 'deepseek-chat',
    'messages' => [
        ['role' => 'system', 'content' => 'You are a helpful JSON extractor for Chinese real estate filing forms. Copy company names exactly from the source text.'],
        ['role' => 'user', 'content' => $prompt],
    ],
    'temperature' => 0.1,
    'response_format' => ['type' => 'json_object'],
];

$ch = curl_init($apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Content-Type: application/json',
    'Authorization: Bearer ' . $apiKey,
]);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error || $httpCode !== 200) {
    file_put_contents('deepseek_error.log', date('Y-m-d H:i:s') . " Error: $error | Code: $httpCode | Resp: $response\n", FILE_APPEND);
    echo json_encode(['status' => 'error', 'msg' => '智能识别服务暂时不可用']);
    exit;
}

$resultArr = json_decode($response, true);
$content = $resultArr['choices'][0]['message']['content'] ?? '';
$content = preg_replace('/^```json\s*/i', '', $content);
$content = preg_replace('/\s*```$/', '', $content);

$parsedData = json_decode($content, true);
if (!$parsedData || !is_array($parsedData)) {
    echo json_encode(['status' => 'error', 'msg' => '无法解析识别结果']);
    exit;
}

// 3. 公司名校正 + 库内匹配（解决「房仕通」被识别成「百仕通」）
$resolved = smart_parse_resolve_company_name(
    $pdo,
    $text,
    $parsedData['company_name'] ?? '',
    $hints,
    $companyRows
);
if ($resolved['company_name'] !== '') {
    $parsedData['company_name'] = $resolved['company_name'];
}
if (!empty($resolved['company_store_name'])) {
    $parsedData['company_store_name'] = $resolved['company_store_name'];
}
if (!empty($resolved['follower'])) {
    $parsedData['follower'] = $resolved['follower'];
}
$parsedData['company_matched'] = !empty($resolved['company_matched']);
if (!empty($resolved['company_match_keyword'])) {
    $parsedData['company_match_keyword'] = $resolved['company_match_keyword'];
}

echo json_encode(['status' => 'success', 'data' => $parsedData], JSON_UNESCAPED_UNICODE);
