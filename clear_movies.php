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
    // Clear all records from mac_vod table
    $stmt = $pdo->exec("TRUNCATE TABLE mac_vod");
    
    // Reset auto increment
    $stmt = $pdo->exec("ALTER TABLE mac_vod AUTO_INCREMENT = 1");
    
    echo "All movie data has been cleared successfully.\n";
    echo "Auto increment has been reset to 1.\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 