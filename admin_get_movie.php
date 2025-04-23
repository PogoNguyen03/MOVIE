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

// Function to import movie to database (handles both INSERT and UPDATE)
function importMovieToDB($movie, $pdo, $existingVodId = null) {
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
        $playFrom = 'ngm3u8'; // Mặc định
        if (!empty($episodeInfo)) {
            $playFrom = $episodeInfo[0]['server_name'] ?? 'ngm3u8'; // Lấy server_name từ tập đầu tiên nếu có
        foreach ($episodeInfo as $episode) {
            // Nếu là phim lẻ (type_id = 2), sử dụng server_name làm tên tập
            if ($typeId == 2) {
                    $episodeData[] = $episode['server_name'] . '$' . $episode['embed'];
            } else {
                    $episodeData[] = $episode['name'] . '$' . $episode['embed'];
                }
            }
        }
        $playUrl = implode('#', $episodeData);

        // Prepare SQL statement (Common fields for INSERT and UPDATE)
        $fields = [
            'vod_name' => $movie['name'] ?? '',
            'vod_sub' => $movie['slug'] ?? '',
            'vod_en' => $movie['original_name'] ?? '',
            'vod_tag' => !empty($categoryInfo['genres']) ? implode(',', $categoryInfo['genres']) : '',
            'vod_class' => '', // Cần xác định logic lấy class nếu có
            'vod_pic' => $movie['poster_url'] ?? '',
            'vod_pic_thumb' => $movie['thumb_url'] ?? '',
            'vod_actor' => $movie['casts'] ?? '',
            'vod_director' => $movie['director'] ?? '',
            'vod_writer' => '',
            'vod_behind' => '',
            'vod_content' => $movie['description'] ?? '',
            'vod_blurb' => mb_substr($movie['description'] ?? '', 0, 200, 'UTF-8'),
            'vod_year' => !empty($categoryInfo['year']) ? $categoryInfo['year'] : date('Y'),
            'vod_area' => !empty($categoryInfo['country']) ? $categoryInfo['country'] : 'Đang cập nhật',
            'vod_state' => $movie['episode_current'] ?? '', // Cập nhật trạng thái tập
            'vod_serial' => $typeId == 2 ? 0 : 1,
            'vod_tv' => 0,
            'vod_weekday' => '',
            'vod_total' => $movie['episode_total'] ?? count($episodeData), // Cập nhật tổng số tập
            'vod_trysee' => 0,
            'vod_duration' => $duration,
            'vod_remarks' => $movie['quality'] ?? ($movie['lang'] ?? ''), // Cập nhật remarks
            'vod_tpl' => '',
            'vod_tpl_play' => '',
            'vod_tpl_down' => '',
            'vod_isend' => ($movie['status'] ?? '') === 'completed' ? 1 : 0, // Cập nhật trạng thái hoàn thành
            'vod_lock' => 0,
            'vod_level' => 0,
            'vod_copyright' => 0,
            'vod_points' => 0,
            'vod_points_play' => 0,
            'vod_points_down' => 0,
            'vod_play_from' => $playFrom,
            'vod_play_server' => '',
            'vod_play_note' => '',
            'vod_play_url' => $playUrl,
            'vod_down_from' => '',
            'vod_down_server' => '',
            'vod_down_note' => '',
            'vod_down_url' => '',
            'vod_time' => time(),
            'vod_reurl' => '',
            'vod_rel_vod' => '',
            'vod_rel_art' => '',
            'vod_pwd' => '',
            'vod_pwd_url' => '',
            'vod_pwd_play' => '',
            'vod_pwd_play_url' => '',
            'vod_pwd_down' => '',
            'vod_pwd_down_url' => '',
            'vod_plot' => 0,
            'vod_plot_name' => '',
            'vod_plot_detail' => '',
            'vod_status' => 1,
            'vod_version' => '',
            'type_id' => $typeId
        ];

        // Xử lý ngôn ngữ riêng để tránh lỗi độ dài
        $language = !empty($movie['lang']) ? $movie['lang'] : 'Phụ đề Việt';
        $language = str_replace(['+', '_'], [',', ' '], $language);
        $language = preg_replace('/\s+/', ' ', $language);
        $language = trim($language);
        if (mb_strlen($language, 'UTF-8') > 50) {
            $language = mb_substr($language, 0, 50, 'UTF-8');
            $lastSpace = mb_strrpos($language, ' ', 0, 'UTF-8');
            if ($lastSpace !== false) {
                $language = mb_substr($language, 0, $lastSpace, 'UTF-8');
            }
        }
        $fields['vod_lang'] = $language;

        if ($existingVodId !== null) {
            // UPDATE existing movie
            $setClauses = [];
            foreach (array_keys($fields) as $field) {
                $setClauses[] = "`$field` = :$field";
            }
            $sql = "UPDATE mac_vod SET " . implode(', ', $setClauses) . " WHERE vod_id = :vod_id";
            $stmt = $pdo->prepare($sql);
            $fields['vod_id'] = $existingVodId;
        } else {
            // INSERT new movie
            // Thêm các trường chỉ có khi INSERT
            $fields['vod_hits'] = 0;
            $fields['vod_hits_day'] = 0;
            $fields['vod_hits_week'] = 0;
            $fields['vod_hits_month'] = 0;
            $fields['vod_up'] = 0;
            $fields['vod_down'] = 0;
            $fields['vod_score'] = 0;
            $fields['vod_score_all'] = 0;
            $fields['vod_score_num'] = 0;
            $fields['vod_author'] = '';
            $fields['vod_jumpurl'] = '';
            $fields['vod_time_add'] = time();
            $fields['vod_time_hits'] = 0;
            $fields['vod_time_make'] = 0;
            $fields['vod_douban_id'] = 0;
            $fields['vod_douban_score'] = 0;

            $sql = "INSERT INTO mac_vod (" . implode(', ', array_map(function($f){ return "`$f`"; }, array_keys($fields))) . ") VALUES (:" . implode(', :', array_keys($fields)) . ")";
            $stmt = $pdo->prepare($sql);
        }
        
        // Bind values
        foreach ($fields as $key => $value) {
             if($key === 'vod_play_url' && is_array($value)) {
                 $stmt->bindValue(":$key", json_encode($value));
             } else {
                 $stmt->bindValue(":$key", $value);
             }
         }
        
        $stmt->execute();
        
        return ['success' => true, 'action' => ($existingVodId !== null ? 'updated' : 'inserted')];
    } catch (PDOException $e) {
        error_log("Database error when importing/updating movie {$movie['name']}: " . $e->getMessage());
        return ['error' => 'Lỗi cơ sở dữ liệu: ' . $e->getMessage()];
    } catch (Exception $e) {
        error_log("General error when importing/updating movie {$movie['name']}: " . $e->getMessage());
        return ['error' => 'Lỗi không xác định: ' . $e->getMessage()];
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
    $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] === '1'; // Đổi tên biến
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
                $totalUpdatedCount = 0; // Thêm biến đếm cập nhật
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
                        'updated' => 0, // Thêm đếm cập nhật cho trang
                        'errors' => []
                    ];
                    
                    // Fetch movies from API using category URL
                    $url = API_BASE_URL . $categoryUrl . "?page=" . $page;
                    $response = makeRequest($url);
                    
                    if (isset($response['data'])) {
                        $data = json_decode($response['data'], true);
                        if (json_last_error() === JSON_ERROR_NONE && isset($data['items']) && !empty($data['items'])) {
                            $moviesOnPage = $data['items'];
                            $totalMoviesOnPage = count($moviesOnPage);
                            $importedCount = 0;
                            $skippedCount = 0;
                            $updatedCount = 0; // Đếm cập nhật cho trang
                            
                            $pageResult['total'] = $totalMoviesOnPage;
                            
                            // Giới hạn số lượng phim xử lý trên mỗi trang
                            $moviesToProcess = array_slice($moviesOnPage, 0, $limit);
                            
                            foreach ($moviesToProcess as $movie) {
                                $totalMoviesCount++; // Đếm tổng số phim đã xử lý
                                
                                // Check if movie already exists
                                $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_sub = ?");
                                $stmt->execute([$movie['slug']]);
                                $existingMovie = $stmt->fetch(PDO::FETCH_ASSOC);
                                
                                // Fetch detailed movie information regardless of existence if update is enabled
                                $movieDetails = null;
                                if (!$existingMovie || $update_existing) {
                                    $movieDetails = fetchMovieDetails($movie['slug']);
                                }

                                if ($existingMovie) {
                                    if ($update_existing && $movieDetails) {
                                        // Cập nhật phim hiện có
                                        $importResult = importMovieToDB($movieDetails, $pdo, $existingMovie['vod_id']);
                                        if ($importResult['success']) {
                                            $pageResult['updated']++;
                                            $updatedCount++;
                                        } else {
                                            $pageResult['errors'][] = "Lỗi cập nhật phim '{$movie['name']}': " . ($importResult['error'] ?? 'Unknown error');
                                        }
                                    } else {
                                        // Bỏ qua phim hiện có nếu không chọn cập nhật
                                        $pageResult['skipped']++;
                                        $skippedCount++;
                                    }
                                } elseif ($movieDetails) {
                                    // Import phim mới
                                    $importResult = importMovieToDB($movieDetails, $pdo);
                                    if ($importResult['success']) {
                                        $pageResult['imported']++;
                                        $importedCount++;
                                    } else {
                                        $pageResult['errors'][] = "Lỗi import phim '{$movie['name']}': " . ($importResult['error'] ?? 'Unknown error');
                                    }
                                } else {
                                    // Lỗi không lấy được chi tiết phim mới
                                     $pageResult['errors'][] = "Không thể lấy thông tin chi tiết phim mới '{$movie['name']}'.";
                                     error_log("Skipping movie '{$movie['name']}' (slug: {$movie['slug']}) because details could not be fetched.");
                                }
                            }
                            
                            $totalImportedCount += $importedCount;
                            $totalSkippedCount += $skippedCount;
                            $totalUpdatedCount += $updatedCount;
                            
                        } else {
                            $pageResult['errors'][] = "Lỗi khi parse JSON hoặc không tìm thấy phim nào trên trang {$page}. Chi tiết lỗi JSON: " . json_last_error_msg();
                            error_log("JSON parse error or no items found on page {$page}. Response: " . $response['data']);
                        }
                    } else {
                        $pageResult['errors'][] = "Không thể kết nối đến API cho trang {$page}: " . ($response['error'] ?? 'Lỗi không xác định');
                    }
                    
                    $result['pages'][] = $pageResult;
                }
                
                $result['summary'] = [
                    'total_movies_processed' => $totalMoviesCount,
                    'total_imported' => $totalImportedCount,
                    'total_skipped' => $totalSkippedCount,
                    'total_updated' => $totalUpdatedCount // Thêm tổng số cập nhật
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
                                        <input type="radio" name="update_existing" value="0" checked class="form-radio h-4 w-4 text-blue-600">
                                        <span class="ml-2 text-gray-700">Bỏ qua</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="update_existing" value="1" class="form-radio h-4 w-4 text-blue-600">
                                        <span class="ml-2 text-gray-700">Cập nhật</span>
                                    </label>
                                </div>
                            </div>
                        </div>
                        <div class="mt-6">
                            <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2" id="importButton">
                                <i class="fas fa-download mr-2"></i>Import / Cập nhật Phim
                            </button>
                        </div>
                    </form>
                </div>
                
                <!-- Progress Section -->
                <div id="progressSection" style="display: none;" class="bg-white rounded-lg shadow-sm p-6 mb-6">
                    <h3 class="text-lg font-semibold text-gray-800 mb-4">Tiến độ:</h3>
                    <div class="w-full bg-gray-200 rounded-full h-6 mb-4 relative">
                        <div class="progress-bar bg-blue-600 h-6 rounded-full flex items-center justify-center text-xs text-white font-medium" id="importProgress" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h5 class="text-md font-medium text-gray-700 mb-3">Thống kê</h5>
                            <div class="space-y-2">
                                <p class="text-sm text-gray-600" id="currentPage">Trang: 0/0</p>
                                <p class="text-sm text-gray-600" id="currentMovie">Phim: 0/0</p>
                                <p class="text-sm text-green-600" id="importedCount">Đã import mới: 0 phim</p>
                                <p class="text-sm text-blue-600" id="updatedCount">Đã cập nhật: 0 phim</p>
                                <p class="text-sm text-yellow-600" id="skippedCount">Đã bỏ qua: 0 phim</p>
                            </div>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4">
                            <h5 class="text-md font-medium text-gray-700 mb-3">Thời gian</h5>
                            <div class="space-y-2">
                                <p class="text-sm text-gray-600" id="elapsedTime">Đã trải qua: 0 giây</p>
                                <p class="text-sm text-gray-600" id="estimatedTime">Thời gian còn lại: Đang tính toán...</p>
                            </div>
                        </div>
                         <div class="bg-gray-50 rounded-lg p-4">
                             <h5 class="text-md font-medium text-gray-700 mb-3">Trạng thái</h5>
                             <p class="text-sm text-gray-600" id="statusMessage">Đang chuẩn bị...</p>
                        </div>
                    </div>
                </div>
                
                <!-- Results Section -->
                <?php if (!empty($result)): ?>
                <div class="bg-white rounded-lg shadow-sm p-6">
                    <?php if (isset($result['error'])): ?>
                        <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                            <p><?php echo htmlspecialchars($result['error']); ?></p>
                        </div>
                    <?php else: ?>
                        <h3 class="text-lg font-semibold text-gray-800 mb-4">Kết quả:</h3>
                        
                        <?php if (isset($result['summary'])): ?>
                            <div class="bg-blue-50 border-l-4 border-blue-500 text-blue-700 p-4 mb-4">
                                <p class="font-medium">Tổng số phim đã xử lý: <?php echo $result['summary']['total_movies_processed']; ?></p>
                                <p class="font-medium">Tổng số phim mới đã import: <?php echo $result['summary']['total_imported']; ?></p>
                                <p class="font-medium">Tổng số phim đã cập nhật: <?php echo $result['summary']['total_updated']; ?></p>
                                <p class="font-medium">Tổng số phim đã bỏ qua: <?php echo $result['summary']['total_skipped']; ?></p>
                            </div>
                        <?php endif; ?>
                        
                        <?php if (isset($result['pages']) && !empty($result['pages'])): ?>
                            <h4 class="text-md font-medium text-gray-700 mb-3">Chi tiết từng trang:</h4>
                            
                            <div class="overflow-x-auto">
                                <table class="min-w-full divide-y divide-gray-200">
                                    <thead class="bg-gray-50">
                                        <tr>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trang</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tổng phim</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Import mới</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cập nhật</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Bỏ qua</th>
                                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lỗi</th>
                                        </tr>
                                    </thead>
                                    <tbody class="bg-white divide-y divide-gray-200">
                                        <?php foreach ($result['pages'] as $pageResult): ?>
                                            <tr class="hover:bg-gray-50">
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['page']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $pageResult['total']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-green-600"><?php echo $pageResult['imported']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-blue-600"><?php echo $pageResult['updated']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm text-yellow-600"><?php echo $pageResult['skipped']; ?></td>
                                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                                                    <?php if (!empty($pageResult['errors'])): ?>
                                                        <button type="button" class="text-red-600 hover:text-red-900 mr-3" onclick="togglePageDetails(<?php echo $pageResult['page']; ?>)">
                                                            <?php echo count($pageResult['errors']); ?> lỗi
                                                        </button>
                                                    <?php else: ?>
                                                        <span class="text-gray-500">Không có lỗi</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                            <?php if (!empty($pageResult['errors'])): ?>
                                                <tr id="pageDetails<?php echo $pageResult['page']; ?>" class="hidden bg-red-50">
                                    <td colspan="6" class="px-6 py-4">
                                                        <div class="text-sm text-red-700">
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
            const importedCountEl = document.getElementById('importedCount');
            const updatedCountEl = document.getElementById('updatedCount');
            const skippedCountEl = document.getElementById('skippedCount');
            const elapsedTime = document.getElementById('elapsedTime');
            const estimatedTime = document.getElementById('estimatedTime');
            const statusMessage = document.getElementById('statusMessage');
            
            let startTime;
            let timerInterval;
            let progressInterval;
            let totalPages;
            let totalMoviesToProcess;
            let processedMovies = 0;
            let importedMovies = 0;
            let updatedMovies = 0;
            let skippedMovies = 0;
            
            importForm.addEventListener('submit', function(e) {
                e.preventDefault();
                
                progressSection.style.display = 'block';
                
                const startPage = parseInt(document.getElementById('start_page').value);
                const endPage = parseInt(document.getElementById('end_page').value);
                const limit = parseInt(document.getElementById('limit').value);
                
                totalPages = endPage - startPage + 1;
                totalMoviesToProcess = totalPages * limit;
                
                processedMovies = 0;
                importedMovies = 0;
                updatedMovies = 0;
                skippedMovies = 0;
                startTime = new Date();
                
                clearInterval(timerInterval);
                timerInterval = setInterval(updateTimer, 1000);
                
                updateProgressUI(0, 0, 0);
                statusMessage.textContent = 'Đang bắt đầu...';
                
                const formData = new FormData(importForm);
                
                fetch('', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.text())
                .then(html => {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    const newBody = doc.body;
                    document.body.innerHTML = newBody.innerHTML;
                    
                    clearInterval(timerInterval);
                    statusMessage.textContent = 'Hoàn tất!';
                    progressSection.style.display = 'none';
                })
                .catch(error => {
                    clearInterval(timerInterval);
                    statusMessage.textContent = 'Lỗi: ' + error.message;
                    progressSection.style.display = 'none';
                    console.error('Fetch error:', error);
                    alert('Có lỗi xảy ra trong quá trình xử lý. Vui lòng kiểm tra Console.');
                });
            });
            
            function updateProgressUI(percent, currentPageNum, currentMovieNumInPage) {
                importProgress.style.width = percent + '%';
                importProgress.textContent = percent + '%';
                importProgress.setAttribute('aria-valuenow', percent);
                
                currentPage.textContent = `Trang: ${currentPageNum}/${totalPages}`;
                importedCountEl.textContent = `Đã import mới: ${importedMovies} phim`;
                updatedCountEl.textContent = `Đã cập nhật: ${updatedMovies} phim`;
                skippedCountEl.textContent = `Đã bỏ qua: ${skippedMovies} phim`;
            }
            
            function updateTimer() {
                const now = new Date();
                const elapsed = Math.floor((now - startTime) / 1000);
                
                elapsedTime.textContent = `Đã trải qua: ${formatTime(elapsed)}`;
                
                estimatedTime.textContent = `Thời gian còn lại: Đang tính toán...`;
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
        });

        function togglePageDetails(pageNum) {
            const detailsRow = document.getElementById(`pageDetails${pageNum}`);
            if (detailsRow) {
                if (detailsRow.classList.contains('hidden')) {
                    document.querySelectorAll('[id^="pageDetails"]').forEach(row => {
                        if (row.id !== `pageDetails${pageNum}`) {
                            row.classList.add('hidden');
                        }
                    });
                    detailsRow.classList.remove('hidden');
                } else {
                    detailsRow.classList.add('hidden');
                }
            }
        }
    </script>