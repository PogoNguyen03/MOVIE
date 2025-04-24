function importMovieToDB($movieData, $existingMovieId = null) {
    global $conn;
    
    try {
        // Start transaction
        $conn->beginTransaction();
        
        // Prepare movie data
        $title = $movieData['name'];
        $slug = $movieData['slug'];
        $content = $movieData['content'] ?? '';
        $pic = $movieData['pic'] ?? '';
        $lang = $movieData['lang'] ?? 'Việt Sub';
        $area = $movieData['area'] ?? 'Việt Nam';
        $year = $movieData['year'] ?? date('Y');
        $state = $movieData['state'] ?? 'Đang chiếu';
        $actor = $movieData['actor'] ?? '';
        $director = $movieData['director'] ?? '';
        $des = $movieData['des'] ?? '';
        $tag = $movieData['tag'] ?? '';
        $class = $movieData['class'] ?? '';
        $note = $movieData['note'] ?? '';
        $time_add = time();
        $time_update = time();
        $hits = $movieData['hits'] ?? 0;
        $hits_day = $movieData['hits_day'] ?? 0;
        $hits_week = $movieData['hits_week'] ?? 0;
        $hits_month = $movieData['hits_month'] ?? 0;
        $content = $movieData['content'] ?? '';
        $playFrom = 'ngm3u8'; // Luôn sử dụng ngm3u8
        
        // Prepare episode data
        $episodeData = [];
        if (!empty($movieData['episodes'])) {
            foreach ($movieData['episodes'] as $episode) {
                $episodeData[] = [
                    'name' => $episode['name'],
                    'url' => $episode['url']
                ];
            }
        }
        
        if ($existingMovieId) {
            // Update existing movie
            $sql = "UPDATE mac_vod SET 
                    vod_name = ?, vod_slug = ?, vod_content = ?, vod_pic = ?,
                    vod_lang = ?, vod_area = ?, vod_year = ?, vod_state = ?,
                    vod_actor = ?, vod_director = ?, vod_des = ?, vod_tag = ?,
                    vod_class = ?, vod_note = ?, vod_time_update = ?,
                    vod_hits = ?, vod_hits_day = ?, vod_hits_week = ?, vod_hits_month = ?,
                    vod_content = ?, vod_play_from = ?
                    WHERE vod_id = ?";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssssssssiiiiissi", 
                $title, $slug, $content, $pic,
                $lang, $area, $year, $state,
                $actor, $director, $des, $tag,
                $class, $note, $time_update,
                $hits, $hits_day, $hits_week, $hits_month,
                $content, $playFrom, $existingMovieId
            );
            $stmt->execute();
            
            // Delete existing episodes
            $stmt = $conn->prepare("DELETE FROM mac_vod_play WHERE vod_id = ?");
            $stmt->bind_param("i", $existingMovieId);
            $stmt->execute();
            
            $movieId = $existingMovieId;
        } else {
            // Insert new movie
            $sql = "INSERT INTO mac_vod (vod_name, vod_slug, vod_content, vod_pic,
                    vod_lang, vod_area, vod_year, vod_state,
                    vod_actor, vod_director, vod_des, vod_tag,
                    vod_class, vod_note, vod_time_add, vod_time_update,
                    vod_hits, vod_hits_day, vod_hits_week, vod_hits_month,
                    vod_content, vod_play_from)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $conn->prepare($sql);
            $stmt->bind_param("ssssssssssssssiiiiiss", 
                $title, $slug, $content, $pic,
                $lang, $area, $year, $state,
                $actor, $director, $des, $tag,
                $class, $note, $time_add, $time_update,
                $hits, $hits_day, $hits_week, $hits_month,
                $content, $playFrom
            );
            $stmt->execute();
            $movieId = $conn->insert_id;
        }
        
        // Insert episodes
        if (!empty($episodeData)) {
            $sql = "INSERT INTO mac_vod_play (vod_id, play_name, play_url) VALUES (?, ?, ?)";
            $stmt = $conn->prepare($sql);
            
            foreach ($episodeData as $episode) {
                $stmt->bind_param("iss", $movieId, $episode['name'], $episode['url']);
                $stmt->execute();
            }
        }
        
        // Commit transaction
        $conn->commit();
        return true;
    } catch (Exception $e) {
        // Rollback transaction on error
        $conn->rollback();
        error_log("Error importing movie: " . $e->getMessage());
        return false;
    }
}

