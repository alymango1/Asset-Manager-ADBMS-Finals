<?php
session_start();
require_once '../database/db.php';

// admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: equipments.php");
    exit();
}

// make sure the request is legit
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

// make sure it exists
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT equipment_id, resource_name FROM equipments WHERE equipment_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "Equipment not found.";
    header("Location: equipments.php");
    exit();
}

// don't delete if it's still being used
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

// delete it
$delete = mysqli_query($conn, "DELETE FROM equipments WHERE equipment_id = $id");

if ($delete) {
    // give a new csrf token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['success'] = "\"" . htmlspecialchars($check['resource_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete equipment: " . mysqli_error($conn);
}

header("Location: equipments.php");
exit();
?>