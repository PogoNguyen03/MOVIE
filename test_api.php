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

// Function to display category information
function displayCategory($category, $prefix = '') {
    if (!is_array($category)) {
        echo $prefix . "Thể loại: {$category}\n";
        return;
    }
    
    foreach ($category as $group) {
        if (isset($group['group'])) {
            echo $prefix . "{$group['group']['name']}:\n";
            if (isset($group['list']) && is_array($group['list'])) {
                foreach ($group['list'] as $item) {
                    echo $prefix . "  - {$item['name']}\n";
                }
            }
        }
    }
}

// Function to display episodes
function displayEpisodes($episodes, $prefix = '') {
    if (!is_array($episodes)) {
        return;
    }
    
    foreach ($episodes as $server) {
        if (isset($server['server_name'])) {
            echo $prefix . "Server: {$server['server_name']}\n";
            if (isset($server['items']) && is_array($server['items'])) {
                foreach ($server['items'] as $episode) {
                    echo $prefix . "  Tập {$episode['name']}:\n";
                    echo $prefix . "    - Link: {$episode['embed']}\n";
                    if (isset($episode['m3u8'])) {
                        echo $prefix . "    - M3U8: {$episode['m3u8']}\n";
                    }
                }
            }
        }
    }
}

// Function to display movie details
function displayMovieDetails($movie, $prefix = '') {
    echo $prefix . "Tên phim: {$movie['name']}\n";
    echo $prefix . "Slug: {$movie['slug']}\n";
    echo $prefix . "Tên gốc: {$movie['original_name']}\n";
    echo $prefix . "Ảnh bìa: {$movie['thumb_url']}\n";
    echo $prefix . "Ảnh poster: {$movie['poster_url']}\n";
    echo $prefix . "Ngày tạo: {$movie['created']}\n";
    echo $prefix . "Ngày sửa: {$movie['modified']}\n";
    echo $prefix . "Mô tả: {$movie['description']}\n";
    echo $prefix . "Tổng số tập: {$movie['total_episodes']}\n";
    echo $prefix . "Tập hiện tại: {$movie['current_episode']}\n";
    echo $prefix . "Thời lượng: {$movie['time']}\n";
    echo $prefix . "Chất lượng: {$movie['quality']}\n";
    echo $prefix . "Ngôn ngữ: {$movie['language']}\n";
    echo $prefix . "Đạo diễn: " . ($movie['director'] ?: 'Chưa cập nhật') . "\n";
    echo $prefix . "Diễn viên: {$movie['casts']}\n";
    
    // Hiển thị thông tin thể loại
    if (isset($movie['category'])) {
        echo $prefix . "Thông tin phân loại:\n";
        displayCategory($movie['category'], $prefix . '  ');
    }
    
    // Hiển thị thông tin tập
    if (isset($movie['episodes'])) {
        echo $prefix . "Danh sách tập:\n";
        displayEpisodes($movie['episodes'], $prefix . '  ');
    }
}

// Test API endpoints
echo "Kiểm tra API phim.nguonc.com...\n\n";

// Test 1: Lấy danh sách phim mới
echo "Test 1: Lấy danh sách phim mới\n";
$listUrl = API_BASE_URL . "/films/phim-moi-cap-nhat?page=1";
$listResponse = makeRequest($listUrl);

if ($listResponse) {
    $listData = json_decode($listResponse, true);
    if (json_last_error() === JSON_ERROR_NONE && isset($listData['items']) && !empty($listData['items'])) {
        echo "✓ Lấy danh sách phim thành công. Tìm thấy " . count($listData['items']) . " phim.\n\n";
        
        // Hiển thị thông tin tổng quan về danh sách phim
        echo "Thông tin tổng quan về danh sách phim:\n";
        echo "- Tổng số phim: " . count($listData['items']) . "\n";
        if (isset($listData['meta'])) {
            echo "- Trang hiện tại: {$listData['meta']['current_page']}\n";
            echo "- Tổng số trang: {$listData['meta']['last_page']}\n";
            echo "- Tổng số phim: {$listData['meta']['total']}\n";
        }
        echo "\n";
        
        // Hiển thị thông tin chi tiết của 3 phim đầu tiên
        echo "Thông tin chi tiết của 3 phim đầu tiên:\n";
        for ($i = 0; $i < min(3, count($listData['items'])); $i++) {
            $movie = $listData['items'][$i];
            echo "\nPhim #" . ($i + 1) . ":\n";
            displayMovieDetails($movie, '  ');
            
            // Test 2: Lấy thông tin chi tiết phim từ API thứ hai
            echo "\n  Lấy thông tin chi tiết từ API thứ hai:\n";
            $slug = $movie['slug'];
            $detailUrl = API_BASE_URL . "/film/" . $slug;
            $detailResponse = makeRequest($detailUrl);
            
            if ($detailResponse) {
                $detailData = json_decode($detailResponse, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($detailData['movie'])) {
                    echo "  ✓ Lấy thông tin chi tiết phim thành công.\n";
                    echo "  Thông tin chi tiết từ API thứ hai:\n";
                    displayMovieDetails($detailData['movie'], '    ');
                    
                    // So sánh dữ liệu từ hai API
                    echo "  So sánh dữ liệu từ hai API:\n";
                    $listMovie = $movie;
                    $detailMovie = $detailData['movie'];
                    
                    // Kiểm tra các trường khác nhau
                    $differentFields = [];
                    foreach ($detailMovie as $key => $value) {
                        if (!isset($listMovie[$key]) || $listMovie[$key] !== $value) {
                            $differentFields[$key] = $value;
                        }
                    }
                    
                    if (!empty($differentFields)) {
                        echo "  Các trường chỉ có trong API chi tiết:\n";
                        foreach ($differentFields as $key => $value) {
                            if (is_string($value)) {
                                echo "    - $key: $value\n";
                            } elseif (is_array($value)) {
                                echo "    - $key: " . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
                            } else {
                                echo "    - $key: " . json_encode($value) . "\n";
                            }
                        }
                    } else {
                        echo "  Không có sự khác biệt giữa hai API.\n";
                    }
                } else {
                    echo "  ✗ Lỗi khi parse JSON thông tin chi tiết phim: " . json_last_error_msg() . "\n";
                }
            } else {
                echo "  ✗ Không thể lấy thông tin chi tiết phim.\n";
            }
            echo "\n";
        }
    } else {
        echo "✗ Lỗi khi parse JSON danh sách phim hoặc không tìm thấy phim nào.\n";
    }
} else {
    echo "✗ Không thể kết nối đến API danh sách phim.\n";
}
?> 