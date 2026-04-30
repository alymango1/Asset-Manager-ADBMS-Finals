<?php
session_start();

include('../database/db.php');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

$name = "User";
if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0];
}

// Build profile initials
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

$addUserMessage = '';
$addUserMessageType = '';
$openAddUserModal = false;

if (isset($_POST['create_user'])) {
    $openAddUserModal = true;

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $addUserMessage = "Invalid request. Please try again.";
        $addUserMessageType = 'error';
    } else {
        $newFullName = trim($_POST['full_name'] ?? '');
        $newUsername = trim($_POST['username'] ?? '');
        $newPassword = trim($_POST['password'] ?? '');
        $newRole = trim($_POST['role'] ?? '');
        $allowedRoles = ['admin', 'staff'];

        if (!in_array($newRole, $allowedRoles, true)) {
            $addUserMessage = "Invalid role selected.";
            $addUserMessageType = 'error';
        } elseif ($newFullName === '' || $newUsername === '' || $newPassword === '') {
            $addUserMessage = "All fields are required.";
            $addUserMessageType = 'error';
        } elseif (strlen($newPassword) < 8) {
            $addUserMessage = "Password must be at least 8 characters.";
            $addUserMessageType = 'error';
        } else {
            $hashedPassword = password_hash($newPassword, PASSWORD_BCRYPT);
            $stmt = mysqli_prepare($conn, "INSERT INTO users (full_name, username, password, roles) VALUES (?, ?, ?, ?)");
            mysqli_stmt_bind_param($stmt, 'ssss', $newFullName, $newUsername, $hashedPassword, $newRole);

            if (mysqli_stmt_execute($stmt)) {
                $_SESSION['success'] = "User account created successfully.";
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                mysqli_stmt_close($stmt);
                header("Location: users.php");
                exit();
            } else {
                $addUserMessage = "Error: " . mysqli_stmt_error($stmt);
                $addUserMessageType = 'error';
            }
            mysqli_stmt_close($stmt);
        }
    }
}

$totalUsers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users"))['total'];
$totalAdmins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='admin'"))['total'];
$totalStaff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='staff'"))['total'];

// Search + filter
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$role = isset($_GET['role']) ? trim($_GET['role']) : '';
$searchEscaped = mysqli_real_escape_string($conn, $search);
$roleEscaped = mysqli_real_escape_string($conn, $role);

$where = "WHERE 1=1";
if ($search !== '') {
    $where .= " AND (full_name LIKE '%$searchEscaped%' OR username LIKE '%$searchEscaped%')";
}
if ($role !== '') {
    $where .= " AND roles = '$roleEscaped'";
}

$limit = 5;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $limit;

$totalQuery = mysqli_query($conn, "SELECT COUNT(*) as total FROM users $where");
$totalRow = mysqli_fetch_assoc($totalQuery);
$totalRecords = (int)$totalRow['total'];
$totalPages = max((int)ceil($totalRecords / $limit), 1);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

