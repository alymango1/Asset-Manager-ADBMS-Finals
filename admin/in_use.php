<?php
require_once __DIR__ . '/../database/db.php';
session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

date_default_timezone_set('Asia/Manila');

mysqli_query($conn, "SET time_zone = '+08:00'");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: login.php');
    exit();
}

$name = 'User';
if (isset($_SESSION['full_name'])) {
    $parts = explode(' ', trim($_SESSION['full_name']));
    $name  = $parts[0];
}

// make initials from their name
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$nameParts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $nameParts[0] ?? '';
$last  = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '';
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

// show success message after return
$flash = isset($_GET['returned']) ? 'Equipment successfully marked as Returned.' : '';

// handle filters
$filterSearch   = trim($_GET['search']   ?? '');
$filterCategory = trim($_GET['category'] ?? '');
$filterOverdue  = trim($_GET['overdue']  ?? '');

$whereParts = ["e.status = 'In-Use'"];
if ($filterSearch !== '') {
    $esc = mysqli_real_escape_string($conn, $filterSearch);
    $whereParts[] = "(e.resource_name LIKE '%$esc%' OR ru.full_name LIKE '%$esc%')";
}
if ($filterCategory !== '') {
    $esc = mysqli_real_escape_string($conn, $filterCategory);
    $whereParts[] = "e.categories = '$esc'";
}
if ($filterOverdue === '1') {
    $whereParts[] = "(r.reserved_end IS NOT NULL AND r.reserved_end < NOW())";
}
$whereSQL = implode(' AND ', $whereParts);

