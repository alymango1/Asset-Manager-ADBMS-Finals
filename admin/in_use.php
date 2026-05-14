<?php
require_once __DIR__ . '/../database/db.php';
session_start();

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

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$nameParts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $nameParts[0] ?? '';
$last  = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '';
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

// Return success flash
$flash = isset($_GET['returned']) ? 'Equipment successfully marked as Returned.' : '';

// Fetch in-use equipment rows
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
    WHERE e.status = 'In-Use'
    ORDER BY COALESCE(r.batch_id, ''), e.equipment_id ASC
");

// Group rows: batch_id → group of items | null → individual
$inUseGroups = [];
$inUseCount  = 0;
while ($row = mysqli_fetch_assoc($inUseQuery)) {
    $inUseCount++;
    if (!empty($row['batch_id'])) {
        $key = 'batch_' . $row['batch_id'];
        if (!isset($inUseGroups[$key])) {
            $inUseGroups[$key] = ['batch_id' => $row['batch_id'], 'rows' => [], 'is_batch' => true];
        }
        $inUseGroups[$key]['rows'][] = $row;
    } else {
        $key = 'single_' . $row['equipment_id'];
        $inUseGroups[$key] = ['batch_id' => null, 'rows' => [$row], 'is_batch' => false];
    }
}

