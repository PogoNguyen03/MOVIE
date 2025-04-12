<?php
// API URL
$apiUrl = "https://phim.nguonc.com/api/films/phim-moi-cap-nhat?page=1";
echo "Checking API: $apiUrl\n\n";

// Initialize cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $apiUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');

// Execute cURL request
$response = curl_exec($ch);

// Check for errors
if (curl_errno($ch)) {
    echo "cURL Error: " . curl_error($ch) . "\n";
    exit;
}

// Get HTTP status code
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "HTTP Status Code: $httpCode\n\n";

// Close cURL
curl_close($ch);

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