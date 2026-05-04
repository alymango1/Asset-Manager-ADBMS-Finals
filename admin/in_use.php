<?php
require_once __DIR__ . '/../database/db.php';
session_start();

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
        r.reserved_date,
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
    ORDER BY e.equipment_id ASC
");

$inUseCount = mysqli_num_rows($inUseQuery);
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

            <?php while ($row = mysqli_fetch_assoc($inUseQuery)): ?>
            <tr id="row-<?php echo $row['equipment_id']; ?>" style="background:#fff !important;">
                <td><?php echo $row['equipment_id']; ?></td>
                <td><b><?php echo htmlspecialchars($row['resource_name']); ?></b></td>
                <td><?php echo htmlspecialchars($row['categories']); ?></td>
                <td class="status in-use"><span class="status-pill">IN-USE</span></td>
                <td>
                    <?php if ($row['reservation_id']): ?>
                    <div class="res-meta">
                        <b>Res #<?php echo $row['reservation_id']; ?></b><br>
                        Requested by: <b><?php echo htmlspecialchars($row['requester_name'] ?? '—'); ?></b><br>
                        Reserved date: <?php echo $row['reserved_date']; ?><br>
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
            <?php endwhile; ?>
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


<script>
let returnTargetId   = null;
let returnTargetName = null;

function openReturnModal(equipmentId, equipmentName) {
    returnTargetId   = equipmentId;
    returnTargetName = equipmentName;

    document.getElementById('returnEquipmentName').textContent = equipmentName;
    document.getElementById('returnRemarks').value             = '';
    document.getElementById('returnModalMsg').textContent      = '';
    document.getElementById('confirmReturnBtn').disabled       = false;
    document.getElementById('confirmReturnBtn').textContent    = 'Confirm Return';
    document.getElementById('returnModal').classList.add('active');
}

function closeReturnModal() {
    document.getElementById('returnModal').classList.remove('active');
    returnTargetId   = null;
    returnTargetName = null;
}

// Close modal when clicking backdrop
document.getElementById('returnModal').addEventListener('click', function(e) {
    if (e.target === this) closeReturnModal();
});

function submitReturn() {
    if (!returnTargetId) return;

    const remarks = document.getElementById('returnRemarks').value.trim();
    const btn     = document.getElementById('confirmReturnBtn');
    const msgEl   = document.getElementById('returnModalMsg');

    btn.disabled    = true;
    btn.textContent = 'Processing…';
    msgEl.textContent = '';
    msgEl.style.color = 'red';

    const formData = new FormData();
    formData.append('equipment_id', returnTargetId);
    formData.append('remarks',      remarks);
    formData.append('csrf_token',   document.querySelector('meta[name="csrf-token"]').content);

    fetch('return_equipment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeReturnModal();

            // Remove returned row
            const row = document.getElementById('row-' + data.equipment_id);
            if (row) {
                row.style.transition = 'opacity 0.3s';
                row.style.opacity    = '0';
                setTimeout(() => {
                    row.remove();
                    checkEmptyTable();
                }, 300);
            }

            showToast(data.message, 'success');
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

function checkEmptyTable() {
    const tbody = document.querySelector('.transaction_table.equipment tbody');
    const rows  = document.querySelectorAll('.transaction_table.equipment tr[id^="row-"]');

    if (rows.length === 0) {
        // Swap table with empty state
        const wrap = document.querySelector('.table-wrap');
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
        // Remove badge when no items left
        const badge = document.querySelector('.inuse-badge');
        if (badge) badge.remove();
    } else {
        // Refresh badge count
        const badge = document.querySelector('.inuse-badge span');
        if (badge) badge.textContent = rows.length + ' item' + (rows.length !== 1 ? 's' : '');
    }
}

function showToast(message, type = 'success') {
    const toast = document.getElementById('toastNotif');
    toast.textContent = message;
    toast.className   = 'show toast-' + type;
    setTimeout(() => { toast.className = ''; }, 3500);
}
</script>

</body>
</html>