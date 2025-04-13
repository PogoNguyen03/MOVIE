<?php
require_once 'config.php';

// Function to make HTTP request with cURL
function makeRequest($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'Origin: https://phim.nguonc.com',
        'Referer: https://phim.nguonc.com/'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => "Lỗi cURL: " . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => "Lỗi HTTP: " . $httpCode];
    }
    
    return ['data' => $response];
}

// Function to extract category information
function extractCategoryInfo($category) {
    $info = [
        'year' => '',
        'country' => '',
        'genres' => []
    ];
    
    if (!is_array($category)) {
        return $info;
    }
    
    foreach ($category as $group) {
        if (isset($group['group'])) {
            $groupName = strtolower($group['group']['name']);
            if ($groupName === 'năm') {
                $info['year'] = isset($group['list'][0]['name']) ? $group['list'][0]['name'] : '';
            } elseif ($groupName === 'quốc gia') {
                $info['country'] = isset($group['list'][0]['name']) ? $group['list'][0]['name'] : '';
            } elseif ($groupName === 'thể loại') {
                foreach ($group['list'] as $item) {
                    $info['genres'][] = $item['name'];
                }
            }
        }
    }
    
    return $info;
}

// Function to extract episode information
function extractEpisodeInfo($episodes) {
    $episodeInfo = [];
    
    if (!is_array($episodes)) {
        return $episodeInfo;
    }
    
    foreach ($episodes as $server) {
        if (isset($server['server_name']) && isset($server['items'])) {
            foreach ($server['items'] as $episode) {
                $episodeInfo[] = [
                    'name' => $episode['name'],
                    'slug' => $episode['slug'],
                    'embed' => $episode['embed']
                ];
            }
        }
    }
    
    return $episodeInfo;
}

// Function to convert time format to minutes
function convertTimeToMinutes($time) {
    if (empty($time)) return 0;
    
    // Handle "XX phút" format
    if (preg_match('/^(\d+)\s*phút$/i', $time, $matches)) {
        return (int)$matches[1];
    }
    
    // Handle "XX Phút/Tập" format
    if (preg_match('/^(\d+)\s*Phút\/Tập$/i', $time, $matches)) {
        return (int)$matches[1];
    }
    
    // Handle "XX:XX" format
    if (preg_match('/^(\d+):(\d+)$/', $time, $matches)) {
        return (int)$matches[1] * 60 + (int)$matches[2];
    }
    
    return 0;
}

// Function to normalize slug
function normalizeSlug($text) {
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    $text = strtolower($text);
    return $text;
}

// Function to determine type_id based on movie information
function determineTypeId($category) {
    if (!is_array($category)) {
        return 1; // Default to "Phim bộ" if category is not an array
    }
    
    $isAnimated = false;
    $isTVShow = false;
    $isSeries = false;
    $isMovie = false;
    
    foreach ($category as $group) {
        if (isset($group['group'])) {
            $groupName = strtolower($group['group']['name']);
            
            // Check for "Định dạng" group
            if ($groupName === 'định dạng' && isset($group['list'])) {
                foreach ($group['list'] as $item) {
                    $formatName = strtolower($item['name']);
                    if ($formatName === 'phim bộ') {
                        $isSeries = true;
                    } elseif ($formatName === 'phim lẻ') {
                        $isMovie = true;
                    } elseif ($formatName === 'tv shows') {
                        $isTVShow = true;
                    }
                }
            }
            
            // Check for "Thể loại" group to identify animations
            if ($groupName === 'thể loại' && isset($group['list'])) {
                foreach ($group['list'] as $item) {
                    $genreName = strtolower($item['name']);
                    if ($genreName === 'hoạt hình') {
                        $isAnimated = true;
                    }
                }
            }
        }
    }
    
    // Determine type_id based on the checks
    if ($isAnimated) {
        return 3; // Hoạt hình
    } elseif ($isTVShow) {
        return 4; // TV shows
    } elseif ($isMovie) {
        return 2; // Phim lẻ
    } else {
        return 1; // Default to "Phim bộ"
    }
}

// Function to fetch movie details from API
function fetchMovieDetails($slug) {
    $url = API_BASE_URL . "/film/" . $slug;
    $response = makeRequest($url);
    
    if (isset($response['data'])) {
        $data = json_decode($response['data'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['movie'])) {
            return $data['movie'];
        }
    }
    
    return null;
}

