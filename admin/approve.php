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
if (!$reservation_id) {
    header("Location: reservation.php");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];
$today    = date('Y-m-d');

// get the reservation
$get_stmt = mysqli_prepare($conn, "
    SELECT r.*, e.status AS equipment_status, e.resource_name
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.reservation_id = ?
");
mysqli_stmt_bind_param($get_stmt, 'i', $reservation_id);
mysqli_stmt_execute($get_stmt);
$get_result = mysqli_stmt_get_result($get_stmt);
mysqli_stmt_close($get_stmt);

if (!$get_result || mysqli_num_rows($get_result) === 0) {
    $_SESSION['error'] = "Reservation not found.";
    header("Location: reservation.php");
    exit();
}

$res          = mysqli_fetch_assoc($get_result);
$equipment_id = (int) $res['equipment_id'];

// skip if already handled
if ($res['status'] !== 'pending') {
    $_SESSION['error'] = "This reservation has already been processed.";
    header("Location: reservation.php");
    exit();
}

// skip if date already passed
if ($res['reserved_date'] < $today) {
    $equip_name   = htmlspecialchars($res['resource_name']);
    $reservedDate = htmlspecialchars($res['reserved_date']);
    $_SESSION['error'] = "Cannot approve: the reserved date ($reservedDate) for \"$equip_name\" is in the past. Please reject this outdated request instead.";
    header("Location: reservation.php");
    exit();
}

// skip if equipment isn't usable
if (!in_array($res['equipment_status'], ['Available', 'Reserved'])) {
    $equip_name = htmlspecialchars($res['resource_name']);
    $equip_stat = htmlspecialchars($res['equipment_status']);
    $_SESSION['error'] = "Cannot approve: \"$equip_name\" is currently $equip_stat. Reject this request or wait until the equipment is returned.";
    header("Location: reservation.php");
    exit();
}

// start db transaction
mysqli_begin_transaction($conn);
try {
    // lock the row so no one else grabs it
    $lock_stmt = mysqli_prepare($conn, "
        SELECT r.status, r.reserved_date, r.equipment_id,
               e.status AS equipment_status
        FROM reservations r
        JOIN equipments   e ON r.equipment_id = e.equipment_id
        WHERE r.reservation_id = ?
        FOR UPDATE
    ");
    mysqli_stmt_bind_param($lock_stmt, 'i', $reservation_id);
    mysqli_stmt_execute($lock_stmt);
    $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock_stmt));
    mysqli_stmt_close($lock_stmt);

    // double check it's still pending
    if (!$locked || $locked['status'] !== 'pending') {
        mysqli_rollback($conn);
        $_SESSION['error'] = "This reservation has already been processed by another admin.";
        header("Location: reservation.php");
        exit();
    }

    // make sure no overlap with other approved ones
    $conflict_stmt = mysqli_prepare($conn, "
        SELECT reservation_id FROM reservations
        WHERE equipment_id   = ?
        AND status         = 'approved'
        AND reserved_date  = ?
        AND reservation_id != ?
        AND NOT (reserved_end <= ? OR reserved_start >= ?)
    ");
    mysqli_stmt_bind_param($conflict_stmt, 'isiss',
        $equipment_id,
        $locked['reserved_date'],
        $reservation_id,
        $locked['reserved_start'],
        $locked['reserved_end']
    );
    mysqli_stmt_execute($conflict_stmt);
    $conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict_stmt));
    mysqli_stmt_close($conflict_stmt);

    if ($conflict) {
        mysqli_rollback($conn);
        $equip_name   = htmlspecialchars($res['resource_name']);
        $reservedDate = htmlspecialchars($locked['reserved_date']);
        $_SESSION['error'] = "Cannot approve: \"$equip_name\" already has an approved reservation on $reservedDate.";
        header("Location: reservation.php");
        exit();
    }

    $now = date('Y-m-d H:i:s');
    $newEquipmentStatus = 'In-Use';

    // mark reservation as approved
    $upd_res = mysqli_prepare($conn, "
        UPDATE reservations
        SET status      = 'approved',
            approved_by = ?,
            approved_at = NOW()
        WHERE reservation_id = ?
    ");
    mysqli_stmt_bind_param($upd_res, 'ii', $admin_id, $reservation_id);
    mysqli_stmt_execute($upd_res);
    mysqli_stmt_close($upd_res);

    // mark equipment as in use
    $upd_eq = mysqli_prepare($conn, "UPDATE equipments SET status = ? WHERE equipment_id = ?");
    mysqli_stmt_bind_param($upd_eq, 'si', $newEquipmentStatus, $equipment_id);
    mysqli_stmt_execute($upd_eq);
    mysqli_stmt_close($upd_eq);

    // save a log entry
    $log_remarks = "Approved reservation #$reservation_id";
    $ins = mysqli_prepare($conn, "
        INSERT INTO equipment_transactions
            (equipment_id, performed_by, status_from, status_to, action_date, remarks)
        VALUES (?, ?, ?, ?, CURDATE(), ?)
    ");
    mysqli_stmt_bind_param($ins, 'iisss', $equipment_id, $admin_id, $locked['equipment_status'], $newEquipmentStatus, $log_remarks);
    mysqli_stmt_execute($ins);
    mysqli_stmt_close($ins);

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['error'] = "Approval failed due to a database error. Please try again.";
    header("Location: reservation.php");
    exit();
}

// give a new csrf token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_SESSION['success'] = "Reservation #$reservation_id approved successfully.";
header("Location: reservation.php");
exit();