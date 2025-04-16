<?php
require_once 'config.php';
require_once 'auth.php';

// Kiểm tra xác thực
checkAuth();

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
    die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
}

$pageTitle = 'Import Phim';
require_once 'layout.php';

// Function to make HTTP request with cURL
function makeRequest($url, $headers = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        return ['error' => "Lỗi cURL: " . $error];
    }
    
    if ($httpCode !== 200) {
        return ['error' => "Lỗi HTTP: " . $httpCode . " - URL: " . $url];
    }
    
    return ['data' => $response];
}

// Function to extract category information
function extractCategoryInfo($category) {
    $info = [
        'genres' => [],
        'year' => '',
        'country' => ''
    ];
    
    if (!is_array($category)) {
        return $info;
    }
    
    foreach ($category as $groupKey => $group) {
        if (!isset($group['group']) || !isset($group['list']) || !is_array($group['list'])) {
            continue;
        }
        
        $groupName = strtolower($group['group']['name']);
        
        if ($groupName === 'năm') {
            $info['year'] = isset($group['list'][0]['name']) ? $group['list'][0]['name'] : '';
        } elseif ($groupName === 'quốc gia') {
            $info['country'] = isset($group['list'][0]['name']) ? $group['list'][0]['name'] : '';
        } elseif ($groupName === 'thể loại') {
            foreach ($group['list'] as $item) {
                if (isset($item['name'])) {
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
                // Xử lý tên tập phim
                $episodeName = $episode['name'];
                // Loại bỏ "Thứ" và "tập" từ tên tập
                $episodeName = str_replace(['Thứ', 'tập'], '', $episodeName);
                // Loại bỏ khoảng trắng thừa
                $episodeName = trim($episodeName);
                // Chỉ lấy số tập
                preg_match('/\d+/', $episodeName, $matches);
                $episodeName = isset($matches[0]) ? $matches[0] : $episodeName;
                
                $episodeInfo[] = [
                    'name' => $episodeName,
                    'slug' => $episode['slug'],
                    'embed' => $episode['embed'],
                    'server_name' => $server['server_name']
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
    // Chuyển đổi ký tự đặc biệt thành ký tự thường
    $text = mb_strtolower($text, 'UTF-8');
    
    // Thay thế khoảng trắng bằng dấu gạch ngang
    $text = preg_replace('/\s+/', '-', $text);
    
    // Giữ lại các ký tự đặc biệt tiếng Việt
    $text = preg_replace('/[^a-z0-9\-àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/', '', $text);
    
    // Loại bỏ dấu gạch ngang liên tiếp
    $text = preg_replace('/-+/', '-', $text);
    
    // Loại bỏ dấu gạch ngang ở đầu và cuối
    $text = trim($text, '-');
    
    return $text;
}

// Function to determine type_id based on category information
function determineTypeId($category) {
    if (!is_array($category)) {
        return 1; // Default to "Phim bộ" if category is not an array
    }
    
    $isAnimated = false;
    $isTVShow = false;
    $isSeries = false;
    $isMovie = false;
    
    // Loop through each category group
    foreach ($category as $groupKey => $group) {
        if (isset($group['group']) && isset($group['list'])) {
            $groupName = $group['group']['name'];
            
            // Check for "Thể loại" group first to identify animations
            if ($groupName === 'Thể loại') {
                foreach ($group['list'] as $item) {
                    $genreName = $item['name'];
                    if ($genreName === 'Hoạt Hình') {
                        $isAnimated = true;
                        // If it's an animation, we can stop checking other categories
                        return 3;
                    }
                }
            }
            
            // Check for "Định dạng" group only if not an animation
            if ($groupName === 'Định dạng') {
                foreach ($group['list'] as $item) {
                    $formatName = $item['name'];
                    if ($formatName === 'Phim bộ') {
                        $isSeries = true;
                    } elseif ($formatName === 'Phim lẻ') {
                        $isMovie = true;
                    } elseif ($formatName === 'TV shows') {
                        $isTVShow = true;
                    }
                }
            }
        }
    }
    
    // Determine type_id based on the checks
    if ($isTVShow) {
        return 4; // TV shows
    } elseif ($isMovie) {
        return 2; // Phim lẻ
    } else {
        return 1; // Default to "Phim bộ"
    }
}

// Function to fetch movie details from API
function fetchMovieDetails($slug) {
    // Chuẩn hóa slug trước khi gọi API
    $slug = normalizeSlug($slug);
    $url = API_BASE_URL . "/film/" . $slug;
    
    // Log URL để debug
    error_log("Fetching movie details from URL: " . $url);
    
    $response = makeRequest($url);
    
    if (isset($response['data'])) {
        $data = json_decode($response['data'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['movie'])) {
            return $data['movie'];
        } else {
            // Log lỗi JSON
            error_log("JSON decode error: " . json_last_error_msg() . " for movie: " . $slug);
            error_log("Response data: " . $response['data']);
            return null;
        }
    } else {
        // Log lỗi API
        error_log("API error for movie: " . $slug . " - " . ($response['error'] ?? 'Unknown error'));
        return null;
    }
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
            // Nếu là phim lẻ (type_id = 2), sử dụng server_name làm tên tập
            if ($typeId == 2) {
                $episodeData[] = [
                    'name' => $episode['server_name'],
                    'embed' => $episode['embed']
                ];
            } else {
                $episodeData[] = $episode['embed'];
            }
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
        $stmt->bindValue(':vod_class', '');
        $stmt->bindValue(':vod_pic', $movie['poster_url'] ?? '');
        $stmt->bindValue(':vod_pic_thumb', $movie['thumb_url'] ?? '');
        $stmt->bindValue(':vod_actor', $movie['casts'] ?? '');
        $stmt->bindValue(':vod_director', $movie['director'] ?? '');
        $stmt->bindValue(':vod_writer', '');
        $stmt->bindValue(':vod_behind', '');
        $stmt->bindValue(':vod_content', $movie['description'] ?? '');
        $stmt->bindValue(':vod_blurb', mb_substr($movie['description'] ?? '', 0, 200, 'UTF-8'));
        $stmt->bindValue(':vod_year', !empty($categoryInfo['year']) ? $categoryInfo['year'] : date('Y'));
        $stmt->bindValue(':vod_area', !empty($categoryInfo['country']) ? $categoryInfo['country'] : 'Đang cập nhật');
        
        // Xử lý và giới hạn độ dài của ngôn ngữ
        $language = !empty($movie['language']) ? $movie['language'] : 'Phụ đề Việt';
        $language = str_replace('+', ',', $language);
        $language = preg_replace('/\s+/', ' ', $language);
        $language = trim($language);
        $language = mb_convert_encoding($language, 'UTF-8', 'UTF-8');
        if (mb_strlen($language, 'UTF-8') > 50) {
            $language = mb_substr($language, 0, 50, 'UTF-8');
            $lastSpace = mb_strrpos($language, ' ', 0, 'UTF-8');
            if ($lastSpace !== false) {
                $language = mb_substr($language, 0, $lastSpace, 'UTF-8');
            }
        }
        $stmt->bindValue(':vod_lang', $language);
        
        $stmt->bindValue(':vod_state', 1);
        $stmt->bindValue(':vod_serial', $typeId == 2 ? 0 : 1);
        $stmt->bindValue(':vod_tv', 0);
        $stmt->bindValue(':vod_weekday', '');
        $stmt->bindValue(':vod_total', count($episodeData));
        $stmt->bindValue(':vod_trysee', 0);
        $stmt->bindValue(':vod_hits', 0);
        $stmt->bindValue(':vod_hits_day', 0);
        $stmt->bindValue(':vod_hits_week', 0);
        $stmt->bindValue(':vod_hits_month', 0);
        $stmt->bindValue(':vod_duration', $duration);
        $stmt->bindValue(':vod_up', 0);
        $stmt->bindValue(':vod_down', 0);
        $stmt->bindValue(':vod_score', 0);
        $stmt->bindValue(':vod_remarks', '');
        $stmt->bindValue(':vod_author', '');
        $stmt->bindValue(':vod_jumpurl', '');
        $stmt->bindValue(':vod_tpl', '');
        $stmt->bindValue(':vod_tpl_play', '');
        $stmt->bindValue(':vod_tpl_down', '');
        $stmt->bindValue(':vod_isend', $typeId == 2 ? 1 : 0);
        $stmt->bindValue(':vod_lock', 0);
        $stmt->bindValue(':vod_level', 0);
        $stmt->bindValue(':vod_copyright', 0);
        $stmt->bindValue(':vod_points', 0);
        $stmt->bindValue(':vod_points_play', 0);
        $stmt->bindValue(':vod_points_down', 0);
        $stmt->bindValue(':vod_play_from', '');
        $stmt->bindValue(':vod_play_server', '');
        $stmt->bindValue(':vod_play_note', '');
        $stmt->bindValue(':vod_play_url', $typeId == 2 ? json_encode($episodeData) : implode('$$$', $episodeData));
        $stmt->bindValue(':vod_down_from', '');
        $stmt->bindValue(':vod_down_server', '');
        $stmt->bindValue(':vod_down_note', '');
        $stmt->bindValue(':vod_down_url', '');
        $stmt->bindValue(':vod_time', time());
        $stmt->bindValue(':vod_time_add', time());
        $stmt->bindValue(':vod_time_hits', time());
        $stmt->bindValue(':vod_time_make', time());
        $stmt->bindValue(':vod_douban_id', '');
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
        $stmt->bindValue(':vod_plot', '');
        $stmt->bindValue(':vod_plot_name', '');
        $stmt->bindValue(':vod_plot_detail', '');
        $stmt->bindValue(':vod_status', 1);
        $stmt->bindValue(':vod_version', '');
        $stmt->bindValue(':type_id', $typeId);
        
        $stmt->execute();
        
        return true;
    } catch (PDOException $e) {
        error_log("Database error: " . $e->getMessage());
        return false;
    }
}

// Define movie categories and their API endpoints
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

// Process form submission
$result = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startPage = isset($_POST['start_page']) ? (int)$_POST['start_page'] : 1;
    $endPage = isset($_POST['end_page']) ? (int)$_POST['end_page'] : 1;
    $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
    $force = isset($_POST['force']) && $_POST['force'] === '1';
    $category = isset($_POST['category']) ? $_POST['category'] : 'phim-moi-cap-nhat';
    
    // Validate category
    if (!array_key_exists($category, $movieCategories)) {
        $result['error'] = "Danh mục không hợp lệ.";
    } else {
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
                
                // Modify the API URL based on selected category
                $categoryUrl = $movieCategories[$category]['url'];
                
                // Process each page
                for ($page = $startPage; $page <= $endPage; $page++) {
                    $pageResult = [
                        'page' => $page,
                        'total' => 0,
                        'imported' => 0,
                        'skipped' => 0,
                        'errors' => []
                    ];
                    
                    // Fetch movies from API using category URL
                    $url = API_BASE_URL . $categoryUrl . "?page=" . $page;
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
                                    // Determine type_id based on category information
                                    $movieDetails['type_id'] = determineTypeId($movieDetails['category']);
                                    
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
}
?>
<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">Import Phim</h1>
    <p class="text-gray-600">Nhập thông tin để import phim từ nguồn dữ liệu</p>
</div>

<!-- Import Form -->
<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <form method="post" action="" id="importForm">
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label for="category" class="block text-sm font-medium text-gray-700 mb-1">Danh mục phim:</label>
                <select class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="category" name="category" required>
                    <?php foreach ($movieCategories as $key => $category): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>"><?php echo htmlspecialchars($category['name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
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
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tổng</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Đã lấy</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bỏ qua</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($result['pages'] as $pageResult): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['page']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['total']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['imported']; ?></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['skipped']; ?></td>
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
                                    <td colspan="5" class="px-6 py-4">
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

<?php require_once 'footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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