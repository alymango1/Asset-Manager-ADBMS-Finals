<?php
session_start();
require_once '../database/db.php';

// Auth guard — only admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$id) {
    header("Location: equipments.php");
    exit();
}

// Make sure the equipment exists
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT equipment_id, resource_name FROM equipments WHERE equipment_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "Equipment not found.";
    header("Location: equipments.php");
    exit();
}

// Delete the equipment (CASCADE will remove linked equipment_transactions)
$delete = mysqli_query($conn, "DELETE FROM equipments WHERE equipment_id = $id");

if ($delete) {
    $_SESSION['success'] = "\"" . htmlspecialchars($check['resource_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete equipment: " . mysqli_error($conn);
}

header("Location: equipments.php");
exit();
?>