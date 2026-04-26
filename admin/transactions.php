<?php
require_once __DIR__ . '/../database/db.php';

session_start();

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

// QUERY
$query = "
SELECT 
    r.reservation_id,
    r.equipment_id,
    e.resource_name,
    r.requested_by,
    req.username AS requested_name,

    r.status,
    r.reserved_date,

    r.approved_by,
    app.username AS approved_name,
    r.approved_at,

    r.rejected_by,
    rej.username AS rejected_name,
    r.rejected_at,

    r.remarks,
    r.created_at

FROM reservations r
JOIN equipments e ON r.equipment_id = e.equipment_id

LEFT JOIN users req ON r.requested_by = req.user_id
LEFT JOIN users app ON r.approved_by = app.user_id
LEFT JOIN users rej ON r.rejected_by = rej.user_id

ORDER BY r.created_at DESC
";

$result = mysqli_query($conn, $query);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Transactions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Transactions</h1>

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


    <div class="table-wrap">
        <h2>Transaction Logs</h2>
        <table class="transaction_table">
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Requested By</th>
                <th>Date</th>
                <th>Status</th>
                <th>Details</th>
                <th>Created At</th>
            </tr>

            <?php while($row = mysqli_fetch_assoc($result)) { ?>
            <tr>
                <td><?php echo $row['reservation_id']; ?></td>
                <td><?php echo $row['resource_name']; ?></td>
                <td><?php echo $row['requested_name']; ?></td>
                <td><?php echo $row['reserved_date']; ?></td>
                
                
                <!-- STATUS COLOR -->
                <td class="status <?php echo strtolower(trim($row['status'])); ?>">
                    <?php echo strtoupper($row['status']); ?>
                </td>

                <!-- DETAILS COLUMN -->
                <td>
                    <?php if ($row['status'] == 'approved') { ?>
                        Approved by <b><?php echo $row['approved_name']; ?></b><br>
                        At: <?php echo $row['approved_at']; ?>
                    <?php } else if ($row['status'] == 'rejected') { ?>
                    Rejected by <b><?php echo $row['rejected_name']; ?></b><br>
                    At: <?php echo $row['rejected_at']; ?><br>
                    Reason: <span class="reason"><?php echo $row['remarks']; ?></span>
                    <?php } ?>
                </td>

                <td><?php echo $row['created_at']; ?></td>
            </tr>
            <?php } ?>

        </table>

    </div>

</div>

</body>
</html>