<?php
session_start();
include('../database/db.php');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php");
    exit();
}

// CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$name = "User";

if (isset($_SESSION['full_name'])) {
    $fullName = $_SESSION['full_name'];
    $nameParts = explode(" ", trim($fullName));
    $name = $nameParts[0];
}

$message = "";

if (isset($_POST['submit'])) {

    // CSRF check
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $message = "Invalid request. Please try again.";
    } else {
        $fullName    = trim($_POST['full_name']);
        $username    = trim($_POST['username']);
        $rawPassword = trim($_POST['password']);
        $role        = $_POST['role'];

        // Validate role
        $allowedRoles = ['admin', 'staff'];
        if (!in_array($role, $allowedRoles, true)) {
            $message = "Invalid role selected.";
        } elseif (empty($fullName) || empty($username) || empty($rawPassword)) {
            $message = "All fields are required.";
        } elseif (strlen($rawPassword) < 8) {
            $message = "Password must be at least 8 characters.";
        } else {
            $password = password_hash($rawPassword, PASSWORD_BCRYPT);
            // Insert user
            $stmt = mysqli_prepare($conn,
                "INSERT INTO users (full_name, username, password, roles) VALUES (?, ?, ?, ?)"
            );
            mysqli_stmt_bind_param($stmt, 'ssss', $fullName, $username, $password, $role);

            if (mysqli_stmt_execute($stmt)) {
                $message = "User added successfully!";
                // Refresh token
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            } else {
                $message = "Error: " . mysqli_stmt_error($stmt);
            }
            mysqli_stmt_close($stmt);
        }
    }
}
?>

<!DOCTYPE html>
<html>
<head>
    <title>Add User</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/admin/style.css">
</head>

<body>

<?php include('sidebar.php');?>

<div class="header">
        <h1>Dashboard</h1>

        <div class="header-right">
        <button class="profile_btn" id="profileBtn">
            <svg xmlns="http://www.w3.org/2000/svg" height="34px" viewBox="0 -960 960 960" width="40px" fill="#FFFFFF"><path d="M226-262q59-42.33 121.33-65.5 62.34-23.17 132.67-23.17 70.33 0 133 23.17T734.67-262q41-49.67 59.83-103.67T813.33-480q0-141-96.16-237.17Q621-813.33 480-813.33t-237.17 96.16Q146.67-621 146.67-480q0 60.33 19.16 114.33Q185-311.67 226-262Zm155.83-224.5Q342-526.33 342-584.67q0-58.33 39.83-98.16 39.84-39.84 98.17-39.84t98.17 39.84Q618-643 618-584.67q0 58.34-39.83 98.17-39.84 39.83-98.17 39.83t-98.17-39.83ZM480-80q-82.33 0-155.33-31.5-73-31.5-127.34-85.83Q143-251.67 111.5-324.67T80-480q0-83 31.5-155.67 31.5-72.66 85.83-127Q251.67-817 324.67-848.5T480-880q83 0 155.67 31.5 72.66 31.5 127 85.83 54.33 54.34 85.83 127Q880-563 880-480q0 82.33-31.5 155.33-31.5 73-85.83 127.34-54.34 54.33-127 85.83Q563-80 480-80Zm105-82.5q50.67-15.83 97.67-52.17-47-33.66-98-51.5Q533.67-284 480-284t-104.67 17.83q-51 17.84-98 51.5 47 36.34 97.67 52.17 50.67 15.83 105 15.83t105-15.83Zm-53.67-370.83q20-20 20-51.34 0-31.33-20-51.33T480-656q-31.33 0-51.33 20t-20 51.33q0 31.34 20 51.34 20 20 51.33 20t51.33-20ZM480-584.67Zm0 369.34Z"/></svg>        
        </button>
        </div>

        <!-- Dropdown -->
        <div class="dropdown" id="dropdownMenu">
            <p>Greetings, <?php echo htmlspecialchars($name ?? ''); ?>!</p>
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

// Close dropdown on outside click
document.addEventListener("click", function () {
    menu.classList.remove("active");
});
</script>

