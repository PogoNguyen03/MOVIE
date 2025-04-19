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
</script> 