// Count overdue items
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
    <link rel="stylesheet" href="../css/admin/modal.css">
    <style>
        tr.overdue-row td { background: #fff !important; }
        tr.overdue-row td:first-child { box-shadow: inset 3px 0 0 #C40C0C; }

        .overdue-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            background: #fff;
            color: #C40C0C;
            border: 1px solid #C40C0C;
            border-radius: 4px;
            font-size: 9px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            padding: 2px 7px;
            margin-top: 5px;
        }
        .overdue-badge-dot {
            width: 5px;
            height: 5px;
            border-radius: 50%;
            background: #C40C0C;
            flex-shrink: 0;
            animation: dot-pulse 1.2s ease-in-out infinite;
        }
        @keyframes dot-pulse {
            0%, 100% { opacity: 1; transform: scale(1); }
            50%       { opacity: .35; transform: scale(0.65); }
        }
        .status-stack {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
        }

        /* ── Overdue banner — critical alarm ── */
        .overdue-banner {
            display: flex;
            align-items: center;
            gap: 20px;
            background: #E8000D;
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 16px;
            animation: alarm-pulse 1.6s ease-in-out infinite;
        }
        @keyframes alarm-pulse {
            0%, 100% { box-shadow: 0 0 0 0 rgba(232,0,13,0); }
            50%       { box-shadow: 0 0 0 6px rgba(232,0,13,0.22); }
        }
        .overdue-banner-eyebrow {
            font-size: 10px;
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.7);
            display: flex;
            align-items: center;
            gap: 7px;
            margin-bottom: 4px;
        }
        .overdue-banner-eyebrow-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #fff;
            animation: dot-pulse 1s ease-in-out infinite;
            flex-shrink: 0;
        }
        .overdue-banner-text strong {
            display: block;
            font-size: 16px;
            font-weight: 800;
            color: #fff;
            margin-bottom: 3px;
            letter-spacing: -0.2px;
        }
        .overdue-banner-text span {
            font-size: 12.5px;
            color: rgba(255,255,255,0.72);
        }
        .overdue-banner-count {
            margin-left: auto;
            text-align: center;
            flex-shrink: 0;
            background: rgba(0,0,0,0.18);
            border-radius: 10px;
            padding: 10px 20px;
            border: 1.5px solid rgba(255,255,255,0.2);
        }
        .overdue-banner-count strong {
            display: block;
            font-size: 36px;
            font-weight: 900;
            color: #fff;
            line-height: 1;
            letter-spacing: -1.5px;
        }
        .overdue-banner-label {
            display: block;
            font-size: 9px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: rgba(255,255,255,0.6);
            margin-top: 3px;
        }
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
            <tr>
                <th>ID</th>
                <th>Resource Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Reservation Info</th>
                <th>Action</th>
            </tr>

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
            ?>
            <!-- ── BATCH GROUP HEADER ROW ── -->
            <tr id="<?= htmlspecialchars($batchRowId) ?>"
                class="batch-header-row<?= $groupAnyOverdue ? ' overdue-row' : '' ?>"
                style="cursor:pointer;"
                onclick="toggleBatch('<?= htmlspecialchars($batchId) ?>')">
                <td colspan="2">
                    <div style="display:flex;align-items:center;gap:8px;">
                        <svg id="chevron-<?= htmlspecialchars($batchId) ?>" xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor" style="transition:transform .2s;flex-shrink:0;">
                            <path d="M480-345 240-585l56-56 184 184 184-184 56 56-240 240Z"/>
                        </svg>
                        <span style="font-weight:700;">Batch Reservation</span>
                        <span style="background:#e8f0fe;color:#1a73e8;font-size:10px;font-weight:800;border-radius:20px;padding:2px 9px;letter-spacing:.5px;">
                            <?= $batchCount ?> ITEMS
                        </span>
                    </div>
                </td>
                <td><?= htmlspecialchars($first['categories']) ?></td>
                <td class="status in-use">
                    <div class="status-stack">
                        <span class="status-pill">IN-USE</span>
                        <?php if ($groupAnyOverdue): ?>
                            <span class="overdue-badge"><span class="overdue-badge-dot"></span>Overdue</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ($first['reservation_id']): ?>
                    <div class="res-meta">
                        Requested by: <b><?= htmlspecialchars($first['requester_name'] ?? '—') ?></b><br>
                        Reserved date: <?= $first['reserved_date'] ?><br>
                        <?php if (!empty($first['reserved_start'])): ?>
                        Time: <?= date('g:i a', strtotime($first['reserved_start'])) ?> – <?= date('g:i a', strtotime($first['reserved_end'])) ?><br>
                        <?php endif; ?>
                        Approved by: <?= htmlspecialchars($first['approver_name'] ?? '—') ?>
                    </div>
                    <?php else: ?>
                    <span class="no-res">No reservation linked</span>
                    <?php endif; ?>
                </td>
                <td class="actions" onclick="event.stopPropagation()">
                    <button class="btn-return"
                        onclick="openBatchReturnModal('<?= htmlspecialchars($batchId) ?>',<?= htmlspecialchars(json_encode($batchEqIds)) ?>,<?= htmlspecialchars(json_encode($batchEqNames)) ?>)">
                        <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor">
                            <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
                        </svg>
                        Return All (<?= $batchCount ?>)
                    </button>
                </td>
            </tr>

            <!-- ── BATCH CHILD ROWS (collapsed by default) ── -->
            <?php foreach ($rows as $idx => $row):
                $isOverdue = !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time();
            ?>
            <tr id="row-<?= $row['equipment_id'] ?>"
                class="batch-child-row<?= $isOverdue ? ' overdue-row' : '' ?>"
                data-batch="<?= htmlspecialchars($batchId) ?>"
                style="display:none;">
                <td style="padding-left:36px;"><?= $row['equipment_id'] ?></td>
                <td><b><?= htmlspecialchars($row['resource_name']) ?></b></td>
                <td><?= htmlspecialchars($row['categories']) ?></td>
                <td class="status in-use">
                    <div class="status-stack">
                        <span class="status-pill">IN-USE</span>
                        <?php if ($isOverdue): ?>
                            <span class="overdue-badge"><span class="overdue-badge-dot"></span>Overdue</span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <div class="res-meta">
                        <b>Res #<?= $row['reservation_id'] ?></b>
                    </div>
                </td>
                <td class="actions">
                    <button class="btn-return btn-sm"
                        onclick="openReturnModal(<?= $row['equipment_id'] ?>,'<?= addslashes(htmlspecialchars($row['resource_name'])) ?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor">
                            <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
                        </svg>
                        Return
                    </button>
                </td>
            </tr>
            <?php endforeach; // end batch child rows ?>

            <?php else: // ── INDIVIDUAL (non-batch) ROW ──
                $row       = $first;
                $isOverdue = !empty($row['reserved_end']) && strtotime($row['reserved_end']) < time();
            ?>
            <tr id="row-<?php echo $row['equipment_id']; ?>" class="<?= $isOverdue ? 'overdue-row' : '' ?>">
                <td><?php echo $row['equipment_id']; ?></td>
                <td><b><?php echo htmlspecialchars($row['resource_name']); ?></b></td>
                <td><?php echo htmlspecialchars($row['categories']); ?></td>
                <td class="status in-use">
                    <div class="status-stack">
                        <span class="status-pill">IN-USE</span>
                        <?php if ($isOverdue): ?>
                            <span class="overdue-badge">
                                <span class="overdue-badge-dot"></span>
                                Overdue
                            </span>
                        <?php endif; ?>
                    </div>
                </td>
                <td>
                    <?php if ($row['reservation_id']): ?>
                    <div class="res-meta">
                        <b>Res #<?php echo $row['reservation_id']; ?></b><br>
                        Requested by: <b><?php echo htmlspecialchars($row['requester_name'] ?? '—'); ?></b><br>
                        Reserved date: <?php echo $row['reserved_date']; ?><br>
                        <?php if (!empty($row['reserved_start'])): ?>
                        Time: <?= date('g:i a', strtotime($row['reserved_start'])) ?> – <?= date('g:i a', strtotime($row['reserved_end'])) ?><br>
                        <?php endif; ?>
                        Approved by: <?php echo htmlspecialchars($row['approver_name'] ?? '—'); ?><br>
                        Approved at: <?php echo $row['approved_at']; ?>
                    </div>
                    <?php else: ?>
                    <span class="no-res">No reservation linked (manual override)</span>
                    <?php endif; ?>
                </td>
                <td class="actions">
                    <button class="btn-return"
                        onclick="openReturnModal(
                            <?php echo $row['equipment_id']; ?>,
                            '<?php echo addslashes(htmlspecialchars($row['resource_name'])); ?>'
                        )">
                        <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor">
                            <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
                        </svg>
                        Mark as Returned
                    </button>
                </td>
            </tr>
            <?php endif; // end if/else batch ?>
            <?php endforeach; // end $inUseGroups ?>
        </table>

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


