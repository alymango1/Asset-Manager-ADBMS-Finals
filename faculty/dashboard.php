<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

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
$name = ucfirst(strtolower($firstNameRaw)); // for greeting banner
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$pending_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status = 'pending'");
$pending_count = (int)(mysqli_fetch_assoc($pending_q)['total'] ?? 0);

$approved_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status = 'approved'");
$approved_count = (int)(mysqli_fetch_assoc($approved_q)['total'] ?? 0);

$rejected_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status = 'rejected'");
$rejected_count = (int)(mysqli_fetch_assoc($rejected_q)['total'] ?? 0);

$total_q = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id");
$total_count = (int)(mysqli_fetch_assoc($total_q)['total'] ?? 0);

$query = "
    SELECT
        r.reservation_id,
        e.resource_name,
        r.reserved_date,
        r.status,
        r.remarks
    FROM reservations r
    LEFT JOIN equipments e ON e.equipment_id = r.equipment_id
    WHERE r.requested_by = $user_id
    ORDER BY r.created_at DESC
    LIMIT 10
";
$result = mysqli_query($conn, $query);
$reservationRows = mysqli_num_rows($result);

$hour = (int)date('H');
$greeting = $hour < 12 ? 'Good Morning' : ($hour < 18 ? 'Good Afternoon' : 'Good Evening');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Faculty Dashboard</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="../css/faculty/style.css">
    <link rel="stylesheet" href="../css/faculty/dashboard.css">
    <link rel="stylesheet" href="../css/faculty/sidebar.css">
    <link rel="stylesheet" href="../css/faculty/modal.css">
</head>
<body>

<?php include('../faculty/sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Dashboard</h1>
        <p>Overview of your reservation activity</p>
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
    <div class="greeting-banner">
        <div class="greeting-text">
            <h2><?php echo $greeting; ?>, <span class="name"><?php echo htmlspecialchars($name); ?></span> 👋</h2>
            <p>Here is your current reservation status.</p>
        </div>
        <?php if ($pending_count > 0): ?>
            <a href="my_reservations.php" class="greeting-tag">
                ⚡ <?php echo $pending_count; ?> pending reservation<?php echo $pending_count !== 1 ? 's' : ''; ?>
            </a>
        <?php else: ?>
            <span class="greeting-tag">✓ All clear - no pending items</span>
        <?php endif; ?>
    </div>

    <div class="stats-grid">
        <div class="stat-card inuse">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $pending_count; ?></div>
            <div class="stat-label">Pending Reservations</div>
        </div>
        <div class="stat-card available">
            <div class="stat-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div class="stat-value"><?php echo $approved_count; ?></div>
            <div class="stat-label">Approved Reservations</div>
        </div>
    </div>

    <div class="content-grid">
        <div class="section-card">
            <div class="section-header">
                <h2>My Reservation History</h2>
                <a href="my_reservations.php">View all →</a>
            </div>
            <table class="res-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Equipment</th>
                        <th>Status</th>
                        <th>Date</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php if ($reservationRows === 0): ?>
                    <tr class="empty-row"><td colspan="5">No reservation records found.</td></tr>
                <?php else: ?>
                    <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr>
                        <td style="color:var(--text-3);font-size:0.75rem;">#<?php echo (int)$row['reservation_id']; ?></td>
                        <td class="equipment-name"><?php echo htmlspecialchars($row['resource_name'] ?? 'N/A'); ?></td>
                        <td><span class="badge <?php echo strtolower($row['status']); ?>"><?php echo htmlspecialchars(ucfirst($row['status'])); ?></span></td>
                        <td style="color:var(--text-3);font-size:0.79rem;"><?php echo date('M j, Y', strtotime($row['reserved_date'])); ?></td>
                        <td><?php echo htmlspecialchars($row['remarks'] ?? 'None'); ?></td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
                </tbody>
            </table>
            <?php if ($reservationRows > 0 && $reservationRows < 10): ?>
            <div class="table-filler">
                <p>More reservations will appear here.</p>
                <span>New requests are automatically added as you create them.</span>
            </div>
            <?php endif; ?>
        </div>

        <div class="right-panel">
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-list">
                    <a href="reservation.php" class="action-item primary">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="white"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>New Reservation</strong>
                            <span>Create a reservation request</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="white"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </a>
                    <a href="my_reservations.php" class="action-item secondary">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="var(--red)"><path d="M320-240h320v-80H320v80Zm0-160h320v-80H320v80Zm-80 320q-33 0-56.5-23.5T160-160v-640q0-33 23.5-56.5T240-880h400q33 0 56.5 23.5T720-800v640q0 33-23.5 56.5T640-80H240Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>My Reservations</strong>
                            <span>Track request status</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </a>
                </div>
            </div>

            <div class="status-card">
                <h3>Reservation Status</h3>
                <?php
                $total_for_pct = max($total_count, 1);
                $status_items = [
                    ['Pending', $pending_count, '#F59E0B'],
                    ['Approved', $approved_count, '#27AE60'],
                    ['Rejected', $rejected_count, '#C0392B'],
                ];
                foreach ($status_items as $item):
                    $pct = round(($item[1] / $total_for_pct) * 100);
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
        </div>
    </div>
</main>

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
</body>
</html>
