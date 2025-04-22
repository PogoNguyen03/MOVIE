<?php
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

// Xử lý khi người dùng gửi form
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Xử lý thêm mới liên kết
    if (isset($_POST['add'])) {
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $sort_order = (int)$_POST['sort_order'];
        
        if (empty($name) || empty($url)) {
            $error = "Vui lòng nhập đầy đủ tên và URL liên kết";
        } else {
            try {
                $time = time();
                $stmt = $pdo->prepare("INSERT INTO mac_link (link_name, link_url, link_sort, link_add_time, link_time) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$name, $url, $sort_order, $time, $time]);
                $success = "Thêm liên kết thành công";
            } catch (PDOException $e) {
                $error = "Lỗi thêm liên kết: " . $e->getMessage();
            }
        }
    }
    
    // Xử lý cập nhật liên kết
    if (isset($_POST['update'])) {
        $id = (int)$_POST['id'];
        $name = trim($_POST['name']);
        $url = trim($_POST['url']);
        $sort_order = (int)$_POST['sort_order'];
        
        if (empty($name) || empty($url)) {
            $error = "Vui lòng nhập đầy đủ tên và URL liên kết";
        } else {
            try {
                $time = time();
                $stmt = $pdo->prepare("UPDATE mac_link SET link_name = ?, link_url = ?, link_sort = ?, link_time = ? WHERE link_id = ?");
                $stmt->execute([$name, $url, $sort_order, $time, $id]);
                $success = "Cập nhật liên kết thành công";
            } catch (PDOException $e) {
                $error = "Lỗi cập nhật liên kết: " . $e->getMessage();
            }
        }
    }
    
    // Xử lý xóa liên kết
    if (isset($_POST['delete'])) {
        $id = (int)$_POST['id'];
        
        try {
            $stmt = $pdo->prepare("DELETE FROM mac_link WHERE link_id = ?");
            $stmt->execute([$id]);
            $success = "Xóa liên kết thành công";
        } catch (PDOException $e) {
            $error = "Lỗi xóa liên kết: " . $e->getMessage();
        }
    }
}

// Lấy danh sách liên kết
try {
    $stmt = $pdo->query("SELECT * FROM mac_link ORDER BY link_sort ASC, link_id ASC");
    $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Lỗi truy vấn dữ liệu: " . $e->getMessage();
    $links = [];
}

$pageTitle = "Quản lý liên kết";
require_once 'layout.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Quản lý liên kết</h1>
    </div>
    
    <!-- Form thêm mới -->
    <div class="bg-white shadow-md rounded-lg p-6 mb-6">
        <h2 class="text-xl font-semibold mb-4">Thêm liên kết mới</h2>
        <form method="POST" action="" class="space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="name">Tên liên kết</label>
                    <input type="text" name="name" id="name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="url">URL</label>
                    <input type="url" name="url" id="url" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                
                <div>
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="sort_order">Thứ tự</label>
                    <input type="number" name="sort_order" id="sort_order" value="0" min="0" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                </div>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" name="add" class="bg-green-500 hover:bg-green-600 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                    <i class="fas fa-plus mr-1"></i> Thêm liên kết
                </button>
            </div>
        </form>
    </div>
    
    <!-- Danh sách liên kết -->
    <div class="bg-white shadow-md rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tên liên kết</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">URL</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trạng thái</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thứ tự</th>
                    <th scope="col" class="px-6 py-3 text-center text-xs font-medium text-gray-500 uppercase tracking-wider">Thao tác</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php if (empty($links)): ?>
                <tr>
                    <td colspan="6" class="px-6 py-4 text-center text-sm text-gray-500">Chưa có liên kết nào.</td>
                </tr>
                <?php else: ?>
                    <?php foreach ($links as $link): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo $link['link_id']; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo htmlspecialchars($link['link_name']); ?></td>
                        <td class="px-6 py-4"><a href="<?php echo htmlspecialchars($link['link_url']); ?>" target="_blank" class="text-blue-600 hover:underline"><?php echo htmlspecialchars($link['link_url']); ?></a></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full bg-green-100 text-green-800">
                                Hiển thị
                            </span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo $link['link_sort']; ?></td>
                        <td class="px-6 py-4 whitespace-nowrap text-center">
                            <button class="edit-link-btn text-blue-600 hover:text-blue-900 mr-3" data-id="<?php echo $link['link_id']; ?>" data-name="<?php echo htmlspecialchars($link['link_name']); ?>" data-url="<?php echo htmlspecialchars($link['link_url']); ?>" data-sort="<?php echo $link['link_sort']; ?>">
                                <i class="fas fa-edit"></i>
                            </button>
                            <form method="POST" action="" class="inline-block delete-form">
                                <input type="hidden" name="id" value="<?php echo $link['link_id']; ?>">
                                <button type="submit" name="delete" class="text-red-600 hover:text-red-900" onclick="return confirm('Bạn có chắc chắn muốn xóa liên kết này?');">
                                    <i class="fas fa-trash-alt"></i>
                                </button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal sửa liên kết -->
