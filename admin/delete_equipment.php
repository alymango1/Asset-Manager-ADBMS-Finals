<?php
session_start();
require_once '../database/db.php';

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: equipments.php");
    exit();
}

// CSRF check
if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: equipments.php");
    exit();
}

$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if (!$id) {
    header("Location: equipments.php");
    exit();
}

// Check equipment exists
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT equipment_id, resource_name FROM equipments WHERE equipment_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "Equipment not found.";
    header("Location: equipments.php");
    exit();
}

// Block delete with active reservations
$activeRes = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT reservation_id FROM reservations
     WHERE equipment_id = $id AND status IN ('pending','approved')
     LIMIT 1"
));
if ($activeRes) {
    $_SESSION['error'] = "Cannot delete \"" . htmlspecialchars($check['resource_name']) . "\": it has an active reservation. Resolve or reject the reservation first.";
    header("Location: equipments.php");
    exit();
}

// Delete equipment
$delete = mysqli_query($conn, "DELETE FROM equipments WHERE equipment_id = $id");

if ($delete) {
    // Refresh token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['success'] = "\"" . htmlspecialchars($check['resource_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete equipment: " . mysqli_error($conn);
}

header("Location: equipments.php");
exit();
?>