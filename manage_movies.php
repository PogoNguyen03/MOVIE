<?php
/**
 * Movie Management Script
 * 
 * This script provides options to manage the mac_vod table:
 * 1. Delete all records and reset ID counter
 * 2. Reset ID counter only
 * 3. Check current status
 * 
 * Usage: php manage_movies.php [option]
 * Options:
 *   delete - Delete all records and reset ID counter
 *   reset  - Reset ID counter only
 *   check  - Check current status (default)
 */

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

// Function to make HTTP request with cURL - copied from admin_get_movie.php
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

// Function to normalize slug - copied from admin_get_movie.php
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

// Function to fetch movie details from API - copied from admin_get_movie.php
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

// Function to extract category information - copied from admin_get_movie.php
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

// Function to extract episode information - copied from admin_get_movie.php
function extractEpisodeInfo($episodes) {
    $episodeInfo = [];
    
    // Log incoming episodes data
    error_log("Extracting episode info from: " . json_encode($episodes, JSON_UNESCAPED_UNICODE));
    
    if (!is_array($episodes)) {
        error_log("Episodes data is not an array");
        return $episodeInfo;
    }
    
    foreach ($episodes as $server) {
        // Log current server being processed
        error_log("Processing server: " . json_encode($server, JSON_UNESCAPED_UNICODE));
        
        if (!isset($server['server_name']) || !isset($server['items']) || !is_array($server['items'])) {
            error_log("Invalid server data - missing server_name or items");
            continue;
        }
        
        foreach ($server['items'] as $episode) {
            // Log current episode being processed
            error_log("Processing episode: " . json_encode($episode, JSON_UNESCAPED_UNICODE));
            
            if (!isset($episode['name']) || !isset($episode['slug']) || !isset($episode['embed'])) {
                error_log("Invalid episode data - missing required fields");
                continue;
            }
            
            // Process episode name
            $episodeName = $episode['name'];
            // Remove "Thứ" and "tập" from episode name
            $episodeName = str_replace(['Thứ', 'tập'], '', $episodeName);
            // Remove extra whitespace
            $episodeName = trim($episodeName);
            // Extract only the episode number
            preg_match('/\d+/', $episodeName, $matches);
            $episodeName = isset($matches[0]) ? $matches[0] : $episodeName;
            
            $episodeData = [
                'name' => $episodeName,
                'slug' => $episode['slug'],
                'embed' => $episode['embed'],
                'server_name' => $server['server_name']
            ];
            
            // Log processed episode data
            error_log("Processed episode data: " . json_encode($episodeData, JSON_UNESCAPED_UNICODE));
            
            $episodeInfo[] = $episodeData;
        }
    }
    
    // Log final episode info array
    error_log("Final episode info array: " . json_encode($episodeInfo, JSON_UNESCAPED_UNICODE));
    
    return $episodeInfo;
}

// Function to convert time format to minutes - copied from admin_get_movie.php
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

// Function to determine type_id based on category information - copied from admin_get_movie.php
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