// Function to import movie to database
function importMovieToDB($movie, $pdo) {
    try {
        // Extract category information
        $categoryInfo = extractCategoryInfo($movie['category']);
        
        // Extract episode information
        $episodeInfo = extractEpisodeInfo($movie['episodes']);
        
        // Convert time to minutes
        $duration = convertTimeToMinutes($movie['time']);
        
        // Determine type_id based on movie information
        $typeId = determineTypeId($movie['category']);
        
        // Prepare episode data
        $episodeData = [];
        foreach ($episodeInfo as $episode) {
            $episodeData[] = $episode['embed'];
        }
        
        // Prepare SQL statement
        $sql = "INSERT INTO mac_vod (
            vod_name, vod_sub, vod_en, vod_tag, vod_class, vod_pic, vod_pic_thumb, 
            vod_actor, vod_director, vod_writer, vod_behind, vod_content, vod_blurb,
            vod_year, vod_area, vod_lang, vod_state, vod_serial, vod_tv, 
            vod_weekday, vod_total, vod_trysee, vod_hits, vod_hits_day, 
            vod_hits_week, vod_hits_month, vod_duration, vod_up, vod_down, 
            vod_score, vod_remarks, vod_author, vod_jumpurl, vod_tpl, vod_tpl_play, 
            vod_tpl_down, vod_isend, vod_lock, vod_level, vod_copyright, vod_points, 
            vod_points_play, vod_points_down, vod_play_from, vod_play_server, 
            vod_play_note, vod_play_url, vod_down_from, vod_down_server, vod_down_note, 
            vod_down_url, vod_time, vod_time_add, vod_time_hits, vod_time_make, 
            vod_douban_id, vod_douban_score, vod_reurl, vod_rel_vod, vod_rel_art, 
            vod_pwd, vod_pwd_url, vod_pwd_play, vod_pwd_play_url, vod_pwd_down, 
            vod_pwd_down_url, vod_plot, vod_plot_name, vod_plot_detail, vod_status,
            vod_version, type_id
        ) VALUES (
            :vod_name, :vod_sub, :vod_en, :vod_tag, :vod_class, :vod_pic, :vod_pic_thumb, 
            :vod_actor, :vod_director, :vod_writer, :vod_behind, :vod_content, :vod_blurb,
            :vod_year, :vod_area, :vod_lang, :vod_state, :vod_serial, :vod_tv, 
            :vod_weekday, :vod_total, :vod_trysee, :vod_hits, :vod_hits_day, 
            :vod_hits_week, :vod_hits_month, :vod_duration, :vod_up, :vod_down, 
            :vod_score, :vod_remarks, :vod_author, :vod_jumpurl, :vod_tpl, :vod_tpl_play, 
            :vod_tpl_down, :vod_isend, :vod_lock, :vod_level, :vod_copyright, :vod_points, 
            :vod_points_play, :vod_points_down, :vod_play_from, :vod_play_server, 
            :vod_play_note, :vod_play_url, :vod_down_from, :vod_down_server, :vod_down_note, 
            :vod_down_url, :vod_time, :vod_time_add, :vod_time_hits, :vod_time_make, 
            :vod_douban_id, :vod_douban_score, :vod_reurl, :vod_rel_vod, :vod_rel_art, 
            :vod_pwd, :vod_pwd_url, :vod_pwd_play, :vod_pwd_play_url, :vod_pwd_down, 
            :vod_pwd_down_url, :vod_plot, :vod_plot_name, :vod_plot_detail, :vod_status,
            :vod_version, :type_id
        )";
        
        $stmt = $pdo->prepare($sql);
        
        // Set values
        $stmt->bindValue(':vod_name', $movie['name'] ?? '');
        $stmt->bindValue(':vod_sub', $movie['slug'] ?? '');
        $stmt->bindValue(':vod_en', $movie['original_name'] ?? '');
        $stmt->bindValue(':vod_tag', !empty($categoryInfo['genres']) ? implode(',', $categoryInfo['genres']) : '');
        $stmt->bindValue(':vod_class', !empty($categoryInfo['genres']) ? implode(',', $categoryInfo['genres']) : '');
        $stmt->bindValue(':vod_pic', $movie['poster_url'] ?? '');
        $stmt->bindValue(':vod_pic_thumb', $movie['thumb_url'] ?? '');
        $stmt->bindValue(':vod_actor', !empty($movie['casts']) ? $movie['casts'] : 'Đang cập nhật');
        $stmt->bindValue(':vod_director', !empty($movie['director']) ? $movie['director'] : 'Đang cập nhật');
        $stmt->bindValue(':vod_writer', 'Đang cập nhật');
        $stmt->bindValue(':vod_behind', 'Đang cập nhật');
        $stmt->bindValue(':vod_content', !empty($movie['description']) ? $movie['description'] : 'Đang cập nhật');
        
        // Truncate description for vod_blurb
        $blurb = !empty($movie['description']) ? $movie['description'] : 'Đang cập nhật';
        $maxLength = 255; // Giới hạn độ dài tối đa là 255 ký tự
        
        // Cắt chuỗi an toàn với UTF-8
        if (mb_strlen($blurb, 'UTF-8') > $maxLength) {
            // Lấy phần đầu tiên của chuỗi
            $blurb = mb_substr($blurb, 0, $maxLength, 'UTF-8');
        }
        
        // Đảm bảo chuỗi là UTF-8 hợp lệ
        $blurb = mb_convert_encoding($blurb, 'UTF-8', 'UTF-8');
        
        $stmt->bindValue(':vod_blurb', $blurb);
        
        $stmt->bindValue(':vod_year', !empty($categoryInfo['year']) ? $categoryInfo['year'] : date('Y'));
        $stmt->bindValue(':vod_area', !empty($categoryInfo['country']) ? $categoryInfo['country'] : 'Đang cập nhật');
        
        // Xử lý và giới hạn độ dài của ngôn ngữ
        $language = !empty($movie['language']) ? $movie['language'] : 'Phụ đề Việt';
        // Giới hạn độ dài tối đa là 50 ký tự
        if (mb_strlen($language, 'UTF-8') > 50) {
            $language = mb_substr($language, 0, 50, 'UTF-8');
        }
        $stmt->bindValue(':vod_lang', $language);
        
        $stmt->bindValue(':vod_state', 1);
        $stmt->bindValue(':vod_serial', !empty($movie['total_episodes']) && $movie['total_episodes'] > 1 ? 1 : 0);
        $stmt->bindValue(':vod_tv', '');
        $stmt->bindValue(':vod_weekday', '');
        $stmt->bindValue(':vod_total', $movie['total_episodes'] ?? 0);
        $stmt->bindValue(':vod_trysee', 0);
        $stmt->bindValue(':vod_hits', 0);
        $stmt->bindValue(':vod_hits_day', 0);
        $stmt->bindValue(':vod_hits_week', 0);
        $stmt->bindValue(':vod_hits_month', 0);
        $stmt->bindValue(':vod_duration', $duration);
        $stmt->bindValue(':vod_up', 0);
        $stmt->bindValue(':vod_down', 0);
        $stmt->bindValue(':vod_score', 0);
        $stmt->bindValue(':vod_remarks', !empty($movie['current_episode']) ? $movie['current_episode'] : '');
        $stmt->bindValue(':vod_author', '');
        $stmt->bindValue(':vod_jumpurl', '');
        $stmt->bindValue(':vod_tpl', '');
        $stmt->bindValue(':vod_tpl_play', '');
        $stmt->bindValue(':vod_tpl_down', '');
        $stmt->bindValue(':vod_isend', 0);
        $stmt->bindValue(':vod_lock', 0);
        $stmt->bindValue(':vod_level', 0);
        $stmt->bindValue(':vod_copyright', 0);
        $stmt->bindValue(':vod_points', 0);
        $stmt->bindValue(':vod_points_play', 0);
        $stmt->bindValue(':vod_points_down', 0);
        $stmt->bindValue(':vod_play_from', 'ngm3u8');
        $stmt->bindValue(':vod_play_server', '');
        $stmt->bindValue(':vod_play_note', '');
        $stmt->bindValue(':vod_play_url', implode('#', $episodeData));
        $stmt->bindValue(':vod_down_from', '');
        $stmt->bindValue(':vod_down_server', '');
        $stmt->bindValue(':vod_down_note', '');
        $stmt->bindValue(':vod_down_url', '');
        $stmt->bindValue(':vod_time', time());
        $stmt->bindValue(':vod_time_add', time());
        $stmt->bindValue(':vod_time_hits', time());
        $stmt->bindValue(':vod_time_make', time());
        $stmt->bindValue(':vod_douban_id', 0);
        $stmt->bindValue(':vod_douban_score', 0);
        $stmt->bindValue(':vod_reurl', '');
        $stmt->bindValue(':vod_rel_vod', '');
        $stmt->bindValue(':vod_rel_art', '');
        $stmt->bindValue(':vod_pwd', '');
        $stmt->bindValue(':vod_pwd_url', '');
        $stmt->bindValue(':vod_pwd_play', '');
        $stmt->bindValue(':vod_pwd_play_url', '');
        $stmt->bindValue(':vod_pwd_down', '');
        $stmt->bindValue(':vod_pwd_down_url', '');
        $stmt->bindValue(':vod_plot', 0);
        $stmt->bindValue(':vod_plot_name', '');
        $stmt->bindValue(':vod_plot_detail', '');
        $stmt->bindValue(':vod_status', 1);
        $stmt->bindValue(':vod_version', !empty($movie['quality']) ? $movie['quality'] : 'HD');
        $stmt->bindValue(':type_id', $typeId);
        
        $stmt->execute();
        return true;
    } catch (PDOException $e) {
        return ['error' => "Lỗi khi import phim {$movie['name']}: " . $e->getMessage()];
    }
}

