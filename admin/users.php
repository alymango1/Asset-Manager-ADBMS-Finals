<?php
session_start();

include('../database/db.php');
date_default_timezone_set('Asia/Manila');
mysqli_query($conn, "SET time_zone = '+08:00'");

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

// make initials from their name
$fullNameRaw = trim(preg_replace('/\s+/', ' ', (string)($_SESSION['full_name'] ?? $name)));
$parts = $fullNameRaw !== '' ? preg_split('/\s+/', $fullNameRaw) : [];
$first = $parts[0] ?? '';
$last  = count($parts) > 1 ? $parts[count($parts) - 1] : '';
$profileInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$profileInitials = $profileInitials !== '' ? $profileInitials : 'U';

// get data for the bell icon
$_notifPendingQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
$_notifPendingCount = mysqli_fetch_assoc($_notifPendingQuery)['total'];
$_notifOverdueQuery = mysqli_query($conn, "
    SELECT e.resource_name, r.reserved_end, r.reservation_id
    FROM reservations r
    JOIN equipments e ON r.equipment_id = e.equipment_id
    WHERE r.status = 'approved'
      AND e.status = 'In-Use'
      AND r.reserved_end IS NOT NULL
      AND r.reserved_end < NOW()
    ORDER BY r.reserved_end ASC
    LIMIT 5
");
$_notifOverdueItems = [];
while ($row = mysqli_fetch_assoc($_notifOverdueQuery)) {
    $_notifOverdueItems[] = $row;
}
$_notifOverdueCount = count($_notifOverdueItems);
$_notifTotal = $_notifOverdueCount + ($_notifPendingCount > 0 ? 1 : 0);

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

// handle edit user form submit
$editUserMessage     = '';
$editUserMessageType = '';
$openEditUserModal   = false;
$editUserData        = null; // pre-filled row for the modal

if (isset($_POST['edit_user'])) {
    $openEditUserModal = true;

    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $editUserMessage     = "Invalid request. Please try again.";
        $editUserMessageType = 'error';
    } else {
        $editId       = isset($_POST['edit_id']) ? (int)$_POST['edit_id'] : 0;
        $editFullName = trim($_POST['edit_full_name']  ?? '');
        $editUsername = trim($_POST['edit_username']   ?? '');
        $editRole     = trim($_POST['edit_role']       ?? '');
        $editPassword = trim($_POST['edit_password']   ?? '');
        $allowedRoles = ['admin', 'staff'];

        // remember form values on error
        $editUserData = [
            'user_id'   => $editId,
            'full_name' => $editFullName,
            'username'  => $editUsername,
            'roles'     => $editRole,
        ];

        if ($editId <= 0) {
            $editUserMessage = "Invalid user."; $editUserMessageType = 'error';
        } elseif ($editFullName === '') {
            $editUserMessage = "Full name cannot be empty."; $editUserMessageType = 'error';
        } elseif ($editUsername === '') {
            $editUserMessage = "Username cannot be empty."; $editUserMessageType = 'error';
        } elseif (!in_array($editRole, $allowedRoles, true)) {
            $editUserMessage = "Invalid role selected."; $editUserMessageType = 'error';
        } else {
            $dup = mysqli_prepare($conn, "SELECT user_id FROM users WHERE username = ? AND user_id != ?");
            mysqli_stmt_bind_param($dup, 'si', $editUsername, $editId);
            mysqli_stmt_execute($dup);
            mysqli_stmt_store_result($dup);
            $isDup = mysqli_stmt_num_rows($dup) > 0;
            mysqli_stmt_close($dup);

            if ($isDup) {
                $editUserMessage = "That username is already taken by another account.";
                $editUserMessageType = 'error';
            } else {
                if ($editPassword !== '') {
                    $hash = password_hash($editPassword, PASSWORD_BCRYPT);
                    $upd  = mysqli_prepare($conn, "UPDATE users SET full_name=?, username=?, password=?, roles=? WHERE user_id=?");
                    mysqli_stmt_bind_param($upd, 'ssssi', $editFullName, $editUsername, $hash, $editRole, $editId);
                } else {
                    $upd = mysqli_prepare($conn, "UPDATE users SET full_name=?, username=?, roles=? WHERE user_id=?");
                    mysqli_stmt_bind_param($upd, 'sssi', $editFullName, $editUsername, $editRole, $editId);
                }
                if (mysqli_stmt_execute($upd)) {
                    mysqli_stmt_close($upd);
                    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                    $_SESSION['success_edit'] = "User updated successfully.";
                    header("Location: users.php");
                    exit();
                } else {
                    $editUserMessage = "Error: " . mysqli_stmt_error($upd);
                    $editUserMessageType = 'error';
                    mysqli_stmt_close($upd);
                }
            }
        }
    }
}

