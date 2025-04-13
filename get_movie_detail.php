<?php
require_once 'config.php';

// Kết nối database
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
} catch (PDOException $e) {
    die(json_encode([
        'success' => false,
        'message' => "Lỗi kết nối database: " . $e->getMessage()
    ]));
}

// Kiểm tra ID phim
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    die(json_encode([
        'success' => false,
        'message' => "ID phim không hợp lệ"
    ]));
}

$movieId = (int)$_GET['id'];

// Lấy thông tin phim
try {
    $stmt = $pdo->prepare("SELECT * FROM mac_vod WHERE vod_id = ?");
    $stmt->execute([$movieId]);
    $movie = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$movie) {
        die(json_encode([
            'success' => false,
            'message' => "Không tìm thấy phim"
        ]));
    }
    
    // Trả về thông tin phim
    echo json_encode([
        'success' => true,
        'movie' => $movie
    ]);
    
} catch (PDOException $e) {
    die(json_encode([
        'success' => false,
        'message' => "Lỗi khi lấy thông tin phim: " . $e->getMessage()
    ]));
} 