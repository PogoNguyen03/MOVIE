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
    // Get records with slug information
    $stmt = $pdo->query("SELECT vod_id, vod_name, vod_slug FROM mac_vod LIMIT 5");
    
    echo "Checking vod_slug field in mac_vod table:\n";
    echo "========================================\n\n";
    
    while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "ID: {$row['vod_id']}\n";
        echo "Name: {$row['vod_name']}\n";
        echo "Slug: " . ($row['vod_slug'] ? $row['vod_slug'] : '(empty)') . "\n";
        echo "----------------------------------------\n";
    }
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 