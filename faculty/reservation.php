<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

// set up csrf token if missing
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// get user's name
$name = 'User';
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(' ', trim($_SESSION['full_name']));
    $name = $nameParts[0];
} elseif (isset($_SESSION['username'])) {
    $name = $_SESSION['username'];
}

// make display name and initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$firstNameRaw = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw)[0] : 'User';
$name = ucfirst(strtolower($firstNameRaw));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$user_id = $_SESSION['user_id'];

// handle cart reservation submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_cart'])) {
    header('Content-Type: application/json');

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $res_date_raw = $_POST['res_date'] ?? '';
    $parsed_date  = DateTime::createFromFormat('Y-m-d', $res_date_raw);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $res_date_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit();
    }
    $res_date = $res_date_raw;
    $today = date('Y-m-d');
    if ($res_date < $today) {
        echo json_encode(['success' => false, 'message' => 'Reservation date cannot be in the past.']);
        exit();
    }

    $start_time_raw = $_POST['start_time'] ?? '';
    $end_time_raw   = $_POST['end_time']   ?? '';
    $parsed_start   = DateTime::createFromFormat('H:i', $start_time_raw);
    $parsed_end     = DateTime::createFromFormat('H:i', $end_time_raw);
    if (!$parsed_start || $parsed_start->format('H:i') !== $start_time_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid start time.']);
        exit();
    }
    if (!$parsed_end || $parsed_end->format('H:i') !== $end_time_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid end time.']);
        exit();
    }
    if ($parsed_end <= $parsed_start) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
        exit();
    }
    $start_time    = $start_time_raw . ':00';
    $end_time      = $end_time_raw   . ':00';
    $reserved_start = $res_date . ' ' . $start_time;
    $reserved_end   = $res_date . ' ' . $end_time;

    // parse the cart data from post
    $cart_items_raw = $_POST['cart_items'] ?? '[]';
    $cart_items = json_decode($cart_items_raw, true);
    if (!is_array($cart_items) || count($cart_items) === 0) {
        echo json_encode(['success' => false, 'message' => 'No items in cart.']);
        exit();
    }

    $inserted_ids = [];
    $errors = [];

    // make a batch id if more than one item
    $batch_id = (count($cart_items) > 1) ? bin2hex(random_bytes(16)) : null;

    foreach ($cart_items as $item) {
        $equipment_id = (int)($item['id'] ?? 0);
        if ($equipment_id <= 0) continue;

        // skip if user already reserved this
        $stmt_self = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
            WHERE equipment_id = ? AND requested_by = ?
            AND status IN ('pending','approved')
            AND reserved_date = ?
            AND NOT (reserved_end <= ? OR reserved_start >= ?)");
        mysqli_stmt_bind_param($stmt_self, 'iisss', $equipment_id, $user_id, $res_date, $reserved_start, $reserved_end);
        mysqli_stmt_execute($stmt_self);
        $result_self = mysqli_stmt_get_result($stmt_self);
        $self_count  = mysqli_num_rows($result_self);
        mysqli_stmt_close($stmt_self);
        if ($self_count > 0) {
            $errors[] = htmlspecialchars($item['name'] ?? "Item #$equipment_id") . ': you already have an overlapping reservation.';
            continue;
        }

        // skip if there's a time conflict
        $stmt_conflict = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
            WHERE equipment_id = ?
            AND status = 'approved'
            AND reserved_date = ?
            AND NOT (reserved_end <= ? OR reserved_start >= ?)");
        mysqli_stmt_bind_param($stmt_conflict, 'isss', $equipment_id, $res_date, $reserved_start, $reserved_end);
        mysqli_stmt_execute($stmt_conflict);
        $result_conflict = mysqli_stmt_get_result($stmt_conflict);
        $conflict_count  = mysqli_num_rows($result_conflict);
        mysqli_stmt_close($stmt_conflict);
        if ($conflict_count > 0) {
            $errors[] = htmlspecialchars($item['name'] ?? "Item #$equipment_id") . ': already reserved during that time slot.';
            continue;
        }

        $stmt_insert = mysqli_prepare($conn,
            "INSERT INTO reservations (batch_id, equipment_id, requested_by, status, reserved_date, reserved_start, reserved_end, created_at)
             VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())");
        mysqli_stmt_bind_param($stmt_insert, 'siisss', $batch_id, $equipment_id, $user_id, $res_date, $reserved_start, $reserved_end);
        if (mysqli_stmt_execute($stmt_insert)) {
            $inserted_ids[$equipment_id] = mysqli_insert_id($conn);
        } else {
            $errors[] = htmlspecialchars($item['name'] ?? "Item #$equipment_id") . ': database error.';
        }
        mysqli_stmt_close($stmt_insert);
    }

    if (count($inserted_ids) > 0) {
        echo json_encode([
            'success'      => true,
            'inserted'     => $inserted_ids,
            'errors'       => $errors,
            'partial'      => count($errors) > 0,
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => implode(' | ', $errors) ?: 'No items were reserved.', 'errors' => $errors]);
    }
    exit();
}

