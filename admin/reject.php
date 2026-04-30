<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// Require POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: reservation.php");
    exit();
}

// CSRF check
if (
    empty($_SESSION['csrf_token']) ||
    !isset($_POST['csrf_token']) ||
    !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: reservation.php");
    exit();
}

$reservation_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$remarks        = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';
$admin_id       = (int) $_SESSION['user_id'];

if (!$reservation_id) {
    header("Location: reservation.php");
    exit();
}

$stmt = mysqli_prepare($conn, "
    UPDATE reservations
    SET status      = 'rejected',
        rejected_by = ?,
        rejected_at = NOW(),
        remarks     = ?
    WHERE reservation_id = ?
      AND status         = 'pending'
");
mysqli_stmt_bind_param($stmt, 'isi', $admin_id, $remarks, $reservation_id);
mysqli_stmt_execute($stmt);
mysqli_stmt_close($stmt);

// Refresh token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_SESSION['success'] = "Reservation rejected.";
header("Location: reservation.php");
exit();
?>