// show flash message if any
$editSuccessMsg = '';
if (isset($_SESSION['success_edit'])) {
    $editSuccessMsg = $_SESSION['success_edit'];
    unset($_SESSION['success_edit']);
}

$totalUsers = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users"))['total'];
$totalAdmins = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='admin'"))['total'];
$totalStaff = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as total FROM users WHERE roles='staff'"))['total'];

// handle search and filters
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
        <!-- Bell notification -->
        <div class="notif-wrap" id="notifWrap">
            <button class="notif-btn" id="notifBtn" aria-label="Notifications">
                <svg xmlns="http://www.w3.org/2000/svg" height="20px" viewBox="0 -960 960 960" width="20px" fill="currentColor"><path d="M160-200v-80h80v-280q0-83 50-149.5T420-790v-30q0-25 17.5-42.5T480-880q25 0 42.5 17.5T540-820v30q80 20 130 86.5T720-560v280h80v80H160Zm320-300Zm0 420q-33 0-56.5-23.5T400-160h160q0 33-23.5 56.5T480-80ZM320-280h320v-280q0-66-47-113t-113-47q-66 0-113 47t-47 113v280Z"/></svg>
                <?php if ($_notifTotal > 0): ?>
                <span class="notif-badge"><?= $_notifTotal ?></span>
                <?php endif; ?>
            </button>
            <div class="notif-dropdown" id="notifDropdown">
                <div class="notif-dropdown-header">
                    <span class="notif-dropdown-title">Notifications</span>
                    <?php if ($_notifTotal > 0): ?>
                    <span class="notif-dropdown-count"><?= $_notifTotal ?> new</span>
                    <?php endif; ?>
                </div>
                <div class="notif-list">
                <?php if ($_notifOverdueCount === 0 && $_notifPendingCount === 0): ?>
                    <div class="notif-empty">
                        <svg xmlns="http://www.w3.org/2000/svg" height="28px" viewBox="0 -960 960 960" width="28px" fill="currentColor"><path d="m424-312 282-282-56-56-226 226-114-114-56 56 170 170Zm56 232q-83 0-156-31.5T197-197q-54-54-85.5-127T80-480q0-83 31.5-156T197-763q54-54 127-85.5T480-880q83 0 156 31.5T763-763q54 54 85.5 127T880-480q0 83-31.5 156T763-197q-54 54-127 85.5T480-80Z"/></svg>
                        <p>All clear — nothing needs attention.</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($_notifOverdueItems as $_notifItem):
                        $_notifSecsLate = time() - strtotime($_notifItem['reserved_end']);
                        $_notifMinsLate = round($_notifSecsLate / 60);
                        if ($_notifSecsLate < 3600)           $_notifTimeLabel = $_notifMinsLate . ' min ago';
                        elseif ($_notifSecsLate < 86400)      $_notifTimeLabel = round($_notifSecsLate/3600) . ' hr ago';
                        elseif ($_notifSecsLate < 604800)     $_notifTimeLabel = round($_notifSecsLate/86400) . ' day' . (round($_notifSecsLate/86400) == 1 ? '' : 's') . ' ago';
                        elseif ($_notifSecsLate < 2592000)    $_notifTimeLabel = round($_notifSecsLate/604800) . ' week' . (round($_notifSecsLate/604800) == 1 ? '' : 's') . ' ago';
                        elseif ($_notifSecsLate < 31536000)   $_notifTimeLabel = round($_notifSecsLate/2592000) . ' month' . (round($_notifSecsLate/2592000) == 1 ? '' : 's') . ' ago';
                        else                                  $_notifTimeLabel = round($_notifSecsLate/31536000) . ' year' . (round($_notifSecsLate/31536000) == 1 ? '' : 's') . ' ago';
                    ?>
                    <a href="in_use.php" class="notif-item notif-critical">
                        <span class="notif-item-dot notif-dot-red"></span>
                        <div class="notif-item-body">
                            <strong><?= htmlspecialchars($_notifItem['resource_name']) ?> — not returned</strong>
                            <span>Overdue since <?= date('g:i a', strtotime($_notifItem['reserved_end'])) ?></span>
                        </div>
                        <span class="notif-item-time"><?= $_notifTimeLabel ?></span>
                    </a>
                    <?php endforeach; ?>
                    <?php if ($_notifPendingCount > 0): ?>
                    <a href="reservation.php" class="notif-item notif-warning">
                        <span class="notif-item-dot notif-dot-amber"></span>
                        <div class="notif-item-body">
                            <strong><?= $_notifPendingCount ?> pending reservation<?= $_notifPendingCount != 1 ? 's' : '' ?></strong>
                            <span>Waiting for your approval</span>
                        </div>
                        <span class="notif-item-time">Review →</span>
                    </a>
                    <?php endif; ?>
                <?php endif; ?>
                </div>
                <?php if ($_notifTotal > 0): ?>
                <div class="notif-dropdown-footer">
                    <a href="in_use.php">View all overdue items →</a>
                </div>
                <?php endif; ?>
            </div>
        </div>

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
            <?php if ($editSuccessMsg !== ''): ?>
                <div class="message-box success"><?php echo htmlspecialchars($editSuccessMsg); ?></div>
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
                                        <button type="button" class="btn btn-edit" onclick="openEditUserModal(<?php echo $row['user_id']; ?>, <?php echo htmlspecialchars(json_encode($row['full_name'])); ?>, <?php echo htmlspecialchars(json_encode($row['username'])); ?>, <?php echo htmlspecialchars(json_encode($row['roles'])); ?>)">Edit</button>
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