// handle old single-item submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_res'])) {
    header('Content-Type: application/json');

    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $equipment_id = (int)$_POST['equipment_id'];
    $res_date_raw = $_POST['res_date'] ?? '';
    $parsed_date  = DateTime::createFromFormat('Y-m-d', $res_date_raw);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $res_date_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit();
    }
    $res_date = $res_date_raw;
    $today = date('Y-m-d');
    if ($res_date < $today) {
        echo json_encode(['success' => false, 'message' => 'Reservation date cannot be in the past.']);
        exit();
    }

    $start_time_raw = $_POST['start_time'] ?? '';
    $end_time_raw   = $_POST['end_time']   ?? '';
    $parsed_start   = DateTime::createFromFormat('H:i', $start_time_raw);
    $parsed_end     = DateTime::createFromFormat('H:i', $end_time_raw);
    if (!$parsed_start || $parsed_start->format('H:i') !== $start_time_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid start time.']);
        exit();
    }
    if (!$parsed_end || $parsed_end->format('H:i') !== $end_time_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid end time.']);
        exit();
    }
    if ($parsed_end <= $parsed_start) {
        echo json_encode(['success' => false, 'message' => 'End time must be after start time.']);
        exit();
    }
    $start_time = $start_time_raw . ':00';
    $end_time   = $end_time_raw   . ':00';
    $reserved_start = $res_date . ' ' . $start_time;
    $reserved_end   = $res_date . ' ' . $end_time;

    $stmt_self = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = ? AND requested_by = ?
        AND status IN ('pending','approved')
        AND reserved_date = ?
        AND NOT (reserved_end <= ? OR reserved_start >= ?)");
    mysqli_stmt_bind_param($stmt_self, 'iisss', $equipment_id, $user_id, $res_date, $reserved_start, $reserved_end);
    mysqli_stmt_execute($stmt_self);
    $result_self = mysqli_stmt_get_result($stmt_self);
    $self_count  = mysqli_num_rows($result_self);
    mysqli_stmt_close($stmt_self);
    if ($self_count > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending or approved reservation for this item that overlaps with the selected time.']);
        exit();
    }

    $stmt_conflict = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = ?
        AND status = 'approved'
        AND reserved_date = ?
        AND NOT (reserved_end <= ? OR reserved_start >= ?)");
    mysqli_stmt_bind_param($stmt_conflict, 'isss', $equipment_id, $res_date, $reserved_start, $reserved_end);
    mysqli_stmt_execute($stmt_conflict);
    $result_conflict = mysqli_stmt_get_result($stmt_conflict);
    $conflict_count  = mysqli_num_rows($result_conflict);
    mysqli_stmt_close($stmt_conflict);
    if ($conflict_count > 0) {
        echo json_encode(['success' => false, 'message' => 'This equipment is already reserved during that time slot.']);
        exit();
    }

    $stmt_insert = mysqli_prepare($conn,
        "INSERT INTO reservations (equipment_id, requested_by, status, reserved_date, reserved_start, reserved_end, created_at)
         VALUES (?, ?, 'pending', ?, ?, ?, NOW())");
    mysqli_stmt_bind_param($stmt_insert, 'iisss', $equipment_id, $user_id, $res_date, $reserved_start, $reserved_end);
    if (mysqli_stmt_execute($stmt_insert)) {
        $new_reservation_id = mysqli_insert_id($conn);
        mysqli_stmt_close($stmt_insert);
        echo json_encode(['success' => true, 'reservation_id' => $new_reservation_id]);
    } else {
        $err = mysqli_stmt_error($stmt_insert);
        mysqli_stmt_close($stmt_insert);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $err]);
    }
    exit();
}

// grab search filter values
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';

$where = "WHERE status = 'Available'";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";

$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$totalQuery   = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments $where");
$totalRow     = mysqli_fetch_assoc($totalQuery);
$totalRecords = (int)$totalRow['total'];
$totalPages   = $totalRecords > 0 ? ceil($totalRecords / $limit) : 1;

if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;

$equipments_query = mysqli_query($conn, "SELECT * FROM equipments $where LIMIT $limit OFFSET $offset");

$userReservationMap = [];
$activeResStmt = mysqli_prepare($conn,
    "SELECT reservation_id, equipment_id, status
     FROM reservations
     WHERE requested_by = ?
       AND status IN ('pending', 'approved')
     ORDER BY created_at DESC"
);
mysqli_stmt_bind_param($activeResStmt, 'i', $user_id);
mysqli_stmt_execute($activeResStmt);
$activeResResult = mysqli_stmt_get_result($activeResStmt);
while ($active = mysqli_fetch_assoc($activeResResult)) {
    $eqId = (int)$active['equipment_id'];
    if (!isset($userReservationMap[$eqId])) {
        $userReservationMap[$eqId] = [
            'reservation_id' => (int)$active['reservation_id'],
            'status' => $active['status']
        ];
    }
}
mysqli_stmt_close($activeResStmt);

$queryParams  = [];
if ($search   !== '') $queryParams[] = 'search='   . urlencode($search);
if ($category !== '') $queryParams[] = 'category=' . urlencode($category);
$filterString = count($queryParams) ? '&' . implode('&', $queryParams) : '';

$totalAvailable = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available'"))['total'] ?? 0);
$itCount = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available' AND categories = 'IT Equipment'"))['total'] ?? 0);
$classroomCount = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available' AND categories = 'Classroom'"))['total'] ?? 0);
$eventsCount = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available' AND categories = 'Events Equipment'"))['total'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Reservation</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/faculty/style.css">
    <link rel="stylesheet" href="../css/faculty/dashboard.css">
    <link rel="stylesheet" href="../css/faculty/sidebar.css">
    <link rel="stylesheet" href="../css/faculty/modal.css">
    <link rel="stylesheet" href="../css/faculty/reservation.css">
    <link rel="stylesheet" href="../css/faculty/cart.css">

</head>
<body>

<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Create Reservations</h1>
        <p>Request available equipment with clear scheduling</p>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><?php echo date('l, F j, Y'); ?></span>
        <div class="profile-wrap">
            <button class="profile-btn" id="profileBtn">
                <?php echo htmlspecialchars($profileInitials); ?>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <p><?php echo htmlspecialchars(($_SESSION['full_name'] ?? '') !== '' ? ucwords(strtolower($_SESSION['full_name'])) : $name); ?></p>
                    <p>Staff</p>
                </div>
                <a href="logout.php" class="danger">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
                    Sign Out
                </a>
            </div>
        </div>
    </div>
</header>

