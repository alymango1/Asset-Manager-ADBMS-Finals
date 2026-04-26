<?php
include('../database/db.php');

session_start();

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

// ── Search & Filter params ──────────────────────────────────────
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';
$status   = isset($_GET['status'])   ? trim(mysqli_real_escape_string($conn, $_GET['status']))   : '';

// Build WHERE clause
$where = "WHERE 1=1";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";
if ($status   !== '') $where .= " AND status = '$status'";

// ── Pagination ──────────────────────────────────────────────────
$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$totalQuery   = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments $where");
$totalRow     = mysqli_fetch_assoc($totalQuery);
$totalRecords = $totalRow['total'];
$totalPages   = ceil($totalRecords / $limit);

if ($page < 1) $page = 1;
if ($totalPages > 0 && $page > $totalPages) $page = $totalPages;

$equipmentsQuery = mysqli_query($conn, "
    SELECT * FROM equipments
    $where
    ORDER BY equipment_id ASC
    LIMIT $limit OFFSET $offset
");

// Build query string that pagination links carry forward
$queryParams = [];
if ($search   !== '') $queryParams[] = 'search='   . urlencode($search);
if ($category !== '') $queryParams[] = 'category=' . urlencode($category);
if ($status   !== '') $queryParams[] = 'status='   . urlencode($status);
$filterString = count($queryParams) ? '&' . implode('&', $queryParams) : '';
?>

<!DOCTYPE html>
<html>
<head>
    <title>Equipments</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
    <h1>Equipments</h1>
    <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>
        </button>
    </div>
    <!-- DROPDOWN -->
    <div class="dropdown" id="dropdownMenu">
        <p>Greetings, <?php echo $name ?>!</p>
        <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#000000"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
    </div>
</div>
</div>

<script>
const btn = document.getElementById("profileBtn");
const menu = document.getElementById("dropdownMenu");
btn.addEventListener("click", function(e) {
    e.stopPropagation();
    menu.classList.toggle("active");
});
document.addEventListener("click", function() {
    menu.classList.remove("active");
});
</script>

<a href="add_equipment.php" class="fab" title="Add Equipment">
    <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="#fff">
        <path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/>
    </svg>
</a>

<div class="main">

    <div class="table-wrap">

        <h2>Equipment List</h2>

        <?php if (isset($_SESSION['success'])): ?>
            <div class="message-box success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        <?php if (isset($_SESSION['error'])): ?>
            <div class="message-box error"><?php echo htmlspecialchars($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>

        <!-- ── Search & Filter Bar (always below the title) ── -->
        <div class="search-filter-bar-wrap">
            <form method="GET" action="equipments.php" class="search-filter-bar">
                <div class="search-input-wrap">
                    <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="#999"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                    <input
                        type="text"
                        name="search"
                        placeholder="Search equipment..."
                        value="<?php echo htmlspecialchars($search); ?>"
                        autocomplete="off"
                    >
                </div>

                <select name="category">
                    <option value="">All Categories</option>
                    <option value="IT Equipment"     <?php if($category === 'IT Equipment')     echo 'selected'; ?>>IT Equipment</option>
                    <option value="Classroom"        <?php if($category === 'Classroom')        echo 'selected'; ?>>Classroom</option>
                    <option value="Events Equipment" <?php if($category === 'Events Equipment') echo 'selected'; ?>>Events Equipment</option>
                </select>

                <select name="status">
                    <option value="">All Statuses</option>
                    <option value="Available"         <?php if($status === 'Available')         echo 'selected'; ?>>Available</option>
                    <option value="In-Use"            <?php if($status === 'In-Use')            echo 'selected'; ?>>In-Use</option>
                    <option value="Under Maintenance" <?php if($status === 'Under Maintenance') echo 'selected'; ?>>Under Maintenance</option>
                </select>

                <button type="submit" class="btn-search">Search</button>

                <?php if ($search !== '' || $category !== '' || $status !== ''): ?>
                    <a href="equipments.php" class="btn-clear-filter">&#x2715; Clear</a>
                <?php endif; ?>
            </form>

            <p class="result-count">
                <?php if ($search !== '' || $category !== '' || $status !== ''): ?>
                    Showing <strong><?php echo $totalRecords; ?></strong> result<?php echo $totalRecords != 1 ? 's' : ''; ?>
                    <?php if ($search !== ''): ?> for <strong>"<?php echo htmlspecialchars($search); ?>"</strong><?php endif; ?>
                <?php else: ?>
                    Showing <strong><?php echo $totalRecords; ?></strong> equipment<?php echo $totalRecords != 1 ? 's' : ''; ?> total
                <?php endif; ?>
            </p>
        </div>

        <table class="transaction_table equipment" width="100%" cellpadding="10" cellspacing="0">
            <tr>
                <th>ID</th>
                <th>Resource Name</th>
                <th>Category</th>
                <th>Status</th>
                <th>Actions</th>
            </tr>

            <?php if (mysqli_num_rows($equipmentsQuery) === 0): ?>
            <tr>
                <td colspan="5" style="text-align:center; padding:32px; color:#888;">
                    No equipment found matching your search.
                </td>
            </tr>
            <?php else: ?>
            <?php while($row = mysqli_fetch_assoc($equipmentsQuery)): ?>
            <tr>
                <td><?php echo $row['equipment_id']; ?></td>
                <td><?php echo htmlspecialchars($row['resource_name']); ?></td>
                <td><?php echo htmlspecialchars($row['categories']); ?></td>
                <td class="status <?php echo strtolower(str_replace(' ', '-', $row['status'])); ?>">
                    <?php echo strtoupper($row['status']); ?>
                </td>
                <td class="actions">
                    <div class="action-menu-wrap">
                        <button class="action-kebab" onclick="toggleMenu(this)" title="Actions">
                            <span></span><span></span><span></span>
                        </button>
                        <div class="action-dropdown">
                            <a class="action-item" href="edit_equipment.php?id=<?php echo $row['equipment_id']; ?>">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M200-200h57l391-391-57-57-391 391v57Zm-80 80v-170l528-527q12-11 26.5-17t30.5-6q16 0 31 6t26 18l55 56q12 11 17.5 26t5.5 30q0 16-5.5 30.5T817-647L290-120H120Zm640-584-56-56 56 56Zm-141 85-28-29 57 57-29-28Z"/></svg>
                                Edit Details
                            </a>
                            <a class="action-item" href="#" onclick="event.preventDefault(); closeAllMenus(); openEditModal(<?php echo $row['equipment_id']; ?>,'<?php echo addslashes($row['resource_name']); ?>','<?php echo addslashes($row['categories']); ?>','<?php echo htmlspecialchars($row['status'], ENT_QUOTES); ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M160-160v-80l80-80v160h-80Zm160 0v-240l80-80v320h-80Zm160 0v-320l80 81v239h-80Zm160 0v-239l80-80v319h-80Zm160 0v-400l80-80v480h-80ZM160-440l280-280 160 160 200-200 80 80-280 280-160-160-280 280-80 80Z"/></svg>
                                Edit Status
                            </a>
                            <?php if ($row['status'] === 'In-Use'): ?>
                            <a class="action-item action-return" href="#" onclick="event.preventDefault(); closeAllMenus(); openReturnModal(<?php echo $row['equipment_id']; ?>,'<?php echo addslashes(htmlspecialchars($row['resource_name'])); ?>')">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
                                Mark as Returned
                            </a>
                            <?php endif; ?>
                            <div class="action-divider"></div>
                            <a class="action-item action-delete" href="delete_equipment.php?id=<?php echo $row['equipment_id']; ?>" onclick="return confirm('Delete <?php echo addslashes(htmlspecialchars($row['resource_name'])); ?>? This cannot be undone.');">
                                <svg xmlns="http://www.w3.org/2000/svg" height="16px" viewBox="0 -960 960 960" width="16px" fill="currentColor"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Zm400-600H280v520h400v-520ZM360-280h80v-360h-80v360Zm160 0h80v-360h-80v360ZM280-720v520-520Z"/></svg>
                                Delete
                            </a>
                        </div>
                    </div>
                </td>
            </tr>
            <?php endwhile; ?>
            <?php endif; ?>
        </table>

        <!-- Pagination — carries filter params forward -->
        <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?page=<?php echo $page - 1; ?><?php echo $filterString; ?>">&laquo; Prev</a>
            <?php endif; ?>

            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?page=<?php echo $i; ?><?php echo $filterString; ?>"
                   class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                    <?php echo $i; ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="?page=<?php echo $page + 1; ?><?php echo $filterString; ?>">Next &raquo;</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>

    <br><br>

    <div class="table-wrap intro-card">
        <h2>Equipment Management Guide</h2>
        <p class="intro-text">
            This system allows administrators to manage and monitor all equipment records in real time.
            You can track availability, handle reservations, and maintain proper inventory control across the organization.
        </p>
        <div class="intro-grid">
            <div class="intro-item">
                <h3>Inventory Tracking</h3>
                <p>View all registered equipment including status and category.</p>
            </div>
            <div class="intro-item">
                <h3>Inventory Expansion</h3>
                <p>Register new equipment to the database.</p>
            </div>
            <div class="intro-item">
                <h3>Maintenance Control</h3>
                <p>Flag items under maintenance to prevent scheduling conflicts.</p>
            </div>
            <div class="intro-item">
                <h3>Role-Based Access</h3>
                <p>Ensure only authorized users can modify equipment data.</p>
            </div>
        </div>
    </div>

</div>


<!-- ── Edit Status Modal ── -->
<div class="modal-overlay" id="editStatusModal">
    <div class="modal-box">
        <h3>Edit Equipment Status</h3>
        <div class="modal-info-row">
            <div class="modal-info-group">
                <label>Equipment</label>
                <p id="modalEquipmentName">—</p>
            </div>
            <div class="modal-info-group">
                <label>Category</label>
                <p id="modalCategory">—</p>
            </div>
        </div>
        <div class="modal-status-group">
            <label>Status</label>
            <div class="status-options" id="statusOptions">
                <button type="button" class="status-chip available"   data-value="Available">Available</button>
                <button type="button" class="status-chip in-use"      data-value="In-Use">In-Use</button>
                <button type="button" class="status-chip maintenance" data-value="Under Maintenance">Under Maintenance</button>
            </div>
        </div>
        <p id="editModalMsg" style="color:red; font-size:0.85rem; min-height:1.2em;"></p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeEditModal()">Cancel</button>
            <button type="button" class="btn-confirm-edit" onclick="submitEditStatus()">Update Status</button>
        </div>
    </div>
</div>


<style>
/* ── Search & Filter Bar ── */

/* Title on its own line */
.table-wrap h2 {
    margin: 0 0 14px 0;
}

/* Wrapper always stays below the title — never shifts */
.search-filter-bar-wrap {
    margin-bottom: 16px;
    padding: 14px 16px;
    background: #fafafa;
    border: 1px solid #ebebeb;
    border-radius: 10px;
}

.search-filter-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
    margin-bottom: 10px;
}

.result-count {
    font-size: 0.85rem;
    color: #777;
    margin: 0;
}

.search-input-wrap {
    position: relative;
    display: flex;
    align-items: center;
}
.search-input-wrap svg {
    position: absolute;
    left: 10px;
    pointer-events: none;
}
.search-input-wrap input {
    padding: 8px 12px 8px 34px;
    border: 1px solid #ddd;
    border-radius: 8px;
    font-size: 0.875rem;
    width: 200px;
    font-family: inherit;
    transition: border-color 0.15s;
}
.search-input-wrap input:focus {
    outline: none;
    border-color: #4a5568;
}

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
.search-filter-bar select:focus {
    outline: none;
    border-color: #4a5568;
}

.btn-search {
    padding: 8px 18px;
    background: #1a1a2e;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 600;
    cursor: pointer;
    font-family: inherit;
    transition: background 0.15s;
}
.btn-search:hover { background: #2d2d50; }

.btn-clear-filter {
    padding: 8px 14px;
    background: #f0f0f0;
    color: #555;
    border-radius: 8px;
    font-size: 0.875rem;
    text-decoration: none;
    transition: background 0.15s;
}
.btn-clear-filter:hover { background: #e0e0e0; color: #222; }

/* ── Edit Status Modal ── */
.modal-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.55);
    backdrop-filter: blur(3px);
    z-index: 9999;
    align-items: center;
    justify-content: center;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff;
    border-radius: 12px;
    padding: 28px 28px 22px;
    width: 460px;
    max-width: 92vw;
    box-shadow: 0 20px 60px rgba(0,0,0,0.25);
    animation: modalIn 0.2s ease;
}
@keyframes modalIn {
    from { transform: scale(0.92); opacity: 0; }
    to   { transform: scale(1);    opacity: 1; }
}
.modal-box h3 { margin: 0 0 18px; font-size: 1.15rem; color: #1a1a2e; }
.modal-info-row { display: flex; gap: 20px; margin-bottom: 20px; }
.modal-info-group { flex: 1; }
.modal-info-group label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #888;
    margin-bottom: 4px;
}
.modal-info-group p {
    margin: 0;
    font-size: 0.95rem;
    color: #222;
    font-weight: 500;
    background: #f5f5f5;
    padding: 8px 12px;
    border-radius: 6px;
}
.modal-status-group { margin-bottom: 6px; }
.modal-status-group > label {
    display: block;
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: #888;
    margin-bottom: 10px;
}
.status-options { display: flex; gap: 10px; flex-wrap: wrap; }
.status-chip {
    padding: 8px 18px;
    border-radius: 20px;
    border: 2px solid transparent;
    font-size: 0.85rem;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.15s;
    background: #f0f0f0;
    color: #555;
}
.status-chip.available.selected   { background: #d4edda; border-color: #28a745; color: #155724; }
.status-chip.in-use.selected      { background: #fff3cd; border-color: #ffc107; color: #856404; }
.status-chip.maintenance.selected { background: #f8d7da; border-color: #dc3545; color: #721c24; }
.status-chip.available:hover   { background: #e9f7ec; }
.status-chip.in-use:hover      { background: #fffbea; }
.status-chip.maintenance:hover { background: #fdf0f1; }
.modal-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
.btn-cancel {
    padding: 8px 18px;
    border: 1px solid #ccc;
    border-radius: 6px;
    background: #fff;
    cursor: pointer;
    font-size: 0.9rem;
}
.btn-cancel:hover { background: #f5f5f5; }
.btn-confirm-edit {
    padding: 8px 20px;
    border: none;
    border-radius: 6px;
    background: #1a1a2e;
    color: #fff;
    cursor: pointer;
    font-size: 0.9rem;
    font-weight: 600;
}
.btn-confirm-edit:hover    { background: #2d2d50; }
.btn-confirm-edit:disabled { opacity: 0.6; cursor: not-allowed; }
</style>

<script>
let _editId = null;
let _selectedStatus = null;

function openEditModal(id, name, category, currentStatus) {
    _editId = id;
    _selectedStatus = currentStatus;
    document.getElementById('modalEquipmentName').textContent = name;
    document.getElementById('modalCategory').textContent = category;
    document.getElementById('editModalMsg').textContent = '';
    document.querySelectorAll('.status-chip').forEach(chip => {
        chip.classList.toggle('selected', chip.dataset.value === currentStatus);
    });
    document.getElementById('editStatusModal').classList.add('active');
}
function closeEditModal() {
    document.getElementById('editStatusModal').classList.remove('active');
}
document.querySelectorAll('.status-chip').forEach(chip => {
    chip.addEventListener('click', function() {
        document.querySelectorAll('.status-chip').forEach(c => c.classList.remove('selected'));
        this.classList.add('selected');
        _selectedStatus = this.dataset.value;
    });
});
document.getElementById('editStatusModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditModal();
});
function submitEditStatus() {
    if (!_selectedStatus) {
        document.getElementById('editModalMsg').textContent = 'Please select a status.';
        return;
    }
    const btn = document.querySelector('.btn-confirm-edit');
    btn.disabled = true;
    btn.textContent = 'Updating…';
    const form = new FormData();
    form.append('id', _editId);
    form.append('status', _selectedStatus);
    form.append('update', '1');
    fetch('update_equipment_status.php', { method: 'POST', body: form })
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                closeEditModal();
                location.reload();
            } else {
                document.getElementById('editModalMsg').textContent = data.message || 'Update failed.';
                btn.disabled = false;
                btn.textContent = 'Update Status';
            }
        })
        .catch(() => {
            document.getElementById('editModalMsg').textContent = 'An error occurred.';
            btn.disabled = false;
            btn.textContent = 'Update Status';
        });
}
</script>


<!-- ── Quick Return Modal (from Equipments page) ── -->
<div class="modal-overlay" id="quickReturnModal">
    <div class="modal-box">
        <h3 style="color:#1a1a2e; display:flex; align-items:center; gap:8px;">
            <svg xmlns="http://www.w3.org/2000/svg" height="22px" viewBox="0 -960 960 960" width="22px" fill="#16a34a">
                <path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/>
            </svg>
            Confirm Return
        </h3>
        <p style="font-size:0.875rem; color:#555; margin: 4px 0 12px;">
            Mark this equipment as returned and set it back to <b>Available</b>:
        </p>
        <div id="qrEquipmentPill" style="display:inline-block; background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; border-radius:8px; padding:6px 14px; font-size:0.9rem; font-weight:600; margin-bottom:16px;">—</div>
        <div>
            <label style="display:block; font-size:0.82rem; font-weight:600; color:#555; margin-bottom:5px;">
                Return Notes <span style="font-weight:400; color:#999;">(optional)</span>
            </label>
            <textarea id="qrRemarks"
                placeholder="e.g. Returned in good condition…"
                style="width:100%; border:1px solid #ddd; border-radius:8px; padding:9px 12px; font-size:0.875rem; font-family:inherit; resize:vertical; min-height:75px; box-sizing:border-box;"></textarea>
        </div>
        <p id="qrModalMsg" style="color:red; font-size:0.82rem; min-height:1.1em; margin-top:6px;"></p>
        <div class="modal-actions">
            <button type="button" class="btn-cancel" onclick="closeQuickReturnModal()">Cancel</button>
            <button type="button" id="qrConfirmBtn"
                style="padding:8px 22px; background:#16a34a; color:#fff; border:none; border-radius:8px; font-size:0.875rem; font-weight:600; cursor:pointer; font-family:inherit;"
                onclick="submitQuickReturn()">
                Confirm Return
            </button>
        </div>
    </div>
</div>

<!-- Toast -->
<div id="equipToast" style="position:fixed; bottom:28px; right:28px; background:#1a1a2e; color:#fff; padding:14px 22px; border-radius:10px; font-size:0.88rem; font-weight:500; box-shadow:0 4px 20px rgba(0,0,0,0.22); opacity:0; transform:translateY(12px); transition:opacity 0.25s,transform 0.25s; pointer-events:none; z-index:99999; max-width:360px; border-left:4px solid #22c55e;"></div>

<style>
/* ── Kebab action menu ── */
.action-menu-wrap {
    position: relative;
    display: inline-block;
}
.action-kebab {
    display: inline-flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 3.5px;
    width: 32px;
    height: 32px;
    background: transparent;
    border: 1px solid #e2e2e2;
    border-radius: 8px;
    cursor: pointer;
    transition: background 0.15s, border-color 0.15s, box-shadow 0.15s;
    padding: 0;
}
.action-kebab span {
    display: block;
    width: 4px;
    height: 4px;
    background: #555;
    border-radius: 50%;
    transition: background 0.15s;
}
.action-kebab:hover {
    background: #f4f4f8;
    border-color: #bbb;
    box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}
.action-kebab:hover span { background: #1a1a2e; }
.action-kebab.open {
    background: #1a1a2e;
    border-color: #1a1a2e;
}
.action-kebab.open span { background: #fff; }

.action-dropdown {
    display: none;
    position: absolute;
    right: 0;
    top: calc(100% + 6px);
    background: #fff;
    border: 1px solid #e8e8e8;
    border-radius: 10px;
    box-shadow: 0 8px 30px rgba(0,0,0,0.12), 0 2px 8px rgba(0,0,0,0.06);
    z-index: 1000;
    min-width: 172px;
    padding: 5px;
    animation: dropIn 0.15s ease;
}
.action-dropdown.open { display: block; }

@keyframes dropIn {
    from { opacity: 0; transform: translateY(-6px) scale(0.97); }
    to   { opacity: 1; transform: translateY(0)   scale(1); }
}

.action-item {
    display: flex;
    align-items: center;
    gap: 9px;
    padding: 8px 11px;
    font-size: 0.845rem;
    font-weight: 500;
    color: #2d2d2d;
    text-decoration: none;
    border-radius: 7px;
    transition: background 0.12s, color 0.12s;
    white-space: nowrap;
    cursor: pointer;
}
.action-item svg { flex-shrink: 0; opacity: 0.7; }
.action-item:hover { background: #f4f4f8; color: #1a1a2e; }
.action-item:hover svg { opacity: 1; }

.action-return { color: #15803d; }
.action-return:hover { background: #f0fdf4; color: #166534; }
.action-delete { color: #b91c1c; }
.action-delete:hover { background: #fff1f1; color: #991b1b; }

.action-divider {
    height: 1px;
    background: #f0f0f0;
    margin: 4px 6px;
}
.transaction_table.equipment td.actions,
.transaction_table.equipment th:last-child {
    width: 52px;
    text-align: center;
}
</style>

<script>
let _qrId   = null;
let _qrName = null;

function openReturnModal(equipmentId, equipmentName) {
    _qrId   = equipmentId;
    _qrName = equipmentName;
    document.getElementById('qrEquipmentPill').textContent = equipmentName;
    document.getElementById('qrRemarks').value             = '';
    document.getElementById('qrModalMsg').textContent      = '';
    const btn = document.getElementById('qrConfirmBtn');
    btn.disabled    = false;
    btn.textContent = 'Confirm Return';
    document.getElementById('quickReturnModal').classList.add('active');
}
function closeQuickReturnModal() {
    document.getElementById('quickReturnModal').classList.remove('active');
    _qrId = null; _qrName = null;
}
document.getElementById('quickReturnModal').addEventListener('click', function(e) {
    if (e.target === this) closeQuickReturnModal();
});

function submitQuickReturn() {
    if (!_qrId) return;
    const remarks = document.getElementById('qrRemarks').value.trim();
    const btn     = document.getElementById('qrConfirmBtn');
    const msgEl   = document.getElementById('qrModalMsg');
    btn.disabled    = true;
    btn.textContent = 'Processing…';
    msgEl.textContent = '';

    const fd = new FormData();
    fd.append('equipment_id', _qrId);
    fd.append('remarks', remarks);

    fetch('return_equipment.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeQuickReturnModal();
                const toast = document.getElementById('equipToast');
                toast.textContent = data.message;
                toast.style.opacity = '1';
                toast.style.transform = 'translateY(0)';
                setTimeout(() => {
                    toast.style.opacity = '0';
                    toast.style.transform = 'translateY(12px)';
                    setTimeout(() => location.reload(), 300);
                }, 2200);
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
</script>

<script>
// ── Kebab menu logic ──────────────────────────────────────────
function toggleMenu(btn) {
    const wrap = btn.closest('.action-menu-wrap');
    const drop = wrap.querySelector('.action-dropdown');
    const isOpen = drop.classList.contains('open');
    closeAllMenus();
    if (!isOpen) {
        drop.classList.add('open');
        btn.classList.add('open');
        // Flip upward if near bottom of viewport
        const rect = drop.getBoundingClientRect();
        if (rect.bottom > window.innerHeight - 16) {
            drop.style.top  = 'auto';
            drop.style.bottom = 'calc(100% + 6px)';
        } else {
            drop.style.top  = '';
            drop.style.bottom = '';
        }
    }
}
function closeAllMenus() {
    document.querySelectorAll('.action-dropdown.open').forEach(d => d.classList.remove('open'));
    document.querySelectorAll('.action-kebab.open').forEach(b => b.classList.remove('open'));
}
document.addEventListener('click', function(e) {
    if (!e.target.closest('.action-menu-wrap')) closeAllMenus();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeAllMenus();
});
</script>
</body>
</html>