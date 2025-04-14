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
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle ?? 'Admin Panel'; ?></title>
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap');
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #f3f4f6;
        }
        
        .card {
            transition: all 0.3s ease;
        }
        
        .card:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        
        .sidebar {
            transition: all 0.3s ease;
        }
        
        .content {
            transition: all 0.3s ease;
        }
        
        @media (max-width: 768px) {
            .sidebar {
                transform: translateX(-100%);
            }
            
            .sidebar.active {
                transform: translateX(0);
            }
            
            .content {
                margin-left: 0;
            }
        }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen overflow-hidden">
        <!-- Sidebar -->
        <div class="sidebar fixed top-0 left-0 h-full w-64 bg-white shadow-lg z-10">
            <div class="p-4 border-b border-gray-200">
                <h1 class="text-xl font-bold text-gray-800">Movie Manager</h1>
            </div>
            <div class="p-4">
                <ul class="space-y-2">
                    <li>
                        <a href="manage_movies.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'manage_movies.php' ? 'bg-gray-100' : ''; ?>">
                            <i class="fas fa-film w-5 h-5 mr-3"></i>
                            <span>Quản lý phim</span>
                        </a>
                    </li>
                    <li>
                        <a href="admin_get_movie.php" class="flex items-center p-2 text-gray-700 hover:bg-gray-100 rounded-lg <?php echo basename($_SERVER['PHP_SELF']) == 'admin_get_movie.php' ? 'bg-gray-100' : ''; ?>">
                            <i class="fas fa-download w-5 h-5 mr-3"></i>
                            <span>Import phim</span>
                        </a>
                    </li>
                </ul>
            </div>
        </div>
        
        <!-- Main Content -->
        <div class="content ml-64 flex-1 overflow-auto">
            <!-- Top Navigation -->
            <div class="bg-white shadow-sm">
                <div class="flex items-center justify-between p-4">
                    <button id="sidebarToggle" class="text-gray-500 hover:text-gray-700 focus:outline-none">
                        <i class="fas fa-bars text-xl"></i>
                    </button>
                    <div class="flex items-center space-x-4">
                        <div class="relative">
                            <form action="" method="GET" class="flex items-center">
                                <input type="text" name="search" placeholder="Tìm kiếm phim..." value="<?php echo htmlspecialchars($search ?? ''); ?>" class="pl-10 pr-4 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                                <i class="fas fa-search absolute left-3 top-3 text-gray-400"></i>
                                <button type="submit" class="ml-2 bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2">
                                    Tìm
                                </button>
                            </form>
                        </div>
                        <div class="flex items-center space-x-2">
                            <span class="text-sm text-gray-600"><?php echo htmlspecialchars($_SESSION['admin_username']); ?></span>
                            <img src="https://ui-avatars.com/api/?name=Admin&background=0D8ABC&color=fff" alt="User" class="w-8 h-8 rounded-full">
                            <a href="logout.php" class="text-red-600 hover:text-red-800">
                                <i class="fas fa-sign-out-alt"></i> Đăng xuất
                            </a>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Page Content -->
            <div class="p-6">
                <?php if (isset($error)): ?>
                <div class="bg-red-100 border-l-4 border-red-500 text-red-700 p-4 mb-4">
                    <p><?php echo $error; ?></p>
                </div>
                <?php endif; ?>
                
                <?php if (isset($success)): ?>
                <div class="bg-green-100 border-l-4 border-green-500 text-green-700 p-4 mb-4">
                    <p><?php echo $success; ?></p>
                </div>
                <?php endif; ?> 