<main class="main">
    <section class="equip-hero">
        <div class="equip-hero-copy">
            <p class="eyebrow">Faculty Reservation</p>
            <h2>Create Reservation Requests</h2>
            <p class="hero-subtitle">Browse available equipment, choose your preferred date, and submit requests for admin approval.</p>
        </div>
    </section>

    <section class="equip-metrics">
        <article class="metric-card metric-all"><div class="metric-body"><p>Available Total</p><strong><?php echo $totalAvailable; ?></strong></div></article>
        <article class="metric-card metric-available"><div class="metric-body"><p>IT Equipment</p><strong><?php echo $itCount; ?></strong></div></article>
        <article class="metric-card metric-inuse"><div class="metric-body"><p>Classroom</p><strong><?php echo $classroomCount; ?></strong></div></article>
        <article class="metric-card metric-maintenance"><div class="metric-body"><p>Events Equipment</p><strong><?php echo $eventsCount; ?></strong></div></article>
    </section>

    <!-- Cart intro hint -->
    <div class="cart-intro-banner">
        <div class="cart-intro-banner-icon">
            <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M280-80q-33 0-56.5-23.5T200-160q0-33 23.5-56.5T280-240q33 0 56.5 23.5T360-160q0 33-23.5 56.5T280-80Zm400 0q-33 0-56.5-23.5T600-160q0-33 23.5-56.5T680-240q33 0 56.5 23.5T760-160q0 33-23.5 56.5T680-80ZM246-720l96 200h280l110-200H246Zm-38-80h590q23 0 35 20.5t1 41.5L692-480q-11 20-29.5 30T622-440H324l-44 80h480v80H280q-45 0-68-39.5t-2-78.5l54-98-144-304H40v-80h130l38 80Zm134 280h280-280Z"/></svg>
        </div>
        <p><strong>New: Reservation Cart</strong> — Need multiple items for one session? Click <strong>+ Add to Cart</strong> on each item, then reserve them all at once with a single date &amp; time.</p>
    </div>

    <section class="reservation-layout">
    <div class="reservation-table-wrap">
        <h2 class="reservation-section-title">Available Equipment</h2>

        <!-- Search filters -->
        <div class="reservation-filter-wrap">
            <form method="GET" action="reservation.php" class="reservation-filter-bar">
                <div class="reservation-search-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#999"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    <input type="text" name="search" placeholder="Search equipment..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
                <select name="category">
                    <option value="">All Categories</option>
                    <option value="IT Equipment"     <?php if($category==='IT Equipment')     echo 'selected';?>>IT Equipment</option>
                    <option value="Classroom"        <?php if($category==='Classroom')        echo 'selected';?>>Classroom</option>
                    <option value="Events Equipment" <?php if($category==='Events Equipment') echo 'selected';?>>Events Equipment</option>
                </select>
                <button type="submit" class="reservation-btn-search">Search</button>
                <?php if ($search!==''||$category!==''): ?>
                    <a href="reservation.php" class="reservation-btn-clear">&#x2715; Clear</a>
                <?php endif; ?>
            </form>
            <p class="reservation-result-count">
                <?php if ($search!==''||$category!==''): ?>
                    Showing <strong><?php echo $totalRecords;?></strong> result<?php echo $totalRecords!=1?'s':'';?>
                    <?php if($search!==''): ?> for <strong>"<?php echo htmlspecialchars($search);?>"</strong><?php endif;?>
                <?php else: ?>
                    <strong><?php echo $totalRecords;?></strong> available equipment<?php echo $totalRecords!=1?'s':'';?> found
                <?php endif;?>
            </p>
        </div>

        <div class="reservation-table-scroll">
        <table class="reservation-data-table" width="100%" cellpadding="10" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Resource Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php if (mysqli_num_rows($equipments_query) === 0): ?>
            <tr>
                <td colspan="5" class="reservation-empty-state">No available equipment found.</td>
            </tr>
            <?php else: ?>
            <?php while($row = mysqli_fetch_assoc($equipments_query)): ?>
            <?php
                $equipmentId  = (int)$row['equipment_id'];
                $currentUserRes = $userReservationMap[$equipmentId] ?? null;
                $resourceName = htmlspecialchars(addslashes($row['resource_name']));
                $categoryName = htmlspecialchars(addslashes($row['categories']));
            ?>
            <tr>
                <td><?php echo $row['equipment_id'];?></td>
                <td><?php echo htmlspecialchars($row['resource_name']);?></td>
                <td>
                    <span class="category-badge category-<?php echo strtolower(str_replace(' ','-',$row['categories']));?>">
                        <?php echo htmlspecialchars($row['categories']);?>
                    </span>
                </td>
                <td class="status <?php echo strtolower(str_replace(' ','-',$row['status']));?>">
                    <?php echo strtoupper($row['status']);?>
                </td>
                <td class="actions">
                    <?php if ($currentUserRes && $currentUserRes['status'] === 'pending'): ?>
                        <button class="reservation-btn-cancel"
                            onclick="cancelExistingReservation(<?php echo $currentUserRes['reservation_id']; ?>, '<?php echo $resourceName; ?>')">
                            Cancel
                        </button>
                    <?php elseif ($currentUserRes): ?>
                        <span class="reservation-pill-active">Already Reserved</span>
                    <?php else: ?>
                        <!-- Add to Cart button -->
                        <button class="btn-add-cart"
                            id="cartbtn-<?php echo $equipmentId;?>"
                            data-id="<?php echo $equipmentId; ?>"
                            data-name="<?php echo $resourceName; ?>"
                            data-category="<?php echo $categoryName; ?>"
                            onclick="toggleCart(<?php echo $equipmentId;?>, '<?php echo $resourceName;?>', '<?php echo $categoryName;?>')">
                            <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M440-280h80v-160h160v-80H520v-160h-80v160H280v80h160v160Zm40 200q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                            Add to Cart
                        </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endwhile;?>
            <?php endif;?>
        </table>
        </div>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page-1;?><?php echo $filterString;?>">&laquo; Prev</a>
            <?php endif;?>
            <?php for ($i=1;$i<=$totalPages;$i++): ?>
                <a href="?page=<?php echo $i;?><?php echo $filterString;?>" class="<?php echo($i==$page)?'active':'';?>"><?php echo $i;?></a>
            <?php endfor;?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page+1;?><?php echo $filterString;?>">Next &raquo;</a>
            <?php endif;?>
        </div>
        <?php endif;?>
    </div>
    </section>

    <section class="reservation-help">
        <div class="reservation-guide-card">
            <h3>How Reservations Work</h3>
            <p>Use <strong>Add to Cart</strong> to queue multiple items (e.g. projector + HDMI cable), then reserve them all in one go — same date and time for the whole bundle.</p>
            <ul>
                <li>Only available equipment can be requested.</li>
                <li>Pick an upcoming date; past dates are blocked.</li>
                <li>All cart items share the same date &amp; time slot.</li>
                <li>Track approval outcomes in My Reservations.</li>
            </ul>
        </div>
        <div class="reservation-actions-card">
            <h3>Quick Actions</h3>
            <div class="reservation-action-list">
                <a href="my_reservations.php">Open My Reservations</a>
                <a href="dashboard.php">Back to Dashboard</a>
            </div>
        </div>
    </section>
