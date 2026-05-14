<?php
session_start();

include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

// Notification bell data
$_notifPendingQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
$_notifPendingCount = mysqli_fetch_assoc($_notifPendingQuery)['total'];
$_notifOverdueQuery = mysqli_query($conn, "
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
$_notifOverdueItems = [];
while ($row = mysqli_fetch_assoc($_notifOverdueQuery)) {
    $_notifOverdueItems[] = $row;
}
$_notifOverdueCount = count($_notifOverdueItems);
$_notifTotal = $_notifOverdueCount + ($_notifPendingCount > 0 ? 1 : 0);

// Search filters
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';
$status   = isset($_GET['status'])   ? trim(mysqli_real_escape_string($conn, $_GET['status']))   : '';

// Build conditions
$where = "WHERE 1=1";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";
if ($status   !== '') $where .= " AND status = '$status'";

// Pagination
$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$totalQuery   = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments $where");
$totalRow     = mysqli_fetch_assoc($totalQuery);
$totalRecords = $totalRow['total'];
$totalPages   = ceil($totalRecords / $limit);

if ($page < 1) $page = 1;
if ($totalPages > 0 && $page > $totalPages) $page = $totalPages;

$equipmentsQuery = mysqli_query($conn, "
    SELECT * FROM equipments
    $where
    ORDER BY equipment_id ASC
    LIMIT $limit OFFSET $offset
");
$equipmentRows = mysqli_num_rows($equipmentsQuery);

$totalEquipment = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments"))['total'];
$totalAvailable = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Available'"))['total'];
$totalInUse = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'In-Use'"))['total'];
$totalMaintenance = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipments WHERE status = 'Under Maintenance'"))['total'];

// Keep filters in pagination
$queryParams = [];
if ($search   !== '') $queryParams[] = 'search='   . urlencode($search);
if ($category !== '') $queryParams[] = 'category=' . urlencode($category);
if ($status   !== '') $queryParams[] = 'status='   . urlencode($status);
$filterString = count($queryParams) ? '&' . implode('&', $queryParams) : '';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <title>Equipments — BSU Asset Manager</title>
    <link rel="icon" href="../img/favicon-96.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Syne:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/equipments.css">
    <link rel="stylesheet" href="../css/admin/modal.css">
<style>
/* ── Bell notification ── */
.topbar-right {
    display: flex;
    align-items: center;
    gap: 12px;
}
.notif-btn, .profile-btn {
    box-sizing: border-box;
    flex-shrink: 0;
    padding: 0;
    margin: 0;
    line-height: 1;
}
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
</head>

<body>

<?php include('sidebar.php');?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Equipments</h1>
        <p>Control inventory, availability, and lifecycle state</p>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><?php echo date('l, F j, Y'); ?></span>
        <!-- Bell notification -->
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" id="notifBtn" aria-label="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M160-200v-80h80v-280q0-83 50-149.5T420-790v-30q0-25 17.5-42.5T480-880q25 0 42.5 17.5T540-820v30q80 20 130 86.5T720-560v280h80v80H160Zm320-300Zm0 420q-33 0-56.5-23.5T400-160h160q0 33-23.5 56.5T480-80ZM320-280h320v-280q0-66-47-113t-113-47q-66 0-113 47t-47 113v280Z"/></svg>
                <?php if ($_notifTotal > 0): ?>
                <span class="notif-badge"><?= $_notifTotal ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title">Notifications</span>
                    <?php if ($_notifTotal > 0): ?>
                    <span class="notif-dropdown-count"><?= $_notifTotal ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="notif-list">
                <?php if ($_notifOverdueCount === 0 && $_notifPendingCount === 0): ?>
                    <div class="notif-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="currentColor"><path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
                        <p>All clear — nothing needs attention.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($_notifOverdueItems as $_notifItem):
                        $_notifSecsLate = time() - strtotime($_notifItem['reserved_end']);
                        $_notifMinsLate = round($_notifSecsLate / 60);
                        if ($_notifSecsLate < 3600)           $_notifTimeLabel = $_notifMinsLate . ' min ago';
                        elseif ($_notifSecsLate < 86400)      $_notifTimeLabel = round($_notifSecsLate/3600) . ' hr ago';
                        elseif ($_notifSecsLate < 604800)     $_notifTimeLabel = round($_notifSecsLate/86400) . ' day' . (round($_notifSecsLate/86400) == 1 ? '' : 's') . ' ago';
                        elseif ($_notifSecsLate < 2592000)    $_notifTimeLabel = round($_notifSecsLate/604800) . ' week' . (round($_notifSecsLate/604800) == 1 ? '' : 's') . ' ago';
                        elseif ($_notifSecsLate < 31536000)   $_notifTimeLabel = round($_notifSecsLate/2592000) . ' month' . (round($_notifSecsLate/2592000) == 1 ? '' : 's') . ' ago';
                        else                                  $_notifTimeLabel = round($_notifSecsLate/31536000) . ' year' . (round($_notifSecsLate/31536000) == 1 ? '' : 's') . ' ago';
                    ?>
                    <a href="in_use.php" class="notif-item notif-critical">
                        <span class="notif-item-dot notif-dot-red"></span>
                        <div class="notif-item-body">
                            <strong><?= htmlspecialchars($_notifItem['resource_name']) ?> — not returned</strong>
                            <span>Overdue since <?= date('g:i a', strtotime($_notifItem['reserved_end'])) ?></span>
                        </div>
                        <span class="notif-item-time"><?= $_notifTimeLabel ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if ($_notifPendingCount > 0): ?>
                    <a href="reservation.php" class="notif-item notif-warning">
                        <span class="notif-item-dot notif-dot-amber"></span>
                        <div class="notif-item-body">
                            <strong><?= $_notifPendingCount ?> pending reservation<?= $_notifPendingCount != 1 ? 's' : '' ?></strong>
                            <span>Waiting for your approval</span>
                        </div>
                        <span class="notif-item-time">Review →</span>
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                <?php if ($_notifTotal > 0): ?>
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

<button type="button" class="fab" id="openAddEquipment" title="Add Equipment" aria-label="Add Equipment">
    <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="#fff">
        <path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/>
    </svg>
</button>

<main class="main">
    <section class="equip-hero">
        <div class="equip-hero-copy">
            <p class="eyebrow">Inventory Administration</p>
            <h2>Equipment Control Workspace</h2>
            <p class="hero-subtitle">Track assets, monitor utilization, and keep scheduling availability accurate across all categories.</p>
        </div>
    </section>

    <section class="equip-metrics">
        <article class="metric-card metric-all">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>
            </div>
            <div class="metric-body"><p>Total Equipment</p><strong><?php echo $totalEquipment; ?></strong></div>
        </article>
        <article class="metric-card metric-available">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div class="metric-body"><p>Available</p><strong><?php echo $totalAvailable; ?></strong></div>
        </article>
        <article class="metric-card metric-inuse">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
            </div>
            <div class="metric-body"><p>In-Use</p><strong><?php echo $totalInUse; ?></strong></div>
        </article>
        <article class="metric-card metric-maintenance">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M686-132 444-376q-20 8-43 12t-47 4q-100 0-170-70t-70-170q0-27 4-52t12-48l138 138 92-92-138-138q23-8 48-12t52-4q100 0 170 70t70 170q0 24-4 47t-12 43l244 242q12 12 12 29t-12 29l-56 56q-12 12-29 12t-29-12Z"/></svg>
            </div>
            <div class="metric-body"><p>Maintenance</p><strong><?php echo $totalMaintenance; ?></strong></div>
        </article>
    </section>

    <section class="content-grid">
    <div class="table-wrap section-card">

        <div class="section-header">
            <h2>Equipment List</h2>

            <!-- Search filters -->
        <div class="search-filter-bar-wrap">
            <form method="GET" action="equipments.php" class="search-filter-bar">
                <div class="search-input-wrap sf-search-input">
                    <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#999"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search equipment..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        autocomplete="off"
                    >
                </div>

                <select name="category" class="sf-category">
                    <option value="">All Categories</option>
                    <option value="IT Equipment"     <?php if($category === 'IT Equipment')     echo 'selected'; ?>>IT Equipment</option>
                    <option value="Classroom"        <?php if($category === 'Classroom')        echo 'selected'; ?>>Classroom</option>
                    <option value="Events Equipment" <?php if($category === 'Events Equipment') echo 'selected'; ?>>Events Equipment</option>
                </select>

                <select name="status" class="sf-status">
                    <option value="">All Statuses</option>
                    <option value="Available"         <?php if($status === 'Available')         echo 'selected'; ?>>Available</option>
                    <option value="In-Use"            <?php if($status === 'In-Use')            echo 'selected'; ?>>In-Use</option>
                    <option value="Under Maintenance" <?php if($status === 'Under Maintenance') echo 'selected'; ?>>Under Maintenance</option>
                </select>

                <button type="submit" class="btn-search sf-search-btn">Search</button>

                <?php if ($search !== '' || $category !== '' || $status !== ''): ?>
                    <a href="equipments.php" class="btn-clear-filter sf-clear-btn">&#x2715; Clear</a>
                <?php endif; ?>
            </form>

            <p class="result-count">
                <?php if ($search !== '' || $category !== '' || $status !== ''): ?>
                    Showing <strong><?php echo $totalRecords; ?></strong> result<?php echo $totalRecords != 1 ? 's' : ''; ?>
                    <?php if ($search !== ''): ?> for <strong>"<?php echo htmlspecialchars($search); ?>"</strong><?php endif; ?>
                <?php else: ?>
                    Showing <strong><?php echo $totalRecords; ?></strong> equipment<?php echo $totalRecords != 1 ? 's' : ''; ?> total
                <?php endif; ?>
            </p>
        </div>
        </div>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message-box success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message-box error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        

        <table class="transaction_table equipment" width="100%" cellpadding="10" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Resource Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php if (mysqli_num_rows($equipmentsQuery) === 0): ?>
            <tr>
                <td colspan="5" style="text-align:center; padding:32px; color:#888;">
                    No equipment found matching your search.
                </td>
            </tr>
            <?php else: ?>
            <?php while($row = mysqli_fetch_assoc($equipmentsQuery)): ?>
            <tr>
                <td><?php echo $row['equipment_id']; ?></td>
                <td><?php echo htmlspecialchars($row['resource_name']); ?></td>
                <td><?php echo htmlspecialchars($row['categories']); ?></td>
                <td class="status <?php echo strtolower(str_replace(' ', '-', $row['status'])); ?>">
                    <span class="status-pill"><?php echo htmlspecialchars($row['status']); ?></span>
                </td>
                <td class="actions">
                    <div class="action-menu-wrap">
                        <button class="action-kebab" onclick="toggleMenu(this, event)" title="Actions">
                            <span></span><span></span><span></span>
                        </button>
                        <div class="action-dropdown">
                            <a class="action-item" href="#"
                               onclick='event.preventDefault(); closeAllMenus(); openEditDetailsModal(<?php echo (int)$row['equipment_id']; ?>, <?php echo json_encode($row["resource_name"]); ?>, <?php echo json_encode($row["categories"]); ?>);'>
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M200-200h57l391-391-57-57-391 391v57Zm-80 80v-170l528-527q12-11 26.5-17t30.5-6q16 0 31 6t26 18l55 56q12 11 17.5 26t5.5 30q0 16-5.5 30.5T817-647L290-120H120Zm640-584-56-56 56 56Zm-141 85-28-29 57 57-29-28Z"/></svg>
                                Edit Details
                            </a>
                            <a class="action-item" href="#" onclick="event.preventDefault(); closeAllMenus(); openEditModal(<?php echo $row['equipment_id']; ?>,'<?php echo addslashes($row['resource_name']); ?>','<?php echo addslashes($row['categories']); ?>','<?php echo htmlspecialchars($row['status'], ENT_QUOTES); ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M160-160v-80l80-80v160h-80Zm160 0v-240l80-80v320h-80Zm160 0v-320l80 81v239h-80Zm160 0v-239l80-80v319h-80Zm160 0v-400l80-80v480h-80ZM160-440l280-280 160 160 200-200 80 80-280 280-160-160-280 280-80 80Z"/></svg>
                                Edit Status
                            </a>
                            <?php if ($row['status'] === 'In-Use'): ?>
                            <a class="action-item action-return" href="#" onclick="event.preventDefault(); closeAllMenus(); openReturnModal(<?php echo $row['equipment_id']; ?>,'<?php echo addslashes(htmlspecialchars($row['resource_name'])); ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
                                Mark as Returned
                            </a>
                            <?php endif; ?>
                            <div class="action-divider"></div>
                            <form method="POST" action="delete_equipment.php" class="delete-equipment-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="id" value="<?php echo $row['equipment_id']; ?>">
                                <button type="button" class="action-item action-delete"
                                    onclick="openDeleteEquipmentModal(this.form, 'Delete <?php echo addslashes(htmlspecialchars($row['resource_name'])); ?>? This cannot be undone.')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Zm400-600H280v520h400v-520ZM360-280h80v-360h-80v360Zm160 0h80v-360h-80v360ZM280-720v520-520Z"/></svg>
                                Delete
                                </button>
                            </form>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php endif; ?>
        </table>

        <!-- Pagination -->
        <?php if ($equipmentRows > 0 && $equipmentRows < $limit): ?>
        <div class="table-filler">
            <p>Your inventory panel has room for more assets.</p>
            <span>New equipment entries will appear here automatically.</span>
        </div>
        <?php endif; ?>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $filterString; ?>">&laquo; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo $filterString; ?>"
                   class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $filterString; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    <div class="right-panel">
        <div class="guide-card">
            <h3>Equipment Management Guide</h3>
            <p>Keep operations smooth by maintaining clear status updates and consistent category organization.</p>
            <ul>
                <li>Use precise categories to make resources easier to locate.</li>
                <li>Mark damaged items as maintenance immediately.</li>
                <li>Review in-use assets regularly for timely returns.</li>
            </ul>
        </div>
        <div class="quick-actions">
            <h3>Quick Actions</h3>
            <div class="action-list-mini">
                <a href="#" id="openAddEquipmentInline" onclick="event.preventDefault(); document.getElementById('openAddEquipment').click();">
                    <span class="mini-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M446.67-120v-326.67H120v-66.66h326.67V-840h66.66v326.67H840v66.66H513.33V-120h-66.66Z"/></svg>
                    </span>
                    Add Equipment
                </a>
                <a href="in_use.php">
                    <span class="mini-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
                    </span>
                    View In-Use Items
                </a>
                <a href="transactions.php">
                    <span class="mini-icon">
                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="currentColor"><path d="M280-600v-80h400v80H280Zm0 160v-80h240v80H280Zm0 160v-80h400v80H280ZM200-80q-33 0-56.5-23.5T120-160v-640q0-33 23.5-56.5T200-880h560q33 0 56.5 23.5T840-800v640q0 33-23.5 56.5T760-80H200Z"/></svg>
                    </span>
                    Open Transactions
                </a>
            </div>
        </div>
    </div>
</section>
</main>

<div class="modal-overlay" id="deleteEquipmentModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeDeleteEquipmentModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Z"/></svg>
            </span>
        </div>
        <h3>Are you sure?</h3>
        <p id="deleteEquipmentModalMsg" class="confirm-body">Are you sure?</p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="confirmDeleteEquipmentBtn">Delete Equipment</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeDeleteEquipmentModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Edit details modal -->
<div class="modal-overlay" id="editDetailsModal">
    <div class="modal-box">
        <h3>Edit Equipment Details</h3>
        <div class="modal-info-row">
            <div class="modal-info-group">
                <label>Equipment ID</label>
                <p id="editDetailsEquipmentId">—</p>
            </div>
        </div>
        <div class="input-group" style="margin-bottom: 10px;">
            <label for="editDetailsResourceName">Resource Name</label>
            <input type="text" id="editDetailsResourceName" name="resource_name" autocomplete="off" required>
        </div>
        <div class="input-group">
            <label for="editDetailsCategory">Category</label>
            <select id="editDetailsCategory" name="category" required>
                <option value="">Select Category</option>
                <option value="IT Equipment">IT Equipment</option>
                <option value="Classroom">Classroom</option>
                <option value="Events Equipment">Events Equipment</option>
            </select>
        </div>
        <p id="editDetailsMsg" style="color:red; font-size:0.85rem; min-height:1.2em; margin-top:8px;"></p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeEditDetailsModal()">Cancel</button>
            <button type="button" class="btn-confirm-edit" id="confirmEditDetailsBtn" onclick="submitEditDetails()">Save Changes</button>
        </div>
    </div>
</div>

<!-- Add equipment modal -->
<div class="ae-modal-overlay" id="addEquipmentModal" role="dialog" aria-modal="true">
    <div class="ae-modal-card">
        <div class="ae-modal-head">
            <div>
                <p class="ae-modal-kicker">Inventory Administration</p>
                <h3 class="ae-modal-title">Add new equipment</h3>
                <p class="ae-modal-subtitle">Create an item and set its category.</p>
            </div>
            <button type="button" class="ae-modal-close" id="closeAddEquipment" aria-label="Close">
                &#x2715;
            </button>
        </div>

        <div class="ae-modal-message" id="addEquipMsg"></div>

        <form method="POST" class="ae-form" id="addEquipmentForm" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="ajax" value="1">
            <input type="hidden" name="add" value="1">

            <div class="input-group span-2">
                <label>Resource Name</label>
                <input type="text" name="resource_name" placeholder="Example: Projector, HDMI Cable, Laptop" required>
            </div>

            <div class="input-group span-2">
                <label>Category</label>
                <select name="category" required>
                    <option value="">Select Category</option>
                    <option value="IT Equipment">IT Equipment</option>
                    <option value="Classroom">Classroom</option>
                    <option value="Events Equipment">Events Equipment</option>
                </select>
            </div>

            <div class="ae-actions">
                <button type="button" class="ae-btn-secondary" id="cancelAddEquipment">Cancel</button>
                <button type="submit" class="ae-btn-primary" id="submitAddEquipment">Add Equipment</button>
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
const openAddBtn = document.getElementById('openAddEquipment');
const addModal = document.getElementById('addEquipmentModal');
const closeAddBtn = document.getElementById('closeAddEquipment');
const cancelAddBtn = document.getElementById('cancelAddEquipment');
const addForm = document.getElementById('addEquipmentForm');
const addMsg = document.getElementById('addEquipMsg');
const submitBtn = document.getElementById('submitAddEquipment');

function showAddModal() {
    addMsg.className = 'ae-modal-message';
    addMsg.textContent = '';
    addModal.classList.add('active');
    document.body.classList.add('ae-modal-open');
    const firstInput = addForm.querySelector('input[name="resource_name"]');
    if (firstInput) firstInput.focus();
}

function hideAddModal() {
    addModal.classList.remove('active');
    document.body.classList.remove('ae-modal-open');
    addForm.reset();
    addForm.querySelector('input[name="ajax"]').value = '1';
    addForm.querySelector('input[name="add"]').value = '1';
    addForm.querySelector('input[name="csrf_token"]').value = document.querySelector('meta[name="csrf-token"]').content;
}

openAddBtn.addEventListener('click', showAddModal);
closeAddBtn.addEventListener('click', hideAddModal);
cancelAddBtn.addEventListener('click', hideAddModal);
addModal.addEventListener('click', (e) => {
    if (e.target === addModal) hideAddModal();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && addModal.classList.contains('active')) hideAddModal();
});

// Sync CSRF token
addForm.querySelector('input[name="csrf_token"]').value = document.querySelector('meta[name="csrf-token"]').content;

addForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    addMsg.className = 'ae-modal-message';
    addMsg.textContent = '';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Adding…';

    try {
        const fd = new FormData(addForm);
        const res = await fetch('add_equipment.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data && data.success) {
            addMsg.className = 'ae-modal-message success show';
            addMsg.textContent = data.message || 'Equipment added.';
            setTimeout(() => location.reload(), 600);
        } else {
            addMsg.className = 'ae-modal-message error show';
            addMsg.textContent = (data && data.message) ? data.message : 'Add failed.';
            submitBtn.disabled = false;
            submitBtn.textContent = 'Add Equipment';
        }
    } catch (err) {
        addMsg.className = 'ae-modal-message error show';
        addMsg.textContent = 'Network error. Please try again.';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Equipment';
    }
});
</script>


