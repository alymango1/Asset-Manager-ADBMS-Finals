<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id  = $_SESSION['user_id'];
$username = $_SESSION['username'];

// Derive first name for the greeting dropdown
$name = 'User'; // fallback
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(' ', trim($_SESSION['full_name']));
    $name = $nameParts[0];
} elseif (isset($_SESSION['username'])) {
    $name = $_SESSION['username'];
}
$pending_q = mysqli_query($conn, "SELECT * FROM reservations WHERE requested_by = '$user_id' AND status = 'pending'");
$pending_count = mysqli_num_rows($pending_q);

$approved_q = mysqli_query($conn, "SELECT * FROM reservations WHERE requested_by = '$user_id' AND status = 'approved'");
$approved_count = mysqli_num_rows($approved_q);

$query = "SELECT e.resource_name, r.reserved_date, r.status, r.remarks FROM reservations r LEFT JOIN equipments e ON e.equipment_id = r.equipment_id WHERE requested_by = '$user_id' ORDER BY created_at DESC";
$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Faculty Dashboard</title>
    <link rel="stylesheet" href="../css/style_faculty.css">
</head>
<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Dashboard</h1>

        <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>        
        </button>
        </div>

        <!-- DROPDOWN -->
        <div class="dropdown" id="dropdownMenu">
            <p>Greetings, <?php echo $name?>!</p>
            <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
        </div>

    </div>
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");

btn.addEventListener("click", function (e) {
    e.stopPropagation();
    menu.classList.toggle("active");
});

// close when clicking outside
document.addEventListener("click", function () {
    menu.classList.remove("active");
});
</script>
 
<div class="main">

    <h1>Welcome, <?php echo htmlspecialchars($username); ?>!</h1>

    <div class="cards">
    <div class="card pending">
        <h3>Pending Resevations:</h3>
        <p><?php echo $pending_count; ?></p>
    </div>
    <div class="card approved">
        <h3>Approved Reservations</h3>
        <p><?php echo $approved_count; ?></p>
    </div>
</div>

<br>

    <div class="table-wrap">
        <h2>Quick Actions</h2>  
    <div class="action-grid">
    
        <a class="action-tile primary" href="reservation.php">
             <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#75FB4C"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg></span>
        <div>
             <h3>Quick New Reservation</h3>
            <p>Register new inventory item</p>
        </div>
        </a>

        <a class="action-tile secondary" href="equipments.php">
             <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#75FB4C"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg></span>
        <div>
             <h3>View Equipments</h3>
            <p>View equipments that are currently available</p>
        </div>

        
        </a>
</div>
</div>

<br>
    
    <div class="table-wrap">
    <h3>My Reservation History</h3>
    <table class="transaction_table">
        
        <thead>
            <tr>
                <th>Equipment Name</th>
                <th>Reserved Date</th>
                <th>Status</th>
                <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            <?php while($row = mysqli_fetch_assoc($result)): ?>
            <tr>
                <td><?php echo htmlspecialchars($row['resource_name']); ?></td>
                <td><?php echo htmlspecialchars($row['reserved_date']); ?></td>
                <td class="status <?php echo strtolower($row['status']); ?>">
                    <?php echo htmlspecialchars(ucfirst($row['status'])); ?>
                </td>
                <td><?php echo htmlspecialchars($row['remarks'] ?? 'None'); ?></td>
            </tr>
            <?php endwhile; ?>
        </tbody>
    </table>

            </div>
            </div>
</body>
</html>