<!-- ══════════════ CREATE USER MODAL — Premium Redesign ══════════════ -->
<div class="modal-overlay<?php echo $openAddUserModal ? ' active' : ''; ?>" id="addUserModal">
    <div class="cau-shell">

        <!-- Left decorative panel -->
        <div class="cau-panel">
            <div class="cau-panel-inner">
                <div class="cau-panel-orb cau-panel-orb--1"></div>
                <div class="cau-panel-orb cau-panel-orb--2"></div>
                <div class="cau-panel-orb cau-panel-orb--3"></div>
                <div class="cau-panel-badge">
                    <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <line x1="19" y1="8" x2="19" y2="14"/>
                        <line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                </div>
                <div class="cau-panel-text">
                    <p class="cau-panel-eyebrow">User Access</p>
                    <h2 class="cau-panel-title">New<br>Account</h2>
                    <p class="cau-panel-desc">Create a secure admin or staff account with role-based permissions.</p>
                </div>
                <ul class="cau-panel-checklist">
                    <li>
                        <span class="cau-check-icon">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        Minimum 8-character password
                    </li>
                    <li>
                        <span class="cau-check-icon">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        Role-based access control
                    </li>
                    <li>
                        <span class="cau-check-icon">
                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                        </span>
                        Bcrypt-encrypted credentials
                    </li>
                </ul>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="cau-form-panel">
            <div class="cau-form-header">
                <div class="cau-form-header-text">
                    <p class="cau-form-kicker">BSU Asset Manager</p>
                    <h3 class="cau-form-title">Create User Account</h3>
                </div>
                <button type="button" class="cau-close-btn" id="closeAddUserModal" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                </button>
            </div>

            <?php if ($addUserMessage !== ''): ?>
                <div class="cau-alert <?php echo $addUserMessageType === 'error' ? 'cau-alert--error' : 'cau-alert--success'; ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <?php if ($addUserMessageType === 'error'): ?>
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        <?php else: ?>
                            <polyline points="20 6 9 17 4 12"/>
                        <?php endif; ?>
                    </svg>
                    <?php echo htmlspecialchars($addUserMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="users.php" class="cau-form" id="createUserForm" onsubmit="return cauValidate()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

                <!-- Row 1: Full name + Username -->
                <div class="cau-row">
                    <div class="cau-field">
                        <label class="cau-label" for="create_full_name">
                            <span class="cau-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            Full Name
                        </label>
                        <input class="cau-input" id="create_full_name" type="text" name="full_name"
                               placeholder="e.g. Juan dela Cruz"
                               value="<?php echo htmlspecialchars($_POST['full_name'] ?? ''); ?>" required
                               oninput="cauLiveAvatar(this.value)">
                    </div>
                    <div class="cau-field">
                        <label class="cau-label" for="create_username">
                            <span class="cau-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/><path d="M21 21v-2a4 4 0 0 0-3-3.85"/></svg>
                            </span>
                            Username
                        </label>
                        <input class="cau-input" id="create_username" type="text" name="username"
                               placeholder="e.g. jdelacruz"
                               value="<?php echo htmlspecialchars($_POST['username'] ?? ''); ?>" required>
                    </div>
                </div>

                <!-- Row 2: Password + Confirm -->
                <div class="cau-row">
                    <div class="cau-field">
                        <label class="cau-label" for="create_password">
                            <span class="cau-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            </span>
                            Password
                        </label>
                        <div class="cau-input-wrap">
                            <input class="cau-input" id="create_password" type="password" name="password"
                                   placeholder="Min. 8 characters" minlength="8" required
                                   oninput="cauCheckMatch(); cauStrength(this.value)">
                            <button type="button" class="cau-eye-btn" onclick="cauToggle('create_password','cauEye1')" title="Show/hide password">
                                <svg id="cauEye1" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <!-- Password strength bar -->
                        <div class="cau-strength-wrap" id="cauStrengthWrap">
                            <div class="cau-strength-bar">
                                <div class="cau-strength-fill" id="cauStrengthFill"></div>
                            </div>
                            <span class="cau-strength-label" id="cauStrengthLabel"></span>
                        </div>
                    </div>
                    <div class="cau-field">
                        <label class="cau-label">
                            <span class="cau-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                            </span>
                            Confirm Password
                        </label>
                        <div class="cau-input-wrap">
                            <input class="cau-input" id="cau_confirm" type="password"
                                   placeholder="Repeat password"
                                   oninput="cauCheckMatch()">
                            <button type="button" class="cau-eye-btn" onclick="cauToggle('cau_confirm','cauEye2')" title="Show/hide password">
                                <svg id="cauEye2" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                </svg>
                            </button>
                        </div>
                        <div class="cau-match-hint" id="cauMatchHint"></div>
                    </div>
                </div>

                <!-- Row 3: Role (full width) -->
                <div class="cau-field cau-field--full">
                    <label class="cau-label" for="create_role">
                        <span class="cau-label-icon">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </span>
                        Role
                    </label>
                    <!-- Custom role selector cards -->
                    <div class="cau-role-group">
                        <label class="cau-role-card" id="cau-role-admin">
                            <input type="radio" name="role" value="admin" <?php if (($_POST['role'] ?? '') === 'admin') echo 'checked'; ?> required>
                            <span class="cau-role-card-icon cau-role-card-icon--admin">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>
                            </span>
                            <span class="cau-role-card-body">
                                <strong>Admin</strong>
                                <span>Full system access</span>
                            </span>
                            <span class="cau-role-check">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </label>
                        <label class="cau-role-card" id="cau-role-staff">
                            <input type="radio" name="role" value="staff" <?php if (($_POST['role'] ?? '') === 'staff') echo 'checked'; ?>>
                            <span class="cau-role-card-icon cau-role-card-icon--staff">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <span class="cau-role-card-body">
                                <strong>Staff</strong>
                                <span>Limited access</span>
                            </span>
                            <span class="cau-role-check">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Divider -->
                <div class="cau-divider"></div>

                <!-- Actions -->
                <div class="cau-actions">
                    <!-- Live avatar preview -->
                    <div class="cau-avatar-preview" id="cauAvatarPreview">
                        <div class="cau-avatar-ring" id="cauAvatarRing">
                            <span id="cauAvatarInitials">?</span>
                        </div>
                        <span class="cau-avatar-name" id="cauAvatarName">New User</span>
                    </div>
                    <div class="cau-actions-btns">
                        <button type="button" class="cau-btn-cancel" id="cancelAddUser">Cancel</button>
                        <button type="submit" name="create_user" class="cau-btn-submit" id="cauSubmitBtn">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4"/>
                                <line x1="19" y1="8" x2="19" y2="14"/>
                                <line x1="22" y1="11" x2="16" y2="11"/>
                            </svg>
                            Create Account
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

// create user modal stuff
function cauCheckMatch() {
    const pw   = document.getElementById('create_password').value;
    const cpw  = document.getElementById('cau_confirm').value;
    const hint = document.getElementById('cauMatchHint');
    const pwEl = document.getElementById('create_password');
    const cpwEl= document.getElementById('cau_confirm');
    const btn  = document.getElementById('cauSubmitBtn');
    if (cpw === '') {
        hint.textContent = ''; hint.className = 'cau-match-hint';
        pwEl.classList.remove('cau-err','cau-ok');
        cpwEl.classList.remove('cau-err','cau-ok');
        btn.disabled = false; return;
    }
    if (pw === cpw) {
        hint.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Passwords match';
        hint.className = 'cau-match-hint match';
        pwEl.classList.replace('cau-err','cau-ok') || pwEl.classList.add('cau-ok');
        cpwEl.classList.replace('cau-err','cau-ok') || cpwEl.classList.add('cau-ok');
        btn.disabled = false;
    } else {
        hint.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords do not match';
        hint.className = 'cau-match-hint no-match';
        pwEl.classList.replace('cau-ok','cau-err') || pwEl.classList.add('cau-err');
        cpwEl.classList.replace('cau-ok','cau-err') || cpwEl.classList.add('cau-err');
        btn.disabled = true;
    }
}

function cauValidate() {
    const pw  = document.getElementById('create_password').value;
    const cpw = document.getElementById('cau_confirm').value;
    if (pw !== cpw) {
        document.getElementById('cauMatchHint').innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords do not match';
        document.getElementById('cauMatchHint').className = 'cau-match-hint no-match';
        return false;
    }
    return true;
}

function cauToggle(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.innerHTML = show
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

function cauStrength(pw) {
    const wrap  = document.getElementById('cauStrengthWrap');
    const fill  = document.getElementById('cauStrengthFill');
    const label = document.getElementById('cauStrengthLabel');
    if (!pw) { wrap.style.opacity = '0'; return; }
    wrap.style.opacity = '1';
    let score = 0;
    if (pw.length >= 8)  score++;
    if (pw.length >= 12) score++;
    if (/[A-Z]/.test(pw)) score++;
    if (/[0-9]/.test(pw)) score++;
    if (/[^A-Za-z0-9]/.test(pw)) score++;
    const levels = [
        { pct: '20%', color: '#e53e3e', text: 'Very weak'  },
        { pct: '40%', color: '#dd6b20', text: 'Weak'       },
        { pct: '60%', color: '#d69e2e', text: 'Fair'       },
        { pct: '80%', color: '#38a169', text: 'Strong'     },
        { pct: '100%',color: '#276749', text: 'Very strong'},
    ];
    const l = levels[Math.max(0, score - 1)] || levels[0];
    fill.style.width = l.pct;
    fill.style.background = l.color;
    label.textContent = l.text;
    label.style.color = l.color;
}

function cauLiveAvatar(name) {
    const parts = name.trim().split(/\s+/).filter(Boolean);
    const initials = ((parts[0]?.[0] || '') + (parts[1]?.[0] || parts[0]?.[1] || '')).toUpperCase() || '?';
    document.getElementById('cauAvatarInitials').textContent = initials;
    document.getElementById('cauAvatarName').textContent = name.trim() || 'New User';
}

// open and close modal
document.getElementById('addUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeAddUserModalFn();
});
document.getElementById('closeAddUserModal').addEventListener('click', closeAddUserModalFn);
document.getElementById('cancelAddUser').addEventListener('click', closeAddUserModalFn);

function closeAddUserModalFn() {
    document.getElementById('addUserModal').classList.remove('active');
    document.body.classList.remove('modal-open');
}

// close on escape key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('addUserModal').classList.contains('active')) {
        closeAddUserModalFn();
    }
});

