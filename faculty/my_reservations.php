<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

$user_id = $_SESSION['user_id'];
$my_reservations = "
SELECT e.resource_name, r.reserved_date, r.status, r.reservation_id, r.created_at FROM reservations r
LEFT JOIN equipments e ON r.equipment_id = e.equipment_id 
WHERE requested_by = $user_id
ORDER BY r.created_at DESC;
" ;
$result_reservations = mysqli_query($conn, $my_reservations);


?>

<!DOCTYPE html>
<html>
<head>
    <title>Reservations</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Reservations</h1>

        <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>        
        </button>
        </div>

        <!-- DROPDOWN -->
        <div class="dropdown" id="dropdownMenu">
            <p>Greetings, <?php echo $name?>!</p>
            <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
        </div>

    </div>
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");

btn.addEventListener("click", function (e) {
    e.stopPropagation();
    menu.classList.toggle("active");
});

// close when clicking outside
document.addEventListener("click", function () {
    menu.classList.remove("active");
});
</script>

<div class="main">

    <div class="table-wrap">
        <h2>My Reservations</h2>
        <table class="transaction_table" width="100%">
            <tr>
                <th>ID</th>
                <th>Equipment</th>
                <th>Reservation Created On</th>
                <th>Date to be Used</th>
                <th>Status</th>
                <th>Action</th>
            </tr>

            <?php while($row = mysqli_fetch_assoc($result_reservations)) { ?>
            <tr id="res-row-<?php echo $row['reservation_id']; ?>">
                <td><?php echo $row['reservation_id']; ?></td>
                <td><?php echo htmlspecialchars($row['resource_name']); ?></td>
                <td><?php echo $row['created_at']; ?></td>
                <td><?php echo $row['reserved_date']; ?></td>
                <td class="status <?php echo strtolower(str_replace(' ', '-', $row['status'])); ?>">
                    <?php echo strtoupper($row['status']); ?>
                </td>
                <td class="actions">
                    <?php if ($row['status'] === 'pending'): ?>
                        <button class="btn-cancel-res"
                            onclick="openCancelModal(<?php echo $row['reservation_id']; ?>, '<?php echo htmlspecialchars(addslashes($row['resource_name'])); ?>')">
                            <svg xmlns="http://www.w3.org/2000/svg" height="15px" viewBox="0 -960 960 960" width="15px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                            Cancel
                        </button>
                    <?php else: ?>
                        <span class="no-action">—</span>
                    <?php endif; ?>
                </td>
            </tr>
            <?php } ?>

        </table>

    </div>

<br><br>

    <div class="table-wrap intro-card reservation-guide">

    <h2>Reservation Management Guide</h2>

    <p class="intro-text">
        This page allows administrators to review, approve, or reject equipment reservation requests.
        Each request comes from faculty or staff who need to borrow equipment for academic or institutional use.
    </p>

    <div class="intro-grid">

        <div class="intro-item info">
            <h3>Pending Requests</h3>
            <p>All requests shown here are waiting for your approval or rejection.</p>
        </div>

        <div class="intro-item success">
            <h3>Approve Reservation</h3>
            <p>Click <b>Approve</b> if the equipment is available and the request is valid.</p>
        </div>

        <div class="intro-item danger">
            <h3>Reject Reservation</h3>
            <p>Reject requests that are invalid or when equipment is unavailable. A reason is required.</p>
        </div>

        <div class="intro-item warning">
            <h3>Important Note</h3>
            <p>Approved requests will automatically be recorded in transaction logs for tracking.</p>
        </div>

    </div>

</div>


</div>


<!-- ── Cancel Confirmation Modal ── -->
<div class="modal-overlay" id="cancelModal">
    <div class="modal-box">
        <div class="modal-header">
            <div class="modal-header-icon" style="background:#C40C0C;">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M480-280q17 0 28.5-11.5T520-320q0-17-11.5-28.5T480-360q-17 0-28.5 11.5T440-320q0 17 11.5 28.5T480-280Zm-40-160h80v-240h-80v240Zm40 360q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
            </div>
            <div>
                <h3 id="cancelModalTitle">Cancel Reservation</h3>
                <p class="modal-subtitle">This action cannot be undone.</p>
            </div>
            <button class="modal-close-btn" onclick="closeCancelModal()">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <div style="padding:18px 22px 0;">
            <p style="margin:0; font-size:0.92rem; color:#555;">
                Are you sure you want to cancel your reservation for:<br>
                <strong id="cancelEquipmentName" style="color:#1a1a2e;">—</strong>?
            </p>
            <p style="margin:12px 0 0; font-size:0.82rem; color:#999;">
                Only pending reservations can be cancelled. The equipment will become available for others to reserve.
            </p>
        </div>

        <p id="cancelModalMsg" style="color:#C40C0C; font-size:0.83rem; padding:6px 22px 0; margin:0; min-height:1.2em;"></p>

        <div class="modal-actions" style="padding:16px 22px 22px;">
            <button type="button" class="modal-btn-cancel-action" onclick="closeCancelModal()">Keep Reservation</button>
            <button type="button" id="confirmCancelBtn" class="modal-btn-confirm-cancel" onclick="submitCancel()">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                Yes, Cancel It
            </button>
        </div>
    </div>
