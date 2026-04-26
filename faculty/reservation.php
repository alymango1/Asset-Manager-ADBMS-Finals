<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// ── Name for greeting dropdown ──────────────────────────────────
$name = 'User';
if (isset($_SESSION['full_name'])) {
    $nameParts = explode(' ', trim($_SESSION['full_name']));
    $name = $nameParts[0];
} elseif (isset($_SESSION['username'])) {
    $name = $_SESSION['username'];
}

$user_id = $_SESSION['user_id'];

// ── Handle reservation AJAX POST ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_res'])) {
    header('Content-Type: application/json');
    $equipment_id = (int)$_POST['equipment_id'];
    $res_date     = mysqli_real_escape_string($conn, $_POST['res_date']);

    // Add this after line 25 (after $res_date is set):
    $today = date('Y-m-d');
    if ($res_date < $today) {
    echo json_encode(['success' => false, 'message' => 'Reservation date cannot be in the past.']);
    exit();
}

    // 1. Prevent the same user from having an active reservation for this equipment on this date
    $check_self = mysqli_query($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = $equipment_id AND requested_by = $user_id
        AND status IN ('pending','approved')
        AND reserved_date = '$res_date'");

    if (mysqli_num_rows($check_self) > 0) {
        echo json_encode(['success' => false, 'message' => 'You already have a pending or approved reservation for this item on that date.']);
        exit();
    }

    // 2. Prevent booking equipment that is already approved (confirmed in-use) for that date by someone else
    $check_conflict = mysqli_query($conn, "SELECT reservation_id FROM reservations
        WHERE equipment_id = $equipment_id
        AND status = 'approved'
        AND reserved_date = '$res_date'");

    if (mysqli_num_rows($check_conflict) > 0) {
        echo json_encode(['success' => false, 'message' => 'This equipment is already reserved by someone else on that date. Please choose a different date or item.']);
        exit();
    }

    $insert = mysqli_query($conn, "
        INSERT INTO reservations (equipment_id, requested_by, status, reserved_date, created_at)
        VALUES ($equipment_id, $user_id, 'pending', '$res_date', NOW())");

    if ($insert) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Database error: ' . mysqli_error($conn)]);
    }
    exit();
}

// ── Search & Filter params ──────────────────────────────────────
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';

$where = "WHERE status = 'Available'";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";

// ── Pagination ──────────────────────────────────────────────────
$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$totalQuery   = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments $where");
$totalRow     = mysqli_fetch_assoc($totalQuery);
$totalRecords = (int)$totalRow['total'];
$totalPages   = $totalRecords > 0 ? ceil($totalRecords / $limit) : 1;

if ($page < 1) $page = 1;
if ($page > $totalPages) $page = $totalPages;

$equipments_query = mysqli_query($conn, "SELECT * FROM equipments $where LIMIT $limit OFFSET $offset");

$queryParams  = [];
if ($search   !== '') $queryParams[] = 'search='   . urlencode($search);
if ($category !== '') $queryParams[] = 'category=' . urlencode($category);
$filterString = count($queryParams) ? '&' . implode('&', $queryParams) : '';
?>
<!DOCTYPE html>
<html>
<head>
    <title>Create Reservation</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style_faculty.css">
</head>
<body>

<?php include('sidebar.php'); ?>

<div class="header">
    <h1>Create Reservation</h1>
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
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");
btn.addEventListener("click", function(e) { e.stopPropagation(); menu.classList.toggle("active"); });
document.addEventListener("click", function() { menu.classList.remove("active"); });
</script>

<div class="main">
    <div class="table-wrap">
        <h2>Available Equipment</h2>

        <!-- Search & Filter Bar -->
        <div class="search-filter-bar-wrap">
            <form method="GET" action="reservation.php" class="search-filter-bar">
                <div class="search-input-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#999"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    <input type="text" name="search" placeholder="Search equipment..." value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                </div>
                <select name="category">
                    <option value="">All Categories</option>
                    <option value="IT Equipment"     <?php if($category==='IT Equipment')     echo 'selected';?>>IT Equipment</option>
                    <option value="Classroom"        <?php if($category==='Classroom')        echo 'selected';?>>Classroom</option>
                    <option value="Events Equipment" <?php if($category==='Events Equipment') echo 'selected';?>>Events Equipment</option>
                </select>
                <button type="submit" class="btn-search">Search</button>
                <?php if ($search!==''||$category!==''): ?>
                    <a href="reservation.php" class="btn-clear-filter">&#x2715; Clear</a>
                <?php endif; ?>
            </form>
            <p class="result-count">
                <?php if ($search!==''||$category!==''): ?>
                    Showing <strong><?php echo $totalRecords;?></strong> result<?php echo $totalRecords!=1?'s':'';?>
                    <?php if($search!==''): ?> for <strong>"<?php echo htmlspecialchars($search);?>"</strong><?php endif;?>
                <?php else: ?>
                    <strong><?php echo $totalRecords;?></strong> available equipment<?php echo $totalRecords!=1?'s':'';?> found
                <?php endif;?>
            </p>
        </div>

        <table class="transaction_table equipment" width="100%" cellpadding="10" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Resource Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
            <?php if (mysqli_num_rows($equipments_query) === 0): ?>
            <tr>
                <td colspan="5" style="text-align:center;padding:40px;color:#999;">
                    <svg xmlns="http://www.w3.org/2000/svg" height="40px" viewBox="0 -960 960 960" width="40px" fill="#ccc" style="display:block;margin:0 auto 10px"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    No available equipment found.
                </td>
            </tr>
            <?php else: ?>
            <?php while($row = mysqli_fetch_assoc($equipments_query)): ?>
            <tr>
                <td><?php echo $row['equipment_id'];?></td>
                <td><?php echo htmlspecialchars($row['resource_name']);?></td>
                <td>
                    <span class="category-badge category-<?php echo strtolower(str_replace(' ','-',$row['categories']));?>">
                        <?php echo htmlspecialchars($row['categories']);?>
                    </span>
                </td>
                <td class="status <?php echo strtolower(str_replace(' ','-',$row['status']));?>">
                    <?php echo strtoupper($row['status']);?>
                </td>
                <td class="actions">
                    <button class="btn-reserve"
                        onclick="openReserveModal(<?php echo $row['equipment_id'];?>, '<?php echo htmlspecialchars(addslashes($row['resource_name']));?>', '<?php echo htmlspecialchars(addslashes($row['categories']));?>')">
                        <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
                        Reserve
                    </button>
                </td>
            </tr>
            <?php endwhile;?>
            <?php endif;?>
        </table>

        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page-1;?><?php echo $filterString;?>">&laquo; Prev</a>
            <?php endif;?>
            <?php for ($i=1;$i<=$totalPages;$i++): ?>
                <a href="?page=<?php echo $i;?><?php echo $filterString;?>" class="<?php echo($i==$page)?'active':'';?>"><?php echo $i;?></a>
            <?php endfor;?>
            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page+1;?><?php echo $filterString;?>">Next &raquo;</a>
            <?php endif;?>
        </div>
        <?php endif;?>
    </div>

    <br>

    <div class="table-wrap intro-card reservation-guide">
        <h2>How Reservations Work</h2>
        <p class="intro-text">Click <strong>Reserve</strong> on any available item, pick your date, and submit. An admin will review and approve or reject your request.</p>
        <div class="intro-grid">
            <div class="intro-item info">
                <h3>📋 Browse Available Items</h3>
                <p>Only equipment marked <em>Available</em> appears here.</p>
            </div>
            <div class="intro-item success">
                <h3>📅 Pick Your Date</h3>
                <p>Choose the date you need the equipment. Past dates are blocked.</p>
            </div>
            <div class="intro-item warning">
                <h3>⏳ Await Approval</h3>
                <p>Your request starts as <em>Pending</em> until an admin reviews it.</p>
            </div>
            <div class="intro-item danger">
                <h3>📌 Track Status</h3>
                <p>Check <em>My Reservations</em> to see approved or rejected requests.</p>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     RESERVE MODAL
════════════════════════════════════════════ -->
<div class="modal-overlay" id="reserveModal">
    <div class="modal-box">

        <!-- Header -->
        <div class="modal-header">
            <div class="modal-header-icon">
                <svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="currentColor"><path d="M580-240q-42 0-71-29t-29-71q0-42 29-71t71-29q42 0 71 29t29 71q0 42-29 71t-71 29ZM200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Z"/></svg>
            </div>
            <div>
                <h3 id="modalTitle">Reserve Item</h3>
                <p id="modalSubtitle" class="modal-subtitle">Submit a reservation request</p>
            </div>
            <button class="modal-close-btn" onclick="closeReserveModal()" title="Close">
                <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <!-- Equipment Info Row -->
        <div class="modal-info-row">
            <div class="modal-info-group">
                <label>Equipment</label>
                <p id="modalEquipmentName">—</p>
            </div>
            <div class="modal-info-group">
                <label>Category</label>
                <p id="modalCategory">—</p>
            </div>
            <div class="modal-info-group">
                <label>Status</label>
                <p class="modal-status-badge">AVAILABLE</p>
            </div>
        </div>

        <!-- Date Picker -->
        <div class="modal-date-group">
            <label for="reserveDate">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#C40C0C"><path d="M200-80q-33 0-56.5-23.5T120-160v-560q0-33 23.5-56.5T200-800h40v-80h80v80h320v-80h80v80h40q33 0 56.5 23.5T840-720v560q0 33-23.5 56.5T760-80H200Zm0-80h560v-400H200v400Zm0-480h560v-80H200v80Zm0 0v-80 80Zm280 240q-17 0-28.5-11.5T440-440q0-17 11.5-28.5T480-480q17 0 28.5 11.5T520-440q0 17-11.5 28.5T480-400Zm-160 0q-17 0-28.5-11.5T280-440q0-17 11.5-28.5T320-480q17 0 28.5 11.5T360-440q0 17-11.5 28.5T320-400Zm320 0q-17 0-28.5-11.5T600-440q0-17 11.5-28.5T640-480q17 0 28.5 11.5T680-440q0 17-11.5 28.5T640-400ZM480-240q-17 0-28.5-11.5T440-280q0-17 11.5-28.5T480-320q17 0 28.5 11.5T520-280q0 17-11.5 28.5T480-240Zm-160 0q-17 0-28.5-11.5T280-280q0-17 11.5-28.5T320-320q17 0 28.5 11.5T360-280q0 17-11.5 28.5T320-240Zm320 0q-17 0-28.5-11.5T600-280q0-17 11.5-28.5T640-320q17 0 28.5 11.5T680-280q0 17-11.5 28.5T640-240Z"/></svg>
                Date to be Used
            </label>
            <input type="date" id="reserveDate" min="<?php echo date('Y-m-d');?>" required>
            <p class="date-hint">Select the date you need this equipment. Requests are subject to admin approval.</p>
        </div>

        <p id="modalMsg" class="modal-msg"></p>

        <!-- Actions -->
        <div class="modal-actions">
            <button type="button" class="modal-btn-cancel" onclick="closeReserveModal()">Cancel</button>
            <button type="button" class="modal-btn-submit" id="submitReserveBtn" onclick="submitReservation()">
                <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg>
                Submit Request
            </button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════
     SUCCESS TOAST
════════════════════════════════════════════ -->
<div class="toast" id="successToast">
    <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="currentColor"><path d="m424-296 282-282-56-56-226 226-114-114-56 56 170 170Zm56 216q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Zm0-80q134 0 227-93t93-227q0-134-93-227t-227-93q-134 0-227 93t-93 227q0 134 93 227t227 93Zm0-320Z"/></svg>
    Reservation submitted! Awaiting admin approval.
</div>

<style>
/* ── Search & Filter Bar ── */
.search-filter-bar-wrap {
    margin-bottom: 18px;
    padding: 14px 16px;
    background: #fafafa;
    border: 1px solid #ebebeb;
    border-radius: 10px;
}
.search-filter-bar { display:flex; align-items:center; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.result-count { font-size:0.85rem; color:#777; margin:0; }
.search-input-wrap { position:relative; display:flex; align-items:center; }
.search-input-wrap svg { position:absolute; left:10px; pointer-events:none; }
.search-input-wrap input {
    padding: 8px 12px 8px 34px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 0.875rem;
    width: 200px;
    font-family: inherit;
    transition: border-color 0.15s;
}
.search-input-wrap input:focus { outline:none; border-color:#C40C0C; }
.search-filter-bar select {
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 0.875rem;
    font-family: inherit;
    background: #fff;
    cursor: pointer;
    transition: border-color 0.15s;
}
.search-filter-bar select:focus { outline:none; border-color:#C40C0C; }
.btn-search {
    padding: 8px 18px;
    background: #C40C0C;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
}
.btn-search:hover { background: #8e0000; }
.btn-clear-filter {
    padding: 8px 14px;
    background: #f0f0f0;
    color: #555;
    border-radius: 8px;
    font-size: 0.875rem;
    text-decoration: none;
    transition: background 0.15s;
}
.btn-clear-filter:hover { background: #e0e0e0; color:#222; }

/* ── Category badge ── */
.category-badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    letter-spacing: 0.02em;
}
.category-it-equipment      { background:#e3f0ff; color:#1565c0; }
.category-classroom          { background:#fff8e1; color:#7b5800; }
.category-events-equipment   { background:#fce4ec; color:#880e4f; }

/* ── Reserve button ── */
.btn-reserve {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: #C40C0C;
    color: white;
    padding: 7px 14px;
    border-radius: 8px;
    border: none;
    font-size: 0.85em;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.2s, transform 0.15s;
}
.btn-reserve:hover { background: #8e0000; transform: translateY(-1px); }

/* ═══════════════════════════════
   RESERVATION MODAL
═══════════════════════════════ */
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
    width: 500px;
    max-width: 94vw;
    box-shadow: 0 24px 70px rgba(0,0,0,0.28);
    animation: modalIn 0.22s cubic-bezier(.34,1.56,.64,1);
    overflow: hidden;
}
@keyframes modalIn {
    from { transform: scale(0.88) translateY(20px); opacity:0; }
    to   { transform: scale(1)    translateY(0);    opacity:1; }
}

/* Modal header bar */
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
    background: #C40C0C;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.modal-header h3 { margin:0; font-size:1.1rem; color:#2c0b0b; }
.modal-subtitle  { margin:2px 0 0; font-size:0.82rem; color:#999; }
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

/* Info row */
.modal-info-row {
    display: flex;
    gap: 12px;
    padding: 18px 22px 0;
}
.modal-info-group { flex: 1; }
.modal-info-group label {
    display: block;
    font-size: 0.72rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #aaa;
    margin-bottom: 5px;
}
.modal-info-group p {
    margin: 0;
    font-size: 0.92rem;
    color: #222;
    font-weight: 600;
    background: #f7f7f7;
    padding: 9px 12px;
    border-radius: 8px;
    border: 1px solid #eeeeee;
}
.modal-status-badge {
    background: #e8f5e9 !important;
    color: #2e7d32 !important;
    border: 1px solid #a5d6a7 !important;
}

/* Date group */
.modal-date-group {
    padding: 18px 22px 0;
}
.modal-date-group > label {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 0.82rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #aaa;
    margin-bottom: 8px;
}
.modal-date-group input[type="date"] {
    width: 100%;
    padding: 11px 14px;
    border: 1.5px solid #ddd;
    border-radius: 10px;
    font-size: 1rem;
    font-family: inherit;
    color: #222;
    background: #fff;
    box-sizing: border-box;
    transition: border-color 0.15s, box-shadow 0.15s;
    cursor: pointer;
}
.modal-date-group input[type="date"]:focus {
    outline: none;
    border-color: #C40C0C;
    box-shadow: 0 0 0 3px rgba(196,12,12,0.12);
}
.date-hint {
    margin: 7px 0 0;
    font-size: 0.78rem;
    color: #aaa;
}

/* Modal message */
.modal-msg {
    min-height: 1.2em;
    font-size: 0.83rem;
    color: #C40C0C;
    padding: 6px 22px 0;
    margin: 0;
}

/* Actions */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    padding: 16px 22px 22px;
    margin-top: 6px;
}
.modal-btn-cancel {
    padding: 10px 20px;
    border: 1.5px solid #ddd;
    border-radius: 10px;
    background: #fff;
    cursor: pointer;
    font-size: 0.9rem;
    font-family: inherit;
    color: #555;
    transition: background 0.15s, border-color 0.15s;
}
.modal-btn-cancel:hover { background: #f5f5f5; border-color: #ccc; }
.modal-btn-submit {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 10px 22px;
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
.modal-btn-submit:hover    { background: #8e0000; transform: translateY(-1px); }
.modal-btn-submit:disabled { opacity: 0.6; cursor: not-allowed; transform: none; }

/* ── Toast notification ── */
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
.toast.show { opacity:1; transform:translateY(0); }
</style>

<script>
let _reserveId = null;

function openReserveModal(id, name, category) {
    _reserveId = id;
    document.getElementById('modalEquipmentName').textContent = name;
    document.getElementById('modalCategory').textContent = category;
    document.getElementById('modalTitle').textContent = 'Reserve: ' + name;
    document.getElementById('reserveDate').value = '';
    document.getElementById('modalMsg').textContent = '';
    const btn = document.getElementById('submitReserveBtn');
    btn.disabled = false;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
    document.getElementById('reserveModal').classList.add('active');
}

function closeReserveModal() {
    document.getElementById('reserveModal').classList.remove('active');
    _reserveId = null;
}

// Close on backdrop click
document.getElementById('reserveModal').addEventListener('click', function(e) {
    if (e.target === this) closeReserveModal();
});

function submitReservation() {
    const dateVal = document.getElementById('reserveDate').value;
    const msgEl   = document.getElementById('modalMsg');

    if (!dateVal) {
        msgEl.textContent = 'Please select a date.';
        document.getElementById('reserveDate').focus();
        return;
    }

    const btn = document.getElementById('submitReserveBtn');
    btn.disabled = true;
    btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor" style="animation:spin 0.8s linear infinite"><path d="M480-80q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q17 0 28.5 11.5T520-840q0 17-11.5 28.5T480-800q-133 0-226.5 93.5T160-480q0 133 93.5 226.5T480-160q133 0 226.5-93.5T800-480q0-17 11.5-28.5T840-520q17 0 28.5 11.5T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg> Submitting…';
    msgEl.textContent = '';

    const form = new FormData();
    form.append('equipment_id', _reserveId);
    form.append('res_date', dateVal);
    form.append('submit_res', '1');

    fetch('reservation.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeReserveModal();
                showToast();
            } else {
                msgEl.textContent = data.message || 'Failed to submit reservation.';
                btn.disabled = false;
                btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
            }
        })
        .catch(() => {
            msgEl.textContent = 'An error occurred. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M382-240 154-468l57-57 171 171 367-367 57 57-424 424Z"/></svg> Submit Request';
        });
}

function showToast() {
    const t = document.getElementById('successToast');
    t.classList.add('show');
    setTimeout(() => t.classList.remove('show'), 3500);
}

// Spin keyframe for loading icon
const style = document.createElement('style');
style.textContent = '@keyframes spin { to { transform: rotate(360deg); } }';
document.head.appendChild(style);
</script>

</body>
</html>