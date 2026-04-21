<?php
include('../database/db.php');

session_start();

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

// COUNTS
$totalUsers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users"))['total'];
$totalAdmins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='admin'"))['total'];
$totalStaff  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='staff'"))['total'];

$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

// COUNT USERS
$totalQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM users");
$totalRow = mysqli_fetch_assoc($totalQuery);
$totalRecords = $totalRow['total'];
$totalPages = ceil($totalRecords / $limit);

// FETCH USERS WITH LIMIT
$usersQuery = mysqli_query($conn, "
    SELECT * FROM users 
    ORDER BY user_id ASC
    LIMIT $limit OFFSET $offset
");
?>

<!DOCTYPE html>
<html>
<head>
    <title>Users</title>
    <link rel="stylesheet" href="../css/style.css">
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
        <h1>Users</h1>

        <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>        
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

<a href="add_user.php" class="fab" title="Add User">
    <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="#fff">
        <path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/>
    </svg>
</a>

<div class="main">



    <br>

        <div class="users-grid">

            <div class="user-box users-total">
                <h3>Total Users</h3>
                <p><?php echo $totalUsers; ?></p>
            </div>

            <div class="user-box users-admin">
                <h3>Admins</h3>
                <p><?php echo $totalAdmins; ?></p>
            </div>

            <div class="user-box users-staff">
                <h3>Staff</h3>
                <p><?php echo $totalStaff; ?></p>
            </div>

        </div>

    <br><br>


    <!-- USERS TABLE -->
    <div class="table-wrap">
    <div class="transaction-table">
        <h2>Users List</h2>

        <table class="table" width="100%" cellpadding="10" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Full Name</th>
                <th>Username</th>
                <th>Role</th>
                <th>Action</th>
            </tr>

            <?php while($row = mysqli_fetch_assoc($usersQuery)) { ?>
            <tr>
            <td><?php echo $row['user_id']; ?></td>
            <td><?php echo $row['full_name']; ?></td>
            <td><?php echo $row['username']; ?></td>
            <td><?php echo ucfirst($row['roles']); ?></td>

            <td>
                <a href="../config/delete_user.php?id=<?php echo $row['user_id']; ?>"
                onclick="return confirm('Are you sure you want to delete this user?')"
                style="color:white; background:#C40C0C; padding:6px 10px; border-radius:6px; text-decoration:none;">
                Delete
                </a>
    </td>
</tr>
            <?php } ?>

        </table>

        <div class="pagination">

    <?php if ($page > 1): ?>
        <a href="?page=<?php echo $page - 1; ?>">&laquo; Prev</a>
    <?php endif; ?>

    <?php for ($i = 1; $i <= $totalPages; $i++): ?>
        <a href="?page=<?php echo $i; ?>"
           class="<?php echo ($i == $page) ? 'active' : ''; ?>">
            <?php echo $i; ?>
        </a>
    <?php endfor; ?>

    <?php if ($page < $totalPages): ?>
        <a href="?page=<?php echo $page + 1; ?>">Next &raquo;</a>
    <?php endif; ?>

</div>
    </div>
    </div>
    <br><br>

    <div class="table-wrap">
    <div class="users-intro">

    <div class="intro-main">
        <h2>User Management</h2>
        <p>Manage system users, roles, and access permissions for the asset management system.</p>
    </div>

        <div class="intro-badges">

        <div class="badge primary">
            <p class="badge-title">Admin & Staff Control</p>
            <p class="badge-desc">Manage who can access system features and administrative tools.</p>
        </div>

        <div class="badge secondary">
            <p class="badge-title">Secure Role-Based Access</p>
            <p class="badge-desc">Each account is assigned permissions based on its role level.</p>
        </div>

    </div>
    </div>
</div>

</div>

</body>
</html>