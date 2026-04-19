<?php
include('../database/db.php');

session_start();


/*check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: ../login-system/login.php");
    exit();
}*/


// Total Equipment
$equipmentCountQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments");
$equipmentCount = mysqli_fetch_assoc($equipmentCountQuery)['total'];

// Equipment Status Counts
$inUseQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'In-Use'");
$inUse = mysqli_fetch_assoc($inUseQuery)['total'];

$availableQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available'");
$available = mysqli_fetch_assoc($availableQuery)['total'];

$maintenanceQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Under Maintenance'");
$maintenance = mysqli_fetch_assoc($maintenanceQuery)['total'];

// Active Reservations Today
$today = date('Y-m-d');

$resTodayQuery = mysqli_query($conn, "
    SELECT COUNT(*) AS total 
    FROM reservations 
    WHERE DATE(reserved_date) = '$today'
");

$reservationsQuery = mysqli_query($conn, "
    SELECT 
        r.reservation_id,
        e.resource_name,
        u.username AS requested_by,
        r.status,
        r.reserved_date
    FROM reservations r
    JOIN equipments e 
        ON r.equipment_id = e.equipment_id
    JOIN users u 
        ON r.requested_by = u.user_id
    ORDER BY r.reserved_date DESC
");

$resToday = mysqli_fetch_assoc($resTodayQuery)['total'];

// Active In-Use Equipment (based on your schema)
$activeItemsQuery = mysqli_query($conn, "
    SELECT 
        e.equipment_id,
        e.resource_name,
        t.status_from,
        t.status_to,
        t.action_date,
        t.remarks
    FROM equipment_transactions t
    JOIN equipments e 
        ON t.equipment_id = e.equipment_id
    WHERE e.status = 'In-Use'
    ORDER BY t.action_date DESC
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Admin Dashboard</title>
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>
<div class="sidebar">
    <h1>ADMIN</h1>
    <hr>
    <br>
    <a href="#"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Zm80-400h160v-240H200v240Zm400 320h160v-240H600v240Zm0-480h160v-80H600v80ZM200-200h160v-80H200v80Zm160-320Zm240-160Zm0 240ZM360-280Z"/></svg>Dashboard</a>
    <a href="#"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M367-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM160-160v-112q0-34 17.5-62.5T224-378q62-31 126-46.5T480-440q66 0 130 15.5T736-378q29 15 46.5 43.5T800-272v112H160Zm80-80h480v-32q0-11-5.5-20T700-306q-54-27-109-40.5T480-360q-56 0-111 13.5T260-306q-9 5-14.5 14t-5.5 20v32Zm296.5-343.5Q560-607 560-640t-23.5-56.5Q513-720 480-720t-56.5 23.5Q400-673 400-640t23.5 56.5Q447-560 480-560t56.5-23.5ZM480-640Zm0 400Z"/></svg>Users</a>
    <a href="../admin/equipments.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>Equipments</a>
    <a href="../admin/reservation.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm221.5-198.5Q510-807 510-820t-8.5-21.5Q493-850 480-850t-21.5 8.5Q450-833 450-820t8.5 21.5Q467-790 480-790t21.5-8.5ZM200-200v-560 560Z"/></svg>Reservations</a>
    <a href="#"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M400-400h160v-80H400v80Zm0-120h320v-80H400v80Zm0-120h320v-80H400v80Zm-80 400q-33 0-56.5-23.5T240-320v-480q0-33 23.5-56.5T320-880h480q33 0 56.5 23.5T880-800v480q0 33-23.5 56.5T800-240H320Zm0-80h480v-480H320v480ZM160-80q-33 0-56.5-23.5T80-160v-560h80v560h560v80H160Zm160-720v480-480Z"/></svg>Transactions</a>
    <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
</div>

<div class="main">
    <div class="header">
        <h1>Admin Dashboard</h1>
    </div>

    <br>
    <div class="table-wrap">
    <div class="cards">
        <div class="card total">
            <h3>Total Equipment</h3>
            <p><?php echo $equipmentCount; ?></p>
        </div>

        <div class="card inuse">
            <h3>In-Use</h3>
            <p><?php echo $inUse; ?></p>
        </div>

        <div class="card available">
            <h3>Available</h3>
            <p><?php echo $available; ?></p>
        </div>

        <div class="card maintenance">
            <h3>Maintenance</h3>
            <p><?php echo $maintenance; ?></p>
        </div>

        <div class="card reservation">
            <h3>Reservations Today</h3>
            <p><?php echo $resToday; ?></p>
        </div>
    </div>
</div>

<br><br>
 
<div class="table-wrap">
    <h2>Quick Actions</h2>

    <div class="action-grid">

        <a href="../admin/add_equipment.php" class="action-tile primary">
            <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#75FB4C"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg></span>
            <div>
                <h3>Add Equipment</h3>
                <p>Register new inventory item</p>
            </div>
        </a>

        <a href="../admin/reservation.php" class="action-tile secondary">
            <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#EA3323"><path d="M186.67-120q-27.5 0-47.09-19.58Q120-159.17 120-186.67v-586.66q0-27.5 19.58-47.09Q159.17-840 186.67-840h192.66q7.67-35.33 35.84-57.67Q443.33-920 480-920t64.83 22.33Q573-875.33 580.67-840h192.66q27.5 0 47.09 19.58Q840-800.83 840-773.33v308.66q-15.67-8.33-32.67-13.83t-34-8.5v-286.33H186.67v586.66h286.66q3.67 18 9.19 34.79 5.51 16.78 13.15 31.88h-309Zm0-111.33v44.66-586.66V-487v-3.67V-231.33ZM280-280h195q3.67-17.67 9.17-34.33 5.5-16.67 13.5-32.34H280V-280Zm0-166.67h310.67q20-14.66 41.83-24.33 21.83-9.67 47.5-14.67v-27.66H280v66.66Zm0-166.66h400V-680H280v66.67ZM503.5-804.5q9.83-9.83 9.83-23.5t-9.83-23.5q-9.83-9.83-23.5-9.83t-23.5 9.83q-9.83 9.83-9.83 23.5t9.83 23.5q9.83 9.83 23.5 9.83t23.5-9.83ZM728.33-40.67q-79.33 0-135.5-56.5-56.16-56.5-56.16-134.83 0-79.96 56.16-136.31 56.16-56.36 135.84-56.36 79 0 135.5 56.36 56.5 56.35 56.5 136.31 0 78.33-56.5 134.83-56.5 56.5-135.84 56.5ZM712-107.33h35.33V-214H854v-35.33H747.33V-356H712v106.67H605.33V-214H712v106.67Z"/></svg></span>
            <div>
                <h3>Approve / Reject Reservations</h3>
                <p>Approve or reject user reservations</p>
            </div>
        </a>

        <a href="../admin/add_user.php" class="action-tile secondary">
            <span class="icon"><svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#EA3323"><path d="M726.67-400v-126.67H600v-66.66h126.67V-720h66.66v126.67H920v66.66H793.33V-400h-66.66ZM250.33-524.33Q206.67-568 206.67-634t43.66-109.67Q294-787.33 360-787.33t109.67 43.66Q513.33-700 513.33-634t-43.66 109.67Q426-480.67 360-480.67t-109.67-43.66ZM40-160v-100q0-34.67 17.5-63.17T106.67-366q70.66-32.33 131-46.5Q298-426.67 360-426.67t122 14.17q60 14.17 130.67 46.5 31.66 15 49.5 43.17Q680-294.67 680-260v100H40Zm66.67-66.67h506.66V-260q0-14.33-7.83-27t-20.83-19q-65.34-31-116.34-42.5T360-360q-57.33 0-108.67 11.5Q200-337 134.67-306q-13 6.33-20.5 19t-7.5 27v33.33Zm315.16-345.5Q446.67-597 446.67-634t-24.84-61.83Q397-720.67 360-720.67t-61.83 24.84Q273.33-671 273.33-634t24.84 61.83Q323-547.33 360-547.33t61.83-24.84ZM360-634Zm0 407.33Z"/></svg></span>
            <div>
                <h3>Create Accounts</h3>
                <p>Add faculty or staff account</p>
            </div>
        </a>

    </div>
</div>

<br><br>

<div class="table-wrap">
    <h2>Reservations</h2>

    <table class="table" border="1" width="100%" cellpadding="10" cellspacing="0">
        <tr>
            <th>ID</th>
            <th>Equipment</th>
            <th>Requested By</th>
            <th>Status</th>
            <th>Date</th>
        </tr>

        <?php while($row = mysqli_fetch_assoc($reservationsQuery)) { ?>
        <tr>
            <td><?php echo $row['reservation_id']; ?></td>
            <td><?php echo $row['resource_name']; ?></td>
            <td><?php echo $row['requested_by']; ?></td>
            <td><?php echo $row['status']; ?></td>
            <td><?php echo $row['reserved_date']; ?></td>
        </tr>
        <?php } ?>
    </table>
</div>
    </div>
</div>

</body>
</html>