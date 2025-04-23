<?php
require_once 'functions.php';

header('Content-Type: application/json');

try {
    $db = getDBConnection();
    
    // Get all tasks ordered by creation date
    $stmt = $db->query("SELECT * FROM mac_import_queue ORDER BY created_at DESC");
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format tasks for display
    $formattedTasks = array_map(function($task) {
        return [
            'id' => $task['id'],
            'category' => $task['category'],
            'pages' => "{$task['start_page']}-{$task['end_page']}",
            'status' => $task['status'],
            'progress' => round($task['progress'], 2),
            'stats' => [
                'imported' => $task['imported_movies'],
                'updated' => $task['updated_movies'],
                'skipped' => $task['skipped_movies']
            ],
            'created_at' => $task['created_at'],
            'updated_at' => $task['updated_at']
        ];
    }, $tasks);
    
    echo json_encode([
        'success' => true,
        'tasks' => $formattedTasks
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
} 