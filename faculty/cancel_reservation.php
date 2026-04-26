<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

header('Content-Type: application/json');

$user_id        = (int) $_SESSION['user_id'];
$reservation_id = isset($_POST['reservation_id']) ? (int) $_POST['reservation_id'] : 0;

if (!$reservation_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid reservation ID.']);
    exit();
}

// Fetch the reservation — must belong to this user and still be pending
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT reservation_id, status, requested_by
     FROM reservations
     WHERE reservation_id = $reservation_id"
));

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Reservation not found.']);
    exit();
}

if ((int)$row['requested_by'] !== $user_id) {
    echo json_encode(['success' => false, 'message' => 'You do not own this reservation.']);
    exit();
}

if ($row['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending reservations can be cancelled.']);
    exit();
}

$delete = mysqli_query($conn,
    "DELETE FROM reservations WHERE reservation_id = $reservation_id"
);

if ($delete) {
    echo json_encode(['success' => true, 'reservation_id' => $reservation_id]);
} else {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
}