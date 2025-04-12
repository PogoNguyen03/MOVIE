<?php
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get records with vod_sub information
    $stmt = $pdo->query("SELECT vod_id, vod_name, vod_sub FROM mac_vod LIMIT 5");
    
    echo "Checking vod_sub field in mac_vod table:\n";
    echo "========================================\n\n";
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['vod_id']}\n";
        echo "Name: {$row['vod_name']}\n";
        echo "Sub/Slug: " . ($row['vod_sub'] ? $row['vod_sub'] : '(empty)') . "\n";
        echo "----------------------------------------\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 