<?php
require_once 'config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
);

try {
    // Count records
    $stmt = $pdo->query("SELECT COUNT(*) FROM mac_vod");
    $count = $stmt->fetchColumn();
    
    echo "Number of records in mac_vod table: $count\n";
    
    if ($count > 0) {
        // Get the first and last ID
        $stmt = $pdo->query("SELECT MIN(vod_id) as min_id, MAX(vod_id) as max_id FROM mac_vod");
        $ids = $stmt->fetch(PDO::FETCH_ASSOC);
        
        echo "ID range: {$ids['min_id']} to {$ids['max_id']}\n";
        
        // Get the next auto_increment value
        $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
        $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
        $nextId = $tableInfo['Auto_increment'];
        
        echo "Next ID will be: $nextId\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 