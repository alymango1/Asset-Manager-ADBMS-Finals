<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

header('Content-Type: application/json');


// make sure the request is legit
$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit();
}

$user_id        = (int) $_SESSION['user_id'];
$reservation_id = isset($_POST['reservation_id']) ? (int) $_POST['reservation_id'] : 0;

if (!$reservation_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID.']);
    exit();
}

// get the reservation
$fetch_stmt = mysqli_prepare($conn,
    "SELECT reservation_id, status, requested_by, equipment_id
     FROM reservations
     WHERE reservation_id = ?"
);
mysqli_stmt_bind_param($fetch_stmt, 'i', $reservation_id);
mysqli_stmt_execute($fetch_stmt);
$row = mysqli_fetch_assoc(mysqli_stmt_get_result($fetch_stmt));
mysqli_stmt_close($fetch_stmt);

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
    exit();
}

// make sure it belongs to this user
if ($row['requested_by'] === null || (int)$row['requested_by'] !== $user_id) {
    echo json_encode(['success' => false, 'message' => 'You do not own this reservation.']);
    exit();
}

if ($row['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
    exit();
}

// keep equipment id for later
$equipment_id = (int)$row['equipment_id'];

// start db transaction
mysqli_begin_transaction($conn);
try {
    $cancel = mysqli_prepare($conn,
        "UPDATE reservations SET status = 'cancelled' WHERE reservation_id = ?"
    );
    mysqli_stmt_bind_param($cancel, 'i', $reservation_id);
    mysqli_stmt_execute($cancel);
    mysqli_stmt_close($cancel);

    if ($equipment_id) {
        // set equipment back to available if no one else has it
        $approved_stmt = mysqli_prepare($conn,
            "SELECT reservation_id FROM reservations
             WHERE equipment_id = ?
               AND status       = 'approved'
             LIMIT 1"
        );
        mysqli_stmt_bind_param($approved_stmt, 'i', $equipment_id);
        mysqli_stmt_execute($approved_stmt);
        $still_approved = mysqli_fetch_assoc(mysqli_stmt_get_result($approved_stmt));
        mysqli_stmt_close($approved_stmt);

        if (!$still_approved) {
            $upd_eq = mysqli_prepare($conn,
                "UPDATE equipments SET status = 'Available' WHERE equipment_id = ? AND status = 'Reserved'"
            );
            mysqli_stmt_bind_param($upd_eq, 'i', $equipment_id);
            mysqli_stmt_execute($upd_eq);
            mysqli_stmt_close($upd_eq);
        }
    }

    mysqli_commit($conn);
    echo json_encode(['success' => true, 'reservation_id' => $reservation_id]);
} catch (Exception $e) {
    mysqli_rollback($conn);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
}