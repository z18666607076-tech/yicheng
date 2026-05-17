<?php
/** 项目封面地址：存库可能为 uploads/x.jpg，在 /admin/ 页面相对路径会错解析为 /admin/uploads/ */
function project_image_public_url($image) {
    if ($image === null || $image === '') {
        return $image;
    }
    $t = trim((string) $image);
    if ($t === '') {
        return $image;
    }
    if (preg_match('#^https?://#i', $t) || $t[0] === '/') {
        return $t;
    }
    return '/' . ltrim($t, '/');
}