</main>

<!-- ════════════════════════════════════════════════
     FLOATING CART BUBBLE
════════════════════════════════════════════════ -->
<button id="cartBubble" onclick="openCartDrawer()" aria-label="View reservation cart">
    <span class="cart-bubble-icon">
        <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M280-80q-33 0-56.5-23.5T200-160q0-33 23.5-56.5T280-240q33 0 56.5 23.5T360-160q0 33-23.5 56.5T280-80Zm400 0q-33 0-56.5-23.5T600-160q0-33 23.5-56.5T680-240q33 0 56.5 23.5T760-160q0 33-23.5 56.5T680-80ZM246-720l96 200h280l110-200H246Zm-38-80h590q23 0 35 20.5t1 41.5L692-480q-11 20-29.5 30T622-440H324l-44 80h480v80H280q-45 0-68-39.5t-2-78.5l54-98-144-304H40v-80h130l38 80Zm134 280h280-280Z"/></svg>
    </span>
    Reservation Cart
    <span class="cart-bubble-count" id="cartBubbleCount">0</span>
</button>

<!-- ════════════════════════════════════════════════
     CART DRAWER
════════════════════════════════════════════════ -->
<div id="cartDrawer">
    <div class="cart-backdrop" onclick="closeCartDrawer()"></div>
    <div class="cart-panel">
        <!-- Header -->
        <div class="cart-panel-header">
            <div class="cart-panel-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="M280-80q-33 0-56.5-23.5T200-160q0-33 23.5-56.5T280-240q33 0 56.5 23.5T360-160q0 33-23.5 56.5T280-80Zm400 0q-33 0-56.5-23.5T600-160q0-33 23.5-56.5T680-240q33 0 56.5 23.5T760-160q0 33-23.5 56.5T680-80ZM246-720l96 200h280l110-200H246Zm-38-80h590q23 0 35 20.5t1 41.5L692-480q-11 20-29.5 30T622-440H324l-44 80h480v80H280q-45 0-68-39.5t-2-78.5l54-98-144-304H40v-80h130l38 80Zm134 280h280-280Z"/></svg>
            </div>
            <div class="cart-panel-header-text">
                <h2>Reservation Cart</h2>
                <p id="cartDrawerSubtitle">0 items queued for reservation</p>
            </div>
            <button class="cart-panel-close" onclick="closeCartDrawer()" aria-label="Close cart">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <!-- Items list -->
        <div class="cart-items-list" id="cartItemsList">
            <div class="cart-empty-state" id="cartEmptyState">
                <svg xmlns="http://www.w3.org/2000/svg" height="52px" viewBox="0 -960 960 960" width="52px" fill="currentColor"><path d="M280-80q-33 0-56.5-23.5T200-160q0-33 23.5-56.5T280-240q33 0 56.5 23.5T360-160q0 33-23.5 56.5T280-80Zm400 0q-33 0-56.5-23.5T600-160q0-33 23.5-56.5T680-240q33 0 56.5 23.5T760-160q0 33-23.5 56.5T680-80ZM246-720l96 200h280l110-200H246Zm-38-80h590q23 0 35 20.5t1 41.5L692-480q-11 20-29.5 30T622-440H324l-44 80h480v80H280q-45 0-68-39.5t-2-78.5l54-98-144-304H40v-80h130l38 80Zm134 280h280-280Z"/></svg>
                <div>
                    <strong>Your cart is empty</strong>
                    <p>Click <em>+ Add to Cart</em> on any equipment to queue it here. Then reserve everything at once.</p>
                </div>
            </div>
        </div>

        <!-- Footer -->
        <div class="cart-panel-footer">
            <div class="cart-summary-row">
                <span>Items queued</span>
                <strong id="cartFooterCount">0 items</strong>
            </div>
            <div style="display:flex;gap:8px;">
                <button class="cart-clear-btn" onclick="clearCart()">Clear all</button>
                <button class="cart-checkout-btn" id="cartCheckoutBtn" onclick="openCartReserveModal()" disabled>
                    <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
                    Reserve All
                    <span class="cart-items-count-badge" id="cartCheckoutCount">0</span>
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════
     CART RESERVATION MODAL
════════════════════════════════════════════════ -->
<div id="cartReserveModal">
    <div class="crm-box">
        <!-- Header -->
        <div class="crm-header">
            <div class="crm-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
            </div>
            <div class="crm-header-text">
                <h3>Reserve All Items</h3>
                <p id="crmSubtitle">Set one date &amp; time for your entire cart</p>
            </div>
            <button class="crm-close" onclick="closeCartReserveModal()">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <!-- Body -->
        <div class="crm-body" id="crmBody">

            <!-- Items summary -->
            <div class="crm-items-summary">
                <p class="crm-items-summary-label">Items in this reservation</p>
                <div id="crmItemsList"><!-- populated by JS --></div>
            </div>

            <div class="crm-divider"></div>
            <p class="crm-section-label">Schedule — applies to all items</p>

            <!-- Date + Times -->
            <div class="crm-schedule-grid">
                <div class="crm-field">
                    <label>
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Z"/></svg>
                        Date
                    </label>
                    <input type="date" id="crmDate" min="<?php echo date('Y-m-d');?>" required>
                </div>
                <div class="crm-field">
                    <label>
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm28 8-20-20v-208h-80v240l168 168 56-56-124-124Z"/></svg>
                        Start
                    </label>
                    <input type="time" id="crmStart" required>
                </div>
                <div class="crm-time-arrow">
                    <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="#ddd"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                </div>
                <div class="crm-field">
                    <label>
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0 160q-17 0-28.5-11.5T440-360v-200h-80v-80h160v280q0 17-11.5 28.5T480-320Z"/></svg>
                        End
                    </label>
                    <input type="time" id="crmEnd" required>
                </div>
            </div>

            <!-- Progress bar (shown during submission) -->
            <div class="crm-progress" id="crmProgressWrap" style="display:none;">
                <div class="crm-progress-bar-wrap"><div class="crm-progress-bar" id="crmProgressBar"></div></div>
                <div class="crm-progress-label"><span id="crmProgressLabel">Submitting…</span><span id="crmProgressFraction"></span></div>
            </div>

            <!-- Hint -->
            <div class="crm-hint">
                <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="#C8102E"><path d="M480-280q17 0 28.5-11.5T520-320v-160q0-17-11.5-28.5T480-520q-17 0-28.5 11.5T440-480v160q0 17 11.5 28.5T480-280Zm0-320q17 0 28.5-11.5T520-640q0-17-11.5-28.5T480-680q-17 0-28.5 11.5T440-640q0 17 11.5 28.5T480-600Zm0 520q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
                All items in your cart will be requested for the same date &amp; time slot. Requests are subject to admin approval.
            </div>

            <p id="crmMsg" class="crm-msg"></p>
        </div>

        <!-- Footer -->
        <div class="crm-footer">
            <button type="button" class="crm-btn-cancel" onclick="closeCartReserveModal()">Cancel</button>
            <button type="button" class="crm-btn-submit" id="crmSubmitBtn" onclick="submitCartReservation()">
                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                Confirm Reservation
            </button>
        </div>
    </div>
