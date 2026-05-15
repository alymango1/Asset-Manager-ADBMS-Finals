<?php
session_start();
include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

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

$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

// get data for the bell icon
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

$today = date('Y-m-d');

// grab filter values
$filterStatus   = isset($_GET['status'])    ? trim($_GET['status'])    : 'pending';
$filterUser     = isset($_GET['user'])       ? trim(mysqli_real_escape_string($conn, $_GET['user'])) : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'cancelled', 'returned'];
if (!in_array($filterStatus, $allowedStatuses)) $filterStatus = 'pending';

$whereParts = [];
if ($filterStatus !== 'all') {
    $whereParts[] = "r.status = '" . mysqli_real_escape_string($conn, $filterStatus) . "'";
}
if ($filterUser !== '') {
    $whereParts[] = "(u.username LIKE '%$filterUser%' OR u.full_name LIKE '%$filterUser%')";
}
if ($filterDateFrom !== '') {
    $whereParts[] = "r.reserved_date >= '" . mysqli_real_escape_string($conn, $filterDateFrom) . "'";
}
if ($filterDateTo !== '') {
    $whereParts[] = "r.reserved_date <= '" . mysqli_real_escape_string($conn, $filterDateTo) . "'";
}

$whereSQL = count($whereParts) ? 'WHERE ' . implode(' AND ', $whereParts) : '';

// handle pagination
$limit  = 10;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// count groups for pending view
// count rows for other views
$countResult = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u ON r.requested_by = u.user_id
    $whereSQL
");
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages   = max(1, ceil($totalRecords / $limit));

// count by status for the summary
$pendingCount   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='pending'"))['c'];
$approvedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='approved'"))['c'];
$rejectedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='rejected'"))['c'];
$cancelledCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='cancelled'"))['c'];
$returnedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='returned'"))['c'];

