<?php
try {
    $pdo = new PDO("mysql:host=localhost;dbname=movie", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->query("SHOW COLUMNS FROM mac_vod WHERE Field = 'vod_time'");
    $column = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Column details for vod_time:\n";
    print_r($column);
} catch(PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?> 