<?php
require_once __DIR__ . '/../database/db.php';

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User"; // default name

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // show first name
}

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

// Load transaction logs
$limit  = 10;
$page   = isset($_GET['page']) && is_numeric($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset = ($page - 1) * $limit;

$totalRow  = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipment_transactions"));
$totalRows  = (int) $totalRow['total'];
$totalPages = (int) ceil($totalRows / $limit);
$page = min($page, max(1, $totalPages));

$today = date('Y-m-d');
$todayRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipment_transactions WHERE DATE(action_date) = '$today'"));
$todayCount = (int) ($todayRow['total'] ?? 0);

$checkoutsRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipment_transactions WHERE status_to = 'In-Use'"));
$checkoutsCount = (int) ($checkoutsRow['total'] ?? 0);

$returnsRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM equipment_transactions WHERE status_to = 'Available'"));
$returnsCount = (int) ($returnsRow['total'] ?? 0);

$result = mysqli_query($conn, "
SELECT
    t.transaction_id,
    t.equipment_id,
    e.resource_name,
    t.performed_by,
    u.username  AS performed_name,
    u.full_name AS performed_fullname,
    t.status_from,
    t.status_to,
    t.action_date,
    t.remarks
FROM equipment_transactions t
JOIN equipments e ON t.equipment_id = e.equipment_id
LEFT JOIN users u ON t.performed_by = u.user_id
ORDER BY t.transaction_id DESC
LIMIT $limit OFFSET $offset
");
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

<?php include('sidebar.php');?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Transactions</h1>
        <p>Track every equipment status change in one place.</p>
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

<main class="main">
    <section class="transactions-hero">
        <div class="transactions-hero-copy">
            <p class="eyebrow">Asset Activity</p>
            <h2>Transaction logs</h2>
            <p class="hero-subtitle">View item status changes with time stamps and remarks.</p>
        </div>
        <div class="hero-stats">
            <div class="hero-stat">
                <span>Total Logs</span>
                <strong><?php echo $totalRows; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Today</span>
                <strong><?php echo $todayCount; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Returns</span>
                <strong><?php echo $returnsCount; ?></strong>
            </div>
            <div class="hero-stat">
                <span>Checkouts</span>
                <strong><?php echo $checkoutsCount; ?></strong>
            </div>
        </div>
    </section>

    <section class="section-card table-wrap">
        <div class="section-header">
            <h2>Transaction History</h2>
            <span class="meta-pill">Page <?php echo $page; ?> of <?php echo max(1, $totalPages); ?></span>
        </div>

        <?php if ($totalRows === 0): ?>
            <div class="empty-state">
                <h3>No transaction records yet</h3>
                <p>New logs will appear here when equipment status changes occur.</p>
            </div>
        <?php else: ?>
        <div class="table-scroll">
            <table class="transaction-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Equipment</th>
                        <th>Performed By</th>
                        <th>Action Date</th>
                        <th>From Status</th>
                        <th>To Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php while($row = mysqli_fetch_assoc($result)) { ?>
                    <tr style="background:#ffffff !important;">
                        <td>#<?php echo $row['transaction_id']; ?></td>
                        <td class="equip-name"><?php echo htmlspecialchars($row['resource_name']); ?></td>
                        <td>
                            <?php echo htmlspecialchars($row['performed_name'] ?? '—'); ?>
                            <?php if (!empty($row['performed_fullname'])): ?>
                                <span class="muted"><?php echo htmlspecialchars($row['performed_fullname']); ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="muted"><?php echo htmlspecialchars($row['action_date']); ?></td>
                        <td>
                            <span class="status-pill <?php echo strtolower(str_replace([' ', '_'], '-', $row['status_from'])); ?>">
                                <?php echo strtoupper($row['status_from']); ?>
                            </span>
                        </td>
                        <td>
                            <span class="status-pill <?php echo strtolower(str_replace([' ', '_'], '-', $row['status_to'])); ?>">
                                <?php echo strtoupper($row['status_to']); ?>
                            </span>
                        </td>
                        <td><?php echo htmlspecialchars($row['remarks'] ?? '—'); ?></td>
                    </tr>
                    <?php } ?>
                </tbody>
            </table>
        </div>

        <div class="pagination-wrap">
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?= $page - 1 ?>" class="page-btn">&laquo; Prev</a>
                <?php endif; ?>
                <?php for ($p = max(1,$page-2); $p <= min($totalPages,$page+2); $p++): ?>
                    <a href="?page=<?= $p ?>"
                       class="page-btn <?= $p === $page ? 'page-btn-active' : '' ?>">
                        <?= $p ?>
                    </a>
                <?php endfor; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?= $page + 1 ?>" class="page-btn">Next &raquo;</a>
                <?php endif; ?>
            </div>
            <p class="pagination-meta">
                Showing <?= ($offset + 1) ?>–<?= min($offset + $limit, $totalRows) ?> of <?= $totalRows ?> records
            </p>
        </div>
        <?php endif; ?>
    </section>
</main>



</body>
</html>