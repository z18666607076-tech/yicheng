<?php
/**
 * PHP环境探针
 * 显示服务器和PHP的详细信息
 */
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHP环境探针</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background-color: #f0f0f0;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
            background-color: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0,0,0,0.1);
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #3498db;
            padding-bottom: 10px;
        }
        h2 {
            color: #555;
            margin-top: 30px;
            border-left: 4px solid #3498db;
            padding-left: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
        }
        th {
            background-color: #f2f2f2;
            font-weight: bold;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .info-box {
            background-color: #e8f4f8;
            padding: 15px;
            border-radius: 5px;
            margin-top: 15px;
        }
        .warning {
            color: #d35400;
        }
        .success {
            color: #27ae60;
        }
        .error {
            color: #c0392b;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>PHP环境探针</h1>
        
        <!-- 基本信息 -->
        <h2>基本信息</h2>
        <table>
            <tr>
                <th>项目</th>
                <th>值</th>
            </tr>
            <tr>
                <td>PHP版本</td>
                <td><?php echo PHP_VERSION; ?></td>
            </tr>
            <tr>
                <td>服务器软件</td>
                <td><?php echo $_SERVER['SERVER_SOFTWARE']; ?></td>
            </tr>
            <tr>
                <td>服务器IP</td>
                <td><?php echo $_SERVER['SERVER_ADDR']; ?></td>
            </tr>
            <tr>
                <td>服务器端口</td>
                <td><?php echo $_SERVER['SERVER_PORT']; ?></td>
            </tr>
            <tr>
                <td>文档根目录</td>
                <td><?php echo $_SERVER['DOCUMENT_ROOT']; ?></td>
            </tr>
            <tr>
                <td>当前脚本</td>
                <td><?php echo $_SERVER['SCRIPT_FILENAME']; ?></td>
            </tr>
            <tr>
                <td>请求方法</td>
                <td><?php echo $_SERVER['REQUEST_METHOD']; ?></td>
            </tr>
            <tr>
                <td>当前时间</td>
                <td><?php echo date('Y-m-d H:i:s'); ?></td>
            </tr>
            <tr>
                <td>时区</td>
                <td><?php echo date_default_timezone_get(); ?></td>
            </tr>
        </table>

        <!-- PHP配置信息 -->
        <h2>PHP配置信息</h2>
        <table>
            <tr>
                <th>配置项</th>
                <th>值</th>
            </tr>
            <tr>
                <td>max_execution_time</td>
                <td><?php echo ini_get('max_execution_time'); ?> 秒</td>
            </tr>
            <tr>
                <td>memory_limit</td>
                <td><?php echo ini_get('memory_limit'); ?></td>
            </tr>
            <tr>
                <td>post_max_size</td>
                <td><?php echo ini_get('post_max_size'); ?></td>
            </tr>
            <tr>
                <td>upload_max_filesize</td>
                <td><?php echo ini_get('upload_max_filesize'); ?></td>
            </tr>
            <tr>
                <td>display_errors</td>
                <td><?php echo ini_get('display_errors') ? '开启' : '关闭'; ?></td>
            </tr>
            <tr>
                <td>error_reporting</td>
                <td><?php echo error_reporting(); ?></td>
            </tr>
            <tr>
                <td>short_open_tag</td>
                <td><?php echo ini_get('short_open_tag') ? '开启' : '关闭'; ?></td>
            </tr>
            <tr>
                <td>allow_url_fopen</td>
                <td><?php echo ini_get('allow_url_fopen') ? '开启' : '关闭'; ?></td>
            </tr>
        </table>

        <!-- 已加载的扩展 -->
        <h2>已加载的PHP扩展</h2>
        <div class="info-box">
            <?php
            $extensions = get_loaded_extensions();
            echo implode(', ', $extensions);
            ?>
        </div>

        <!-- 系统环境变量 -->
        <h2>系统环境变量</h2>
        <table>
            <tr>
                <th>变量名</th>
                <th>值</th>
            </tr>
            <?php
            foreach ($_ENV as $key => $value) {
                echo "<tr><td>$key</td><td>$value</td></tr>";
            }
            ?>
        </table>

        <!-- PHP信息（完整） -->
        <h2>完整PHP信息</h2>
        <div class="info-box">
            <p>点击查看： <a href="<?php echo $_SERVER['PHP_SELF']; ?>?phpinfo=1" target="_blank">完整phpinfo()</a></p>
        </div>
    </div>
</body>
</html>

<?php
// 如果请求包含phpinfo参数，则显示完整的phpinfo
if (isset($_GET['phpinfo']) && $_GET['phpinfo'] == 1) {
    phpinfo();
}
?>