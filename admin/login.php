<?php
session_start();
include('../database/db.php'); 

if (isset($_POST['login'])) {
    $username = mysqli_real_escape_string($conn, $_POST['username']);
    $password = $_POST['password'];

    $query = "SELECT * FROM users WHERE username='$username'";
    $result = mysqli_query($conn, $query);

    if ($result && mysqli_num_rows ($result) == 1){
        $user = mysqli_fetch_assoc($result);
        
        if ($password == $user['password']) {
        if ($user['roles'] == 'staff') {
            $error = "Staff must log in through the Faculty/Staff Portal.";
        }
        else if ($user['roles'] == 'admin') {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['roles'];
            $_SESSION['full_name'] = $user['full_name'];
            
            header('Location: dashboard.php');
            exit();

        } else {
            $error = "You do have not permission to access this area.";
        }
        } else {
            $error = "Invalid password.";
        }
    } else {
        $error = "Account not found.";
    }
    }

?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Admin Login - Asset Manager</title>
    <link rel="stylesheet" href="../css/style.css"> <style>
        /* Simple styling to match the red theme */
        body { font-family: Arial, sans-serif; background: #f4f4f4; display: flex; justify-content: center; align-items: center; height: 100vh; }
        .login-box { background: #fff; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 350px; border-top: 5px solid #d9534f; }
        h2 { text-align: center; color: #333; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #d9534f; color: white; border: none; border-radius: 4px; cursor: pointer; }
        button:hover { background: #c9302c; }
        .error { color: red; text-align: center; font-size: 14px; }
    </style>
    <link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Funnel+Sans:ital,wght@0,300..800;1,300..800&family=Google+Sans:ital,opsz,wght@0,17..18,400..700;1,17..18,400..700&family=Mona+Sans:ital,wght@0,200..900;1,200..900&family=Open+Sans:ital,wght@0,300..800;1,300..800&display=swap" rel="stylesheet">
</head>
<body>

<div class="login-box">
    <h2>Admin Login</h2>
    
    <?php if(isset($error)) { echo '<p class="error">'.$error.'</p>'; } ?>
    
    <form method="POST" action="">
        <input type="text" name="username" placeholder="Username" required>
        <input type="password" name="password" placeholder="Password" required>
        <button type="submit" name="login">Login</button>
    </form>
    
    <p style="text-align:center; font-size: 12px; margin-top: 15px;">
        <a href="../index.php"style="color: #666; ">Back to Main</a>
    </p>
</div>

</body>
</html>