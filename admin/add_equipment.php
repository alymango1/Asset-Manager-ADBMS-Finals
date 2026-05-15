<?php
session_start();
include('../database/db.php');

// set up csrf token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(" ", trim($_SESSION['full_name']));
    $name = $nameParts[0];
}

$message = "";

if (isset($_POST['add'])) {

    // make sure the request is legit
    if (
        empty($_SESSION['csrf_token']) ||
        !isset($_POST['csrf_token']) ||
        !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])
    ) {
        $message = "Invalid request. Please try again.";
    } else {

    $resource_name = mysqli_real_escape_string($conn, $_POST['resource_name']);
    $category      = $_POST['category'] ?? '';
    $status        = "Available"; // Default status

    // reject bad category values
    $allowed_categories = ['IT Equipment', 'Classroom', 'Events Equipment'];
    if (!in_array($category, $allowed_categories, true)) {
        $message = "Invalid category selected.";
    } elseif (!empty($resource_name) && !empty($category)) {

        $stmt = mysqli_prepare($conn,
            "INSERT INTO equipments (resource_name, categories, status) VALUES (?, ?, 'Available')"
        );
        mysqli_stmt_bind_param($stmt, 'ss', $resource_name, $category);

        if (mysqli_stmt_execute($stmt)) {
            $new_equipment_id = (int) mysqli_insert_id($conn);
            $message = "Equipment added successfully!";

            // log it
            $admin_id  = (int) $_SESSION['user_id'];
            $log_remarks = "Added equipment: \"$resource_name\" (Category: $category)";
            $log_stmt = mysqli_prepare($conn, "
                INSERT INTO equipment_transactions
                    (action_type, equipment_id, performed_by,
                     field_changed, new_value, action_date, remarks)
                VALUES ('equipment_added', ?, ?, 'initial', ?, NOW(), ?)
            ");
            mysqli_stmt_bind_param($log_stmt, 'iiss',
                $new_equipment_id, $admin_id,
                $category, $log_remarks);
            mysqli_stmt_execute($log_stmt);
            mysqli_stmt_close($log_stmt);

            // end log
        } else {
            $message = "Error: " . mysqli_stmt_error($stmt);
        }
        mysqli_stmt_close($stmt);

    } else {
        $message = "Please fill in all fields.";
    }
    } // End category check

    // give a new csrf token
    if (strpos($message, 'successfully') !== false) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    } // End CSRF branch

    // send json back if it's an ajax call
    if (isset($_POST['ajax']) && $_POST['ajax'] === '1') {
        header('Content-Type: application/json; charset=utf-8');
        $success = (stripos($message, 'success') !== false);
        echo json_encode([
            'success' => $success,
            'message' => $message
        ]);
        exit();
    }

?>

<!DOCTYPE html>
<html>
<head>
    <title>Add Equipment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Add Equipment</h1>

        <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>        
        </button>
        </div>

        <!-- Dropdown -->
        <div class="dropdown" id="dropdownMenu">
            <p>Greetings, <?php echo htmlspecialchars($name ?? ''); ?>!</p>
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

// close dropdown when clicking outside
document.addEventListener("click", function () {
    menu.classList.remove("active");
});
</script>



<div class="main">

    <div class="table-wrap">

        <h2>Add New Equipment</h2>
        <p class="subtitle">Add a new equipment item to the system</p>

        <?php if ($message != "") { ?>
            <div class="message-box">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <form method="POST" class="form-grid">
            <!-- CSRF field -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <!-- Resource name -->
            <div class="input-group">
                <label>Resource Name</label>
                <input type="text" name="resource_name" placeholder="Enter equipment name" required>
            </div>

            <!-- Category -->
            <div class="input-group">
                <label>Category</label>
                <select name="category" required>
                    <option value="">Select Category</option>
                    <option value="IT Equipment">IT Equipment</option>
                    <option value="Classroom">Classroom</option>
                    <option value="Events Equipment">Events Equipment</option>
                </select>
            </div>

            <!-- Form actions -->
            <div class="button-row">
                <button type="submit" name="add" class="btn-primary">
                    Add Equipment
                </button>

                <a href="equipments.php" class="btn-secondary">
                    Cancel
                </a>
            </div>

        </form>

    </div>

</div>

</body>
</html>