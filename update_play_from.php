<?php
require_once 'config.php';
$conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($conn->connect_error) {
    die('Connection failed: ' . $conn->connect_error);
}

$sql = "UPDATE mac_vod SET vod_play_from = 'ngm3u8'";
$result = $conn->query($sql);

if ($result) {
    echo "Successfully updated " . $conn->affected_rows . " records.";
} else {
    echo "Error updating records: " . $conn->error;
}

$conn->close();
?> 