<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'layout.php';

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

// Tạo bảng mac_import_queue nếu chưa tồn tại
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS mac_import_queue (
        id INT AUTO_INCREMENT PRIMARY KEY,
        category VARCHAR(50) NOT NULL,
        start_page INT NOT NULL,
        end_page INT NOT NULL,
        limit_per_page INT NOT NULL,
        update_existing TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        progress INT NOT NULL DEFAULT 0,
        total_movies INT NOT NULL DEFAULT 0,
        imported_movies INT NOT NULL DEFAULT 0,
        updated_movies INT NOT NULL DEFAULT 0,
        skipped_movies INT NOT NULL DEFAULT 0,
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (PDOException $e) {
    error_log("Error creating mac_import_queue table: " . $e->getMessage());
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

// Hàm thêm tác vụ vào queue
function addToQueue($category, $startPage, $endPage, $limitPerPage, $updateExisting) {
    try {
        $stmt = $pdo->prepare("INSERT INTO mac_import_queue (category, start_page, end_page, limit_per_page, update_existing) 
                              VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$category, $startPage, $endPage, $limitPerPage, $updateExisting ? 1 : 0]);
        return $pdo->lastInsertId();
    } catch (PDOException $e) {
        error_log("Error adding task to queue: " . $e->getMessage());
        return false;
    }
}

// Hàm lấy danh sách tác vụ trong queue
function getQueueTasks($pdo, $limit = 10) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM mac_import_queue ORDER BY created_at DESC LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting queue tasks: " . $e->getMessage());
        return [];
    }
}

// Xử lý form submit
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $category = $_POST['category'] ?? '';
    $startPage = (int)($_POST['start_page'] ?? 1);
    $endPage = (int)($_POST['end_page'] ?? 1);
    $limitPerPage = (int)($_POST['limit_per_page'] ?? 20);
    $updateExisting = isset($_POST['update_existing']) ? 1 : 0;
    $useQueue = isset($_POST['use_queue']) ? 1 : 0;

    if ($useQueue) {
        // Thêm vào queue
        $queueId = addToQueue($category, $startPage, $endPage, $limitPerPage, $updateExisting);
        if ($queueId) {
            // Chạy script xử lý queue trong background
            $cmd = sprintf(
                'php process_import_queue.php %d > /dev/null 2>&1 &',
                $queueId
            );
            exec($cmd);
            
            $message = "Đã thêm tác vụ vào queue. ID: " . $queueId;
            $messageType = 'success';
        } else {
            $message = "Không thể thêm tác vụ vào queue";
            $messageType = 'error';
        }
    } else {
        // Xử lý trực tiếp
        try {
            $totalMovies = 0;
            $importedMovies = 0;
            $updatedMovies = 0;
            $skippedMovies = 0;

            for ($page = $startPage; $page <= $endPage; $page++) {
                $movies = fetchMovieList($category, $page, $limitPerPage);
                if (empty($movies)) {
                    break;
                }

                foreach ($movies as $movie) {
                    $totalMovies++;
                    
                    // Kiểm tra phim đã tồn tại chưa
                    $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_name = ?");
                    $stmt->execute([$movie['name']]);
                    $existingMovie = $stmt->fetch(PDO::FETCH_ASSOC);

                    if ($existingMovie) {
                        if ($updateExisting) {
                            // Cập nhật phim
                            $movieDetails = fetchMovieDetails($movie['slug']);
                            if ($movieDetails) {
                                importMovieToDB($movieDetails, $pdo, $existingMovie['vod_id']);
                                $updatedMovies++;
                            }
                        } else {
                            $skippedMovies++;
                        }
                    } else {
                        // Import phim mới
                        $movieDetails = fetchMovieDetails($movie['slug']);
                        if ($movieDetails) {
                            importMovieToDB($movieDetails, $pdo);
                            $importedMovies++;
                        }
                    }
                }
            }

            $message = sprintf(
                "Hoàn thành! Tổng: %d, Import: %d, Cập nhật: %d, Bỏ qua: %d",
                $totalMovies,
                $importedMovies,
                $updatedMovies,
                $skippedMovies
            );
            $messageType = 'success';
        } catch (Exception $e) {
            $message = "Lỗi: " . $e->getMessage();
            $messageType = 'error';
        }
    }
}

