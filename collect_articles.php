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

// Hàm lấy nội dung trang web bằng cURL
function getUrlContent($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
    $html = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode !== 200) {
        return false;
    }
    return $html;
}

// Xử lý thu thập bài viết
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['collect'])) {
    $start_page = isset($_POST['start_page']) ? (int)$_POST['start_page'] : 1;
    $end_page = isset($_POST['end_page']) ? (int)$_POST['end_page'] : 1;
    $news_type = isset($_POST['news_type']) ? $_POST['news_type'] : 'movie';
    
    if ($start_page > $end_page) {
        $_SESSION['error'] = "Trang bắt đầu phải nhỏ hơn hoặc bằng trang kết thúc!";
    } else {
        $success_count = 0;
        $error_count = 0;
        $error_details = [];
        
        // Xác định URL và type_id dựa vào loại tin
        if ($news_type === 'movie') {
            $base_url = "https://vnexpress.net/giai-tri/phim-p";
            $type_id = 6;
            $type_id_1 = 5;
        } else {
            $base_url = "https://vnexpress.net/giai-tri/gioi-sao-p";
            $type_id = 7;
            $type_id_1 = 5;
        }
        
        for ($page = $start_page; $page <= $end_page; $page++) {
            $url = $base_url . $page;
            
            // Lấy nội dung trang
            $html = getUrlContent($url);
            if ($html === false) {
                $error_count++;
                $error_details[] = "Không thể truy cập trang $url";
                continue;
            }
            
            // Tạo DOMDocument
            $dom = new DOMDocument();
            @$dom->loadHTML($html, LIBXML_NOERROR);
            $xpath = new DOMXPath($dom);
            
            // Lấy danh sách bài viết
            $articles = $xpath->query("//article[contains(@class, 'item-news')]");
            
            if ($articles->length === 0) {
                $error_count++;
                $error_details[] = "Không tìm thấy bài viết nào trên trang $url";
                continue;
            }
            
            foreach ($articles as $article) {
                try {
                    
                    // Lấy tiêu đề
                    $titleNode = $xpath->query(".//h2[contains(@class, 'title-news')]/a", $article)->item(0);
                    if (!$titleNode) {
                        $error_details[] = "Không tìm thấy tiêu đề bài viết";
                        continue;
                    }
                    $title = trim($titleNode->textContent);
                    $link = $titleNode->getAttribute('href');
                    
                    // Lấy mô tả
                    $descNode = $xpath->query(".//p[contains(@class, 'description')]/a", $article)->item(0);
                    $description = $descNode ? trim($descNode->textContent) : '';
                    
                    // Lấy ảnh
                    $imgNode = $xpath->query(".//div[contains(@class, 'thumb-art')]//picture//img", $article)->item(0);
                    $image = '';
                    $image_slide = '';
                    $image_thumb = '';
                    if ($imgNode) {
                        // Lấy URL hình ảnh từ thuộc tính data-src
                        $image = $imgNode->getAttribute('data-src');
                        
                        // Nếu không có data-src, thử lấy từ source tag
                        if (!$image) {
                            $sourceNode = $xpath->query(".//div[contains(@class, 'thumb-art')]//picture//source", $article)->item(0);
                            if ($sourceNode) {
                                $srcset = $sourceNode->getAttribute('srcset');
                                if ($srcset) {
                                    // Lấy URL đầu tiên từ srcset (1x resolution)
                                    if (preg_match('/^([^\s]+)/', $srcset, $matches)) {
                                        $image = $matches[1];
                                    }
                                }
                            }
                        }
                        
                        // Thêm domain nếu là URL tương đối
                        if ($image && strpos($image, 'http') !== 0) {
                            $image = 'https://vnexpress.net' . $image;
                        }
                        
                        // Chuyển đổi URL hình ảnh sang định dạng phù hợp
                        if ($image) {
                            // Tách URL gốc và các tham số
                            $urlParts = explode('?', $image);
                            $baseUrl = $urlParts[0];
                            
                            // Tạo URL cho ảnh slide (kích thước lớn hơn)
                            $image_slide = $baseUrl . '?w=660&h=360&q=100&dpr=1&fit=crop';
                            
                            // Tạo URL cho ảnh thumb (kích thước nhỏ hơn)
                            $image_thumb = $baseUrl . '?w=180&h=108&q=100&dpr=1&fit=crop';
                            
                            // Giữ nguyên URL gốc với các tham số
                            $image = $urlParts[0] . (isset($urlParts[1]) ? '?' . $urlParts[1] : '');
                        }
                    }
                    
                    // Lấy nội dung chi tiết
                    $detailHtml = getUrlContent($link);
                    if ($detailHtml === false) {
                        $error_details[] = "Không thể truy cập bài viết: $link";
                        continue;
                    }
                    
                    $detailDom = new DOMDocument();
                    @$detailDom->loadHTML($detailHtml, LIBXML_NOERROR);
                    $detailXpath = new DOMXPath($detailDom);
                    
                    // Lấy nội dung chính
                    $contentNode = $detailXpath->query("//article[contains(@class, 'fck_detail')]")->item(0);
                    if (!$contentNode) {
                        $error_details[] = "Không tìm thấy nội dung bài viết: $link";
                        continue;
                    }
                    
                    // Xử lý nội dung
                    $content = '';
                    
                    // Thêm container cho nội dung
                    $content .= '<div class="article-content">';
                    
                    // Xử lý từng phần tử trong nội dung
                    foreach ($contentNode->childNodes as $node) {
                        if ($node->nodeType === XML_ELEMENT_NODE) {
                            $nodeName = strtolower($node->nodeName);
                            
                            // Xử lý hình ảnh
                            if ($nodeName === 'figure') {
                                $imgNode = $detailXpath->query(".//img", $node)->item(0);
                                if ($imgNode) {
                                    $imgSrc = $imgNode->getAttribute('data-src');
                                    if (!$imgSrc) {
                                        $imgSrc = $imgNode->getAttribute('src');
                                    }
                                    
                                    // Lấy caption nếu có
                                    $figcaption = $detailXpath->query(".//figcaption", $node)->item(0);
                                    $caption = $figcaption ? $figcaption->textContent : '';
                                    
                                    if ($imgSrc) {
                                        $content .= '<figure class="image-container">';
                                        $content .= '<img src="' . $imgSrc . '" alt="' . htmlspecialchars($caption) . '">';
                                        if ($caption) {
                                            $content .= '<figcaption>' . htmlspecialchars($caption) . '</figcaption>';
                                        }
                                        $content .= '</figure>';
                                    }
                                }
                            }
                            // Xử lý video
                            else if ($nodeName === 'div' && strpos($node->getAttribute('class'), 'video_player') !== false) {
                                $content .= '<div class="video-container">';
                                $content .= $detailDom->saveHTML($node);
                                $content .= '</div>';
                            }
                            // Xử lý đoạn văn bản
                            else if ($nodeName === 'p') {
                                $paragraphContent = trim($node->textContent);
                                if (!empty($paragraphContent)) {
                                    $content .= '<p>' . htmlspecialchars($paragraphContent) . '</p>';
                                }
                            }
                            // Các phần tử khác
                            else {
                                $content .= $detailDom->saveHTML($node);
                            }
                        }
                    }
                    
                    // Đóng container
                    $content .= '</div>';
                    
                    // Thêm thông tin bản quyền và nguồn
                    $copyright = '<div class="copyright-info">
                        <p>
                            <strong>Nguồn:</strong> <a href="' . $link . '" target="_blank">VnExpress</a><br>
                            <strong>Bản quyền:</strong> © ' . date('Y') . ' VnExpress. Tất cả quyền được bảo lưu.
                        </p>
                    </div>';
                    $content .= $copyright;
                    
                    // Kiểm tra xem bài viết đã tồn tại chưa
                    $stmt = $pdo->prepare("SELECT COUNT(*) FROM mac_art WHERE art_name = ?");
                    $stmt->execute([$title]);
                    if ($stmt->fetchColumn() > 0) {
                        $error_details[] = "Bài viết đã tồn tại: $title";
                        continue;
                    }
                    
                    // Thêm vào database
                    $stmt = $pdo->prepare("INSERT INTO mac_art (type_id, art_name, art_title, art_note, art_content, art_blurb, art_pic, art_pic_screenshot, art_pic_slide, art_pic_thumb, art_time_add, art_time, art_status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $time = time();
                    $stmt->execute([$type_id, $title, $title, '', $content, $description, $image, $image, $image_slide, $image_thumb, $time, $time, 1]);
                    
                    $success_count++;
                } catch (Exception $e) {
                    $error_count++;
                    $error_details[] = "Lỗi xử lý bài viết: " . $e->getMessage();
                }
            }
        }
        
        $_SESSION['success'] = "Thu thập thành công $success_count bài viết. Có $error_count lỗi xảy ra.";
        if (!empty($error_details)) {
            $_SESSION['error_details'] = $error_details;
        }
    }
    
    header("Location: collect_articles.php");
    exit;
}

$page_title = "Thu thập bài viết";
require_once 'layout.php';
?>

<div class="container mx-auto px-4 py-8">
    <div class="flex justify-between items-center mb-6">
        <h1 class="text-2xl font-bold">Thu thập bài viết</h1>
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

    <?php if (isset($_SESSION['error_details'])): ?>
        <div class="bg-yellow-100 border border-yellow-400 text-yellow-700 px-4 py-3 rounded relative mb-4" role="alert">
            <h3 class="font-bold mb-2">Chi tiết lỗi:</h3>
            <ul class="list-disc list-inside">
                <?php foreach ($_SESSION['error_details'] as $detail): ?>
                    <li><?php echo htmlspecialchars($detail); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php unset($_SESSION['error_details']); ?>
    <?php endif; ?>

    <div class="bg-white shadow-md rounded-lg p-6">
        <form method="POST" class="space-y-4">
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="news_type">Loại tin tức</label>
                <select name="news_type" id="news_type" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
                    <option value="movie">Tin tức phim (type_id: 6)</option>
                    <option value="celebrity">Tin tức sao (type_id: 7)</option>
                </select>
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="start_page">Trang bắt đầu</label>
                <input type="number" name="start_page" id="start_page" min="1" value="1" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            
            <div>
                <label class="block text-gray-700 text-sm font-bold mb-2" for="end_page">Trang kết thúc</label>
                <input type="number" name="end_page" id="end_page" min="1" value="1" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 leading-tight focus:outline-none focus:shadow-outline" required>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" name="collect" class="bg-blue-500 text-white px-4 py-2 rounded hover:bg-blue-600">
                    <i class="fas fa-download mr-2"></i>Thu thập
                </button>
            </div>
        </form>
    </div>
</div> 