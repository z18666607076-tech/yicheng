<?php
// admin_finance_attachments.php — 成交合同附件左右浏览（单页仅加载当前一张图，避免列表卡顿）
session_start();
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$host = '127.0.0.1';
$db = 'ychf';
$user = 'ychf';
$pass = 'rjX5DESSbGXbewfa';
$charset = 'utf8mb4';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) {
    http_response_code(500);
    exit('数据库错误');
}

$id = (int)($_GET['id'] ?? 0);
$urls = [];
$title = '成交附件';
$metaClient = '';

if ($id > 0) {
    $st = $pdo->prepare(
        'SELECT f.attachments, f.client_name, p.name AS project_name
         FROM filings f
         LEFT JOIN projects p ON f.project_id = p.id
         WHERE f.id = ? LIMIT 1'
    );
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $raw = trim((string)($row['attachments'] ?? ''));
        if ($raw !== '') {
            foreach (explode(',', $raw) as $p) {
                $p = trim($p);
                if ($p !== '') {
                    $urls[] = $p;
                }
            }
        }
        $pn = trim((string)($row['project_name'] ?? ''));
        $cn = trim((string)($row['client_name'] ?? ''));
        $metaClient = $cn;
        $title = '成交附件 · 报备 #' . $id;
        if ($pn !== '') {
            $title .= ' · ' . $pn;
        }
        if ($cn !== '') {
            $title .= ' · ' . $cn;
        }
    }
}

