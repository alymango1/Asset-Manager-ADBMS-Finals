<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'staff') {
    header("Location: login.php");
    exit();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(" ", trim($_SESSION['full_name']));
    $name = $nameParts[0];
}

// make display name and initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$firstNameRaw = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw)[0] : 'User';
$name = ucfirst(strtolower($firstNameRaw));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$user_id = (int)$_SESSION['user_id'];

$myReservationsQuery = "
SELECT
    r.reservation_id,
    r.created_at,
    r.reserved_date,
    r.status,
    r.remarks,
    e.resource_name,
    e.categories
FROM reservations r
LEFT JOIN equipments e ON r.equipment_id = e.equipment_id
WHERE r.requested_by = $user_id
ORDER BY r.created_at DESC
";
$resultReservations = mysqli_query($conn, $myReservationsQuery);

$countTotal = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id"))['total'] ?? 0);
$countPending = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status = 'pending'"))['total'] ?? 0);
$countApproved = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status = 'approved'"))['total'] ?? 0);
$countCompleted = (int)(mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE requested_by = $user_id AND status IN ('returned','cancelled','rejected')"))['total'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
    <title>My Reservations</title>
    <link rel="stylesheet" href="../css/faculty/style.css">
    <link rel="stylesheet" href="../css/faculty/dashboard.css">
    <link rel="stylesheet" href="../css/faculty/sidebar.css">
    <link rel="stylesheet" href="../css/faculty/modal.css">
    <link rel="stylesheet" href="../css/faculty/my_reservations.css">
</head>
<body>

<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>My Reservations</h1>
        <p>Track every request from submission to completion</p>
    </div>
    <div class="topbar-right">
        <span class="topbar-date"><?php echo date('l, F j, Y'); ?></span>
        <div class="profile-wrap">
            <button class="profile-btn" id="profileBtn"><?php echo htmlspecialchars($profileInitials); ?></button>
            <div class="profile-dropdown" id="profileDropdown">
                <div class="profile-dropdown-header">
                    <p><?php echo htmlspecialchars(($_SESSION['full_name'] ?? '') !== '' ? ucwords(strtolower($_SESSION['full_name'])) : $name); ?></p>
                    <p>Staff</p>
                </div>
                <a href="logout.php" class="danger">Sign Out</a>
            </div>
        </div>
    </div>
</header>

<main class="main">
    <section class="hero-card">
        <div>
            <p class="eyebrow">Faculty Reservation</p>
            <h2>Reservations History</h2>
            <p class="hero-subtitle">Monitor pending, confirmed, rejected, and completed requests.</p>
        </div>
    </section>

    <section class="metrics-grid">
        <article class="metric-card metric-total"><p>Total Requests</p><strong><?php echo $countTotal; ?></strong></article>
        <article class="metric-card metric-pending"><p>Pending</p><strong><?php echo $countPending; ?></strong></article>
        <article class="metric-card metric-approved"><p>Approved</p><strong><?php echo $countApproved; ?></strong></article>
        <article class="metric-card metric-completed"><p>Completed / Closed</p><strong><?php echo $countCompleted; ?></strong></article>
    </section>

    <section class="table-wrap section-card">
        <div class="section-header">
            <h2>Reservation History</h2>
        </div>

        <div class="table-scroll">
            <table class="reservation-table">
                <tr>
                    <th>ID</th>
                    <th>Equipment</th>
                    <th>Category</th>
                    <th>Requested On</th>
                    <th>Use Date</th>
                    <th>Status</th>
                    <th>Action</th>
                </tr>
                <?php if (!$resultReservations || mysqli_num_rows($resultReservations) === 0): ?>
                <tr>
                    <td colspan="7" class="empty-state">No reservation records found yet.</td>
                </tr>
                <?php else: ?>
                <?php while($row = mysqli_fetch_assoc($resultReservations)): ?>
                <tr id="res-row-<?php echo (int)$row['reservation_id']; ?>">
                    <td>#<?php echo (int)$row['reservation_id']; ?></td>
                    <td><?php echo htmlspecialchars($row['resource_name'] ?? 'Unknown Equipment'); ?></td>
                    <td><?php echo htmlspecialchars($row['categories'] ?? 'N/A'); ?></td>
                    <td><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime($row['created_at']))); ?></td>
                    <td><?php echo htmlspecialchars(date('M j, Y', strtotime($row['reserved_date']))); ?></td>
                    <td class="status">
                        <span class="status-pill <?php echo strtolower(str_replace(' ', '-', $row['status'])); ?>"><?php echo htmlspecialchars(strtoupper($row['status'])); ?></span>
                    </td>
                    <td class="actions">
                        <?php if ($row['status'] === 'pending'): ?>
                        <button class="btn-cancel-res" onclick="cancelExistingReservation(<?php echo (int)$row['reservation_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['resource_name'] ?? 'Equipment')); ?>')">Cancel</button>
                        <?php else: ?>
                        <span class="no-action">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endwhile; ?>
                <?php endif; ?>
            </table>
        </div>
    </section>