<!-- Edit status modal -->
<div class="modal-overlay" id="editStatusModal">
    <div class="modal-box">
        <h3>Edit Equipment Status</h3>
        <div class="modal-info-row">
            <div class="modal-info-group">
                <label>Equipment</label>
                <p id="modalEquipmentName">—</p>
            </div>
            <div class="modal-info-group">
                <label>Category</label>
                <p id="modalCategory">—</p>
            </div>
        </div>
        <div class="modal-status-group">
            <label>Status</label>
            <div class="status-options" id="statusOptions">
                <button type="button" class="status-chip available"   data-value="Available">Available</button>
                <button type="button" class="status-chip in-use"      data-value="In-Use">In-Use</button>
                <button type="button" class="status-chip maintenance" data-value="Under Maintenance">Under Maintenance</button>
            </div>
        </div>
        <p id="editModalMsg" style="color:red; font-size:0.85rem; min-height:1.2em;"></p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
            <button type="button" class="btn-confirm-edit" onclick="submitEditStatus()">Update Status</button>
        </div>
    </div>
</div>




<script>
let _editId = null;
let _selectedStatus = null;

function openEditModal(id, name, category, currentStatus) {
    _editId = id;
    _selectedStatus = currentStatus;
    document.getElementById('modalEquipmentName').textContent = name;
    document.getElementById('modalCategory').textContent = category;
    document.getElementById('editModalMsg').textContent = '';
    document.querySelectorAll('.status-chip').forEach(chip => {
        chip.classList.toggle('selected', chip.dataset.value === currentStatus);
    });
    document.getElementById('editStatusModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editStatusModal').classList.remove('active');
}
document.querySelectorAll('.status-chip').forEach(chip => {
    chip.addEventListener('click', function() {
        document.querySelectorAll('.status-chip').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        _selectedStatus = this.dataset.value;
    });
});
document.getElementById('editStatusModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
function submitEditStatus() {
    if (!_selectedStatus) {
        document.getElementById('editModalMsg').textContent = 'Please select a status.';
        return;
    }
    const btn = document.querySelector('.btn-confirm-edit');
    btn.disabled = true;
    btn.textContent = 'Updating…';
    const form = new FormData();
    form.append('id', _editId);
    form.append('status', _selectedStatus);
    form.append('update', '1');
    form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
    fetch('update_equipment_status.php', { method: 'POST', body: form })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeEditModal();
                location.reload();
            } else {
                document.getElementById('editModalMsg').textContent = data.message || 'Update failed.';
                btn.disabled = false;
                btn.textContent = 'Update Status';
            }
        })
        .catch(() => {
            document.getElementById('editModalMsg').textContent = 'An error occurred.';
            btn.disabled = false;
            btn.textContent = 'Update Status';
        });
}
</script>


