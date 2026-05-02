<?php
session_start();
require_once 'database/db.php';

// ── Fetch parking spots ──────────────────────────────────────────────────────
$stmt = $pdo->query("
    SELECT spot_number, status 
    FROM parking_spots 
    ORDER BY 
        substring(spot_number FROM 1 FOR 1), 
        CAST(substring(spot_number FROM 2) AS INTEGER)
");
$db_spots = $stmt->fetchAll();

$js_spots = [];
foreach ($db_spots as $spot) {
    $zoneLetter = substr($spot['spot_number'], 0, 1);
    $js_spots[] = [
        'id'          => $spot['spot_number'],
        'zone'        => $zoneLetter,
        'status'      => $spot['status'],
        'type'        => 'standard',
        'occupant'    => null,
        'timeElapsed' => null
    ];
}
$spots_json = json_encode($js_spots);

// ── Session / auth state ─────────────────────────────────────────────────────
$is_logged_in        = isset($_SESSION['user_id']);
$has_login_error     = isset($_GET['error']);
$force_register_view = isset($_GET['show_register']);

$error_msg = $_SESSION['login_error']    ?? '';
$reg_error = $_SESSION['register_error'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_error']);

// ── Fetch logged-in user's name ──────────────────────────────────────────────
$current_user = null;
if ($is_logged_in) {
    $stmt = $pdo->prepare('SELECT first_name, last_name, email FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $current_user = $stmt->fetch();
}

// ── Count stats ───────────────────────────────────────────────────────────────
$total_spots    = count($db_spots);
$free_spots     = count(array_filter($db_spots, fn($s) => $s['status'] === 'available'));
$reserved_spots = count(array_filter($db_spots, fn($s) => $s['status'] === 'reserved'));
$occupied_spots = count(array_filter($db_spots, fn($s) => $s['status'] === 'occupied'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=1280">
  <title>Parkster — Park Smarter</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow:wght@300;400;500;600;700&family=Barlow+Condensed:wght@400;700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/style.css">
  <!-- PayPal SDK — sandbox; replace client-id with your live key for production -->
  <script src="https://www.paypal.com/sdk/js?client-id=sb&currency=EUR&components=buttons&intent=capture"></script>
</head>

<body
  class="<?= $is_logged_in ? '' : 'landing-mode' ?>"
  data-logged-in="<?= $is_logged_in ? 'true' : 'false' ?>"
  data-login-err="<?= $has_login_error ? 'true' : 'false' ?>"
  data-force-register="<?= $force_register_view ? 'true' : 'false' ?>"
  data-spots='<?= $spots_json ?>'
  data-total-spots="<?= $total_spots ?>"
  data-free-spots="<?= $free_spots ?>"
  data-reserved-spots="<?= $reserved_spots ?>"
  data-occupied-spots="<?= $occupied_spots ?>">

  <div class="cursor" id="cursor"></div>
  <div class="cursor-ring" id="cursorRing"></div>

  <!-- ── AUTH MODAL ── -->
  <div class="auth-overlay" id="authOverlay">
    <div class="auth-box">
      <button class="auth-close" onclick="closeAuth()"><i class="fa-solid fa-xmark"></i></button>
      <div class="auth-logo"><i class="fa-solid fa-gem"></i> PARK<span>STER</span></div>
      <div class="auth-subtitle">Smart Parking System</div>

      <div class="auth-tabs">
        <button class="auth-tab" id="tabLogin" onclick="switchTab('login')">Sign In</button>
        <button class="auth-tab" id="tabRegister" onclick="switchTab('register')">Create Account</button>
      </div>

      <div class="auth-form" id="formLogin">
        <div class="auth-error <?= $has_login_error ? 'visible' : '' ?>">
          <?= $error_msg ? htmlspecialchars($error_msg) : 'Invalid email or password. Please try again.' ?>
        </div>
        <form action="functions/login.php" method="POST">
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="you@example.com" required autofocus>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••" required>
          </div>
          <button type="submit" class="auth-submit">Sign In &rarr; Dashboard</button>
        </form>
        <div class="auth-switch">No account? <a onclick="switchTab('register')">Create one free</a></div>
      </div>

      <div class="auth-form" id="formRegister">
        <div class="auth-error <?= ($force_register_view && $reg_error) ? 'visible' : '' ?>">
          <?= $reg_error ? htmlspecialchars($reg_error) : 'This email is already registered.' ?>
        </div>
        <form action="functions/register.php" method="POST">
          <div class="form-row">
            <div class="form-group">
              <label>First Name</label>
              <input type="text" name="first_name" placeholder="John" required>
            </div>
            <div class="form-group">
              <label>Last Name</label>
              <input type="text" name="last_name" placeholder="Doe" required>
            </div>
          </div>
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="you@example.com" required>
          </div>
          <div class="form-group">
            <label>Phone Number</label>
            <input type="tel" name="phone_number" placeholder="+355 69 123 4567">
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Min. 8 characters" required>
          </div>
          <button type="submit" class="auth-submit">Create Account &rarr; Get Started</button>
        </form>
        <div class="auth-switch">Already have an account? <a onclick="switchTab('login')">Sign in</a></div>
      </div>
    </div>
  </div>

  <!-- ── LANDING PAGE ── -->
  <div id="page-landing">
    <div class="live-ticker">
      <div class="ticker-dot"></div> LIVE — <span id="liveSpots"><?= $free_spots ?></span> Spots Free
    </div>

    <nav class="landing-nav">
      <div class="nav-logo"><i class="fa-solid fa-gem"></i> PARK<span>STER</span></div>
      <ul class="nav-links">
        <li><a href="#features">Services</a></li>
        <li><a href="#about">About</a></li>
        <li><a href="#pricing">Pricing</a></li>
      </ul>
      <div class="nav-btns">
        <button class="btn-login" onclick="openAuth('login')">Sign In</button>
        <button class="btn-signup" onclick="openAuth('register')">Get Started</button>
      </div>
    </nav>

    <section class="hero">
      <div class="hero-bg">
        <div class="grid-floor"></div>
        <div class="beam"></div><div class="beam"></div><div class="beam"></div>
        <div class="road"></div>
        <div class="spots-overlay">
          <div class="spot-cell"></div><div class="spot-cell occupied"></div>
          <div class="spot-cell occupied"></div><div class="spot-cell"></div>
          <div class="spot-cell"></div><div class="spot-cell occupied"></div>
          <div class="spot-cell"></div><div class="spot-cell"></div>
        </div>
      </div>
      <div class="hero-overlay"></div>
      <div class="hero-content">
        <div class="hero-tag">Smart Parking System</div>
        <h1 class="hero-title">PARK<br><span class="accent">SMARTER</span><br>FASTER</h1>
        <p class="hero-sub">Find, reserve and pay for your parking spot in seconds. The future of parking — right here.</p>
        <div class="hero-ctas">
          <button class="cta-primary" onclick="openAuth('register')">Get Started Free</button>
          <button class="cta-secondary" onclick="document.getElementById('features').scrollIntoView({behavior:'smooth'})">See How It Works</button>
        </div>
        <div class="hero-stats">
          <div class="stat">
            <div class="stat-num" id="statSpots">0</div>
            <div class="stat-label">Total Spots</div>
          </div>
          <div class="stat">
            <div class="stat-num" id="statFree"><?= $free_spots ?></div>
            <div class="stat-label">Available Now</div>
          </div>
          <div class="stat">
            <div class="stat-num" id="statOccupied"><?= $occupied_spots ?></div>
            <div class="stat-label">Occupied</div>
          </div>
        </div>
      </div>
    </section>

    <section class="section-features" id="features">
      <div class="section-header reveal">
        <div>
          <div class="section-tag">Technology</div>
          <h2 class="section-title">WHAT WE<br><span class="accent">OFFER</span></h2>
        </div>
      </div>
      <div class="features-grid">
        <div class="feature-card reveal"><div class="feature-num">01</div><div class="feature-icon">📍</div><div class="feature-title">Live GPS & Map</div><p class="feature-desc">Find the nearest available spot in real time. Direct navigation straight to your reserved space.</p></div>
        <div class="feature-card reveal" style="transition-delay:.1s"><div class="feature-num">02</div><div class="feature-icon">⚡</div><div class="feature-title">Book in 30 Seconds</div><p class="feature-desc">Choose your spot, pay online and receive your access code instantly on your phone.</p></div>
        <div class="feature-card reveal" style="transition-delay:.2s"><div class="feature-num">03</div><div class="feature-icon">🤖</div><div class="feature-title">AI Monitoring</div><p class="feature-desc">Smart cameras monitor every spot 24/7. Automatic alerts if anything unusual is detected.</p></div>
        <div class="feature-card reveal" style="transition-delay:.05s"><div class="feature-num">04</div><div class="feature-icon">💳</div><div class="feature-title">Secure Payments</div><p class="feature-desc">Credit card, Revolut, Apple Pay. Digital receipts immediately after every parking session.</p></div>
        <div class="feature-card reveal" style="transition-delay:.15s"><div class="feature-num">05</div><div class="feature-icon">🔋</div><div class="feature-title">EV Charging</div><p class="feature-desc">Dedicated spots for electric vehicles with Type-2 and CCS2 chargers available.</p></div>
        <div class="feature-card reveal" style="transition-delay:.25s"><div class="feature-num">06</div><div class="feature-icon">🛡️</div><div class="feature-title">Maximum Security</div><p class="feature-desc">HD CCTV, 24/7 lighting, security guards and anti-break-in systems. Your car is always safe.</p></div>
      </div>
    </section>

    <section class="section-about" id="about">
      <div class="about-visual reveal">
        <div class="lot-visual">
          <div class="scan-line"></div>
          <div style="padding:20px;display:flex;flex-direction:column;gap:8px;height:100%">
            <div style="text-align:center;font-family:'Barlow Condensed',sans-serif;font-size:11px;letter-spacing:3px;color:rgba(200,255,0,.5);padding:11px 0;border-bottom:1px solid rgba(200,255,0,.08)">PARKSTER — ZONE A — LEVEL 1</div>
            <div class="lot-row"><div class="lot-spot occ"></div><div class="lot-spot"></div><div class="lot-spot occ"></div><div class="lot-spot occ"></div><div class="lot-spot"></div></div>
            <div class="lot-row"><div class="lot-spot"></div><div class="lot-spot occ"></div><div class="lot-spot"></div><div class="lot-spot"></div><div class="lot-spot occ"></div></div>
            <div style="display:flex;justify-content:center;align-items:center;flex:1;font-family:'Barlow Condensed';font-size:10px;letter-spacing:3px;color:rgba(200,255,0,.18)">MAIN CORRIDOR</div>
            <div class="lot-row"><div class="lot-spot occ"></div><div class="lot-spot occ"></div><div class="lot-spot"></div><div class="lot-spot occ"></div><div class="lot-spot"></div></div>
            <div class="lot-row"><div class="lot-spot"></div><div class="lot-spot"></div><div class="lot-spot occ"></div><div class="lot-spot"></div><div class="lot-spot occ"></div></div>
            <div style="display:flex;gap:16px;padding-top:13px;border-top:1px solid rgba(200,255,0,.08);justify-content:center;">
              <div style="display:flex;align-items:center;gap:5px;font-size:10px;letter-spacing:2px;color:rgba(200,255,0,.5);font-family:'Barlow Condensed'"><div style="width:10px;height:10px;border:1px solid rgba(200,255,0,.8);background:rgba(200,255,0,.1)"></div>OCCUPIED</div>
              <div style="display:flex;align-items:center;gap:5px;font-size:10px;letter-spacing:2px;color:rgba(200,255,0,.5);font-family:'Barlow Condensed'"><div style="width:10px;height:10px;border:1px solid rgba(200,255,0,.3)"></div>FREE</div>
            </div>
          </div>
        </div>
      </div>
      <div class="about-info reveal" style="transition-delay:.2s">
        <div class="section-tag">About the Business</div>
        <h2 class="section-title" style="margin-bottom:22px">PARKSTER<br><span class="accent">ALBANIA</span></h2>
        <div class="neon-divider"></div>
        <p class="about-text">Parkster is the leading intelligent parking management company in Albania. With our advanced technology, we have transformed the way people find and use parking spaces — making every journey seamless.</p>
        <ul class="info-list">
          <li class="info-item"><strong>Address:</strong> Parking Street 1, Tirana, Albania</li>
          <li class="info-item"><strong>Phone:</strong> +355 69 123 4567</li>
          <li class="info-item"><strong>Email:</strong> info@parkster.al</li>
          <li class="info-item"><strong>Schedule:</strong> Open 24 hours, 7 days a week</li>
          <li class="info-item"><strong>Capacity:</strong> 100+ parking spots</li>
          <li class="info-item"><strong>Founded:</strong> 2026 — Albania</li>
        </ul>
        <button class="cta-primary" onclick="openAuth('register')">Get Started Free</button>
      </div>
    </section>

    <section class="section-pricing" id="pricing">
      <div class="section-header reveal">
        <div><div class="section-tag">Pricing</div><h2 class="section-title">OUR<br><span class="accent">PLANS</span></h2></div>
      </div>
      <div class="pricing-grid">
        <div class="pricing-card reveal">
          <div class="price-plan">Basic</div>
          <div class="price-amount">150<span style="font-size:22px">L</span></div>
          <div class="price-unit">per hour</div>
          <ul class="price-features"><li>Standard access</li><li>Online payment</li><li>Digital receipt</li><li>GPS navigation</li></ul>
          <button class="price-cta" onclick="openAuth('register')">Book Now</button>
        </div>
        <div class="pricing-card featured reveal" style="transition-delay:.1s">
          <div class="price-plan">Pro — Most Popular</div>
          <div class="price-amount">800<span style="font-size:22px">L</span></div>
          <div class="price-unit" style="color:rgba(0,0,0,.5)">per day (unlimited)</div>
          <ul class="price-features"><li>Everything in Basic</li><li>Priority reservation</li><li>Guaranteed spot</li><li>24/7 assistance</li></ul>
          <button class="price-cta" onclick="openAuth('register')">Book Now</button>
        </div>
        <div class="pricing-card reveal" style="transition-delay:.2s">
          <div class="price-plan">Monthly VIP</div>
          <div class="price-amount">12000<span style="font-size:22px">L</span></div>
          <div class="price-unit">per month</div>
          <ul class="price-features"><li>Dedicated spot</li><li>Free EV charging</li><li>VIP zone access</li><li>Personal manager</li></ul>
          <button class="price-cta" onclick="openAuth('register')">Contact Us</button>
        </div>
      </div>
    </section>

    <footer>
      <div class="footer-neon-line"></div>
      <div class="footer-top">
        <div>
          <div class="footer-logo">PARK<span>STER</span></div>
          <p class="footer-tagline">Albania's most advanced intelligent parking system. Tomorrow's technology, today.</p>
        </div>
        <div class="footer-col"><h4>Navigate</h4><ul><li><a href="#features">Services</a></li><li><a href="#about">About</a></li><li><a href="#pricing">Pricing</a></li></ul></div>
        <div class="footer-col">
          <h4>Schedule & Security</h4>
          <ul>
            <li><a style="cursor:default;text-decoration:none;">Open 24/7</a></li>
            <li><a style="cursor:default;text-decoration:none;">HD Security Cameras</a></li>
            <li><a style="cursor:default;text-decoration:none;">On-site Assistance</a></li>
            <li><a style="cursor:default;text-decoration:none;">Optimal Lighting</a></li>
          </ul>
        </div>
        <div class="footer-col"><h4>Contact</h4><ul><li><a href="#">info@parkster.al</a></li><li><a href="#">+355 69 123 4567</a></li><li><a href="#">Instagram</a></li><li><a href="#">Facebook</a></li></ul></div>
      </div>
      <div class="footer-bottom">
        <span>&copy; 2026 Parkster Albania. All rights reserved.</span>
        <span>Made with &hearts; in Albania</span>
      </div>
    </footer>
  </div>

  <!-- ── DASHBOARD ── -->
  <div id="page-dashboard">

    <header class="top-header">
      <div class="header-logo">
        <i class="fa-solid fa-gem"></i> Parkster
      </div>
      <div class="header-right">
        <a href="functions/logout.php" style="color:var(--text-dark); text-decoration:none; font-size:12px; font-weight:700;">LOG OUT</a>
        <span class="bell">
          <i class="fa-solid fa-bell"></i>
          <span id="notif-badge" class="notification-dot"></span>
        </span>
        <div class="user-profile" onclick="dbView('dash')" style="cursor:pointer;">
          <span><?= $current_user ? htmlspecialchars($current_user['first_name']) : 'User' ?></span>
          <i class="fa-solid fa-circle-user" style="font-size:20px;"></i>
        </div>
      </div>
    </header>

    <aside class="sidebar" id="mySidebar">
      <ul>
        <li id="nav-dashboard" class="active" onclick="dbView('dash')"><i class="fa-solid fa-gauge-high"></i> <span class="left-text">Dashboard</span></li>
        <li id="nav-parking" onclick="dbView('parking')"><i class="fa-solid fa-map-location-dot"></i> <span class="left-text">Parking</span></li>
      </ul>
    </aside>

    <main class="main-content">

      <div class="profile-banner">
        <div class="profile-left">
          <img src="https://ui-avatars.com/api/?name=<?= $current_user ? urlencode($current_user['first_name']) : 'User' ?>&background=1cc7d0&color=fff&size=200" alt="Profile" class="profile-img">
          <div>
            <div class="profile-name">
              <h1>Hi, <?= $current_user ? htmlspecialchars($current_user['first_name']) : 'User' ?></h1>
            </div>
            <div class="profile-links">
              <span class="link-item">View Profile <i class="fa-solid fa-circle-info"></i></span> <span>|</span>
              <span class="link-item"><?= $current_user ? htmlspecialchars($current_user['email']) : 'email@example.com' ?> <i class="fa-solid fa-circle-info"></i></span> <span>|</span>
              <span class="link-item">Albania | AL <i class="fa-solid fa-circle-info"></i></span>
            </div>
          </div>
        </div>
      </div>

      <hr class="main-divider">

      <div id="view-dashboard" class="dashboard-grid">

        <div style="margin-top:55px;">
          <div class="left-column">
            <div class="checklist-item" onclick="dbView('parking')">
              <div class="check-icon"><i class="fa-solid fa-car"></i></div>
              <div class="checklist-text"><h4>Find a Spot</h4><p>View the live parking map</p></div>
            </div>
            <div class="checklist-item">
              <div class="check-icon"><i class="fa-solid fa-credit-card"></i></div>
              <div class="checklist-text"><h4>Payment History</h4><p>View your billing records</p></div>
            </div>
            <div class="checklist-item">
              <div class="check-icon"><i class="fa-solid fa-bell"></i></div>
              <div class="checklist-text"><h4>Notifications</h4><p>Manage your alerts</p></div>
            </div>
            <div class="checklist-item">
              <div class="check-icon"><i class="fa-solid fa-user-shield"></i></div>
              <div class="checklist-text"><h4>Security Settings</h4><p>Update password and 2FA</p></div>
            </div>
          </div>
          <div class="confidential-note">
            Your profile and parking history are confidential and protected by high-level encryption. We value your privacy.
          </div>
        </div>

        <div class="right-column">
          <div class="jobs-header">
            <h2><i class="fa-solid fa-map-location-dot"></i> Sesionet e Parkimit</h2>
            <div class="slider-controls">
              <button id="prevBtn"><i class="fa-solid fa-chevron-left"></i></button>
              <button id="nextBtn"><i class="fa-solid fa-chevron-right"></i></button>
            </div>
          </div>

          <div class="slider-viewport">
            <div class="slider-track" id="sliderTrack">
              <div class="job-card">
                <div class="job-banner" style="background-image:url('https://images.unsplash.com/photo-1506521781263-d8422e82f27a?ixlib=rb-1.2.1&auto=format&fit=crop&w=320&q=80');">
                  <div class="company-logo" style="background:#000;"><strong style="color:var(--teal);font-size:24px;">H</strong></div>
                  <div class="no-match-badge" style="color:#777;">COMPLETED</div>
                </div>
                <div class="job-body">
                  <div class="job-company">Historiku</div>
                  <div class="job-title">Sesion i Mëparshëm Parkimi</div>
                  <div class="job-location">Sektori A</div>
                  <div class="open-badge" style="border-color:#888;color:#888;">PAGUAR</div>
                  <div class="status-bars">
                    <span class="active" style="background:#888;"></span><span class="active" style="background:#888;"></span><span class="active" style="background:#888;"></span><span class="active" style="background:#888;"></span><span class="active" style="background:#888;"></span>
                  </div>
                  <div class="job-footer-text">
                    <i class="fa-solid fa-clock" style="color:#888;"></i>
                    <div>Përfunduar më:<br><span style="color:#888;font-size:11px;">Më herët</span></div>
                  </div>
                  <div class="withdraw-btn" style="color:#888;cursor:default;border-top-color:#eaeaea;">
                    <i class="fa-solid fa-check-circle" style="color:#888;"></i> Faturuar
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div id="view-parking" style="display:none;">
        <div class="parking-split-view">
          <div id="map-panel">
            <div class="map-wrap">
              <div class="garage-bg" id="garage"></div>
            </div>
          </div>

          <div id="parking-sidebar">
            <div class="sb-header">Parking — Harta Live</div>

            <div class="sb-section">
              <div class="stat-row">
                <div class="stat"><div class="stat-n g" id="cnt-f">0</div><div class="stat-l">I LIRË</div></div>
                <div class="stat"><div class="stat-n y" id="cnt-r">0</div><div class="stat-l">REZERVUAR</div></div>
                <div class="stat"><div class="stat-n r" id="cnt-t">0</div><div class="stat-l">I ZËNË</div></div>
              </div>
            </div>

            <div class="sb-section">
              <h4>Legjenda</h4>
              <div class="leg-item"><div class="leg-dot g"></div> I lirë — klik për të zgjedhur</div>
              <div class="leg-item"><div class="leg-dot r"></div> I zënë (me makinë)</div>
            </div>

            <div class="sb-section" style="flex:1; overflow-y:auto;">
              <h4>Vendi i zgjedhur</h4>
              <div id="sel-empty">Kliko një vend<br>të lirë në hartë</div>
              <div id="sel-info" style="display:none;">
                <div style="margin-bottom:12px;">
                  <div class="sel-id" id="si-id">—</div>
                  <div class="sel-type" id="si-type">—</div>
                </div>
                <div class="info-row"><span class="lbl">Statusi</span><span class="val g" id="si-status">I lirë</span></div>
                <div class="info-row"><span class="lbl">Çmimi/orë</span><span class="val" id="si-rate">150 L</span></div>
                <div style="margin:12px 0 8px;">
                  <h4 style="font-size:10px;font-weight:600;color:#555;letter-spacing:1.5px;text-transform:uppercase;margin-bottom:8px;">Kohëzgjatja</h4>
                  <div class="dur-row" id="dur-row">
                    <button class="dur active" data-h="1">1 orë</button>
                    <button class="dur" data-h="2">2 orë</button>
                    <button class="dur" data-h="3">3 orë</button>
                    <button class="dur" data-h="6">6 orë</button>
                    <button class="dur" data-h="12">12 orë</button>
                    <button class="dur" data-h="24">24 orë</button>
                  </div>
                </div>
                <div class="price-box" id="price-box">
                  <div class="price-row"><span>Çmim/orë</span><span id="pr-rate">—</span></div>
                  <div class="price-row"><span>Kohëzgjatja</span><span id="pr-dur">—</span></div>
                  <div class="price-row"><span>Tarifë Baze</span><span>20 L</span></div>
                  <div class="price-total"><span class="tlbl">Total</span><span class="tval" id="pr-total">—</span></div>
                </div>
              </div>
            </div>

            <button id="pay-btn" style="display:none;" onclick="doPay()">Konfirmo &amp; Paguaj</button>
            <div class="times" id="times-box" style="display:none">
              Check-in: <span id="t-in">—</span><br>
              Check-out: <span id="t-out">—</span>
            </div>
          </div>
        </div>
        <div id="toast"></div>
      </div>

    </main>
  </div>

  <!-- ── PAYPAL PAYMENT MODAL ── -->
  <div class="pp-overlay" id="ppOverlay">
    <div class="pp-modal">
      <button class="pp-close" id="ppClose"><i class="fa-solid fa-xmark"></i></button>
      <div class="pp-header">
        <div class="pp-logo"><i class="fa-solid fa-gem"></i> PARKSTER</div>
        <div class="pp-title">Konfirmo Rezervimin</div>
      </div>

      <div class="pp-summary">
        <div class="pp-row"><span class="pp-lbl">Vendi</span><span class="pp-val" id="pp-spot">—</span></div>
        <div class="pp-row"><span class="pp-lbl">Sektori</span><span class="pp-val" id="pp-zone">—</span></div>
        <div class="pp-row"><span class="pp-lbl">Kohëzgjatja</span><span class="pp-val" id="pp-dur">—</span></div>
        <div class="pp-row"><span class="pp-lbl">Check-in</span><span class="pp-val" id="pp-in">—</span></div>
        <div class="pp-row"><span class="pp-lbl">Check-out</span><span class="pp-val" id="pp-out">—</span></div>
        <div class="pp-divider"></div>
        <div class="pp-row pp-total-row">
          <span class="pp-lbl">Total</span>
          <span class="pp-total-val" id="pp-total">—</span>
        </div>
        <div class="pp-row">
          <span class="pp-lbl" style="font-size:10px;color:#aaa">Lek Albanian → EUR (approx)</span>
          <span class="pp-val" style="font-size:11px;color:#aaa" id="pp-eur">—</span>
        </div>
      </div>

      <div id="pp-status" class="pp-status" style="display:none;"></div>
      <div id="paypal-button-container"></div>
      <div class="pp-secure"><i class="fa-solid fa-lock"></i> Pagesa e sigurt me PayPal</div>
    </div>
  </div>

  <script src="assets/app.js"></script>
</body>
</html>