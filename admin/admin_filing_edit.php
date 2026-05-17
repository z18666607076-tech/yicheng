<?php
// admin_filing_edit.php - 报备详情/修改
session_start();

// === 登录验证 ===
if (!isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$host='127.0.0.1'; $db='ychf'; $user='ychf'; $pass='rjX5DESSbGXbewfa';
try{ $pdo=new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4",$user,$pass); }catch(PDOException $e){die("DB Error");}

$id = $_GET['id'] ?? 0;
$action = $_GET['action'] ?? '';

// [API] 获取详情
if ($action == 'get_detail') {
    header('Content-Type: application/json; charset=utf-8');
    $stmt = $pdo->prepare("SELECT f.*, p.name as project_name FROM filings f LEFT JOIN projects p ON f.project_id = p.id WHERE f.id = ?");
    $stmt->execute([$id]);
    echo json_encode($stmt->fetch(PDO::FETCH_ASSOC)); exit;
}

// [API] 保存修改
if ($action == 'save') {
    header('Content-Type: application/json; charset=utf-8');
    $d = $_POST;
    try {
        $toNull = function ($v) {
            return (isset($v) && trim((string)$v) !== '') ? $v : null;
        };
        $toNumberOrZero = function ($v) {
            return (isset($v) && trim((string)$v) !== '') ? (float)$v : 0;
        };
        $toIntOrZero = function ($v) {
            return (isset($v) && trim((string)$v) !== '') ? (int)$v : 0;
        };
        $toDateTimeOrNull = function ($v) {
            $v = trim((string)($v ?? ''));
            if ($v === '') return null;
            $v = str_replace('T', ' ', $v);
            if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';
            return $v;
        };

        // 先取旧值，用于生成字段级修改日志
        $oldStmt = $pdo->prepare("SELECT * FROM filings WHERE id = ?");
        $oldStmt->execute([$id]);
        $old = $oldStmt->fetch(PDO::FETCH_ASSOC);
        if (!$old) {
            echo json_encode(['status'=>'error', 'msg'=>'保存失败: 记录不存在']); exit;
        }

        $new = [
            'client_name' => $d['client_name'] ?? '',
            'client_phone' => $d['client_phone'] ?? '',
            'client_num' => $toIntOrZero($d['client_num'] ?? null),
            'broker_name' => $d['broker_name'] ?? '',
            'broker_phone' => $d['broker_phone'] ?? '',
            'broker_num' => $toIntOrZero($d['broker_num'] ?? null),
            'follower' => $d['follower'] ?? '',
            'visit_time' => $toDateTimeOrNull($d['visit_time'] ?? null),
            'designated_sales' => $d['designated_sales'] ?? '',
            'remark' => $d['remark'] ?? '',
            'status' => $toIntOrZero($d['status'] ?? null),
            'company_name' => $d['company_name'] ?? '',
            'deal_price' => $toNumberOrZero($d['deal_price'] ?? null),
            'commission_amount' => $toNumberOrZero($d['commission_amount'] ?? null),
            'commission_status' => $toIntOrZero($d['commission_status'] ?? null),
            'subscriber_name' => $toNull($d['subscriber_name'] ?? null),
            'subscribed_room_number' => $toNull($d['subscribed_room_number'] ?? null),
            'transaction_area' => $toNull($d['transaction_area'] ?? null),
            'salesperson' => $toNull($d['salesperson'] ?? null),
            'subscription_phone_full' => $toNull($d['subscription_phone_full'] ?? null),
            'subscription_date' => $toNull($d['subscription_date'] ?? null),
            'transaction_recorder' => $toNull($d['transaction_recorder'] ?? null)
        ];

        // 更新主表
        $sql = "UPDATE filings SET 
                client_name=?, client_phone=?, client_num=?, 
                broker_name=?, broker_phone=?, broker_num=?,
                follower=?, visit_time=?, designated_sales=?, remark=?, 
                status=?, company_name=?,
                deal_price=?, commission_amount=?, commission_status=?,
                subscriber_name=?, subscribed_room_number=?, transaction_area=?,
                salesperson=?, subscription_phone_full=?, subscription_date=?, transaction_recorder=?
                WHERE id=?";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $new['client_name'], $new['client_phone'], $new['client_num'],
            $new['broker_name'], $new['broker_phone'], $new['broker_num'],
            $new['follower'], $new['visit_time'], $new['designated_sales'], $new['remark'],
            $new['status'], $new['company_name'],
            $new['deal_price'], $new['commission_amount'], $new['commission_status'],
            $new['subscriber_name'], $new['subscribed_room_number'], $new['transaction_area'],
            $new['salesperson'], $new['subscription_phone_full'], $new['subscription_date'], $new['transaction_recorder'],
            $id
        ]);
        
        // 记录日志
        $fieldLabels = [
            'client_name' => '客户姓名',
            'client_phone' => '客户电话',
            'client_num' => '客户人数',
            'broker_name' => '业务员姓名',
            'broker_phone' => '业务员电话',
            'broker_num' => '业务员人数',
            'follower' => '渠道',
            'visit_time' => '到访时间',
            'designated_sales' => '指定销售',
            'remark' => '备注',
            'status' => '状态',
            'company_name' => '所属公司',
            'deal_price' => '认购总价',
            'commission_amount' => '佣金金额',
            'commission_status' => '佣金状态',
            'subscriber_name' => '认购人信息',
            'subscribed_room_number' => '认购房号',
            'transaction_area' => '购房面积',
            'salesperson' => '销售人员',
            'subscription_phone_full' => '认购电话全号',
            'subscription_date' => '认购日期',
            'transaction_recorder' => '成交录入人'
        ];
        $changes = [];
        foreach ($fieldLabels as $k => $label) {
            $oldVal = array_key_exists($k, $old) ? $old[$k] : null;
            $newVal = array_key_exists($k, $new) ? $new[$k] : null;
            $oldNorm = ($oldVal === null || $oldVal === '') ? '空' : (string)$oldVal;
            $newNorm = ($newVal === null || $newVal === '') ? '空' : (string)$newVal;
            if ($oldNorm !== $newNorm) {
                $changes[] = $label . ': ' . $oldNorm . ' -> ' . $newNorm;
            }
        }
        $__an = trim((string)($_SESSION['admin_name'] ?? ''));
        if ($__an === '') $__an = '管理员';
        $__an = str_replace(["\r", "\n", '[', ']'], ['', '', '(', ')'], $__an);
        $__admTag = "[管理员·{$__an}] ";
        $log = "\n" . date('Y-m-d H:i') . " {$__admTag}修改信息/状态变更为: " . $new['status'];
        if (!empty($changes)) {
            $log .= "\n" . date('Y-m-d H:i') . " {$__admTag}具体修改: " . implode('；', $changes);
        } else {
            $log .= "\n" . date('Y-m-d H:i') . " {$__admTag}具体修改: 无字段变化";
        }
        if ($new['status'] == 4) {
            $log .= "\n" . date('Y-m-d H:i') . " {$__admTag}设置成交信息 - 总价: " . $new['deal_price'] . " 佣金: " . $new['commission_amount'];
        }
        $pdo->prepare("UPDATE filings SET status_log = CONCAT(IFNULL(status_log,''), ?) WHERE id=?")->execute([$log, $id]);
        
        echo json_encode(['status'=>'success']); exit;
    } catch (Throwable $e) {
        echo json_encode(['status'=>'error', 'msg'=>'保存失败: ' . $e->getMessage()]); exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>报备详情</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
</head>
<body class="bg-gray-50 p-8">
<div id="app" class="max-w-2xl mx-auto bg-white rounded-xl shadow-lg p-8">
    <div class="flex justify-between items-center mb-6 border-b pb-4">
        <h1 class="text-2xl font-bold text-slate-800">报备详情 #{{ form.id }}</h1>
        <div class="text-sm text-gray-500">当前项目：{{ form.project_name }}</div>
    </div>

    <div class="space-y-6">
        <div class="bg-blue-50 p-4 rounded-lg flex items-center gap-4">
            <label class="font-bold text-blue-800">当前状态:</label>
            <select v-model="form.status" class="bg-white border border-blue-200 rounded px-3 py-1 text-sm outline-none">
                <option value="0">待审核</option>
                <option value="1">有效/待接待</option>
                <option value="2">已到访</option>
                <option value="3">已下定</option>
                <option value="4">已成交</option>
                <option value="5">已失效</option>
            </select>
            <span class="text-xs text-blue-400">* 修改状态会自动记录日志</span>
        </div>

        <div class="grid grid-cols-2 gap-4">
            <div><label class="text-xs text-gray-400 block mb-1">客户姓名</label><input v-model="form.client_name" class="w-full border rounded p-2"></div>
            <div><label class="text-xs text-gray-400 block mb-1">客户人数</label><input v-model="form.client_num" class="w-full border rounded p-2"></div>
            <div class="col-span-2"><label class="text-xs text-gray-400 block mb-1">到访时间</label><input v-model="form.visit_time" type="datetime-local" class="w-full border rounded p-2"></div>
        </div>
        <div class="mb-4 space-y-3 border-t border-gray-100 pt-4 mt-4">
            <div>
                <label class="text-xs font-bold text-slate-500 block mb-2">客户号码 (必填)</label>
                <input v-model="form.client_phone" type="text" inputmode="numeric" autocomplete="tel" class="w-full bg-slate-50 border border-gray-200 rounded-lg p-3 text-sm outline-none font-mono tracking-wide" placeholder="请输入客户号码" @blur="onClientPhoneBlur">
                <p class="text-[10px] text-slate-400 mt-1">默认展示前三位 + **** + 后四位；修改请清空后输入完整号码，失焦后自动脱敏</p>
            </div>
        </div>

        <div class="border-t pt-4 grid grid-cols-2 gap-4">
            <div><label class="text-xs text-gray-400 block mb-1">业务员姓名</label><input v-model="form.broker_name" class="w-full border rounded p-2"></div>
            <div><label class="text-xs text-gray-400 block mb-1">业务员电话</label><input v-model="form.broker_phone" class="w-full border rounded p-2"></div>
            <div><label class="text-xs text-gray-400 block mb-1">所属公司</label><input v-model="form.company_name" class="w-full border rounded p-2"></div>
            <div><label class="text-xs text-gray-400 block mb-1">渠道</label><input v-model="form.follower" class="w-full border rounded p-2" placeholder="请输入渠道"></div>
            <div><label class="text-xs text-gray-400 block mb-1">指定销售</label><input v-model="form.designated_sales" class="w-full border rounded p-2"></div>
        </div>

        <!-- 佣金结算相关字段 - 仅在已成交状态下显示 -->
        <div v-if="form.status == 4" class="border-t pt-4 bg-purple-50 p-4 rounded-lg border border-purple-100">
            <h3 class="font-bold text-purple-800 text-sm mb-3">佣金结算信息</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-purple-600 block mb-1">认购总价 (元)</label>
                    <input v-model.number="form.deal_price" type="number" class="w-full border border-purple-200 rounded p-2" placeholder="请输入认购总价">
                </div>
                <div>
                    <label class="text-xs text-purple-600 block mb-1">佣金金额 (元)</label>
                    <input v-model.number="form.commission_amount" type="number" class="w-full border border-purple-200 rounded p-2" placeholder="请输入佣金金额">
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-purple-600 block mb-1">佣金状态</label>
                    <select v-model.number="form.commission_status" class="w-full border border-purple-200 rounded p-2">
                        <option value="0">待确认业绩</option>
                        <option value="1">待发放佣金</option>
                        <option value="2">佣金已发放</option>
                    </select>
                </div>
            </div>
        </div>

        <!-- 成交详情字段 -->
        <div class="border-t pt-4 bg-green-50 p-4 rounded-lg border border-green-100">
            <h3 class="font-bold text-green-800 text-sm mb-3">成交详情信息</h3>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="text-xs text-green-600 block mb-1">认购人信息</label>
                    <input v-model="form.subscriber_name" type="text" class="w-full border border-green-200 rounded p-2" placeholder="请输入认购人信息">
                </div>
                <div>
                    <label class="text-xs text-green-600 block mb-1">认购房号</label>
                    <input v-model="form.subscribed_room_number" type="text" class="w-full border border-green-200 rounded p-2" placeholder="请输入认购房号">
                </div>
                <div>
                    <label class="text-xs text-green-600 block mb-1">购房面积 (㎡)</label>
                    <input v-model.number="form.transaction_area" type="number" step="0.01" class="w-full border border-green-200 rounded p-2" placeholder="请输入购房面积">
                </div>
                <div>
                    <label class="text-xs text-green-600 block mb-1">销售人员</label>
                    <input v-model="form.salesperson" type="text" class="w-full border border-green-200 rounded p-2" placeholder="请输入销售人员姓名">
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-green-600 block mb-1">认购电话全号（可多个）</label>
                    <input v-model="form.subscription_phone_full" type="text" inputmode="numeric" autocomplete="tel" class="w-full border border-green-200 rounded p-2 font-mono text-sm" placeholder="多个号码请用英文逗号分隔，完整号码展示">
                    <p class="text-[10px] text-green-600/70 mt-1">完整号码展示、不脱敏；多个请用英文逗号分隔</p>
                </div>
                <div>
                    <label class="text-xs text-green-600 block mb-1">认购日期</label>
                    <input v-model="form.subscription_date" type="date" class="w-full border border-green-200 rounded p-2">
                </div>
                <div class="col-span-2">
                    <label class="text-xs text-green-600 block mb-1">成交录入人</label>
                    <input v-model="form.transaction_recorder" type="text" class="w-full border border-green-200 rounded p-2" placeholder="请输入成交录入人">
                </div>
            </div>
        </div>

        <!-- 附件展示 -->
        <div class="border-t pt-4">
            <h3 class="font-bold text-gray-800 text-sm mb-3">附件文件</h3>
            <div v-if="form.attachments" class="grid grid-cols-3 gap-3">
                <div v-for="(url, index) in form.attachments.split(',').filter(x => x)" :key="index" class="relative aspect-square rounded-lg overflow-hidden border border-gray-200">
                    <img :src="url" class="w-full h-full object-cover cursor-pointer hover:opacity-90 transition" @click="openImage(url)">
                    <div class="absolute top-1 right-1 bg-white/80 rounded-full p-1 shadow-sm">
                        <span class="text-xs font-bold text-gray-600">{{ index + 1 }}</span>
                    </div>
                </div>
            </div>
            <div v-else class="text-center py-4 text-gray-400 text-xs">
                暂无附件
            </div>
        </div>

        <div class="border-t pt-4">
            <label class="text-xs text-gray-400 block mb-1">备注信息</label>
            <textarea v-model="form.remark" class="w-full border rounded p-2 h-20"></textarea>
        </div>
        
        <div class="bg-gray-50 p-4 rounded text-xs text-gray-500 max-h-40 overflow-y-auto whitespace-pre-wrap">
            <div class="font-bold mb-2">操作日志：</div>
            <div v-html="statusLogHtml"></div>
        </div>

        <div class="flex justify-end gap-4 pt-4">
            <button @click="closeWin" class="px-6 py-2 border rounded text-gray-600 hover:bg-gray-50">关闭</button>
            <button @click="save" class="px-6 py-2 bg-blue-600 text-white rounded hover:bg-blue-700 shadow-lg">保存修改</button>
        </div>
    </div>
</div>
<script>
const { createApp, ref, onMounted, computed } = Vue;
createApp({
    setup() {
        const form = ref({
            commission_status: 0 // 默认佣金状态
        });
        const rawPhones = ref({
            client_phone: '',
            broker_phone: '',
            subscription_phone_full: ''
        });
        const id = new URLSearchParams(window.location.search).get('id');
        const statusLogHtml = computed(() => {
            const raw = String(form.value.status_log || '');
            const escapeHtml = (str) => str
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
            const escaped = escapeHtml(raw);
            const linked = escaped.replace(
                /(https?:\/\/[^\s<]+|\/uploads\/[^\s<]+\.(?:jpg|jpeg|png|gif|webp))/gi,
                (match) => {
                    const href = /^https?:\/\//i.test(match) ? match : (window.location.origin + match);
                    return `<a href="${href}" target="_blank" rel="noopener noreferrer" class="text-blue-600 underline break-all">${match}</a>`;
                }
            );
            return linked.replace(/\r\n|\r|\n/g, '<br>');
        });

        /** 前三 + **** + 后四；满 7 位数字即脱敏（含 11 位与历史误存的 7 位连号） */
        const maskPhone = (phone) => {
            if (!phone) return '';
            const digits = String(phone).replace(/\D/g, '');
            if (digits.length >= 7) {
                return digits.slice(0, 3) + '****' + digits.slice(-4);
            }
            return String(phone);
        };

        const normalizePhoneForSave = (inputValue, rawValue) => {
            const text = String(inputValue || '').trim();
            if (!text) return '';
            // 若仍为脱敏展示值，沿用原始号码，避免把 * 写入数据库
            if (text.includes('*')) {
                const expected = maskPhone(rawValue);
                if (rawValue && (text === expected || text.replace(/\s/g, '') === String(expected).replace(/\s/g, ''))) {
                    return String(rawValue).replace(/\D/g, '') || String(rawValue).trim();
                }
                return String(rawValue || '').trim();
            }
            // 输入了新号码时，尽量只保留数字
            const digits = text.replace(/\D/g, '');
            return digits || text;
        };

        /** 认购电话全号：界面全号不脱敏；若误粘贴带 *，按 raw 还原为全号再落库 */
        const normalizeSubscriptionForSave = (inputValue, rawValue) => {
            const text = String(inputValue || '').trim();
            if (!text) return '';
            if (text.includes('*')) {
                return String(rawValue || '')
                    .split(/[,，]/)
                    .map((p) => p.replace(/\D/g, ''))
                    .filter(Boolean)
                    .join(',');
            }
            return text
                .split(/[,，]/)
                .map((p) => p.replace(/\D/g, ''))
                .filter(Boolean)
                .join(',');
        };

        const onClientPhoneBlur = () => {
            const t = String(form.value.client_phone || '').trim();
            if (!t) return;
            const raw = rawPhones.value.client_phone || '';
            if (t.includes('*')) {
                if (raw) form.value.client_phone = maskPhone(raw);
                return;
            }
            const d = t.replace(/\D/g, '');
            if (d.length >= 7) {
                rawPhones.value.client_phone = d.length > 11 ? d.slice(0, 11) : d;
                form.value.client_phone = maskPhone(rawPhones.value.client_phone);
            }
        };

        const loadData = async () => {
            const res = await fetch('?action=get_detail&id=' + id);
            form.value = await res.json();
            // 处理日期时间格式，供 datetime-local 控件显示到分钟
            if(form.value.visit_time) form.value.visit_time = String(form.value.visit_time).replace(' ', 'T').substring(0,16);
            // 确保佣金状态有默认值
            if(form.value.commission_status === undefined) form.value.commission_status = 0;
            // 客户手机号默认展示为脱敏格式，业务员电话保持全号展示
            rawPhones.value.client_phone = form.value.client_phone || '';
            rawPhones.value.broker_phone = form.value.broker_phone || '';
            rawPhones.value.subscription_phone_full = form.value.subscription_phone_full || '';
            form.value.client_phone = maskPhone(rawPhones.value.client_phone);
            form.value.broker_phone = rawPhones.value.broker_phone;
            form.value.subscription_phone_full = rawPhones.value.subscription_phone_full;
        };

        const save = async () => {
            try {
                const fd = new FormData();
                const payload = { ...form.value };
                payload.client_phone = normalizePhoneForSave(form.value.client_phone, rawPhones.value.client_phone);
                payload.broker_phone = normalizePhoneForSave(form.value.broker_phone, rawPhones.value.broker_phone);
                payload.subscription_phone_full = normalizeSubscriptionForSave(form.value.subscription_phone_full, rawPhones.value.subscription_phone_full);
                for (let k in payload) fd.append(k, payload[k] ?? '');

                const res = await fetch('?action=save&id=' + id, {method:'POST', body:fd});
                const text = await res.text();
                let d = null;
                try {
                    d = JSON.parse(text);
                } catch (e) {
                    alert('保存失败：返回格式异常\n' + text);
                    return;
                }
                if(d.status === 'success') { alert('保存成功'); loadData(); } else { alert(d.msg || '保存失败'); }
            } catch (e) {
                alert('保存失败：' + (e && e.message ? e.message : '网络错误'));
            }
        };

        const closeWin = () => window.close();

        const openImage = (url) => {
            window.open(url, '_blank');
        };

        onMounted(loadData);
        return { form, save, closeWin, openImage, statusLogHtml, onClientPhoneBlur };
    }
}).mount('#app');
</script>