// get all rows then group and paginate
$inUseQuery = mysqli_query($conn, "
    SELECT
        e.equipment_id,
        e.resource_name,
        e.categories,
        e.status,
        r.reservation_id,
        r.batch_id,
        r.reserved_date,
        r.reserved_start,
        r.reserved_end,
        r.approved_at,
        ru.full_name  AS requester_name,
        au.full_name  AS approver_name
    FROM equipments e
    LEFT JOIN reservations r
        ON  r.equipment_id = e.equipment_id
        AND r.status       = 'approved'
        AND r.approved_at  = (
            SELECT MAX(r2.approved_at)
            FROM   reservations r2
            WHERE  r2.equipment_id = e.equipment_id
              AND  r2.status       = 'approved'
        )
    LEFT JOIN users ru ON r.requested_by = ru.user_id
    LEFT JOIN users au ON r.approved_by  = au.user_id
    WHERE $whereSQL
    ORDER BY COALESCE(r.batch_id, ''), e.equipment_id ASC
");

// group items by batch or keep solo
$allGroups  = [];
$inUseCount = 0;
while ($row = mysqli_fetch_assoc($inUseQuery)) {
    $inUseCount++;
    if (!empty($row['batch_id'])) {
        $key = 'batch_' . $row['batch_id'];
        if (!isset($allGroups[$key])) {
            $allGroups[$key] = ['batch_id' => $row['batch_id'], 'rows' => [], 'is_batch' => true];
        }
        $allGroups[$key]['rows'][] = $row;
    } else {
        $key = 'single_' . $row['equipment_id'];
        $allGroups[$key] = ['batch_id' => null, 'rows' => [$row], 'is_batch' => false];
    }
}

// paginate the groups
$perPage     = 10;
$totalGroups = count($allGroups);
$totalPages  = max(1, (int)ceil($totalGroups / $perPage));
$page        = max(1, min($totalPages, (int)($_GET['page'] ?? 1)));
$inUseGroups = array_slice($allGroups, ($page - 1) * $perPage, $perPage, true);

// carry filters into page links
$pagerParams = [];
if ($filterSearch   !== '') $pagerParams['search']   = $filterSearch;
if ($filterCategory !== '') $pagerParams['category'] = $filterCategory;
if ($filterOverdue  !== '') $pagerParams['overdue']  = $filterOverdue;

// how many are overdue
$overdueCountResult = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.status = 'approved'
      AND e.status = 'In-Use'
      AND r.reserved_end IS NOT NULL
      AND r.reserved_end < NOW()
");
$overdueTotal = $overdueCountResult ? (int)mysqli_fetch_assoc($overdueCountResult)['total'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <title>In-Use Equipment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/in_use.css">
    <link rel="stylesheet" href="../css/admin/reservation_batch_embed.css">
    <link rel="stylesheet" href="../css/admin/modal.css">

</head>
<body>

<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>In-Use / Returns</h1>
        <p>Track active assets and process returns instantly.</p>
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

<main class="main">
    <section class="inuse-hero">
        <div class="inuse-hero-copy">
            <p class="eyebrow">Operations</p>
            <h2>Active equipments</h2>
            <p class="hero-subtitle">See which items are currently in-use, confirm returns, and keep inventory accurate.</p>
        </div>
        <div class="inuse-count">
            <span>In-Use now</span>
            <strong id="inUseCount"><?php echo (int)$inUseCount; ?></strong>
        </div>
    </section>

    <?php if ($overdueTotal > 0): ?>
    <div class="overdue-banner">
        <div class="overdue-banner-text">
            <div class="overdue-banner-eyebrow">
                <span class="overdue-banner-eyebrow-dot"></span>
                Critical — Overdue
            </div>
            <strong>⚠ Equipment not yet returned!</strong>
            <span><?= $overdueTotal ?> item<?= $overdueTotal !== 1 ? 's have' : ' has' ?> passed <?= $overdueTotal !== 1 ? 'their' : 'its' ?> reserved time slot and <?= $overdueTotal !== 1 ? 'have' : 'has' ?> not been returned. Immediate action required.</span>
        </div>
        <div class="overdue-banner-count">
            <strong><?= $overdueTotal ?></strong>
            <span class="overdue-banner-label">item<?= $overdueTotal !== 1 ? 's' : '' ?> overdue</span>
        </div>
    </div>
    <?php endif; ?>

    <section class="content-grid">
        <div class="table-wrap section-card">
            <div class="section-header">
                <h2>Currently In-Use Equipments</h2>
            </div>

            <!-- ── Filter bar ── -->
            <form method="GET" action="" class="inuse-filter-bar">
                <div class="inuse-search-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="#999"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    <input type="text" name="search" placeholder="Search by equipment or requester…" value="<?= htmlspecialchars($filterSearch) ?>" autocomplete="off">
                </div>
                <select name="category">
                    <option value="">All Categories</option>
                    <option value="IT Equipment"     <?= $filterCategory === 'IT Equipment'     ? 'selected' : '' ?>>IT Equipment</option>
                    <option value="Classroom"        <?= $filterCategory === 'Classroom'        ? 'selected' : '' ?>>Classroom</option>
                    <option value="Events Equipment" <?= $filterCategory === 'Events Equipment' ? 'selected' : '' ?>>Events Equipment</option>
                </select>
                <select name="overdue">
                    <option value="">All Status</option>
                    <option value="1" <?= $filterOverdue === '1' ? 'selected' : '' ?>>Overdue only</option>
                </select>
                <button type="submit" class="inuse-btn-search">Search</button>
                <?php if ($filterSearch !== '' || $filterCategory !== '' || $filterOverdue !== ''): ?>
                    <a href="in_use.php" class="inuse-btn-clear">&#x2715; Clear</a>
                <?php endif; ?>
            </form>
            <p class="inuse-result-count">
                <?php if ($filterSearch !== '' || $filterCategory !== '' || $filterOverdue !== ''): ?>
                    Showing <strong><?= $totalGroups ?></strong> result<?= $totalGroups !== 1 ? 's' : '' ?>
                    <?php if ($filterSearch !== ''): ?> for <strong>"<?= htmlspecialchars($filterSearch) ?>"</strong><?php endif; ?>
                <?php else: ?>
                    <strong id="groupCount"><?= $totalGroups ?></strong> group<?= $totalGroups !== 1 ? 's' : '' ?> in use
                <?php endif; ?>
            </p>

        <?php if ($flash): ?>
        <div class="flash-success">
            <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            <?php echo htmlspecialchars($flash); ?>
        </div>
        <?php endif; ?>

        <?php if ($inUseCount === 0): ?>
        <div class="empty-state">
            <svg xmlns="http://www.w3.org/2000/svg" height="64px" viewBox="0 -960 960 960" width="64px" fill="#888">
                <path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
            </svg>
            <h3>No equipment currently in use</h3>
            <p>All items are available or under maintenance.</p>
        </div>
        <?php else: ?>

        <table class="transaction_table equipment" width="100%" cellpadding="10" cellspacing="0">

            <?php foreach ($inUseGroups as $group):
                $isBatch  = $group['is_batch'];
                $batchId  = $group['batch_id'];
                $rows     = $group['rows'];
                $first    = $rows[0];
                $groupAnyOverdue = false;
                foreach ($rows as $r) {
                    if (!empty($r['reserved_end']) && strtotime($r['reserved_end']) < time()) {
                        $groupAnyOverdue = true; break;
                    }
                }

                if ($isBatch):
                    $batchEqIds    = array_column($rows, 'equipment_id');
                    $batchEqNames  = array_column($rows, 'resource_name');
                    $batchRowId    = 'batch-' . $batchId;
                    $batchCount    = count($rows);
                    $rFullName     = trim((string)($first['requester_name'] ?? 'U'));
                    $rParts        = $rFullName !== '' ? preg_split('/\s+/', $rFullName) : [];
                    $rFirst        = $rParts[0] ?? '';
                    $rLast         = count($rParts) > 1 ? $rParts[count($rParts) - 1] : '';
                    $rInitials     = strtoupper(substr($rFirst, 0, 1) . ($rLast !== '' ? substr($rLast, 0, 1) : substr($rFirst, 1, 1)));
                    $rInitials     = $rInitials !== '' ? $rInitials : 'U';
                    $approvedSub   = '';
                    if (!empty($first['approved_at'])) {
                        $approvedSub = 'Approved ' . date('M j, g:i a', strtotime($first['approved_at']));
                    }
            ?>
            <tr id="<?= htmlspecialchars($batchRowId) ?>"
                class="batch-inuse-embed-tr<?= $groupAnyOverdue ? ' overdue-row' : '' ?>"
                tabindex="0"
                aria-expanded="true"
                data-batch-toggle="<?= htmlspecialchars($batchId) ?>">
                <td colspan="6" class="batch-inuse-embed-td">
                    <div class="batch-group-block<?= $groupAnyOverdue ? ' batch-group-block--inuse-overdue' : '' ?>" data-in-use-batch-root="1" data-batch-id="<?= htmlspecialchars($batchId) ?>">

                        <div class="batch-identity-banner">
                            <div class="batch-identity-left">
                                <div class="batch-identity-icon" aria-hidden="true">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="17px" viewBox="0 -960 960 960" width="17px" fill="currentColor"><path d="M160-160q-33 0-56.5-23.5T80-240v-480q0-33 23.5-56.5T160-800h240l80 80h320q33 0 56.5 23.5T880-640H447l-80-80H160v480l96-320h684L837-160H160Zm84-80h516l72-240H316l-72 240Zm0 0 72-240-72 240Zm-84-400v-80 80Z"/></svg>
                                </div>
                                <div class="batch-identity-text">
                                    <span class="batch-identity-title">Batch Request</span>
                                    <span class="batch-identity-sub">Multiple items checked out together &mdash; return as a group</span>
                                </div>
                            </div>
                            <span class="batch-identity-count">
                                <span class="batch-identity-count-dot"></span>
                                <?= (int)$batchCount ?> item<?= $batchCount !== 1 ? 's' : '' ?>
                            </span>
                        </div>

                        <div class="batch-group-header">
                            <div class="single-card-fields" style="background:transparent;">
                                <div class="single-card-field" style="min-width:160px;flex:1.4;background:transparent;">
                                    <span class="single-card-field-label">Requested By</span>
                                    <div class="requester-chip" style="margin-top:1px;">
                                        <div class="requester-avatar"><?= htmlspecialchars($rInitials) ?></div>
                                        <div class="requester-info">
                                            <span class="requester-name"><?= htmlspecialchars($first['requester_name'] ?? '—') ?></span>
                                        </div>
                                    </div>
                                </div>
                                <div class="single-card-field" style="min-width:130px;background:transparent;">
                                    <span class="single-card-field-label">Date</span>
                                    <span class="single-card-field-value">
                                        <?= $first['reserved_date'] ? date('M j, Y', strtotime($first['reserved_date'])) : '—' ?>
                                    </span>
                                    <?php if ($approvedSub !== ''): ?>
                                    <span class="single-card-field-sub"><?= htmlspecialchars($approvedSub) ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($first['reserved_start'])): ?>
                                <div class="single-card-field" style="min-width:130px;background:transparent;">
                                    <span class="single-card-field-label">Time Frame</span>
                                    <span class="single-card-field-value"><?= date('g:i a', strtotime($first['reserved_start'])) ?> &ndash; <?= date('g:i a', strtotime($first['reserved_end'])) ?></span>
                                </div>
                                <?php endif; ?>
                            </div>
                            <button type="button" class="batch-chevron-btn" onclick="toggleInUseBatchBody(this)" aria-label="Toggle items" style="position:relative;top:auto;right:auto;width:32px;height:32px;margin:auto 14px auto 8px;border-radius:9px;background:rgba(200,16,46,0.07);border-color:rgba(200,16,46,0.18);">
                                <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor"><path d="M480-345 240-585l56-56 184 184 184-184 56 56-240 240Z"/></svg>
                            </button>
                        </div>

                        <div class="batch-actions-bar">
                            <div class="batch-actions-bar-label">
                                <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                                This batch &mdash; <?= (int)$batchCount ?> item<?= $batchCount !== 1 ? 's' : '' ?> currently in use.
                            </div>
                            <div class="batch-actions-bar-btns">
                                <button type="button" class="btn-batch-approve"
                                    onclick="openBatchReturnModal('<?= htmlspecialchars($batchId, ENT_QUOTES) ?>',<?= htmlspecialchars(json_encode($batchEqIds), ENT_QUOTES, 'UTF-8') ?>,<?= htmlspecialchars(json_encode($batchEqNames), ENT_QUOTES, 'UTF-8') ?>)">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
                                    Return All (<?= (int)$batchCount ?>)
                                </button>
                            </div>
                        </div>

                        <div class="batch-group-body">
                            <table class="batch-inner-table batch-inner-table--inuse">
                                <colgroup>
                                    <col class="batch-inner-col batch-inner-col--id">
                                    <col class="batch-inner-col batch-inner-col--eq">
                                    <col class="batch-inner-col batch-inner-col--cat">
                                    <col class="batch-inner-col batch-inner-col--status">
                                    <col class="batch-inner-col batch-inner-col--res">
                                    <col class="batch-inner-col batch-inner-col--act">
                                </colgroup>
                                <thead>
                                    <tr>
                                        <th>#</th>
                                        <th>Equipment</th>
                                        <th>Category</th>
                                        <th>Status</th>
                                        <th>Reservation</th>
                                        <th>Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ($rows as $row):
                                    $isOverdue = !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time();
                                ?>
                                    <tr id="row-<?= (int)$row['equipment_id'] ?>" class="<?= $isOverdue ? 'overdue-row' : '' ?>">
                                        <td class="batch-inner-td batch-inner-td--id"><span class="item-num">#<?= (int)$row['reservation_id'] ?></span></td>
                                        <td class="batch-inner-td batch-inner-td--eq"><strong><?= htmlspecialchars($row['resource_name']) ?></strong></td>
                                        <td class="batch-inner-td batch-inner-td--cat"><?= htmlspecialchars($row['categories']) ?></td>
                                        <td class="batch-inner-td batch-inner-td--status status in-use">
                                            <div class="status-stack status-stack--batch-inner">
                                                <span class="status-pill">IN-USE</span>
                                                <?php if ($isOverdue): ?>
                                                <span class="overdue-badge"><span class="overdue-badge-dot"></span>Overdue</span>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                        <td class="batch-inner-td batch-inner-td--res">
                                            <?php if ($row['reservation_id']): ?>
                                                <div class="batch-inner-reserve">
                                                    <span class="batch-inner-reserve__id">Res #<?= (int)$row['reservation_id'] ?></span>
                                                    <?php if (!empty($row['reserved_date'])): ?><span class="batch-inner-reserve__line">Date <?= htmlspecialchars($row['reserved_date']) ?></span><?php endif; ?>
                                                    <?php if (!empty($row['reserved_start'])): ?>
                                                    <span class="batch-inner-reserve__line"><?= date('g:i a', strtotime($row['reserved_start'])) ?> &ndash; <?= date('g:i a', strtotime($row['reserved_end'])) ?></span>
                                                    <?php endif; ?>
                                                </div>
                                            <?php else: ?><span class="muted-cell">—</span><?php endif; ?>
                                        </td>
                                        <td class="batch-inner-td batch-inner-td--act actions">
                                            <button type="button" class="btn-return btn-sm"
                                                onclick="openReturnModal(<?= (int)$row['equipment_id'] ?>,'<?= addslashes(htmlspecialchars($row['resource_name'])) ?>')">
                                                <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor">
                                                    <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
                                                </svg>
                                                Return
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </td>
            </tr>

            <?php else: // ── INDIVIDUAL (non-batch) — same card pattern as admin/reservation.php ──
                $row       = $first;
                $isOverdue = !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time();
                $hasRes    = !empty($row['reservation_id']);
                $rFullName = trim((string)($row['requester_name'] ?? ''));
                $rParts    = $rFullName !== '' ? preg_split('/\s+/', $rFullName) : [];
                $rFirst    = $rParts[0] ?? '';
                $rLast     = count($rParts) > 1 ? $rParts[count($rParts) - 1] : '';
                $rInitials = strtoupper(substr($rFirst, 0, 1) . ($rLast !== '' ? substr($rLast, 0, 1) : substr($rFirst, 1, 1)));
                $rInitials = $rInitials !== '' ? $rInitials : '—';

                if ($hasRes) {
                    $singleStatusClass  = 'status-approved';
                    $singleOverdueClass = $isOverdue ? 'overdue-row' : '';
                    $inUseLabel         = $isOverdue ? 'Overdue · Active checkout' : 'In-Use · Active checkout';
                    $singleIconPath     = 'M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z';
                    $identityBadge      = 'RES #' . (int)$row['reservation_id'];
                } else {
                    $singleStatusClass  = 'status-returned';
                    $singleOverdueClass = '';
                    $inUseLabel         = 'In-Use · Manual checkout';
                    $singleIconPath     = 'M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm-40-120h80v-240h-80v240Zm40-320q17 0 28.5-11.5T520-560t-8.5-21.5T480-590t-21.5 8.5T450-560t8.5 21.5T480-520Z';
                    $identityBadge      = 'EQ #' . (int)$row['equipment_id'];
                }
            ?>
            <tr id="row-<?= (int)$row['equipment_id'] ?>" class="inuse-single-embed-tr<?= $isOverdue ? ' overdue-row' : '' ?>">
                <td colspan="6" class="inuse-single-embed-td">
                    <div class="single-row-wrap inuse-single-card <?= htmlspecialchars($singleStatusClass) ?> <?= htmlspecialchars($singleOverdueClass) ?>">

                        <!-- Left icon zone -->
                        <div class="solo-icon-zone">
                            <div class="solo-icon-circle">
                                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor">
                                    <path d="M200-80q-33 0-56.5-23.5T120-160v-451q-18-11-29-28.5T80-680v-120q0-33 23.5-56.5T160-880h640q33 0 56.5 23.5T880-800v120q0 23-11 40.5T840-611v451q0 33-23.5 56.5T760-80H200Zm0-520v440h560v-440H200Zm-40-80h640v-120H160v120Zm200 280h240v-80H360v80Zm120 20Z"/>
                                </svg>
                            </div>
                            <span class="solo-eq-id">EQ&nbsp;#<?= (int)$row['equipment_id'] ?></span>
                        </div>

                        <!-- Main info -->
                        <div class="solo-info">

                            <!-- Row 1: name + type tag + category -->
                            <div class="solo-info-top">
                                <span class="solo-name"><?= htmlspecialchars($row['resource_name']) ?></span>
                                <span class="solo-type-tag">
                                    <span class="solo-type-tag-dot"></span>
                                    <?= $isOverdue ? 'Overdue' : 'Solo Item' ?>
                                </span>
                            </div>

                            <!-- Row 2: meta chips -->
                            <div class="solo-info-meta">
                                <?php if (!empty($row['categories'])): ?>
                                <span class="solo-category-tag"><?= htmlspecialchars($row['categories']) ?></span>
                                <?php endif; ?>

                                <span class="status-pill" style="font-size:9px;padding:2px 8px;">IN-USE</span>

                                <?php if ($hasRes): ?>
                                <span class="solo-meta-chip">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="currentColor"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Zm280 240q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-160 0q-17 0-28.5-11.5T280-440q0-17 11.5-28.5T320-480q17 0 28.5 11.5T360-440q0 17-11.5 28.5T320-400Zm320 0q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-160 0q-17 0-28.5-11.5T280-280q0-17 11.5-28.5T320-320q17 0 28.5 11.5T360-280q0 17-11.5 28.5T320-240Zm320 0q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240Z"/></svg>
                                    <strong><?= $row['reserved_date'] ? date('M j, Y', strtotime($row['reserved_date'])) : '—' ?></strong>
                                </span>
                                <?php if (!empty($row['reserved_start'])): ?>
                                <span class="solo-meta-chip<?= $isOverdue ? ' solo-chip-overdue' : '' ?>">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="currentColor"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Zm28 164-20-20v-208h-80v174l-28-28-56 56 124 124 60-98Z"/></svg>
                                    <?= date('g:i a', strtotime($row['reserved_start'])) ?> &ndash; <?= date('g:i a', strtotime($row['reserved_end'])) ?>
                                    <?php if ($isOverdue): ?>&nbsp;· <strong>Overdue</strong><?php endif; ?>
                                </span>
                                <?php endif; ?>
                                <?php if (!empty($row['approver_name'])): ?>
                                <span class="solo-meta-chip">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="currentColor"><path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                                    Approved by <strong><?= htmlspecialchars($row['approver_name']) ?></strong>
                                </span>
                                <?php endif; ?>
                                <?php endif; // end if ($hasRes) ?>
                                <?php if (!$hasRes): ?>
                                <span class="solo-meta-chip" style="color:#94a3b8;">
                                    <svg xmlns="http://www.w3.org/2000/svg" height="12px" viewBox="0 -960 960 960" width="12px" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
                                    Manual override
                                </span>
                                <?php endif; ?>
                            </div>

                            <!-- Row 3: requester -->
                            <?php if ($hasRes && !empty($row['requester_name'])): ?>
                            <div class="solo-info-bottom">
                                <span class="solo-requester-label">Checked out by</span>
                                <div class="requester-chip">
                                    <div class="requester-avatar"><?= htmlspecialchars($rInitials) ?></div>
                                    <span class="requester-name"><?= htmlspecialchars($row['requester_name']) ?></span>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>

                        <!-- Actions -->
                        <div class="single-card-actions">
                            <button type="button" class="btn-return"
                                onclick="openReturnModal(<?= (int)$row['equipment_id'] ?>,'<?= addslashes(htmlspecialchars($row['resource_name'])) ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor">
                                    <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
                                </svg>
                                Mark as Returned
                            </button>
                        </div>

                    </div>
                </td>
            </tr>
            <?php endif; // end if/else batch ?>
            <?php endforeach; // end $inUseGroups ?>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="inuse-pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($pagerParams, ['page' => $page - 1])) ?>" class="inuse-page-btn">&laquo; Prev</a>
            <?php endif; ?>
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?<?= http_build_query(array_merge($pagerParams, ['page' => $i])) ?>"
                   class="inuse-page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="?<?= http_build_query(array_merge($pagerParams, ['page' => $page + 1])) ?>" class="inuse-page-btn">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <?php endif; ?>
        </div>

    </section>

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

<!-- Return modal -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <h3 style="display:flex; align-items:center; gap:8px; margin:0 0 10px; font-size:1.1rem;">
            <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="#16a34a" style="flex-shrink:0;">
                <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
            </svg>
            Confirm Return
        </h3>
        <p style="font-size:0.875rem; color:#555; margin:4px 0 0;">
            Mark this equipment as returned and set it back to <b>Available</b>:
        </p>
        <div class="equipment-pill" id="returnEquipmentName">—</div>

        <label for="returnRemarks">Return Notes <span style="font-weight:400; color:#999;">(optional)</span></label>
        <textarea id="returnRemarks" placeholder="Example: Returned in good condition, minor scratch on HDMI port."></textarea>

        <p id="returnModalMsg" style="color:red;"></p>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeReturnModal()">Cancel</button>
            <button type="button" class="btn-confirm-edit" id="confirmReturnBtn" onclick="submitReturn()">
                Confirm Return
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="toastNotif"></div>

<script>

// expand/collapse batch cards
function toggleInUseBatchBody(btn) {
    const root = btn.closest('[data-in-use-batch-root]');
    if (!root) return;
    const body = root.querySelector('.batch-group-body');
    if (!body) return;
    const collapsed = body.classList.toggle('collapsed');
    btn.classList.toggle('collapsed', collapsed);
    const tr = root.closest('tr.batch-inuse-embed-tr');
    if (tr) tr.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

document.querySelectorAll('tr.batch-inuse-embed-tr').forEach(function (tr) {
    tr.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' && e.key !== ' ') return;
        e.preventDefault();
        var btn = tr.querySelector('.batch-chevron-btn');
        if (btn) toggleInUseBatchBody(btn);
    });
});

