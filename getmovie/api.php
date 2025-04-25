<?php
require_once __DIR__ . '/config.php';

/**
 * Thực hiện HTTP request với cURL, có retry khi gặp lỗi
 * 
 * @param string $url URL cần gọi
 * @param array $headers Headers bổ sung
 * @param int $retries Số lần thử lại
 * @param int $delay Thời gian chờ giữa các lần thử
 * @return array Response data hoặc error
 */
function makeRequest($url, $headers = [], $retries = DEFAULT_RETRIES, $delay = DEFAULT_DELAY) {
    $attempt = 0;
    $maxTimeout = 30; // Timeout ban đầu
    
    do {
        // Tăng timeout theo số lần thử
        $currentTimeout = $maxTimeout * ($attempt + 1);
        
        // Add delay between retries với backoff
        if ($attempt > 0) {
            $sleepTime = $delay * pow(2, $attempt - 1); // Exponential backoff
            sleep($sleepTime);
            error_log("Retry attempt $attempt for URL: $url with timeout $currentTimeout seconds");
        }
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, $currentTimeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36');
        curl_setopt($ch, CURLOPT_ENCODING, ''); // Chấp nhận mọi encoding
        curl_setopt($ch, CURLOPT_HTTPHEADER, array_merge([
            'Accept: application/json',
            'Connection: keep-alive',
            'Cache-Control: no-cache'
        ], $headers));
        
        // Thêm proxy nếu cần
        // curl_setopt($ch, CURLOPT_PROXY, "proxy.example.com:8080");
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $info = curl_getinfo($ch);
        curl_close($ch);
        
        // Log thông tin request
        error_log(sprintf(
            "Request to %s - HTTP Code: %d, Total time: %.2fs, Error: %s",
            $url,
            $httpCode,
            $info['total_time'],
            $error
        ));
            
        // Kiểm tra các loại lỗi cụ thể
        if ($httpCode === 200) {
            return ['data' => $response];
        }
        
        // Xử lý các mã lỗi cụ thể
        if (in_array($httpCode, [524, 522, 504, 503, 429])) {
            // Cloudflare timeout (524), Connection timed out (522),
            // Gateway timeout (504), Service unavailable (503),
            // Too many requests (429)
            if ($attempt < $retries) {
                error_log("Encountered error $httpCode, will retry after delay");
                continue;
            }
        }
        
        if ($error) {
            return ['error' => "Lỗi cURL: " . $error];
        }
        
        return ['error' => "Lỗi HTTP: " . $httpCode . " - URL: " . $url];
        
    } while ($attempt++ < $retries);
    
    return ['error' => "Đã thử lại $retries lần nhưng không thành công. URL: " . $url];
}

/**
 * Lấy thông tin chi tiết của phim từ API
 * 
 * @param string $slug Slug của phim
 * @param int $maxRetries Số lần thử lại tối đa
 * @return array|null Thông tin chi tiết của phim hoặc null nếu có lỗi
 */
function fetchMovieDetails($slug, $maxRetries = DEFAULT_RETRIES) {
    // Chuẩn hóa slug trước khi gọi API
    $slug = normalizeSlug($slug);
    $url = API_BASE_URL . "/film/" . $slug;
    
    // Log URL để debug
    error_log("Fetching movie details from URL: " . $url);
    
    $response = makeRequest($url, [], $maxRetries, DEFAULT_DELAY);
    
    if (isset($response['data'])) {
        $data = json_decode($response['data'], true);
        if (json_last_error() === JSON_ERROR_NONE && isset($data['movie'])) {
            return $data['movie'];
        } else {
            // Log lỗi JSON
            error_log("JSON decode error: " . json_last_error_msg() . " for movie: " . $slug);
            error_log("Response data: " . substr($response['data'], 0, 1000)); // Log 1000 ký tự đầu tiên
            return null;
        }
    } else {
        // Log lỗi API
        error_log("API error for movie: " . $slug . " - " . ($response['error'] ?? 'Unknown error'));
        
        // Nếu lỗi là HTTP 429 hoặc 524, ghi log chi tiết hơn
        if (strpos(($response['error'] ?? ''), '429') !== false) {
            error_log("Rate limit exceeded (HTTP 429). Consider increasing delay between requests.");
        } elseif (strpos(($response['error'] ?? ''), '524') !== false) {
            error_log("Cloudflare timeout (HTTP 524). Server took too long to respond.");
        }
        
        return null;
    }
} 