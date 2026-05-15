<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: reservation.php");
    exit();
}

// make sure the request is legit
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

// get the reservation info
$res_stmt = mysqli_prepare($conn, "
    SELECT r.equipment_id, r.status, e.resource_name, e.status AS equipment_status
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.reservation_id = ?
");
mysqli_stmt_bind_param($res_stmt, 'i', $reservation_id);
mysqli_stmt_execute($res_stmt);
$res = mysqli_fetch_assoc(mysqli_stmt_get_result($res_stmt));
mysqli_stmt_close($res_stmt);

if (!$res || $res['status'] !== 'pending') {
    $_SESSION['error'] = "Reservation not found or already processed.";
    header("Location: reservation.php");
    exit();
}

$equipment_id = (int) $res['equipment_id'];

mysqli_begin_transaction($conn);
try {
    // mark as rejected
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
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    if ($affected === 0) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Reservation was already processed.";
        header("Location: reservation.php");
        exit();
    }

    // log it
    $log_remarks = "Rejected reservation #$reservation_id"
                 . ($remarks !== '' ? " — Reason: $remarks" : '');
    $log_stmt = mysqli_prepare($conn, "
        INSERT INTO equipment_transactions
            (action_type, reservation_id, equipment_id, performed_by,
             status_from, status_to, action_date, remarks)
        VALUES ('reservation_rejected', ?, ?, ?, 'pending', 'rejected', NOW(), ?)
    ");
    mysqli_stmt_bind_param($log_stmt, 'iiis',
        $reservation_id, $equipment_id, $admin_id, $log_remarks);
    mysqli_stmt_execute($log_stmt);
    mysqli_stmt_close($log_stmt);

    // end log

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['error'] = "Rejection failed due to a database error. Please try again.";
    header("Location: reservation.php");
    exit();
}

// give a new csrf token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_SESSION['success'] = "Reservation #$reservation_id rejected.";
header("Location: reservation.php");
exit();