// handle single item return
let returnTargetId   = null;
let returnTargetName = null;
let returnTargetBatch = null; // set when returning a whole batch
let returnBatchEqIds  = null;

function openReturnModal(equipmentId, equipmentName) {
    returnTargetId    = equipmentId;
    returnTargetName  = equipmentName;
    returnTargetBatch = null;
    returnBatchEqIds  = null;

    document.getElementById('returnEquipmentName').textContent = equipmentName;
    document.getElementById('returnRemarks').value             = '';
    document.getElementById('returnModalMsg').textContent      = '';
    document.getElementById('confirmReturnBtn').disabled       = false;
    document.getElementById('confirmReturnBtn').textContent    = 'Confirm Return';
    document.getElementById('returnModal').classList.add('active');
}

// handle batch return
function openBatchReturnModal(batchId, eqIds, eqNames) {
    returnTargetId    = null;
    returnTargetBatch = batchId;
    returnBatchEqIds  = eqIds;
    returnTargetName  = eqNames.join(', ');

    document.getElementById('returnEquipmentName').textContent = eqNames.length + ' items: ' + eqNames.join(', ');
    document.getElementById('returnRemarks').value             = '';
    document.getElementById('returnModalMsg').textContent      = '';
    document.getElementById('confirmReturnBtn').disabled       = false;
    document.getElementById('confirmReturnBtn').textContent    = 'Return All (' + eqIds.length + ')';
    document.getElementById('returnModal').classList.add('active');
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('active');
    returnTargetId    = null;
    returnTargetName  = null;
    returnTargetBatch = null;
    returnBatchEqIds  = null;
}

