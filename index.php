<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Asset Manager — Batangas State University</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,300;0,400;0,600;1,300;1,400&family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="index.css">
</head>
<body>

  <div class="bg-canvas"></div>
  <div class="bg-grid"></div>
  <div class="bg-texture"></div>
  <div class="bg-orb-1"></div>
  <div class="bg-orb-2"></div>
  <div class="deco-line"></div>

  <div class="page">

    <!-- Header -->
    <header>
      <div class="brand">
        <img src="img/bsu.png" alt="BSU Logo" class="brand-logo">
        <div class="brand-text">
          <div class="brand-name">Batangas State University</div>
          <div class="brand-sub">The National Engineering University</div>
        </div>
      </div>
      <div class="header-badge">
        <span class="header-badge-dot"></span>
        System Online
      </div>
    </header>

    <!-- Hero -->
    <main class="hero">
      <div class="hero-eyebrow">Asset Manager System</div>

      <div class="hero-title"><em>Leading Innovations, Transforming Lives, Building the Nation</em></div>
      <div class="hero-title-line2">Asset Manager</div>

      <div class="hero-divider"></div>

      <p class="hero-desc">
        A control system for Batangas State University's equipment inventory, reservations, and resource allocation — built for administrators and faculty.
      </p>

      <!-- Portal cards -->
      <div class="portals">

        <!-- Admin -->
        <a href="admin/login.php" class="portal-card admin">
          <div class="card-top">
            <div class="card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 -960 960 960" fill="#9b1c1c">
                <path d="M480-80q-140-35-230-162.5T160-522v-238l320-120 320 120v238q0 152-90 279.5T480-80Z"/>
              </svg>
            </div>
            <div class="card-arrow">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M2 10L10 2M10 2H4M10 2V8"/>
              </svg>
            </div>
          </div>

          <div class="card-label">BatStateU</div>
          <div class="card-title">Admin<br>Portal</div>
          <div class="card-desc">Full system control — manage equipment, users, and review reservation requests.</div>

          <div class="card-divider"></div>

          <div class="card-features">
            <div class="card-feature"><span class="card-feature-dot"></span>Equipment &amp; inventory control</div>
            <div class="card-feature"><span class="card-feature-dot"></span>User account management</div>
            <div class="card-feature"><span class="card-feature-dot"></span>Approve &amp; track reservations</div>
          </div>

          <div class="card-cta">Sign in as Admin</div>
          <div class="card-num">01</div>
        </a>

        <!-- Faculty -->
        <a href="faculty/login.php" class="portal-card faculty">
          <div class="card-top">
            <div class="card-icon">
              <svg xmlns="http://www.w3.org/2000/svg" width="22" height="22" viewBox="0 -960 960 960" fill="#a8843e">
                <path d="M480-120 200-272v-240L40-600l440-240 440 240v320h-80v-276l-80 44v240L480-120Zm0-332 274-148-274-148-274 148 274 148Zm0 241 200-108v-151L480-360 280-471v151l200 109Zm0-241Zm0 90Zm0 0Z"/>
              </svg>
            </div>
            <div class="card-arrow">
              <svg viewBox="0 0 12 12" fill="none" stroke="currentColor" stroke-width="1.8">
                <path d="M2 10L10 2M10 2H4M10 2V8"/>
              </svg>
            </div>
          </div>

          <div class="card-label">BatStateU</div>
          <div class="card-title">Faculty<br>Portal</div>
          <div class="card-desc">Browse available equipment, submit reservations, and track your active requests.</div>

          <div class="card-divider"></div>

          <div class="card-features">
            <div class="card-feature"><span class="card-feature-dot"></span>Browse equipment catalog</div>
            <div class="card-feature"><span class="card-feature-dot"></span>Submit reservation requests</div>
            <div class="card-feature"><span class="card-feature-dot"></span>Track approval status</div>
          </div>

          <div class="card-cta">Sign in as Faculty</div>
          <div class="card-num">02</div>
        </a>

      </div>
    </main>

    <!-- Footer -->
    <footer>
      <div class="footer-left">
        &copy; 2026 <strong>Batangas State University</strong> — JPLPC Malvar Campus
      </div>
      <div class="footer-right">
        <a href="#" class="footer-link">Help</a>
        <div class="footer-sep"></div>
        <a href="#" class="footer-link">Privacy</a>
        <div class="footer-sep"></div>
        <a href="#" class="footer-link">About</a>
      </div>
    </footer>

  </div>

</body>
</html>