<?php
include('../database/db.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$reservation_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if (!$reservation_id) {
    header("Location: reservation.php");
    exit();
}

$admin_id = (int) $_SESSION['user_id'];
$today    = date('Y-m-d');

// 1. Get reservation details, including current equipment status
$get = mysqli_query($conn, "
    SELECT r.*, e.status AS equipment_status, e.resource_name
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.reservation_id = $reservation_id
");

if (!$get || mysqli_num_rows($get) === 0) {
    $_SESSION['error'] = "Reservation not found.";
    header("Location: reservation.php");
    exit();
}

$res          = mysqli_fetch_assoc($get);
$equipment_id = (int) $res['equipment_id'];

// 2. Guard: reservation must still be pending
if ($res['status'] !== 'pending') {
    $_SESSION['error'] = "This reservation has already been processed.";
    header("Location: reservation.php");
    exit();
}

// 3. Guard: reserved_date must not be in the past (issue #14 — server-side)
if ($res['reserved_date'] < $today) {
    $equip_name   = htmlspecialchars($res['resource_name']);
    $reservedDate = htmlspecialchars($res['reserved_date']);
    $_SESSION['error'] = "Cannot approve: the reserved date ($reservedDate) for \"$equip_name\" is in the past. Please reject this outdated request instead.";
    header("Location: reservation.php");
    exit();
}

// 4. Guard: equipment must be Available or Reserved (Reserved means approved for a future date)
if (!in_array($res['equipment_status'], ['Available', 'Reserved'])) {
    $equip_name = htmlspecialchars($res['resource_name']);
    $equip_stat = htmlspecialchars($res['equipment_status']);
    $_SESSION['error'] = "Cannot approve: \"$equip_name\" is currently $equip_stat. Reject this request or wait until the equipment is returned.";
    header("Location: reservation.php");
    exit();
}

// 4b. Guard: prevent approving if another reservation is already approved for the same date
$conflict = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT reservation_id FROM reservations
    WHERE equipment_id   = $equipment_id
      AND status         = 'approved'
      AND reserved_date  = '{$res['reserved_date']}'
      AND reservation_id != $reservation_id
"));
if ($conflict) {
    $equip_name   = htmlspecialchars($res['resource_name']);
    $reservedDate = htmlspecialchars($res['reserved_date']);
    $_SESSION['error'] = "Cannot approve: \"$equip_name\" already has an approved reservation on $reservedDate.";
    header("Location: reservation.php");
    exit();
}

// Determine new equipment status:
// - If the reservation date is today → In-Use (equipment is being taken right now)
// - If the reservation date is in the future → Reserved (approved but not yet picked up)
$newEquipmentStatus = ($res['reserved_date'] === $today) ? 'In-Use' : 'Reserved';

// 5. Update reservation status
mysqli_query($conn, "
    UPDATE reservations
    SET status      = 'approved',
        approved_by = $admin_id,
        approved_at = NOW()
    WHERE reservation_id = $reservation_id
");

// 6. Update equipment status
mysqli_query($conn, "
    UPDATE equipments
    SET status = '$newEquipmentStatus'
    WHERE equipment_id = $equipment_id
");

// 7. Log transaction
mysqli_query($conn, "
    INSERT INTO equipment_transactions
        (equipment_id, performed_by, status_from, status_to, action_date, remarks)
    VALUES
        ($equipment_id, $admin_id, '{$res['equipment_status']}', '$newEquipmentStatus', CURDATE(), 'Approved reservation #$reservation_id')
");

$_SESSION['success'] = "Reservation #$reservation_id approved successfully.";
header("Location: reservation.php");
exit();