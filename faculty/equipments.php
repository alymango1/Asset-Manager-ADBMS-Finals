<?php
include('../database/db.php');

session_start();

$name = "User"; // fallback

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0]; // first name only
}

// ── Search & Filter params ──────────────────────────────────────
$search   = isset($_GET['search'])   ? trim(mysqli_real_escape_string($conn, $_GET['search']))   : '';
$category = isset($_GET['category']) ? trim(mysqli_real_escape_string($conn, $_GET['category'])) : '';

// Build WHERE clause
$where = "WHERE 1=1";
if ($search   !== '') $where .= " AND resource_name LIKE '%$search%'";
if ($category !== '') $where .= " AND categories = '$category'";

// ── Pagination ──────────────────────────────────────────────────
$limit  = 10;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $limit;

$available_records = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments WHERE status ='Available'");
$available_result = mysqli_fetch_assoc($available_records);

$totalQuery   = mysqli_query($conn, "SELECT COUNT(*) as total FROM equipments $where");
$totalRow     = mysqli_fetch_assoc($totalQuery);
$totalRecords = $available_result['total'];
$totalPages   = ceil($totalRecords / $limit);

if ($page < 1) $page = 1;
if ($totalPages > 0 && $page > $totalPages) $page = $totalPages;

$equipmentsQuery = mysqli_query($conn, "
    SELECT * FROM equipments WHERE status = 'Available'
    LIMIT $limit OFFSET $offset");
    

// Build query string that pagination links carry forward
$queryParams = [];
if ($search   !== '') $queryParams[] = 'search='   . urlencode($search);
if ($category !== '') $queryParams[] = 'category=' . urlencode($category);
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

<div class="sidebar">
    <div class="logo-container">
        <img src="../img/bsu.png" alt="Logo">
        <h2>Asset Manager</h2>
    </div>
    <hr>
    <br>
    <a href="../faculty/dashboard.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Zm80-400h160v-240H200v240Zm400 320h160v-240H600v240Zm0-480h160v-80H600v80ZM200-200h160v-80H200v80Zm160-320Zm240-160Zm0 240ZM360-280Z"/></svg>Dashboard</a>
    <a href="../faculty/equipments.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>Equipments</a>
    <a href="../faculty/my_reservations.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Zm0-80h560v-560H200v560Zm80-80h280v-80H280v80Zm0-160h400v-80H280v80Zm0-160h400v-80H280v80Zm221.5-198.5Q510-807 510-820t-8.5-21.5Q493-850 480-850t-21.5 8.5Q450-833 450-820t8.5 21.5Q467-790 480-790t21.5-8.5ZM200-200v-560 560Z"/></svg>Reservations</a>
    <a href="logout.php"><svg xmlns="http://www.w3.org/2000/svg" height="24px" viewBox="0 -960 960 960" width="24px" fill="#FFFFFF"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>Logout</a>
</div>

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

        <div class="table-header-row">
            <h2>Equipment List</h2>

            <!-- ── Search & Filter Bar ── -->
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

                <button type="submit" class="btn-search">Search</button>

                <?php if ($search !== '' || $category !== ''): ?>
                    <a href="equipments.php" class="btn-clear-filter">&#x2715; Clear</a>
                <?php endif; ?>
            </form>
        </div>

        <!-- Result count -->
        <p class="result-count">
            <?php if ($search !== '' || $category !== ''): ?>
                Showing <strong><?php echo $totalRecords; ?></strong> result<?php echo $totalRecords != 1 ? 's' : ''; ?>
                <?php if ($search !== ''): ?> for <strong>"<?php echo htmlspecialchars($search); ?>"</strong><?php endif; ?>
            <?php else: ?>
                Showing <strong><?php echo $totalRecords; ?></strong> equipment<?php echo $totalRecords != 1 ? 's' : ''; ?> total
            <?php endif; ?>
        </p>

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

                
                        <a class="btn-edit"
                        href="reserve_item.php?id=<?php echo $row['equipment_id']; ?>">
                        Reserve
                        </a>

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
.table-header-row {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 8px;
}
.table-header-row h2 { margin: 0; line-height: 1.8; }

.search-filter-bar {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
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

.result-count {
    font-size: 0.85rem;
    color: #777;
    margin: 0 0 12px;
}

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

</body>
</html>