// open modal from fab button
document.querySelectorAll('.js-open-add-user').forEach(function(el) {
    el.addEventListener('click', function() {
        document.getElementById('addUserModal').classList.add('active');
        document.body.classList.add('modal-open');
    });
});
</script>

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

<!-- ══════════════ EDIT USER MODAL — Premium Redesign ══════════════ -->
<div class="modal-overlay<?php echo $openEditUserModal ? ' active' : ''; ?>" id="editUserModal">
    <div class="euu-shell">

        <!-- Left panel: identity + context -->
        <div class="euu-panel">
            <div class="euu-panel-inner">
                <div class="euu-panel-orb euu-panel-orb--1"></div>
                <div class="euu-panel-orb euu-panel-orb--2"></div>
                <div class="euu-panel-orb euu-panel-orb--3"></div>

                <!-- Live avatar -->
                <div class="euu-avatar-wrap">
                    <div class="euu-avatar-ring" id="euAvatarRing">
                        <span id="euAvatarInitials">?</span>
                    </div>
                    <div class="euu-avatar-glow"></div>
                </div>

                <div class="euu-panel-identity">
                    <p class="euu-panel-eyebrow">Editing account</p>
                    <h2 class="euu-panel-name" id="euPanelName">—</h2>
                    <p class="euu-panel-meta" id="euPanelMeta">User ID #—</p>
                </div>

                <!-- Role badge -->
                <div class="euu-current-role">
                    <span class="euu-role-label">Current Role</span>
                    <span class="euu-role-pill" id="euPanelRoleBadge">—</span>
                </div>

                <!-- Change summary -->
                <div class="euu-change-summary" id="euChangeSummary">
                    <p class="euu-change-title">
                        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                        No changes yet
                    </p>
                    <ul class="euu-change-list" id="euChangeList"></ul>
                </div>
            </div>
        </div>

        <!-- Right form panel -->
        <div class="euu-form-panel">
            <div class="euu-form-header">
                <div class="euu-form-header-text">
                    <p class="euu-form-kicker">BSU Asset Manager</p>
                    <h3 class="euu-form-title">Edit User Account</h3>
                </div>
                <button type="button" class="euu-close-btn" onclick="closeEditUserModal()" aria-label="Close">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 -960 960 960" fill="currentColor"><path d="m256-200-56-56 224-224-224-224 56-56 224 224 224-224 56 56-224 224 224 224-56 56-224-224-224 224Z"/></svg>
                </button>
            </div>

            <?php if ($editUserMessage !== ''): ?>
                <div class="euu-alert <?php echo $editUserMessageType === 'error' ? 'euu-alert--error' : 'euu-alert--success'; ?>">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <?php if ($editUserMessageType === 'error'): ?>
                            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                        <?php else: ?>
                            <polyline points="20 6 9 17 4 12"/>
                        <?php endif; ?>
                    </svg>
                    <?php echo htmlspecialchars($editUserMessage); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="users.php" class="euu-form" id="editUserForm" onsubmit="return euValidate()">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                <input type="hidden" name="edit_id" id="euHiddenId" value="<?php echo htmlspecialchars($editUserData['user_id'] ?? ''); ?>">

                <!-- Row 1: Full Name + Username -->
                <div class="euu-row">
                    <div class="euu-field">
                        <label class="euu-label" for="euFullName">
                            <span class="euu-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            Full Name
                        </label>
                        <input class="euu-input" type="text" name="edit_full_name" id="euFullName"
                               placeholder="Full name" required
                               value="<?php echo htmlspecialchars($editUserData['full_name'] ?? ''); ?>"
                               oninput="euLiveUpdate()">
                    </div>
                    <div class="euu-field">
                        <label class="euu-label" for="euUsername">
                            <span class="euu-label-icon">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                            </span>
                            Username
                        </label>
                        <input class="euu-input" type="text" name="edit_username" id="euUsername"
                               placeholder="Username" required
                               value="<?php echo htmlspecialchars($editUserData['username'] ?? ''); ?>"
                               oninput="euTrackChanges()">
                    </div>
                </div>

                <!-- Row 2: Role (full width, card select) -->
                <div class="euu-field euu-field--full">
                    <label class="euu-label">
                        <span class="euu-label-icon">
                            <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        </span>
                        Role
                    </label>
                    <div class="euu-role-group" id="euRoleGroup">
                        <label class="euu-role-card" id="euu-role-admin">
                            <input type="radio" name="edit_role" id="euRoleAdmin" value="admin"
                                   <?php if (($editUserData['roles'] ?? '') === 'admin') echo 'checked'; ?>
                                   onchange="euTrackChanges(); euUpdatePanelRole('admin')">
                            <span class="euu-role-card-icon euu-role-card-icon--admin">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M9 9h6M9 12h6M9 15h4"/></svg>
                            </span>
                            <span class="euu-role-card-body">
                                <strong>Admin</strong>
                                <span>Full system access</span>
                            </span>
                            <span class="euu-role-check">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </label>
                        <label class="euu-role-card" id="euu-role-staff">
                            <input type="radio" name="edit_role" id="euRoleStaff" value="staff"
                                   <?php if (($editUserData['roles'] ?? '') === 'staff') echo 'checked'; ?>
                                   onchange="euTrackChanges(); euUpdatePanelRole('staff')">
                            <span class="euu-role-card-icon euu-role-card-icon--staff">
                                <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            </span>
                            <span class="euu-role-card-body">
                                <strong>Staff</strong>
                                <span>Limited access</span>
                            </span>
                            <span class="euu-role-check">
                                <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </span>
                        </label>
                    </div>
                </div>

                <!-- Password section -->
                <div class="euu-pw-section">
                    <div class="euu-pw-section-label">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        Change Password
                        <span class="euu-pw-optional">optional — leave blank to keep current</span>
                    </div>
                    <div class="euu-row">
                        <div class="euu-field">
                            <label class="euu-label" for="euPassword">New Password</label>
                            <div class="euu-input-wrap">
                                <input class="euu-input" type="password" name="edit_password" id="euPassword"
                                       placeholder="Enter new password"
                                       autocomplete="new-password"
                                       oninput="euCheckMatch(); euTrackChanges()">
                                <button type="button" class="euu-eye-btn" onclick="euTogglePw('euPassword','euEyeIcon')" title="Show/hide">
                                    <svg id="euEyeIcon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                        <div class="euu-field">
                            <label class="euu-label" for="euConfirmPassword">Confirm Password</label>
                            <div class="euu-input-wrap">
                                <input class="euu-input" type="password" id="euConfirmPassword"
                                       placeholder="Repeat new password"
                                       autocomplete="new-password"
                                       oninput="euCheckMatch()">
                                <button type="button" class="euu-eye-btn" onclick="euTogglePw('euConfirmPassword','euEyeIcon2')" title="Show/hide">
                                    <svg id="euEyeIcon2" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                                    </svg>
                                </button>
                            </div>
                            <div class="euu-match-hint" id="euMatchHint"></div>
                        </div>
                    </div>
                </div>

                <!-- Divider -->
                <div class="euu-divider"></div>

                <!-- Actions -->
                <div class="euu-actions">
                    <div class="euu-save-status" id="euSaveStatus">
                        <span class="euu-status-dot" id="euStatusDot"></span>
                        <span id="euStatusText">Ready to edit</span>
                    </div>
                    <div class="euu-actions-btns">
                        <button type="button" class="euu-btn-cancel" onclick="closeEditUserModal()">Cancel</button>
                        <button type="submit" name="edit_user" class="euu-btn-save" id="euSaveBtn">
                            <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/>
                                <polyline points="17 21 17 13 7 13 7 21"/>
                                <polyline points="7 3 7 8 15 8"/>
                            </svg>
                            Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>

