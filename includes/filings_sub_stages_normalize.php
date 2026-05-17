<?php
/**
 * 将 filings.sub_stages 规范为白名单内、去重、固定顺序的逗号分隔串。
 * 防止前端重复 push、脏字符串或未来阶段增多时超出 VARCHAR 长度。
 */
function filings_normalize_sub_stages_csv(string $raw): string
{
    static $order = ['deposit', 'lock', 'sign', 'subscription', 'contract', 'biz_confirm', 'refund_submit'];
    $allowed = array_flip($order);
    $parts = preg_split('/[,，、\s]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    $seen = [];
    foreach ($parts as $p) {
        $k = trim((string)$p);
        if ($k === '' || !isset($allowed[$k])) {
            continue;
        }
        $seen[$k] = true;
    }
    $out = [];
    foreach ($order as $k) {
        if (!empty($seen[$k])) {
            $out[] = $k;
        }
    }
    return implode(',', $out);
}
