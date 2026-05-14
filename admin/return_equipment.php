<?php
session_start();
require_once __DIR__ . '/../database/db.php';

// Auth guard
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

header('Content-Type: application/json');

$admin_id     = (int) $_SESSION['user_id'];
$equipment_id = isset($_POST['equipment_id']) ? (int) $_POST['equipment_id'] : 0;
$remarks      = isset($_POST['remarks']) ? trim(mysqli_real_escape_string($conn, $_POST['remarks'])) : '';

if (!$equipment_id) {
    echo json_encode(['success' => false, 'message' => 'Invalid equipment ID.']);
    exit();
}

// Fetch current equipment status — must be In-Use to be returnable
$row = mysqli_fetch_assoc(mysqli_query($conn,
    "SELECT equipment_id, resource_name, status FROM equipments WHERE equipment_id = $equipment_id"
));

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Equipment not found.']);
    exit();
}
if ($row['status'] !== 'In-Use') {
    echo json_encode(['success' => false, 'message' => 'Equipment is not currently In-Use.']);
    exit();
}

// Find the linked approved reservation (most recent) for this equipment
$resRow = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT reservation_id
    FROM reservations
    WHERE equipment_id = $equipment_id
      AND status       = 'approved'
    ORDER BY approved_at DESC
    LIMIT 1
"));

$reservation_id = $resRow ? (int) $resRow['reservation_id'] : 'NULL';

$final_remarks = $remarks !== '' ? $remarks : 'Equipment returned by admin';

// After return, check if there's another approved (Reserved) reservation coming up
// If yes → stay Reserved so the next person can still pick it up
// If no  → go back to Available
$upcoming = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT reservation_id FROM reservations
    WHERE equipment_id  = $equipment_id
      AND status        = 'approved'
      AND (
    reserved_date > CURDATE()
    OR (reserved_date = CURDATE() AND reserved_end > NOW())
)
      AND reservation_id != " . ($resRow ? (int)$resRow['reservation_id'] : 0) . "
    ORDER BY reserved_date ASC
    LIMIT 1
"));

$newStatus = $upcoming ? 'Reserved' : 'Available';

// 1. Update equipment status
$update = mysqli_query($conn,
    "UPDATE equipments SET status = '$newStatus' WHERE equipment_id = $equipment_id"
);
if (!$update) {
    echo json_encode(['success' => false, 'message' => mysqli_error($conn)]);
    exit();
}

// 2. Close the reservation by updating its status to 'returned'
if ($resRow) {
    mysqli_query($conn, "
        UPDATE reservations
        SET status  = 'returned',
            remarks = CONCAT(IFNULL(remarks, ''), ' | Returned on: ', CURDATE())
        WHERE reservation_id = {$resRow['reservation_id']}
    ");
}

// 3. Log in equipment_transactions
mysqli_query($conn, "
    INSERT INTO equipment_transactions
        (equipment_id, performed_by, status_from, status_to, action_date, remarks)
    VALUES
        ($equipment_id, $admin_id, 'In-Use', '$newStatus', CURDATE(), '$final_remarks')
");

echo json_encode([
    'success'      => true,
    'message'      => htmlspecialchars($row['resource_name']) . ' has been marked as Returned. Status is now ' . $newStatus . '.',
    'equipment_id' => $equipment_id,
]);