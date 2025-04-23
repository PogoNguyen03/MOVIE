<?php
require_once 'functions.php';

// Check if running from command line
if (php_sapi_name() !== 'cli') {
    die('This script can only be run from the command line');
}

// Get task ID from command line arguments
$taskId = isset($argv[1]) ? $argv[1] : null;
if (!$taskId) {
    die("Please provide a task ID\n");
}

try {
    // Get task information
    $task = getQueueTask($taskId);
    if (!$task) {
        die("Task not found\n");
    }
    
    // Update task status to processing
    updateQueueStatus($taskId, 'processing');
    
    // Calculate total pages
    $totalPages = $task['end_page'] - $task['start_page'] + 1;
    $currentPage = $task['start_page'];
    
    // Initialize counters
    $imported = 0;
    $updated = 0;
    $skipped = 0;
    
    // Process each page
    while ($currentPage <= $task['end_page']) {
        try {
            // Fetch movies from API
            $movies = fetchMovies($task['category'], $currentPage, $task['limit_per_page']);
            
            // Process each movie
            foreach ($movies as $movie) {
                try {
                    // Get movie details
                    $details = fetchMovieDetails($movie['slug']);
                    
                    // Check if movie exists
                    $existing = checkMovieExists($movie['slug']);
                    
                    if ($existing) {
                        if ($task['update_existing']) {
                            // Update existing movie
                            importMovieToDB($details, $existing['vod_id']);
                            $updated++;
                        } else {
                            $skipped++;
                        }
                    } else {
                        // Import new movie
                        importMovieToDB($details);
                        $imported++;
                    }
                    
                } catch (Exception $e) {
                    error_log("Error processing movie {$movie['slug']}: " . $e->getMessage());
                    continue;
                }
            }
            
            // Update progress
            $progress = (($currentPage - $task['start_page'] + 1) / $totalPages) * 100;
            updateQueueProgress($taskId, $progress, $imported, $updated, $skipped);
            
            // Move to next page
            $currentPage++;
            
            // Add delay between pages to prevent API overload
            sleep(1);
            
        } catch (Exception $e) {
            error_log("Error processing page $currentPage: " . $e->getMessage());
            continue;
        }
    }
    
    // Update task status to completed
    updateQueueStatus($taskId, 'completed');
    
} catch (Exception $e) {
    error_log("Error processing task $taskId: " . $e->getMessage());
    updateQueueStatus($taskId, 'error', $e->getMessage());
} 