// how many pending batches
$batchPendingCount = mysqli_fetch_assoc(mysqli_query($conn, "
    SELECT COUNT(DISTINCT COALESCE(batch_id, CONCAT('single_', reservation_id))) AS c
    FROM reservations WHERE status='pending'
"))['c'];

// main data query
$query = "
    SELECT
        r.reservation_id,
        r.batch_id,
        r.equipment_id,
        r.reserved_date,
        r.reserved_start,
        r.reserved_end,
        r.status,
        r.remarks,
        r.approved_at,
        r.rejected_at,
        r.created_at,
        e.resource_name,
        u.username    AS requester_name,
        u.full_name   AS requester_full,
        u.user_id     AS requester_id,
        app.username  AS approved_by_name,
        rej.username  AS rejected_by_name
    FROM reservations r
    JOIN equipments e   ON r.equipment_id  = e.equipment_id
    LEFT JOIN users u   ON r.requested_by  = u.user_id
    LEFT JOIN users app ON r.approved_by   = app.user_id
    LEFT JOIN users rej ON r.rejected_by   = rej.user_id
    $whereSQL
    ORDER BY
        FIELD(r.status, 'pending', 'approved', 'rejected', 'cancelled', 'returned'),
        r.created_at DESC,
        r.batch_id ASC,
        r.reservation_id ASC
    LIMIT $limit OFFSET $offset
";
$result = mysqli_query($conn, $query);

// group items by batch for pending view
$rows = [];
while ($row = mysqli_fetch_assoc($result)) {
    $rows[] = $row;
}

// group by batch or keep individual
$groups = [];       // [ groupKey => ['batch_id'=>..., 'rows'=>[...], 'is_batch'=>bool] ]
foreach ($rows as $row) {
    if (!empty($row['batch_id'])) {
        $key = 'batch_' . $row['batch_id'];
        if (!isset($groups[$key])) {
            $groups[$key] = ['batch_id' => $row['batch_id'], 'rows' => [], 'is_batch' => true];
        }
        $groups[$key]['rows'][] = $row;
    } else {
        $key = 'single_' . $row['reservation_id'];
        $groups[$key] = ['batch_id' => null, 'rows' => [$row], 'is_batch' => false];
    }
}

function pageUrl(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
}
function tabUrl(string $s): string {
    $p = $_GET; $p['status'] = $s; unset($p['page']); return '?' . http_build_query($p);
}
?>
<!DOCTYPE html>
<html>
<head>
    <title>Reservations</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/reservation.css">
    <link rel="stylesheet" href="../css/admin/modal.css">

</head>
<body>

<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Reservations</h1>
        <p>Check requests, approve schedules, and see past decisions.</p>
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
                        if ($_notifSecsLate < 3600)         $_notifTimeLabel = round($_notifSecsLate/60) . ' min ago';
                        elseif ($_notifSecsLate < 86400)    $_notifTimeLabel = round($_notifSecsLate/3600) . ' hr ago';
                        elseif ($_notifSecsLate < 604800)   $_notifTimeLabel = round($_notifSecsLate/86400) . ' d ago';
                        else                                $_notifTimeLabel = round($_notifSecsLate/604800) . ' wk ago';
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

<main class="main">

    <section class="res-hero">
        <div class="res-hero-copy">
            <p class="eyebrow">Reservation Center</p>
            <h2>Reservation Manager</h2>
            <p class="hero-subtitle">Approve or reject requests, add remarks, and filter by status or date.</p>
        </div>
        <div class="hero-stats">
            <div class="hero-stat">
                <span>Pending</span>
                <strong><?php echo $pendingCount; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Approved</span>
                <strong><?php echo $approvedCount; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Rejected</span>
                <strong><?php echo $rejectedCount; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Returned</span>
                <strong><?php echo $returnedCount; ?></strong>
            </div>
        </div>
    </section>

    <!-- Flash messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="res-message error">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="res-message success">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <section class="content-grid">
        <div class="table-wrap section-card">
            <div class="section-header">
                <h2>Reservation List</h2>

                <!-- Status tabs -->
                <div class="tab-bar">
                    <a href="<?= tabUrl('pending') ?>"   class="tab-btn <?= $filterStatus === 'pending'   ? 'active-pending'   : '' ?>">Pending   <span class="tab-badge"><?= $pendingCount ?></span></a>
                    <a href="<?= tabUrl('approved') ?>"  class="tab-btn <?= $filterStatus === 'approved'  ? 'active-approved'  : '' ?>">Approved  <span class="tab-badge"><?= $approvedCount ?></span></a>
                    <a href="<?= tabUrl('rejected') ?>"  class="tab-btn <?= $filterStatus === 'rejected'  ? 'active-rejected'  : '' ?>">Rejected  <span class="tab-badge"><?= $rejectedCount ?></span></a>
                    <a href="<?= tabUrl('cancelled') ?>" class="tab-btn <?= $filterStatus === 'cancelled' ? 'active-cancelled' : '' ?>">Cancelled <span class="tab-badge"><?= $cancelledCount ?></span></a>
                    <a href="<?= tabUrl('returned') ?>"  class="tab-btn <?= $filterStatus === 'returned'  ? 'active-returned'  : '' ?>">Returned  <span class="tab-badge"><?= $returnedCount ?></span></a>
                    <a href="<?= tabUrl('all') ?>"       class="tab-btn <?= $filterStatus === 'all'       ? 'active-all'       : '' ?>">All History</a>
                </div>

                <!-- Filters -->
                <div class="filter-wrap">
                    <form method="GET" action="" id="filterForm" class="filter-bar">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <div class="fg fg-search">
                            <label>Search User</label>
                            <input type="text" name="user" placeholder="Username or full name" value="<?= htmlspecialchars($filterUser) ?>">
                        </div>
                        <div class="fg">
                            <label>Date From</label>
                            <input type="date" name="date_from" value="<?= htmlspecialchars($filterDateFrom) ?>">
                        </div>
                        <div class="fg">
                            <label>Date To</label>
                            <input type="date" name="date_to" value="<?= htmlspecialchars($filterDateTo) ?>">
                        </div>
                        <button type="submit" class="btn-filter">Filter</button>
                        <a href="?status=<?= htmlspecialchars($filterStatus) ?>" class="btn-clear">Clear</a>
                    </form>
                    <p class="result-meta">
                        Showing <strong><?= min($offset + 1, $totalRecords) ?>–<?= min($offset + $limit, $totalRecords) ?></strong>
                        of <strong><?= $totalRecords ?></strong> item<?= $totalRecords !== 1 ? 's' : '' ?>
                        <?php if ($filterStatus === 'pending' && $batchPendingCount > 0): ?>
                            &nbsp;·&nbsp; <strong><?= $batchPendingCount ?></strong> request<?= $batchPendingCount !== 1 ? 's' : '' ?>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- ══════════════════════════════════════════
                 GROUPED / BATCHED RESERVATION LIST
            ══════════════════════════════════════════ -->
            <?php if (empty($groups)): ?>
                <div class="empty-state-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" height="48px" viewBox="0 -960 960 960" width="48px" fill="#ccc"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm200-198q13 0 21.5-8.5T510-820t-8.5-21.5T480-850t-21.5 8.5T450-820t8.5 21.5T480-798ZM200-200v-560 560Z"/></svg>
                    <p>No reservations match your filters.</p>
                </div>
            <?php else: ?>
                <?php foreach ($groups as $groupKey => $group):
                    $isBatch   = $group['is_batch'];
                    $batchId   = $group['batch_id'];
                    $groupRows = $group['rows'];
                    $firstRow  = $groupRows[0];
                    $isPending = ($firstRow['status'] === 'pending');
                    $itemCount = count($groupRows);

                    // Requester initials for avatar
                    $rFullName = trim($firstRow['requester_full'] ?? $firstRow['requester_name'] ?? 'U');
                    $rParts    = preg_split('/\s+/', $rFullName);
                    $rFirst    = $rParts[0] ?? '';
                    $rLast     = count($rParts) > 1 ? $rParts[count($rParts)-1] : '';
                    $rInitials = strtoupper(substr($rFirst,0,1) . ($rLast ? substr($rLast,0,1) : substr($rFirst,1,1)));
                    $rInitials = $rInitials ?: 'U';

                    // Build list of pending IDs in this batch
                    $pendingIds = [];
                    foreach ($groupRows as $r) {
                        if ($r['status'] === 'pending') $pendingIds[] = $r['reservation_id'];
                    }
                ?>

                <?php if ($isBatch && $itemCount > 1): /* ── BATCH GROUP ── */ ?>
                <div class="batch-group-block">

                    <!-- ① IDENTITY BANNER — always visible, impossible to miss -->
                    <div class="batch-identity-banner">
                        <div class="batch-identity-left">
                            <div class="batch-identity-icon">
                                <svg xmlns="http://www.w3.org/2000/svg" height="17px" viewBox="0 -960 960 960" width="17px" fill="currentColor"><path d="M160-160q-33 0-56.5-23.5T80-240v-480q0-33 23.5-56.5T160-800h240l80 80h320q33 0 56.5 23.5T880-640H447l-80-80H160v480l96-320h684L837-160H160Zm84-80h516l72-240H316l-72 240Zm0 0 72-240-72 240Zm-84-400v-80 80Z"/></svg>
                            </div>
                            <div class="batch-identity-text">
                                <span class="batch-identity-title">Batch Request</span>
                                <span class="batch-identity-sub">Multiple items submitted together &mdash; review as a group</span>
                            </div>
                        </div>
                        <span class="batch-identity-count">
                            <span class="batch-identity-count-dot"></span>
                            <?= $itemCount ?> item<?= $itemCount !== 1 ? 's' : '' ?>
                        </span>
                    </div>

                    <!-- ② INFO ROW — requester, date, time -->
                    <div class="batch-group-header">
                        <div class="single-card-fields" style="background:transparent;">
                            <!-- Requested By -->
                            <div class="single-card-field" style="min-width:160px;flex:1.4;background:transparent;">
                                <span class="single-card-field-label">Requested By</span>
                                <div class="requester-chip" style="margin-top:1px;">
                                    <div class="requester-avatar"><?= $rInitials ?></div>
                                    <div class="requester-info">
                                        <span class="requester-name"><?= htmlspecialchars($firstRow['requester_name']) ?></span>
                                        <?php if ($firstRow['requester_full']): ?>
                                        <span class="requester-sub"><?= htmlspecialchars($firstRow['requester_full']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Date + Submitted -->
                            <div class="single-card-field" style="min-width:130px;background:transparent;">
                                <span class="single-card-field-label">Date</span>
                                <?php $batchIsPast = ($firstRow['reserved_date'] < $today); ?>
                                <span class="single-card-field-value">
                                    <?= date('M j, Y', strtotime($firstRow['reserved_date'])) ?>
                                    <?php if ($batchIsPast && $isPending): ?><span class="past-badge">PAST</span><?php endif; ?>
                                </span>
                                <span class="single-card-field-sub">Submitted <?= date('M j, g:i a', strtotime($firstRow['created_at'])) ?></span>
                            </div>
                            <!-- Time Frame -->
                            <?php if (!empty($firstRow['reserved_start'])): ?>
                            <div class="single-card-field" style="min-width:130px;background:transparent;">
                                <span class="single-card-field-label">Time Frame</span>
                                <span class="single-card-field-value"><?= date('g:i a', strtotime($firstRow['reserved_start'])) ?> – <?= date('g:i a', strtotime($firstRow['reserved_end'])) ?></span>
                            </div>
                            <?php endif; ?>
                        </div>
                        <!-- Chevron toggle pinned right -->
                        <button class="batch-chevron-btn" onclick="toggleBatch(this)" aria-label="Toggle items" style="position:relative;top:auto;right:auto;width:32px;height:32px;margin:auto 14px auto 8px;border-radius:9px;background:rgba(200,16,46,0.07);border-color:rgba(200,16,46,0.18);">
                            <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor"><path d="M480-345 240-585l56-56 184 184 184-184 56 56-240 240Z"/></svg>
                        </button>
                    </div>

                    <!-- ③ ACTIONS BAR — only shown when pending -->
                    <?php if ($isPending && count($pendingIds) > 0): ?>
                    <div class="batch-actions-bar">
                        <div class="batch-actions-bar-label">
                            <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                            This is a batch - Contains <?= $itemCount ?> items.
                        </div>
                        <div class="batch-actions-bar-btns">
                            <button class="btn-batch-approve"
                                    onclick="openBatchApproveModal('<?= htmlspecialchars($batchId) ?>', <?= $itemCount ?>)">
                                <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                                Approve All
                            </button>
                            <button class="btn-batch-reject"
                                    onclick="openBatchRejectModal('<?= htmlspecialchars($batchId) ?>', <?= $itemCount ?>)">
                                <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                                Reject All
                            </button>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Batch items table -->
                    <div class="batch-group-body">
                    <table class="batch-inner-table">
                        <tr>
                            <th style="width:34px;">#</th>
                            <th>Equipment</th>
                            <th>Status</th>
                            <th>Reserved Date</th>
                            <th>Time Frame</th>
                            <?php if ($filterStatus !== 'pending'): ?><th>Details</th><?php endif; ?>
                            <?php if ($filterStatus === 'pending' || $filterStatus === 'all'): ?><th>Action</th><?php endif; ?>
                        </tr>
                        <?php foreach ($groupRows as $i => $row):
                            $isPast    = ($row['reserved_date'] < $today);
                            $isOverdue = ($row['status'] === 'approved' && !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time());
                        ?>
                        <tr class="<?= $isOverdue ? 'overdue-row' : '' ?>">
                            <td><span class="item-num" style="width:auto;padding:0 7px;font-size:9.5px;">#<?= $row['reservation_id'] ?></span></td>
                            <td><strong><?= htmlspecialchars($row['resource_name']) ?></strong></td>
                            <td class="status <?= strtolower($row['status']) ?>">
                                <span class="status-pill"><?= strtoupper($row['status']) ?></span>
                                <?php if ($isOverdue): ?><br><span class="overdue-badge">Overdue</span><?php endif; ?>
                            </td>
                            <td>
                                <?= date('Y-m-d', strtotime($row['reserved_date'])) ?>
                                <?php if ($isPast && $row['status'] === 'pending'): ?><span class="past-badge">PAST</span><?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($row['reserved_start'])): ?>
                                    <?= date('g:i a', strtotime($row['reserved_start'])) ?> – <?= date('g:i a', strtotime($row['reserved_end'])) ?>
                                <?php else: ?><span style="color:#ccc;">—</span><?php endif; ?>
                            </td>
                            <?php if ($filterStatus !== 'pending'): ?>
                            <td class="detail-cell" style="font-size:12px;">
                                <?php if ($row['status'] === 'approved'): ?>
                                    Approved by <b><?= htmlspecialchars($row['approved_by_name'] ?? '—') ?></b><br>
                                    <?= $row['approved_at'] ?? '—' ?>
                                <?php elseif ($row['status'] === 'rejected'): ?>
                                    Rejected by <b><?= htmlspecialchars($row['rejected_by_name'] ?? '—') ?></b><br>
                                    Reason: <span class="reason"><?= htmlspecialchars($row['remarks'] ?? '—') ?></span>
                                <?php else: ?>—<?php endif; ?>
                            </td>
                            <?php endif; ?>
                            <?php if ($filterStatus === 'pending' || $filterStatus === 'all'): ?>
                            <td class="actions">
                                <?php if ($row['status'] === 'pending'): ?>
                                    <form method="POST" action="../admin/approve.php" style="display:inline;" class="approve-reservation-form">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                        <input type="hidden" name="id" value="<?= $row['reservation_id'] ?>">
                                        <button type="button" class="btn-approve" onclick="openApproveReservationModal(this.form)">
                                            <svg xmlns="http://www.w3.org/2000/svg" height="13px" viewBox="0 -960 960 960" width="13px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                                            Approve
                                        </button>
                                    </form>
                                    <button class="btn-reject" onclick="openRejectModal(<?= $row['reservation_id'] ?>)">
                                        <svg xmlns="http://www.w3.org/2000/svg" height="13px" viewBox="0 -960 960 960" width="13px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                                        Reject
                                    </button>
                                <?php else: ?><span style="color:#ccc;font-size:12px;">—</span><?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                    </table>
                    </div><!-- /.batch-group-body -->
                </div><!-- /.batch-group-block -->

                <?php else: /* ── SINGLE RESERVATION (no batch or batch of 1) ── */ ?>
                <?php $row = $firstRow;
                      $isPast    = ($row['reserved_date'] < $today);
                      $isOverdue = ($row['status'] === 'approved' && !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time());
                ?>
                <?php
                    $singleStatusClass = 'status-' . strtolower($row['status']);
                    $singleOverdueClass = $isOverdue ? 'overdue-row' : '';

                    // Icon SVG path per status
                    $singleIcons = [
                        'pending'   => 'M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z',
                        'approved'  => 'M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z',
                        'rejected'  => 'm256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z',
                        'cancelled' => 'M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q54 0 104-17.5t92-50.5L228-676q-33 42-50.5 92T160-480q0 134 93 227t227 93Zm252-124q33-42 50.5-92T800-480q0-134-93-227t-227-93q-54 0-104 17.5T284-732l448 448Z',
                        'returned'  => 'M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm-40-120h80v-240h-80v240Zm40-320q17 0 28.5-11.5T520-560t-8.5-21.5T480-590t-21.5 8.5T450-560t8.5 21.5T480-520Z',
                    ];
                    $singleIconPath = $singleIcons[strtolower($row['status'])] ?? $singleIcons['pending'];
                    $singleLabel = $isOverdue ? 'Overdue · Individual Request' : ucfirst($row['status']) . ' · Individual Request';
                ?>
                <div class="single-row-wrap <?= $singleStatusClass ?> <?= $singleOverdueClass ?>">
                    <!-- Identity banner -->
                    <div class="single-identity-banner <?= $singleStatusClass ?> <?= $singleOverdueClass ?>">
                        <div class="single-identity-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor"><path d="<?= $singleIconPath ?>"/></svg>
                        </div>
                        <span class="single-identity-label"><?= $singleLabel ?></span>
                        <span class="single-identity-id">ITEM #<?= $row['reservation_id'] ?></span>
                    </div>
                    <div class="single-card-body">
                        <div class="single-card-fields">
                            <!-- Reservation ID column -->
                            <div class="single-card-field" style="min-width:54px;flex:0 0 54px;">
                                <span class="single-card-field-label">ID</span>
                                <span class="single-card-field-value" style="font-size:13px;font-weight:800;color:#C8102E;"><?= $row['reservation_id'] ?></span>
                            </div>
                            <!-- Equipment name -->
                            <div class="single-card-field" style="min-width:130px;flex:1.2;">
                                <span class="single-card-field-label">Equipment</span>
                                <span class="single-card-field-value">
                                    <?= htmlspecialchars($row['resource_name']) ?>
                                </span>
                                <div class="status <?= strtolower($row['status']) ?>" style="margin-top:2px;">
                                    <span class="status-pill" style="font-size:9px;padding:2px 8px;"><?= ucfirst($row['status']) ?></span>
                                    <?php if ($isOverdue): ?><span class="overdue-badge" style="font-size:8.5px;">Overdue</span><?php endif; ?>
                                </div>
                            </div>
                            <!-- Requested By -->
                            <div class="single-card-field" style="min-width:150px;flex:1.4;">
                                <span class="single-card-field-label">Requested By</span>
                                <div class="requester-chip" style="margin-top:1px;">
                                    <div class="requester-avatar"><?= $rInitials ?></div>
                                    <div class="requester-info">
                                        <span class="requester-name"><?= htmlspecialchars($row['requester_name']) ?></span>
                                        <?php if ($row['requester_full']): ?>
                                        <span class="requester-sub"><?= htmlspecialchars($row['requester_full']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <!-- Date + Submitted merged -->
                            <div class="single-card-field" style="min-width:120px;">
                                <span class="single-card-field-label">Date</span>
                                <span class="single-card-field-value">
                                    <?= date('M j, Y', strtotime($row['reserved_date'])) ?>
                                    <?php if ($isPast && $row['status'] === 'pending'): ?><span class="past-badge">PAST</span><?php endif; ?>
                                </span>
                                <span class="single-card-field-sub">Submitted <?= date('M j, g:i a', strtotime($row['created_at'])) ?></span>
                            </div>
                            <!-- Time Frame inline -->
                            <div class="single-card-field" style="min-width:120px;">
                                <span class="single-card-field-label">Time Frame</span>
                                <?php if (!empty($row['reserved_start'])): ?>
                                    <span class="single-card-field-value"><?= date('g:i a', strtotime($row['reserved_start'])) ?> – <?= date('g:i a', strtotime($row['reserved_end'])) ?></span>
                                <?php else: ?>
                                    <span class="single-card-field-value muted">—</span>
                                <?php endif; ?>
                            </div>
                            <!-- Details (non-pending only) -->
                            <?php if ($filterStatus !== 'pending'): ?>
                            <div class="single-card-field" style="min-width:130px;flex:1;">
                                <span class="single-card-field-label">Details</span>
                                <?php if ($row['status'] === 'approved'): ?>
                                    <span class="single-card-field-value" style="font-size:12px;">by <b><?= htmlspecialchars($row['approved_by_name'] ?? '—') ?></b></span>
                                    <span class="single-card-field-sub"><?= $row['approved_at'] ?? '—' ?></span>
                                <?php elseif ($row['status'] === 'rejected'): ?>
                                    <span class="single-card-field-value" style="font-size:12px;">by <b><?= htmlspecialchars($row['rejected_by_name'] ?? '—') ?></b></span>
                                    <span class="single-card-field-sub reason" style="white-space:normal;max-width:160px;"><?= htmlspecialchars($row['remarks'] ?? '—') ?></span>
                                <?php else: ?>
                                    <span class="single-card-field-value muted">—</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <!-- Actions -->
                        <?php if ($filterStatus === 'pending' || $filterStatus === 'all'): ?>
                        <div class="single-card-actions">
                            <?php if ($row['status'] === 'pending'): ?>
                                <form method="POST" action="../admin/approve.php" id="approveForm-<?= $row['reservation_id'] ?>" class="approve-reservation-form" style="display:none;">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                                    <input type="hidden" name="id" value="<?= $row['reservation_id'] ?>">
                                </form>
                                <button type="button" class="btn-approve" onclick="openApproveReservationModal(document.getElementById('approveForm-<?= $row['reservation_id'] ?>'))">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="13px" viewBox="0 -960 960 960" width="13px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                                    Approve
                                </button>
                                <button class="btn-reject" onclick="openRejectModal(<?= $row['reservation_id'] ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="13px" viewBox="0 -960 960 960" width="13px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                                    Reject
                                </button>
                            <?php else: ?><span style="color:#ccc;font-size:12px;text-align:center;">—</span><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; /* end single/batch switch */ ?>

                <?php endforeach; /* end groups loop */ ?>
            <?php endif; /* end empty check */ ?>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="<?= pageUrl(1) ?>" class="<?= $page === 1 ? 'disabled' : '' ?>">&laquo; First</a>
                <?php if ($page > 1): ?><a href="<?= pageUrl($page - 1) ?>">&lsaquo; Prev</a><?php endif; ?>
                <?php for ($i = max(1,$page-2); $i <= min($totalPages,$page+2); $i++): ?>
                    <a href="<?= pageUrl($i) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?><a href="<?= pageUrl($page + 1) ?>">Next &rsaquo;</a><?php endif; ?>
                <a href="<?= pageUrl($totalPages) ?>" class="<?= $page === $totalPages ? 'disabled' : '' ?>">Last &raquo;</a>
            </div>
            <?php endif; ?>

        </div><!-- /.table-wrap -->
    </section>

</main>

<!-- ═══════════════════════════ MODALS ═══════════════════════════ -->

<!-- Reject single reservation -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3>Reject Reservation</h3>
        <p>Please provide a reason for rejecting this reservation request.</p>
        <form method="POST" action="../admin/reject.php" id="rejectForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="id" id="rejectId">
            <textarea name="remarks" id="rejectRemarks" placeholder="Enter reason here..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-confirm-reject">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>

<!-- Approve single reservation -->
<div class="modal-overlay" id="approveReservationModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeApproveReservationModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
            </span>
        </div>
        <h3>Approve Reservation?</h3>
        <p class="confirm-body">This will approve the selected equipment for the requested time slot.</p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="approveReservationConfirmBtn">Approve</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeApproveReservationModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Batch Approve modal -->
<div class="modal-overlay" id="batchApproveModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeBatchApproveModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon" style="background:#dcfce7;">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="#16a34a"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
            </span>
        </div>
        <h3>Approve Entire Batch?</h3>
        <p class="confirm-body" id="batchApproveDesc">All <strong id="batchApproveCount"></strong> items in this batch will be approved at once.</p>
        <form method="POST" action="../admin/batch_approve.php" id="batchApproveForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="batch_id" id="batchApproveId">
        </form>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" style="background:#16a34a;" onclick="document.getElementById('batchApproveForm').submit()">Approve All</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeBatchApproveModal()">Cancel</button>
        </div>
    </div>
</div>

<!-- Batch Reject modal -->
<div class="modal-overlay" id="batchRejectModal">
    <div class="modal-box">
        <h3>Reject Entire Batch</h3>
        <p id="batchRejectDesc">Reject all <strong id="batchRejectCount"></strong> items in this batch. Please provide a reason.</p>
        <form method="POST" action="../admin/batch_reject.php" id="batchRejectForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            <input type="hidden" name="batch_id" id="batchRejectId">
            <textarea name="remarks" id="batchRejectRemarks" placeholder="Reason for rejection..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeBatchRejectModal()">Cancel</button>
                <button type="submit" class="btn-confirm-reject">Confirm Reject All</button>
            </div>
        </form>
    </div>
</div>

<!-- Notice modal -->
<div class="modal-overlay" id="noticeModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeNoticeModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Z"/></svg>
            </span>
        </div>
        <h3>Please check this</h3>
        <p id="noticeModalMessage" class="confirm-body">Please review your input.</p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-secondary" onclick="closeNoticeModal()">Close</button>
        </div>
    </div>
</div>

<script>

/* ── Profile & notification toggles ── */
const profileBtn      = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', (e) => { e.stopPropagation(); profileDropdown.classList.toggle('open'); });
document.addEventListener('click', () => { profileDropdown.classList.remove('open'); });

const notifBtn      = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
notifBtn.addEventListener('click', (e) => { e.stopPropagation(); notifDropdown.classList.toggle('open'); profileDropdown.classList.remove('open'); });
notifDropdown.addEventListener('click', (e) => e.stopPropagation());
document.addEventListener('click', () => notifDropdown.classList.remove('open'));

/* ── Single approve ── */
let _approveReservationForm = null;
function openApproveReservationModal(formEl) {
    _approveReservationForm = formEl;
    document.getElementById('approveReservationModal').classList.add('active');
}
function closeApproveReservationModal() {
    document.getElementById('approveReservationModal').classList.remove('active');
    _approveReservationForm = null;
}
document.getElementById('approveReservationConfirmBtn').addEventListener('click', function() {
    if (_approveReservationForm) _approveReservationForm.submit();
});
document.getElementById('approveReservationModal').addEventListener('click', function(e) { if (e.target === this) closeApproveReservationModal(); });

/* ── Single reject ── */
function openRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectRemarks').value = '';
    document.getElementById('rejectModal').classList.add('active');
}
function closeRejectModal() { document.getElementById('rejectModal').classList.remove('active'); }
document.getElementById('rejectModal').addEventListener('click', function(e) { if (e.target === this) closeRejectModal(); });

/* ── Batch approve ── */
function openBatchApproveModal(batchId, count) {
    document.getElementById('batchApproveId').value   = batchId;
    document.getElementById('batchApproveCount').textContent = count;
    document.getElementById('batchApproveModal').classList.add('active');
}
function closeBatchApproveModal() { document.getElementById('batchApproveModal').classList.remove('active'); }
document.getElementById('batchApproveModal').addEventListener('click', function(e) { if (e.target === this) closeBatchApproveModal(); });

/* ── Batch reject ── */
function openBatchRejectModal(batchId, count) {
    document.getElementById('batchRejectId').value    = batchId;
    document.getElementById('batchRejectCount').textContent = count;
    document.getElementById('batchRejectRemarks').value = '';
    document.getElementById('batchRejectModal').classList.add('active');
}
function closeBatchRejectModal() { document.getElementById('batchRejectModal').classList.remove('active'); }
document.getElementById('batchRejectModal').addEventListener('click', function(e) { if (e.target === this) closeBatchRejectModal(); });

/* ── Notice ── */
function openNoticeModal(msg) { document.getElementById('noticeModalMessage').textContent = msg; document.getElementById('noticeModal').classList.add('active'); }
function closeNoticeModal() { document.getElementById('noticeModal').classList.remove('active'); }
document.getElementById('noticeModal').addEventListener('click', function(e) { if (e.target === this) closeNoticeModal(); });

/* ── Batch toggle collapse/expand ── */
function toggleBatch(btn) {
    const block = btn.closest('.batch-group-block');
    const body  = block.querySelector('.batch-group-body');
    const isCollapsed = body.classList.toggle('collapsed');
    btn.classList.toggle('collapsed', isCollapsed);
}

/* ── Filter date validation ── */
document.getElementById('filterForm').addEventListener('submit', function(e) {
    const from = this.date_from.value, to = this.date_to.value;
    if (from && to && from > to) {
        e.preventDefault();
        openNoticeModal('"Date From" cannot be later than "Date To".');
    }
});
document.querySelectorAll('#filterForm input[type="date"]').forEach((input) => {
    const openPicker = () => { if (typeof input.showPicker === 'function') { try { input.showPicker(); } catch(e){} } };
    input.addEventListener('click', openPicker);
    input.addEventListener('focus', openPicker);
});
</script>
</body>
</html>