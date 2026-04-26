<?php
include('../database/db.php');
session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(" ", trim($_SESSION['full_name']));
    $name = $nameParts[0];
}

$today = date('Y-m-d');

// ── Filter inputs (sanitized) ──────────────────────────────────
$filterStatus = isset($_GET['status'])     ? trim($_GET['status'])     : 'pending';
$filterUser   = isset($_GET['user'])       ? trim(mysqli_real_escape_string($conn, $_GET['user']))   : '';
$filterDateFrom = isset($_GET['date_from']) ? trim($_GET['date_from']) : '';
$filterDateTo   = isset($_GET['date_to'])   ? trim($_GET['date_to'])   : '';

$allowedStatuses = ['all', 'pending', 'approved', 'rejected'];
if (!in_array($filterStatus, $allowedStatuses)) $filterStatus = 'pending';

// ── Build WHERE clause ─────────────────────────────────────────
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

// ── Pagination ─────────────────────────────────────────────────
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

// ── Summary counts (always shown regardless of filter) ─────────
$pendingCount  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='pending'"))['c'];
$approvedCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='approved'"))['c'];
$rejectedCount = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM reservations WHERE status='rejected'"))['c'];

// ── Main query ─────────────────────────────────────────────────
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
        FIELD(r.status, 'pending', 'approved', 'rejected'),
        r.reserved_date ASC
    LIMIT $limit OFFSET $offset
";
$result = mysqli_query($conn, $query);