</div>

<!-- Single-item Reserve Modal (kept for compatibility) -->
<div class="modal-overlay" id="reserveModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
            </div>
            <div>
                <h3 id="modalTitle">Reserve Item</h3>
                <p id="modalSubtitle" class="modal-subtitle">Submit a reservation request</p>
            </div>
            <button class="modal-close-btn" onclick="closeReserveModal()" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>
        <div class="modal-body">
            <div class="modal-info-row">
                <div class="modal-info-chip"><label>Equipment</label><p id="modalEquipmentName">—</p></div>
                <div class="modal-info-chip"><label>Category</label><p id="modalCategory">—</p></div>
                <div class="modal-info-chip"><label>Status</label><p class="modal-status-badge">AVAILABLE</p></div>
            </div>
            <div class="modal-divider"></div>
            <p class="modal-section-label">Schedule</p>
            <div class="modal-schedule-row">
                <div class="modal-field">
                    <label for="reserveDate">
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C40C0C"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Z"/></svg>
                        Date
                    </label>
                    <input type="date" id="reserveDate" min="<?php echo date('Y-m-d');?>" required>
                </div>
                <div class="modal-field">
                    <label for="reserveStartTime">
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C40C0C"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm28 8-20-20v-208h-80v240l168 168 56-56-124-124Z"/></svg>
                        Start
                    </label>
                    <input type="time" id="reserveStartTime" required>
                </div>
                <div class="modal-time-arrow">
                    <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="#ccc"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                </div>
                <div class="modal-field">
                    <label for="reserveEndTime">
                        <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C40C0C"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0 160q-17 0-28.5-11.5T440-360v-200h-80v-80h160v280q0 17-11.5 28.5T480-320Z"/></svg>
                        End
                    </label>
                    <input type="time" id="reserveEndTime" required>
                </div>
            </div>
            <div class="modal-hint">
                <svg xmlns="http://www.w3.org/2000/svg" height="13px" viewBox="0 -960 960 960" width="13px" fill="#C40C0C"><path d="M480-280q17 0 28.5-11.5T520-320v-160q0-17-11.5-28.5T480-520q-17 0-28.5 11.5T440-480v160q0 17 11.5 28.5T480-280Zm0-320q17 0 28.5-11.5T520-640q0-17-11.5-28.5T480-680q-17 0-28.5 11.5T440-640q0 17 11.5 28.5T480-600Zm0 520q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
                Requests are subject to admin approval. Select your date and time carefully.
            </div>
            <p id="modalMsg" class="modal-msg"></p>
        </div>
        <div class="modal-footer">
            <button type="button" class="modal-btn-cancel" onclick="closeReserveModal()">Cancel</button>
            <button type="button" class="modal-btn-submit" id="submitReserveBtn" onclick="submitReservation()">
                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Success toast -->
<div class="toast" id="successToast">
    <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
    <span id="toastMsg">Reservation submitted! Awaiting admin approval.</span>
</div>

<!-- Confirm action modal (kept) -->
<div class="modal-overlay" id="confirmActionModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeConfirmActionModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
            <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#EA3323"><path d="m40-120 440-760 440 760H40Zm138-80h604L480-720 178-200Zm330.5-51.5Q520-263 520-280t-11.5-28.5Q497-320 480-320t-28.5 11.5Q440-297 440-280t11.5 28.5Q463-240 480-240t28.5-11.5ZM440-360h80v-200h-80v200Zm40-100Z"/></svg></span>
        </div>
        <h3 id="confirmActionTitle">Are you sure?</h3>
        <p id="confirmActionMsg" class="confirm-body"></p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="confirmActionProceedBtn">Continue</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeConfirmActionModal()">Cancel</button>
        </div>
    </div>
</div>

<script>

// profile dropdown
const profileBtn = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('open'); });
document.addEventListener('click', () => { profileDropdown.classList.remove('open'); });

// confirm modal
let _confirmActionCallback = null;
function openConfirmActionModal(message, onConfirm) {
    _confirmActionCallback = onConfirm;
    document.getElementById('confirmActionTitle').textContent = 'Are you sure?';
    document.getElementById('confirmActionMsg').textContent = message;
    document.querySelector('#confirmActionModal .confirm-btn-secondary').style.display = 'inline-flex';
    document.getElementById('confirmActionProceedBtn').textContent = 'Continue';
    document.getElementById('confirmActionProceedBtn').className = 'confirm-btn-danger';
    document.getElementById('confirmActionModal').classList.add('active');
}
function openInfoActionModal(message) {
    _confirmActionCallback = null;
    document.getElementById('confirmActionTitle').textContent = 'Please check this';
    document.getElementById('confirmActionMsg').textContent = message;
    document.querySelector('#confirmActionModal .confirm-btn-secondary').style.display = 'none';
    document.getElementById('confirmActionProceedBtn').textContent = 'Close';
    document.getElementById('confirmActionProceedBtn').className = 'confirm-btn-secondary';
    document.getElementById('confirmActionModal').classList.add('active');
}
function closeConfirmActionModal() {
    document.getElementById('confirmActionModal').classList.remove('active');
    _confirmActionCallback = null;
}
document.getElementById('confirmActionModal').addEventListener('click', function(e) { if (e.target === this) closeConfirmActionModal(); });
document.getElementById('confirmActionProceedBtn').addEventListener('click', function() {
    if (typeof _confirmActionCallback === 'function') { const cb = _confirmActionCallback; closeConfirmActionModal(); cb(); }
    else closeConfirmActionModal();
});