// edit user modal setup
let _euOriginal = { fullName: '', username: '', role: '' };

function openEditUserModal(id, fullName, username, role) {
    // save original values to track changes
    _euOriginal = { fullName, username, role };

    // fill in form fields
    document.getElementById('euHiddenId').value  = id;
    document.getElementById('euFullName').value  = fullName;
    document.getElementById('euUsername').value  = username;
    document.getElementById('euPassword').value  = '';
    document.getElementById('euConfirmPassword').value = '';

    // clear password match hint
    document.getElementById('euMatchHint').textContent = '';
    document.getElementById('euMatchHint').className   = 'euu-match-hint';
    document.getElementById('euPassword').classList.remove('euu-err','euu-ok');
    document.getElementById('euConfirmPassword').classList.remove('euu-err','euu-ok');

    // set the role radio button
    const adminR = document.getElementById('euRoleAdmin');
    const staffR = document.getElementById('euRoleStaff');
    adminR.checked = (role === 'admin');
    staffR.checked = (role === 'staff');

    // update avatar
    euUpdatePanelAvatar(fullName);

    // update name and info
    document.getElementById('euPanelName').textContent = fullName || '—';
    document.getElementById('euPanelMeta').textContent = 'User ID #' + id;

    // update role badge
    euUpdatePanelRole(role);

    // clear the change summary
    euResetChangeSummary();

    // update status
    euSetStatus('ready');

    // open the modal
    document.getElementById('editUserModal').classList.add('active');
    document.body.classList.add('modal-open');
}

