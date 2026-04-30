<?php
session_start();
require_once '../database/db.php';

// Admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin/login.php");
    exit();
}

// Require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/users.php");
    exit();
}

// CSRF check
if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: ../admin/users.php");
    exit();
}

// Read user ID
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if (!$id) {
    header("Location: ../admin/users.php");
    exit();
}

// Block self-delete
if ($id === (int) $_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot delete your own account.";
    header("Location: ../admin/users.php");
    exit();
}

// Check user exists
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT user_id, full_name FROM users WHERE user_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "User not found.";
    header("Location: ../admin/users.php");
    exit();
}

// Delete user
$delete = mysqli_query($conn, "DELETE FROM users WHERE user_id = $id");

if ($delete) {
    // Refresh token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['success'] = "\"" . htmlspecialchars($check['full_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete user: " . mysqli_error($conn);
}

header("Location: ../admin/users.php");
exit();
?>