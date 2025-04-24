<?php
require_once 'config.php';

$pdo = new PDO(
    "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
    DB_USER,
    DB_PASS,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
);

try {
    // Lấy 10 phim mới nhất
    $stmt = $pdo->query("SELECT * FROM mac_vod ORDER BY vod_time DESC LIMIT 10");
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "10 phim mới nhất:\n";
    echo "================\n\n";
    
    foreach ($movies as $movie) {
        echo "ID: " . $movie['vod_id'] . "\n";
        echo "Tên phim: " . $movie['vod_name'] . "\n";
        echo "slup: " . $movie['vod_sub'] . "\n";
        echo "Diễn viên: " . $movie['vod_actor'] . "\n";
        echo "Đạo diễn: " . $movie['vod_director'] . "\n";
        echo "Thời lượng: " . $movie['vod_duration'] . " phút\n";
        echo "Năm: " . $movie['vod_year'] . "\n";
        echo "Quốc gia: " . $movie['vod_area'] . "\n";
        echo "Thể loại: " . $movie['vod_class'] . "\n";
        echo "Poster: " . $movie['vod_pic'] . "\n";
        echo "Nội dung: " . substr($movie['vod_content'], 0, 200) . "...\n";
        echo "----------------\n\n";
    }
    
    // Hiển thị tổng số phim
    $stmt = $pdo->query("SELECT COUNT(*) FROM mac_vod");
    $total = $stmt->fetchColumn();
    echo "Tổng số phim trong database: " . $total . "\n";
    
} catch (Exception $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
    exit(1);
}
?> 