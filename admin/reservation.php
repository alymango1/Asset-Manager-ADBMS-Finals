<?php
session_start();
include('../database/db.php');

// Create CSRF token once per session
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

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$today = date('Y-m-d');

// Read filter params
$filterStatus = isset($_GET['status'])     ? trim($_GET['status'])     : 'pending';
$filterUser   = isset($_GET['user'])       ? trim(mysqli_real_escape_string($conn, $_GET['user']))   : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

$allowedStatuses = ['all', 'pending', 'approved', 'rejected', 'cancelled', 'returned'];
if (!in_array($filterStatus, $allowedStatuses)) $filterStatus = 'pending';

// Build WHERE conditions
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

// Pagination setup
$limit  = 10;
$page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$countResult = mysqli_query($conn, "
    SELECT COUNT(*) AS total
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    LEFT JOIN users u ON r.requested_by = u.user_id
    $whereSQL
");
$totalRecords = mysqli_fetch_assoc($countResult)['total'];
$totalPages   = max(1, ceil($totalRecords / $limit));

// Status summary counts
$pendingCount   = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='pending'"))['c'];
$approvedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='approved'"))['c'];
$rejectedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='rejected'"))['c'];
$cancelledCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='cancelled'"))['c'];
$returnedCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='returned'"))['c'];

// Main reservations query
$query = "
    SELECT
        r.reservation_id,
        r.equipment_id,
        r.reserved_date,
        r.status,
        r.remarks,
        r.approved_at,
        r.rejected_at,
        r.created_at,
        e.resource_name,
        u.username    AS requester_name,
        u.full_name   AS requester_full,
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
        r.reserved_date ASC
    LIMIT $limit OFFSET $offset
";
$result = mysqli_query($conn, $query);

// Build pagination URL
function pageUrl(int $p): string {
    $params = $_GET;
    $params['page'] = $p;
    return '?' . http_build_query($params);
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

    <section class="res-metrics">
        <article class="metric-card metric-pending">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm-40-200h80v-240h-80v240Zm0-320h80v-80h-80v80Z"/></svg>
            </div>
            <div class="metric-body"><p>Pending</p><strong><?php echo $pendingCount; ?></strong></div>
        </article>
        <article class="metric-card metric-approved">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div class="metric-body"><p>Approved</p><strong><?php echo $approvedCount; ?></strong></div>
        </article>
        <article class="metric-card metric-rejected">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div class="metric-body"><p>Rejected</p><strong><?php echo $rejectedCount; ?></strong></div>
        </article>
        <article class="metric-card metric-returned">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
            </div>
            <div class="metric-body"><p>Returned</p><strong><?php echo $returnedCount; ?></strong></div>
        </article>
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
                <?php
                // Build status tab URLs
                function tabUrl(string $s): string {
                    $p = $_GET; $p['status'] = $s; unset($p['page']); return '?' . http_build_query($p);
                }
                ?>
                <div class="tab-bar">
                    <a href="<?= tabUrl('pending') ?>"
                       class="tab-btn <?= $filterStatus === 'pending'  ? 'active-pending'  : '' ?>">
                        Pending <span class="tab-badge"><?= $pendingCount ?></span>
                    </a>
                    <a href="<?= tabUrl('approved') ?>"
                       class="tab-btn <?= $filterStatus === 'approved' ? 'active-approved' : '' ?>">
                        Approved <span class="tab-badge"><?= $approvedCount ?></span>
                    </a>
                    <a href="<?= tabUrl('rejected') ?>"
                       class="tab-btn <?= $filterStatus === 'rejected' ? 'active-rejected' : '' ?>">
                        Rejected <span class="tab-badge"><?= $rejectedCount ?></span>
                    </a>
                    <a href="<?= tabUrl('cancelled') ?>"
                       class="tab-btn <?= $filterStatus === 'cancelled' ? 'active-cancelled' : '' ?>">
                        Cancelled <span class="tab-badge"><?= $cancelledCount ?></span>
                    </a>
                    <a href="<?= tabUrl('returned') ?>"
                       class="tab-btn <?= $filterStatus === 'returned' ? 'active-returned' : '' ?>">
                        Returned <span class="tab-badge"><?= $returnedCount ?></span>
                    </a>
                    <a href="<?= tabUrl('all') ?>"
                       class="tab-btn <?= $filterStatus === 'all' ? 'active-all' : '' ?>">
                        All History
                    </a>
                </div>

                <!-- Search filters -->
                <div class="filter-wrap">
                    <form method="GET" action="" id="filterForm" class="filter-bar">
                        <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
                        <div class="fg fg-search">
                            <label>Search User</label>
                            <input type="text" name="user" placeholder="Username or full name"
                                   value="<?= htmlspecialchars($filterUser) ?>">
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
                        of <strong><?= $totalRecords ?></strong> reservation<?= $totalRecords !== 1 ? 's' : '' ?>
                    </p>
                </div>
            </div>

            <!-- Reservations table -->
            <div class="table-scroll">
            <table class="transaction_table reservation" width="100%">
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Requested By</th>
                <th>Reserved Date</th>
                <th>Status</th>
                <?php if ($filterStatus !== 'pending'): ?>
                <th>Details</th>
                <?php endif; ?>
                <th>Submitted</th>
                <?php if ($filterStatus === 'pending' || $filterStatus === 'all'): ?>
                <th>Action</th>
                <?php endif; ?>
            </tr>

            <?php
            $hasRows = false;
            while ($row = mysqli_fetch_assoc($result)):
                $hasRows = true;
                $isPast  = ($row['reserved_date'] < $today);
            ?>
            <tr>
                <td><?= $row['reservation_id'] ?></td>
                <td><?= htmlspecialchars($row['resource_name']) ?></td>
                <td>
                    <?= htmlspecialchars($row['requester_name']) ?>
                    <?php if ($row['requester_full']): ?>
                        <br><small style="color:#aaa;"><?= htmlspecialchars($row['requester_full']) ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?= htmlspecialchars($row['reserved_date']) ?>
                    <?php if ($isPast && $row['status'] === 'pending'): ?>
                        <span class="past-badge">PAST</span>
                    <?php endif; ?>
                </td>
                <td class="status <?= strtolower(str_replace(' ', '-', $row['status'])) ?>">
                    <span class="status-pill"><?= strtoupper($row['status']) ?></span>
                </td>

                <?php if ($filterStatus !== 'pending'): ?>
                <td class="detail-cell">
                    <?php if ($row['status'] === 'approved'): ?>
                        Approved by <b><?= htmlspecialchars($row['approved_by_name'] ?? '—') ?></b><br>
                        At: <?= $row['approved_at'] ?? '—' ?>
                    <?php elseif ($row['status'] === 'rejected'): ?>
                        Rejected by <b><?= htmlspecialchars($row['rejected_by_name'] ?? '—') ?></b><br>
                        At: <?= $row['rejected_at'] ?? '—' ?><br>
                        Reason: <span class="reason"><?= htmlspecialchars($row['remarks'] ?? '—') ?></span>
                    <?php else: ?>
                        —
                    <?php endif; ?>
                </td>
                <?php endif; ?>

                <td style="font-size:.82rem;color:#888;"><?= $row['created_at'] ?></td>

                <?php if ($filterStatus === 'pending' || $filterStatus === 'all'): ?>
                <td class="actions">
                    <?php if ($row['status'] === 'pending'): ?>
                        <form method="POST" action="../admin/approve.php" style="display:inline;" class="approve-reservation-form">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="id" value="<?= $row['reservation_id'] ?>">
                            <button type="button" class="btn-approve" onclick="openApproveReservationModal(this.form)">Approve</button>
                        </form>
                        <button class="btn-reject"
                                onclick="openRejectModal(<?= $row['reservation_id'] ?>)">Reject</button>
                    <?php else: ?>
                        <span style="color:#ccc;font-size:.82rem;">—</span>
                    <?php endif; ?>
                </td>
                <?php endif; ?>
            </tr>
            <?php endwhile; ?>

            <?php if (!$hasRows): ?>
            <tr>
                <td colspan="10">
                    <div class="empty-state">
                        <svg xmlns="http://www.w3.org/2000/svg" height="48px" viewBox="0 -960 960 960" width="48px" fill="#aaa"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm200-198q13 0 21.5-8.5T510-820t-8.5-21.5T480-850t-21.5 8.5T450-820t8.5 21.5T480-798ZM200-200v-560 560Z"/></svg>
                        <p>No reservations match your filters.</p>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
        </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <a href="<?= pageUrl(1) ?>" class="<?= $page === 1 ? 'disabled' : '' ?>">&laquo; First</a>
            <?php if ($page > 1): ?>
                <a href="<?= pageUrl($page - 1) ?>">&lsaquo; Prev</a>
            <?php endif; ?>
            <?php
            $start = max(1, $page - 2);
            $end   = min($totalPages, $page + 2);
            for ($i = $start; $i <= $end; $i++):
            ?>
                <a href="<?= pageUrl($i) ?>" class="<?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
            <?php endfor; ?>
            <?php if ($page < $totalPages): ?>
                <a href="<?= pageUrl($page + 1) ?>">Next &rsaquo;</a>
            <?php endif; ?>
            <a href="<?= pageUrl($totalPages) ?>" class="<?= $page === $totalPages ? 'disabled' : '' ?>">Last &raquo;</a>
        </div>
        <?php endif; ?>

        </div><!-- /.table-wrap -->
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

<!-- Reject modal -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3>Reject Reservation</h3>
        <p>Please provide a reason for rejecting this reservation request.</p>
        <form method="POST" action="../admin/reject.php" id="rejectForm">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="id" id="rejectId">
            <textarea name="remarks" id="rejectRemarks" placeholder="Enter reason here..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-confirm-reject">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>

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
        <h3>Are you sure?</h3>
        <p class="confirm-body">Approve this reservation request now?</p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="approveReservationConfirmBtn">Approve Reservation</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeApproveReservationModal()">Cancel</button>
        </div>
    </div>
</div>

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
let _approveReservationForm = null;

function openApproveReservationModal(formEl) {
    _approveReservationForm = formEl;
    document.getElementById('approveReservationModal').classList.add('active');
}
function closeApproveReservationModal() {
    document.getElementById('approveReservationModal').classList.remove('active');
    _approveReservationForm = null;
}
function openNoticeModal(message) {
    document.getElementById('noticeModalMessage').textContent = message;
    document.getElementById('noticeModal').classList.add('active');
}
function closeNoticeModal() {
    document.getElementById('noticeModal').classList.remove('active');
}
document.getElementById('approveReservationConfirmBtn').addEventListener('click', function() {
    if (_approveReservationForm) _approveReservationForm.submit();
});
document.getElementById('approveReservationModal').addEventListener('click', function(e) {
    if (e.target === this) closeApproveReservationModal();
});
document.getElementById('noticeModal').addEventListener('click', function(e) {
    if (e.target === this) closeNoticeModal();
});

function openRejectModal(id) {
    document.getElementById('rejectId').value = id;
    document.getElementById('rejectRemarks').value = '';
    document.getElementById('rejectModal').classList.add('active');
}
function closeRejectModal() {
    document.getElementById('rejectModal').classList.remove('active');
}
document.getElementById('rejectModal').addEventListener('click', function(e) {
    if (e.target === this) closeRejectModal();
});

// Validate date range
document.getElementById('filterForm').addEventListener('submit', function(e) {
    const from = this.date_from.value;
    const to   = this.date_to.value;
    if (from && to && from > to) {
        e.preventDefault();
        openNoticeModal('"Date From" cannot be later than "Date To".');
    }
});

// Open native date picker on click/focus
document.querySelectorAll('#filterForm input[type="date"]').forEach((input) => {
    const openPicker = () => {
        if (typeof input.showPicker === 'function') {
            try { input.showPicker(); } catch (e) {}
        }
    };

    input.addEventListener('click', openPicker);
    input.addEventListener('focus', openPicker);
});
</script>

</body>
</html>
