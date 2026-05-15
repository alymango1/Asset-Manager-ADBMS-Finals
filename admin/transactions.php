<?php
require_once __DIR__ . '/../database/db.php';
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $fullName  = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name      = $nameParts[0];
}

// make initials from their name
$fullNameRaw     = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts           = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first           = $parts[0] ?? '';
$last            = count($parts) > 1 ? $parts[count($parts) - 1] : '';
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
$_notifTotal        = $_notifOverdueCount + ($_notifPendingCount > 0 ? 1 : 0);

// handle filters
$filter_type = isset($_GET['type'])   ? trim($_GET['type'])   : '';
$filter_date = isset($_GET['date'])   ? trim($_GET['date'])   : '';
$filter_who  = isset($_GET['who'])    ? trim($_GET['who'])    : '';

$allowed_types = [
    'status_change', 'reservation_approved', 'reservation_rejected',
    'equipment_added', 'equipment_edited', 'equipment_deleted',
    'manual_status_change',
];

$where_clauses = [];
$where_params  = [];
$where_types   = '';

if ($filter_type !== '' && in_array($filter_type, $allowed_types)) {
    $where_clauses[] = "t.action_type = ?";
    $where_params[]  = $filter_type;
    $where_types    .= 's';
}
if ($filter_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filter_date)) {
    $where_clauses[] = "DATE(t.action_date) = ?";
    $where_params[]  = $filter_date;
    $where_types    .= 's';
}
if ($filter_who !== '') {
    $where_clauses[] = "(u.username LIKE ? OR u.full_name LIKE ?)";
    $like = '%' . $filter_who . '%';
    $where_params[]  = $like;
    $where_params[]  = $like;
    $where_types    .= 'ss';
}

$where_sql = $where_clauses ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

// handle pagination
$limit  = 15;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

// count results with filters applied
$count_sql = "SELECT COUNT(*) AS total FROM equipment_transactions t
              LEFT JOIN users u ON t.performed_by = u.user_id
              $where_sql";
if ($where_params) {
    $cnt_stmt = mysqli_prepare($conn, $count_sql);
    mysqli_stmt_bind_param($cnt_stmt, $where_types, ...$where_params);
    mysqli_stmt_execute($cnt_stmt);
    $totalRows = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($cnt_stmt))['total'];
    mysqli_stmt_close($cnt_stmt);
} else {
    $totalRows = (int) mysqli_fetch_assoc(mysqli_query($conn, $count_sql))['total'];
}

$totalPages = (int) ceil($totalRows / $limit);
$page       = min($page, max(1, $totalPages));
$offset     = ($page - 1) * $limit;

// get overall stats
$today = date('Y-m-d');

