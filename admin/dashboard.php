<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0];
}

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$equipmentCountQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments");
$equipmentCount = mysqli_fetch_assoc($equipmentCountQuery)['total'];

$inUseQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'In-Use'");
$inUse = mysqli_fetch_assoc($inUseQuery)['total'];

$availableQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available'");
$available = mysqli_fetch_assoc($availableQuery)['total'];

$maintenanceQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Under Maintenance'");
$maintenance = mysqli_fetch_assoc($maintenanceQuery)['total'];

$today = date('Y-m-d');
$resTodayQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE DATE(reserved_date) = '$today' AND status IN ('pending','approved')");
$resToday = mysqli_fetch_assoc($resTodayQuery)['total'];

$reservationsQuery = mysqli_query($conn, "
    SELECT r.reservation_id, e.resource_name, u.username AS requested_by, r.status, r.reserved_date
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u ON r.requested_by = u.user_id
    ORDER BY r.reserved_date DESC
    LIMIT 10
");
$reservationRows = mysqli_num_rows($reservationsQuery);

$pendingQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
$pendingCount = mysqli_fetch_assoc($pendingQuery)['total'];

// Overdue items for bell notification
$overdueItemsQuery = mysqli_query($conn, "
    SELECT e.resource_name, r.reserved_end, r.reservation_id
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.status = 'approved'
      AND e.status = 'In-Use'
      AND r.reserved_end IS NOT NULL
      AND r.reserved_end < NOW()
    ORDER BY r.reserved_end ASC
    LIMIT 5
");
$overdueItems = [];
while ($row = mysqli_fetch_assoc($overdueItemsQuery)) {
    $overdueItems[] = $row;
}
$overdueCount = count($overdueItems);
$notifTotal = $overdueCount + ($pendingCount > 0 ? 1 : 0);

$addUserMessage = '';
$addUserMessageType = '';
$openAddUserModal = false;
if (isset($_POST['create_user_dash'])) {
    $openAddUserModal = true;
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $addUserMessage = "Invalid request. Please refresh and try again.";
        $addUserMessageType = 'error';
    } else {
        $newFullName = trim($_POST['full_name'] ?? '');
        $newUsername = trim($_POST['username'] ?? '');
        $newPassword = trim($_POST['password'] ?? '');
        $newRole = trim($_POST['role'] ?? '');
        $allowedRoles = ['admin', 'staff'];

        if ($newFullName === '' || $newUsername === '' || $newPassword === '' || $newRole === '') {
            $addUserMessage = "All user fields are required.";
            $addUserMessageType = 'error';
        } elseif (!in_array($newRole, $allowedRoles, true)) {
            $addUserMessage = "Invalid role selected.";
            $addUserMessageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $addUserMessage = "Password must be at least 8 characters.";
            $addUserMessageType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, username, password, roles) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssss', $newFullName, $newUsername, $hashedPassword, $newRole);

            if (mysqli_stmt_execute($stmt)) {
                $addUserMessage = "User account created successfully.";
                $addUserMessageType = 'success';
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $_POST = [];
            } else {
                $addUserMessage = "Error: " . mysqli_stmt_error($stmt);
                $addUserMessageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <title>Admin Dashboard — BSU Asset Manager</title>
    <link rel="icon" href="../img/favicon-96.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/dashboard.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/modal.css">
</head>
<body>

<?php include('sidebar.php'); ?>

<!-- Top bar -->
<header class="topbar">
    <div class="topbar-title">
        <h1>Dashboard</h1>
        <p>Overview of all assets &amp; activity</p>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><?php echo date('l, F j, Y'); ?></span>

        <!-- Bell notification -->
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" id="notifBtn" aria-label="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M160-200v-80h80v-280q0-83 50-149.5T420-790v-30q0-25 17.5-42.5T480-880q25 0 42.5 17.5T540-820v30q80 20 130 86.5T720-560v280h80v80H160Zm320-300Zm0 420q-33 0-56.5-23.5T400-160h160q0 33-23.5 56.5T480-80ZM320-280h320v-280q0-66-47-113t-113-47q-66 0-113 47t-47 113v280Z"/></svg>
                <?php if ($notifTotal > 0): ?>
                <span class="notif-badge"><?= $notifTotal ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title">Notifications</span>
                    <?php if ($notifTotal > 0): ?>
                    <span class="notif-dropdown-count"><?= $notifTotal ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="notif-list">
                <?php if ($overdueCount === 0 && $pendingCount === 0): ?>
                    <div class="notif-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="currentColor"><path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
                        <p>All clear — nothing needs attention.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($overdueItems as $item):
                        $secsLate = time() - strtotime($item['reserved_end']);
                        $minsLate = round($secsLate / 60);
                        if ($secsLate < 3600)           $timeLabel = $minsLate . ' min ago';
                        elseif ($secsLate < 86400)      $timeLabel = round($secsLate/3600) . ' hr ago';
                        elseif ($secsLate < 604800)     $timeLabel = round($secsLate/86400) . ' day' . (round($secsLate/86400) == 1 ? '' : 's') . ' ago';
                        elseif ($secsLate < 2592000)    $timeLabel = round($secsLate/604800) . ' week' . (round($secsLate/604800) == 1 ? '' : 's') . ' ago';
                        elseif ($secsLate < 31536000)   $timeLabel = round($secsLate/2592000) . ' month' . (round($secsLate/2592000) == 1 ? '' : 's') . ' ago';
                        else                            $timeLabel = round($secsLate/31536000) . ' year' . (round($secsLate/31536000) == 1 ? '' : 's') . ' ago';
                    ?>
                    <a href="in_use.php" class="notif-item notif-critical">
                        <span class="notif-item-dot notif-dot-red"></span>
                        <div class="notif-item-body">
                            <strong><?= htmlspecialchars($item['resource_name']) ?> — not returned</strong>
                            <span>Overdue since <?= date('g:i a', strtotime($item['reserved_end'])) ?></span>
                        </div>
                        <span class="notif-item-time"><?= $timeLabel ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if ($pendingCount > 0): ?>
                    <a href="reservation.php" class="notif-item notif-warning">
                        <span class="notif-item-dot notif-dot-amber"></span>
                        <div class="notif-item-body">
                            <strong><?= $pendingCount ?> pending reservation<?= $pendingCount != 1 ? 's' : '' ?></strong>
                            <span>Waiting for your approval</span>
                        </div>
                        <span class="notif-item-time">Review →</span>
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                <?php if ($notifTotal > 0): ?>
                <div class="notif-dropdown-footer">
                    <a href="in_use.php">View all overdue items →</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="profile-wrap">
            <button class="profile-btn" id="profileBtn">
                <?php echo htmlspecialchars($profileInitials); ?>
            </button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <p><?php echo htmlspecialchars($_SESSION['full_name'] ?? $name); ?></p>
                    <p>Administrator</p>
                </div>
                <a href="logout.php" class="danger">
                    <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
                    Sign Out
                </a>
            </div>
        </div>
    </div>
</header>

<!-- Main content -->
<main class="main">

    <!-- Greeting -->
    <div class="greeting-banner">
        <div class="greeting-text">
            <h2><?php echo $greeting; ?>, <span class="name"><?php echo htmlspecialchars($name); ?></span>👋</h2>
            <p>Here's what's happening with BSU's assets today.</p>
        </div>
        <?php if ($pendingCount > 0): ?>
        <a href="reservation.php" class="greeting-tag">
            ⚡ <?php echo $pendingCount; ?> pending reservation<?php echo $pendingCount != 1 ? 's' : ''; ?> awaiting review
        </a>
        <?php else: ?>
        <span class="greeting-tag">✓ All clear — no pending items</span>
        <?php endif; ?>
    </div>

    <!-- Stat cards -->
    <div class="stats-grid">
        <div class="stat-card total">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $equipmentCount; ?></div>
            <div class="stat-label">Total Equipment</div>
        </div>
        <div class="stat-card inuse">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $inUse; ?></div>
            <div class="stat-label">In Use</div>
        </div>
        <div class="stat-card available">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $available; ?></div>
            <div class="stat-label">Available</div>
        </div>
        <div class="stat-card maintenance">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M686-132 444-376q-20 8-43 12t-47 4q-100 0-170-70t-70-170q0-27 4-52t12-48l138 138 92-92-138-138q23-8 48-12t52-4q100 0 170 70t70 170q0 24-4 47t-12 43l244 242q12 12 12 29t-12 29l-56 56q-12 12-29 12t-29-12Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $maintenance; ?></div>
            <div class="stat-label">Maintenance</div>
        </div>
        <div class="stat-card reservations">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $resToday; ?></div>
            <div class="stat-label">Reservations Today</div>
        </div>
    </div>

    <!-- Content grid -->
    <div class="content-grid">

        <!-- Recent reservations -->
        <div class="section-card">
            <div class="section-header">
                <h2>Recent Reservations</h2>
                <a href="reservation.php">View all →</a>
            </div>
            <table class="res-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Equipment</th>
                        <th>Requested By</th>
                        <th>Status</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (mysqli_num_rows($reservationsQuery) == 0): ?>
                    <tr class="empty-row"><td colspan="5">No reservation records found.</td></tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($reservationsQuery)): ?>
                    <tr>
                        <td style="color:var(--text-3);font-size:0.75rem;">#<?php echo $row['reservation_id']; ?></td>
                        <td class="equipment-name"><?php echo htmlspecialchars($row['resource_name']); ?></td>
                        <td>
                            <span class="req-by">
                                <span class="req-avatar"><?php echo strtoupper(substr($row['requested_by'] ?? 'G', 0, 2)); ?></span>
                                <?php echo htmlspecialchars($row['requested_by'] ?? 'Guest'); ?>
                            </span>
                        </td>
                        <td><span class="badge <?php echo strtolower($row['status']); ?>"><?php echo $row['status']; ?></span></td>
                        <td style="color:var(--text-3);font-size:0.79rem;"><?php echo date('M j, Y', strtotime($row['reserved_date'])); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($reservationRows > 0 && $reservationRows < 10): ?>
            <div class="table-filler">
                <p>More reservation activity will appear here.</p>
                <span>New requests are automatically added in real time.</span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Right panel -->
        <div class="right-panel">

            <!-- Quick actions -->
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-list">
                    <a href="#" class="action-item primary" id="openAddEquipmentDash" onclick="event.preventDefault();">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="white"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>Add Equipment</strong>
                            <span>Register new inventory</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="white"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </a>
                    <a href="reservation.php" class="action-item secondary">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="var(--red)"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>Manage Reservations</strong>
                            <span>Approve or reject requests</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </a>
                    <button type="button" class="action-item secondary js-open-add-user">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="var(--red)"><path d="M726.67-400v-126.67H600v-66.66h126.67V-720h66.66v126.67H920v66.66H793.33V-400h-66.66ZM250.33-524.33Q206.67-568 206.67-634t43.66-109.67Q294-787.33 360-787.33t109.67 43.66Q513.33-700 513.33-634t-43.66 109.67Q426-480.67 360-480.67t-109.67-43.66ZM40-160v-100q0-34.67 17.5-63.17T106.67-366q70.66-32.33 131-46.5Q298-426.67 360-426.67t122 14.17q60 14.17 130.67 46.5 31.66 15 49.5 43.17Q680-294.67 680-260v100H40Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>Create Account</strong>
                            <span>Add faculty or staff</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </button>
                </div>
            </div>

            <!-- Equipment status -->
            <div class="status-card">
                <h3>Equipment Status</h3>
                <?php
                $total_for_pct = max($equipmentCount, 1);
                $items = [
                    ['Available', $available, '#27AE60'],
                    ['In-Use',    $inUse,     '#E67E22'],
                    ['Maintenance',$maintenance,'#8E44AD'],
                ];
                foreach ($items as $item):
                    $pct = round($item[1] / $total_for_pct * 100);
                ?>
                <div class="progress-item">
                    <span class="progress-label"><?php echo $item[0]; ?></span>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill" style="width:<?php echo $pct; ?>%;background:<?php echo $item[2]; ?>;"></div>
                    </div>
                    <span class="progress-count"><?php echo $item[1]; ?></span>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Categories -->
            <div class="categories-card">
                <div class="section-header">
                    <h2>Categories</h2>
                </div>
                <div class="chip-list">
                    <span class="chip it">IT Equipment</span>
                    <span class="chip cls">Classroom</span>
                    <span class="chip evt">Events Equipment</span>
                </div>
                <div class="chip-footer">
                    <a href="equipments.php">Browse all equipment →</a>
                </div>
            </div>

        </div><!-- /right-panel -->
    </div><!-- /content-grid -->

</main>

<div class="modal-overlay<?php echo $openAddUserModal ? ' active' : ''; ?>" id="addUserModal">
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <p class="modal-kicker">User Access</p>
                <h3>Create User Account</h3>
                <p>Add a new admin or staff account securely.</p>
            </div>
            <button type="button" class="modal-close" id="closeAddUserModal" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <?php if ($addUserMessage !== ''): ?>
            <div class="modal-message <?php echo $addUserMessageType === 'error' ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($addUserMessage); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="dashboard.php" class="add-user-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="input-group">
                <label for="create_full_name">Full Name</label>
                <input id="create_full_name" type="text" name="full_name" placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
            </div>
            <div class="input-group">
                <label for="create_username">Username</label>
                <input id="create_username" type="text" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="input-group">
                <label for="create_password">Password</label>
                <input id="create_password" type="password" name="password" placeholder="Enter password (min. 8 characters)" minlength="8" required>
            </div>
            <div class="input-group">
                <label for="create_role">Role</label>
                <select id="create_role" name="role" required>
                    <option value="">Select Role</option>
                    <option value="admin" <?php if (($_POST['role'] ?? '') === 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="staff" <?php if (($_POST['role'] ?? '') === 'staff') echo 'selected'; ?>>Staff</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="cancelAddUser">Cancel</button>
                <button type="submit" name="create_user_dash" class="btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<!-- Add equipment modal -->
<div class="modal-overlay" id="addEquipDashModal">
    <div class="modal-box">
        <h3>Add new equipment</h3>
        <p>Create an inventory item. Status starts as <strong>Available</strong>.</p>

        <div class="modal-alert" id="addEquipDashAlert"></div>

        <form method="POST" id="addEquipDashForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="add" value="1">

            <div style="margin-bottom:12px;">
                <label>Resource Name</label>
                <input type="text" name="resource_name" placeholder="Example: Projector, HDMI Cable, Laptop" required>
            </div>

            <div style="margin-bottom:6px;">
                <label>Category</label>
                <select name="category" required>
                    <option value="">Select Category</option>
                    <option value="IT Equipment">IT Equipment</option>
                    <option value="Classroom">Classroom</option>
                    <option value="Events Equipment">Events Equipment</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" id="addEquipDashCancel">Cancel</button>
                <button type="submit" class="btn-confirm-edit" id="addEquipDashSubmit">Add Equipment</button>
            </div>
        </form>
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
</script>

<script>
const openAddDash = document.getElementById('openAddEquipmentDash');
const dashModal = document.getElementById('addEquipDashModal');
const dashCancel = document.getElementById('addEquipDashCancel');
const dashForm = document.getElementById('addEquipDashForm');
const dashAlert = document.getElementById('addEquipDashAlert');
const dashSubmit = document.getElementById('addEquipDashSubmit');

function showDashAlert(type, text) {
    dashAlert.className = 'modal-alert show ' + type;
    dashAlert.textContent = text;
}
function clearDashAlert() {
    dashAlert.className = 'modal-alert';
    dashAlert.textContent = '';
}

function openDashModal() {
    clearDashAlert();
    dashModal.classList.add('active');
    const first = dashForm.querySelector('input[name="resource_name"]');
    if (first) first.focus();
}
function closeDashModal() {
    dashModal.classList.remove('active');
    dashForm.reset();
    clearDashAlert();
}

openAddDash.addEventListener('click', openDashModal);
dashCancel.addEventListener('click', closeDashModal);
dashModal.addEventListener('click', (e) => { if (e.target === dashModal) closeDashModal(); });
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && dashModal.classList.contains('active')) closeDashModal();
});

dashForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    clearDashAlert();
    dashSubmit.disabled = true;
    dashSubmit.textContent = 'Adding…';
    try {
        const fd = new FormData(dashForm);
        const res = await fetch('add_equipment.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data && data.success) {
            showDashAlert('success', data.message || 'Equipment added.');
            setTimeout(() => location.reload(), 650);
        } else {
            showDashAlert('error', (data && data.message) ? data.message : 'Add failed.');
            dashSubmit.disabled = false;
            dashSubmit.textContent = 'Add Equipment';
        }
    } catch {
        showDashAlert('error', 'Network error. Please try again.');
        dashSubmit.disabled = false;
        dashSubmit.textContent = 'Add Equipment';
    }
});

