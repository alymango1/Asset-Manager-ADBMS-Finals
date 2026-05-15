<?php
session_start();
require_once '../database/db.php';

// admin only
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: ../admin/login.php");
    exit();
}

// only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: ../admin/users.php");
    exit();
}

// make sure the request is legit
if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: ../admin/users.php");
    exit();
}

// grab the user id from the form
$id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

if (!$id) {
    header("Location: ../admin/users.php");
    exit();
}

// don't let an admin delete themselves
if ($id === (int) $_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot delete your own account.";
    header("Location: ../admin/users.php");
    exit();
}

// make sure the user actually exists before we try anything
$check = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT user_id, full_name FROM users WHERE user_id = $id"
));

if (!$check) {
    $_SESSION['error'] = "User not found.";
    header("Location: ../admin/users.php");
    exit();
}

// go ahead and delete them
$delete = mysqli_query($conn, "DELETE FROM users WHERE user_id = $id");

if ($delete) {
    // give a new csrf token
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['success'] = "\"" . htmlspecialchars($check['full_name']) . "\" has been deleted.";
} else {
    $_SESSION['error'] = "Failed to delete user: " . mysqli_error($conn);
}

header("Location: ../admin/users.php");
exit();
?>