$statsMap = [];
$statsQ   = mysqli_query($conn, "
    SELECT action_type, COUNT(*) AS cnt
    FROM equipment_transactions
    GROUP BY action_type
");
while ($r = mysqli_fetch_assoc($statsQ)) {
    $statsMap[$r['action_type']] = (int) $r['cnt'];
}

$todayCount      = (int) mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipment_transactions WHERE DATE(action_date) = '$today'"))['total'];
$rejectedCount   = ($statsMap['reservation_rejected'] ?? 0);
$approvedCount   = ($statsMap['reservation_approved'] ?? 0) + ($statsMap['status_change'] ?? 0);
$adminEditCount  = ($statsMap['equipment_added'] ?? 0)
                 + ($statsMap['equipment_edited'] ?? 0)
                 + ($statsMap['equipment_deleted'] ?? 0)
                 + ($statsMap['manual_status_change'] ?? 0);

// main data query
$data_sql = "
SELECT
    t.transaction_id,
    t.action_type,
    t.reservation_id,
    t.equipment_id,
    COALESCE(e.resource_name, CONCAT('[Deleted #', t.equipment_id, ']')) AS resource_name,
    t.performed_by,
    u.username  AS performed_name,
    u.full_name AS performed_fullname,
    t.status_from,
    t.status_to,
    t.field_changed,
    t.old_value,
    t.new_value,
    t.action_date,
    t.remarks
FROM equipment_transactions t
LEFT JOIN equipments e ON t.equipment_id = e.equipment_id
LEFT JOIN users u ON t.performed_by = u.user_id
$where_sql
ORDER BY t.transaction_id DESC
LIMIT $limit OFFSET $offset
";

if ($where_params) {
    $data_stmt = mysqli_prepare($conn, $data_sql);
    mysqli_stmt_bind_param($data_stmt, $where_types, ...$where_params);
    mysqli_stmt_execute($data_stmt);
    $result = mysqli_stmt_get_result($data_stmt);
} else {
    $result = mysqli_query($conn, $data_sql);
}

// carry filters into page links
$filter_qs = http_build_query(array_filter([
    'type' => $filter_type,
    'date' => $filter_date,
    'who'  => $filter_who,
]));

// turn action codes into readable labels
function actionLabel(string $type): string {
    return match($type) {
        'status_change'          => 'Status Change',
        'reservation_approved'   => 'Reservation Approved',
        'reservation_rejected'   => 'Reservation Rejected',
        'equipment_added'        => 'Equipment Added',
        'equipment_edited'       => 'Equipment Edited',
        'equipment_deleted'      => 'Equipment Deleted',
        'manual_status_change'   => 'Manual Status Override',
        default                  => ucwords(str_replace('_', ' ', $type)),
    };
}

function actionBadgeClass(string $type): string {
    return match($type) {
        'reservation_approved'  => 'badge-approved',
        'reservation_rejected'  => 'badge-rejected',
        'equipment_added'       => 'badge-added',
        'equipment_edited'      => 'badge-edited',
        'equipment_deleted'     => 'badge-deleted',
        'manual_status_change'  => 'badge-manual',
        default                 => 'badge-default',
    };
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Transactions</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/transaction.css">

</head>

<body>
<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Transactions</h1>
        <p>Full audit trail — every action by every admin, all in one place.</p>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><?php echo date('l, F j, Y'); ?></span>

        <!-- Bell -->
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
                    <?php foreach ($_notifOverdueItems as $_ni):
                        $_s = time() - strtotime($_ni['reserved_end']);
                        if ($_s < 3600)       $_tl = round($_s/60).'m ago';
                        elseif ($_s < 86400)  $_tl = round($_s/3600).'h ago';
                        else                  $_tl = round($_s/86400).'d ago';
                    ?>
                    <a href="in_use.php" class="notif-item notif-critical">
                        <span class="notif-item-dot notif-dot-red"></span>
                        <div class="notif-item-body">
                            <strong><?= htmlspecialchars($_ni['resource_name']) ?> — not returned</strong>
                            <span>Overdue since <?= date('g:i a', strtotime($_ni['reserved_end'])) ?></span>
                        </div>
                        <span class="notif-item-time"><?= $_tl ?></span>
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

<script>
const profileBtn = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', e => { e.stopPropagation(); profileDropdown.classList.toggle('open'); });
document.addEventListener('click', () => profileDropdown.classList.remove('open'));
</script>

<main class="main">
    <section class="transactions-hero">
        <div class="transactions-hero-copy">
            <p class="eyebrow">Logs</p>
            <h2>Transaction &amp; Admin Logs</h2>
            <p class="hero-subtitle">Every action — approvals, rejections, equipment edits — logged with who did it and when.</p>
        </div>
        <div class="hero-stats">
            <div class="hero-stat">
                <span>Total Logs</span>
                <strong><?= array_sum($statsMap) ?></strong>
            </div>
            <div class="hero-stat">
                <span>Today</span>
                <strong><?= $todayCount ?></strong>
            </div>
            <div class="hero-stat">
                <span>Rejections</span>
                <strong style="color:#dc2626;"><?= $rejectedCount ?></strong>
            </div>
            <div class="hero-stat">
                <span>Admin Edits</span>
                <strong style="color:#7c3aed;"><?= $adminEditCount ?></strong>
            </div>
        </div>
    </section>

    <section class="section-card table-wrap">
        <div class="section-header">
            <h2>Audit Log</h2>
            <span class="meta-pill">Page <?= $page ?> of <?= max(1, $totalPages) ?></span>
        </div>

        <!-- Filter bar -->
        <form method="GET" class="filter-bar">
            <span class="filter-label">Filter:</span>
            <select name="type">
                <option value="">All Actions</option>
                <?php foreach ($allowed_types as $at): ?>
                <option value="<?= $at ?>" <?= $filter_type === $at ? 'selected' : '' ?>>
                    <?= actionLabel($at) ?>
                </option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" title="Filter by date">
            <input type="text" name="who" placeholder="Admin name…" value="<?= htmlspecialchars($filter_who) ?>">
            <button type="submit" class="btn-filter">Apply</button>
            <?php if ($filter_type || $filter_date || $filter_who): ?>
            <a href="transactions.php" class="btn-clear">✕ Clear</a>
            <?php endif; ?>
        </form>

        <?php if ($totalRows === 0): ?>
            <div class="empty-state">
                <h3>No log records match your filters</h3>
                <p>Try adjusting the filters above, or <a href="transactions.php">clear them</a>.</p>
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="transaction-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Action</th>
                        <th>Equipment</th>
                        <th>Performed By</th>
                        <th>Date &amp; Time</th>
                        <th>Details</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = mysqli_fetch_assoc($result)): ?>
                    <tr style="background:#ffffff !important;">
                        <td>#<?= $row['transaction_id'] ?></td>

                        <!-- Action type badge -->
                        <td>
                            <span class="action-badge <?= actionBadgeClass($row['action_type']) ?>">
                                <?= actionLabel($row['action_type']) ?>
                            </span>
                        </td>

                        <!-- Equipment name (preserved even if deleted) -->
                        <td class="equip-name"><?= htmlspecialchars($row['resource_name']) ?>
                            <?php if ($row['reservation_id']): ?>
                                <span class="muted">Res #<?= $row['reservation_id'] ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Who did it -->
                        <td>
                            <?= htmlspecialchars($row['performed_name'] ?? '—') ?>
                            <?php if (!empty($row['performed_fullname'])): ?>
                                <span class="muted"><?= htmlspecialchars($row['performed_fullname']) ?></span>
                            <?php endif; ?>
                        </td>

                        <!-- Full datetime -->
                        <td>
                            <?php $dt = strtotime($row['action_date']); ?>
                            <?= date('M j, Y', $dt) ?>
                            <span class="muted"><?= date('g:i a', $dt) ?></span>
                        </td>

                        <!-- Details column: status change or field diff -->
                        <td>
                            <?php if ($row['action_type'] === 'equipment_edited' && $row['field_changed']): ?>
                                <span class="diff-cell">
                                    <strong><?= htmlspecialchars($row['field_changed']) ?>:</strong><br>
                                    <span class="diff-old"><?= htmlspecialchars($row['old_value'] ?? '') ?></span>
                                    <span class="diff-arrow">→</span>
                                    <span class="diff-new"><?= htmlspecialchars($row['new_value'] ?? '') ?></span>
                                </span>
                            <?php elseif ($row['status_from'] || $row['status_to']): ?>
                                <?php if ($row['status_from']): ?>
                                <span class="status-pill <?= strtolower(str_replace([' ','_'],'-',$row['status_from'])) ?>">
                                    <?= strtoupper($row['status_from']) ?>
                                </span>
                                <?php endif; ?>
                                <?php if ($row['status_from'] && $row['status_to']): ?>→<?php endif; ?>
                                <?php if ($row['status_to']): ?>
                                <span class="status-pill <?= strtolower(str_replace([' ','_'],'-',$row['status_to'])) ?>">
                                    <?= strtoupper($row['status_to']) ?>
                                </span>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="muted">—</span>
                            <?php endif; ?>
                        </td>

                        <td><?= htmlspecialchars($row['remarks'] ?? '—') ?></td>
                    </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page-1 ?>&<?= $filter_qs ?>" class="page-btn">&laquo; Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                    <a href="?page=<?= $p ?>&<?= $filter_qs ?>"
                       class="page-btn <?= $p === $page ? 'page-btn-active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page+1 ?>&<?= $filter_qs ?>" class="page-btn">Next &raquo;</a>
                <?php endif; ?>
            </div>
            <p class="pagination-meta">
                Showing <?= ($offset + 1) ?>–<?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?> records
            </p>
        </div>
        <?php endif; ?>
    </section>
</main>

<script>
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
notifBtn.addEventListener('click', e => {
    e.stopPropagation();
    notifDropdown.classList.toggle('open');
    if (typeof profileDropdown !== 'undefined') profileDropdown.classList.remove('open');
});
notifDropdown.addEventListener('click', e => e.stopPropagation());
document.addEventListener('click', () => notifDropdown.classList.remove('open'));
</script>
</body>
</html>