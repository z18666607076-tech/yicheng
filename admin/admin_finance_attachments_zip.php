<?php
// admin_finance_attachments_zip.php — 将报备成交附件打成 ZIP 下载（仅站点根目录内可读文件）
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('HTTP/1.1 401 Unauthorized');
    header('Content-Type: text/plain; charset=utf-8');
    exit('未登录');
}

$host = '127.0.0.1';
$db = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('无效 id');
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('数据库错误');
}

$st = $pdo->prepare('SELECT attachments FROM filings WHERE id = ? LIMIT 1');
$st->execute([$id]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    exit('报备不存在');
}

$urls = [];
$raw = trim((string)($row['attachments'] ?? ''));
if ($raw !== '') {
    foreach (explode(',', $raw) as $p) {
        $p = trim($p);
        if ($p !== '') {
            $urls[] = $p;
        }
    }
}

$docRoot = realpath(dirname(__DIR__));
if ($docRoot === false || !is_dir($docRoot)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('站点根路径不可用');
}

/**
 * @return string|null 本地可读文件绝对路径
 */
function attachment_zip_local_path(string $docRoot, string $url): ?string
{
    $u = trim($url);
    if ($u === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $u)) {
        $path = parse_url($u, PHP_URL_PATH);
        if (!is_string($path) || $path === '' || $path === '/') {
            return null;
        }
    } else {
        $path = $u;
        if ($path[0] !== '/') {
            $path = '/' . $path;
        }
    }
    if (strpos($path, '..') !== false) {
        return null;
    }
    $full = $docRoot . $path;
    $rp = realpath($full);
    if ($rp === false || !is_file($rp) || !is_readable($rp)) {
        return null;
    }
    $docNorm = rtrim(str_replace('\\', '/', $docRoot), '/');
    $fileNorm = str_replace('\\', '/', $rp);
    if (strpos($fileNorm, $docNorm . '/') !== 0 && $fileNorm !== $docNorm) {
        return null;
    }
    return $rp;
}

if (!class_exists('ZipArchive')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('服务器未启用 ZIP 扩展');
}

$pairs = [];
$skipped = [];
foreach ($urls as $i => $u) {
    $local = attachment_zip_local_path($docRoot, $u);
    if ($local === null) {
        $skipped[] = $u;
        continue;
    }
    $pairs[] = ['path' => $local, 'index' => $i + 1];
}

if ($pairs === []) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    exit('没有可打包的本地文件（外链或路径不存在时请逐个打开保存）');
}

$zip = new ZipArchive();
$tmp = tempnam(sys_get_temp_dir(), 'att');
if ($tmp === false) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('临时文件创建失败');
}
@unlink($tmp);
$tmpZip = $tmp . '.zip';
if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ZIP 创建失败');
}

$usedNames = [];
foreach ($pairs as $item) {
    $base = basename($item['path']);
    if ($base === '' || $base === '.' || $base === '..') {
        $base = 'file_' . $item['index'];
    }
    $name = sprintf('%02d_%s', $item['index'], $base);
    if (isset($usedNames[$name])) {
        $ext = pathinfo($base, PATHINFO_EXTENSION);
        $stem = pathinfo($base, PATHINFO_FILENAME);
        $name = sprintf('%02d_%s_%d.%s', $item['index'], $stem, $item['index'], $ext ?: 'bin');
    }
    $usedNames[$name] = true;
    $zip->addFile($item['path'], $name);
}

if ($skipped !== []) {
    $zip->addFromString(
        '_部分未打包说明.txt',
        "以下附件未写入 ZIP（外链或非本站可读路径），请在浏览器中单独打开保存：\n\n" . implode("\n", $skipped) . "\n"
    );
}

$zip->close();

$size = @filesize($tmpZip);
if ($size === false) {
    @unlink($tmpZip);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('ZIP 读取失败');
}

$asciiName = 'filing-' . $id . '-attachments.zip';
$utf8Name = '报备' . $id . '-成交附件.zip';
$utf8Enc = rawurlencode($utf8Name);

header('Content-Type: application/zip');
header('Content-Length: ' . (string)$size);
header(
    'Content-Disposition: attachment; filename="' . $asciiName . '"; filename*=UTF-8\'\'' . $utf8Enc
);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

readfile($tmpZip);
@unlink($tmpZip);
exit;
