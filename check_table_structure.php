<?php
require_once 'config.php';

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
    
    // Get all columns from mac_vod table
    $stmt = $pdo->query("SHOW COLUMNS FROM mac_vod");
    $existingColumns = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $existingColumns[] = $row['Field'];
    }
    
    // Fields we're trying to insert
    $importFields = [
        'vod_name', 'vod_sub', 'vod_class', 'vod_pic', 'vod_actor', 'vod_director',
        'vod_content', 'vod_remarks', 'vod_pubdate', 'vod_total', 'vod_tv', 'vod_serial',
        'vod_hits', 'vod_hits_day', 'vod_hits_week', 'vod_hits_month', 'vod_duration',
        'vod_up', 'vod_down', 'vod_score', 'vod_score_all', 'vod_score_num', 'vod_time',
        'vod_time_add', 'vod_time_hits', 'vod_time_make', 'vod_play_from', 'vod_play_server',
        'vod_play_note', 'vod_down_from', 'vod_down_server', 'vod_down_note', 'vod_plot',
        'vod_plot_name', 'vod_plot_detail', 'vod_status'
    ];
    
    echo "Kiểm tra cấu trúc bảng mac_vod:\n\n";
    
    // Check which fields exist
    echo "Các trường tồn tại trong bảng:\n";
    foreach ($existingColumns as $column) {
        echo "- $column\n";
    }
    
    echo "\nCác trường không tồn tại trong bảng:\n";
    foreach ($importFields as $field) {
        if (!in_array($field, $existingColumns)) {
            echo "- $field\n";
        }
    }
    
    echo "\nCác trường trong bảng không được sử dụng trong import:\n";
    foreach ($existingColumns as $column) {
        if (!in_array($column, $importFields)) {
            echo "- $column\n";
        }
    }
    
} catch(PDOException $e) {
    echo "Lỗi database: " . $e->getMessage() . "\n";
} catch(Exception $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
}
?> 