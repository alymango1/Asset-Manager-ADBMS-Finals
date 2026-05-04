<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}


// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// User name
$name = 'User';
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(' ', trim($_SESSION['full_name']));
    $name = $nameParts[0];
} elseif (isset($_SESSION['username'])) {
    $name = $_SESSION['username'];
}

// Display name + initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$firstNameRaw = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw)[0] : 'User';
$name = ucfirst(strtolower($firstNameRaw));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$user_id = $_SESSION['user_id'];

// Handle reservation request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_res'])) {
    header('Content-Type: application/json');

    // CSRF check
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (empty($token) || !hash_equals($_SESSION['csrf_token'], $token)) {
        echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
        exit();
    }

    $equipment_id = (int)$_POST['equipment_id'];
    $res_date_raw = $_POST['res_date'] ?? '';
    // Validate date format
    $parsed_date  = DateTime::createFromFormat('Y-m-d', $res_date_raw);
    if (!$parsed_date || $parsed_date->format('Y-m-d') !== $res_date_raw) {
        echo json_encode(['success' => false, 'message' => 'Invalid date format.']);
        exit();
    }
    $res_date = $res_date_raw; // safe, validated

    $today = date('Y-m-d');
    if ($res_date < $today) {
        echo json_encode(['success' => false, 'message' => 'Reservation date cannot be in the past.']);
        exit();
    }

    // Prevent duplicate user booking
    $stmt_self = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = ? AND requested_by = ?
        AND status IN ('pending','approved')
        AND reserved_date = ?");
    mysqli_stmt_bind_param($stmt_self, 'iis', $equipment_id, $user_id, $res_date);
    mysqli_stmt_execute($stmt_self);
    $result_self = mysqli_stmt_get_result($stmt_self);
    $self_count  = mysqli_num_rows($result_self);
    mysqli_stmt_close($stmt_self);

    if ($self_count > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending or approved reservation for this item on that date.']);
        exit();
    }

    // Prevent booking if already approved
    $stmt_conflict = mysqli_prepare($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = ?
        AND status = 'approved'
        AND reserved_date = ?");
    mysqli_stmt_bind_param($stmt_conflict, 'is', $equipment_id, $res_date);
    mysqli_stmt_execute($stmt_conflict);
    $result_conflict = mysqli_stmt_get_result($stmt_conflict);
    $conflict_count  = mysqli_num_rows($result_conflict);
    mysqli_stmt_close($stmt_conflict);

    if ($conflict_count > 0) {
        echo json_encode(['success' => false, 'message' => 'This equipment is already reserved by someone else on that date. Please choose a different date or item.']);
        exit();
    }

    $stmt_insert = mysqli_prepare($conn,
        "INSERT INTO reservations (equipment_id, requested_by, status, reserved_date, created_at)
         VALUES (?, ?, 'pending', ?, NOW())");
    mysqli_stmt_bind_param($stmt_insert, 'iis', $equipment_id, $user_id, $res_date);

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

// Search filters
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';

$where = "WHERE status = 'Available'";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";

// Pagination
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

// Track user active reservations
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
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
        <link rel="stylesheet" href="../css/faculty/style.css">
    <link rel="stylesheet" href="../css/faculty/dashboard.css">
    <link rel="stylesheet" href="../css/faculty/sidebar.css">
    <link rel="stylesheet" href="../css/faculty/modal.css">
    <link rel="stylesheet" href="../css/faculty/reservation.css">
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
            <p class="eyebrow">Faculty Reservation </p>
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
                $equipmentId = (int)$row['equipment_id'];
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
                        <button class="reservation-btn-reserve"
                            data-id="<?php echo $equipmentId; ?>"
                            data-name="<?php echo $resourceName; ?>"
                            onclick="openReserveModal(<?php echo $equipmentId;?>, '<?php echo $resourceName;?>', '<?php echo $categoryName;?>')">
                            <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
                            Reserve
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
            <p>Click reserve on any available item, choose the date needed, and submit for admin approval.</p>
            <ul>
                <li>Only available equipment can be requested.</li>
                <li>Pick an upcoming date; past dates are blocked.</li>
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

<!-- Reserve modal -->
<div class="modal-overlay" id="reserveModal">
    <div class="modal-box">

        <!-- Modal header -->
        <div class="modal-header">
            <div class="modal-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
            </div>
            <div>
                <h3 id="modalTitle">Reserve Item</h3>
                <p id="modalSubtitle" class="modal-subtitle">Submit a reservation request</p>
            </div>
            <button class="modal-close-btn" onclick="closeReserveModal()" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <!-- Equipment details -->
        <div class="modal-info-row">
            <div class="modal-info-group">
                <label>Equipment</label>
                <p id="modalEquipmentName">—</p>
            </div>
            <div class="modal-info-group">
                <label>Category</label>
                <p id="modalCategory">—</p>
            </div>
            <div class="modal-info-group">
                <label>Status</label>
                <p class="modal-status-badge">AVAILABLE</p>
            </div>
        </div>

        <!-- Date picker -->
        <div class="modal-date-group">
            <label for="reserveDate">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#C40C0C"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Zm280 240q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-160 0q-17 0-28.5-11.5T280-440q0-17 11.5-28.5T320-480q17 0 28.5 11.5T360-440q0 17-11.5 28.5T320-400Zm320 0q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-160 0q-17 0-28.5-11.5T280-280q0-17 11.5-28.5T320-320q17 0 28.5 11.5T360-280q0 17-11.5 28.5T320-240Zm320 0q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240Z"/></svg>
                Date to be Used
            </label>
            <input type="date" id="reserveDate" min="<?php echo date('Y-m-d');?>" required>
            <p class="date-hint">Select the date you need this equipment. Requests are subject to admin approval.</p>
        </div>

        <p id="modalMsg" class="modal-msg"></p>

        <!-- Modal actions -->
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" onclick="closeReserveModal()">Cancel</button>
            <button type="button" class="modal-btn-submit" id="submitReserveBtn" onclick="submitReservation()">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                Submit Request
            </button>
        </div>
    </div>
</div>

<!-- Success toast -->
<div class="toast" id="successToast">
    <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
    Reservation submitted! Awaiting admin approval.
</div>

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
const profileBtn = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    profileDropdown.classList.toggle('open');
});
document.addEventListener('click', () => {
    profileDropdown.classList.remove('open');
});