<!-- Quick return modal -->
<div class="modal-overlay" id="quickReturnModal">
    <div class="modal-box">
        <h3 style="color:#1a1a2e; display:flex; align-items:center; gap:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="#16a34a">
                <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
            </svg>
            Confirm Return
        </h3>
        <p style="font-size:0.875rem; color:#555; margin: 4px 0 12px;">
            Mark this equipment as returned and set it back to <b>Available</b>:
        </p>
        <div id="qrEquipmentPill" style="display:inline-block; background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; border-radius:8px; padding:6px 14px; font-size:0.9rem; font-weight:600; margin-bottom:16px;">—</div>
        <div>
            <label style="display:block; font-size:0.82rem; font-weight:600; color:#555; margin-bottom:5px;">
                Return Notes <span style="font-weight:400; color:#999;">(optional)</span>
            </label>
            <textarea id="qrRemarks"
                placeholder="Example: Returned in good condition."
                style="width:100%; border:1px solid #ddd; border-radius:8px; padding:9px 12px; font-size:0.875rem; font-family:inherit; resize:vertical; min-height:75px; box-sizing:border-box;"></textarea>
        </div>
        <p id="qrModalMsg" style="color:red; font-size:0.82rem; min-height:1.1em; margin-top:6px;"></p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeQuickReturnModal()">Cancel</button>
            <button type="button" id="qrConfirmBtn"
                style="padding:8px 22px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:0.875rem; font-weight:600; cursor:pointer; font-family:inherit;"
                onclick="submitQuickReturn()">
                Confirm Return
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="equipToast" style="position:fixed; bottom:28px; right:28px; background:#1a1a2e; color:#fff; padding:14px 22px; border-radius:10px; font-size:0.88rem; font-weight:500; box-shadow:0 4px 20px rgba(0,0,0,0.22); opacity:0; transform:translateY(12px); transition:opacity 0.25s,transform 0.25s; pointer-events:none; z-index:99999; max-width:360px; border-left:4px solid #22c55e;"></div>