$usersQuery = mysqli_query($conn, "
    SELECT * FROM users
    $where
    ORDER BY user_id ASC
    LIMIT $limit OFFSET $offset
");
$displayedRows = mysqli_num_rows($usersQuery);

$queryParams = [];
if ($search !== '') $queryParams[] = 'search=' . urlencode($search);
if ($role !== '') $queryParams[] = 'role=' . urlencode($role);
$filterString = count($queryParams) ? '&' . implode('&', $queryParams) : '';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Users — BSU Asset Manager</title>
    <link rel="icon" href="../img/favicon-96.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,400&family=Syne:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
    <link rel="stylesheet" href="../css/admin/sidebar.css">
    <link rel="stylesheet" href="../css/admin/users.css">
    <link rel="stylesheet" href="../css/admin/modal.css">
</head>
<body>

<?php include('sidebar.php'); ?>

<header class="topbar">
    <div class="topbar-title">
        <h1>Users</h1>
        <p>Manage admin and staff access securely</p>
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

<button type="button" class="fab js-open-add-user" title="Add User">
    <svg xmlns="http://www.w3.org/2000/svg" height="23px" viewBox="0 -960 960 960" width="23px" fill="currentColor">
        <path d="M440-440H200v-80h240v-240h80v240h240v80H520v240h-80v-240Z"/>
    </svg>
</button>

<main class="main">
    <section class="users-hero">
        <div class="users-hero-copy">
            <p class="eyebrow">Administration</p>
            <h2>User Management</h2>
            <p class="hero-subtitle">Manage all administrator and staff accounts in the system.</p>
        </div>
    </section>

    <section class="users-metrics">
        <article class="metric-card metric-all-accounts">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M367-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM160-160v-112q0-34 17.5-62.5T224-378q62-31 126-46.5T480-440q66 0 130 15.5T736-378q29 15 46.5 43.5T800-272v112H160Z"/></svg>
            </div>
            <div class="metric-body">
                <p>All Accounts</p>
                <strong><?php echo $totalUsers; ?></strong>
            </div>
        </article>
        <article class="metric-card metric-staff-accounts">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M234-276q51-39 114-61.5T480-360q69 0 132 22.5T726-276q14-17 24-36.5t16-41.5q-42-48-66-106t-24-120q0-109-76-184.5T416-840q-109 0-184.5 75.5T156-580q0 62-24 120t-66 106q6 22 16 41.5T106-276Zm246 116q-83 0-156-31.5T197-277q-54-54-85.5-127T80-560q0-83 31.5-156T197-843q54-54 127-85.5T480-960q83 0 156 31.5T763-843q54 54 85.5 127T880-560q0 83-31.5 156T763-277q-54 54-127 85.5T480-160Zm0-80q47 0 90.5-11.5T654-284q-40-28-84-42t-90-14q-46 0-90 14t-84 42q40 20 83.5 31.5T480-240Z"/></svg>
            </div>
            <div class="metric-body">
                <p>Staff Accounts</p>
                <strong><?php echo $totalStaff; ?></strong>
            </div>
        </article>
        <article class="metric-card metric-admin-accounts">
            <div class="metric-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="19" height="19" viewBox="0 -960 960 960" fill="currentColor"><path d="M80-120v-80h800v80H80Zm80-160v-400h640v400H160Zm80-80h480v-240H240v240Zm240 0Z"/></svg>
            </div>
            <div class="metric-body">
                <p>Admin Accounts</p>
                <strong><?php echo $totalAdmins; ?></strong>
            </div>
        </article>
    </section>

    <section class="content-grid">
        <div class="table-wrap section-card">
            <div class="section-header">
                <h2>Users</h2>

                <div class="search-filter-bar-wrap">
                    <form method="GET" action="users.php" class="search-filter-bar">
                        <div class="search-input-wrap sf-search-input">
                            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M784-120 532-372q-30 24-69 38t-83 14q-109 0-184.5-75.5T120-580q0-109 75.5-184.5T380-840q109 0 184.5 75.5T640-580q0 44-14 83t-38 69l252 252-56 56ZM380-400q75 0 127.5-52.5T560-580q0-75-52.5-127.5T380-760q-75 0-127.5 52.5T200-580q0 75 52.5 127.5T380-400Z"/></svg>
                            <input
                                type="text"
                                name="search"
                                placeholder="Search full name or username..."
                                value="<?php echo htmlspecialchars($search); ?>"
                                autocomplete="off"
                            >
                        </div>

                        <select name="role" class="sf-role">
                            <option value="">All Roles</option>
                            <option value="admin" <?php if ($role === 'admin') echo 'selected'; ?>>Admin</option>
                            <option value="staff" <?php if ($role === 'staff') echo 'selected'; ?>>Staff</option>
                        </select>

                        <button type="submit" class="btn-search sf-search-btn">Search</button>

                        <?php if ($search !== '' || $role !== ''): ?>
                            <a href="users.php" class="btn-clear-filter sf-clear-btn">&#x2715; Clear</a>
                        <?php endif; ?>
                    </form>

                    <p class="result-count">
                        <?php if ($search !== '' || $role !== ''): ?>
                            Showing <strong><?php echo $totalRecords; ?></strong> result<?php echo $totalRecords !== 1 ? 's' : ''; ?>
                            <?php if ($search !== ''): ?> for <strong>"<?php echo htmlspecialchars($search); ?>"</strong><?php endif; ?>
                        <?php else: ?>
                            Showing <strong><?php echo $totalRecords; ?></strong> user account<?php echo $totalRecords !== 1 ? 's' : ''; ?> total
                        <?php endif; ?>
                    </p>
                </div>
            </div>
            <?php if (isset($_SESSION['success'])): ?>
                <div class="message-box success"><?php echo htmlspecialchars($_SESSION['success']); unset($_SESSION['success']); ?></div>
            <?php endif; ?>

            <table class="users-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (mysqli_num_rows($usersQuery) === 0): ?>
                        <tr class="empty-row">
                            <td colspan="5">No users found.</td>
                        </tr>
                    <?php else: ?>
                        <?php while ($row = mysqli_fetch_assoc($usersQuery)) { ?>
                            <tr>
                                <td class="id-cell">#<?php echo $row['user_id']; ?></td>
                                <td class="name-cell"><?php echo htmlspecialchars($row['full_name']); ?></td>
                                <td><?php echo htmlspecialchars($row['username']); ?></td>
                                <td>
                                    <span class="role-badge <?php echo strtolower($row['roles']) === 'admin' ? 'admin' : 'staff'; ?>">
                                        <?php echo ucfirst($row['roles']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="edit_user.php?id=<?php echo $row['user_id']; ?>" class="btn btn-edit">Edit</a>
                                        <form method="POST" action="../config/delete_user.php" class="delete-form">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="id" value="<?php echo $row['user_id']; ?>">
                                            <button type="button" class="btn btn-delete" onclick="openDeleteUserModal(this.form)">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php } ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <?php if ($displayedRows < $limit): ?>
                <div class="table-filler">
                    <p>That's it!</p>
                    <span>Newly created accounts will appear here automatically.</span>
                </div>
            <?php endif; ?>

            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page - 1; ?><?php echo $filterString; ?>">&laquo; Prev</a>
                <?php endif; ?>

                <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                    <a href="?page=<?php echo $i; ?><?php echo $filterString; ?>" class="<?php echo ($i == $page) ? 'active' : ''; ?>">
                        <?php echo $i; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page + 1; ?><?php echo $filterString; ?>">Next &raquo;</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="right-panel">
            <div class="quick-actions">
                <h3>Quick Actions</h3>
                <div class="action-list">
                    <button type="button" class="action-item primary js-open-add-user">
                        <div class="action-item-icon">
                            <svg xmlns="http://www.w3.org/2000/svg" width="17" height="17" viewBox="0 -960 960 960" fill="white"><path d="M726.67-400v-126.67H600v-66.66h126.67V-720h66.66v126.67H920v66.66H793.33V-400h-66.66ZM250.33-524.33Q206.67-568 206.67-634t43.66-109.67Q294-787.33 360-787.33t109.67 43.66Q513.33-700 513.33-634t-43.66 109.67Q426-480.67 360-480.67t-109.67-43.66ZM40-160v-100q0-34.67 17.5-63.17T106.67-366q70.66-32.33 131-46.5Q298-426.67 360-426.67t122 14.17q60 14.17 130.67 46.5 31.66 15 49.5 43.17Q680-294.67 680-260v100H40Z"/></svg>
                        </div>
                        <div class="action-item-body">
                            <strong>Create User Account</strong>
                            <span>Add admin or staff credentials</span>
                        </div>
                        <svg class="action-chevron" xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 -960 960 960" fill="white"><path d="M504-480 320-664l56-56 240 240-240 240-56-56 184-184Z"/></svg>
                    </button>
                </div>
            </div>

            <div class="insights-card">
                <h3>Role Distribution</h3>
                <?php
                    $totalForPct = max((int)$totalUsers, 1);
                    $adminPct = round(((int)$totalAdmins / $totalForPct) * 100);
                    $staffPct = round(((int)$totalStaff / $totalForPct) * 100);
                ?>
                <div class="progress-item">
                    <span class="progress-label">Admins</span>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill admin" style="width: <?php echo $adminPct; ?>%;"></div>
                    </div>
                    <span class="progress-count"><?php echo $totalAdmins; ?></span>
                </div>
                <div class="progress-item">
                    <span class="progress-label">Staff</span>
                    <div class="progress-bar-wrap">
                        <div class="progress-bar-fill staff" style="width: <?php echo $staffPct; ?>%;"></div>
                    </div>
                    <span class="progress-count"><?php echo $totalStaff; ?></span>
                </div>
            </div>

            <div class="guide-card">
                <h3>User Management Guide</h3>
                <p>Keep access clean and secure by assigning correct roles and reviewing unused accounts regularly.</p>
                <ul>
                    <li>Use Admin only for trusted management users.</li>
                    <li>Remove inactive accounts to reduce security risk.</li>
                    <li>Update user details as role responsibilities change.</li>
                </ul>
            </div>
        </div>
    </section>
</main>

<div class="modal-overlay<?php echo $openAddUserModal ? ' active' : ''; ?>" id="addUserModal">
    <div class="modal-card">
        <div class="modal-head">
            <div>
                <p class="modal-kicker">User Access</p>
                <h3>Create User Account</h3>
                <p>Add a new admin or staff account securely.</p>
            </div>
            <button type="button" class="modal-close" id="closeAddUserModal" aria-label="Close">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
            </button>
        </div>

        <?php if ($addUserMessage !== ''): ?>
            <div class="modal-message <?php echo $addUserMessageType === 'error' ? 'error' : 'success'; ?>">
                <?php echo htmlspecialchars($addUserMessage); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="users.php" class="add-user-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="input-group">
                <label for="create_full_name">Full Name</label>
                <input id="create_full_name" type="text" name="full_name" placeholder="Enter full name" value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required>
            </div>
            <div class="input-group">
                <label for="create_username">Username</label>
                <input id="create_username" type="text" name="username" placeholder="Enter username" value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
            </div>
            <div class="input-group">
                <label for="create_password">Password</label>
                <input id="create_password" type="password" name="password" placeholder="Enter password (min. 8 characters)" minlength="8" required>
            </div>
            <div class="input-group">
                <label for="create_role">Role</label>
                <select id="create_role" name="role" required>
                    <option value="">Select Role</option>
                    <option value="admin" <?php if (($_POST['role'] ?? '') === 'admin') echo 'selected'; ?>>Admin</option>
                    <option value="staff" <?php if (($_POST['role'] ?? '') === 'staff') echo 'selected'; ?>>Staff</option>
                </select>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-secondary" id="cancelAddUser">Cancel</button>
                <button type="submit" name="create_user" class="btn-primary">Create User</button>
            </div>
        </form>
    </div>
</div>

<div class="modal-overlay" id="deleteUserModal">
    <div class="modal-box confirm-modern">
        <button type="button" class="confirm-close" onclick="closeDeleteUserModal()" aria-label="Close">
            <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
        </button>
        <div class="confirm-icon-wrap">
            <span class="confirm-icon">
                <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 -960 960 960" fill="currentColor"><path d="M280-120q-33 0-56.5-23.5T200-200v-520h-40v-80h200v-40h240v40h200v80h-40v520q0 33-23.5 56.5T680-120H280Z"/></svg>
            </span>
        </div>
        <h3>Are you sure?</h3>
        <p class="confirm-body">Delete this user account? This action cannot be undone.</p>
        <div class="modal-actions confirm-actions">
            <button type="button" class="confirm-btn-danger" id="confirmDeleteUserBtn">Delete User</button>
            <button type="button" class="confirm-btn-secondary" onclick="closeDeleteUserModal()">Cancel</button>
        </div>
    </div>
</div>

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

const addUserModal = document.getElementById('addUserModal');
const openAddUserBtns = document.querySelectorAll('.js-open-add-user');
const closeAddUserModal = document.getElementById('closeAddUserModal');
const cancelAddUser = document.getElementById('cancelAddUser');
let _deleteUserForm = null;

function showAddUserModal() {
    addUserModal.classList.add('active');
    document.body.classList.add('modal-open');
}
function hideAddUserModal() {
    addUserModal.classList.remove('active');
    document.body.classList.remove('modal-open');
}

openAddUserBtns.forEach((btn) => {
    btn.addEventListener('click', showAddUserModal);
});

if (closeAddUserModal) closeAddUserModal.addEventListener('click', hideAddUserModal);
if (cancelAddUser) cancelAddUser.addEventListener('click', hideAddUserModal);

addUserModal.addEventListener('click', (e) => {
    if (e.target === addUserModal) hideAddUserModal();
});

document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') hideAddUserModal();
});

<?php if ($openAddUserModal): ?>
showAddUserModal();
<?php endif; ?>

function openDeleteUserModal(formEl) {
    _deleteUserForm = formEl;
    document.getElementById('deleteUserModal').classList.add('active');
}
function closeDeleteUserModal() {
    document.getElementById('deleteUserModal').classList.remove('active');
    _deleteUserForm = null;
}
document.getElementById('confirmDeleteUserBtn').addEventListener('click', function() {
    if (_deleteUserForm) _deleteUserForm.submit();
});
document.getElementById('deleteUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeDeleteUserModal();
});
</script>

</body>
</html>

