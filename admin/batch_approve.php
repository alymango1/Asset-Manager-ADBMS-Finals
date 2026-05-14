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

$batch_id = isset($_POST['batch_id']) ? trim($_POST['batch_id']) : '';
if (empty($batch_id)) {
    $_SESSION['error'] = "No batch specified.";
    header("Location: reservation.php");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];
$today    = date('Y-m-d');
$now      = date('Y-m-d H:i:s');

// Fetch all pending reservations in this batch
$fetch = mysqli_prepare($conn, "
    SELECT r.reservation_id, r.equipment_id, r.reserved_date,
           r.reserved_start, r.reserved_end,
           e.status AS equipment_status, e.resource_name
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.batch_id = ? AND r.status = 'pending'
    FOR UPDATE
");
// Note: FOR UPDATE not valid in SELECT outside transaction, handled below per-row

// Safer: fetch first, then lock inside transaction
$fetch_stmt = mysqli_prepare($conn, "
    SELECT r.reservation_id, r.equipment_id, r.reserved_date,
           r.reserved_start, r.reserved_end,
           e.status AS equipment_status, e.resource_name
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.batch_id = ? AND r.status = 'pending'
");
mysqli_stmt_bind_param($fetch_stmt, 's', $batch_id);
mysqli_stmt_execute($fetch_stmt);
$fetch_result = mysqli_stmt_get_result($fetch_stmt);
$pending_rows = [];
while ($row = mysqli_fetch_assoc($fetch_result)) {
    $pending_rows[] = $row;
}
mysqli_stmt_close($fetch_stmt);

if (empty($pending_rows)) {
    $_SESSION['error'] = "No pending items found in this batch (they may have already been processed).";
    header("Location: reservation.php");
    exit();
}

$approved_count = 0;
$skipped        = [];
$errors         = [];

mysqli_begin_transaction($conn);
try {
    foreach ($pending_rows as $res) {
        $reservation_id = (int) $res['reservation_id'];
        $equipment_id   = (int) $res['equipment_id'];

        // Check past date
        if ($res['reserved_date'] < $today) {
            $skipped[] = htmlspecialchars($res['resource_name']) . " (date in the past — please reject instead)";
            continue;
        }

        // Check equipment availability
        if (!in_array($res['equipment_status'], ['Available', 'Reserved'])) {
            $skipped[] = htmlspecialchars($res['resource_name']) . " (equipment is " . htmlspecialchars($res['equipment_status']) . ")";
            continue;
        }

       $lock_stmt = mysqli_prepare($conn, "
            SELECT r.status, r.reserved_date, r.equipment_id,
                r.reserved_start, r.reserved_end,
                e.status AS equipment_status
            FROM reservations r
            JOIN equipments e ON r.equipment_id = e.equipment_id
            WHERE r.reservation_id = ?
            FOR UPDATE
        ");
        mysqli_stmt_bind_param($lock_stmt, 'i', $reservation_id);
        mysqli_stmt_execute($lock_stmt);
        $locked = mysqli_fetch_assoc(mysqli_stmt_get_result($lock_stmt));
        mysqli_stmt_close($lock_stmt);

        if (!$locked || $locked['status'] !== 'pending') {
            $skipped[] = htmlspecialchars($res['resource_name']) . " (already processed)";
            continue;
        }

        // Check scheduling conflict
        $conflict_stmt = mysqli_prepare($conn, "
            SELECT reservation_id FROM reservations
            WHERE equipment_id   = ?
              AND status         = 'approved'
              AND reserved_date  = ?
              AND reservation_id != ?
              AND NOT (reserved_end <= ? OR reserved_start >= ?)
        ");
        mysqli_stmt_bind_param($conflict_stmt, 'isiss',
            $equipment_id, $locked['reserved_date'], $reservation_id,
            $res['reserved_start'], $res['reserved_end']
        );
        mysqli_stmt_execute($conflict_stmt);
        $conflict = mysqli_fetch_assoc(mysqli_stmt_get_result($conflict_stmt));
        mysqli_stmt_close($conflict_stmt);

        if ($conflict) {
            $skipped[] = htmlspecialchars($res['resource_name']) . " (time conflict with an existing approval)";
            continue;
        }

        // Determine equipment status
        // ✅ FIXED
        $newEquipStatus = (
                $locked['reserved_date'] === $today &&
                !empty($locked['reserved_start']) &&
                $now >= $locked['reserved_start'] &&
                $now < $locked['reserved_end']
            ) ? 'In-Use' : 'Reserved';

        // Approve reservation
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

        // Update equipment
        $upd_eq = mysqli_prepare($conn, "UPDATE equipments SET status = ? WHERE equipment_id = ?");
        mysqli_stmt_bind_param($upd_eq, 'si', $newEquipStatus, $equipment_id);
        mysqli_stmt_execute($upd_eq);
        mysqli_stmt_close($upd_eq);

        // Log transaction
        $log_remarks = "Batch approved reservation #$reservation_id (batch: $batch_id)";
        $ins = mysqli_prepare($conn, "
            INSERT INTO equipment_transactions
                (equipment_id, performed_by, status_from, status_to, action_date, remarks)
            VALUES (?, ?, ?, ?, CURDATE(), ?)
        ");
        mysqli_stmt_bind_param($ins, 'iisss',
            $equipment_id, $admin_id,
            $locked['equipment_status'], $newEquipStatus,
            $log_remarks
        );
        mysqli_stmt_execute($ins);
        mysqli_stmt_close($ins);

        $approved_count++;
    }

    mysqli_commit($conn);

} catch (Exception $e) {
    mysqli_rollback($conn);
    $_SESSION['error'] = "Batch approval failed due to a database error. Please try again.";
    header("Location: reservation.php");
    exit();
}

// Refresh CSRF token
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));

// Build result message
if ($approved_count > 0 && empty($skipped)) {
    $_SESSION['success'] = "All $approved_count item" . ($approved_count !== 1 ? 's' : '') . " in the batch were approved successfully.";
} elseif ($approved_count > 0 && !empty($skipped)) {
    $skipList = implode('; ', $skipped);
    $_SESSION['success'] = "$approved_count item" . ($approved_count !== 1 ? 's' : '') . " approved. Skipped: $skipList.";
} else {
    $_SESSION['error'] = "No items could be approved. Skipped: " . implode('; ', $skipped);
}

header("Location: reservation.php");
exit();
