<?php
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
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