function closeEditUserModal() {
    document.getElementById('editUserModal').classList.remove('active');
    document.body.classList.remove('modal-open');
}

// helpers for the side panel
function euUpdatePanelAvatar(name) {
    const parts    = (name || '').trim().split(/\s+/).filter(Boolean);
    const initials = ((parts[0]?.[0] || '') + (parts[1]?.[0] || parts[0]?.[1] || '')).toUpperCase() || '?';
    document.getElementById('euAvatarInitials').textContent = initials;
}

function euUpdatePanelRole(role) {
    const badge = document.getElementById('euPanelRoleBadge');
    badge.textContent = role ? (role.charAt(0).toUpperCase() + role.slice(1)) : '—';
    badge.className   = 'euu-role-pill euu-role-pill--' + (role || 'none');
}

// update avatar as name changes
function euLiveUpdate() {
    const name = document.getElementById('euFullName').value;
    euUpdatePanelAvatar(name);
    document.getElementById('euPanelName').textContent = name || '—';
    euTrackChanges();
}

// track what fields changed
function euTrackChanges() {
    const curName = document.getElementById('euFullName').value.trim();
    const curUser = document.getElementById('euUsername').value.trim();
    const curRole = document.querySelector('input[name="edit_role"]:checked')?.value || '';
    const curPw   = document.getElementById('euPassword').value;

    const changes = [];
    if (curName !== _euOriginal.fullName) changes.push('Name updated');
    if (curUser !== _euOriginal.username) changes.push('Username changed');
    if (curRole !== _euOriginal.role)     changes.push('Role changed → ' + (curRole || '—'));
    if (curPw !== '')                     changes.push('New password set');

    const summary   = document.getElementById('euChangeSummary');
    const titleEl   = summary.querySelector('.euu-change-title');
    const listEl    = document.getElementById('euChangeList');

    if (changes.length === 0) {
        titleEl.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> No changes yet';
        listEl.innerHTML  = '';
        summary.classList.remove('euu-change-summary--active');
        euSetStatus('ready');
    } else {
        titleEl.innerHTML = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><path d="M20.49 15a9 9 0 1 1-.08-4.73"/></svg> ' + changes.length + ' change' + (changes.length > 1 ? 's' : '') + ' pending';
        listEl.innerHTML  = changes.map(c => '<li>' + c + '</li>').join('');
        summary.classList.add('euu-change-summary--active');
        euSetStatus('changed');
    }
}

