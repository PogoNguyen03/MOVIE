<?php
// Database connection
$host = "localhost";
$db = "movie";
$user = "root";
$pass = ""; // Empty password as specified

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    
    echo "<h1>Movie Database Structure</h1>";
    
    foreach ($tables as $table) {
        echo "<h2>Table: $table</h2>";
        
        // Get table structure
        $columns = $pdo->query("SHOW COLUMNS FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        
        echo "<table border='1' cellpadding='5'>";
        echo "<tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th></tr>";
        
        foreach ($columns as $column) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($column['Field']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Type']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Null']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Key']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Default']) . "</td>";
            echo "<td>" . htmlspecialchars($column['Extra']) . "</td>";
            echo "</tr>";
        }
        
        echo "</table>";
        
        // Get row count
        $count = $pdo->query("SELECT COUNT(*) FROM $table")->fetchColumn();
        echo "<p>Total rows: $count</p>";
        
        // Show sample data for mac_vod table
        if ($table == 'mac_vod') {
            echo "<h3>Sample Data (5 rows):</h3>";
            $sample = $pdo->query("SELECT vod_id, vod_name, vod_en, vod_year FROM $table LIMIT 5")->fetchAll(PDO::FETCH_ASSOC);
            
            echo "<table border='1' cellpadding='5'>";
            echo "<tr><th>ID</th><th>Name</th><th>English Name</th><th>Year</th></tr>";
            
            foreach ($sample as $row) {
                echo "<tr>";
                echo "<td>" . htmlspecialchars($row['vod_id']) . "</td>";
                echo "<td>" . htmlspecialchars($row['vod_name']) . "</td>";
                echo "<td>" . htmlspecialchars($row['vod_en']) . "</td>";
                echo "<td>" . htmlspecialchars($row['vod_year']) . "</td>";
                echo "</tr>";
            }
            
            echo "</table>";
        }
        
        echo "<hr>";
    }
    
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?> 