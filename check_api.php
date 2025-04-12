<?php
// API URL
$apiUrl = "https://phim.nguonc.com/api/films/phim-moi-cap-nhat?page=1";
echo "Checking API: $apiUrl\n\n";

// Get data from API
$response = file_get_contents($apiUrl);
if ($response === false) {
    echo "Could not connect to API\n";
    exit;
}

// Display raw response
echo "Raw API Response:\n";
echo "----------------------------------------\n";
echo $response;
echo "\n----------------------------------------\n\n";

// Try to decode JSON
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    echo "JSON decode error: " . json_last_error_msg() . "\n";
    exit;
}

// Display decoded data
echo "Decoded JSON Data:\n";
echo "----------------------------------------\n";
print_r($data);
echo "\n----------------------------------------\n\n";

// Check if data structure is as expected
if (isset($data['data']) && is_array($data['data'])) {
    echo "API structure is valid. Found " . count($data['data']) . " movies.\n";
    
    // Display first movie if available
    if (count($data['data']) > 0) {
        echo "\nFirst movie data:\n";
        print_r($data['data'][0]);
    }
} else {
    echo "API structure is not as expected. Keys found: " . implode(", ", array_keys($data)) . "\n";
}
?> 