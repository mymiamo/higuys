<?php
$mysqli = new mysqli("localhost", "mym34donet", "ed21c-2f1cef", "mym34donet_higuys");

if ($mysqli->connect_errno) {
    echo "MySQL bağlantı hatası: " . $mysqli->connect_error;
    exit();
}

$query = "DELETE FROM skips WHERE skipped_at < DATE_SUB(NOW(), INTERVAL 1 DAY)";
if ($mysqli->query($query)) {
    echo "Silme işlemi başarılı.\n";
} else {
    echo "Sorgu hatası: " . $mysqli->error;
}

$mysqli->close();
?>