function euResetChangeSummary() {
    const summary = document.getElementById('euChangeSummary');
    summary.querySelector('.euu-change-title').innerHTML =
        '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg> No changes yet';
    document.getElementById('euChangeList').innerHTML = '';
    summary.classList.remove('euu-change-summary--active');
}

function euSetStatus(state) {
    const dot  = document.getElementById('euStatusDot');
    const text = document.getElementById('euStatusText');
    if (state === 'ready') {
        dot.className  = 'euu-status-dot euu-status-dot--ready';
        text.textContent = 'Ready to edit';
    } else if (state === 'changed') {
        dot.className  = 'euu-status-dot euu-status-dot--changed';
        text.textContent = 'Unsaved changes';
    } else if (state === 'error') {
        dot.className  = 'euu-status-dot euu-status-dot--error';
        text.textContent = 'Fix errors above';
    }
}

// check if passwords match
function euCheckMatch() {
    const pw   = document.getElementById('euPassword').value;
    const cpw  = document.getElementById('euConfirmPassword').value;
    const hint = document.getElementById('euMatchHint');
    const pwEl = document.getElementById('euPassword');
    const cpwEl= document.getElementById('euConfirmPassword');
    const btn  = document.getElementById('euSaveBtn');

    if (pw === '' && cpw === '') {
        hint.textContent = '';
        hint.className   = 'euu-match-hint';
        pwEl.classList.remove('euu-err','euu-ok');
        cpwEl.classList.remove('euu-err','euu-ok');
        btn.disabled = false;
        return;
    }
    if (cpw === '') {
        hint.textContent = '';
        hint.className   = 'euu-match-hint';
        return;
    }
    if (pw === cpw) {
        hint.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg> Passwords match';
        hint.className = 'euu-match-hint match';
        pwEl.classList.replace('euu-err','euu-ok') || pwEl.classList.add('euu-ok');
        cpwEl.classList.replace('euu-err','euu-ok') || cpwEl.classList.add('euu-ok');
        btn.disabled = false;
    } else {
        hint.innerHTML = '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords do not match';
        hint.className = 'euu-match-hint no-match';
        pwEl.classList.replace('euu-ok','euu-err') || pwEl.classList.add('euu-err');
        cpwEl.classList.replace('euu-ok','euu-err') || cpwEl.classList.add('euu-err');
        btn.disabled = true;
        euSetStatus('error');
    }
}

