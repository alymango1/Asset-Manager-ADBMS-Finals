<?php
include('../database/db.php');

$reservation_id = $_GET['id'];

// 1. Get reservation details first
$get = mysqli_query($conn, "
    SELECT * FROM reservations WHERE reservation_id = $reservation_id
");

$res = mysqli_fetch_assoc($get);

$equipment_id = $res['equipment_id'];
$admin_id = 1; // replace with SESSION user_id later

// 2. Update reservation
mysqli_query($conn, "
    UPDATE reservations 
    SET status = 'approved',
        approved_by = $admin_id,
        approved_at = NOW()
    WHERE reservation_id = $reservation_id
");

// 3. Update equipment status
mysqli_query($conn, "
    UPDATE equipments 
    SET status = 'In-Use'
    WHERE equipment_id = $equipment_id
");

// 4. Insert transaction log
mysqli_query($conn, "
    INSERT INTO equipment_transactions
    (equipment_id, performed_by, status_from, status_to, action_date, remarks)
    VALUES 
    ($equipment_id, $admin_id, 'Available', 'In-Use', CURDATE(), 'Approved reservation')
");

header("Location: ../admin/reservations.php");
exit();
?>
