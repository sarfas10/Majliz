<?php
require_once __DIR__ . '/db_connection.php';
$db = get_db_connection();
if (isset($db['error']))
    die($db['error']);
$conn = $db['conn'];

$result = $conn->query("SHOW TABLES");
if ($result) {
    while ($row = $result->fetch_array()) {
        $table = $row[0];
        echo "Table: $table\n";
        $cols = $conn->query("SHOW COLUMNS FROM $table");
        while ($col = $cols->fetch_assoc()) {
            echo "  - " . $col['Field'] . " (" . $col['Type'] . ")\n";
        }
        echo "\n";
    }
} else {
    echo "Error: " . $conn->error;
}
?>