<div class="main">

    <div class="table-wrap">

        <h2>Create New User</h2>
        <p class="subtitle">Add a new admin or staff account to the system</p>

        <?php if ($message != "") { ?>
            <div class="message-box">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php } ?>

        <style>
            .au-pw-wrap { position:relative; display:flex; align-items:center; }
            .au-pw-wrap input { padding-right:36px !important; width:100%; }
            .au-pw-toggle { position:absolute; right:10px; background:none; border:none; cursor:pointer; color:#b89898; padding:0; display:flex; align-items:center; transition:color 0.15s; }
            .au-pw-toggle:hover { color:#C41E3A; }
            .au-match-hint { font-size:11px; margin-top:4px; min-height:14px; font-weight:400; transition:color 0.2s; }
            .au-match-hint.match    { color:#38a169; }
            .au-match-hint.no-match { color:#C41E3A; }
            input.au-error { border-color:#e53e3e !important; box-shadow:0 0 0 3px rgba(229,62,62,0.08) !important; }
            input.au-ok    { border-color:#38a169 !important; box-shadow:0 0 0 3px rgba(56,161,105,0.08) !important; }
            .btn-danger {
                background: linear-gradient(135deg, #C41E3A 0%, #8B0000 100%);
                color:#fff; border:none; padding:0.65rem 1.4rem; border-radius:8px;
                font-size:13.5px; font-weight:500; cursor:pointer;
                box-shadow:0 2px 8px rgba(196,30,58,0.25);
                transition:all 0.15s ease;
                display:inline-flex; align-items:center; gap:7px;
                position:relative; overflow:hidden; font-family:inherit;
            }
            .btn-danger::before { content:""; position:absolute; top:0; left:-100%; width:60%; height:100%;  transition:left 0.45s ease; }
            .btn-danger:hover::before { left:150%; }
            .btn-danger:hover { background:linear-gradient(135deg,#d42040 0%,#9B1010 100%); box-shadow:0 4px 14px   rgba(196,30,58,0.32); transform:translateY(-1px); }
            .btn-danger:active { transform:translateY(0) scale(0.99); }
            .btn-danger:disabled { opacity:0.5; cursor:not-allowed; transform:none; }
        </style>

        <form method="POST" class="form-grid" id="addUserForm" onsubmit="return auValidate()">
            <!-- CSRF field -->
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="input-group">
                <label>Full Name</label>
                <input type="text" name="full_name" placeholder="Enter full name" required>
            </div>

            <div class="input-group">
                <label>Username</label>
                <input type="text" name="username" placeholder="Enter username" required>
            </div>

            <div class="input-group">
                <label>Password</label>
                <div class="au-pw-wrap">
                    <input type="password" name="password" id="auPassword"
                           placeholder="Min. 8 characters" minlength="8" required
                           oninput="auCheckMatch()">
                    <button type="button" class="au-pw-toggle" onclick="auToggle('auPassword','auEye1')" title="Show/hide">
                        <svg id="auEye1" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="input-group">
                <label>Confirm Password</label>
                <div class="au-pw-wrap">
                    <input type="password" id="auConfirm"
                           placeholder="Repeat password"
                           oninput="auCheckMatch()">
                    <button type="button" class="au-pw-toggle" onclick="auToggle('auConfirm','auEye2')" title="Show/hide">
                        <svg id="auEye2" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <div class="au-match-hint" id="auMatchHint"></div>
            </div>

            <div class="input-group">
                <label>Role</label>
                <select name="role" required>
                    <option value="">Select Role</option>
                    <option value="admin">Admin</option>
                    <option value="staff">Staff</option>
                </select>
            </div>

            <div class="button-row">
                <button type="submit" name="submit" class="btn-danger" id="auSubmitBtn">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                        <circle cx="9" cy="7" r="4"/>
                        <line x1="19" y1="8" x2="19" y2="14"/>
                        <line x1="22" y1="11" x2="16" y2="11"/>
                    </svg>
                    Create User
                </button>
                <a href="users.php" class="btn-secondary">Cancel</a>
            </div>

        </form>

        <script>
        function auCheckMatch() {
            const pw   = document.getElementById("auPassword").value;
            const cpw  = document.getElementById("auConfirm").value;
            const hint = document.getElementById("auMatchHint");
            const pwEl = document.getElementById("auPassword");
            const cpwEl= document.getElementById("auConfirm");
            const btn  = document.getElementById("auSubmitBtn");
            if (cpw === "") {
                hint.textContent = ""; hint.className = "au-match-hint";
                pwEl.classList.remove("au-error","au-ok");
                cpwEl.classList.remove("au-error","au-ok");
                btn.disabled = false; return;
            }
            if (pw === cpw) {
                hint.textContent = "✓ Passwords match"; hint.className = "au-match-hint match";
                pwEl.classList.remove("au-error");  pwEl.classList.add("au-ok");
                cpwEl.classList.remove("au-error"); cpwEl.classList.add("au-ok");
                btn.disabled = false;
            } else {
                hint.textContent = "✕ Passwords do not match"; hint.className = "au-match-hint no-match";
                pwEl.classList.remove("au-ok");  pwEl.classList.add("au-error");
                cpwEl.classList.remove("au-ok"); cpwEl.classList.add("au-error");
                btn.disabled = true;
            }
        }
        function auValidate() {
            const pw  = document.getElementById("auPassword").value;
            const cpw = document.getElementById("auConfirm").value;
            if (pw !== cpw) {
                document.getElementById("auMatchHint").textContent = "✕ Passwords do not match";
                document.getElementById("auMatchHint").className   = "au-match-hint no-match";
                return false;
            }
            return true;
        }
        function auToggle(inputId, iconId) {
            const inp  = document.getElementById(inputId);
            const icon = document.getElementById(iconId);
            const show = inp.type === "password";
            inp.type = show ? "text" : "password";
            icon.innerHTML = show
                ? \'<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>\'
                : \'<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>\';
        }
        </script>

    </div>

</div>

</body>
</html>