<?php
$servername = 'localhost';
$username = 'root';
$password = '';
$dbname = 'asset_manager';

$conn = @mysqli_connect($servername, $username, $password, $dbname, 3306);
if (!$conn) {
    $conn = @mysqli_connect($servername, $username, $password, $dbname, 3307);
}

if (!$conn) {
    die('Connection failed: ' . mysqli_connect_error());
}
?>