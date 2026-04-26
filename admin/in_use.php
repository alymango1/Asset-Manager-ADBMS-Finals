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

// Flash message from redirect (after a successful return via non-AJAX fallback)
$flash = isset($_GET['returned']) ? 'Equipment successfully marked as Returned.' : '';

// ── Pull all In-Use equipment, joining most-recent approved reservation ──
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
    <title>In-Use Equipment</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">

    <style>
        /* ── Badge ── */
        .inuse-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #fff3cd;
            color: #856404;
            border: 1px solid #ffc107;
            border-radius: 20px;
            padding: 3px 12px;
            font-size: 0.78rem;
            font-weight: 600;
            margin-left: 10px;
            vertical-align: middle;
        }
        .inuse-badge span { font-size: 0.72rem; }

        /* ── Empty state ── */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #888;
        }
        .empty-state svg { opacity: 0.25; margin-bottom: 16px; }
        .empty-state h3  { font-size: 1.1rem; margin: 0 0 6px; color: #555; }
        .empty-state p   { font-size: 0.875rem; margin: 0; }

        /* ── Flash / toast ── */
        .flash-success {
            background: #d1fae5;
            color: #065f46;
            border: 1px solid #6ee7b7;
            border-radius: 8px;
            padding: 12px 18px;
            margin-bottom: 18px;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        /* ── Reservation meta inside table ── */
        .res-meta { font-size: 0.8rem; color: #666; line-height: 1.5; }
        .res-meta b { color: #333; }
        .no-res     { font-size: 0.8rem; color: #aaa; font-style: italic; }

        /* ── Return button ── */
        .btn-return {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 7px 16px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.82rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s, transform 0.1s;
        }
        .btn-return:hover   { background: #15803d; }
        .btn-return:active  { transform: scale(0.97); }
        .btn-return:disabled{ opacity: 0.55; cursor: not-allowed; }

        /* ── Return confirm modal ── */
        .modal-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.45);
            z-index: 9999;
            align-items: center;
            justify-content: center;
        }
        .modal-overlay.active { display: flex; }

        .modal-box {
            background: #fff;
            border-radius: 14px;
            padding: 30px 28px 24px;
            width: 420px;
            max-width: 94vw;
            box-shadow: 0 8px 40px rgba(0,0,0,0.18);
            animation: modalIn 0.18s ease;
        }
        @keyframes modalIn {
            from { opacity:0; transform: scale(0.94) translateY(8px); }
            to   { opacity:1; transform: scale(1)    translateY(0);   }
        }

        .modal-box h3 {
            margin: 0 0 6px;
            font-size: 1.1rem;
            color: #1a1a2e;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .modal-box h3 svg { color: #16a34a; }

        .modal-box .equipment-pill {
            display: inline-block;
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
            border-radius: 8px;
            padding: 6px 14px;
            font-size: 0.9rem;
            font-weight: 600;
            margin: 12px 0 16px;
        }

        .modal-box label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: #555;
            margin-bottom: 5px;
        }
        .modal-box textarea {
            width: 100%;
            border: 1px solid #ddd;
            border-radius: 8px;
            padding: 9px 12px;
            font-size: 0.875rem;
            font-family: inherit;
            resize: vertical;
            min-height: 80px;
            box-sizing: border-box;
            transition: border-color 0.15s;
        }
        .modal-box textarea:focus { outline: none; border-color: #16a34a; }

        #returnModalMsg {
            font-size: 0.82rem;
            min-height: 1.1em;
            margin-top: 6px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 18px;
        }
        .btn-cancel {
            padding: 8px 20px;
            background: #f5f5f5;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            font-size: 0.875rem;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s;
        }
        .btn-cancel:hover { background: #ebebeb; }

        .btn-confirm-return {
            padding: 8px 22px;
            background: #16a34a;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 0.875rem;
            font-weight: 600;
            cursor: pointer;
            font-family: inherit;
            transition: background 0.15s;
        }
        .btn-confirm-return:hover    { background: #15803d; }
        .btn-confirm-return:disabled { opacity: 0.55; cursor: not-allowed; }

        /* ── Toast notification ── */
        #toastNotif {
            position: fixed;
            bottom: 28px; right: 28px;
            background: #1a1a2e;
            color: #fff;
            padding: 14px 22px;
            border-radius: 10px;
            font-size: 0.88rem;
            font-weight: 500;
            box-shadow: 0 4px 20px rgba(0,0,0,0.22);
            opacity: 0;
            transform: translateY(12px);
            transition: opacity 0.25s, transform 0.25s;
            pointer-events: none;
            z-index: 99999;
            max-width: 360px;
        }
        #toastNotif.show {
            opacity: 1;
            transform: translateY(0);
        }
        #toastNotif.toast-success { border-left: 4px solid #22c55e; }
        #toastNotif.toast-error   { border-left: 4px solid #ef4444; }
    </style>
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="header">
    <h1>
        In-Use Equipment
        <?php if ($inUseCount > 0): ?>
            <span class="inuse-badge">
                <svg xmlns="http://www.w3.org/2000/svg" height="14px" viewBox="0 -960 960 960" width="14px" fill="currentColor">
                    <path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/>
                </svg>
                <span><?php echo $inUseCount; ?> item<?php echo $inUseCount !== 1 ? 's' : ''; ?></span>
            </span>
        <?php endif; ?>
    </h1>

    <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>
        </button>
    </div>

    <div class="dropdown" id="dropdownMenu">
        <p>Greetings, <?php echo htmlspecialchars($name); ?>!</p>
        <a href="logout.php">
            <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
            Logout
        </a>
    </div>
</div>

<script>
const btn  = document.getElementById('profileBtn');
const menu = document.getElementById('dropdownMenu');
btn.addEventListener('click', e => { e.stopPropagation(); menu.classList.toggle('active'); });
document.addEventListener('click', () => menu.classList.remove('active'));
</script>

<div class="main">
    <div class="table-wrap">
        <h2>Currently In-Use Equipment</h2>

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
            <tr id="row-<?php echo $row['equipment_id']; ?>">
                <td><?php echo $row['equipment_id']; ?></td>
                <td><b><?php echo htmlspecialchars($row['resource_name']); ?></b></td>
                <td><?php echo htmlspecialchars($row['categories']); ?></td>
                <td class="status in-use">IN-USE</td>
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

    <br><br>

    <div class="table-wrap intro-card">
        <h2>Return Workflow Guide</h2>
        <p class="intro-text">
            Use this page to process equipment returns. When an item is marked as Returned, its status
            is reset to <b>Available</b> and the action is recorded in the transaction log for full audit traceability.
        </p>
        <div class="intro-grid">
            <div class="intro-item success">
                <h3>Mark as Returned</h3>
                <p>Click <b>Mark as Returned</b> next to any In-Use item. Add an optional note before confirming.</p>
            </div>
            <div class="intro-item info">
                <h3>Instant Availability</h3>
                <p>Once returned, the equipment status immediately reverts to <b>Available</b> for new reservations.</p>
            </div>
            <div class="intro-item warning">
                <h3>Audit Trail</h3>
                <p>Every return is logged in Transaction Logs with the admin name, date, and remarks.</p>
            </div>
            <div class="intro-item">
                <h3>Reservation Info</h3>
                <p>The table shows who borrowed the item and when, for quick accountability reference.</p>
            </div>
        </div>
    </div>

</div>


<!-- ── Return Confirm Modal ── -->
<div class="modal-overlay" id="returnModal">
    <div class="modal-box">
        <h3>
            <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="#16a34a">
                <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
            </svg>
            Confirm Return
        </h3>
        <p style="font-size:0.875rem; color:#555; margin:4px 0 0;">
            Mark this equipment as returned and set it back to <b>Available</b>:
        </p>
        <div class="equipment-pill" id="returnEquipmentName">—</div>

        <label for="returnRemarks">Return Notes <span style="font-weight:400; color:#999;">(optional)</span></label>
        <textarea id="returnRemarks" placeholder="e.g. Returned in good condition, minor scratch on HDMI port…"></textarea>

        <p id="returnModalMsg" style="color:red;"></p>

        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeReturnModal()">Cancel</button>
            <button type="button" class="btn-confirm-return" id="confirmReturnBtn" onclick="submitReturn()">
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

// Close on overlay click
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

    fetch('return_equipment.php', {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            closeReturnModal();

            // Remove the row from the table immediately
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
        // Replace table with empty state
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
        // Update badge
        const badge = document.querySelector('.inuse-badge');
        if (badge) badge.remove();
    } else {
        // Update badge count
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
