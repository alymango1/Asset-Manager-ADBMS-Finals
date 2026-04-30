<?php
// Pending reservations count
$pendingCount = 0;
if (isset($conn)) {
    $pendingQuery = mysqli_query($conn, "SELECT COUNT(*) AS total FROM reservations WHERE status = 'pending'");
    if ($pendingQuery) {
        $pendingCount = mysqli_fetch_assoc($pendingQuery)['total'];
    }
}

// Current page
$currentPage = basename($_SERVER['PHP_SELF']);

// Logged-in user name
$sidebarNameRaw = $_SESSION['full_name'] ?? ($_SESSION['username'] ?? 'User');
$sidebarNameRaw = trim(preg_replace('/\s+/', ' ', (string)$sidebarNameRaw));

// Normalize name casing for display
$sidebarName = $sidebarNameRaw !== '' ? ucwords(strtolower($sidebarNameRaw)) : 'User';

// Build initials from first/last name (or first 2 letters if single word)
$nameParts = $sidebarNameRaw !== '' ? preg_split('/\s+/', $sidebarNameRaw) : [];
$first = $nameParts[0] ?? '';
$last  = count($nameParts) > 1 ? $nameParts[count($nameParts) - 1] : '';
$sidebarInitials = strtoupper(substr($first, 0, 1) . ($last !== '' ? substr($last, 0, 1) : substr($first, 1, 1)));
$sidebarInitials = $sidebarInitials !== '' ? $sidebarInitials : 'U';
$sidebarRole     = ucfirst($_SESSION['role'] ?? 'User');
?>

<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Serif+Display:ital@0;1&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../css/admin/sidebar.css">



<aside class="sidebar">

    <!-- Logo -->
    <div class="sb-logo">
        <img src="../img/bsu.png" alt="BSU Logo" class="sb-logo-img">
        <div class="sb-logo-info">
            <span class="sb-logo-name">Asset Manager</span>
            <span class="sb-logo-sub">Batangas State University</span>
        </div>
    </div>

    <!-- User card -->
    <div class="sb-user">
        <div class="sb-user-avatar"><?= $sidebarInitials ?></div>
        <div class="sb-user-info">
            <div class="sb-user-tag">Logged in as</div>
            <div class="sb-user-name"><?= htmlspecialchars($sidebarName) ?></div>
            <div class="sb-user-role"><?= htmlspecialchars($sidebarRole) ?></div>
        </div>
    </div>

    <div class="sb-section-label">Main Menu</div>

    <!-- Navigation -->
    <nav class="sb-nav">
        <a href="../admin/dashboard.php" class="<?= $currentPage === 'dashboard.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M520-600v-240h320v240H520ZM120-440v-400h320v400H120Zm400 320v-400h320v400H520Zm-400 0v-240h320v240H120Z"/></svg>
            Dashboard
        </a>
        <a href="../admin/users.php" class="<?= $currentPage === 'users.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M367-527q-47-47-47-113t47-113q47-47 113-47t113 47q47 47 47 113t-47 113q-47 47-113 47t-113-47ZM160-160v-112q0-34 17.5-62.5T224-378q62-31 126-46.5T480-440q66 0 130 15.5T736-378q29 15 46.5 43.5T800-272v112H160Z"/></svg>
            Users
        </a>
        <a href="../admin/equipments.php" class="<?= $currentPage === 'equipments.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M756-120 537-339l84-84 219 219-84 84Zm-552 0-84-84 276-276-68-68-28 28-51-51v82l-28 28-121-121 28-28h82l-50-50 142-142q20-20 43-29t47-9q24 0 47 9t43 29l-92 92 50 50-28 28 68 68 90-90q-4-11-6.5-23t-2.5-24q0-59 40.5-99.5T701-841q15 0 28.5 3t27.5 9l-99 99 72 72 99-99q7 14 9.5 27.5T841-701q0 59-40.5 99.5T701-561q-12 0-24-2t-23-7L204-120Z"/></svg>
            Equipments
        </a>
        <a href="../admin/in_use.php" class="<?= $currentPage === 'in_use.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M440-160q-121-15-200.5-105.5T160-480q0-66 26-126t72-106l57 57q-38 34-56.5 79T240-480q0 88 56 151.5T440-257v97Zm80 0v-97q69-8 124.5-71T700-480q0-100-70-170t-170-70h-3l44 44-56 56-140-140 140-140 56 57-44 43h3q134 0 227 93t93 227q0 121-79.5 211.5T520-160Z"/></svg>
            In-Use / Returns
        </a>
        <a href="../admin/reservation.php" class="<?= $currentPage === 'reservation.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h168q13-36 43.5-58t68.5-22q38 0 68.5 22t43.5 58h168q33 0 56.5 23.5T840-760v560q0 33-23.5 56.5T760-120H200Z"/></svg>
            Reservations
            <?php if ($pendingCount > 0): ?>
            <span class="sb-badge"><?= $pendingCount ?></span>
            <?php endif; ?>
        </a>
        <a href="../admin/transactions.php" class="<?= $currentPage === 'transactions.php' ? 'active' : '' ?>">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M400-400h160v-80H400v80Zm0-120h320v-80H400v80Zm0-120h320v-80H400v80Zm-80 400q-33 0-56.5-23.5T240-320v-480q0-33 23.5-56.5T320-880h480q33 0 56.5 23.5T880-800v480q0 33-23.5 56.5T800-240H320Z"/></svg>
            Transactions
        </a>
    </nav>

    <!-- Logout -->
    <div class="sb-footer">
        <a href="#" onclick="openLogoutModal(); return false;">
            <svg xmlns="http://www.w3.org/2000/svg" height="18px" viewBox="0 -960 960 960" width="18px" fill="currentColor"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
            Logout
        </a>
    </div>

    <!-- Logout Modal -->