const addUserModal = document.getElementById('addUserModal');
const openAddUserBtns = document.querySelectorAll('.js-open-add-user');
const closeAddUserModal = document.getElementById('closeAddUserModal');
const cancelAddUser = document.getElementById('cancelAddUser');

function showAddUserModal() {
    addUserModal.classList.add('active');
    document.body.classList.add('modal-open');
}
function hideAddUserModal() {
    addUserModal.classList.remove('active');
    document.body.classList.remove('modal-open');
}

openAddUserBtns.forEach((btn) => {
    btn.addEventListener('click', showAddUserModal);
});

if (closeAddUserModal) closeAddUserModal.addEventListener('click', hideAddUserModal);
if (cancelAddUser) cancelAddUser.addEventListener('click', hideAddUserModal);

addUserModal.addEventListener('click', (e) => {
    if (e.target === addUserModal) hideAddUserModal();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') hideAddUserModal();
});

<?php if ($openAddUserModal): ?>
showAddUserModal();
<?php endif; ?>
</script>
<style>
/* ── Bell notification ── */
.notif-wrap { position: relative; }
.notif-btn {
    position: relative;
    width: 38px; height: 38px;
    border-radius: 10px;
    border: 1px solid #e5e5e5;
    background: #fff;
    display: flex; align-items: center; justify-content: center;
    color: #555;
    cursor: pointer;
    transition: background .15s, border-color .15s;
}
.notif-btn:hover { background: #f5f5f5; border-color: #ccc; }
.notif-badge {
    position: absolute;
    top: -5px; right: -5px;
    background: #E8000D;
    color: #fff;
    font-size: 9px; font-weight: 800;
    border-radius: 10px;
    padding: 1px 5px;
    border: 2px solid #fff;
    animation: badge-pop 1.4s ease-in-out infinite;
    min-width: 16px; text-align: center;
}
@keyframes badge-pop {
    0%, 100% { transform: scale(1); }
    50%       { transform: scale(1.18); }
}
.notif-dropdown {
    display: none;
    position: absolute;
    top: calc(100% + 10px);
    right: 0;
    width: 320px;
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 14px;
    box-shadow: 0 8px 32px rgba(0,0,0,0.13);
    z-index: 9999;
    overflow: hidden;
}
.notif-dropdown.open { display: block; }
.notif-dropdown-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 13px 16px 10px;
    border-bottom: 1px solid #f0f0f0;
}
.notif-dropdown-title { font-size: 13px; font-weight: 700; color: #111; }
.notif-dropdown-count {
    font-size: 10px; font-weight: 700;
    background: #E8000D; color: #fff;
    border-radius: 20px; padding: 2px 8px;
}
.notif-list { max-height: 280px; overflow-y: auto; }
.notif-item {
    display: flex; align-items: flex-start; gap: 10px;
    padding: 11px 16px;
    border-bottom: 1px solid #f5f5f5;
    text-decoration: none;
    transition: background .12s;
}
.notif-item:hover { background: #fafafa; }
.notif-critical { background: #fff8f8; }
.notif-critical:hover { background: #fff0f0; }
.notif-warning { background: #fffdf5; }
.notif-warning:hover { background: #fffbeb; }
.notif-item-dot {
    width: 8px; height: 8px; border-radius: 50%;
    flex-shrink: 0; margin-top: 4px;
}
.notif-dot-red {
    background: #E8000D;
    animation: dot-blink 1.1s ease-in-out infinite;
}
.notif-dot-amber { background: #d97706; }
@keyframes dot-blink {
    0%, 100% { opacity: 1; } 50% { opacity: .2; }
}
.notif-item-body { flex: 1; min-width: 0; }
.notif-item-body strong {
    display: block; font-size: 12px; font-weight: 700;
    color: #111; margin-bottom: 2px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.notif-item-body span { font-size: 11px; color: #888; }
.notif-item-time {
    font-size: 10px; color: #aaa; white-space: nowrap;
    margin-top: 2px; flex-shrink: 0;
}
.notif-empty {
    display: flex; flex-direction: column; align-items: center;
    gap: 8px; padding: 28px 16px; color: #bbb; text-align: center;
}
.notif-empty p { font-size: 12px; color: #aaa; }
.notif-dropdown-footer {
    padding: 10px 16px;
    border-top: 1px solid #f0f0f0;
    text-align: center;
}
.notif-dropdown-footer a { font-size: 12px; font-weight: 600; color: #E8000D; text-decoration: none; }
.notif-dropdown-footer a:hover { text-decoration: underline; }
</style>

<script>
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
notifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown.classList.toggle('open');
    profileDropdown.classList.remove('open');
});
document.addEventListener('click', () => {
    notifDropdown.classList.remove('open');
});
notifDropdown.addEventListener('click', (e) => e.stopPropagation());
</script>
</body>
</html>