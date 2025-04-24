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
    // Get all imported movies
    $stmt = $pdo->query("SELECT vod_id, vod_name, vod_sub, vod_content, vod_actor, vod_director, vod_duration FROM mac_vod ORDER BY vod_id");
    
    echo "Imported Movies:\n";
    echo "===============\n\n";
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['vod_id']}\n";
        echo "Name: {$row['vod_name']}\n";
        echo "Sub/Slug: {$row['vod_sub']}\n";
        echo "Duration: {$row['vod_duration']}\n";
        echo "Actor: {$row['vod_actor']}\n";
        echo "Director: {$row['vod_director']}\n";
        echo "Content: " . substr($row['vod_content'], 0, 100) . "...\n";
        echo "----------------------------------------\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 