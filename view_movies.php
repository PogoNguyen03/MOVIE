<?php
// Database connection
$host = "localhost";
$db = "movie";
$user = "root";
$pass = ""; // Empty password as specified

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8", $user, $pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get total movies count
    $totalMovies = $pdo->query("SELECT COUNT(*) FROM mac_vod")->fetchColumn();
    
    // Query to get movie data with pagination
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    $limit = 20;
    $offset = ($page - 1) * $limit;
    
    $stmt = $pdo->prepare("SELECT 
        vod_id, vod_name, vod_en, vod_year, vod_area, vod_lang, 
        vod_content, vod_pic, vod_remarks, vod_duration, vod_serial,
        vod_director, vod_actor, vod_time, vod_play_from, vod_play_url,
        vod_score, vod_hits, vod_trysee, vod_plot, vod_plot_name, vod_plot_detail,
        vod_slug
        FROM mac_vod 
        ORDER BY vod_id DESC 
        LIMIT :limit OFFSET :offset");
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $movies = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total pages
    $totalPages = ceil($totalMovies / $limit);
    
    // Output HTML
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Movie Database</title>
        <meta charset="utf-8">
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            table { border-collapse: collapse; width: 100%; margin-top: 20px; }
            th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
            th { background-color: #f2f2f2; }
            tr:nth-child(even) { background-color: #f9f9f9; }
            tr:hover { background-color: #f5f5f5; }
            .pagination { margin-top: 20px; }
            .pagination a { padding: 8px 16px; text-decoration: none; border: 1px solid #ddd; margin: 0 4px; }
            .pagination a.active { background-color: #4CAF50; color: white; border: 1px solid #4CAF50; }
            .movie-image { max-width: 100px; height: auto; border-radius: 5px; }
            .movie-description { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
            .movie-title { font-weight: bold; color: #333; }
            .movie-meta { color: #666; font-size: 0.9em; }
            .quality-badge { 
                display: inline-block;
                padding: 2px 6px;
                background-color: #4CAF50;
                color: white;
                border-radius: 3px;
                font-size: 0.8em;
            }
            .episode-count {
                display: inline-block;
                padding: 2px 6px;
                background-color: #2196F3;
                color: white;
                border-radius: 3px;
                font-size: 0.8em;
            }
            .movie-link {
                text-decoration: none;
                color: #333;
            }
            .movie-link:hover {
                text-decoration: underline;
                color: #4CAF50;
            }
        </style>
    </head>
    <body>
        <h1>Movie Database</h1>
        
        <div class="stats">
            <p>Total Movies: <?php echo $totalMovies; ?></p>
        </div>
        
        <h2>Movies (Page <?php echo $page; ?> of <?php echo $totalPages; ?>)</h2>
        <table>
            <tr>
                <th>ID</th>
                <th>Poster</th>
                <th>Name</th>
                <th>Original Name</th>
                <th>Year</th>
                <th>Area</th>
                <th>Language</th>
                <th>Duration</th>
                <th>Quality</th>
                <th>Episodes</th>
                <th>Director</th>
                <th>Actors</th>
                <th>Description</th>
                <th>Slug</th>
            </tr>
            <?php foreach ($movies as $movie): 
                // Validate and prepare data
                $movieId = $movie['vod_id'] ?? '';
                $movieName = $movie['vod_name'] ?? '';
                $movieEnName = $movie['vod_en'] ?? '';
                $moviePic = $movie['vod_pic'] ?? '';
                $movieYear = $movie['vod_year'] ?? '';
                $movieArea = $movie['vod_area'] ?? '';
                $movieLang = $movie['vod_lang'] ?? '';
                $movieDuration = $movie['vod_duration'] ?? '';
                $movieRemarks = $movie['vod_remarks'] ?? '';
                $movieSerial = $movie['vod_serial'] ?? '';
                $movieDirector = $movie['vod_director'] ?? '';
                $movieActor = $movie['vod_actor'] ?? '';
                $movieContent = $movie['vod_content'] ?? '';
                $movieSlug = $movie['vod_slug'] ?? '';
            ?>
            <tr>
                <td><?php echo htmlspecialchars($movieId); ?></td>
                <td>
                    <?php if (!empty($moviePic)): ?>
                        <img src="<?php echo htmlspecialchars($moviePic); ?>" alt="<?php echo htmlspecialchars($movieName); ?>" class="movie-image">
                    <?php else: ?>
                        <div class="no-image">No Image</div>
                    <?php endif; ?>
                </td>
                <td>
                    <a href="view_movie.php?slug=<?php echo htmlspecialchars($movieSlug); ?>" class="movie-link">
                        <div class="movie-title"><?php echo htmlspecialchars($movieName); ?></div>
                        <div class="movie-meta"><?php echo htmlspecialchars($movieEnName); ?></div>
                    </a>
                </td>
                <td><?php echo htmlspecialchars($movieYear); ?></td>
                <td><?php echo htmlspecialchars($movieArea); ?></td>
                <td><?php echo htmlspecialchars($movieLang); ?></td>
                <td><?php echo htmlspecialchars($movieDuration); ?></td>
                <td><span class="quality-badge"><?php echo htmlspecialchars($movieRemarks); ?></span></td>
                <td><span class="episode-count"><?php echo htmlspecialchars($movieSerial); ?> eps</span></td>
                <td><?php echo htmlspecialchars($movieDirector); ?></td>
                <td class="movie-description"><?php echo htmlspecialchars($movieActor); ?></td>
                <td class="movie-description"><?php echo htmlspecialchars($movieContent); ?></td>
                <td><?php echo htmlspecialchars($movieSlug); ?></td>
            </tr>
            <?php endforeach; ?>
        </table>
        
        <div class="pagination">
            <?php if ($page > 1): ?>
            <a href="?page=1">First</a>
            <a href="?page=<?php echo $page - 1; ?>">Previous</a>
            <?php endif; ?>
            
            <?php
            $startPage = max(1, $page - 2);
            $endPage = min($totalPages, $page + 2);
            
            for ($i = $startPage; $i <= $endPage; $i++) {
                if ($i == $page) {
                    echo "<a class='active' href='?page=$i'>$i</a>";
                } else {
                    echo "<a href='?page=$i'>$i</a>";
                }
            }
            ?>
            
            <?php if ($page < $totalPages): ?>
            <a href="?page=<?php echo $page + 1; ?>">Next</a>
            <a href="?page=<?php echo $totalPages; ?>">Last</a>
            <?php endif; ?>
        </div>
    </body>
    </html>
    <?php
} catch (PDOException $e) {
    echo "Connection failed: " . $e->getMessage();
}
?> 