let _reserveId = null;
const reserveDateInput = document.getElementById('reserveDate');
let _confirmActionCallback = null;

function triggerDatePicker() {
    if (!reserveDateInput) return;
    if (typeof reserveDateInput.showPicker === 'function') {
        try {
            reserveDateInput.showPicker();
        } catch (_) {
            // Ignore unsupported showPicker
        }
    }
}

function openReserveModal(id, name, category) {
    _reserveId = id;
    document.getElementById('modalEquipmentName').textContent = name;
    document.getElementById('modalCategory').textContent = category;
    document.getElementById('modalTitle').textContent = 'Reserve: ' + name;
    document.getElementById('reserveDate').value = '';
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

// Close modal on backdrop
document.getElementById('reserveModal').addEventListener('click', function(e) {
    if (e.target === this) closeReserveModal();
});
document.getElementById('confirmActionModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmActionModal();
});
document.getElementById('confirmActionProceedBtn').addEventListener('click', function() {
    if (typeof _confirmActionCallback === 'function') {
        const cb = _confirmActionCallback;
        closeConfirmActionModal();
        cb();
    } else {
        closeConfirmActionModal();
    }
});

// Open picker on date input
if (reserveDateInput) {
    reserveDateInput.addEventListener('click', triggerDatePicker);
    reserveDateInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            triggerDatePicker();
        }
    });
}

function submitReservation() {
    const dateVal = document.getElementById('reserveDate').value;
    const msgEl   = document.getElementById('modalMsg');

    if (!dateVal) {
        msgEl.textContent = 'Please select a date.';
        document.getElementById('reserveDate').focus();
        return;
    }

    const btn = document.getElementById('submitReserveBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor" style="animation:spin 0.8s linear infinite"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q17 0 28.5 11.5T520-840q0 17-11.5 28.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160q133 0 226.5-93.5T800-480q0-17 11.5-28.5T840-520q17 0 28.5 11.5T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg> Submitting…';
    msgEl.textContent = '';

    const currentEquipId = _reserveId;

    const form = new FormData();
    form.append('equipment_id', currentEquipId);
    form.append('res_date', dateVal);
    form.append('submit_res', '1');
    form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

    fetch('reservation.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeReserveModal();
                showToast();

                // Instantly swap Reserve button → Cancel button without reload
                const reserveBtn = document.querySelector(`button.reservation-btn-reserve[data-id="${currentEquipId}"]`);
                if (reserveBtn) {
                    const resId = data.reservation_id ?? null;
                    const equipName = reserveBtn.getAttribute('data-name') || document.getElementById('modalEquipmentName').textContent;
                    const td = reserveBtn.closest('td');
                    if (td) {
                        if (resId) {
                            td.innerHTML = `<button class="reservation-btn-cancel" onclick="cancelExistingReservation(${resId}, '${equipName.replace(/'/g,"\\'")}')">Cancel</button>`;
                        } else {
                            // No reservation_id returned — show pending pill and reload in background
                            td.innerHTML = `<span class="reservation-pill-active">Pending…</span>`;
                        }
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

function cancelExistingReservation(reservationId, equipmentName) {
    if (!reservationId) return;
    openConfirmActionModal('Cancel reservation for "' + equipmentName + '"?', function() {
        const form = new FormData();
        form.append('reservation_id', reservationId);
        form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

        fetch('cancel_reservation.php', { method: 'POST', body: form })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    openInfoActionModal(data.message || 'Could not cancel reservation.');
                    return;
                }
                window.location.reload();
            })
            .catch(() => {
                openInfoActionModal('An error occurred while cancelling. Please try again.');
            });
        });
}

function showToast() {
    const t = document.getElementById('successToast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// Spinner animation
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);
</script>

</body>
</html>