<style>
.batch-header-row td { background: #f0f4ff !important; font-size: 13px; }
.batch-header-row:hover td { background: #e6edff !important; }
.batch-child-row td { background: #fafbff !important; }
.btn-sm { font-size: 11px; padding: 5px 10px; }
</style>
<script>
// ── Batch expand/collapse ──
function toggleBatch(batchId) {
    const children = document.querySelectorAll(`.batch-child-row[data-batch="${batchId}"]`);
    const chevron  = document.getElementById('chevron-' + batchId);
    const isOpen   = children[0] && children[0].style.display !== 'none';
    children.forEach(r => r.style.display = isOpen ? 'none' : '');
    if (chevron) chevron.style.transform = isOpen ? '' : 'rotate(180deg)';
}

// ── Single item return ──
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

// ── Batch return ──
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

    // ── BATCH return: fire one request per equipment_id ──
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
                closeReturnModal();
                // Remove header row + all child rows for this batch
                const header = document.getElementById('batch-' + returnTargetBatch);
                if (header) { header.style.opacity = '0'; header.style.transition = 'opacity .3s'; }
                const children = document.querySelectorAll(`.batch-child-row[data-batch="${returnTargetBatch}"]`);
                children.forEach(r => { r.style.opacity = '0'; r.style.transition = 'opacity .3s'; });
                setTimeout(() => {
                    if (header) header.remove();
                    children.forEach(r => r.remove());
                    results.forEach(d => {
                        if (d.success) {
                            const row = document.getElementById('row-' + d.equipment_id);
                            if (row) row.remove();
                        }
                    });
                    checkEmptyTable();
                }, 320);

                const count = results.filter(d => d.success).length;
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

    // ── SINGLE return ──
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
                setTimeout(() => { row.remove(); checkEmptyTable(); }, 300);
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