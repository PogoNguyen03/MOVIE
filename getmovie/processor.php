<?php
require_once __DIR__ . '/utils.php';

/**
 * Trích xuất thông tin chung về phim từ dữ liệu API
 * 
 * @param array $category Dữ liệu danh mục từ API
 * @return array Thông tin về thể loại, năm sản xuất, quốc gia
 */
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

/**
 * Trích xuất thông tin về các tập phim
 * 
 * @param array $episodes Dữ liệu các tập từ API
 * @return array Danh sách tập phim đã xử lý
 */
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
                    'slug' => $episode['slug'] ?? '',
                    'embed' => $episode['link_embed'] ?? ($episode['embed'] ?? ''),
                    'server_name' => $server['server_name']
                ];
            }
        }
    }
    
    return $episodeInfo;
}

/**
 * Xác định loại phim dựa trên thông tin danh mục
 * 
 * @param array $category Dữ liệu danh mục từ API
 * @return int Mã loại phim
 */
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