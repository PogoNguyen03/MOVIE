<?php
require_once 'config.php';
require_once 'auth.php';

// Kết nối database
try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME, DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Lỗi kết nối: " . $e->getMessage());
}

$page_title = "Quản lý bài viết";
require_once 'layout.php';

// Xử lý các thao tác CRUD
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'add':
                if (!empty($_POST['art_name']) && !empty($_POST['art_content'])) {
                    $stmt = $pdo->prepare("INSERT INTO mac_art (art_name, art_content, art_time_add, art_time) VALUES (?, ?, ?, ?)");
                    $time = time();
                    $stmt->execute([$_POST['art_name'], $_POST['art_content'], $time, $time]);
                    $_SESSION['success'] = "Thêm bài viết thành công!";
                } else {
                    $_SESSION['error'] = "Vui lòng điền đầy đủ thông tin!";
                }
                break;

            case 'edit':
                if (!empty($_POST['art_id']) && !empty($_POST['art_name']) && !empty($_POST['art_content'])) {
                    $stmt = $pdo->prepare("UPDATE mac_art SET art_name = ?, art_content = ? WHERE art_id = ?");
                    $stmt->execute([$_POST['art_name'], $_POST['art_content'], $_POST['art_id']]);
                    $_SESSION['success'] = "Cập nhật bài viết thành công!";
                } else {
                    $_SESSION['error'] = "Vui lòng điền đầy đủ thông tin!";
                }
                break;

            case 'delete':
                if (!empty($_POST['art_id'])) {
                    $stmt = $pdo->prepare("DELETE FROM mac_art WHERE art_id = ?");
                    $stmt->execute([$_POST['art_id']]);
                    $_SESSION['success'] = "Xóa bài viết thành công!";
                }
                break;
        }
        header("Location: manage_articles.php");
        exit;
    }
}

// Lấy danh sách bài viết
$stmt = $pdo->query("SELECT * FROM mac_art ORDER BY art_time_add DESC");
$articles = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Quản lý bài viết</h1>
        <button onclick="openAddModal()" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
            <i class="fas fa-plus mr-2"></i>Thêm bài viết
        </button>
    </div>

    <?php if (isset($_SESSION['success'])): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></span>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline"><?php echo $_SESSION['error']; unset($_SESSION['error']); ?></span>
        </div>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tiêu đề</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ngày tạo</th>
                    <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Thao tác</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
                <?php foreach ($articles as $article): ?>
                    <tr>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo $article['art_id']; ?></td>
                        <td class="px-6 py-4"><?php echo htmlspecialchars($article['art_name']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap"><?php echo date('d/m/Y H:i', $article['art_time_add']); ?></td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <button onclick="openEditModal(<?php echo $article['art_id']; ?>, '<?php echo htmlspecialchars($article['art_name']); ?>', '<?php echo htmlspecialchars($article['art_content']); ?>')" class="text-blue-600 hover:text-blue-900 mr-3">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button onclick="openDeleteModal(<?php echo $article['art_id']; ?>)" class="text-red-600 hover:text-red-900">
                                <i class="fas fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal thêm bài viết -->
<div id="addModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900">Thêm bài viết mới</h3>
            <form method="POST" class="mt-4">
                <input type="hidden" name="action" value="add">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="art_name">Tiêu đề</label>
                    <input type="text" name="art_name" id="art_name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="art_content">Nội dung</label>
                    <textarea name="art_content" id="art_content" rows="5" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required></textarea>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="closeAddModal()" class="bg-gray-500 text-white px-4 py-2 rounded mr-2">Hủy</button>
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Thêm</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal sửa bài viết -->
<div id="editModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900">Sửa bài viết</h3>
            <form method="POST" class="mt-4">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="art_id" id="edit_art_id">
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_art_name">Tiêu đề</label>
                    <input type="text" name="art_name" id="edit_art_name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                </div>
                <div class="mb-4">
                    <label class="block text-gray-700 text-sm font-bold mb-2" for="edit_art_content">Nội dung</label>
                    <textarea name="art_content" id="edit_art_content" rows="5" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required></textarea>
                </div>
                <div class="flex justify-end">
                    <button type="button" onclick="closeEditModal()" class="bg-gray-500 text-white px-4 py-2 rounded mr-2">Hủy</button>
                    <button type="submit" class="bg-blue-500 text-white px-4 py-2 rounded">Cập nhật</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal xóa bài viết -->
<div id="deleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 hidden overflow-y-auto h-full w-full">
    <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900">Xóa bài viết</h3>
            <p class="mt-2 text-gray-600">Bạn có chắc chắn muốn xóa bài viết này?</p>
            <form method="POST" class="mt-4">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="art_id" id="delete_art_id">
                <div class="flex justify-end">
                    <button type="button" onclick="closeDeleteModal()" class="bg-gray-500 text-white px-4 py-2 rounded mr-2">Hủy</button>
                    <button type="submit" class="bg-red-500 text-white px-4 py-2 rounded">Xóa</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function openAddModal() {
    document.getElementById('addModal').classList.remove('hidden');
}

function closeAddModal() {
    document.getElementById('addModal').classList.add('hidden');
}

function openEditModal(id, name, content) {
    document.getElementById('edit_art_id').value = id;
    document.getElementById('edit_art_name').value = name;
    document.getElementById('edit_art_content').value = content;
    document.getElementById('editModal').classList.remove('hidden');
}

function closeEditModal() {
    document.getElementById('editModal').classList.add('hidden');
}

function openDeleteModal(id) {
    document.getElementById('delete_art_id').value = id;
    document.getElementById('deleteModal').classList.remove('hidden');
}

function closeDeleteModal() {
    document.getElementById('deleteModal').classList.add('hidden');
}
</script> 