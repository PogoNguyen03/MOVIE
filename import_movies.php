<?php
// Load configuration
require_once 'config.php';

// Database connection
try {
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
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

    // Get current timestamp
    $current_time = time();

    // Prepare movie data
    $movie_data = [
        'type_id' => 1, // Phim
        'type_id_1' => 1,
        'vod_name' => $movie['name'],
        'vod_sub' => $movie['name_eng'] ?? $movie['name'],
        'vod_en' => $movie['name_eng'] ?? $movie['name'],
        'vod_status' => 1,
        'vod_letter' => strtoupper(substr($movie['name'], 0, 1)),
        'vod_color' => '',
        'vod_tag' => '',
        'vod_class' => '',
        'vod_pic' => $movie['poster'] ?? '',
        'vod_pic_thumb' => $movie['thumb'] ?? '',
        'vod_pic_slide' => '',
        'vod_actor' => !empty($movie['casts']) ? $movie['casts'] : 'Đang cập nhật',
        'vod_director' => !empty($movie['director']) ? $movie['director'] : 'Đang cập nhật',
        'vod_writer' => '',
        'vod_behind' => '',
        'vod_blurb' => '',
        'vod_remarks' => $movie['episode_current'] ?? '',
        'vod_pubdate' => '',
        'vod_total' => 0,
        'vod_serial' => '0',
        'vod_tv' => '',
        'vod_weekday' => '',
        'vod_area' => !empty($movie['country']) ? $movie['country'] : 'Đang cập nhật',
        'vod_lang' => 'Phụ đề Việt',
        'vod_year' => !empty($movie['year']) ? $movie['year'] : date('Y'),
        'vod_version' => '',
        'vod_state' => '',
        'vod_author' => '',
        'vod_jumpurl' => '',
        'vod_tpl' => '',
        'vod_tpl_play' => '',
        'vod_tpl_down' => '',
        'vod_isend' => 0,
        'vod_lock' => 0,
        'vod_level' => 0,
        'vod_copyright' => 0,
        'vod_points' => 0,
        'vod_points_play' => 0,
        'vod_points_down' => 0,
        'vod_hits' => 0,
        'vod_hits_day' => 0,
        'vod_hits_week' => 0,
        'vod_hits_month' => 0,
        'vod_duration' => $movie['time'] ?? '',
        'vod_up' => 0,
        'vod_down' => 0,
        'vod_score' => 0,
        'vod_score_all' => 0,
        'vod_score_num' => 0,
        'vod_time' => $current_time,
        'vod_time_add' => $current_time,
        'vod_time_hits' => $current_time,
        'vod_time_make' => 0,
        'vod_trysee' => 0,
        'vod_douban_id' => 0,
        'vod_douban_score' => 0,
        'vod_reurl' => '',
        'vod_rel_vod' => '',
        'vod_rel_art' => '',
        'vod_pwd' => '',
        'vod_pwd_url' => '',
        'vod_pwd_play' => '',
        'vod_pwd_play_url' => '',
        'vod_pwd_down' => '',
        'vod_pwd_down_url' => '',
        'vod_content' => !empty($movie['description']) ? $movie['description'] : 'Đang cập nhật',
        'vod_play_from' => 'nguonc',
        'vod_play_server' => '',
        'vod_play_note' => '',
        'vod_play_url' => $movie['slug'],
        'vod_down_from' => '',
        'vod_down_server' => '',
        'vod_down_note' => '',
        'vod_down_url' => '',
        'vod_plot' => 0,
        'vod_plot_name' => '',
        'vod_plot_detail' => ''
    ];

    // Insert new movie
    $fields = implode(', ', array_keys($movie_data));
    $values = ':' . implode(', :', array_keys($movie_data));
    $sql = "INSERT INTO mac_vod ($fields) VALUES ($values)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($movie_data);
    $imported++;
}

echo "Import completed:\n";
echo "Total movies found: $total_movies\n";
echo "Imported: $imported\n";
echo "Skipped: $skipped\n";
?> 