<div id="editModal" class="fixed z-10 inset-0 overflow-y-auto hidden">
    <div class="flex items-center justify-center min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
        <div class="fixed inset-0 transition-opacity" aria-hidden="true">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>
        <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>
        <div class="inline-block align-bottom bg-white rounded-lg text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:align-middle sm:max-w-lg sm:w-full">
            <form method="POST" action="" id="editForm">
                <div class="bg-white px-4 pt-5 pb-4 sm:p-6 sm:pb-4">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Sửa liên kết</h3>
                    <input type="hidden" name="id" id="edit_id">
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_name">Tên liên kết</label>
                        <input type="text" name="name" id="edit_name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_url">URL</label>
                        <input type="url" name="url" id="edit_url" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    </div>
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_sort_order">Thứ tự</label>
                        <input type="number" name="sort_order" id="edit_sort_order" min="0" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline">
                    </div>
                </div>
                <div class="bg-gray-50 px-4 py-3 sm:px-6 sm:flex sm:flex-row-reverse">
                    <button type="submit" name="update" class="w-full inline-flex justify-center rounded-md border border-transparent shadow-sm px-4 py-2 bg-blue-600 text-base font-medium text-white hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 sm:ml-3 sm:w-auto sm:text-sm">
                        Cập nhật
                    </button>
                    <button type="button" class="close-modal mt-3 w-full inline-flex justify-center rounded-md border border-gray-300 shadow-sm px-4 py-2 bg-white text-base font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 sm:mt-0 sm:ml-3 sm:w-auto sm:text-sm">
                        Hủy
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Xử lý sự kiện nút sửa
        const editButtons = document.querySelectorAll('.edit-link-btn');
        const editModal = document.getElementById('editModal');
        const closeModalButtons = document.querySelectorAll('.close-modal');
        
        // Hiển thị modal khi nhấn nút sửa
        editButtons.forEach(button => {
            button.addEventListener('click', function() {
                const id = this.getAttribute('data-id');
                const name = this.getAttribute('data-name');
                const url = this.getAttribute('data-url');
                const sort = this.getAttribute('data-sort');
                
                document.getElementById('edit_id').value = id;
                document.getElementById('edit_name').value = name;
                document.getElementById('edit_url').value = url;
                document.getElementById('edit_sort_order').value = sort;
                
                editModal.classList.remove('hidden');
            });
        });
        
        // Đóng modal khi nhấn nút hủy
        closeModalButtons.forEach(button => {
            button.addEventListener('click', function() {
                editModal.classList.add('hidden');
            });
        });
        
        // Đóng modal khi nhấn ngoài vùng modal
        editModal.addEventListener('click', function(e) {
            if (e.target === editModal) {
                editModal.classList.add('hidden');
            }
        });
    });
</script> 