// Add new function to handle background processing
function processMovieImport($movieSlug, $sourceId, $isUpdate = false) {
    global $conn;
    
    try {
        // Get source details
        $stmt = $conn->prepare("SELECT * FROM mac_source WHERE source_id = ?");
        $stmt->bind_param("i", $sourceId);
        $stmt->execute();
        $result = $stmt->get_result();
        $source = $result->fetch_assoc();
        
        if (!$source) {
            return ['success' => false, 'message' => 'Source not found'];
        }
        
        // Fetch movie details
        $movieData = fetchMovieDetails($source['source_url'], $movieSlug);
        if (!$movieData) {
            return ['success' => false, 'message' => 'Failed to fetch movie details'];
        }
        
        // Check if movie exists
        $existingMovieId = null;
        if ($isUpdate) {
            $stmt = $conn->prepare("SELECT vod_id FROM mac_vod WHERE vod_slug = ?");
            $stmt->bind_param("s", $movieSlug);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($row = $result->fetch_assoc()) {
                $existingMovieId = $row['vod_id'];
            }
        }
        
        // Import movie
        if (importMovieToDB($movieData, $existingMovieId)) {
            return ['success' => true, 'message' => 'Movie imported successfully'];
        } else {
            return ['success' => false, 'message' => 'Failed to import movie'];
        }
    } catch (Exception $e) {
        error_log("Error in processMovieImport: " . $e->getMessage());
        return ['success' => false, 'message' => 'An error occurred'];
    }
}

// Add new endpoint for background processing
if (isset($_POST['action']) && $_POST['action'] === 'process_movie') {
    header('Content-Type: application/json');
    
    if (!isset($_POST['movie_slug']) || !isset($_POST['source_id'])) {
        echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
        exit;
    }
    
    $movieSlug = $_POST['movie_slug'];
    $sourceId = $_POST['source_id'];
    $isUpdate = isset($_POST['is_update']) ? (bool)$_POST['is_update'] : false;
    
    $result = processMovieImport($movieSlug, $sourceId, $isUpdate);
    echo json_encode($result);
    exit;
}

// Modify the main form to use AJAX
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Movie</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .loading {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 1000;
        }
        .loading-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            color: white;
        }
        .progress-bar {
            width: 300px;
            height: 20px;
            background: #ddd;
            border-radius: 10px;
            margin: 10px auto;
            overflow: hidden;
        }
        .progress {
            width: 0%;
            height: 100%;
            background: #4CAF50;
            transition: width 0.3s ease;
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="loading">
        <div class="loading-content">
            <i class="fas fa-spinner fa-spin fa-3x mb-4"></i>
            <div class="text-xl mb-2">Đang xử lý...</div>
            <div class="progress-bar">
                <div class="progress"></div>
            </div>
            <div class="status">Đang chuẩn bị...</div>
        </div>
    </div>

    <div class="container mx-auto px-4 py-8">
        <div class="max-w-2xl mx-auto bg-white rounded-lg shadow-md p-6">
            <h1 class="text-2xl font-bold mb-6">Import Movie</h1>
            
            <form id="importForm" class="space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700">Movie Slug</label>
                    <input type="text" name="movie_slug" required
                           class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                </div>
                
                <div>
                    <label class="block text-sm font-medium text-gray-700">Source</label>
                    <select name="source_id" required
                            class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <?php
                        $stmt = $conn->prepare("SELECT source_id, source_name FROM mac_source ORDER BY source_name");
                        $stmt->execute();
                        $result = $stmt->get_result();
                        while ($row = $result->fetch_assoc()) {
                            echo "<option value='{$row['source_id']}'>{$row['source_name']}</option>";
                        }
                        ?>
                    </select>
                </div>
                
                <div class="flex items-center">
                    <input type="checkbox" name="is_update" id="is_update" class="rounded border-gray-300 text-indigo-600 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <label for="is_update" class="ml-2 block text-sm text-gray-900">Update if exists</label>
                </div>
                
                <div class="flex justify-end">
                    <button type="submit" class="bg-indigo-600 text-white px-4 py-2 rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2">
                        Import Movie
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        document.getElementById('importForm').addEventListener('submit', function(e) {
            e.preventDefault();
            
            const formData = new FormData(this);
            formData.append('action', 'process_movie');
            
            // Show loading overlay
            document.querySelector('.loading').style.display = 'block';
            
            fetch('admin_get_movie.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                // Hide loading overlay
                document.querySelector('.loading').style.display = 'none';
                
                if (data.success) {
                    alert('Movie imported successfully!');
                    this.reset();
                } else {
                    alert('Error: ' + data.message);
                }
            })
            .catch(error => {
                // Hide loading overlay
                document.querySelector('.loading').style.display = 'none';
                alert('An error occurred while processing the request.');
                console.error('Error:', error);
            });
        });
    </script>
</body>
</html> 