// Lấy danh sách tác vụ trong queue
$queueTasks = getQueueTasks($pdo);

?>
<div class="container-fluid">
    <div class="row">
        <div class="col-md-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Import Phim</h3>
                </div>
                <div class="card-body">
                    <?php if (isset($message)): ?>
                        <div class="alert alert-<?php echo $messageType; ?>"><?php echo $message; ?></div>
                    <?php endif; ?>

                    <form method="post" id="importForm">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Danh mục phim</label>
                                    <select name="category" class="form-control" required>
                                        <option value="">Chọn danh mục</option>
                                        <?php foreach ($movieCategories as $key => $value): ?>
                                            <option value="<?php echo $key; ?>"><?php echo $value['name']; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="form-group">
                                    <label>Trang bắt đầu</label>
                                    <input type="number" name="start_page" class="form-control" value="1" min="1" required>
                                </div>

                                <div class="form-group">
                                    <label>Trang kết thúc</label>
                                    <input type="number" name="end_page" class="form-control" value="1" min="1" required>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="form-group">
                                    <label>Số phim mỗi trang</label>
                                    <input type="number" name="limit_per_page" class="form-control" value="20" min="1" max="50" required>
                                </div>

                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="updateExisting" name="update_existing">
                                        <label class="custom-control-label" for="updateExisting">Cập nhật phim đã tồn tại</label>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <div class="custom-control custom-switch">
                                        <input type="checkbox" class="custom-control-input" id="useQueue" name="use_queue">
                                        <label class="custom-control-label" for="useQueue">Xử lý trong background (Queue)</label>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-download"></i> Import
                        </button>
                    </form>

                    <!-- Progress Section -->
                    <div id="progressSection" class="mt-4" style="display: none;">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Tiến độ Import</h5>
                            </div>
                            <div class="card-body">
                                <div class="progress mb-3">
                                    <div id="importProgress" class="progress-bar progress-bar-striped progress-bar-animated" 
                                         role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">0%</div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <p id="currentPage" class="mb-2">Trang: 0/0</p>
                                        <p id="currentMovie" class="mb-2">Đang xử lý: -</p>
                                        <p id="elapsedTime" class="mb-2">Thời gian đã trôi qua: 0:00</p>
                                    </div>
                                    <div class="col-md-6">
                                        <p id="importedCount" class="mb-2 text-success">Đã import mới: 0 phim</p>
                                        <p id="updatedCount" class="mb-2 text-info">Đã cập nhật: 0 phim</p>
                                        <p id="skippedCount" class="mb-2 text-warning">Đã bỏ qua: 0 phim</p>
                                    </div>
                                </div>
                                
                                <div class="alert alert-info" id="statusMessage">
                                    Đang chuẩn bị...
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Queue Tasks Section -->
                    <div class="mt-4">
                        <div class="card">
                            <div class="card-header">
                                <h5 class="card-title mb-0">Danh sách tác vụ trong Queue</h5>
                            </div>
                            <div class="card-body">
                                <div class="table-responsive">
                                    <table class="table table-bordered table-hover" id="queueTable">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Danh mục</th>
                                                <th>Trạng thái</th>
                                                <th>Tiến độ</th>
                                                <th>Đã import</th>
                                                <th>Đã cập nhật</th>
                                                <th>Đã bỏ qua</th>
                                                <th>Thời gian</th>
                                            </tr>
                                        </thead>
                                        <tbody id="queueTableBody">
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once 'footer.php'; ?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const importForm = document.getElementById('importForm');
    const progressSection = document.getElementById('progressSection');
    const importProgress = document.getElementById('importProgress');
    const currentPage = document.getElementById('currentPage');
    const currentMovie = document.getElementById('currentMovie');
    const importedCount = document.getElementById('importedCount');
    const updatedCount = document.getElementById('updatedCount');
    const skippedCount = document.getElementById('skippedCount');
    const elapsedTime = document.getElementById('elapsedTime');
    const statusMessage = document.getElementById('statusMessage');
    const queueTableBody = document.getElementById('queueTableBody');

    let startTime;
    let timerInterval;
    let updateInterval;

    // Handle form submission
    importForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        const formData = new FormData(this);
        const useQueue = formData.get('use_queue') === 'on';
        
        if (useQueue) {
            // Submit form normally for queue processing
            this.submit();
        } else {
            // Show progress section for direct processing
            progressSection.style.display = 'block';
            startTime = new Date();
            resetCounters();
            startTimer();
            
            // Submit form with fetch for direct processing
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => response.text())
            .then(html => {
                stopTimer();
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                // Update page with new content
                document.body.innerHTML = doc.body.innerHTML;
                
                // Show completion message
                statusMessage.textContent = 'Import hoàn tất!';
                statusMessage.className = 'alert alert-success';
            })
            .catch(error => {
                stopTimer();
                console.error('Error:', error);
                statusMessage.textContent = 'Có lỗi xảy ra: ' + error.message;
                statusMessage.className = 'alert alert-danger';
            });
        }
    });

    // Queue status polling
    function updateQueueStatus() {
        fetch('check_queue_status.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateQueueTable(data.tasks);
            }
        })
        .catch(error => console.error('Error updating queue status:', error));
    }

    function updateQueueTable(tasks) {
        queueTableBody.innerHTML = tasks.map(task => `
            <tr>
                <td>${task.id}</td>
                <td>${task.category}</td>
                <td>
                    <span class="badge badge-${getStatusBadgeClass(task.status)}">
                        ${getStatusText(task.status)}
                    </span>
                </td>
                <td>
                    <div class="progress">
                        <div class="progress-bar bg-${getStatusBadgeClass(task.status)}" 
                             role="progressbar" 
                             style="width: ${task.progress}%"
                             aria-valuenow="${task.progress}" 
                             aria-valuemin="0" 
                             aria-valuemax="100">
                            ${task.progress}%
                        </div>
                    </div>
                </td>
                <td>${task.stats.imported}</td>
                <td>${task.stats.updated}</td>
                <td>${task.stats.skipped}</td>
                <td>${formatDateTime(task.created_at)}</td>
            </tr>
        `).join('');
    }

    function getStatusBadgeClass(status) {
        const classes = {
            'pending': 'warning',
            'processing': 'info',
            'completed': 'success',
            'failed': 'danger'
        };
        return classes[status] || 'secondary';
    }

    function getStatusText(status) {
        const texts = {
            'pending': 'Chờ xử lý',
            'processing': 'Đang xử lý',
            'completed': 'Hoàn thành',
            'failed': 'Lỗi'
        };
        return texts[status] || status;
    }

    function formatDateTime(dateStr) {
        const date = new Date(dateStr);
        return date.toLocaleString('vi-VN');
    }

    function resetCounters() {
        importProgress.style.width = '0%';
        importProgress.textContent = '0%';
        currentPage.textContent = 'Trang: 0/0';
        currentMovie.textContent = 'Đang xử lý: -';
        importedCount.textContent = 'Đã import mới: 0 phim';
        updatedCount.textContent = 'Đã cập nhật: 0 phim';
        skippedCount.textContent = 'Đã bỏ qua: 0 phim';
        elapsedTime.textContent = 'Thời gian đã trôi qua: 0:00';
    }

    function startTimer() {
        timerInterval = setInterval(updateTimer, 1000);
        updateInterval = setInterval(updateQueueStatus, 5000);
    }

    function stopTimer() {
        clearInterval(timerInterval);
        clearInterval(updateInterval);
    }

    function updateTimer() {
        const now = new Date();
        const elapsed = Math.floor((now - startTime) / 1000);
        elapsedTime.textContent = `Thời gian đã trôi qua: ${formatTime(elapsed)}`;
    }

    function formatTime(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        
        if (hours > 0) {
            return `${hours}:${padZero(minutes)}:${padZero(secs)}`;
        }
        return `${minutes}:${padZero(secs)}`;
    }

    function padZero(num) {
        return num.toString().padStart(2, '0');
    }

    // Handle queue checkbox
    document.getElementById('useQueue').addEventListener('change', function() {
        const updateExisting = document.getElementById('updateExisting');
        if (this.checked) {
            updateExisting.checked = true;
            updateExisting.disabled = true;
        } else {
            updateExisting.disabled = false;
        }
    });

    // Start queue status polling
    updateQueueStatus();
    setInterval(updateQueueStatus, 5000);
});
</script>