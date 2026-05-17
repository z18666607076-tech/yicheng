<?php
// Add new fields to filings table for transaction entry
$host = '127.0.0.1'; $db = 'ychf'; $user = 'ychf'; $pass = 'rjX5DESSbGXbewfa'; $charset = 'utf8mb4';
try { 
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=$charset", $user, $pass); 
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    die("DB Error: " . $e->getMessage()); 
}

$columns = [
    'subscriber_name' => "VARCHAR(100) DEFAULT NULL COMMENT '认购人姓名'",
    'subscribed_room_number' => "VARCHAR(50) DEFAULT NULL COMMENT '认购房号'",
    'transaction_area' => "DECIMAL(10,2) DEFAULT NULL COMMENT '成交面积'",
    'salesperson' => "VARCHAR(100) DEFAULT NULL COMMENT '销售人员'",
    'subscription_phone_full' => "VARCHAR(20) DEFAULT NULL COMMENT '认购电话全号'",
    'subscription_date' => "DATE DEFAULT NULL COMMENT '认购日期'",
    'transaction_recorder' => "VARCHAR(100) DEFAULT NULL COMMENT '成交录入人'"
];

foreach ($columns as $columnName => $columnDefinition) {
    try {
        $checkColumn = $pdo->query("SHOW COLUMNS FROM filings LIKE '$columnName'");
        if ($checkColumn->rowCount() == 0) {
            $sql = "ALTER TABLE filings ADD COLUMN $columnName $columnDefinition";
            $pdo->exec($sql);
            echo "Added column: $columnName\n";
        } else {
            echo "Column already exists: $columnName\n";
        }
    } catch (PDOException $e) {
        echo "Error adding column $columnName: " . $e->getMessage() . "\n";
    }
}

echo "Migration completed!\n";