// Process form submission
$result = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startPage = isset($_POST['start_page']) ? (int)$_POST['start_page'] : 1;
    $endPage = isset($_POST['end_page']) ? (int)$_POST['end_page'] : 1;
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    
    // Validate page range
    if ($startPage > $endPage) {
        $result['error'] = "Lỗi: Trang bắt đầu phải nhỏ hơn hoặc bằng trang kết thúc.";
    } else {
        // Connect to database
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
            
            $totalImportedCount = 0;
            $totalSkippedCount = 0;
            $totalMoviesCount = 0;
            $result['pages'] = [];
            
            // Process each page
            for ($page = $startPage; $page <= $endPage; $page++) {
                $pageResult = [
                    'page' => $page,
                    'total' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'errors' => []
                ];
                
                // Fetch movies from API
                $url = API_BASE_URL . "/films/phim-moi-cap-nhat?page=" . $page;
                $response = makeRequest($url);
                
                if (isset($response['data'])) {
                    $data = json_decode($response['data'], true);
                    if (json_last_error() === JSON_ERROR_NONE && isset($data['items']) && !empty($data['items'])) {
                        $totalMovies = count($data['items']);
                        $importedCount = 0;
                        $skippedCount = 0;
                        
                        $pageResult['total'] = $totalMovies;
                        
                        foreach ($data['items'] as $index => $movie) {
                            if ($index >= $limit) break;
                            
                            // Check if movie already exists
                            $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_sub = ?");
                            $stmt->execute([$movie['slug']]);
                            $exists = $stmt->fetch();
                            
                            if ($exists && !$force) {
                                $pageResult['skipped']++;
                                $skippedCount++;
                                continue;
                            }
                            
                            // Fetch detailed movie information
                            $movieDetails = fetchMovieDetails($movie['slug']);
                            if ($movieDetails) {
                                $importResult = importMovieToDB($movieDetails, $pdo);
                                if ($importResult === true) {
                                    $pageResult['imported']++;
                                    $importedCount++;
                                } else {
                                    $pageResult['errors'][] = $importResult['error'];
                                }
                            } else {
                                $pageResult['errors'][] = "Không thể lấy thông tin chi tiết phim {$movie['name']}.";
                            }
                        }
                        
                        $totalImportedCount += $importedCount;
                        $totalSkippedCount += $skippedCount;
                        $totalMoviesCount += $totalMovies;
                        
                    } else {
                        $pageResult['errors'][] = "Lỗi khi parse JSON hoặc không tìm thấy phim nào trên trang {$page}.";
                    }
                } else {
                    $pageResult['errors'][] = "Không thể kết nối đến API cho trang {$page}: " . $response['error'];
                }
                
                $result['pages'][] = $pageResult;
            }
            
            $result['summary'] = [
                'total_movies' => $totalMoviesCount,
                'total_imported' => $totalImportedCount,
                'total_skipped' => $totalSkippedCount
            ];
            
        } catch (PDOException $e) {
            $result['error'] = "Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage();
        } catch (Exception $e) {
            $result['error'] = "Lỗi: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Phim</title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        
        .card {
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .progress-bar {
            transition: all 0.5s ease;
            min-width: 2rem; /* Đảm bảo luôn có đủ chỗ để hiển thị phần trăm */
        }
        
        .sidebar {
            transition: all 0.3s ease;
        }
        
        .content {
            transition: all 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <div class="sidebar fixed top-0 left-0 h-full w-64 bg-white shadow-lg z-10">
            <div class="p-4 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800">Movie Manager</h1>
            </div>
            <div class="p-4">
                <ul class="space-y-2">
                    <!-- <li>
                        <a href="#" class="flex items-center p-2 text-gray-700 bg-gray-100 rounded-lg">
                            <i class="fas fa-home w-5 h-5 mr-3"></i>
                            <span>Trang chủ</span>
                        </a>
                    </li> -->
                    <li>
                        <a href="manage_movies.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                            <i class="fas fa-film w-5 h-5 mr-3"></i>
                            <span>Quản lý phim</span>
                        </a>
                    </li>
                    <li>
                        <a href="#" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                            <i class="fas fa-download w-5 h-5 mr-3"></i>
                            <span>Import phim</span>
                        </a>
                    </li>
                    <!-- <li>
                        <a href="#" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg">
                            <i class="fas fa-cog w-5 h-5 mr-3"></i>
                            <span>Cài đặt</span>
                        </a>
                    </li> -->
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="content ml-64 flex-1 overflow-auto">
            <!-- Top Navigation -->
            <div class="bg-white shadow-sm">
                <div class="flex items-center justify-between p-4">
                    <button id="sidebarToggle" class="text-gray-500 hover:text-gray-700 focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <input type="text" placeholder="Tìm kiếm..." class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                            <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600">Admin</span>
                            <img src="https://ui-avatars.com/api/?name=Admin&background=0D8ABC&color=fff" alt="User" class="w-8 h-8 rounded-full">
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Page Content -->
            <div class="p-6">
                <div class="mb-6">
                    <h1 class="text-2xl font-bold text-gray-800">Import Phim</h1>
                    <p class="text-gray-600">Nhập thông tin để import phim từ nguồn dữ liệu</p>
                </div>
                
                <!-- Import Form -->
                <div class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <form method="post" action="" id="importForm">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div>
                                <label for="start_page" class="block text-sm font-medium text-gray-700 mb-1">Trang bắt đầu:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="start_page" name="start_page" value="1" min="1" required>
                            </div>
                            <div>
                                <label for="end_page" class="block text-sm font-medium text-gray-700 mb-1">Trang kết thúc:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="end_page" name="end_page" value="1" min="1" required>
                            </div>
                            <div>
                                <label for="limit" class="block text-sm font-medium text-gray-700 mb-1">Số lượng phim tối đa trên mỗi trang:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="limit" name="limit" value="10" min="1" required>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Xử lý phim đã tồn tại:</label>
                                <div class="flex space-x-4">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="force" id="force_skip" value="0" checked class="form-radio h-4 w-4 text-blue-600">
                                        <span class="ml-2 text-gray-700">Bỏ qua</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="force" id="force_overwrite" value="1" class="form-radio h-4 w-4 text-blue-600">
                                        <span class="ml-2 text-gray-700">Ghi đè</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" id="importButton">
                                <i class="fas fa-download mr-2"></i>Import Phim
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Progress Section -->
                <div id="progressSection" style="display: none;" class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tiến độ import:</h3>
                    <div class="w-full bg-gray-200 rounded-full h-6 mb-4 relative">
                        <div class="progress-bar bg-blue-600 h-6 rounded-full flex items-center justify-center text-xs text-white font-medium" id="importProgress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h5 class="text-md font-medium text-gray-700 mb-3">Thông tin import</h5>
                            <div class="space-y-2">
                                <p class="text-sm text-gray-600" id="currentPage">Trang: 0/0</p>
                                <p class="text-sm text-gray-600" id="currentMovie">Phim: 0/0</p>
                                <p class="text-sm text-green-600" id="importedCount">Đã import: 0 phim</p>
                                <p class="text-sm text-yellow-600" id="skippedCount">Đã bỏ qua: 0 phim</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h5 class="text-md font-medium text-gray-700 mb-3">Thời gian</h5>
                            <div class="space-y-2">
                                <p class="text-sm text-gray-600" id="elapsedTime">Đã trải qua: 0 giây</p>
                                <p class="text-sm text-gray-600" id="estimatedTime">Thời gian còn lại: Đang tính toán...</p>
                                <p class="text-sm text-gray-600" id="statusMessage">Đang chuẩn bị...</p>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Results Section -->
                <?php if (!empty($result)): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <?php if (isset($result['error'])): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                            <p><?php echo $result['error']; ?></p>
                        </div>
                    <?php else: ?>
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Kết quả import:</h3>
                        
                        <?php if (isset($result['summary'])): ?>
                            <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-4">
                                <p class="font-medium">Tổng số phim đã xử lý: <?php echo $result['summary']['total_movies']; ?></p>
                                <p class="font-medium">Tổng số phim đã import: <?php echo $result['summary']['total_imported']; ?></p>
                                <p class="font-medium">Tổng số phim đã bỏ qua: <?php echo $result['summary']['total_skipped']; ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($result['pages']) && !empty($result['pages'])): ?>
                            <h4 class="text-md font-medium text-gray-700 mb-3">Chi tiết từng trang:</h4>
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Hình ảnh</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tên phim</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thể loại</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ngôn ngữ</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Năm</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thao tác</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($result['pages'] as $pageResult): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['page']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap">
                                                    <?php if (!empty($pageResult['errors'])): ?>
                                                        <img src="https://via.placeholder.com/120x160?text=No+Image" 
                                                             alt="No Image" 
                                                             class="h-16 w-12 object-cover rounded">
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['total']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['imported']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['skipped']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm">
                                                    <?php if (!empty($pageResult['errors'])): ?>
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-red-100 text-red-800"><?php echo count($pageResult['errors']); ?> lỗi</span>
                                                    <?php else: ?>
                                                        <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Không có</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <?php if (!empty($pageResult['errors'])): ?>
                                                        <button type="button" class="text-blue-600 hover:text-blue-900 mr-3" onclick="togglePageDetails(<?php echo $pageResult['page']; ?>)">
                                                            <i class="fas fa-info-circle"></i> Xem chi tiết
                                                        </button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if (!empty($pageResult['errors'])): ?>
                                                <tr id="pageDetails<?php echo $pageResult['page']; ?>" class="hidden bg-gray-50">
                                                    <td colspan="6" class="px-6 py-4">
                                                        <div class="text-sm text-red-600">
                                                            <h6 class="font-medium mb-2">Chi tiết lỗi:</h6>
                                                            <ul class="list-disc pl-5 space-y-1">
                                                                <?php foreach ($pageResult['errors'] as $error): ?>
                                                                    <li><?php echo htmlspecialchars($error); ?></li>
                                                                <?php endforeach; ?>
                                                            </ul>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0-alpha1/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Sidebar Toggle
            const sidebarToggle = document.getElementById('sidebarToggle');
            const sidebar = document.querySelector('.sidebar');
            const content = document.querySelector('.content');
            
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('active');
                if (window.innerWidth <= 768) {
                    content.classList.toggle('ml-0');
                }
            });
            
            // Import Form
            const importForm = document.getElementById('importForm');
            const progressSection = document.getElementById('progressSection');
            const importProgress = document.getElementById('importProgress');
            const currentPage = document.getElementById('currentPage');
            const currentMovie = document.getElementById('currentMovie');
            const importedCount = document.getElementById('importedCount');
            const skippedCount = document.getElementById('skippedCount');
            const elapsedTime = document.getElementById('elapsedTime');
            const estimatedTime = document.getElementById('estimatedTime');
            const statusMessage = document.getElementById('statusMessage');
            
            let startTime;
            let timerInterval;
            let progressInterval;
            let totalPages;
            let totalMovies;
            let processedMovies = 0;
            let importedMovies = 0;
            let skippedMovies = 0;
            
            importForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                // Hiển thị phần tiến độ
                progressSection.style.display = 'block';
                
                // Lấy thông tin từ form
                const startPage = parseInt(document.getElementById('start_page').value);
                const endPage = parseInt(document.getElementById('end_page').value);
                const limit = parseInt(document.getElementById('limit').value);
                
                // Tính toán tổng số trang và phim
                totalPages = endPage - startPage + 1;
                totalMovies = totalPages * limit;
                
                // Khởi tạo biến theo dõi
                processedMovies = 0;
                importedMovies = 0;
                skippedMovies = 0;
                startTime = new Date();
                
                // Bắt đầu đếm thời gian
                clearInterval(timerInterval);
                timerInterval = setInterval(updateTimer, 1000);
                
                // Cập nhật trạng thái ban đầu
                updateProgress(0, 0, 0);
                statusMessage.textContent = 'Đang bắt đầu import...';
                
                // Bắt đầu mô phỏng tiến độ
                startProgressSimulation();
                
                // Gửi form bằng AJAX
                const formData = new FormData(importForm);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    // Dừng mô phỏng tiến độ
                    clearInterval(progressInterval);
                    
                    // Cập nhật tiến độ khi nhận được phản hồi
                    updateProgress(100, totalPages, totalMovies);
                    statusMessage.textContent = 'Import hoàn tất!';
                    clearInterval(timerInterval);
                    
                    // Cập nhật nội dung trang
                    document.body.innerHTML = html;
                })
                .catch(error => {
                    // Dừng mô phỏng tiến độ
                    clearInterval(progressInterval);
                    
                    statusMessage.textContent = 'Lỗi: ' + error.message;
                    clearInterval(timerInterval);
                });
            });
            
            function updateProgress(percent, currentPageNum, currentMovieNum) {
                // Cập nhật thanh tiến độ
                importProgress.style.width = percent + '%';
                importProgress.textContent = percent + '%';
                importProgress.setAttribute('aria-valuenow', percent);
                
                // Cập nhật thông tin trang và phim
                currentPage.textContent = `Trang: ${currentPageNum}/${totalPages}`;
                currentMovie.textContent = `Phim: ${currentMovieNum}/${totalMovies}`;
                
                // Cập nhật số lượng phim đã import và bỏ qua
                importedCount.textContent = `Đã import: ${importedMovies} phim`;
                skippedCount.textContent = `Đã bỏ qua: ${skippedMovies} phim`;
            }
            
            function updateTimer() {
                const now = new Date();
                const elapsed = Math.floor((now - startTime) / 1000);
                
                elapsedTime.textContent = `Đã trải qua: ${formatTime(elapsed)}`;
                
                if (processedMovies > 0) {
                    const timePerMovie = elapsed / processedMovies;
                    const remainingMovies = totalMovies - processedMovies;
                    const remainingSeconds = Math.ceil(timePerMovie * remainingMovies);
                    
                    estimatedTime.textContent = `Thời gian còn lại: ${formatTime(remainingSeconds)}`;
                }
            }
            
            function formatTime(seconds) {
                if (seconds < 60) {
                    return `${seconds} giây`;
                } else if (seconds < 3600) {
                    const minutes = Math.floor(seconds / 60);
                    const remainingSeconds = seconds % 60;
                    return `${minutes} phút ${remainingSeconds} giây`;
                } else {
                    const hours = Math.floor(seconds / 3600);
                    const minutes = Math.floor((seconds % 3600) / 60);
                    return `${hours} giờ ${minutes} phút`;
                }
            }
            
            function startProgressSimulation() {
                // Xóa interval cũ nếu có
                if (progressInterval) {
                    clearInterval(progressInterval);
                }
                
                let currentPageNum = 0;
                let currentMovieNum = 0;
                let progressPercent = 0;
                
                // Tạo interval mới để cập nhật tiến độ
                progressInterval = setInterval(() => {
                    // Kiểm tra nếu đã xử lý hết tất cả phim
                    if (currentMovieNum >= totalMovies) {
                        clearInterval(progressInterval);
                        return;
                    }
                    
                    // Xử lý một số phim mỗi lần cập nhật
                    const moviesToProcess = Math.ceil(Math.random() * 3) + 1; // 1-3 phim mỗi lần
                    
                    for (let i = 0; i < moviesToProcess; i++) {
                        if (currentMovieNum >= totalMovies) {
                            break;
                        }
                        
                        // Xử lý phim tiếp theo
                        currentMovieNum++;
                        processedMovies++;
                        
                        // Ngẫu nhiên quyết định phim được import hay bỏ qua
                        if (Math.random() > 0.3) {
                            importedMovies++;
                        } else {
                            skippedMovies++;
                        }
                        
                        // Cập nhật số trang hiện tại
                        currentPageNum = Math.ceil(currentMovieNum / (totalMovies / totalPages));
                    }
                    
                    // Cập nhật tiến độ
                    progressPercent = Math.min(100, Math.floor((currentMovieNum / totalMovies) * 100));
                    updateProgress(progressPercent, currentPageNum, currentMovieNum);
                    
                    // Cập nhật thông báo trạng thái
                    if (currentMovieNum % 5 === 0 || currentMovieNum === 1) {
                        statusMessage.textContent = `Đang xử lý trang ${currentPageNum}, phim ${currentMovieNum}/${totalMovies}...`;
                    }
                }, 500); // Cập nhật mỗi 500ms
            }
        });

        function togglePageDetails(pageNum) {
            const detailsRow = document.getElementById(`pageDetails${pageNum}`);
            if (detailsRow) {
                if (detailsRow.classList.contains('hidden')) {
                    // Đóng tất cả các chi tiết khác trước khi mở cái mới
                    document.querySelectorAll('[id^="pageDetails"]').forEach(row => {
                        if (row.id !== `pageDetails${pageNum}`) {
                            row.classList.add('hidden');
                        }
                    });
                    // Mở chi tiết được chọn
                    detailsRow.classList.remove('hidden');
                } else {
                    // Đóng chi tiết nếu đang mở
                    detailsRow.classList.add('hidden');
                }
            }
        }
    </script>

    <!-- Movie Detail Modal -->
    <div id="movieDetailModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 hidden">
        <div class="relative top-20 mx-auto p-5 border w-4/5 shadow-lg rounded-md bg-white" onclick="event.stopPropagation();">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-semibold text-gray-800">Chi tiết phim: <span id="movieName"></span></h3>
                <button onclick="closeMovieDetail()" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </button>
            </div>
            
            <div id="movieDetailContent" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Content will be loaded via AJAX -->
                <div class="col-span-2 text-center">
                    <i class="fas fa-spinner fa-spin text-3xl text-blue-600"></i>
                    <p class="mt-2 text-gray-600">Đang tải thông tin phim...</p>
                </div>
            </div>
        </div>
    </div>

    <script>
    function showMovieDetail(movieId, event) {
        // Prevent the event from bubbling up
        event.preventDefault();
        event.stopPropagation();
        
        // Show modal and loading state
        const modal = document.getElementById('movieDetailModal');
        modal.classList.remove('hidden');
        
        // Fetch movie details
        fetch(`get_movie_detail.php?id=${movieId}`)
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    const movie = data.movie;
                    document.getElementById('movieName').textContent = movie.vod_name;
                    
                    // Create the content HTML
                    const content = `
                        <div>
                            <h4 class="text-md font-medium text-gray-700 mb-2">Thông tin cơ bản</h4>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">ID:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_id}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Tên phim:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_name}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Tên tiếng Anh:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_en}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Thể loại:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_class}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Năm:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_year}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Quốc gia:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_area}</div>
                                </div>
                            </div>
                        </div>
                        <div>
                            <h4 class="text-md font-medium text-gray-700 mb-2">Thông tin chi tiết</h4>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Diễn viên:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_actor}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Đạo diễn:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_director}</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Thời lượng:</div>
                                    <div class="text-sm text-gray-900 col-span-2">${movie.vod_duration} phút</div>
                                </div>
                                <div class="grid grid-cols-3 gap-2 mb-2">
                                    <div class="text-sm font-medium text-gray-500">Trạng thái:</div>
                                    <div class="text-sm text-gray-900 col-span-2">
                                        ${movie.vod_isend == 1 
                                            ? '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Hoàn thành</span>'
                                            : '<span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Đang cập nhật</span>'}
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-span-2">
                            <h4 class="text-md font-medium text-gray-700 mb-2">Nội dung phim</h4>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-sm text-gray-900">${movie.vod_content.replace(/\n/g, '<br>')}</p>
                            </div>
                        </div>
                        <div class="col-span-2">
                            <h4 class="text-md font-medium text-gray-700 mb-2">Mô tả ngắn</h4>
                            <div class="bg-gray-50 p-4 rounded-lg">
                                <p class="text-sm text-gray-900">${movie.vod_blurb.replace(/\n/g, '<br>')}</p>
                            </div>
                        </div>
                    `;
                    
                    document.getElementById('movieDetailContent').innerHTML = content;
                } else {
                    alert('Không thể lấy thông tin phim: ' + data.message);
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Đã xảy ra lỗi khi lấy thông tin phim');
            });
    }

    function closeMovieDetail() {
        const modal = document.getElementById('movieDetailModal');
        modal.classList.add('hidden');
    }

    // Close modal when clicking outside
    document.getElementById('movieDetailModal').addEventListener('click', function(event) {
        if (event.target === this) {
            closeMovieDetail();
        }
    });
    </script>
</body>
</html>