<?php
// Load database configuration from application/database.php
$databaseConfig = require_once 'application/database.php';

// Database configuration
define('DB_HOST', $databaseConfig['hostname']);
define('DB_NAME', $databaseConfig['database']);
define('DB_USER', $databaseConfig['username']);
define('DB_PASS', $databaseConfig['password']);

// API configuration
define('API_BASE_URL', 'https://phim.nguonc.com/api');

// Other configuration
define('DEFAULT_LIMIT', 10);        // Default number of movies to import
define('MAX_BULK_DELETE', 50);      // Maximum number of movies that can be deleted at once

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Time zone
date_default_timezone_set('Asia/Ho_Chi_Minh');
?> 