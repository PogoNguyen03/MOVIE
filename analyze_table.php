<?php
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
    
    echo "Analyzing table mac_vod...\n";
    
    // Analyze table
    $pdo->exec("ANALYZE TABLE mac_vod");
    
    // Get table status
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "Table status after analysis:\n";
    echo "==========================\n";
    echo "Name: {$tableInfo['Name']}\n";
    echo "Engine: {$tableInfo['Engine']}\n";
    echo "Rows: {$tableInfo['Rows']}\n";
    echo "Auto increment: {$tableInfo['Auto_increment']}\n";
    
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