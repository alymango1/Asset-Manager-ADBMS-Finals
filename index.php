<?php
session_start();

// Redirect already-logged-in users to their portal
if (isset($_SESSION['user_id'])) {
    if ($_SESSION['role'] === 'admin') {
        header("Location: admin/dashboard.php");
    } else {
        header("Location: faculty/dashboard.php");
    }
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asset Manager - Select Portal</title>
    <link rel="icon" href="img/favicon-96.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Open+Sans:wght@300..800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Open Sans', sans-serif;
            background: linear-gradient(135deg, #1a1a2e 0%, #16213e 50%, #0f3460 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .landing-wrap {
            text-align: center;
            color: #fff;
            padding: 40px 20px;
        }
        .logo-row {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 16px;
            margin-bottom: 12px;
        }
        .logo-row img { width: 64px; height: 64px; object-fit: contain; }
        .logo-row h1 { font-size: 2rem; font-weight: 700; }
        .subtitle { color: rgba(255,255,255,0.65); margin-bottom: 48px; font-size: 1rem; }
        .portals {
            display: flex;
            gap: 24px;
            justify-content: center;
            flex-wrap: wrap;
        }
        .portal-card {
            background: rgba(255,255,255,0.07);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 16px;
            padding: 40px 48px;
            text-decoration: none;
            color: #fff;
            transition: all 0.2s;
            min-width: 220px;
            backdrop-filter: blur(10px);
        }
        .portal-card:hover {
            background: rgba(255,255,255,0.14);
            transform: translateY(-4px);
            box-shadow: 0 12px 40px rgba(0,0,0,0.3);
        }
        .portal-card svg { margin-bottom: 16px; }
        .portal-card h2 { font-size: 1.2rem; font-weight: 700; margin-bottom: 6px; }
        .portal-card p { font-size: 0.85rem; color: rgba(255,255,255,0.6); }
        .portal-card.admin { border-color: rgba(220,53,69,0.5); }
        .portal-card.admin:hover { border-color: #dc3545; background: rgba(220,53,69,0.15); }
        .portal-card.faculty { border-color: rgba(40,167,69,0.5); }
        .portal-card.faculty:hover { border-color: #28a745; background: rgba(40,167,69,0.15); }
    </style>
</head>
<body>
<div class="landing-wrap">
    <div class="logo-row">
        <img src="img/bsu.png" alt="BSU Logo">
        <h1>Asset Manager</h1>
    </div>
    <p class="subtitle">Select your portal to continue</p>
    <div class="portals">
        <a href="admin/login.php" class="portal-card admin">
            <svg xmlns="http://www.w3.org/2000/svg" height="48px" viewBox="0 -960 960 960" width="48px" fill="#dc3545">
                <path d="M480-80q-140-35-230-162.5T160-522v-238l320-120 320 120v238q0 152-90 279.5T480-80Z"/>
            </svg>
            <h2>Admin Portal</h2>
            <p>Manage equipment &amp; users</p>
        </a>
        <a href="faculty/login.php" class="portal-card faculty">
            <svg xmlns="http://www.w3.org/2000/svg" height="48px" viewBox="0 -960 960 960" width="48px" fill="#28a745">
                <path d="M480-120 200-272v-240L40-600l440-240 440 240v320h-80v-276l-80 44v240L480-120Zm0-332 274-148-274-148-274 148 274 148Zm0 241 200-108v-151L480-360 280-471v151l200 109Zm0-241Zm0 90Zm0 0Z"/>
            </svg>
            <h2>Faculty/Staff Portal</h2>
            <p>Browse &amp; reserve equipment</p>
        </a>
    </div>
</div>
</body>
</html>
