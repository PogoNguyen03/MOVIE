<?php
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

// Function to normalize slug (copy from import_movies.php)
function normalizeSlug($text) {
    // Convert to lowercase
    $text = mb_strtolower($text, 'UTF-8');
    
    // Replace Vietnamese characters
    $text = preg_replace('/(à|á|ạ|ả|ã|â|ầ|ấ|ậ|ẩ|ẫ|ă|ằ|ắ|ặ|ẳ|ẵ)/', 'a', $text);
    $text = preg_replace('/(è|é|ẹ|ẻ|ẽ|ê|ề|ế|ệ|ể|ễ)/', 'e', $text);
    $text = preg_replace('/(ì|í|ị|ỉ|ĩ)/', 'i', $text);
    $text = preg_replace('/(ò|ó|ọ|ỏ|õ|ô|ồ|ố|ộ|ổ|ỗ|ơ|ờ|ớ|ợ|ở|ỡ)/', 'o', $text);
    $text = preg_replace('/(ù|ú|ụ|ủ|ũ|ư|ừ|ứ|ự|ử|ữ)/', 'u', $text);
    $text = preg_replace('/(ỳ|ý|ỵ|ỷ|ỹ)/', 'y', $text);
    $text = preg_replace('/(đ)/', 'd', $text);
    
    // Replace any remaining non-alphanumeric characters with hyphens
    $text = preg_replace('/[^a-z0-9-]/', '-', $text);
    
    // Replace multiple consecutive hyphens with a single hyphen
    $text = preg_replace('/-+/', '-', $text);
    
    // Remove leading and trailing hyphens
    $text = trim($text, '-');
    
    return $text;
}

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get all records with empty or null slugs
    $stmt = $pdo->query("SELECT vod_id, vod_name FROM mac_vod WHERE vod_slug IS NULL OR vod_slug = ''");
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($movies) . " movies without slugs.\n";
    echo "Updating slugs...\n\n";
    
    $updateStmt = $pdo->prepare("UPDATE mac_vod SET vod_slug = ? WHERE vod_id = ?");
    $updated = 0;
    
    foreach ($movies as $movie) {
        $slug = normalizeSlug($movie['vod_name']);
        $updateStmt->execute([$slug, $movie['vod_id']]);
        echo "Updated {$movie['vod_name']} -> $slug\n";
        $updated++;
    }
    
    echo "\nCompleted! Updated $updated movies.\n";
    
} catch (PDOException $e) {
    echo "Database error: " . $e->getMessage() . "\n";
}
?> 