// ── Helper: build pagination URL ──────────────────────────────
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
    <link rel="stylesheet" href="../css/style.css">
    <style>
        /* ── Status tab bar ── */
        .tab-bar {
            display: flex;
            gap: 8px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        .tab-btn {
            padding: 7px 18px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
            border: 2px solid transparent;
            cursor: pointer;
            text-decoration: none;
            transition: all .15s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f1f1f4;
            color: #555;
        }
        .tab-btn:hover { background: #e2e2ea; color: #222; }
        .tab-btn.active-all      { background: #1a1a2e; color: #fff; border-color: #1a1a2e; }
        .tab-btn.active-pending  { background: #fff3cd; color: #856404; border-color: #ffc107; }
        .tab-btn.active-approved { background: #d4edda; color: #155724; border-color: #28a745; }
        .tab-btn.active-rejected { background: #f8d7da; color: #721c24; border-color: #dc3545; }
        .tab-badge {
            background: rgba(0,0,0,.12);
            border-radius: 10px;
            padding: 1px 7px;
            font-size: 0.78rem;
        }

        /* ── Filter bar ── */
        .filter-bar {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: flex-end;
            margin-bottom: 18px;
            background: #f7f7f9;
            border: 1px solid #ebebeb;
            border-radius: 10px;
            padding: 14px 16px;
        }
        .filter-bar .fg {
            display: flex;
            flex-direction: column;
            gap: 4px;
            flex: 1;
            min-width: 140px;
        }
        .filter-bar label {
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .05em;
            color: #999;
        }
        .filter-bar input,
        .filter-bar select {
            padding: 8px 10px;
            border: 1px solid #ddd;
            border-radius: 7px;
            font-size: 0.9rem;
            background: #fff;
            outline: none;
        }
        .filter-bar input:focus,
        .filter-bar select:focus { border-color: #c0392b; }
        .filter-actions {
            display: flex;
            gap: 8px;
            align-items: flex-end;
        }
        .btn-filter {
            padding: 8px 18px;
            background: #c0392b;
            color: #fff;
            border: none;
            border-radius: 7px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-filter:hover { background: #a93226; }
        .btn-clear {
            padding: 8px 14px;
            background: #eee;
            color: #555;
            border: none;
            border-radius: 7px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
        }
        .btn-clear:hover { background: #ddd; }

        /* ── Result meta ── */
        .result-meta {
            font-size: 0.84rem;
            color: #888;
            margin-bottom: 10px;
        }
        .result-meta b { color: #333; }

        /* ── Actions cell ── */
        td.actions { white-space: nowrap; }

        /* ── Past-date warning badge on table ── */
        .past-badge {
            display: inline-block;
            font-size: 0.7rem;
            background: #f8d7da;
            color: #721c24;
            border-radius: 4px;
            padding: 1px 6px;
            margin-left: 4px;
            vertical-align: middle;
            font-weight: 600;
        }

        /* ── Details cell ── */
        .detail-cell { font-size: 0.82rem; color: #555; line-height: 1.5; }
        .detail-cell b { color: #333; }
        .reason { font-style: italic; color: #888; }

        /* ── Pagination ── */
        .pagination { display:flex; gap:6px; margin-top:16px; flex-wrap:wrap; }
        .pagination a {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #ddd;
            font-size: 0.85rem;
            color: #333;
            text-decoration: none;
            background: #fff;
        }
        .pagination a:hover { background: #f4f4f4; }
        .pagination a.active { background: #c0392b; color: #fff; border-color: #c0392b; }
        .pagination a.disabled { color: #bbb; pointer-events: none; }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 48px 20px;
            color: #aaa;
        }
        .empty-state svg { margin-bottom: 12px; opacity: .4; }
        .empty-state p { font-size: 0.95rem; }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="header">
    <h1>Reservations</h1>
    <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>
        </button>
    </div>
    <div class="dropdown" id="dropdownMenu">
        <p>Greetings, <?php echo htmlspecialchars($name); ?>!</p>
        <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
    </div>
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");
btn.addEventListener("click", e => { e.stopPropagation(); menu.classList.toggle("active"); });
document.addEventListener("click", () => menu.classList.remove("active"));
</script>

<div class="main">

    <!-- Flash messages -->
    <?php if (isset($_SESSION['error'])): ?>
        <div class="message-box" style="background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.9rem;">
            <?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?>
        </div>
    <?php endif; ?>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="message-box" style="background:#d4edda;color:#155724;border:1px solid #c3e6cb;padding:12px 16px;border-radius:8px;margin-bottom:16px;font-size:.9rem;">
            <?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?>
        </div>
    <?php endif; ?>

    <div class="table-wrap">
        <h2>Reservation List</h2>

        <!-- ── Status tab bar ── -->
        <?php
        // Build tab URLs (preserve other filters, reset page)
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
            <a href="<?= tabUrl('all') ?>"
               class="tab-btn <?= $filterStatus === 'all'      ? 'active-all'      : '' ?>">
               All History
            </a>
        </div>

        <!-- ── Search / filter bar ── -->
        <form method="GET" action="" id="filterForm">
            <input type="hidden" name="status" value="<?= htmlspecialchars($filterStatus) ?>">
            <div class="filter-bar">
                <div class="fg">
                    <label>Search User</label>
                    <input type="text" name="user" placeholder="Username or full name"
                           value="<?= htmlspecialchars($filterUser) ?>">
                </div>
                <div class="fg">
                    <label>Date From</label>
                    <input type="date" name="date_from"
                           value="<?= htmlspecialchars($filterDateFrom) ?>">
                </div>
                <div class="fg">
                    <label>Date To</label>
                    <input type="date" name="date_to"
                           value="<?= htmlspecialchars($filterDateTo) ?>">
                </div>
                <div class="filter-actions">
                    <button type="submit" class="btn-filter">Filter</button>
                    <a href="?status=<?= htmlspecialchars($filterStatus) ?>" class="btn-clear">Clear</a>
                </div>
            </div>
        </form>

        <!-- Result meta -->
        <p class="result-meta">
            Showing <b><?= min($offset + 1, $totalRecords) ?>–<?= min($offset + $limit, $totalRecords) ?></b>
            of <b><?= $totalRecords ?></b> reservation<?= $totalRecords !== 1 ? 's' : '' ?>
        </p>

        <!-- ── Table ── -->
        <table class="transaction_table" width="100%">
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
                    <?= strtoupper($row['status']) ?>
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
                        <a class="btn-approve"
                           href="../admin/approve.php?id=<?= $row['reservation_id'] ?>"
                           onclick="return confirm('Approve this reservation?')">Approve</a>
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

        <!-- ── Pagination ── -->
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

    <!-- ── Guide card ── -->
    <br>
    <div class="table-wrap intro-card reservation-guide">
        <h2>Reservation Management Guide</h2>
        <p class="intro-text">Review, approve, or reject equipment reservation requests. Use the tabs to browse history and the filters to narrow down by user or date range.</p>
        <div class="intro-grid">
            <div class="intro-item info">
                <h3>Pending Requests</h3>
                <p>All requests awaiting your decision. Reservations for <b>past dates</b> are flagged with a PAST badge.</p>
            </div>
            <div class="intro-item success">
                <h3>Approve Reservation</h3>
                <p>Click <b>Approve</b> if the equipment is available and the request is valid.</p>
            </div>
            <div class="intro-item danger">
                <h3>Reject Reservation</h3>
                <p>Reject invalid requests. A reason is required and will be visible in history.</p>
            </div>
            <div class="intro-item warning">
                <h3>History Tabs</h3>
                <p>Use <b>Approved</b>, <b>Rejected</b>, or <b>All History</b> tabs to review past decisions.</p>
            </div>
        </div>
    </div>

</div><!-- /.main -->

<!-- ── Reject Modal ── -->
<div class="modal-overlay" id="rejectModal">
    <div class="modal-box">
        <h3>Reject Reservation</h3>
        <p>Please provide a reason for rejecting this reservation request.</p>
        <form method="POST" action="../admin/reject.php" id="rejectForm">
            <input type="hidden" name="id" id="rejectId">
            <textarea name="remarks" id="rejectRemarks" placeholder="Enter reason here..." required></textarea>
            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeRejectModal()">Cancel</button>
                <button type="submit" class="btn-confirm-reject">Confirm Reject</button>
            </div>
        </form>
    </div>
</div>

<script>
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

// ── Date-range validation in filter form ──
document.getElementById('filterForm').addEventListener('submit', function(e) {
    const from = this.date_from.value;
    const to   = this.date_to.value;
    if (from && to && from > to) {
        e.preventDefault();
        alert('"Date From" cannot be later than "Date To".');
    }
});
</script>

</body>
</html>