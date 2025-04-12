<?php
/**
 * Batch Import Script
 * 
 * This script helps import movies in chunks by running the import_movies.php script multiple times.
 * Usage: php batch_import.php [start_chunk] [end_chunk] [pages_per_chunk] [movies_per_page]
 * Example: php batch_import.php 1 10 100 20
 */

// Get command line arguments
$startChunk = isset($argv[1]) ? (int)$argv[1] : 1;
$endChunk = isset($argv[2]) ? (int)$argv[2] : 1;
$pagesPerChunk = isset($argv[3]) ? (int)$argv[3] : 100;
$moviesPerPage = isset($argv[4]) ? (int)$argv[4] : 20;

// Validate input
$startChunk = max(1, $startChunk);
$endChunk = max($startChunk, $endChunk);
$pagesPerChunk = max(1, min($pagesPerChunk, 500));
$moviesPerPage = max(1, min($moviesPerPage, 50));

echo "Batch Import Script\n";
echo "==================\n\n";
echo "Importing movies in chunks from $startChunk to $endChunk\n";
echo "Pages per chunk: $pagesPerChunk, Movies per page: $moviesPerPage\n\n";

// Calculate total pages
$totalPages = 2933; // Maximum page number
$totalChunks = ceil($totalPages / $pagesPerChunk);
$endChunk = min($endChunk, $totalChunks);

echo "Total pages: $totalPages, Total chunks: $totalChunks\n";
echo "Processing chunks $startChunk to $endChunk\n\n";

// Process each chunk
for ($chunk = $startChunk; $chunk <= $endChunk; $chunk++) {
    $startPage = ($chunk - 1) * $pagesPerChunk + 1;
    $endPage = min($chunk * $pagesPerChunk, $totalPages);
    
    echo "Processing chunk $chunk (pages $startPage to $endPage)...\n";
    
    // Run the import script for this chunk
    $command = "php import_movies.php $startPage $endPage $moviesPerPage";
    echo "Running command: $command\n\n";
    
    // Execute the command and capture output
    $output = [];
    $returnVar = 0;
    exec($command, $output, $returnVar);
    
    // Display output
    foreach ($output as $line) {
        echo $line . "\n";
    }
    
    if ($returnVar !== 0) {
        echo "Error: Command failed with return code $returnVar\n";
    }
    
    echo "\nChunk $chunk completed.\n\n";
    
    // Add a delay between chunks to avoid overwhelming the system
    if ($chunk < $endChunk) {
        echo "Waiting 5 seconds before next chunk...\n\n";
        sleep(5);
    }
}

echo "Batch import completed!\n";
?> 