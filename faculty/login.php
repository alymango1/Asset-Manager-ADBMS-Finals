<?php
session_start();
include('../database/db.php');

if (isset($_SESSION['user_id']) && isset($_SESSION['role']) && $_SESSION['role'] === 'staff') {
    header('Location: dashboard.php');
    exit();
}

if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = [];
}

$error = '';

if (isset($_POST['login'])) {
    $username = trim($_POST['username']);

    $attemptKey = 'staff_' . strtolower($username);
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
                if ($user['roles'] === 'admin') {
                    $error = "Administrators must log in through the Admin Portal.";
                } elseif ($user['roles'] === 'staff') {
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
    <title>Faculty Login — Asset Manager</title>
    <link rel="stylesheet" href="../css/faculty/login.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,500;0,600;1,300;1,400&family=Outfit:wght@300;400;500;600&display=swap" rel="stylesheet">
    
</head>
<body>

    <div class="bg-photo">
        <img src="../img/campus.jpg" alt="BSU Campus — College of Engineering and Computing Sciences">
    </div>
    <div class="bg-overlay"></div>
    <div class="bg-glow"></div>
    <div class="bg-scanlines"></div>

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
                <h2>Faculty <span class="portal">Portal</span></h2>
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
                        <button type="button" class="eye-toggle" id="togglePassword" aria-label="Show password">
                            <svg id="eyeOpen" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true">
                                <path d="M16 8s-3-5.5-8-5.5S0 8 0 8s3 5.5 8 5.5S16 8 16 8zM1.173 8a13.133 13.133 0 0 1 1.66-2.043C4.12 4.668 5.88 3.5 8 3.5c2.12 0 3.879 1.168 5.168 2.457A13.133 13.133 0 0 1 14.828 8c-.058.087-.122.183-.195.288-.335.48-.83 1.12-1.465 1.755C11.879 11.332 10.12 12.5 8 12.5c-2.12 0-3.879-1.168-5.168-2.457A13.133 13.133 0 0 1 1.172 8z"/>
                                <path d="M8 5.5a2.5 2.5 0 1 0 0 5 2.5 2.5 0 0 0 0-5zM4.5 8a3.5 3.5 0 1 1 7 0 3.5 3.5 0 0 1-7 0z"/>
                            </svg>
                            <svg id="eyeClosed" xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16" aria-hidden="true" style="display: none;">
                                <path d="M13.359 11.238 15 12.879l-.707.707-14-14 .707-.707 2.223 2.223A9.83 9.83 0 0 1 8 2.5c5 0 8 5.5 8 5.5a16.28 16.28 0 0 1-2.641 3.238zM11.297 9.176l-1.404-1.404a2.5 2.5 0 0 1-3.665-3.665L4.02 1.899A8.06 8.06 0 0 0 1.173 8c.058.087.122.183.195.288.335.48.83 1.12 1.465 1.755C4.121 11.332 5.88 12.5 8 12.5a7.96 7.96 0 0 0 3.297-.676z"/>
                                <path d="M8 5.5c.412 0 .8.102 1.14.282l-2.922-2.922A3.5 3.5 0 0 0 8 12.5c.526 0 1.026-.115 1.475-.321l-1.44-1.44A2.5 2.5 0 0 1 8 5.5z"/>
                            </svg>
                        </button>
                        <div class="input-bar"></div>
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

    <script>
        const passwordInput = document.getElementById('password');
        const togglePassword = document.getElementById('togglePassword');
        const eyeOpen = document.getElementById('eyeOpen');
        const eyeClosed = document.getElementById('eyeClosed');

        if (passwordInput && togglePassword && eyeOpen && eyeClosed) {
            togglePassword.addEventListener('click', () => {
                const isHidden = passwordInput.type === 'password';
                passwordInput.type = isHidden ? 'text' : 'password';
                eyeOpen.style.display = isHidden ? 'none' : 'block';
                eyeClosed.style.display = isHidden ? 'block' : 'none';
                togglePassword.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
            });
        }
    </script>

</body>
</html>