<?php
require_once __DIR__ . '/config.php';

function get_db(): mysqli
{
    static $conn = null;
    if ($conn === null) {
        $conn = mysqli_connect(DB_HOST, DB_APP_USER, DB_APP_PASSWORD, DB_NAME);
        if (!$conn) {
            http_response_code(500);
            exit('No se pudo conectar a la base de datos.');
        }
    }
    return $conn;
}
