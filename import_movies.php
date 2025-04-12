<?php
// Database connection
$host = "localhost";
$db = "movie";
$user = "root";
$pass = "";

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

// Get page number from command line argument or default to 1
$page = isset($argv[1]) ? (int)$argv[1] : 1;

// Get number of movies to import from command line argument or default to 10
$limit = isset($argv[2]) ? (int)$argv[2] : 10;

$api_url = "https://phim.nguonc.com/api/films/phim-moi-cap-nhat?page=" . $page;
$response = file_get_contents($api_url);

if ($response === false) {
    die("Error: Could not connect to API");
}

$data = json_decode($response, true);

if (!isset($data['items']) || !is_array($data['items'])) {
    die("Error: Invalid API response");
}

$movies = array_slice($data['items'], 0, $limit);
$total_movies = count($movies);
$imported = 0;
$skipped = 0;

foreach ($movies as $movie) {
    // Check if movie already exists
    $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_name = ?");
    $stmt->execute([$movie['name']]);
    $existing = $stmt->fetch();

    if ($existing) {
        $skipped++;
        continue;
    }

    // Prepare movie data
    $movie_data = [
        'vod_name' => $movie['name'],
        'vod_slug' => $movie['slug'],
        'vod_content' => $movie['description'],
        'vod_actor' => $movie['casts'] ?? '',
        'vod_director' => $movie['director'] ?? '',
        'vod_time' => $movie['time'],
        'vod_play_url' => $movie['slug'], // Using slug as play URL
        'vod_status' => 1
    ];

    // Insert new movie
    $sql = "INSERT INTO mac_vod (vod_name, vod_slug, vod_content, vod_actor, vod_director, vod_time, vod_play_url, vod_status) 
            VALUES (:vod_name, :vod_slug, :vod_content, :vod_actor, :vod_director, :vod_time, :vod_play_url, :vod_status)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($movie_data);
    $imported++;
}

echo "Import completed:\n";
echo "Total movies found: $total_movies\n";
echo "Imported: $imported\n";
echo "Skipped: $skipped\n";
?> 