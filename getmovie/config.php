<?php
// Sử dụng cấu hình chung
require_once dirname(__DIR__) . '/config.php';

// Định nghĩa các danh mục phim và API endpoint tương ứng
$movieCategories = [
    'phim-moi-cap-nhat' => [
        'name' => 'Cập nhật mới',
        'url' => '/films/phim-moi-cap-nhat'
    ],
    'phim-bo' => [
        'name' => 'Phim Bộ',
        'url' => '/films/danh-sach/phim-bo'
    ],
    'phim-le' => [
        'name' => 'Phim Lẻ',
        'url' => '/films/danh-sach/phim-le'
    ],
    'tv-shows' => [
        'name' => 'TV Shows',
        'url' => '/films/danh-sach/tv-shows'
    ],
    'hoat-hinh' => [
        'name' => 'Hoạt hình',
        'url' => '/films/the-loai/hoat-hinh'
    ]
];

// Cấu hình số lần thử lại mặc định và thời gian delay
define('DEFAULT_RETRIES', 5);        // Tăng số lần retry
define('DEFAULT_DELAY', 5);          // Tăng delay giữa các request
define('BATCH_SIZE', 20);           // Số phim xử lý mỗi trang
define('MAX_PAGES_PER_BATCH', 10);  // Số trang tối đa mỗi lần import

// Cấu hình timeout
define('INITIAL_TIMEOUT', 30);      // Timeout ban đầu (giây)
define('MAX_TIMEOUT', 120);         // Timeout tối đa (giây)

// Cấu hình Cloudflare
define('CLOUDFLARE_WAIT', 10);      // Thời gian chờ khi gặp lỗi Cloudflare (giây) 