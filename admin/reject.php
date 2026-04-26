<?php
include('../database/db.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$reservation_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
$remarks        = isset($_POST['remarks']) ? trim(mysqli_real_escape_string($conn, $_POST['remarks'])) : '';
$admin_id       = (int) $_SESSION['user_id'];

if (!$reservation_id) {
    header("Location: reservation.php");
    exit();
}

mysqli_query($conn, "
    UPDATE reservations
    SET status      = 'rejected',
        rejected_by = $admin_id,
        rejected_at = NOW(),
        remarks     = '$remarks'
    WHERE reservation_id = $reservation_id
      AND status         = 'pending'
");

$_SESSION['success'] = "Reservation rejected.";
header("Location: reservation.php");
exit();
?>