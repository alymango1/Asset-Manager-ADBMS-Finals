<?php
session_start();
require_once '../database/db.php';

// Auth guard — only admins
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin/login.php");
    exit();
}

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if (!$id) {
    header("Location: ../admin/users.php");
    exit();
}

// Prevent admin from deleting their own account
if ($id === (int) $_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot delete your own account.";
    header("Location: ../admin/users.php");
    exit();
}

// Make sure the user exists
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT user_id, full_name FROM users WHERE user_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "User not found.";
    header("Location: ../admin/users.php");
    exit();
}

// Delete the user
$delete = mysqli_query($conn, "DELETE FROM users WHERE user_id = $id");

if ($delete) {
    $_SESSION['success'] = "\"" . htmlspecialchars($check['full_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete user: " . mysqli_error($conn);
}

header("Location: ../admin/users.php");
exit();
?>