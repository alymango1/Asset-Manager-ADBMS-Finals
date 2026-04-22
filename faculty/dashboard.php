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
    <link rel="stylesheet" href="../css/style_faculty.css">
</head>
<body>

<div class="sidebar">
    <div class="logo-container">
        <img src="../img/bsu.png" alt="Logo">
        <h2>Asset Manager</h2>
    </div>
    <hr>
    <br>
    <a href="../admin/dashboard.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Zm80-400h160v-240H200v240Zm400 320h160v-240H600v240Zm0-480h160v-80H600v80ZM200-200h160v-80H200v80Zm160-320Zm240-160Zm0 240ZM360-280Z"/></svg>Dashboard</a>
    <a href="../admin/users.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M367-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM160-160v-112q0-34 17.5-62.5T224-378q62-31 126-46.5T480-440q66 0 130 15.5T736-378q29 15 46.5 43.5T800-272v112H160Zm80-80h480v-32q0-11-5.5-20T700-306q-54-27-109-40.5T480-360q-56 0-111 13.5T260-306q-9 5-14.5 14t-5.5 20v32Zm296.5-343.5Q560-607 560-640t-23.5-56.5Q513-720 480-720t-56.5 23.5Q400-673 400-640t23.5 56.5Q447-560 480-560t56.5-23.5ZM480-640Zm0 400Z"/></svg>Users</a>
    <a href="../admin/equipments.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>Equipments</a>
    <a href="../admin/reservation.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm221.5-198.5Q510-807 510-820t-8.5-21.5Q493-850 480-850t-21.5 8.5Q450-833 450-820t8.5 21.5Q467-790 480-790t21.5-8.5ZM200-200v-560 560Z"/></svg>Reservations</a>
    <a href="../admin/transactions.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M400-400h160v-80H400v80Zm0-120h320v-80H400v80Zm0-120h320v-80H400v80Zm-80 400q-33 0-56.5-23.5T240-320v-480q0-33 23.5-56.5T320-880h480q33 0 56.5 23.5T880-800v480q0 33-23.5 56.5T800-240H320Zm0-80h480v-480H320v480ZM160-80q-33 0-56.5-23.5T80-160v-560h80v560h560v80H160Zm160-720v480-480Z"/></svg>Transactions</a>
    <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
</div>

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

    <h1>Welcome, <?php echo $username; ?>!</h1>

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

        <a class="action-tile secondary" href="reservation.php">
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

            </div>
            </div>
</body>
</html>
