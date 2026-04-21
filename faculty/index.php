<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];
$username = $_SESSION['username'];
$pending_q = mysqli_query($conn, "SELECT * FROM reservations WHERE requested_by = '$user_id' AND status = 'pending'");
$pending_count = mysqli_num_rows($pending_q);

$approved_q = mysqli_query($conn, "SELECT * FROM reservations WHERE requested_by = '$user_id' AND status = 'approved'");
$approved_count = mysqli_num_rows($approved_q);

$query = "SELECT * FROM reservations WHERE requested_by = '$user_id' ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Faculty Dashboard</title>
</head>
<body>

    <h1>Welcome, <?php echo $username; ?>!</h1>

    <div>
        <p><strong>Pending Reservations:</strong> <?php echo $pending_count; ?></p>
        <p><strong>Approved Reservations:</strong> <?php echo $approved_count; ?></p>
    </div>

    <hr>

    <p>
        <a href="reservation.php"><button>Quick New Reservation</button></a>
        <a href="logout.php"><button>Logout</button></a>
    </p>

    <h3>My Reservation History</h3>
    <table border="1" cellpadding="10">
        <thead>
            <tr>
                <th>Equipment ID</th>
                <th>Reserved Date</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><?php echo $row['equipment_id']; ?></td>
                <td><?php echo $row['reserved_date']; ?></td>
                <td><strong><?php echo ucfirst($row['status']); ?></strong></td>
                <td><?php echo $row['remarks'] ?? 'None'; ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

</body>
</html>
