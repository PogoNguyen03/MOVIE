<?php
// Database connection
$host = "localhost";
$db = "movie";
$user = "root";
$pass = ""; // Empty password as specified

echo "Database Connection Test\n";
echo "=======================\n\n";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "✅ Successfully connected to the database!\n\n";
    
    // Get MySQL version
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    echo "MySQL Version: $version\n";
    
    // Get database name
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "Current Database: $dbName\n";
    
    // Count tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    echo "Total Tables: " . count($tables) . "\n";
    
    // Count movies
    $movieCount = $pdo->query("SELECT COUNT(*) FROM mac_vod")->fetchColumn();
    echo "Total Movies: $movieCount\n\n";
    
    // Get a sample movie
    $sampleMovie = $pdo->query("SELECT vod_name, vod_en, vod_year FROM mac_vod LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    
    if ($sampleMovie) {
        echo "Sample Movie:\n";
        echo "Name: " . $sampleMovie['vod_name'] . "\n";
        echo "English Name: " . $sampleMovie['vod_en'] . "\n";
        echo "Year: " . $sampleMovie['vod_year'] . "\n\n";
    }
    
    // Get table structure for mac_vod
    echo "Table Structure (mac_vod):\n";
    echo "-------------------------\n";
    
    $columns = $pdo->query("SHOW COLUMNS FROM mac_vod")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($columns as $column) {
        echo $column['Field'] . " - " . $column['Type'] . "\n";
    }
    
} catch (PDOException $e) {
    echo "❌ Connection failed: " . $e->getMessage() . "\n";
}
?> 