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
        echo "Lỗi cURL: " . $error . "\n";
        return false;
    }
    
    if ($httpCode !== 200) {
        echo "Lỗi HTTP: " . $httpCode . "\n";
        return false;
    }
    
    return $response;
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
    
    if ($response) {
        $data = json_decode($response, true);
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
        $stmt->bindValue(':vod_lang', !empty($movie['language']) ? $movie['language'] : 'Phụ đề Việt');
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
        echo "Lỗi khi import phim {$movie['name']}: " . $e->getMessage() . "\n";
        return false;
    }
}

// Main script
try {
    // Get command line arguments
    $startPage = isset($argv[1]) ? (int)$argv[1] : 1;
    $endPage = isset($argv[2]) ? (int)$argv[2] : 1;
    $limit = isset($argv[3]) ? (int)$argv[3] : 10;
    $force = isset($argv[4]) && $argv[4] === '1';
    
    // Validate page range
    if ($startPage > $endPage) {
        echo "Lỗi: Trang bắt đầu phải nhỏ hơn hoặc bằng trang kết thúc.\n";
        exit(1);
    }
    
    // Connect to database
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
    
    // Process each page
    for ($page = $startPage; $page <= $endPage; $page++) {
        echo "\n===== ĐANG XỬ LÝ TRANG {$page} =====\n\n";
        
        // Fetch movies from API
        $url = API_BASE_URL . "/films/phim-moi-cap-nhat?page=" . $page;
        $response = makeRequest($url);
        
        if ($response) {
            $data = json_decode($response, true);
            if (json_last_error() === JSON_ERROR_NONE && isset($data['items']) && !empty($data['items'])) {
                $totalMovies = count($data['items']);
                $importedCount = 0;
                $skippedCount = 0;
                
                echo "Tìm thấy {$totalMovies} phim trên trang {$page}. Bắt đầu import...\n\n";
                
                foreach ($data['items'] as $index => $movie) {
                    if ($index >= $limit) break;
                    
                    echo "Đang xử lý phim {$movie['name']}...\n";
                    
                    // Check if movie already exists
                    $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_sub = ?");
                    $stmt->execute([$movie['slug']]);
                    $exists = $stmt->fetch();
                    
                    if ($exists && !$force) {
                        echo "Phim đã tồn tại, bỏ qua.\n\n";
                        $skippedCount++;
                        continue;
                    }
                    
                    // Fetch detailed movie information
                    $movieDetails = fetchMovieDetails($movie['slug']);
                    if ($movieDetails) {
                        if (importMovieToDB($movieDetails, $pdo)) {
                            echo "Import phim thành công!\n\n";
                            $importedCount++;
                        } else {
                            echo "Lỗi khi import phim.\n\n";
                        }
                    } else {
                        echo "Không thể lấy thông tin chi tiết phim.\n\n";
                    }
                }
                
                echo "Kết quả import trang {$page}:\n";
                echo "- Tổng số phim: {$totalMovies}\n";
                echo "- Đã import: {$importedCount}\n";
                echo "- Đã bỏ qua: {$skippedCount}\n";
                
                $totalImportedCount += $importedCount;
                $totalSkippedCount += $skippedCount;
                $totalMoviesCount += $totalMovies;
                
            } else {
                echo "Lỗi khi parse JSON hoặc không tìm thấy phim nào trên trang {$page}.\n";
            }
        } else {
            echo "Không thể kết nối đến API cho trang {$page}.\n";
        }
    }
    
    echo "\n===== KẾT QUẢ TỔNG HỢP =====\n";
    echo "- Tổng số phim đã xử lý: {$totalMoviesCount}\n";
    echo "- Tổng số phim đã import: {$totalImportedCount}\n";
    echo "- Tổng số phim đã bỏ qua: {$totalSkippedCount}\n";
    
} catch (Exception $e) {
    echo "Lỗi: " . $e->getMessage() . "\n";
}
?> 