document.getElementById('returnModal').addEventListener('click', function(e) {
    if (e.target === this) closeReturnModal();
});

function submitReturn() {
    const remarks = document.getElementById('returnRemarks').value.trim();
    const btn     = document.getElementById('confirmReturnBtn');
    const msgEl   = document.getElementById('returnModalMsg');
    const csrf    = document.querySelector('meta[name="csrf-token"]').content;

    btn.disabled      = true;
    btn.textContent   = 'Processing…';
    msgEl.textContent = '';
    msgEl.style.color = 'red';

    // send one return request per item in the batch
    if (returnTargetBatch && returnBatchEqIds) {
        const ids = returnBatchEqIds;
        const promises = ids.map(eqId => {
            const fd = new FormData();
            fd.append('equipment_id', eqId);
            fd.append('remarks',      remarks);
            fd.append('csrf_token',   csrf);
            return fetch('return_equipment.php', { method: 'POST', body: fd }).then(r => r.json());
        });

        Promise.all(promises).then(results => {
            const allOk  = results.every(d => d.success);
            const anyOk  = results.some(d => d.success);

            if (anyOk) {
                // Capture before closeReturnModal() nulls returnTargetBatch
                const batchId = returnTargetBatch;
                const header  = document.getElementById('batch-' + batchId);
                const count   = results.filter(d => d.success).length;
                closeReturnModal();
                if (header) { header.style.opacity = '0'; header.style.transition = 'opacity .3s'; }
                setTimeout(() => {
                    if (header) header.remove();
                    results.forEach(d => {
                        if (d.success) {
                            const row = document.getElementById('row-' + d.equipment_id);
                            if (row) row.remove();
                        }
                    });
                    const statEl = document.getElementById('inUseCount');
                    if (statEl) statEl.textContent = Math.max(0, parseInt(statEl.textContent) - count);
                    const groupEl = document.getElementById('groupCount');
                    if (groupEl) groupEl.textContent = Math.max(0, parseInt(groupEl.textContent) - 1);
                    checkEmptyTable();
                }, 320);

                showToast(count + ' item' + (count !== 1 ? 's' : '') + ' returned successfully.', 'success');
            } else {
                msgEl.textContent = 'Return failed. Please try again.';
                btn.disabled      = false;
                btn.textContent   = 'Return All (' + ids.length + ')';
            }
        }).catch(() => {
            msgEl.textContent = 'Network error. Please try again.';
            btn.disabled      = false;
            btn.textContent   = 'Retry';
        });
        return;
    }

    // send single item return
    if (!returnTargetId) return;
    const formData = new FormData();
    formData.append('equipment_id', returnTargetId);
    formData.append('remarks',      remarks);
    formData.append('csrf_token',   csrf);

    fetch('return_equipment.php', { method: 'POST', body: formData })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeReturnModal();
            const row = document.getElementById('row-' + data.equipment_id);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity    = '0';
                setTimeout(() => {
                    row.remove();
                    const statEl = document.getElementById('inUseCount');
                    if (statEl) statEl.textContent = Math.max(0, parseInt(statEl.textContent) - 1);
                    const groupEl = document.getElementById('groupCount');
                    if (groupEl) groupEl.textContent = Math.max(0, parseInt(groupEl.textContent) - 1);
                    checkEmptyTable();
                }, 300);
            }
            showToast(data.message, 'success');
        } else {
            msgEl.textContent = data.message || 'An error occurred.';
            btn.disabled      = false;
            btn.textContent   = 'Confirm Return';
        }
    })
    .catch(() => {
        msgEl.textContent = 'Network error. Please try again.';
        btn.disabled      = false;
        btn.textContent   = 'Confirm Return';
    });
}

function checkEmptyTable() {
    const visibleRows = document.querySelectorAll(
        '.transaction_table.equipment tr[id^="row-"], .transaction_table.equipment tr[id^="batch-"]'
    );
    if (visibleRows.length === 0) {
        const table = document.querySelector('.transaction_table.equipment');
        if (table) {
            table.outerHTML = `
                <div class="empty-state">
                    <svg xmlns="http://www.w3.org/2000/svg" height="64px" viewBox="0 -960 960 960" width="64px" fill="#888">
                        <path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/>
                    </svg>
                    <h3>No equipment currently in use</h3>
                    <p>All items are available or under maintenance.</p>
                </div>`;
        }
        const badge = document.querySelector('.inuse-badge');
        if (badge) badge.remove();
    }
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toastNotif');
    toast.textContent = message;
    toast.className   = 'show toast-' + type;
    setTimeout(() => { toast.className = ''; }, 3500);
}
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