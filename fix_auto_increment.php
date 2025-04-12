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
    
    // Get the maximum ID
    $stmt = $pdo->query("SELECT MAX(vod_id) as max_id FROM mac_vod");
    $maxId = $stmt->fetch(PDO::FETCH_ASSOC)['max_id'];
    
    echo "Maximum ID in the table: $maxId\n";
    
    // Calculate the next ID
    $nextId = $maxId + 1;
    
    // Reset auto_increment to the next available ID
    $pdo->exec("ALTER TABLE mac_vod AUTO_INCREMENT = $nextId");
    
    // Verify the change
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $newAutoIncrement = $tableInfo['Auto_increment'];
    
    echo "Auto_increment value after reset: $newAutoIncrement\n";
    echo "ID counter has been reset successfully!\n";
    
    // Get all records
    $stmt = $pdo->query("SELECT vod_id, vod_name FROM mac_vod ORDER BY vod_id");
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "\nAll records:\n";
    echo "============\n";
    foreach ($movies as $movie) {
        echo "ID: {$movie['vod_id']}, Name: {$movie['vod_name']}\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 