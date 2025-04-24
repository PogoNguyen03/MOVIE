<?php
/**
 * Lấy danh sách liên kết từ database
 */
function getFooterLinks() {
    global $GLOBALS;
    
    $links = [];
    
    try {
        $dsn = 'mysql:host=' . $GLOBALS['config']['db']['server'] . ';dbname=' . $GLOBALS['config']['db']['name'];
        $pdo = new PDO($dsn, $GLOBALS['config']['db']['user'], $GLOBALS['config']['db']['pass']);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $stmt = $pdo->query("SELECT * FROM {$GLOBALS['config']['db']['prefix']}links WHERE status = 1 ORDER BY sort_order ASC, id ASC");
        $links = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // Xử lý lỗi
        error_log('Lỗi khi lấy liên kết: ' . $e->getMessage());
    }
    
    return $links;
}
?> 