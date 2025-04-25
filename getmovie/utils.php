<?php
/**
 * Chuẩn hóa slug cho URL
 * 
 * @param string $text Text cần chuẩn hóa
 * @return string Slug đã chuẩn hóa
 */
function normalizeSlug($text) {
    // Chuyển đổi ký tự đặc biệt thành ký tự thường
    $text = mb_strtolower($text, 'UTF-8');
    
    // Thay thế khoảng trắng bằng dấu gạch ngang
    $text = preg_replace('/\s+/', '-', $text);
    
    // Giữ lại các ký tự đặc biệt tiếng Việt
    $text = preg_replace('/[^a-z0-9\-àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/', '', $text);
    
    // Loại bỏ dấu gạch ngang liên tiếp
    $text = preg_replace('/-+/', '-', $text);
    
    // Loại bỏ dấu gạch ngang ở đầu và cuối
    $text = trim($text, '-');
    
    return $text;
}

/**
 * Chuyển đổi định dạng thời gian thành số phút
 * 
 * @param string $time Thời gian cần chuyển đổi
 * @return int Số phút
 */
function convertTimeToMinutes($time) {
    if (empty($time)) return 0;
    
    // Handle "XX phút" format
    if (preg_match('/^(\d+)\s*phút$/i', $time, $matches)) {
        return (int)$matches[1];
    }
    
    // Handle "XX Phút/Tập" format
    if (preg_match('/^(\d+)\s*Phút\/Tập$/i', $time, $matches)) {
        return (int)$matches[1];
    }
    
    // Handle "XX:XX" format
    if (preg_match('/^(\d+):(\d+)$/', $time, $matches)) {
        return (int)$matches[1] * 60 + (int)$matches[2];
    }
    
    return 0;
} 