</div>

<!-- ── Toast ── -->
<div class="toast" id="cancelToast">
    <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
    Reservation cancelled successfully.
</div>

<style>
/* ── Cancel button in table ── */
.btn-cancel-res {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    background: #fff0f0;
    color: #C40C0C;
    border: 1.5px solid #f5c6c6;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s, border-color 0.15s, transform 0.1s;
}
.btn-cancel-res:hover {
    background: #ffe0e0;
    border-color: #C40C0C;
    transform: translateY(-1px);
}
.no-action { color: #ccc; font-size: 0.85rem; }

/* ── Modal shared styles ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(4px);
    -webkit-backdrop-filter: blur(4px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 16px;
    width: 460px;
    max-width: 94vw;
    box-shadow: 0 24px 70px rgba(0,0,0,0.28);
    animation: modalIn 0.22s cubic-bezier(.34,1.56,.64,1);
    overflow: hidden;
}
@keyframes modalIn {
    from { transform: scale(0.88) translateY(20px); opacity: 0; }
    to   { transform: scale(1) translateY(0); opacity: 1; }
}
.modal-header {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 20px 22px 16px;
    border-bottom: 1px solid #f0e0e0;
    background: linear-gradient(135deg, #fff5f5, #fff);
}
.modal-header-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.modal-header h3 { margin: 0; font-size: 1.05rem; color: #2c0b0b; }
.modal-subtitle  { margin: 2px 0 0; font-size: 0.82rem; color: #999; }
.modal-close-btn {
    margin-left: auto;
    background: none;
    border: none;
    cursor: pointer;
    color: #aaa;
    padding: 6px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    transition: background 0.15s, color 0.15s;
}
.modal-close-btn:hover { background: #fee; color: #C40C0C; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; }

.modal-btn-cancel-action {
    padding: 10px 20px;
    border: 1.5px solid #ddd;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 0.9rem;
    font-family: inherit;
    color: #555;
    transition: background 0.15s;
}
.modal-btn-cancel-action:hover { background: #f5f5f5; }

.modal-btn-confirm-cancel {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 20px;
    border: none;
    border-radius: 10px;
    background: #C40C0C;
    color: #fff;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 700;
    font-family: inherit;
    transition: background 0.15s, transform 0.1s;
}
.modal-btn-confirm-cancel:hover    { background: #8e0000; transform: translateY(-1px); }
.modal-btn-confirm-cancel:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* ── Toast ── */
.toast {
    position: fixed;
    bottom: 30px;
    right: 30px;
    background: #1b5e20;
    color: #fff;
    padding: 14px 20px;
    border-radius: 12px;
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 0.9rem;
    font-weight: 600;
    box-shadow: 0 8px 30px rgba(0,0,0,0.25);
    z-index: 99999;
    opacity: 0;
    transform: translateY(20px);
    pointer-events: none;
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.toast.show { opacity: 1; transform: translateY(0); }
</style>

<script>
let _cancelId   = null;
let _cancelName = null;

function openCancelModal(reservationId, equipmentName) {
    _cancelId   = reservationId;
    _cancelName = equipmentName;
    document.getElementById('cancelEquipmentName').textContent = equipmentName;
    document.getElementById('cancelModalMsg').textContent = '';
    const btn = document.getElementById('confirmCancelBtn');
    btn.disabled    = false;
    btn.innerHTML   = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg> Yes, Cancel It';
    document.getElementById('cancelModal').classList.add('active');
}

function closeCancelModal() {
    document.getElementById('cancelModal').classList.remove('active');
    _cancelId   = null;
    _cancelName = null;
}

document.getElementById('cancelModal').addEventListener('click', function(e) {
    if (e.target === this) closeCancelModal();
});

function submitCancel() {
    if (!_cancelId) return;

    const btn   = document.getElementById('confirmCancelBtn');
    const msgEl = document.getElementById('cancelModalMsg');

    btn.disabled  = true;
    btn.innerHTML = 'Cancelling…';
    msgEl.textContent = '';

    const fd = new FormData();
    fd.append('reservation_id', _cancelId);

    fetch('cancel_reservation.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeCancelModal();

                // Fade out and remove the row
                const row = document.getElementById('res-row-' + data.reservation_id);
                if (row) {
                    row.style.transition = 'opacity 0.35s';
                    row.style.opacity    = '0';
                    setTimeout(() => row.remove(), 360);
                }

                // Show toast
                const toast = document.getElementById('cancelToast');
                toast.classList.add('show');
                setTimeout(() => toast.classList.remove('show'), 3500);
            } else {
                msgEl.textContent = data.message || 'Could not cancel. Please try again.';
                btn.disabled      = false;
                btn.innerHTML     = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg> Yes, Cancel It';
            }
        })
        .catch(() => {
            msgEl.textContent = 'Network error. Please try again.';
            btn.disabled      = false;
            btn.innerHTML     = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224-224-224 224Z"/></svg> Yes, Cancel It';
        });
}
</script>

</body>
</html>