// Function to import movie to database (handles both INSERT and UPDATE) - copied from admin_get_movie.php
function importMovieToDB($movie, $pdo, $existingVodId = null) {
    try {
        // Log incoming movie data for debugging
        error_log("Importing movie data: " . json_encode($movie, JSON_UNESCAPED_UNICODE));
        
        // Extract category information
        $categoryInfo = extractCategoryInfo($movie['category']);
        
        // Extract episode information and add logging
        error_log("Processing episodes data: " . json_encode($movie['episodes'], JSON_UNESCAPED_UNICODE));
        $episodeInfo = extractEpisodeInfo($movie['episodes']);
        error_log("Extracted episode info: " . json_encode($episodeInfo, JSON_UNESCAPED_UNICODE));
        
        // Convert time to minutes
        $duration = convertTimeToMinutes($movie['time']);
        
        // Determine type_id based on movie information
        $typeId = determineTypeId($movie['category']);
        
        // Prepare episode data with improved logging
        $episodeData = [];
        $playFrom = 'ngm3u8'; // Always use ngm3u8
        
        if (!empty($episodeInfo)) {
            error_log("Processing non-empty episode info");
            
            foreach ($episodeInfo as $episode) {
                if ($typeId == 2) { // If it's a movie (not a series)
                    $episodeData[] = $episode['server_name'] . '$' . $episode['embed'];
                    error_log("Added movie episode: " . $episode['server_name'] . '$' . $episode['embed']);
                } else { // If it's a series
                    $episodeData[] = $episode['name'] . '$' . $episode['embed'];
                    error_log("Added series episode: " . $episode['name'] . '$' . $episode['embed']);
                }
            }
        } else {
            error_log("No episode info found in the movie data");
        }
        
        $playUrl = implode('#', $episodeData);
        error_log("Final play URL: " . $playUrl);

        // Prepare SQL statement (Common fields for INSERT and UPDATE)
        $fields = [
            'vod_name' => $movie['name'] ?? '',
            'vod_sub' => $movie['slug'] ?? '',
            'vod_en' => $movie['original_name'] ?? '',
            'vod_tag' => !empty($categoryInfo['genres']) ? implode(',', $categoryInfo['genres']) : '',
            'vod_class' => '', // Need to determine class logic if needed
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
            'vod_state' => $movie['episode_current'] ?? '',
            'vod_serial' => $typeId == 2 ? 0 : 1,
            'vod_tv' => 0,
            'vod_weekday' => '',
            'vod_total' => $movie['episode_total'] ?? count($episodeData),
            'vod_trysee' => 0,
            'vod_duration' => $duration,
            'vod_remarks' => $movie['quality'] ?? ($movie['lang'] ?? ''),
            'vod_tpl' => '',
            'vod_tpl_play' => '',
            'vod_tpl_down' => '',
            'vod_isend' => ($movie['status'] ?? '') === 'completed' ? 1 : 0,
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

        // Handle language separately to avoid length issues
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
            // Add fields only for INSERT
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

$pageTitle = 'Quản lý Phim';
require_once 'layout.php';

// Xử lý xóa tất cả phim
if (isset($_GET['action']) && $_GET['action'] === 'delete_all') {
    try {
        $stmt = $pdo->prepare("DELETE FROM mac_vod");
        $stmt->execute();
        $success = "Đã xóa tất cả phim thành công!";
    } catch (PDOException $e) {
        $error = "Lỗi khi xóa tất cả phim: " . $e->getMessage();
    }
}

// Xử lý xóa nhiều phim được chọn
if (isset($_POST['delete_movies'])) {
    try {
        $movieIds = json_decode($_POST['delete_movies'], true);
        if (!empty($movieIds)) {
            $placeholders = str_repeat('?,', count($movieIds) - 1) . '?';
            $stmt = $pdo->prepare("DELETE FROM mac_vod WHERE vod_id IN ($placeholders)");
            $stmt->execute($movieIds);
            $success = "Đã xóa " . count($movieIds) . " phim thành công!";
        }
    } catch (PDOException $e) {
        $error = "Lỗi khi xóa phim: " . $e->getMessage();
    }
}

// Lấy thông tin phim chi tiết nếu có yêu cầu
$movieDetail = null;
if (isset($_GET['id'])) {
    $movieId = (int)$_GET['id'];
    try {
        $stmt = $pdo->prepare("SELECT * FROM mac_vod WHERE vod_id = ?");
        $stmt->execute([$movieId]);
        $movieDetail = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error = "Lỗi khi lấy thông tin phim: " . $e->getMessage();
    }
}

// Phân trang
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit = 20;
$offset = ($page - 1) * $limit;

// Tìm kiếm
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$whereClause = '';
$params = [];

if (!empty($search)) {
    $whereClause = "WHERE vod_name LIKE ? OR vod_sub LIKE ? OR vod_en LIKE ?";
    $searchParam = "%{$search}%";
    $params = [$searchParam, $searchParam, $searchParam];
}

// Đếm tổng số phim
try {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM mac_vod $whereClause");
    $countStmt->execute($params);
    $totalMovies = $countStmt->fetchColumn();
    $totalPages = ceil($totalMovies / $limit);
} catch (PDOException $e) {
    $error = "Lỗi khi đếm số phim: " . $e->getMessage();
}

// Lấy danh sách phim
try {
    $stmt = $pdo->prepare("SELECT vod_id, vod_name, vod_sub, vod_en, vod_tag, vod_class, vod_year, vod_area, vod_lang, vod_time FROM mac_vod $whereClause ORDER BY vod_time DESC LIMIT $limit OFFSET $offset");
    $stmt->execute($params);
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Lỗi khi lấy danh sách phim: " . $e->getMessage();
}

// Thêm function random dữ liệu phim
function randomMovieStats($pdo, $movieIds = null) {
    try {
        $whereClause = "";
        if ($movieIds !== null) {
            $placeholders = str_repeat('?,', count($movieIds) - 1) . '?';
            $whereClause = "WHERE vod_id IN ($placeholders)";
        }

        $sql = "UPDATE mac_vod SET 
                vod_hits = FLOOR(10 + RAND() * 990),
                vod_hits_day = FLOOR(10 + RAND() * 90),
                vod_hits_week = FLOOR(50 + RAND() * 450),
                vod_hits_month = FLOOR(100 + RAND() * 900),
                vod_up = FLOOR(10 + RAND() * 90),
                vod_down = FLOOR(5 + RAND() * 45),
                vod_score = ROUND(5 + (RAND() * 5), 1),
                vod_score_all = FLOOR(10 + RAND() * 90),
                vod_score_num = FLOOR(10 + RAND() * 90)
                $whereClause";

        $stmt = $pdo->prepare($sql);
        if ($movieIds !== null) {
            $stmt->execute($movieIds);
        } else {
            $stmt->execute();
        }
        
        return $stmt->rowCount();
    } catch (PDOException $e) {
        throw new Exception("Lỗi khi random dữ liệu: " . $e->getMessage());
    }
}

// Xử lý random dữ liệu cho tất cả phim
if (isset($_GET['action']) && $_GET['action'] === 'random_all') {
    try {
        $updatedCount = randomMovieStats($pdo);
        $success = "Đã random dữ liệu cho $updatedCount phim thành công!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Xử lý random dữ liệu cho phim được chọn
if (isset($_POST['random_movies'])) {
    try {
        $movieIds = json_decode($_POST['random_movies'], true);
        if (!empty($movieIds)) {
            $updatedCount = randomMovieStats($pdo, $movieIds);
            $success = "Đã random dữ liệu cho $updatedCount phim thành công!";
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Xử lý cập nhật tất cả phim
if (isset($_POST['update_all'])) {
    try {
        $stmt = $pdo->query("SELECT vod_id, vod_sub FROM mac_vod");
        $allMovies = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $updatedCount = 0;
        $failedSlugs = [];

        foreach ($allMovies as $movie) {
            $movieDetails = fetchMovieDetails($movie['vod_sub']);
            if ($movieDetails) {
                $updateResult = importMovieToDB($movieDetails, $pdo, $movie['vod_id']);
                if ($updateResult['success'] && $updateResult['action'] === 'updated') {
                    $updatedCount++;
                }
            } else {
                $failedSlugs[] = $movie['vod_sub'];
            }
            // Thêm độ trễ nhỏ để tránh quá tải API (tùy chọn)
            usleep(100000); // 0.1 giây
        }

        $success = "Đã cập nhật thành công $updatedCount phim.";
        if (!empty($failedSlugs)) {
            $error = "Không thể lấy thông tin chi tiết hoặc cập nhật cho các slug: " . implode(', ', $failedSlugs);
        }
    } catch (PDOException $e) {
        $error = "Lỗi database khi cập nhật tất cả phim: " . $e->getMessage();
    } catch (Exception $e) {
        $error = "Lỗi khi cập nhật tất cả phim: " . $e->getMessage();
    }
}

// Xử lý cập nhật phim được chọn
if (isset($_POST['update_selected'])) {
    try {
        $movieIds = json_decode($_POST['update_selected'], true);
        $updatedCount = 0;
        $failedSlugs = [];

        if (!empty($movieIds)) {
            $placeholders = str_repeat('?,', count($movieIds) - 1) . '?';
            $stmt = $pdo->prepare("SELECT vod_id, vod_sub FROM mac_vod WHERE vod_id IN ($placeholders)");
            $stmt->execute($movieIds);
            $selectedMovies = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($selectedMovies as $movie) {
                $movieDetails = fetchMovieDetails($movie['vod_sub']);
                if ($movieDetails) {
                    $updateResult = importMovieToDB($movieDetails, $pdo, $movie['vod_id']);
                    if ($updateResult['success'] && $updateResult['action'] === 'updated') {
                        $updatedCount++;
                    }
                } else {
                    $failedSlugs[] = $movie['vod_sub'];
                }
                 // Thêm độ trễ nhỏ
                 usleep(100000); 
            }
            $success = "Đã cập nhật thành công $updatedCount phim được chọn.";
             if (!empty($failedSlugs)) {
                $error = "Không thể lấy thông tin chi tiết hoặc cập nhật cho các slug: " . implode(', ', $failedSlugs);
            }
        } else {
            $error = "Không có phim nào được chọn để cập nhật.";
        }
    } catch (PDOException $e) {
        $error = "Lỗi database khi cập nhật phim đã chọn: " . $e->getMessage();
    } catch (Exception $e) {
        $error = "Lỗi khi cập nhật phim đã chọn: " . $e->getMessage();
    }
}
?>
<div class="mb-6 flex justify-between items-center">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">Quản lý Phim</h1>
        <p class="text-gray-600">Danh sách phim đã import</p>
    </div>
    <div class="flex space-x-2">
        <a href="admin_get_movie.php" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
            <i class="fas fa-download mr-2"></i>Import Phim Mới
        </a>
        <button onclick="confirmRandomAll()" class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2">
            <i class="fas fa-random mr-2"></i>Random tất cả
        </button>
        <button onclick="confirmUpdateAll()" class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2">
            <i class="fas fa-sync-alt mr-2"></i>Cập nhật tất cả
        </button>
        <button onclick="confirmDeleteAll()" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
            <i class="fas fa-trash-alt mr-2"></i>Xóa tất cả
        </button>
        <button onclick="showBulkActions()" class="bg-yellow-600 hover:bg-yellow-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-yellow-500 focus:ring-offset-2">
            <i class="fas fa-check-square mr-2"></i>Chọn nhiều
        </button>
    </div>
</div>

<?php if (isset($success)): ?>
<div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
    <p><?php echo $success; ?></p>
</div>
<?php endif; ?>

<?php if (isset($error)): ?>
<div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
    <p><?php echo $error; ?></p>
</div>
<?php endif; ?>

<!-- Movie List -->
<div class="bg-white rounded-lg shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <input type="checkbox" id="selectAll" class="rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50">
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tên phim</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thể loại</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ngôn ngữ</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Năm</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thao tác</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button onclick="randomSelected()" class="bg-purple-600 hover:bg-purple-700 text-white text-xs font-medium py-1 px-3 rounded focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 mr-2">
                            <i class="fas fa-random mr-1"></i>Random đã chọn
                        </button>
                        <button onclick="updateSelected()" class="bg-green-600 hover:bg-green-700 text-white text-xs font-medium py-1 px-3 rounded focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 mr-2">
                            <i class="fas fa-sync-alt mr-1"></i>Cập nhật đã chọn
                        </button>
                        <button onclick="deleteSelected()" class="bg-red-600 hover:bg-red-700 text-white text-xs font-medium py-1 px-3 rounded focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                            <i class="fas fa-trash-alt mr-1"></i>Xóa đã chọn
                        </button>
                    </th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($movies)): ?>
                <tr>
                    <td colspan="8" class="px-6 py-4 text-center text-gray-500">
                        Không tìm thấy phim nào.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($movies as $movie): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                        <input type="checkbox" class="movie-checkbox rounded border-gray-300 text-blue-600 shadow-sm focus:border-blue-300 focus:ring focus:ring-blue-200 focus:ring-opacity-50" value="<?php echo $movie['vod_id']; ?>">
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo $movie['vod_id']; ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($movie['vod_name']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($movie['vod_tag']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($movie['vod_lang']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900"><?php echo htmlspecialchars($movie['vod_year']); ?></td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <a href="?id=<?php echo $movie['vod_id']; ?>" class="text-blue-600 hover:text-blue-900 mr-3">
                            <i class="fas fa-eye mr-1"></i> Chi tiết
                        </a>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                        <button onclick="deleteMovie(<?php echo $movie['vod_id']; ?>)" class="text-red-600 hover:text-red-900">
                            <i class="fas fa-trash-alt mr-1"></i> Xóa
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
        <div class="flex-1 flex justify-between sm:hidden">
            <?php if ($page > 1): ?>
            <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Trước
            </a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50">
                Sau
            </a>
            <?php endif; ?>
        </div>
        <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
                <p class="text-sm text-gray-700">
                    Hiển thị <span class="font-medium"><?php echo $offset + 1; ?></span> đến <span class="font-medium"><?php echo min($offset + $limit, $totalMovies); ?></span> của <span class="font-medium"><?php echo $totalMovies; ?></span> phim
                </p>
            </div>
            <div>
                <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px" aria-label="Pagination">
                    <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-l-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Trước</span>
                        <i class="fas fa-chevron-left"></i>
                    </a>
                    <?php endif; ?>
                    
                    <?php
                    $startPage = max(1, $page - 2);
                    $endPage = min($totalPages, $page + 2);
                    
                    if ($startPage > 1) {
                        echo '<a href="?page=1' . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">1</a>';
                        if ($startPage > 2) {
                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                        }
                    }
                    
                    for ($i = $startPage; $i <= $endPage; $i++) {
                        if ($i == $page) {
                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-blue-500 bg-blue-50 text-sm font-medium text-blue-600">' . $i . '</span>';
                        } else {
                            echo '<a href="?page=' . $i . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $i . '</a>';
                        }
                    }
                    
                    if ($endPage < $totalPages) {
                        if ($endPage < $totalPages - 1) {
                            echo '<span class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700">...</span>';
                        }
                        echo '<a href="?page=' . $totalPages . (!empty($search) ? '&search=' . urlencode($search) : '') . '" class="relative inline-flex items-center px-4 py-2 border border-gray-300 bg-white text-sm font-medium text-gray-700 hover:bg-gray-50">' . $totalPages . '</a>';
                    }
                    ?>
                    
                    <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo !empty($search) ? '&search=' . urlencode($search) : ''; ?>" class="relative inline-flex items-center px-2 py-2 rounded-r-md border border-gray-300 bg-white text-sm font-medium text-gray-500 hover:bg-gray-50">
                        <span class="sr-only">Sau</span>
                        <i class="fas fa-chevron-right"></i>
                    </a>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<!-- Movie Detail Modal -->
<?php if ($movieDetail): ?>
<div class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50" id="movieDetailModal">
    <div class="relative top-20 mx-auto p-5 border w-4/5 shadow-lg rounded-md bg-white">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-semibold text-gray-800">Chi tiết phim: <?php echo htmlspecialchars($movieDetail['vod_name']); ?></h3>
            <a href="manage_movies.php" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-times text-xl"></i>
            </a>
        </div>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <h4 class="text-md font-medium text-gray-700 mb-2">Thông tin cơ bản</h4>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">ID:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo $movieDetail['vod_id']; ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Tên phim:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_name']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Tên tiếng Anh:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_en']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Slug:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_sub']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Thể loại:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_tag']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Phân loại:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_class']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Ngôn ngữ:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_lang']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Năm phát hành:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_year']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Quốc gia:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_area']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Thời lượng:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_duration']); ?> phút</div>
                    </div>
                </div>
            </div>
            
            <div>
                <h4 class="text-md font-medium text-gray-700 mb-2">Thông tin chi tiết</h4>
                <div class="bg-gray-50 p-4 rounded-lg">
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Diễn viên:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_actor']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Đạo diễn:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_director']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Biên kịch:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_writer']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Hậu kỳ:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_behind']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Tổng số tập:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_total']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Tập hiện tại:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo htmlspecialchars($movieDetail['vod_remarks']); ?></div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Trạng thái:</div>
                        <div class="text-sm text-gray-900 col-span-2">
                            <?php if ($movieDetail['vod_isend'] == 1): ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">Hoàn thành</span>
                            <?php else: ?>
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-yellow-100 text-yellow-800">Đang cập nhật</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-3 gap-2 mb-2">
                        <div class="text-sm font-medium text-gray-500">Ngày cập nhật:</div>
                        <div class="text-sm text-gray-900 col-span-2"><?php echo date('d/m/Y H:i:s', $movieDetail['vod_time']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mt-4">
            <h4 class="text-md font-medium text-gray-700 mb-2">Nội dung phim</h4>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($movieDetail['vod_content'])); ?></p>
            </div>
        </div>
        
        <div class="mt-4">
            <h4 class="text-md font-medium text-gray-700 mb-2">Mô tả ngắn</h4>
            <div class="bg-gray-50 p-4 rounded-lg">
                <p class="text-sm text-gray-900"><?php echo nl2br(htmlspecialchars($movieDetail['vod_blurb'])); ?></p>
            </div>
        </div>
        
        <div class="mt-6 flex justify-end">
            <a href="manage_movies.php" class="bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-gray-500 focus:ring-offset-2 mr-2">
                Đóng
            </a>
            <form action="" method="POST" class="inline" onsubmit="return confirm('Bạn có chắc chắn muốn xóa phim này?');">
                <input type="hidden" name="movie_id" value="<?php echo $movieDetail['vod_id']; ?>">
                <button type="submit" name="delete_movie" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-6 rounded-lg focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2">
                    <i class="fas fa-trash-alt mr-2"></i>Xóa phim
                </button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>

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

        // Select all checkbox functionality
        const selectAllCheckbox = document.getElementById('selectAll');
        if (selectAllCheckbox) {
            selectAllCheckbox.addEventListener('change', function() {
                const checkboxes = document.querySelectorAll('.movie-checkbox');
                checkboxes.forEach(checkbox => {
                    checkbox.checked = this.checked;
                });
            });
        }
    });

    function showMovieDetail(movieId, event) {
        // Prevent the event from bubbling up
        event.preventDefault();
        event.stopPropagation();
        
        // Show modal and loading state
        const modal = document.getElementById('movieDetailModal');
        modal.classList.remove('hidden');
        
        // Fetch movie details
        fetch(`get_movie_detail.php?id=${movieId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error('Network response was not ok');
                }
                return response.json();
            })
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
                    closeMovieDetail();
                }
            })
            .catch(error => {
                console.error('Error:', error);
                alert('Đã xảy ra lỗi khi lấy thông tin phim');
                closeMovieDetail();
            });
    }

    function confirmDeleteAll() {
        if (confirm('Bạn có chắc chắn muốn xóa tất cả phim? Hành động này không thể hoàn tác.')) {
            window.location.href = 'manage_movies.php?action=delete_all';
        }
    }

    function showBulkActions() {
        // Toggle checkbox column visibility
        const checkboxes = document.querySelectorAll('.movie-checkbox');
        const bulkActionsBtn = document.querySelector('th:last-child');
        
        checkboxes.forEach(checkbox => {
            const cell = checkbox.closest('td');
            if (cell) {
                cell.style.display = cell.style.display === 'none' ? 'table-cell' : 'none';
            }
        });
        
        if (bulkActionsBtn) {
            bulkActionsBtn.style.display = bulkActionsBtn.style.display === 'none' ? 'table-cell' : 'none';
        }
    }

    function deleteMovie(movieId) {
        if (confirm('Bạn có chắc chắn muốn xóa phim này?')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_movies.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_movies';
            input.value = JSON.stringify([movieId]);
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function deleteSelected() {
        const selectedMovies = Array.from(document.querySelectorAll('.movie-checkbox:checked')).map(cb => cb.value);
        
        if (selectedMovies.length === 0) {
            alert('Vui lòng chọn ít nhất một phim để xóa.');
            return;
        }
        
        if (confirm(`Bạn có chắc chắn muốn xóa ${selectedMovies.length} phim đã chọn?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_movies.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'delete_movies';
            input.value = JSON.stringify(selectedMovies);
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function confirmRandomAll() {
        if (confirm('Bạn có chắc chắn muốn random dữ liệu cho tất cả phim?')) {
            window.location.href = 'manage_movies.php?action=random_all';
        }
    }

    function randomSelected() {
        const selectedMovies = Array.from(document.querySelectorAll('.movie-checkbox:checked')).map(cb => cb.value);
        
        if (selectedMovies.length === 0) {
            alert('Vui lòng chọn ít nhất một phim để random dữ liệu.');
            return;
        }
        
        if (confirm(`Bạn có chắc chắn muốn random dữ liệu cho ${selectedMovies.length} phim đã chọn?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_movies.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'random_movies';
            input.value = JSON.stringify(selectedMovies);
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function confirmUpdateAll() {
        if (confirm('Bạn có chắc chắn muốn cập nhật dữ liệu cho TẤT CẢ phim từ API? Quá trình này có thể mất nhiều thời gian.')) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_movies.php';
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'update_all';
            input.value = '1';
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }

    function updateSelected() {
        const selectedMovies = Array.from(document.querySelectorAll('.movie-checkbox:checked')).map(cb => cb.value);
        
        if (selectedMovies.length === 0) {
            alert('Vui lòng chọn ít nhất một phim để cập nhật.');
            return;
        }
        
        if (confirm(`Bạn có chắc chắn muốn cập nhật dữ liệu cho ${selectedMovies.length} phim đã chọn từ API?`)) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'manage_movies.php';
            
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'update_selected';
            input.value = JSON.stringify(selectedMovies);
            
            form.appendChild(input);
            document.body.appendChild(form);
            form.submit();
        }
    }
</script> 