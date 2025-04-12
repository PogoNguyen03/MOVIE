<?php
/**
 * Movie Management Script
 * 
 * This script provides options to manage the mac_vod table:
 * 1. Delete all records and reset ID counter
 * 2. Reset ID counter only
 * 3. Check current status
 * 
 * Usage: php manage_movies.php [option]
 * Options:
 *   delete - Delete all records and reset ID counter
 *   reset  - Reset ID counter only
 *   check  - Check current status (default)
 */

// Database connection
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

// Get command line argument
$option = isset($argv[1]) ? strtolower($argv[1]) : 'check';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "Movie Management Script\n";
    echo "=====================\n\n";
    
    // Get current status
    $stmt = $pdo->query("SELECT COUNT(*) FROM mac_vod");
    $count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentAutoIncrement = $tableInfo['Auto_increment'];
    
    echo "Current status:\n";
    echo "- Number of records: $count\n";
    echo "- Next ID will be: $currentAutoIncrement\n";
    
    if ($count > 0) {
        $stmt = $pdo->query("SELECT MIN(vod_id) as min_id, MAX(vod_id) as max_id FROM mac_vod");
        $ids = $stmt->fetch(PDO::FETCH_ASSOC);
        echo "- ID range: {$ids['min_id']} to {$ids['max_id']}\n";
    }
    
    echo "\n";
    
    // Process option
    switch ($option) {
        case 'delete':
            echo "Deleting all records and resetting ID counter...\n";
            
            // Delete all records
            $pdo->exec("DELETE FROM mac_vod");
            
            // Reset auto_increment
            $pdo->exec("ALTER TABLE mac_vod AUTO_INCREMENT = 1");
            
            echo "All records deleted and ID counter reset to 1.\n";
            break;
            
        case 'reset':
            echo "Resetting ID counter only...\n";
            
            // Reset auto_increment
            $pdo->exec("ALTER TABLE mac_vod AUTO_INCREMENT = 1");
            
            echo "ID counter reset to 1.\n";
            break;
            
        case 'check':
        default:
            echo "No action taken. Use 'delete' or 'reset' option to make changes.\n";
            break;
    }
    
    // Show final status
    $stmt = $pdo->query("SELECT COUNT(*) FROM mac_vod");
    $count = $stmt->fetchColumn();
    
    $stmt = $pdo->query("SHOW TABLE STATUS LIKE 'mac_vod'");
    $tableInfo = $stmt->fetch(PDO::FETCH_ASSOC);
    $currentAutoIncrement = $tableInfo['Auto_increment'];
    
    echo "\nFinal status:\n";
    echo "- Number of records: $count\n";
    echo "- Next ID will be: $currentAutoIncrement\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 