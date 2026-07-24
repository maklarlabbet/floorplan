<?php
require_once __DIR__ . '/../config/config.php';

function get_db() {
    static $mysqli = null;
    if ($mysqli === null) {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        try {
            $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
            $mysqli->set_charset('utf8mb4');
        } catch (mysqli_sql_exception $e) {
            http_response_code(500);
            die('Database connection failed. Check config/config.php credentials. (' . htmlspecialchars($e->getMessage()) . ')');
        }
    }
    return $mysqli;
}
