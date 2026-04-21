<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (isset($_POST['submit_res'])) {
    $equipment_id = 1;
    $res_date = $_POST['res_date'];
    $requested_by = $_SESSION['user_id']; 

    $sql = "INSERT INTO reservations (equipment_id, requested_by, reserved_date, status) 
            VALUES ('$equipment_id', '$requested_by', '$res_date', 'pending')";

    if (mysqli_query($conn, $sql)) {
        echo "<script>alert('Reservation submitted successfully.'); window.location='index.php';</script>";
    } else {
        echo "Error: " . mysqli_error($conn);
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Faculty Reservation</title>
    <link rel="stylesheet" href="../css/style.css">
</head>
<body>
    <div class="reservation-container">
        <h2>Request Equipment</h2>
        <form action="reservation.php" method="POST">
            <label>Equipment Name:</label>
            <input type="text" name="equipment" placeholder="e.g. HDMI, Projector" required>

            <label>Date of Use:</label>
            <input type="date" name="res_date" required>

            <button type="submit" name="submit_res">Submit Reservation</button>
        </form>
    </div>
</body>
</html>