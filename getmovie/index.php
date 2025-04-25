<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/api.php';
require_once __DIR__ . '/processor.php';
require_once __DIR__ . '/db_handler.php';
require_once __DIR__ . '/utils.php';

// Tạo thư mục logs nếu chưa tồn tại
$logDir = dirname(__DIR__) . '/logs';
if (!file_exists($logDir)) {
    if (!@mkdir($logDir, 0755, true)) {
        // Nếu không thể tạo thư mục, ghi log vào thư mục tạm của hệ thống
        $logDir = sys_get_temp_dir();
    }
}

// Đặt quyền ghi cho thư mục logs
@chmod($logDir, 0755);

// Tối ưu cấu hình PHP cho xử lý dữ liệu lớn
ini_set('memory_limit', '256M');
ini_set('max_execution_time', 300);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
ini_set('error_log', $logDir . '/php_errors.log');

// Hàm ghi log chi tiết
function writeDetailedLog($message, $type = 'INFO') {
    global $logDir;
    
    try {
        $logFile = $logDir . '/import_detailed.log';
        
        // Kiểm tra quyền ghi file
        if (!is_writable($logDir) && !@chmod($logDir, 0755)) {
            // Nếu không thể ghi vào thư mục logs, sử dụng thư mục tạm
            $logFile = sys_get_temp_dir() . '/import_detailed.log';
        }
        
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp][$type] $message\n";
        
        if (!error_log($logMessage, 3, $logFile)) {
            // Nếu không thể ghi log, hiển thị thông báo
            echo "<div class='bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mb-4'>
                    Warning: Không thể ghi log vào file. Đảm bảo thư mục {$logDir} có quyền ghi.
                  </div>";
        }
    } catch (Exception $e) {
        // Nếu có lỗi khi ghi log, hiển thị thông báo
        echo "<div class='bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4'>
                Error: {$e->getMessage()}
              </div>";
    }
}

// Hàm kiểm tra tài nguyên server
function checkServerResources() {
    $memoryUsage = memory_get_usage(true);
    $memoryLimit = ini_get('memory_limit');
    $memoryLimitBytes = return_bytes($memoryLimit);
    
    writeDetailedLog("Memory Usage: " . formatBytes($memoryUsage) . " / " . $memoryLimit);
    
    if ($memoryUsage > ($memoryLimitBytes * 0.9)) {
        writeDetailedLog("Memory usage too high!", 'WARNING');
        return false;
    }
    return true;
}

// Hàm chuyển đổi memory limit string sang bytes
function return_bytes($val) {
    $val = trim($val);
    $last = strtolower($val[strlen($val)-1]);
    $val = (int)$val;
    switch($last) {
        case 'g': $val *= 1024;
        case 'm': $val *= 1024;
        case 'k': $val *= 1024;
    }
    return $val;
}

// Hàm format bytes thành đơn vị đọc được
function formatBytes($bytes) {
    if ($bytes > 1024*1024*1024) {
        return round($bytes/1024/1024/1024, 2) . ' GB';
    }
    if ($bytes > 1024*1024) {
        return round($bytes/1024/1024, 2) . ' MB';
    }
    if ($bytes > 1024) {
        return round($bytes/1024, 2) . ' KB';
    }
    return $bytes . ' B';
}

// Kiểm tra xác thực
checkAuth();

