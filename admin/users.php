<?php
include('../database/db.php');

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
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Users</h1>

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

<a href="add_user.php" class="fab" title="Add User">
    <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="#fff">
        <path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/>
    </svg>
</a>

<div class="main">

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
            <td><?php echo htmlspecialchars($row['full_name']); ?></td>
            <td><?php echo htmlspecialchars($row['username']); ?></td>
            <td><?php echo ucfirst($row['roles']); ?></td>

            <td>
                <a href="edit_user.php?id=<?php echo $row['user_id']; ?>"
                style="color:white; background:#1976d2; padding:6px 10px; border-radius:6px; text-decoration:none; font-size:0.85em; margin-right:5px;">
                Edit
                </a>
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


</body>
</html>