<?php
require_once __DIR__ . '/processor.php';
require_once __DIR__ . '/utils.php';

/**
 * Import hoặc cập nhật phim vào cơ sở dữ liệu
 * 
 * @param array $movie Dữ liệu phim từ API
 * @param PDO $pdo Kết nối PDO
 * @param int|null $existingVodId ID của phim nếu đã tồn tại (để cập nhật)
 * @return array Kết quả xử lý
 */
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
        $playFrom = 'ngm3u8'; // Luôn sử dụng ngm3u8, không lấy từ server_name nữa
        
        if (!empty($episodeInfo)) {
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

/**
 * Kiểm tra xem phim đã tồn tại trong cơ sở dữ liệu chưa
 *
 * @param string $slug Slug của phim
 * @param PDO $pdo Kết nối PDO
 * @return array|false Thông tin phim nếu đã tồn tại, false nếu chưa
 */
function checkMovieExists($slug, $pdo) {
    $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_sub = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
} 