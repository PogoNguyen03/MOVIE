<?php
require_once 'config.php';

$pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8", DB_USER, DB_PASS);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

try {
    $stmt = $pdo->query("SHOW COLUMNS FROM mac_vod WHERE Field = 'vod_time'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Column details for vod_time:\n";
    print_r($column);
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 