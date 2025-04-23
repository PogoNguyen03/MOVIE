<?php
require_once 'functions.php';

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

try {
    $db = getDBConnection();
    
    // Get pending tasks
    $sql = "SELECT id FROM mac_import_queue WHERE status = 'pending' ORDER BY created_at ASC LIMIT 1";
    $stmt = $db->prepare($sql);
    $stmt->execute();
    
    while ($task = $stmt->fetch(PDO::FETCH_ASSOC)) {
        // Run queue processor for this task
        $command = sprintf('php queue_processor.php %d', $task['id']);
        exec($command);
        
        // Add delay between tasks
        sleep(5);
    }
    
} catch (Exception $e) {
    error_log("Error running queue: " . $e->getMessage());
    die("Error running queue\n");
} 