<?php
// db_connection.php
// Centralized database connection file

function get_db_connection() {
    $servername = "localhost";
    $username = "u654847199_Sarfas2004";
    $password = "Sarfas@2004";
    $dbname = "u654847199_mahal";

    // Create connection
    $conn = new mysqli($servername, $username, $password, $dbname);

    // Check connection
    if ($conn->connect_error) {
        return ['error' => "Connection failed: " . $conn->connect_error];
    }

    // Set charset to utf8mb4 for proper Unicode support
    $conn->set_charset('utf8mb4');

    return ['conn' => $conn];
}
?>
