<?php
// config/db.php — Database connection with timezone synchronization
// ============================================================

define('DB_HOST', 'localhost');
define('DB_USER', 'root');       
define('DB_PASS', '');         
define('DB_NAME', 'examination_system');
define('DB_CHARSET', 'utf8mb4');

// Set your timezone here (Kenya time)
define('APP_TIMEZONE', 'Africa/Nairobi');

// Set PHP timezone globally
date_default_timezone_set(APP_TIMEZONE);

function getDB(): mysqli {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log('Database connection failed: ' . $conn->connect_error);
            die(json_encode(['error' => 'Database connection failed. Please try again later.']));
        }

        $conn->set_charset(DB_CHARSET);
        
        // Synchronize MySQL timezone with PHP timezone
        // This ensures NOW(), CURRENT_TIMESTAMP, etc. in MySQL use the same time as PHP
        $offset = date('P'); // Get timezone offset in format +03:00
        $conn->query("SET time_zone = '$offset'");
    }

    return $conn;
}