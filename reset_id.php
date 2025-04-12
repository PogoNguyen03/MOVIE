<?php
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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