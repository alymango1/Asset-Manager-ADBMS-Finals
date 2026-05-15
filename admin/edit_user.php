<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(" ", trim($_SESSION['full_name']));
    $name = $nameParts[0];
}

// need a valid user id
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    header("Location: users.php");
    exit();
}

$id = (int) $_GET['id'];

// get the user data
$fetchQuery = mysqli_query($conn, "SELECT * FROM users WHERE user_id = $id");
if (!$fetchQuery || mysqli_num_rows($fetchQuery) === 0) {
    header("Location: users.php");
    exit();
}
$user = mysqli_fetch_assoc($fetchQuery);

$message = "";
$messageType = "";

if (isset($_POST['save'])) {

    // make sure the request is legit
    if (
        empty($_SESSION['csrf_token']) ||
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $message     = "Invalid request. Please try again.";
        $messageType = "error";
    } else {

    $full_name    = trim(mysqli_real_escape_string($conn, $_POST['full_name']));
    $username     = trim(mysqli_real_escape_string($conn, $_POST['username']));
    $role         = trim($_POST['role']);
    $new_password = trim($_POST['password']); // blank = no change

    $allowed_roles = ['admin', 'staff'];

    if (empty($full_name)) {
        $message = "Full name cannot be empty.";
        $messageType = "error";
    } elseif (empty($username)) {
        $message = "Username cannot be empty.";
        $messageType = "error";
    } elseif (!in_array($role, $allowed_roles, true)) {
        $message = "Invalid role selected.";
        $messageType = "error";
    } else {
        // make sure username isn't taken
        $checkStmt = mysqli_prepare($conn,
            "SELECT user_id FROM users WHERE username = ? AND user_id != ?"
        );
        mysqli_stmt_bind_param($checkStmt, 'si', $username, $id);
        mysqli_stmt_execute($checkStmt);
        mysqli_stmt_store_result($checkStmt);

        if (mysqli_stmt_num_rows($checkStmt) > 0) {
            $message = "That username is already taken by another account.";
            $messageType = "error";
        } else {
            // hash the password before saving
            if ($new_password !== '') {
                $hashed_password = password_hash($new_password, PASSWORD_BCRYPT);
                $stmt = mysqli_prepare($conn,
                    "UPDATE users SET full_name = ?, username = ?, password = ?, roles = ? WHERE user_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'ssssi', $full_name, $username, $hashed_password, $role, $id);
            } else {
                $stmt = mysqli_prepare($conn,
                    "UPDATE users SET full_name = ?, username = ?, roles = ? WHERE user_id = ?"
                );
                mysqli_stmt_bind_param($stmt, 'sssi', $full_name, $username, $role, $id);
            }

            if (mysqli_stmt_execute($stmt)) {
                mysqli_stmt_close($stmt);

                // Reload user data
                $user['full_name'] = $full_name;
                $user['username']  = $username;
                $user['roles']     = $role;
                $message     = "User updated successfully!";
                $messageType = "success";

                // Refresh token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $message     = "Error: " . mysqli_stmt_error($stmt);
                $messageType = "error";
                mysqli_stmt_close($stmt);
            }
        }
        mysqli_stmt_close($checkStmt);
    }

    } // end CSRF else
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Edit User</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/edit_user.css">
    <link rel="icon" href="../img/favicon-96.png" type="image/png">
</head>

<body>

<?php include('sidebar.php'); ?>

<div class="header">
    <h1>Edit User</h1>

    <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>
        </button>
    </div>

    <!-- Dropdown -->
    <div class="dropdown" id="dropdownMenu">
        <p>Greetings, <?php echo htmlspecialchars($name); ?>!</p>
        <a href="logout.php">
            <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
            Logout
        </a>
    </div>
</div>
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");
btn.addEventListener("click", function(e) {
    e.stopPropagation();
    menu.classList.toggle("active");
});
document.addEventListener("click", function() {
    menu.classList.remove("active");
});
</script>

<div class="main">
    <div class="table-wrap">

        <!-- Breadcrumb -->
        <p style="font-size:0.85rem; color:#888; margin:0 0 6px;">
            <a href="users.php" style="color:#1a1a2e; text-decoration:none;">Users</a>
            &rsaquo; Edit
        </p>

        <h2>Edit User</h2>
        <p class="subtitle">Update account details or change the user's role.</p>

        <?php if ($message !== ""): ?>
            <div class="message-box <?php echo htmlspecialchars($messageType ?? ''); ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>

        <!-- User info -->
        <div class="edit-info-strip">
            <div class="edit-info-item">
                <span class="edit-info-label">User ID</span>
                <span class="edit-info-value">#<?php echo $user['user_id']; ?></span>
            </div>
            <div class="edit-info-item">
                <span class="edit-info-label">Current Role</span>
                <span class="role-badge <?php echo $user['roles']; ?>">
                    <?php echo ucfirst($user['roles']); ?>
                </span>
            </div>
        </div>

        <form method="POST" class="form-grid">
            <!-- CSRF field -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="input-group">
                <label>Full Name</label>
                <input
                    type="text"
                    name="full_name"
                    placeholder="Enter full name"
                    value="<?php echo htmlspecialchars($user['full_name']); ?>"
                    required
                >
            </div>

            <div class="input-group">
                <label>Username</label>
                <input
                    type="text"
                    name="username"
                    placeholder="Enter username"
                    value="<?php echo htmlspecialchars($user['username']); ?>"
                    required
                >
            </div>

            <div class="input-group">
                <label>
                    New Password
                    <span class="label-hint">(leave blank to keep current password)</span>
                </label>
                <div class="password-wrap">
                    <input
                        type="password"
                        name="password"
                        id="passwordInput"
                        placeholder="Enter new password"
                        autocomplete="new-password"
                    >
                    <button type="button" class="toggle-pw" onclick="togglePassword()" title="Show / hide password">
                        <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="#888"><path d="M480-320q75 0 127.5-52.5T660-500q0-75-52.5-127.5T480-680q-75 0-127.5 52.5T300-500q0 75 52.5 127.5T480-320Zm0-72q-45 0-76.5-31.5T372-500q0-45 31.5-76.5T480-608q45 0 76.5 31.5T588-500q0 45-31.5 76.5T480-392Zm0 192q-146 0-266-81.5T40-500q54-137 174-218.5T480-800q146 0 266 81.5T920-500q-54 137-174 218.5T480-200Zm0-300Zm0 220q113 0 207.5-59.5T832-500q-50-101-144.5-160.5T480-720q-113 0-207.5 59.5T128-500q50 101 144.5 160.5T480-280Z"/></svg>
                    </button>
                </div>
            </div>

            <div class="input-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="">Select Role</option>
                    <option value="admin" <?php echo $user['roles'] === 'admin' ? 'selected' : ''; ?>>Admin</option>
                    <option value="staff" <?php echo $user['roles'] === 'staff' ? 'selected' : ''; ?>>Staff</option>
                </select>
            </div>

            <div class="button-row">
                <button type="submit" name="save" class="btn-primary">
                    Save Changes
                </button>
                <a href="users.php" class="btn-secondary">
                    Cancel
                </a>
            </div>

        </form>

    </div>
</div>

<script>
function togglePassword() {
    const input = document.getElementById('passwordInput');
    const icon  = document.getElementById('eyeIcon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.setAttribute('fill', '#1a1a2e');
    } else {
        input.type = 'password';
        icon.setAttribute('fill', '#888');
    }
}
</script>



</body>
</html>
