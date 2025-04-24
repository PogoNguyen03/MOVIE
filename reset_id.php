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
    // Get current auto_increment value
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentAutoIncrement = $tableInfo['Auto_increment'];
    
    echo "Current auto_increment value: $currentAutoIncrement\n";
    
    // Reset auto_increment to 1
    $pdo->exec("ALTER TABLE mac_vod AUTO_INCREMENT = 1");
    
    // Verify the change
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $newAutoIncrement = $tableInfo['Auto_increment'];
    
    echo "Auto_increment value after reset: $newAutoIncrement\n";
    echo "ID counter has been reset successfully!\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 