// Kết nối database
try {
    writeDetailedLog("Attempting database connection");
    $pdo = new PDO(
        "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4",
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ]
    );
    writeDetailedLog("Database connection successful");
} catch (PDOException $e) {
    writeDetailedLog("Database connection failed: " . $e->getMessage(), 'ERROR');
    die("Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage());
}

$pageTitle = 'Import Phim';
require_once dirname(__DIR__) . '/layout.php';

// Process form submission
$result = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        writeDetailedLog("Starting import process");
        $startPage = isset($_POST['start_page']) ? (int)$_POST['start_page'] : 1;
        $endPage = isset($_POST['end_page']) ? (int)$_POST['end_page'] : 1;
        $limit = isset($_POST['limit']) ? (int)$_POST['limit'] : 10;
        $update_existing = isset($_POST['update_existing']) && $_POST['update_existing'] === '1';
        $category = isset($_POST['category']) ? $_POST['category'] : 'phim-moi-cap-nhat';
        $delay_between_requests = isset($_POST['delay']) ? (int)$_POST['delay'] : DEFAULT_DELAY;

        writeDetailedLog("Import parameters - Start: $startPage, End: $endPage, Limit: $limit, Category: $category");

        // Kiểm tra và điều chỉnh giới hạn để tránh quá tải
        if (($endPage - $startPage + 1) * $limit > 1000) {
            writeDetailedLog("Large import detected, adjusting parameters", 'WARNING');
            $limit = min($limit, 30);
            $delay_between_requests = max($delay_between_requests, 3);
        }

        // Validate category
        if (!array_key_exists($category, $movieCategories)) {
            $result['error'] = "Danh mục không hợp lệ.";
        } else {
            // Validate page range
            if ($startPage > $endPage) {
                $result['error'] = "Lỗi: Trang bắt đầu phải nhỏ hơn hoặc bằng trang kết thúc.";
            } else {
                try {
                    $totalImportedCount = 0;
                    $totalSkippedCount = 0;
                    $totalUpdatedCount = 0;
                    $totalMoviesCount = 0;
                    $result['pages'] = [];
                    
                    // Modify the API URL based on selected category
                    $categoryUrl = $movieCategories[$category]['url'];
                    
                    // Process each page
                    for ($page = $startPage; $page <= $endPage; $page++) {
                        if (!checkServerResources()) {
                            writeDetailedLog("Server resources critical, pausing import", 'ERROR');
                            throw new Exception("Server resources are running low. Import paused for safety.");
                        }

                        writeDetailedLog("Processing page $page");
                        
                        // Thêm delay giữa các trang
                        if ($page > $startPage) {
                            writeDetailedLog("Applying delay of {$delay_between_requests} seconds");
                            sleep($delay_between_requests);
                        }
                        
                        $pageResult = [
                            'page' => $page,
                            'total' => 0,
                            'imported' => 0,
                            'skipped' => 0,
                            'updated' => 0,
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
                                $updatedCount = 0;
                                
                                $pageResult['total'] = $totalMoviesOnPage;
                                
                                // Giới hạn số lượng phim xử lý trên mỗi trang
                                $moviesToProcess = array_slice($moviesOnPage, 0, $limit);
                                
                                foreach ($moviesToProcess as $movieIndex => $movie) {
                                    $totalMoviesCount++;
                                    
                                    // Log tiến trình
                                    error_log("Processing movie " . ($movieIndex + 1) . "/{$limit} on page {$page}/{$endPage}: {$movie['name']} (slug: {$movie['slug']})");
                                    
                                    // Thực hiện garbage collection sau mỗi 100 phim
                                    if ($totalMoviesCount % 100 == 0) {
                                        writeDetailedLog("Performing garbage collection");
                                        gc_collect_cycles();
                                    }
                                    
                                    // Check if movie already exists
                                    $existingMovie = checkMovieExists($movie['slug'], $pdo);
                                    
                                    // Fetch detailed movie information regardless of existence if update is enabled
                                    $movieDetails = null;
                                    if (!$existingMovie || $update_existing) {
                                        // Thêm delay giữa các lần fetch movie details
                                        if ($totalMoviesCount > 1) {
                                            sleep($delay_between_requests);
                                        }
                                        $movieDetails = fetchMovieDetails($movie['slug']);
                                    }

                                    if ($existingMovie) {
                                        if ($update_existing && $movieDetails) {
                                            // Log cập nhật
                                            error_log("Updating existing movie: {$movie['name']} (ID: {$existingMovie['vod_id']})");
                                            
                                            // Cập nhật phim hiện có
                                            $importResult = importMovieToDB($movieDetails, $pdo, $existingMovie['vod_id']);
                                            if ($importResult['success']) {
                                                $pageResult['updated']++;
                                                $updatedCount++;
                                            } else {
                                                $pageResult['errors'][] = "Lỗi cập nhật phim '{$movie['name']}': " . ($importResult['error'] ?? 'Unknown error');
                                            }
                                        } else {
                                            // Log bỏ qua
                                            error_log("Skipping existing movie: {$movie['name']} (ID: {$existingMovie['vod_id']})");
                                            
                                            // Bỏ qua phim hiện có nếu không chọn cập nhật
                                            $pageResult['skipped']++;
                                            $skippedCount++;
                                        }
                                    } elseif ($movieDetails) {
                                        // Log import phim mới
                                        error_log("Importing new movie: {$movie['name']}");
                                        
                                        // Import phim mới
                                        $importResult = importMovieToDB($movieDetails, $pdo);
                                        if ($importResult['success']) {
                                            $pageResult['imported']++;
                                            $importedCount++;
                                        } else {
                                            $pageResult['errors'][] = "Lỗi import phim '{$movie['name']}': " . ($importResult['error'] ?? 'Unknown error');
                                        }
                                    } else {
                                        // Log lỗi phim mới
                                        error_log("Failed to fetch details for movie: {$movie['name']} (slug: {$movie['slug']})");
                                         
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
                        'total_updated' => $totalUpdatedCount
                    ];
                    
                    writeDetailedLog("Import process completed successfully");
                    
                } catch (PDOException $e) {
                    writeDetailedLog("Database connection failed: " . $e->getMessage(), 'ERROR');
                    $result['error'] = "Lỗi kết nối cơ sở dữ liệu: " . $e->getMessage();
                } catch (Exception $e) {
                    writeDetailedLog("Error during import: " . $e->getMessage(), 'ERROR');
                    $result['error'] = "Lỗi: " . $e->getMessage();
                }
            }
        }
    } catch (Exception $e) {
        writeDetailedLog("Error during import: " . $e->getMessage(), 'ERROR');
        $result['error'] = "Lỗi: " . $e->getMessage();
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
                                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo (isset($_POST['category']) && $_POST['category'] === $key) ? 'selected' : ''; ?>><?php echo htmlspecialchars($category['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div>
                                <label for="start_page" class="block text-sm font-medium text-gray-700 mb-1">Trang bắt đầu:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="start_page" name="start_page" value="<?php echo isset($_POST['start_page']) ? intval($_POST['start_page']) : 1; ?>" min="1" required>
                            </div>
                            <div>
                                <label for="end_page" class="block text-sm font-medium text-gray-700 mb-1">Trang kết thúc:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="end_page" name="end_page" value="<?php echo isset($_POST['end_page']) ? intval($_POST['end_page']) : 1; ?>" min="1" required>
                            </div>
                            <div>
                                <label for="limit" class="block text-sm font-medium text-gray-700 mb-1">Số lượng phim tối đa trên mỗi trang:</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="limit" name="limit" value="<?php echo isset($_POST['limit']) ? intval($_POST['limit']) : 10; ?>" min="1" required>
                            </div>
                            <div>
                                <label for="delay" class="block text-sm font-medium text-gray-700 mb-1">Thời gian chờ giữa các request (giây):</label>
                                <input type="number" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent" id="delay" name="delay" value="<?php echo isset($_POST['delay']) ? intval($_POST['delay']) : DEFAULT_DELAY; ?>" min="1" max="20" required>
                                <p class="mt-1 text-sm text-gray-500">Tăng thời gian này sẽ giúp tránh lỗi "Too Many Requests"</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Xử lý phim đã tồn tại:</label>
                                <div class="flex space-x-4">
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="update_existing" value="0" <?php echo !isset($_POST['update_existing']) || $_POST['update_existing'] === '0' ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-blue-600">
                                        <span class="ml-2 text-gray-700">Bỏ qua</span>
                                    </label>
                                    <label class="inline-flex items-center">
                                        <input type="radio" name="update_existing" value="1" <?php echo isset($_POST['update_existing']) && $_POST['update_existing'] === '1' ? 'checked' : ''; ?> class="form-radio h-4 w-4 text-blue-600">
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
                                <p class="text-sm text-gray-600" id="currentPage">Trang: <?php echo isset($_POST['start_page']) ? intval($_POST['start_page']) : 1; ?>/<?php echo isset($_POST['end_page']) ? intval($_POST['end_page']) : 1; ?></p>
                                <p class="text-sm text-gray-600" id="currentMovie">Phim: 0/<?php echo isset($_POST['limit']) ? intval($_POST['limit']) : 10; ?></p>
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
    
<?php require_once dirname(__DIR__) . '/footer.php'; ?>

<script>
    document.addEventListener('DOMContentLoaded', function() {
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
        let totalPages = 0;
        let totalMoviesToProcess = 0;
        let currentPageNum = 0;
        let currentMovieNum = 0;
        let importedMovies = 0;
        let updatedMovies = 0;
        let skippedMovies = 0;
        let progressCheckInterval;
        
        // Kiểm tra nếu có tiến độ đã lưu từ lần trước bị lỗi
        checkAndRestoreProgress();
        
        // Kiểm tra nếu đã có kết quả thì hiển thị section
        <?php if (!empty($result) && !isset($result['error'])): ?>
            // Chuyển đến kết quả nếu đã có
            if (document.querySelector('.bg-blue-50')) {
                document.querySelector('.bg-blue-50').scrollIntoView({ behavior: 'smooth' });
            }
        <?php endif; ?>
        
        importForm.addEventListener('submit', function(e) {
            e.preventDefault();
            
            // Reset tiến độ
            startTime = new Date();
            currentPageNum = 0;
            currentMovieNum = 0;
            importedMovies = 0;
            updatedMovies = 0;
            skippedMovies = 0;
            
            // Lấy thông tin trang và số lượng phim
            const startPage = parseInt(document.getElementById('start_page').value);
            const endPage = parseInt(document.getElementById('end_page').value);
            const limit = parseInt(document.getElementById('limit').value);
            const category = document.getElementById('category').value;
            const delay = parseInt(document.getElementById('delay').value);
            const updateExisting = document.querySelector('input[name="update_existing"]:checked').value;
            
            // Lưu trạng thái ban đầu vào localStorage
            saveImportProgress({
                startPage: startPage,
                currentPage: startPage,
                endPage: endPage,
                limit: limit,
                category: category,
                delay: delay,
                updateExisting: updateExisting,
                lastProcessedPage: startPage - 1
            });
            
            totalPages = endPage - startPage + 1;
            totalMoviesToProcess = totalPages * limit;
            
            // Reset UI
            progressSection.style.display = 'block';
            statusMessage.textContent = 'Đang bắt đầu...';
            statusMessage.className = 'text-sm text-gray-600';
            
            // Cập nhật UI ban đầu với trang bắt đầu
            updateProgressUI(0, startPage, 0);
            
            // Bắt đầu đếm thời gian
            clearInterval(timerInterval);
            timerInterval = setInterval(updateTimer, 1000);
            
            // Tạo FormData từ form
            const formData = new FormData(importForm);
            
            // Disable form while processing
            const submitButton = importForm.querySelector('button[type="submit"]');
            submitButton.disabled = true;
            submitButton.innerHTML = '<i class="fas fa-spinner fa-spin mr-2"></i>Đang xử lý...';
            
            // Bắt đầu kiểm tra tiến độ bằng cách mô phỏng
            startProgressUpdate(startPage, endPage, limit);
            
            processImport(formData, submitButton);
        });

        function processImport(formData, submitButton) {
            fetch('', {
                method: 'POST',
                body: formData
            })
            .then(response => {
                if (!response.ok) {
                    // Cập nhật tiến độ hiện tại trước khi ném lỗi
                    const progress = getImportProgress();
                    if (progress) {
                        progress.lastError = `HTTP error! status: ${response.status}`;
                        progress.lastErrorTime = new Date().toISOString();
                        saveImportProgress(progress);
                    }
                    
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.text();
            })
            .then(html => {
                try {
                    const parser = new DOMParser();
                    const doc = parser.parseFromString(html, 'text/html');
                    
                    // Kiểm tra nếu có thông báo lỗi trong response
                    const errorElement = doc.querySelector('.bg-red-100');
                    if (errorElement) {
                        const errorText = errorElement.textContent.trim();
                        
                        // Cập nhật tiến độ với lỗi
                        const progress = getImportProgress();
                        if (progress) {
                            progress.lastError = errorText;
                            progress.lastErrorTime = new Date().toISOString();
                            saveImportProgress(progress);
                        }
                        
                        throw new Error(errorText);
                    }
                    
                    // Cập nhật tiến độ cuối cùng - Hiển thị trạng thái hoàn tất với trang cuối
                    const endPage = parseInt(document.getElementById('end_page').value);
                    updateProgressUI(100, endPage, parseInt(document.getElementById('limit').value));
                    
                    // Thay vì thay thế toàn bộ body, chỉ thay thế phần kết quả
                    const resultContainer = doc.querySelector('.bg-white.rounded-lg.shadow-sm.p-6:not(#progressSection):not(:has(form))');
                    
                    if (resultContainer) {
                        // Kiểm tra xem đã có container kết quả trên trang chưa
                        let existingResultContainer = document.querySelector('.bg-white.rounded-lg.shadow-sm.p-6:not(#progressSection):not(:has(form))');
                        
                        if (existingResultContainer) {
                            // Nếu đã có, thay thế nội dung
                            existingResultContainer.innerHTML = resultContainer.innerHTML;
                        } else {
                            // Nếu chưa có, thêm vào sau progressSection
                            progressSection.insertAdjacentHTML('afterend', resultContainer.outerHTML);
                        }
                    }
                    
                    // Kích hoạt lại form
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fas fa-download mr-2"></i>Import / Cập nhật Phim';
                    
                    // Ẩn progressSection sau khi hoàn thành
                    progressSection.style.display = 'none';
                    
                    // Cuộn đến kết quả
                    const newResultContainer = document.querySelector('.bg-white.rounded-lg.shadow-sm.p-6:not(#progressSection):not(:has(form))');
                    if (newResultContainer) {
                        newResultContainer.scrollIntoView({ behavior: 'smooth' });
                    }
                    
                    // Xóa tiến độ đã lưu vì đã hoàn tất
                    clearImportProgress();
                    
                    statusMessage.textContent = 'Hoàn tất!';
                    statusMessage.className = 'text-sm text-green-600';
                } catch (error) {
                    console.error('Parse error:', error);
                    showError('Lỗi khi xử lý phản hồi từ server: ' + error.message);
                    
                    // Kích hoạt lại form
                    submitButton.disabled = false;
                    submitButton.innerHTML = '<i class="fas fa-download mr-2"></i>Import / Cập nhật Phim';
                }
            })
            .catch(error => {
                console.error('Fetch error:', error);
                
                // Kiểm tra nếu là lỗi 524 (Cloudflare timeout)
                if (error.message.includes('524')) {
                    showError524WithRetry();
                } else {
                    showError('Lỗi: ' + error.message);
                }
                
                // Re-enable form
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fas fa-download mr-2"></i>Import / Cập nhật Phim';
            })
            .finally(() => {
                clearInterval(timerInterval);
                clearInterval(progressCheckInterval);
            });
        }

        // Hàm mô phỏng cập nhật tiến độ (vì không có API thực tế để kiểm tra)
        function startProgressUpdate(startPage, endPage, limit) {
            const totalItems = (endPage - startPage + 1) * limit;
            let processedItems = 0;
            
            clearInterval(progressCheckInterval);
            progressCheckInterval = setInterval(() => {
                // Mô phỏng tiến độ dựa trên thời gian đã trôi qua
                const elapsed = (new Date() - startTime) / 1000;
                const estimatedTimePerItem = 2.5; // Ước tính thời gian xử lý mỗi phim
                
                // Ước tính số phim đã xử lý dựa trên thời gian
                processedItems = Math.min(Math.floor(elapsed / estimatedTimePerItem), totalItems);
                
                // Tính toán trang và phim hiện tại
                currentPageNum = Math.floor(processedItems / limit) + startPage;
                if (currentPageNum > endPage) currentPageNum = endPage;
                
                currentMovieNum = processedItems % limit;
                if (currentPageNum === endPage && currentMovieNum === 0 && processedItems > 0) {
                    currentMovieNum = limit;
                }
                
                // Mô phỏng phân bổ giữa import mới, cập nhật và bỏ qua
                importedMovies = Math.floor(processedItems * 0.6); // 60% new imports
                updatedMovies = Math.floor(processedItems * 0.2); // 20% updates
                skippedMovies = processedItems - importedMovies - updatedMovies; // 20% skipped
                
                // Tính phần trăm hoàn thành
                const percentComplete = Math.min(Math.floor((processedItems / totalItems) * 100), 99);
                
                // Cập nhật UI
                updateProgressUI(percentComplete, currentPageNum, currentMovieNum);
                
                // Lưu tiến độ
                updateImportProgress(currentPageNum);
                
                // Nếu đã hoàn thành, dừng kiểm tra
                if (processedItems >= totalItems) {
                    clearInterval(progressCheckInterval);
                }
            }, 500);
        }

        function showError(message) {
            statusMessage.textContent = message;
            statusMessage.className = 'text-sm text-red-600';
            
            // Tạo thông báo lỗi chi tiết
            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mt-4';
            errorDiv.innerHTML = `
                <p class="font-bold">Lỗi xảy ra!</p>
                <p>${message}</p>
                <p class="mt-2">Vui lòng thử các bước sau:</p>
                <ul class="list-disc ml-5 mt-1">
                    <li>Giảm số lượng trang cần import (thử với 5-10 trang một lần)</li>
                    <li>Tăng thời gian delay giữa các request (thử với 5-10 giây)</li>
                    <li>Kiểm tra log file trong thư mục logs để biết chi tiết lỗi</li>
                    <li>Đảm bảo kết nối internet ổn định</li>
                </ul>
            `;
            
            // Chèn thông báo lỗi vào sau progress section
            progressSection.insertAdjacentElement('afterend', errorDiv);
        }
        
        function showError524WithRetry() {
            const progress = getImportProgress();
            if (!progress) return showError('Lỗi 524: Cloudflare Timeout. Không thể khôi phục tiến độ.');
            
            // Tính toán trang tiếp theo cần xử lý
            const nextPage = progress.lastProcessedPage + 1;
            
            // Tính toán delay mới - tăng lên gấp đôi nhưng tối thiểu 5s, tối đa 20s
            const newDelay = Math.min(20, Math.max(5, progress.delay * 2));
            
            statusMessage.textContent = 'Lỗi 524: Cloudflare Timeout - Đang chuẩn bị thử lại...';
            statusMessage.className = 'text-sm text-orange-600';
            
            // Tạo thông báo lỗi chi tiết với đồng hồ đếm ngược
            const errorDiv = document.createElement('div');
            errorDiv.className = 'bg-yellow-100 border-l-4 border-yellow-500 text-yellow-700 p-4 mt-4';
            errorDiv.innerHTML = `
                <p class="font-bold">Lỗi 524: Cloudflare Timeout!</p>
                <p>Server đã mất quá nhiều thời gian để phản hồi. Quá trình import đã dừng ở trang ${progress.lastProcessedPage}.</p>
                <div class="mt-4 mb-2">
                    <p class="font-medium">Thông tin tiến trình:</p>
                    <ul class="list-disc pl-5 mt-1 mb-3">
                        <li>Đã xử lý đến: Trang ${progress.lastProcessedPage}</li>
                        <li>Trang bắt đầu: ${progress.startPage}</li>
                        <li>Trang kết thúc: ${progress.endPage}</li>
                        <li>Thời gian xảy ra lỗi: ${new Date(progress.lastErrorTime).toLocaleString()}</li>
                    </ul>
                </div>
                <p class="mb-2">Hệ thống sẽ tự động tiếp tục từ trang ${nextPage} sau <span id="countdown">30</span> giây...</p>
                <p class="mb-4 text-sm">Thời gian chờ giữa các request đã được tăng lên ${newDelay} giây để tránh lỗi Cloudflare Timeout.</p>
                <div class="flex space-x-2">
                    <button id="retryNow" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg focus:outline-none">
                        <i class="fas fa-play mr-2"></i>Tiếp tục ngay
                    </button>
                    <button id="cancelRetry" class="bg-red-600 hover:bg-red-700 text-white font-medium py-2 px-4 rounded-lg focus:outline-none">
                        <i class="fas fa-stop mr-2"></i>Dừng lại
                    </button>
                </div>
            `;
            
            // Chèn thông báo lỗi vào sau progress section
            progressSection.insertAdjacentElement('afterend', errorDiv);
            
            // Bắt đầu đếm ngược
            let countdown = 30; // 30 giây đếm ngược
            let countdownTimer;
            let autoRetryTimeout;
            
            function updateCountdown() {
                document.getElementById('countdown').textContent = countdown;
                if (countdown <= 0) {
                    clearInterval(countdownTimer);
                    startRetry();
                } else {
                    countdown--;
                }
            }
            
            function startRetry() {
                // Cập nhật form với giá trị mới
                document.getElementById('start_page').value = nextPage;
                document.getElementById('end_page').value = progress.endPage;
                document.getElementById('limit').value = progress.limit;
                document.getElementById('category').value = progress.category;
                document.getElementById('delay').value = newDelay;
                
                // Chọn radio button update_existing
                const updateExistingRadio = document.querySelector(`input[name="update_existing"][value="${progress.updateExisting}"]`);
                if (updateExistingRadio) updateExistingRadio.checked = true;
                
                // Xóa thông báo lỗi
                errorDiv.remove();
                
                // Submit form
                importForm.dispatchEvent(new Event('submit'));
            }
            
            function cancelRetry() {
                // Dừng đếm ngược và hủy auto retry
                clearInterval(countdownTimer);
                clearTimeout(autoRetryTimeout);
                
                // Cập nhật UI
                errorDiv.innerHTML = `
                    <p class="font-bold">Đã dừng tiến trình tự động!</p>
                    <p>Bạn có thể tiếp tục thủ công khi đã sẵn sàng.</p>
                    <div class="mt-4">
                        <button id="manualRetry" class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg focus:outline-none">
                            <i class="fas fa-sync-alt mr-2"></i>Tiếp tục từ trang ${nextPage}
                        </button>
                        <button id="clearProgressBtn" class="ml-2 bg-gray-500 hover:bg-gray-600 text-white font-medium py-2 px-4 rounded-lg focus:outline-none">
                            <i class="fas fa-trash-alt mr-2"></i>Xóa tiến độ
                        </button>
                    </div>
                `;
                
                // Thêm event listeners mới
                document.getElementById('manualRetry').addEventListener('click', startRetry);
                document.getElementById('clearProgressBtn').addEventListener('click', function() {
                    clearImportProgress();
                    errorDiv.remove();
                    showError('Đã xóa tiến độ. Bạn có thể bắt đầu lại từ đầu.');
                });
            }
            
            // Thêm event listener cho các nút
            document.getElementById('retryNow').addEventListener('click', function() {
                clearInterval(countdownTimer);
                clearTimeout(autoRetryTimeout);
                startRetry();
            });
            
            document.getElementById('cancelRetry').addEventListener('click', cancelRetry);
            
            // Bắt đầu đếm ngược
            countdownTimer = setInterval(updateCountdown, 1000);
            // Đặt timeout để tự động retry sau khi đếm ngược kết thúc
            autoRetryTimeout = setTimeout(startRetry, countdown * 1000);
            
            // Cập nhật ngay lần đầu
            updateCountdown();
        }

        function updateProgressUI(percent, currentPageNum, currentMovieNumInPage) {
            // Cập nhật thanh tiến độ
            importProgress.style.width = percent + '%';
            importProgress.textContent = percent + '%';
            importProgress.setAttribute('aria-valuenow', percent);
            
            // Lấy giá trị trang cuối từ input người dùng
            const endPage = parseInt(document.getElementById('end_page').value);
            
            // Cập nhật thông tin trang và phim hiện tại
            currentPage.textContent = `Trang: ${currentPageNum}/${endPage}`;
            currentMovie.textContent = `Phim: ${currentMovieNumInPage}/${Math.min(parseInt(document.getElementById('limit').value), 100)}`;
            
            // Cập nhật thống kê
            importedCountEl.textContent = `Đã import mới: ${importedMovies} phim`;
            updatedCountEl.textContent = `Đã cập nhật: ${updatedMovies} phim`;
            skippedCountEl.textContent = `Đã bỏ qua: ${skippedMovies} phim`;
        }
        
        function updateTimer() {
            const now = new Date();
            const elapsed = Math.floor((now - startTime) / 1000);
            
            // Hiển thị thời gian đã trôi qua
            elapsedTime.textContent = `Đã trải qua: ${formatTime(elapsed)}`;
            
            // Ước tính thời gian còn lại
            if (importedMovies + updatedMovies + skippedMovies > 0) {
                const processedItems = importedMovies + updatedMovies + skippedMovies;
                const percentDone = processedItems / totalMoviesToProcess;
                if (percentDone > 0) {
                    const totalTimeEstimate = elapsed / percentDone;
                    const remainingTime = Math.max(0, totalTimeEstimate - elapsed);
                    estimatedTime.textContent = `Thời gian còn lại: ${formatTime(Math.round(remainingTime))}`;
                }
            }
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
        
        // Các hàm xử lý lưu và khôi phục tiến độ
        function saveImportProgress(progress) {
            localStorage.setItem('movieImportProgress', JSON.stringify(progress));
        }
        
        function getImportProgress() {
            const progressStr = localStorage.getItem('movieImportProgress');
            return progressStr ? JSON.parse(progressStr) : null;
        }
        
        function clearImportProgress() {
            localStorage.removeItem('movieImportProgress');
        }
        
        function updateImportProgress(currentPage) {
            const progress = getImportProgress();
            if (progress) {
                progress.currentPage = currentPage;
                progress.lastProcessedPage = currentPage;
                saveImportProgress(progress);
            }
        }
        
        function checkAndRestoreProgress() {
            const progress = getImportProgress();
            if (progress && progress.lastError && progress.lastError.includes('524')) {
                // Nếu có lỗi 524 trong tiến trình đã lưu, hiển thị thông báo khôi phục
                showError524WithRetry();
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