<div id="logout-overlay" class="logout-overlay" role="dialog" aria-modal="true" aria-hidden="true"
     style="position:fixed; inset:0; background:rgba(80,19,19,0.18); backdrop-filter:blur(2px); z-index:9999; align-items:center; justify-content:center;">
  <div class="logout-modal-card" style="background:#fff; border-radius:20px; width:100%; max-width:420px; margin:1rem; border:0.5px solid #f0c8c8; overflow:hidden; font-family:'DM Sans',sans-serif;">
    
    <div style="height:4px; background:linear-gradient(90deg,#A32D2D,#E24B4A,#F09595);"></div>

    <div style="padding:2rem 2rem 1.25rem; display:flex; flex-direction:column; align-items:center; gap:1rem; text-align:center; border-bottom:0.5px solid #f7e8e8;">
      <div style="width:64px; height:64px; border-radius:50%; background:#FCEBEB; border:1.5px solid #F7C1C1; display:flex; align-items:center; justify-content:center;">
        <svg xmlns="http://www.w3.org/2000/svg" height="28" viewBox="0 -960 960 960" width="28" fill="#A32D2D"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
      </div>
      <div>
        <div style="font-family:'DM Serif Display',serif; font-size:22px; color:#1a1a1a; letter-spacing:-0.3px;">Sign out of Asset Manager</div>
        <p style="margin-top:6px; font-size:14px; color:#888780; line-height:1.6; max-width:290px;">You are about to sign out. Unsaved changes may be lost.</p>
      </div>
    </div>

    <div style="padding:1.25rem 2rem;">
      <div style="background:#fdf5f5; border:0.5px solid #f0d0d0; border-radius:12px; padding:0.875rem 1rem; display:flex; align-items:center; gap:12px;">
        <div style="width:36px; height:36px; border-radius:50%; background:#F7C1C1; color:#791F1F; font-weight:500; font-size:13px; display:flex; align-items:center; justify-content:center; flex-shrink:0;"><?= $sidebarInitials ?></div>
        <div style="flex:1; min-width:0;">
          <div style="font-size:14px; font-weight:500; color:#1a1a1a;"><?= htmlspecialchars($sidebarName) ?></div>
          <div style="font-size:12px; color:#888780; margin-top:1px;"><?= htmlspecialchars($sidebarRole) ?> · Batangas State University</div>
        </div>
        <div style="background:#FCEBEB; color:#A32D2D; font-size:11px; font-weight:500; padding:3px 8px; border-radius:20px; border:0.5px solid #F7C1C1;">Active</div>
      </div>
    </div>

    <div style="padding:0.25rem 2rem 1.75rem; display:flex; flex-direction:column; gap:10px;">
      <a href="logout.php" style="display:flex; align-items:center; justify-content:center; gap:8px; background:#A32D2D; color:#fff; padding:13px 20px; border-radius:10px; font-size:14px; font-weight:500; text-decoration:none; letter-spacing:0.1px;">
        <svg xmlns="http://www.w3.org/2000/svg" height="16" viewBox="0 -960 960 960" width="16" fill="#fff"><path d="M200-120q-33 0-56.5-23.5T120-200v-560q0-33 23.5-56.5T200-840h280v80H200v560h280v80H200Zm440-160-55-58 102-102H360v-80h327L585-622l55-58 200 200-200 200Z"/></svg>
        Yes, sign me out
      </a>
      <div style="text-align:center; font-size:12px; color:#B4B2A9;">or</div>
      <button onclick="closeLogoutModal()" style="background:#fff; color:#444441; border:0.5px solid #D3D1C7; padding:13px 20px; border-radius:10px; font-size:14px; font-weight:500; cursor:pointer; font-family:'DM Sans',sans-serif;">Stay signed in</button>
      <p style="text-align:center; font-size:11.5px; color:#B4B2A9; line-height:1.5;">You will be signed out and sent back to the <span style="color:#A32D2D; font-weight:500;">login page</span>.</p>
    </div>

  </div>
</div>

<script>
  function openLogoutModal() {
    const el = document.getElementById('logout-overlay');
    el.classList.add('is-open');
    el.setAttribute('aria-hidden', 'false');
  }
  function closeLogoutModal() {
    const el = document.getElementById('logout-overlay');
    el.classList.remove('is-open');
    el.setAttribute('aria-hidden', 'true');
  }
  document.getElementById('logout-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeLogoutModal();
  });
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeLogoutModal();
  });

  // Scroll sidebar to the active link
  document.addEventListener('DOMContentLoaded', function() {
    const nav = document.querySelector('.sb-nav');
    const active = document.querySelector('.sb-nav a.active');
    if (!nav || !active) return;
    requestAnimationFrame(() => {
      try {
        active.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
      } catch (_) {
        // Basic fallback
        active.scrollIntoView();
      }
    });
  });
</script>

</aside>