<script>
let _qrId   = null;
let _qrName = null;

function openReturnModal(equipmentId, equipmentName) {
    _qrId   = equipmentId;
    _qrName = equipmentName;
    document.getElementById('qrEquipmentPill').textContent = equipmentName;
    document.getElementById('qrRemarks').value             = '';
    document.getElementById('qrModalMsg').textContent      = '';
    const btn = document.getElementById('qrConfirmBtn');
    btn.disabled    = false;
    btn.textContent = 'Confirm Return';
    document.getElementById('quickReturnModal').classList.add('active');
}
function closeQuickReturnModal() {
    document.getElementById('quickReturnModal').classList.remove('active');
    _qrId = null; _qrName = null;
}
document.getElementById('quickReturnModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickReturnModal();
});

function submitQuickReturn() {
    if (!_qrId) return;
    const remarks = document.getElementById('qrRemarks').value.trim();
    const btn     = document.getElementById('qrConfirmBtn');
    const msgEl   = document.getElementById('qrModalMsg');
    btn.disabled    = true;
    btn.textContent = 'Processing…';
    msgEl.textContent = '';

    const fd = new FormData();
    fd.append('equipment_id', _qrId);
    fd.append('remarks', remarks);
    fd.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);

    fetch('return_equipment.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeQuickReturnModal();
                const toast = document.getElementById('equipToast');
                toast.textContent = data.message;
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(12px)';
                    setTimeout(() => location.reload(), 300);
                }, 2200);
            } else {
                msgEl.textContent   = data.message || 'An error occurred.';
                btn.disabled        = false;
                btn.textContent     = 'Confirm Return';
            }
        })
        .catch(() => {
            msgEl.textContent   = 'Network error. Please try again.';
            btn.disabled        = false;
            btn.textContent     = 'Confirm Return';
        });
}
</script>

