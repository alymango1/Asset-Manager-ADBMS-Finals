<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

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

$batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
$remarks  = isset($_POST['remarks'])  ? trim($_POST['remarks'])  : '';
$admin_id = (int) $_SESSION['user_id'];

if (empty($batch_id)) {
    $_SESSION['error'] = "No batch specified.";
    header("Location: reservation.php");
    exit();
}
if (empty($remarks)) {
    $_SESSION['error'] = "A reason is required to reject a batch.";
    header("Location: reservation.php");
    exit();
}

mysqli_begin_transaction($conn);
try {
    // get all pending items before we reject them
    $fetch_stmt = mysqli_prepare($conn, "
        SELECT reservation_id, equipment_id
        FROM reservations
        WHERE batch_id = ? AND status = 'pending'
    ");
    mysqli_stmt_bind_param($fetch_stmt, 's', $batch_id);
    mysqli_stmt_execute($fetch_stmt);
    $fetch_result = mysqli_stmt_get_result($fetch_stmt);
    $pending_items = [];
    while ($row = mysqli_fetch_assoc($fetch_result)) {
        $pending_items[] = $row;
    }
    mysqli_stmt_close($fetch_stmt);

    if (empty($pending_items)) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "No pending items found in this batch to reject.";
        header("Location: reservation.php");
        exit();
    }

    // mark all as rejected
    $stmt = mysqli_prepare($conn, "
        UPDATE reservations
        SET status      = 'rejected',
            rejected_by = ?,
            rejected_at = NOW(),
            remarks     = ?
        WHERE batch_id = ?
          AND status   = 'pending'
    ");
    mysqli_stmt_bind_param($stmt, 'iss', $admin_id, $remarks, $batch_id);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    // log each rejected item
    $log_stmt = mysqli_prepare($conn, "
        INSERT INTO equipment_transactions
            (action_type, reservation_id, equipment_id, performed_by,
             status_from, status_to, action_date, remarks)
        VALUES ('reservation_rejected', ?, ?, ?, 'pending', 'rejected', NOW(), ?)
    ");
    $log_remarks = "Batch rejected (batch: $batch_id) — Reason: $remarks";
    foreach ($pending_items as $item) {
        mysqli_stmt_bind_param($log_stmt, 'iiis',
            $item['reservation_id'], $item['equipment_id'], $admin_id, $log_remarks);
        mysqli_stmt_execute($log_stmt);
    }
    mysqli_stmt_close($log_stmt);

    // end log

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['error'] = "Batch rejection failed due to a database error. Please try again.";
    header("Location: reservation.php");
    exit();
}

// give a new csrf token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_SESSION['success'] = "Batch rejected — $affected item" . ($affected !== 1 ? 's' : '') . " rejected successfully.";
header("Location: reservation.php");
exit();