function euValidate() {
    const pw  = document.getElementById('euPassword').value;
    const cpw = document.getElementById('euConfirmPassword').value;
    if (pw !== '' && pw !== cpw) {
        document.getElementById('euMatchHint').innerHTML =
            '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg> Passwords do not match';
        document.getElementById('euMatchHint').className = 'euu-match-hint no-match';
        return false;
    }
    return true;
}

function euTogglePw(inputId, iconId) {
    const inp  = document.getElementById(inputId);
    const icon = document.getElementById(iconId);
    const show = inp.type === 'password';
    inp.type = show ? 'text' : 'password';
    icon.innerHTML = show
        ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>'
        : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
}

// close on overlay click or escape
document.getElementById('editUserModal').addEventListener('click', function(e) {
    if (e.target === this) closeEditUserModal();
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && document.getElementById('editUserModal').classList.contains('active')) {
        closeEditUserModal();
    }
});

<?php if ($openEditUserModal && $editUserData): ?>
openEditUserModal(
    <?php echo (int)$editUserData['user_id']; ?>,
    <?php echo json_encode($editUserData['full_name']); ?>,
    <?php echo json_encode($editUserData['username']); ?>,
    <?php echo json_encode($editUserData['roles']); ?>
);
<?php endif; ?>
</script>
<script>
const notifBtn = document.getElementById('notifBtn');
const notifDropdown = document.getElementById('notifDropdown');
notifBtn.addEventListener('click', (e) => {
    e.stopPropagation();
    notifDropdown.classList.toggle('open');
    if (typeof profileDropdown !== 'undefined') profileDropdown.classList.remove('open');
});
notifDropdown.addEventListener('click', (e) => e.stopPropagation());
document.addEventListener('click', () => notifDropdown.classList.remove('open'));
</script>
</body>
</html>