$urlsJson = json_encode($urls, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($urlsJson === false) {
    $urlsJson = '[]';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
    </style>
</head>
<body class="min-h-screen bg-slate-900 text-slate-100 flex flex-col">
    <header class="shrink-0 flex flex-wrap items-center justify-between gap-3 px-4 py-3 border-b border-slate-700 bg-slate-800/90">
        <div class="min-w-0">
            <h1 class="text-sm font-bold text-white truncate"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1>
            <p id="counter" class="text-xs text-slate-400 mt-0.5 tabular-nums"></p>
        </div>
        <div class="flex items-center gap-2 shrink-0">
            <a href="admin_finance.php" class="text-xs text-slate-300 hover:text-white px-3 py-1.5 rounded-lg border border-slate-600 hover:bg-slate-700 transition">返回佣金结算</a>
        </div>
    </header>

    <main class="flex-1 flex flex-col min-h-0 p-4">
        <div id="empty" class="hidden flex-1 flex flex-col items-center justify-center text-slate-500 gap-2">
            <i class="fas fa-folder-open text-4xl opacity-50"></i>
            <p class="text-sm">该报备暂无成交附件</p>
        </div>

        <div id="viewer" class="hidden flex-1 flex flex-col min-h-0 max-w-5xl mx-auto w-full gap-4">
            <div class="flex-1 min-h-0 flex items-center justify-center bg-black/40 rounded-xl border border-slate-700 overflow-hidden relative">
                <img id="mainImg" alt="" class="hidden max-w-full max-h-[70vh] object-contain select-none">
                <div id="nonImgPanel" class="hidden p-8 text-center max-w-md">
                    <p class="text-sm text-slate-300 mb-3">当前为文件链接（非图片预览）</p>
                    <a id="nonImgLink" href="#" target="_blank" rel="noopener" class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-violet-600 hover:bg-violet-500 text-white text-sm font-bold transition">
                        <i class="fas fa-external-link-alt"></i> 新窗口打开
                    </a>
                </div>
            </div>
            <div class="flex flex-wrap items-center justify-center gap-3 shrink-0">
                <button type="button" id="btnPrev" class="px-5 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-sm font-bold border border-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    <i class="fas fa-chevron-left mr-2"></i>上一张
                </button>
                <button type="button" id="btnNext" class="px-5 py-2.5 rounded-xl bg-slate-700 hover:bg-slate-600 text-white text-sm font-bold border border-slate-600 disabled:opacity-40 disabled:cursor-not-allowed transition">
                    下一张<i class="fas fa-chevron-right ml-2"></i>
                </button>
                <button type="button" id="btnSaveAll" class="px-5 py-2.5 rounded-xl bg-emerald-700 hover:bg-emerald-600 text-white text-sm font-bold border border-emerald-600 disabled:opacity-50 disabled:cursor-not-allowed transition">
                    <i class="fas fa-file-archive mr-2"></i>一键保存
                </button>
            </div>
            <p class="text-center text-[11px] text-slate-500">提示：键盘 ← → 切换</p>
        </div>
    </main>

    <script>
(function () {
    const urls = <?= $urlsJson ?>;
    const emptyEl = document.getElementById('empty');
    const viewerEl = document.getElementById('viewer');
    const mainImg = document.getElementById('mainImg');
    const nonImgPanel = document.getElementById('nonImgPanel');
    const nonImgLink = document.getElementById('nonImgLink');
    const counterEl = document.getElementById('counter');
    const btnPrev = document.getElementById('btnPrev');
    const btnNext = document.getElementById('btnNext');
    const btnSaveAll = document.getElementById('btnSaveAll');
    const filingId = <?= (int)$id ?>;

    function absUrl(u) {
        const s = String(u || '').trim();
        if (!s) return '';
        if (/^https?:\/\//i.test(s)) return s;
        if (s.startsWith('/')) return window.location.origin + s;
        return window.location.origin + '/' + s;
    }
    function isImageUrl(u) {
        return /\.(jpe?g|png|gif|webp)(\?|#|$)/i.test(String(u || ''));
    }

    if (!urls.length) {
        emptyEl.classList.remove('hidden');
        return;
    }

    viewerEl.classList.remove('hidden');
    let idx = 0;

    function setCounter() {
        counterEl.textContent = (idx + 1) + ' / ' + urls.length;
        btnPrev.disabled = idx <= 0;
        btnNext.disabled = idx >= urls.length - 1;
    }

    function show() {
        const u = urls[idx];
        const full = absUrl(u);
        if (isImageUrl(u)) {
            mainImg.classList.remove('hidden');
            nonImgPanel.classList.add('hidden');
            mainImg.onload = function () { mainImg.onload = null; };
            mainImg.src = full;
        } else {
            mainImg.classList.add('hidden');
            mainImg.removeAttribute('src');
            nonImgPanel.classList.remove('hidden');
            nonImgLink.href = full;
        }
        setCounter();
    }

    btnPrev.addEventListener('click', function () {
        if (idx > 0) { idx--; show(); }
    });
    btnNext.addEventListener('click', function () {
        if (idx < urls.length - 1) { idx++; show(); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft') { e.preventDefault(); btnPrev.click(); }
        if (e.key === 'ArrowRight') { e.preventDefault(); btnNext.click(); }
    });

    btnSaveAll.addEventListener('click', function () {
        if (!filingId) {
            return;
        }
        btnSaveAll.disabled = true;
        const zipUrl = 'admin_finance_attachments_zip.php?id=' + encodeURIComponent(String(filingId));
        fetch(zipUrl, { credentials: 'same-origin' })
            .then(function (r) {
                if (!r.ok) {
                    return r.text().then(function (t) {
                        throw new Error((t && t.trim()) || ('打包失败（' + r.status + '）'));
                    });
                }
                return r.blob();
            })
            .then(function (blob) {
                const a = document.createElement('a');
                const href = URL.createObjectURL(blob);
                a.href = href;
                a.download = '报备' + filingId + '-成交附件.zip';
                a.rel = 'noopener';
                document.body.appendChild(a);
                a.click();
                a.remove();
                setTimeout(function () { URL.revokeObjectURL(href); }, 2000);
            })
            .catch(function (err) {
                alert(err.message || '下载失败');
            })
            .finally(function () {
                btnSaveAll.disabled = false;
            });
    });

    show();
})();
    </script>
</body>
</html>