<script>
let _editDetailsId = null;
function openEditDetailsModal(id, resourceName, category) {
    _editDetailsId = id;
    document.getElementById('editDetailsEquipmentId').textContent = `#${id}`;
    document.getElementById('editDetailsResourceName').value = resourceName || '';
    document.getElementById('editDetailsCategory').value = category || '';
    document.getElementById('editDetailsMsg').textContent = '';
    document.getElementById('confirmEditDetailsBtn').disabled = false;
    document.getElementById('confirmEditDetailsBtn').textContent = 'Save Changes';
    document.getElementById('editDetailsModal').classList.add('active');
}
function closeEditDetailsModal() {
    document.getElementById('editDetailsModal').classList.remove('active');
    _editDetailsId = null;
}
document.getElementById('editDetailsModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditDetailsModal();
});
async function submitEditDetails() {
    const resourceName = document.getElementById('editDetailsResourceName').value.trim();
    const category = document.getElementById('editDetailsCategory').value;
    const msgEl = document.getElementById('editDetailsMsg');
    const btn = document.getElementById('confirmEditDetailsBtn');
    if (!_editDetailsId) return;
    if (!resourceName) {
        msgEl.textContent = 'Resource name cannot be empty.';
        return;
    }
    if (!category) {
        msgEl.textContent = 'Please select a category.';
        return;
    }

    msgEl.textContent = '';
    btn.disabled = true;
    btn.textContent = 'Saving...';

    try {
        const form = new FormData();
        form.append('id', _editDetailsId);
        form.append('resource_name', resourceName);
        form.append('category', category);
        form.append('csrf_token', document.querySelector('meta[name="csrf-token"]').content);
        const res = await fetch('update_equipment_details.php', { method: 'POST', body: form });
        const data = await res.json();
        if (data && data.success) {
            closeEditDetailsModal();
            location.reload();
            return;
        }
        msgEl.textContent = (data && data.message) ? data.message : 'Update failed.';
    } catch (e) {
        msgEl.textContent = 'Network error. Please try again.';
    } finally {
        btn.disabled = false;
        btn.textContent = 'Save Changes';
    }
}
</script>

