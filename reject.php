<?php
include('../database/db.php');

$reservation_id = $_POST['id'];
$remarks = $_POST['remarks'];

$admin_id = 1; // replace later with session

mysqli_query($conn, "
    UPDATE reservations 
    SET status = 'rejected',
        rejected_by = $admin_id,
        rejected_at = NOW(),
        remarks = '$remarks'
    WHERE reservation_id = $reservation_id
");

header("Location: ../admin/reservations.php");
exit();
?>