</main>

<!-- Confirm modal (shared with reservation.php) -->
<div class="modal-overlay" id="confirmActionModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeConfirmActionModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#EA3323"><path d="m40-120 440-760 440 760H40Zm138-80h604L480-720 178-200Zm330.5-51.5Q520-263 520-280t-11.5-28.5Q497-320 480-320t-28.5 11.5Q440-297 440-280t11.5 28.5Q463-240 480-240t28.5-11.5ZM440-360h80v-200h-80v200Zm40-100Z"/></svg>
            </span>
        </div>
        <h3 id="confirmActionTitle">Are you sure?</h3>
        <p id="confirmActionMsg" class="confirm-body"></p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="confirmActionProceedBtn">Continue</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeConfirmActionModal()">Cancel</button>
        </div>
    </div>
</div>

<div class="toast" id="cancelToast">Reservation cancelled successfully.</div>

<script>
const profileBtn = document.getElementById('profileBtn');
const profileDropdown = document.getElementById('profileDropdown');
profileBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    profileDropdown.classList.toggle('open');
});
document.addEventListener('click', () => profileDropdown.classList.remove('open'));

const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]').content;
let _confirmActionCallback = null;

function openConfirmActionModal(message, onConfirm) {
    _confirmActionCallback = onConfirm;
    document.getElementById('confirmActionTitle').textContent = 'Are you sure?';
    document.getElementById('confirmActionMsg').textContent = message;
    document.querySelector('#confirmActionModal .confirm-btn-secondary').style.display = 'inline-flex';
    document.getElementById('confirmActionProceedBtn').textContent = 'Continue';
    document.getElementById('confirmActionProceedBtn').className = 'confirm-btn-danger';
    document.getElementById('confirmActionModal').classList.add('active');
}

function openInfoActionModal(message) {
    _confirmActionCallback = null;
    document.getElementById('confirmActionTitle').textContent = 'Please check this';
    document.getElementById('confirmActionMsg').textContent = message;
    document.querySelector('#confirmActionModal .confirm-btn-secondary').style.display = 'none';
    document.getElementById('confirmActionProceedBtn').textContent = 'Close';
    document.getElementById('confirmActionProceedBtn').className = 'confirm-btn-secondary';
    document.getElementById('confirmActionModal').classList.add('active');
}

function closeConfirmActionModal() {
    document.getElementById('confirmActionModal').classList.remove('active');
    _confirmActionCallback = null;
}

// close modal on outside click
document.getElementById('confirmActionModal').addEventListener('click', function(e) {
    if (e.target === this) closeConfirmActionModal();
});

document.getElementById('confirmActionProceedBtn').addEventListener('click', function() {
    if (typeof _confirmActionCallback === 'function') {
        const cb = _confirmActionCallback;
        closeConfirmActionModal();
        cb();
    } else {
        closeConfirmActionModal();
    }
});

function cancelExistingReservation(reservationId, equipmentName) {
    if (!reservationId) return;
    openConfirmActionModal('Cancel reservation for "' + equipmentName + '"?', function() {
        const fd = new FormData();
        fd.append('reservation_id', reservationId);
        fd.append('csrf_token', CSRF_TOKEN);

        fetch('cancel_reservation.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    openInfoActionModal(data.message || 'Could not cancel reservation.');
                    return;
                }

                const row = document.getElementById('res-row-' + data.reservation_id);
                if (row) {
                    row.style.transition = 'opacity 0.25s ease';
                    row.style.opacity = '0';
                    setTimeout(() => row.remove(), 260);
                }

                const toast = document.getElementById('cancelToast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 3000);
            })
            .catch(() => {
                openInfoActionModal('An error occurred while cancelling. Please try again.');
            });
    });
}
</script>
</body>
</html>