<script>
// Action menu
let _deleteEquipmentForm = null;
function openDeleteEquipmentModal(formEl, message) {
    _deleteEquipmentForm = formEl;
    document.getElementById('deleteEquipmentModalMsg').textContent = 'Delete this equipment record? This action cannot be undone.';
    document.getElementById('deleteEquipmentModal').classList.add('active');
}
function closeDeleteEquipmentModal() {
    document.getElementById('deleteEquipmentModal').classList.remove('active');
    _deleteEquipmentForm = null;
}
document.getElementById('confirmDeleteEquipmentBtn').addEventListener('click', function() {
    if (_deleteEquipmentForm) _deleteEquipmentForm.submit();
});
document.getElementById('deleteEquipmentModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteEquipmentModal();
});

function toggleMenu(btn, event) {
    event.stopPropagation();
    const wrap = btn.closest('.action-menu-wrap');
    const drop = wrap.querySelector('.action-dropdown');
    const isOpen = drop.classList.contains('open');
    closeAllMenus();
    if (!isOpen) {
        drop.classList.add('open');
        btn.classList.add('open');
    }
}
function closeAllMenus() {
    document.querySelectorAll('.action-dropdown.open').forEach(d => {
        d.classList.remove('open');
        d.style.top = '';
        d.style.left = '';
    });
    document.querySelectorAll('.action-kebab.open').forEach(b => b.classList.remove('open'));
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-menu-wrap')) closeAllMenus();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAllMenus();
});
</script>
<script>
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
notifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown.classList.toggle('open');
    if (typeof profileDropdown !== 'undefined') profileDropdown.classList.remove('open');
});
notifDropdown.addEventListener('click', (e) => e.stopPropagation());
document.addEventListener('click', () => notifDropdown.classList.remove('open'));
</script>
</body>
</html>