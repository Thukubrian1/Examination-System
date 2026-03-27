<?php

define('DB_HOST', '');
define('DB_USER', '');       
define('DB_PASS', '');         
define('DB_NAME', 'examination_system');
define('DB_CHARSET', 'utf8mb4');


function getDB(): mysqli {
    static $conn = null;

    if ($conn === null) {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);

        if ($conn->connect_error) {
            error_log('Database connection failed: ' . $conn->connect_error);
            die(json_encode(['error' => 'Database connection failed. Please try again later.']));
        }

        $conn->set_charset(DB_CHARSET);
    }

    return $conn;
}