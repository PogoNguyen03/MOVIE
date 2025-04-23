/**
 * Thêm tác vụ vào queue
 */
function addToQueue($category, $startPage, $endPage, $limitPerPage, $updateExisting) {
    $pdo = getDBConnection();
    
    $sql = "INSERT INTO mac_import_queue (category, start_page, end_page, limit_per_page, update_existing, status, created_at) 
            VALUES (:category, :start_page, :end_page, :limit_per_page, :update_existing, 'pending', NOW())";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':category' => $category,
        ':start_page' => $startPage,
        ':end_page' => $endPage,
        ':limit_per_page' => $limitPerPage,
        ':update_existing' => $updateExisting ? 1 : 0
    ]);
    
    return $pdo->lastInsertId();
}

/**
 * Lấy danh sách tác vụ trong queue
 */
function getQueueTasks($limit = 10) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("SELECT * FROM mac_import_queue 
            ORDER BY created_at DESC 
            LIMIT ?");
        $stmt->execute([$limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting queue tasks: " . $e->getMessage());
        return [];
    }
}

/**
 * Lấy thông tin chi tiết của một tác vụ
 */
function getQueueTask($taskId) {
    $pdo = getDBConnection();
    
    $sql = "SELECT * FROM mac_import_queue WHERE id = :id";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $taskId]);
    
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * Xóa tác vụ khỏi queue
 */
function deleteQueueTask($taskId) {
    global $pdo;
    
    try {
        $stmt = $pdo->prepare("DELETE FROM mac_import_queue WHERE id = ?");
        $stmt->execute([$taskId]);
        return true;
    } catch (PDOException $e) {
        error_log("Error deleting queue task: " . $e->getMessage());
        return false;
    }
}

// Database connection
function getDBConnection() {
    $host = 'localhost';
    $dbname = 'mac_vod';
    $username = 'root';
    $password = '';
    
    try {
        $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection failed: " . $e->getMessage());
        throw $e;
    }
}

function updateQueueProgress($taskId, $progress, $imported = 0, $updated = 0, $skipped = 0) {
    $pdo = getDBConnection();
    
    $sql = "UPDATE mac_import_queue 
            SET progress = :progress,
                imported_movies = imported_movies + :imported,
                updated_movies = updated_movies + :updated,
                skipped_movies = skipped_movies + :skipped,
                updated_at = NOW()
            WHERE id = :id";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $taskId,
        ':progress' => $progress,
        ':imported' => $imported,
        ':updated' => $updated,
        ':skipped' => $skipped
    ]);
}

function updateQueueStatus($taskId, $status, $errorMessage = null) {
    $pdo = getDBConnection();
    
    $sql = "UPDATE mac_import_queue 
            SET status = :status,
                error_message = :error_message,
                updated_at = NOW()
            WHERE id = :id";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([
        ':id' => $taskId,
        ':status' => $status,
        ':error_message' => $errorMessage
    ]);
}

// Movie processing functions
function fetchMovies($category, $page, $limit) {
    $apiUrl = "https://api.example.com/movies?category=$category&page=$page&limit=$limit";
    $response = file_get_contents($apiUrl);
    
    if ($response === false) {
        throw new Exception("Failed to fetch movies from API");
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['movies'])) {
        throw new Exception("Invalid API response");
    }
    
    return $data['movies'];
}

function fetchMovieDetails($slug) {
    $apiUrl = "https://api.example.com/movie/$slug";
    $response = file_get_contents($apiUrl);
    
    if ($response === false) {
        throw new Exception("Failed to fetch movie details from API");
    }
    
    $data = json_decode($response, true);
    if (!$data || !isset($data['movie'])) {
        throw new Exception("Invalid API response");
    }
    
    return $data['movie'];
}

function importMovieToDB($movie, $existingId = null) {
    $pdo = getDBConnection();
    
    try {
        $pdo->beginTransaction();
        
        if ($existingId) {
            // Update existing movie
            $sql = "UPDATE mac_vod SET 
                    vod_name = ?, 
                    vod_name_en = ?,
                    vod_pic = ?,
                    vod_content = ?,
                    vod_actor = ?,
                    vod_director = ?,
                    vod_year = ?,
                    vod_area = ?,
                    vod_lang = ?,
                    vod_play_from = 'ngm3u8',
                    vod_play_url = ?,
                    vod_time = NOW()
                    WHERE vod_id = ?";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $movie['name'],
                $movie['slug'],
                $movie['poster'],
                $movie['description'],
                $movie['actors'],
                $movie['director'],
                $movie['year'],
                $movie['area'],
                $movie['language'],
                json_encode($movie['episodes']),
                $existingId
            ]);
        } else {
            // Insert new movie
            $sql = "INSERT INTO mac_vod (
                    vod_name, 
                    vod_name_en,
                    vod_pic,
                    vod_content,
                    vod_actor,
                    vod_director,
                    vod_year,
                    vod_area,
                    vod_lang,
                    vod_play_from,
                    vod_play_url,
                    vod_time
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'ngm3u8', ?, NOW())";
            
            $stmt = $pdo->prepare($sql);
            $stmt->execute([
                $movie['name'],
                $movie['slug'],
                $movie['poster'],
                $movie['description'],
                $movie['actors'],
                $movie['director'],
                $movie['year'],
                $movie['area'],
                $movie['language'],
                json_encode($movie['episodes'])
            ]);
        }
        
        $pdo->commit();
        return true;
        
    } catch (Exception $e) {
        $pdo->rollBack();
        error_log("Error importing/updating movie: " . $e->getMessage());
        throw $e;
    }
}

function checkMovieExists($slug) {
    $pdo = getDBConnection();
    $stmt = $pdo->prepare("SELECT vod_id FROM mac_vod WHERE vod_name_en = ?");
    $stmt->execute([$slug]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
} 