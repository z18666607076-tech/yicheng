<?php
// upload.php - 负责文件上传处理
header('Content-Type: application/json');

// 允许上传的目录（相对站点根目录）
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);

/** 转为站点根路径，避免在 /admin/ 等子目录页面里相对路径被解析到 /admin/uploads/ */
function upload_public_url($relativePath) {
    return '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
}

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // 1. 通用文件上传处理
    if (isset($_FILES['file'])) {
        $file = $_FILES['file'];
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = uniqid() . '.' . $ext;
        $targetPath = $uploadDir . $filename;

        if (move_uploaded_file($file['tmp_name'], $targetPath)) {
            
            // === 如果是 OCR 请求 (这里接入真实 OCR API) ===
            if ($action === 'ocr') {
                // TODO: 这里替换为 百度/腾讯 OCR SDK 代码
                // $result = BaiduOCR::recognize($targetPath);
                
                // 模拟返回：假装识别到了图片里的文字
                echo json_encode([
                    'status' => 'success',
                    'type'   => 'ocr',
                    'data'   => [
                        'text' => "识别结果：\n客户：李建国\n电话：13900139000\n备注：意向三房，周末带看", // 模拟OCR出来的文本
                        'raw_url' => upload_public_url($targetPath)
                    ]
                ]);
            } 
            // === 普通附件上传 ===
            else {
                echo json_encode([
                    'status' => 'success',
                    'url'    => upload_public_url($targetPath),
                    'msg'    => '上传成功'
                ]);
            }
        } else {
            echo json_encode(['status' => 'error', 'msg' => '文件移动失败']);
        }
    } else {
        echo json_encode(['status' => 'error', 'msg' => '没有接收到文件']);
    }
}
?>