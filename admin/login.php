<?php
session_start();
include('../database/db.php');

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'admin') {
    header('Location: dashboard.php');
    exit();
}

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

$error = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);

    $attemptKey = 'admin_' . strtolower($username);
    $attempts   = $_SESSION['login_attempts'][$attemptKey] ?? ['count' => 0, 'last' => 0];

    $lockoutSeconds = 300;
    $maxAttempts    = 5;

    if ($attempts['count'] >= $maxAttempts && (time() - $attempts['last']) < $lockoutSeconds) {
        $remaining = $lockoutSeconds - (time() - $attempts['last']);
        $error = "Too many failed attempts. Please wait " . ceil($remaining / 60) . " minute(s) before trying again.";
    } else {
        if ((time() - $attempts['last']) >= $lockoutSeconds) {
            $_SESSION['login_attempts'][$attemptKey] = ['count' => 0, 'last' => 0];
        }

        $stmt = mysqli_prepare($conn, "SELECT * FROM users WHERE username = ?");
        mysqli_stmt_bind_param($stmt, 's', $username);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        mysqli_stmt_close($stmt);

        if ($result && mysqli_num_rows($result) === 1) {
            $user = mysqli_fetch_assoc($result);

            if (password_verify($_POST['password'], $user['password'])) {
                if ($user['roles'] === 'staff') {
                    $error = "Staff must log in through the Faculty/Staff Portal.";
                } elseif ($user['roles'] === 'admin') {
                    session_regenerate_id(true);
                    unset($_SESSION['login_attempts'][$attemptKey]);
                    $_SESSION['user_id']   = $user['user_id'];
                    $_SESSION['username']  = $user['username'];
                    $_SESSION['role']      = $user['roles'];
                    $_SESSION['full_name'] = $user['full_name'];
                    header('Location: dashboard.php');
                    exit();
                } else {
                    $error = "You do not have permission to access this area.";
                }
            } else {
                $_SESSION['login_attempts'][$attemptKey] = ['count' => $attempts['count'] + 1, 'last' => time()];
                $error = "Invalid username or password.";
            }
        } else {
            $_SESSION['login_attempts'][$attemptKey] = ['count' => $attempts['count'] + 1, 'last' => time()];
            $error = "Invalid username or password.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — Asset Manager</title>
    <link rel="stylesheet" href="../css/admin/login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    
</head>
<body>

    <div class="bg-photo">
        <img src="../img/campus.jpg" alt="BSU Campus — College of Engineering and Computing Sciences">
    </div>
    <div class="bg-overlay"></div>
    <div class="bg-vignette"></div>

    <div class="corner corner-tl"></div>
    <div class="corner corner-br"></div>

    <div class="login-wrapper">

        <div class="login-panel">

            <div class="login-brand">
                <div class="login-brand-seal">
                    <img class="login-brand-logo" src="../img/bsu.png" alt="BSU Seal">
                </div>
                <div>
                    <div class="login-brand-name">Batangas State University</div>
                    <div class="login-brand-tagline">The National Engineering University</div>
                </div>
            </div>

            <div class="login-divider">
                <div class="login-divider-line"></div>
                <div class="login-divider-diamond"></div>
            </div>

            <div class="login-heading">
                <h2>Admin <span class="portal">Portal</span></h2>
                <p class="subtitle">Asset Manager System</p>
            </div>

            <?php if ($error !== ''): ?>
                <p class="error"><?= htmlspecialchars($error) ?></p>
            <?php endif; ?>

            <form class="login-form" method="POST" action="" autocomplete="off">
                <div class="field-group">
                    <label for="username">Username</label>
                    <div class="input-wrap">
                        <input type="text" id="username" name="username"
                               placeholder="Enter your username"
                               required autocomplete="username">
                        <div class="input-bar"></div>
                    </div>
                </div>
                <div class="field-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <input type="password" id="password" name="password"
                               placeholder="Enter your password"
                               required autocomplete="current-password">
                        <button type="button" class="eye-toggle" id="eyeToggle" aria-label="Show password" tabindex="-1">
                            <svg id="eyeIcon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                        <div class="input-bar"></div>
                        <script>
                        (function(){
                            var btn = document.getElementById('eyeToggle');
                            var inp = document.getElementById('password');
                            var icon = document.getElementById('eyeIcon');
                            var eyeOpen = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
                            var eyeOff  = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/>';
                            btn.addEventListener('click', function(){
                                var shown = inp.type === 'text';
                                inp.type = shown ? 'password' : 'text';
                                icon.innerHTML = shown ? eyeOpen : eyeOff;
                                btn.setAttribute('aria-label', shown ? 'Show password' : 'Hide password');
                            });
                        })();
                        </script>
                    </div>
                </div>
                <button type="submit" name="login" class="btn-login">Sign In</button>
            </form>

            <div class="login-footer">
                <a href="../index.php">← Back to Main Portal</a>
            </div>

        </div>

        <div class="login-photo-col">
            <div class="photo-caption">
                <div class="photo-caption-label">Campus</div>
                <div class="photo-caption-rule"></div>
                <div class="photo-caption-name">Batangas State University JPLPC Malvar</div>
            </div>
        </div>

    </div>

</body>
</html>