// cart state saved across reloads
function loadCart() {
    try { return JSON.parse(localStorage.getItem('reservationCart') || '[]'); } catch(e) { return []; }
}
function saveCart(c) {
    try { localStorage.setItem('reservationCart', JSON.stringify(c)); } catch(e) {}
}
let cart = loadCart(); // [{id, name, category}]

function getCartIndex(id) {
    return cart.findIndex(item => item.id === id);
}

function updateCartUI() {
    saveCart(cart);
    const count = cart.length;

    // update cart bubble count
    const bubble = document.getElementById('cartBubble');
    document.getElementById('cartBubbleCount').textContent = count;
    if (count > 0) bubble.classList.add('visible');
    else bubble.classList.remove('visible');

    // update cart drawer subtitle
    document.getElementById('cartDrawerSubtitle').textContent = count + ' item' + (count !== 1 ? 's' : '') + ' queued for reservation';
    document.getElementById('cartFooterCount').textContent = count + ' item' + (count !== 1 ? 's' : '');

    // update checkout button
    const checkoutBtn = document.getElementById('cartCheckoutBtn');
    checkoutBtn.disabled = count === 0;
    document.getElementById('cartCheckoutCount').textContent = count;

    // update items list
    const listEl = document.getElementById('cartItemsList');
    const emptyEl = document.getElementById('cartEmptyState');

    // clear old cards
    listEl.querySelectorAll('.cart-item-card').forEach(el => el.remove());
    if (count === 0) {
        emptyEl.style.display = 'flex';
    } else {
        emptyEl.style.display = 'none';
        cart.forEach((item, idx) => {
            const card = document.createElement('div');
            card.className = 'cart-item-card';
            card.id = 'cart-card-' + item.id;
            card.innerHTML = `
                <div class="cart-item-num">${idx + 1}</div>
                <div class="cart-item-info">
                    <p class="cart-item-name">${escHtml(item.name)}</p>
                    <span class="cart-item-category">${escHtml(item.category)}</span>
                </div>
                <button class="cart-item-remove" onclick="removeFromCart(${item.id})" title="Remove">
                    <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Zm400-600H280v520h400v-520ZM360-280h80v-360h-80v360Zm160 0h80v-360h-80v360ZM280-720v520-520Z"/></svg>
                </button>
            `;
            listEl.appendChild(card);
        });
    }
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function toggleCart(id, name, category) {
    const idx = getCartIndex(id);
    const btn = document.getElementById('cartbtn-' + id);
    if (idx === -1) {
        cart.push({ id, name, category });
        if (btn) {
            btn.classList.add('in-cart');
            btn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                In Cart`;
        }
    } else {
        cart.splice(idx, 1);
        if (btn) {
            btn.classList.remove('in-cart');
            btn.innerHTML = `
                <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M440-280h80v-160h160v-80H520v-160h-80v160H280v80h160v160Zm40 200q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                Add to Cart`;
        }
    }
    updateCartUI();
}

function removeFromCart(id) {
    const idx = getCartIndex(id);
    if (idx !== -1) cart.splice(idx, 1);

    // reset the add button on the table
    const btn = document.getElementById('cartbtn-' + id);
    if (btn) {
        btn.classList.remove('in-cart');
        btn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M440-280h80v-160h160v-80H520v-160h-80v160H280v80h160v160Zm40 200q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
            Add to Cart`;
    }
    updateCartUI();
}

function clearCart() {
    if (cart.length === 0) return;
    openConfirmActionModal('Remove all ' + cart.length + ' items from your cart?', function() {
        // reset all add buttons
        cart.forEach(item => {
            const btn = document.getElementById('cartbtn-' + item.id);
            if (btn) {
                btn.classList.remove('in-cart');
                btn.innerHTML = `
                    <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M440-280h80v-160h160v-80H520v-160h-80v160H280v80h160v160Zm40 200q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                    Add to Cart`;
            }
        });
        cart = [];
        saveCart(cart);
        updateCartUI();
    });
}

// cart drawer open/close
function openCartDrawer() {
    document.getElementById('cartDrawer').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeCartDrawer() {
    document.getElementById('cartDrawer').classList.remove('open');
    document.body.style.overflow = '';
}

// cart reserve modal
function openCartReserveModal() {
    if (cart.length === 0) return;
    closeCartDrawer();

    // reset modal body if it was replaced
    document.getElementById('crmBody').innerHTML = `
        <div class="crm-items-summary">
            <p class="crm-items-summary-label">Items in this reservation</p>
            <div id="crmItemsList"></div>
        </div>
        <div class="crm-divider"></div>
        <p class="crm-section-label">Schedule — applies to all items</p>
        <div class="crm-schedule-grid">
            <div class="crm-field">
                <label>
                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Z"/></svg>
                    Date
                </label>
                <input type="date" id="crmDate" min="${new Date().toISOString().split('T')[0]}" required>
            </div>
            <div class="crm-field">
                <label>
                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm28 8-20-20v-208h-80v240l168 168 56-56-124-124Z"/></svg>
                    Start
                </label>
                <input type="time" id="crmStart" required>
            </div>
            <div class="crm-time-arrow">
                <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="#ddd"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
            </div>
            <div class="crm-field">
                <label>
                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="#C8102E"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0 160q-17 0-28.5-11.5T440-360v-200h-80v-80h160v280q0 17-11.5 28.5T480-320Z"/></svg>
                    End
                </label>
                <input type="time" id="crmEnd" required>
            </div>
        </div>
        <div class="crm-progress" id="crmProgressWrap" style="display:none;">
            <div class="crm-progress-bar-wrap"><div class="crm-progress-bar" id="crmProgressBar"></div></div>
            <div class="crm-progress-label"><span id="crmProgressLabel">Submitting…</span><span id="crmProgressFraction"></span></div>
        </div>
        <div class="crm-hint">
            <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="#C8102E"><path d="M480-280q17 0 28.5-11.5T520-320v-160q0-17-11.5-28.5T480-520q-17 0-28.5 11.5T440-480v160q0 17 11.5 28.5T480-280Zm0-320q17 0 28.5-11.5T520-640q0-17-11.5-28.5T480-680q-17 0-28.5 11.5T440-640q0 17 11.5 28.5T480-600Zm0 520q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            All items in your cart will be requested for the same date &amp; time slot. Requests are subject to admin approval.
        </div>
        <p id="crmMsg" class="crm-msg"></p>
    `;

    // reset footer buttons
    document.querySelector('.crm-footer').innerHTML = `
        <button type="button" class="crm-btn-cancel" onclick="closeCartReserveModal()">Cancel</button>
        <button type="button" class="crm-btn-submit" id="crmSubmitBtn" onclick="submitCartReservation()">
            <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
            Confirm Reservation
        </button>
    `;

    // fill in the items list
    const listEl = document.getElementById('crmItemsList');
    cart.forEach(item => {
        const pill = document.createElement('div');
        pill.className = 'crm-item-pill';
        pill.innerHTML = `
            <span class="crm-item-pill-dot"></span>
            <span>${escHtml(item.name)}</span>
            <span class="crm-item-pill-cat">${escHtml(item.category)}</span>
        `;
        listEl.appendChild(pill);
    });

    document.getElementById('crmSubtitle').textContent = 'Set one date & time for all ' + cart.length + ' items';

    document.getElementById('cartReserveModal').classList.add('active');
}

function closeCartReserveModal() {
    document.getElementById('cartReserveModal').classList.remove('active');
}

document.getElementById('cartReserveModal').addEventListener('click', function(e) {
    if (e.target === this) closeCartReserveModal();
});

function submitCartReservation() {
    const dateVal  = document.getElementById('crmDate').value;
    const startVal = document.getElementById('crmStart').value;
    const endVal   = document.getElementById('crmEnd').value;
    const msgEl    = document.getElementById('crmMsg');

    if (!dateVal)  { msgEl.textContent = 'Please select a date.';        document.getElementById('crmDate').focus();  return; }
    if (!startVal) { msgEl.textContent = 'Please select a start time.';  document.getElementById('crmStart').focus(); return; }
    if (!endVal)   { msgEl.textContent = 'Please select an end time.';   document.getElementById('crmEnd').focus();   return; }
    if (endVal <= startVal) { msgEl.textContent = 'End time must be after start time.'; document.getElementById('crmEnd').focus(); return; }

    msgEl.textContent = '';
    const submitBtn = document.getElementById('crmSubmitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor" style="animation:spin 0.8s linear infinite"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q17 0 28.5 11.5T520-840q0 17-11.5 28.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160q133 0 226.5-93.5T800-480q0-17 11.5-28.5T840-520q17 0 28.5 11.5T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg> Submitting…`;

    // show the progress bar
    const progressWrap = document.getElementById('crmProgressWrap');
    const progressBar  = document.getElementById('crmProgressBar');
    const progressLbl  = document.getElementById('crmProgressLabel');
    const progressFrac = document.getElementById('crmProgressFraction');
    progressWrap.style.display = '';
    progressLbl.textContent = 'Submitting reservations…';
    progressFrac.textContent = '0 / ' + cart.length;

    const form = new FormData();
    form.append('res_date',    dateVal);
    form.append('start_time',  startVal);
    form.append('end_time',    endVal);
    form.append('cart_items',  JSON.stringify(cart));
    form.append('submit_cart', '1');
    form.append('csrf_token',  document.querySelector('meta[name="csrf-token"]').content);

    // animate the bar while waiting
    let fakeProgress = 0;
    const progressInterval = setInterval(() => {
        if (fakeProgress < 80) { fakeProgress += 8; progressBar.style.width = fakeProgress + '%'; }
    }, 180);

    fetch('reservation.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            clearInterval(progressInterval);
            progressBar.style.width = '100%';
            progressFrac.textContent = (data.inserted ? Object.keys(data.inserted).length : 0) + ' / ' + cart.length;

            if (data.success) {
                const insertedIds = data.inserted || {};
                const successCount = Object.keys(insertedIds).length;
                const successItems = cart.filter(item => insertedIds[item.id] !== undefined);
                const failedItems  = data.errors || [];

                setTimeout(() => {
                    // Update row buttons for successfully reserved items
                    successItems.forEach(item => {
                        const btn = document.getElementById('cartbtn-' + item.id);
                        if (btn) {
                            const resId = insertedIds[item.id];
                            const td = btn.closest('td');
                            if (td && resId) {
                                td.innerHTML = `<button class="reservation-btn-cancel" onclick="cancelExistingReservation(${resId}, '${item.name.replace(/'/g,"\\'")}')" >Cancel</button>`;
                            }
                        }
                    });

                    // Remove successfully reserved items from cart
                    cart = cart.filter(item => insertedIds[item.id] === undefined);
                    saveCart(cart);
                    updateCartUI();

                    // Show success in modal
                    showCartSuccess(successItems, failedItems);
                }, 400);
            } else {
                progressWrap.style.display = 'none';
                msgEl.textContent = data.message || (data.errors || []).join(' | ') || 'Failed to submit reservations.';
                submitBtn.disabled = false;
                submitBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Confirm Reservation`;
            }
        })
        .catch(() => {
            clearInterval(progressInterval);
            progressWrap.style.display = 'none';
            msgEl.textContent = 'An error occurred. Please try again.';
            submitBtn.disabled = false;
            submitBtn.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Confirm Reservation`;
        });
}

function showCartSuccess(successItems, failedErrors) {
    const body   = document.getElementById('crmBody');
    const footer = document.querySelector('.crm-footer');

    const successRowsHtml = successItems.map(item => `
        <div class="crm-success-item-row">
            <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Z"/></svg>
            ${escHtml(item.name)}
        </div>
    `).join('');

    const errorHtml = failedErrors.length > 0
        ? `<div style="margin-top:8px;background:#fff8f8;border:1px solid #ffd9d9;border-radius:9px;padding:8px 11px;font-size:0.78rem;color:#C8102E;">
               <strong>Some items had issues:</strong><br>${failedErrors.map(e => escHtml(e)).join('<br>')}
           </div>` : '';

    body.innerHTML = `
        <div class="crm-success-view">
            <div class="crm-success-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="32px" viewBox="0 -960 960 960" width="32px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <h3>${successItems.length === 1 ? '1 Item Reserved!' : successItems.length + ' Items Reserved!'}</h3>
            <p>Your reservation request${successItems.length !== 1 ? 's are' : ' is'} pending admin approval. You can track them in <strong>My Reservations</strong>.</p>
            <div class="crm-success-items">${successRowsHtml}</div>
            ${errorHtml}
        </div>
    `;
    footer.innerHTML = `
        <button type="button" class="crm-btn-cancel" onclick="closeCartReserveModal()">Close</button>
        <a href="my_reservations.php" style="display:flex;align-items:center;gap:6px;padding:9px 20px;border:none;border-radius:10px;background:linear-gradient(135deg,#C8102E,#9b0b22);color:#fff;font-size:0.85rem;font-weight:800;font-family:inherit;cursor:pointer;text-decoration:none;box-shadow:0 2px 10px rgba(155,11,34,.28);">
            <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-840h560q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-480H200v480Zm80-80h400v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Z"/></svg>
            View My Reservations
        </a>
    `;

    showToast(successItems.length + ' reservation' + (successItems.length !== 1 ? 's' : '') + ' submitted! Awaiting approval.');
}

// old single item modal
let _reserveId = null;
const reserveDateInput = document.getElementById('reserveDate');

function triggerDatePicker() {
    if (!reserveDateInput) return;
    if (typeof reserveDateInput.showPicker === 'function') { try { reserveDateInput.showPicker(); } catch (_) {} }
}

function openReserveModal(id, name, category) {
    _reserveId = id;
    document.getElementById('modalEquipmentName').textContent = name;
    document.getElementById('modalCategory').textContent = category;
    document.getElementById('modalTitle').textContent = 'Reserve: ' + name;
    document.getElementById('reserveDate').value = '';
    document.getElementById('reserveStartTime').value = '';
    document.getElementById('reserveEndTime').value = '';
    document.getElementById('modalMsg').textContent = '';
    const btn = document.getElementById('submitReserveBtn');
    btn.disabled = false;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
    document.getElementById('reserveModal').classList.add('active');
}
function closeReserveModal() {
    document.getElementById('reserveModal').classList.remove('active');
    _reserveId = null;
}
document.getElementById('reserveModal').addEventListener('click', function(e) { if (e.target === this) closeReserveModal(); });

if (reserveDateInput) {
    reserveDateInput.addEventListener('click', triggerDatePicker);
    reserveDateInput.addEventListener('keydown', function(e) { if (e.key === 'Enter' || e.key === ' ') triggerDatePicker(); });
}
['reserveStartTime','reserveEndTime'].forEach(function(id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('click', function() { if (typeof el.showPicker==='function') { try{el.showPicker();}catch(_){} } });
});

function submitReservation() {
    const dateVal  = document.getElementById('reserveDate').value;
    const startVal = document.getElementById('reserveStartTime').value;
    const endVal   = document.getElementById('reserveEndTime').value;
    const msgEl    = document.getElementById('modalMsg');
    if (!dateVal)  { msgEl.textContent = 'Please select a date.';       document.getElementById('reserveDate').focus();      return; }
    if (!startVal) { msgEl.textContent = 'Please select a start time.'; document.getElementById('reserveStartTime').focus(); return; }
    if (!endVal)   { msgEl.textContent = 'Please select an end time.';  document.getElementById('reserveEndTime').focus();   return; }
    if (endVal <= startVal) { msgEl.textContent = 'End time must be after start time.'; document.getElementById('reserveEndTime').focus(); return; }

    const btn = document.getElementById('submitReserveBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor" style="animation:spin 0.8s linear infinite"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q17 0 28.5 11.5T520-840q0 17-11.5 28.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160q133 0 226.5-93.5T800-480q0-17 11.5-28.5T840-520q17 0 28.5 11.5T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg> Submitting…';
    msgEl.textContent = '';
    const currentEquipId = _reserveId;
    const form = new FormData();
    form.append('equipment_id', currentEquipId);
    form.append('res_date', dateVal);
    form.append('start_time', startVal);
    form.append('end_time', endVal);
    form.append('submit_res', '1');
    form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    fetch('reservation.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeReserveModal();
                showToast('Reservation submitted! Awaiting admin approval.');
                const reserveBtn = document.getElementById('cartbtn-' + currentEquipId);
                if (reserveBtn) {
                    const resId = data.reservation_id ?? null;
                    const equipName = reserveBtn.getAttribute('data-name') || '';
                    const td = reserveBtn.closest('td');
                    if (td && resId) {
                        td.innerHTML = `<button class="reservation-btn-cancel" onclick="cancelExistingReservation(${resId}, '${equipName.replace(/'/g,"\\'")}')">Cancel</button>`;
                    }
                }
            } else {
                msgEl.textContent = data.message || 'Failed to submit reservation.';
                btn.disabled = false;
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
            }
        })
        .catch(() => {
            msgEl.textContent = 'An error occurred. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
        });
}

// cancel reservation
function cancelExistingReservation(reservationId, equipmentName) {
    if (!reservationId) return;
    openConfirmActionModal('Cancel reservation for "' + equipmentName + '"?', function() {
        const form = new FormData();
        form.append('reservation_id', reservationId);
        form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        fetch('cancel_reservation.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (!data.success) { openInfoActionModal(data.message || 'Could not cancel reservation.'); return; }
                window.location.reload();
            })
            .catch(() => { openInfoActionModal('An error occurred while cancelling. Please try again.'); });
    });
}

// show toast message
function showToast(msg) {
    const t = document.getElementById('successToast');
    document.getElementById('toastMsg').textContent = msg || 'Reservation submitted!';
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3800);
}

// restore cart button states on load
cart.forEach(item => {
    const btn = document.getElementById('cartbtn-' + item.id);
    if (btn) {
        btn.classList.add('in-cart');
        btn.innerHTML = `
            <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
            In Cart`;
    }
});
updateCartUI();
</script>

</body>
</html>