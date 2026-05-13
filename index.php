<?php
/*
 * index.php — Main entry point for Parkster. Handles session state, auth views (login / register / email
 * verification), profile updates, parking spot data, and renders either the public landing page or the
 * logged-in dashboard depending on the user's authentication status.
 */
session_start();
require_once 'database/db.php';

$is_logged_in        = isset($_SESSION['user_id']);
$has_login_error     = isset($_GET['error']);
$force_register_view = isset($_GET['show_register']);

$error_msg = $_SESSION['login_error']    ?? '';
$reg_error = $_SESSION['register_error'] ?? '';
unset($_SESSION['login_error'], $_SESSION['register_error']);

$show_verify_view   = isset($_SESSION['pending_registration']) || isset($_GET['verify_register']);
$pending_reg_email  = $_SESSION['pending_registration']['email']      ?? '';
$pending_expires    = (int) ($_SESSION['pending_registration']['expires_at'] ?? 0);
$expires_in_seconds = max(0, $pending_expires - time());

$verify_error = $_SESSION['verify_register_error'] ?? '';
$verify_info  = $_SESSION['verify_register_info']  ?? '';
unset($_SESSION['verify_register_error'], $_SESSION['verify_register_info']);

function _mask_email_pkstr(string $email): string {
    $parts = explode('@', $email, 2);
    if (count($parts) !== 2) return $email;
    [$local, $domain] = $parts;
    if (strlen($local) <= 2) return $local[0] . '***@' . $domain;
    return substr($local, 0, 2) . str_repeat('*', max(3, strlen($local) - 2)) . '@' . $domain;
}
$masked_pending_email = $pending_reg_email !== '' ? _mask_email_pkstr($pending_reg_email) : '';

$current_user = null;
if ($is_logged_in) {
  $stmt = $pdo->prepare('SELECT first_name, last_name, email, phone_number, role, profile_image_url FROM users WHERE id = ?');
  $stmt->execute([$_SESSION['user_id']]);
  $current_user = $stmt->fetch();

  if (!$current_user) {
      session_destroy();
      header("Location: index.php");
      exit;
  }
}

$profile_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile']) && $is_logged_in) {
    $new_email = trim($_POST['new_email'] ?? '');
    $new_phone = trim($_POST['new_phone'] ?? '');
    $user_id   = $_SESSION['user_id'];

    if (!filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
        $profile_error = 'Invalid email format.';
    }
    elseif (!empty($new_phone) && !preg_match('/^\+?[0-9\s\-]{7,15}$/', $new_phone)) {
        $profile_error = 'Invalid phone number.';
    }
    else {
        $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id != ?');
        $check->execute([$new_email, $user_id]);
        if ($check->fetch()) {
            $profile_error = 'This email is already used by another account.';
        } else {
            try {
                $update_stmt = $pdo->prepare('UPDATE users SET email = ?, phone_number = ? WHERE id = ?');
                $update_stmt->execute([$new_email, $new_phone ?: null, $user_id]);
                header("Location: index.php?profile_updated=success");
                exit;
            } catch (PDOException $e) {
                $profile_error = 'Error updating profile.';
                error_log('Profile update: ' . $e->getMessage());
            }
        }
    }
}

$stmt = $pdo->query("
    SELECT id, spot_number, status 
    FROM parking_spots 
    ORDER BY 
        substring(spot_number FROM 1 FOR 1), 
        CAST(substring(spot_number FROM 2) AS INTEGER)
");
$db_spots = $stmt->fetchAll();

$my_spot_ids = [];
if ($is_logged_in) {
    $stmt = $pdo->prepare("
        SELECT spot_id FROM reservations 
         WHERE user_id = ? AND status = 'active' AND reservation_end > NOW()
        UNION
        SELECT ps.spot_id FROM parking_sessions ps
          JOIN vehicles v ON ps.vehicle_id = v.id
         WHERE v.user_id = ? AND ps.status = 'active'
    ");
    $stmt->execute([$_SESSION['user_id'], $_SESSION['user_id']]);
    $my_spot_ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

$js_spots = [];
foreach ($db_spots as $spot) {
  $zoneLetter = substr($spot['spot_number'], 0, 1);
  $js_spots[] = [
    'id'     => $spot['spot_number'],
    'zone'   => $zoneLetter,
    'status' => $spot['status'],
    'type'   => 'standard',
    'mine'   => in_array((int)$spot['id'], $my_spot_ids, true),
  ];
}
$spots_json = json_encode($js_spots);

$total_spots    = count($db_spots);
$free_spots     = count(array_filter($db_spots, fn($s) => $s['status'] === 'available'));
$reserved_spots = count(array_filter($db_spots, fn($s) => $s['status'] === 'reserved'));
$occupied_spots = count(array_filter($db_spots, fn($s) => $s['status'] === 'occupied'));

$user_initial = $current_user
    ? strtoupper(substr($current_user['first_name'] ?? 'U', 0, 1))
    : 'U';

$is_admin = $current_user && ($current_user['role'] ?? '') === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=1280">
  <title>Parkster</title>
  <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Barlow:wght@300;400;500;600;700&family=Barlow+Condensed:wght@400;700;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="assets/style.css">
</head>

<body
  class="<?= $is_logged_in ? '' : 'landing-mode' ?>"
  data-logged-in="<?= $is_logged_in ? 'true' : 'false' ?>"
  data-login-err="<?= $has_login_error ? 'true' : 'false' ?>"
  data-force-register="<?= $force_register_view ? 'true' : 'false' ?>"
  data-show-verify="<?= $show_verify_view ? 'true' : 'false' ?>"
  data-verify-expires-in="<?= $expires_in_seconds ?>"
  data-spots='<?= $spots_json ?>'
  data-total-spots="<?= $total_spots ?>"
  data-free-spots="<?= $free_spots ?>"
  data-reserved-spots="<?= $reserved_spots ?>"
  data-occupied-spots="<?= $occupied_spots ?>">

  <?php if (isset($_GET['profile_updated'])): ?>
    <div class="success-toast">Profile updated successfully!</div>
  <?php endif; ?>
  <?php if (isset($_GET['upload']) && $_GET['upload'] === 'success'): ?>
    <div class="success-toast"><i class="fa-solid fa-circle-check"></i> Photo uploaded successfully!</div>
  <?php endif; ?>
  <?php if (isset($_GET['photo_removed']) && $_GET['photo_removed'] === 'true'): ?>
    <div class="success-toast" style="background:#6c757d;"><i class="fa-solid fa-trash"></i> Photo removed successfully!</div>
  <?php endif; ?>
  <?php if (isset($_GET['upload_error'])): ?>
    <?php
      switch ($_GET['upload_error']) {
        case 'size':
          $errMsg = 'Error: Photo is too large (max 5MB).';
          break;
        case 'type':
          $errMsg = 'Error: Only JPG and PNG files are allowed.';
          break;
        default:
          $errMsg = 'Error uploading photo. Please try again.';
      }
    ?>
    <div class="success-toast" style="background:#dc3545;"><?= htmlspecialchars($errMsg) ?></div>
  <?php endif; ?>

  <div class="cursor" id="cursor"></div>
  <div class="cursor-ring" id="cursorRing"></div>

  <div class="auth-overlay" id="authOverlay">
    <div class="auth-box">
      <button class="auth-close" onclick="closeAuth()"><i class="fa-solid fa-xmark"></i></button>
      <div class="auth-logo"><i class="fa-solid fa-gem"></i> PARK<span>STER</span></div>
      <div class="auth-subtitle">Smart Parking System</div>

      <div class="auth-tabs">
        <button class="auth-tab" id="tabLogin" onclick="switchTab('login')">Log In</button>
        <button class="auth-tab" id="tabRegister" onclick="switchTab('register')">Register</button>
      </div>

      <div class="auth-form" id="formLogin">
        <div class="auth-error <?= $has_login_error ? 'visible' : '' ?>">
          <?= $error_msg ? htmlspecialchars($error_msg) : 'Invalid email or password. Please try again.' ?>
        </div>
        
        <a href="functions/google_auth.php?action=login" class="google-btn">
          <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google"> Continue with Google
        </a>
        
        <div class="auth-separator"><span>Or with Email</span></div>
        
        <form action="functions/login.php" method="POST">
          <div class="form-group">
            <label>Email Address</label>
            <input type="email" name="email" placeholder="you@example.com" required autofocus>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="••••••••" required>
          </div>
          <button type="submit" class="auth-submit">Log In &rarr; Dashboard</button>
        </form>
        <div class="auth-switch">No account? <a onclick="switchTab('register')">Register for free</a></div>
      </div>

      <div class="auth-form" id="formRegister">
        <div class="auth-error <?= ($force_register_view && $reg_error) ? 'visible' : '' ?>">
          <?= $reg_error ? htmlspecialchars($reg_error) : 'This email is already registered.' ?>
        </div>
        
        <a href="functions/google_auth.php?action=register" class="google-btn">
          <img src="https://www.svgrepo.com/show/475656/google-color.svg" alt="Google"> Register with Google
        </a>
        
        <div class="auth-separator"><span>Or with Email</span></div>

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
          <button type="submit" class="auth-submit">Register &rarr; Get Started</button>
        </form>
        <div class="auth-switch">Already have an account? <a onclick="switchTab('login')">Log in</a></div>
      </div>

      <div class="auth-form" id="formVerify">
        <div class="verify-header">
          <h3><i class="fa-solid fa-shield-halved" style="color:#1cc7d0;"></i> Verify your email</h3>
          <p>We sent a 6-digit code to <strong><?= htmlspecialchars($masked_pending_email) ?></strong>. Enter it below to complete your registration.</p>
        </div>

        <div class="auth-error <?= $verify_error ? 'visible' : '' ?>">
          <?= $verify_error ? htmlspecialchars($verify_error) : 'Invalid code.' ?>
        </div>

        <?php if ($verify_info): ?>
          <div class="verify-info"><i class="fa-solid fa-circle-info"></i> <?= htmlspecialchars($verify_info) ?></div>
        <?php endif; ?>

        <form action="functions/verify_register.php" method="POST" id="verifyCodeForm" autocomplete="off">
          <div class="code-input-row" id="codeRow">
            <input type="text" name="digit1" inputmode="numeric" maxlength="1" pattern="\d" autocomplete="one-time-code" required>
            <input type="text" name="digit2" inputmode="numeric" maxlength="1" pattern="\d" required>
            <input type="text" name="digit3" inputmode="numeric" maxlength="1" pattern="\d" required>
            <input type="text" name="digit4" inputmode="numeric" maxlength="1" pattern="\d" required>
            <input type="text" name="digit5" inputmode="numeric" maxlength="1" pattern="\d" required>
            <input type="text" name="digit6" inputmode="numeric" maxlength="1" pattern="\d" required>
          </div>
          <input type="hidden" name="code" id="codeHidden">

          <div class="verify-timer" id="verifyTimer">
            <?php if ($expires_in_seconds > 0): ?>
              Code expires in <span class="t" id="vCountdown"><?= gmdate('i:s', $expires_in_seconds) ?></span>
            <?php else: ?>
              <span class="t" style="color:#ef4444;">Code has expired</span>
            <?php endif; ?>
          </div>

          <button type="submit" class="auth-submit" id="verifySubmitBtn" <?= $expires_in_seconds <= 0 ? 'disabled' : '' ?>>
            <i class="fa-solid fa-shield-halved"></i> &nbsp;Verify & Create Account
          </button>
        </form>

        <div class="verify-resend">
          Didn't receive the code?
          <form method="POST" action="functions/resend_register_code.php">
            <button type="submit" id="vResendBtn">Resend</button>
          </form>
        </div>

        <a href="functions/cancel_register.php" class="verify-back">
          <i class="fa-solid fa-arrow-left"></i> Cancel and restart registration
        </a>
      </div>
    </div>
  </div>

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
        <button class="btn-login" onclick="openAuth('login')">Log In</button>
        <button class="btn-signup" onclick="openAuth('register')">Register</button>
      </div>
    </nav>

    <section class="hero">
      <div class="hero-bg">
        <div class="grid-floor"></div>
        <div class="road"></div>
        <div class="spots-overlay">
          <div class="spot-cell"></div>
          <div class="spot-cell occupied"></div>
          <div class="spot-cell occupied"></div>
          <div class="spot-cell"></div>
          <div class="spot-cell"></div>
          <div class="spot-cell occupied"></div>
          <div class="spot-cell"></div>
          <div class="spot-cell"></div>
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
        <div class="feature-card reveal">
          <div class="feature-num">01</div>
          <div class="feature-icon">📍</div>
          <div class="feature-title">Live GPS & Map</div>
          <p class="feature-desc">Find the nearest available spot in real time. Direct navigation straight to your reserved space.</p>
        </div>
        <div class="feature-card reveal" style="transition-delay:.1s">
          <div class="feature-num">02</div>
          <div class="feature-icon">⚡</div>
          <div class="feature-title">Book in 30 Seconds</div>
          <p class="feature-desc">Choose your spot, pay online and receive your access code instantly on your phone.</p>
        </div>
        <div class="feature-card reveal" style="transition-delay:.2s">
          <div class="feature-num">03</div>
          <div class="feature-icon">🤖</div>
          <div class="feature-title">AI Monitoring</div>
          <p class="feature-desc">Smart cameras monitor every spot 24/7. Automatic alerts if anything unusual is detected.</p>
        </div>
        <div class="feature-card reveal" style="transition-delay:.05s">
          <div class="feature-num">04</div>
          <div class="feature-icon">💳</div>
          <div class="feature-title">Secure Payments</div>
          <p class="feature-desc">Credit card, Revolut, Apple Pay. Digital receipts immediately after every parking session.</p>
        </div>
        <div class="feature-card reveal" style="transition-delay:.15s">
          <div class="feature-num">05</div>
          <div class="feature-icon">🔋</div>
          <div class="feature-title">EV Charging</div>
          <p class="feature-desc">Dedicated spots for electric vehicles with Type-2 and CCS2 chargers available.</p>
        </div>
        <div class="feature-card reveal" style="transition-delay:.25s">
          <div class="feature-num">06</div>
          <div class="feature-icon">🛡️</div>
          <div class="feature-title">Maximum Security</div>
          <p class="feature-desc">HD CCTV, 24/7 lighting, security guards and anti-break-in systems. Your car is always safe.</p>
        </div>
      </div>
    </section>

    <section class="section-about" id="about">
      <div class="about-visual reveal">
        <div class="lot-visual">
          <div class="scan-line"></div>
          <div style="padding:20px;display:flex;flex-direction:column;gap:8px;height:100%">
            <div style="text-align:center;font-family:'Barlow Condensed',sans-serif;font-size:11px;letter-spacing:3px;color:rgba(200,255,0,.5);padding:11px 0;border-bottom:1px solid rgba(200,255,0,.08)">PARKSTER — ZONE A — LEVEL 1</div>
            <div class="lot-row">
              <div class="lot-spot occ"></div><div class="lot-spot"></div>
              <div class="lot-spot occ"></div><div class="lot-spot occ"></div><div class="lot-spot"></div>
            </div>
            <div class="lot-row">
              <div class="lot-spot"></div><div class="lot-spot occ"></div>
              <div class="lot-spot"></div><div class="lot-spot"></div><div class="lot-spot occ"></div>
            </div>
            <div style="display:flex;justify-content:center;align-items:center;flex:1;font-family:'Barlow Condensed';font-size:10px;letter-spacing:3px;color:rgba(200,255,0,.18)">MAIN CORRIDOR</div>
            <div class="lot-row">
              <div class="lot-spot occ"></div><div class="lot-spot occ"></div>
              <div class="lot-spot"></div><div class="lot-spot occ"></div><div class="lot-spot"></div>
            </div>
            <div class="lot-row">
              <div class="lot-spot"></div><div class="lot-spot"></div>
              <div class="lot-spot occ"></div><div class="lot-spot"></div><div class="lot-spot occ"></div>
            </div>
            <div style="display:flex;gap:16px;padding-top:13px;border-top:1px solid rgba(200,255,0,.08);justify-content:center;">
              <div style="display:flex;align-items:center;gap:5px;font-size:10px;letter-spacing:2px;color:rgba(200,255,0,.5);font-family:'Barlow Condensed'">
                <div style="width:10px;height:10px;border:1px solid rgba(200,255,0,.8);background:rgba(200,255,0,.1)"></div>OCCUPIED
              </div>
              <div style="display:flex;align-items:center;gap:5px;font-size:10px;letter-spacing:2px;color:rgba(200,255,0,.5);font-family:'Barlow Condensed'">
                <div style="width:10px;height:10px;border:1px solid rgba(200,255,0,.3)"></div>FREE
              </div>
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
        <div>
          <div class="section-tag">Pricing</div>
          <h2 class="section-title">OUR<br><span class="accent">PLANS</span></h2>
        </div>
      </div>
      <div class="pricing-grid">
        <div class="pricing-card reveal">
          <div class="price-plan">Basic</div>
          <div class="price-amount">150<span style="font-size:22px">L</span></div>
          <div class="price-unit">per hour</div>
          <ul class="price-features">
            <li>Standard access</li><li>Online payment</li>
            <li>Digital receipt</li><li>GPS navigation</li>
          </ul>
          <button class="price-cta" onclick="openAuth('register')">Book Now</button>
        </div>
        <div class="pricing-card featured reveal" style="transition-delay:.1s">
          <div class="price-plan">Pro — Most Popular</div>
          <div class="price-amount">800<span style="font-size:22px">L</span></div>
          <div class="price-unit" style="color:rgba(0,0,0,.5)">per day (unlimited)</div>
          <ul class="price-features">
            <li>Everything in Basic</li><li>Priority reservation</li>
            <li>Guaranteed spot</li><li>24/7 assistance</li>
          </ul>
          <button class="price-cta" onclick="openAuth('register')">Book Now</button>
        </div>
        <div class="pricing-card reveal" style="transition-delay:.2s">
          <div class="price-plan">Monthly VIP</div>
          <div class="price-amount">12000<span style="font-size:22px">L</span></div>
          <div class="price-unit">per month</div>
          <ul class="price-features">
            <li>Dedicated spot</li><li>Free EV charging</li>
            <li>VIP zone access</li><li>Personal manager</li>
          </ul>
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
        <div class="footer-col">
          <h4>Navigate</h4>
          <ul>
            <li><a href="#features">Services</a></li>
            <li><a href="#about">About</a></li>
            <li><a href="#pricing">Pricing</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Schedule & Security</h4>
          <ul>
            <li><a style="cursor:default;text-decoration:none;">Open 24/7</a></li>
            <li><a style="cursor:default;text-decoration:none;">HD Security Cameras</a></li>
            <li><a style="cursor:default;text-decoration:none;">On-site Assistance</a></li>
            <li><a style="cursor:default;text-decoration:none;">Optimal Lighting</a></li>
          </ul>
        </div>
        <div class="footer-col">
          <h4>Contact</h4>
          <ul>
            <li><a href="#">info@parkster.al</a></li>
            <li><a href="#">+355 69 123 4567</a></li>
            <li><a href="#">Instagram</a></li>
            <li><a href="#">Facebook</a></li>
          </ul>
        </div>
      </div>
      <div class="footer-bottom">
        <span>&copy; 2026 Parkster Albania. All rights reserved.</span>
        <span>Made with &hearts; in Albania</span>
      </div>
    </footer>
  </div>

  <div id="page-dashboard">

    <header class="top-header">
      <div class="header-logo"><i class="fa-solid fa-gem"></i> Parkster</div>
      <div class="header-right" style="display:flex;align-items:center;gap:16px;position:relative;z-index:10;">
        <?php if ($is_admin): ?>
          <a href="admin.php" class="admin-link" style="position:relative;z-index:11;pointer-events:all;"><i class="fa-solid fa-shield-halved"></i> ADMIN</a>
        <?php endif; ?>
        <a href="functions/logout.php"
           style="color:var(--text-dark);text-decoration:none;font-size:12px;font-weight:700;
                  position:relative;z-index:11;pointer-events:all;cursor:pointer;">LOG OUT</a>
        <span class="bell" id="notifBell" onclick="toggleNotifPanel(event)" style="position:relative;z-index:11;">
          <i class="fa-solid fa-bell"></i>
          <span id="notif-badge" class="notification-dot"></span>
          <div id="notifPanel" class="notif-panel" onclick="event.stopPropagation()">
            <div class="notif-header">
              <span><i class="fa-solid fa-bell"></i> Notifications</span>
              <button class="notif-clear" onclick="markAllNotifRead()">Mark all read</button>
            </div>
            <div id="notifList" class="notif-list">
              <div class="notif-empty"><i class="fa-solid fa-circle-notch fa-spin"></i> Loading…</div>
            </div>
          </div>
        </span>
        <div class="user-profile" onclick="dbView('dash')" style="cursor:pointer;position:relative;z-index:10;display:flex;align-items:center;gap:8px;">
          <span><?= $current_user ? htmlspecialchars($current_user['first_name']) : 'User' ?></span>
          <?php if (!empty($current_user['profile_image_url'])): ?>
            <img src="uploads/profiles/<?= htmlspecialchars($current_user['profile_image_url']) ?>"
                 alt="avatar"
                 style="width:28px;height:28px;border-radius:50%;object-fit:cover;vertical-align:middle;flex-shrink:0;">
          <?php else: ?>
            <span class="small-initial-avatar"><?= htmlspecialchars($user_initial) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </header>

    <aside class="sidebar" id="mySidebar">
      <ul>
        <li id="nav-dashboard" class="active" onclick="dbView('dash')">
          <i class="fa-solid fa-gauge-high"></i> <span class="left-text">Dashboard</span>
        </li>
        <li id="nav-parking" onclick="dbView('parking')">
          <i class="fa-solid fa-map-location-dot"></i> <span class="left-text">Parking</span>
        </li>
      </ul>
    </aside>

    <main class="main-content">

      <div class="profile-banner">
        <div class="profile-left" style="display:flex;align-items:center;">

          <?php
            $hasPhoto = !empty($current_user['profile_image_url']);
            $photoUrl = $hasPhoto
              ? 'uploads/profiles/' . htmlspecialchars($current_user['profile_image_url'])
              : null;
          ?>

          <div class="profile-photo-wrap">
            <?php if ($hasPhoto): ?>
              <img src="<?= $photoUrl ?>" alt="Profile Photo">
            <?php else: ?>
              <div class="initial-avatar"><?= htmlspecialchars($user_initial) ?></div>
            <?php endif; ?>

            <div class="profile-photo-overlay">
              <button class="pho-btn" onclick="document.getElementById('photoFileInput').click()" title="Upload photo">
                <i class="fa-solid fa-camera"></i> Upload
              </button>
              <?php if ($hasPhoto): ?>
                <button class="pho-btn remove"
                        onclick="if(confirm('Delete profile photo?')) window.location.href='functions/remove_photo.php';"
                        title="Remove photo">
                  <i class="fa-solid fa-trash"></i>
                </button>
              <?php endif; ?>
            </div>
          </div>

          <form id="photoUploadForm" action="functions/upload_profile.php" method="POST" enctype="multipart/form-data" style="display:none;">
            <input type="file" id="photoFileInput" name="profile_image" accept="image/jpeg,image/png">
          </form>

          <div>
            <div class="profile-name">
              <h1>Hi, <?= $current_user ? htmlspecialchars($current_user['first_name']) : 'User' ?></h1>
            </div>
            <div class="profile-links">
              <span class="link-item"><?= htmlspecialchars($current_user['phone_number'] ?? 'Add phone number') ?> <i class="fa-solid fa-phone"></i></span>
              <span>|</span>
              <span class="link-item"><?= $current_user ? htmlspecialchars($current_user['email']) : 'email@example.com' ?> <i class="fa-solid fa-circle-info"></i></span>
              <span>|</span>
              <span style="cursor:default;">Albania | AL <i class="fa-solid fa-location-dot"></i></span>
            </div>
            <div class="profile-actions" style="margin-top:15px;">
              <button onclick="openModal()" class="btn-edit"
                      style="padding:6px 16px;background:#1cc7d0;color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">
                Edit Profile <i class="fa-solid fa-pen" style="margin-left:5px;"></i>
              </button>
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
            <div class="checklist-item" onclick="openHistoryModal()" style="cursor:pointer;">
              <div class="check-icon"><i class="fa-solid fa-credit-card"></i></div>
              <div class="checklist-text"><h4>Payment History</h4><p>View your billing records</p></div>
            </div>
            <div class="checklist-item" onclick="openNotifFromTile(event)" style="cursor:pointer;">
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
            <h2><i class="fa-solid fa-map-location-dot"></i> Active Session</h2>
          </div>

          <div id="activeStatusContainer" style="margin-top: 20px;">
            <div class="empty-card">
              <i class="fa-solid fa-circle-notch fa-spin"></i>
              <div style="font-weight:700;margin-bottom:6px;color:#666;">Loading…</div>
              <div style="font-size:12px;">Checking your session</div>
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
            <div class="sb-header">Parking — Live Map</div>

            <div class="sb-section">
              <div class="spot-search-wrap">
                <i class="fa-solid fa-magnifying-glass"></i>
                <input type="text" id="spotSearch" class="spot-search" placeholder="Search spot (e.g. A12)" autocomplete="off">
              </div>
              <div class="spot-search-count" id="spotSearchCount"></div>
            </div>

            <div class="sb-section">
              <div class="stat-row">
                <div class="stat"><div class="stat-n g" id="cnt-f">0</div><div class="stat-l">FREE</div></div>
                <div class="stat"><div class="stat-n y" id="cnt-r">0</div><div class="stat-l">RESERVED</div></div>
                <div class="stat"><div class="stat-n r" id="cnt-t">0</div><div class="stat-l">OCCUPIED</div></div>
              </div>
            </div>

            <div class="sb-section">
              <h4>Legend</h4>
              <div class="leg-item"><div class="leg-dot g"></div> Free — click to select</div>
              <div class="leg-item"><div class="leg-dot" style="background:#f8961e"></div> Reserved</div>
              <div class="leg-item"><div class="leg-dot r"></div> Occupied</div>
              <div class="leg-item"><div class="leg-dot" style="background:#1cc7d0;box-shadow:0 0 6px #1cc7d0"></div> Your spot</div>
            </div>

            <div class="sb-section" style="flex:1;">
              <div id="sel-empty" style="padding:30px 12px;text-align:center;color:#888;font-size:13px;">
                Click a free spot<br>on the map to begin
              </div>

              <div id="sel-info" style="display:none;">
                <div class="sb-spot-title">
                  <div class="num" id="si-id">—</div>
                  <div class="lbl">SELECTED SPOT</div>
                </div>

                <div class="sb-actions">
                  <button class="sb-action-btn btn-reserve" onclick="openReserveModal()">
                    <i class="fa-solid fa-clock"></i> Reserve
                  </button>
                  <button class="sb-action-btn btn-occupy" onclick="confirmOccupy()">
                    <i class="fa-solid fa-car-side"></i> Occupy Spot
                  </button>
                </div>
              </div>
            </div>

          </div>

        </div>
        <div id="toast"></div>
      </div>

    </main>
  </div>

  <div id="editProfileModal" style="display:<?= $profile_error ? 'flex' : 'none' ?>;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:1000;justify-content:center;align-items:center;">
    <div style="background:#fff;padding:30px;border-radius:10px;width:350px;position:relative;">
      <span onclick="closeModal()" style="position:absolute;top:15px;right:20px;font-size:20px;cursor:pointer;color:#333;">&times;</span>
      <h2 style="margin-top:0;color:#333;font-size:20px;margin-bottom:20px;">Edit Profile</h2>

      <?php if ($profile_error): ?>
        <div style="background:#fee;color:#c33;padding:10px;border-radius:5px;margin-bottom:15px;font-size:14px;">
          <?= htmlspecialchars($profile_error) ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="">
        <div style="margin-bottom:15px;">
          <label style="display:block;margin-bottom:5px;color:#555;font-size:14px;">New Email:</label>
          <input type="email" name="new_email" value="<?= htmlspecialchars($current_user['email'] ?? '') ?>" required
                 style="width:100%;padding:10px;border:1px solid #ccc;border-radius:5px;box-sizing:border-box;">
        </div>
        <div style="margin-bottom:20px;">
          <label style="display:block;margin-bottom:5px;color:#555;font-size:14px;">Phone Number:</label>
          <input type="text" name="new_phone" value="<?= htmlspecialchars($current_user['phone_number'] ?? '') ?>"
                 placeholder="+355 69 123 4567"
                 style="width:100%;padding:10px;border:1px solid #ccc;border-radius:5px;box-sizing:border-box;">
        </div>
        <button type="submit" name="update_profile"
                style="width:100%;padding:10px;background:#1cc7d0;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:16px;font-weight:bold;">
          Save Changes
        </button>
      </form>
    </div>
  </div>

  <div id="reserveModal" class="res-modal-bg">
    <div class="res-modal">
      <h2>Reserve Spot <span id="rmSpotId" style="color:#1cc7d0;">—</span></h2>
      <p class="sub">Choose duration with the slider (max. 6 hours)</p>

      <div class="slider-display">
        <div class="h"><span id="rmHours">1</span></div>
        <div class="lbl"><span id="rmHoursLbl">HOURS</span></div>
      </div>

      <input type="range" id="rmSlider" min="1" max="6" step="1" value="1">
      <div class="ticks">
        <span>1h</span><span>2h</span><span>3h</span><span>4h</span><span>5h</span><span>6h</span>
      </div>

      <div class="actions">
        <button class="cancel" onclick="closeReserveModal()">Cancel</button>
        <button class="confirm" id="rmConfirm" onclick="confirmReserve()">Confirm Reservation</button>
      </div>
    </div>
  </div>

  <div id="payModal" class="pay-modal-bg">
    <div class="pay-modal">
      <h2><i class="fa-solid fa-credit-card" style="color:#28a745;"></i> Pay for Spot</h2>
      <p class="sub">Complete the session and free your parking spot.</p>

      <div class="pay-summary">
        <div class="row">
          <span class="lbl"><i class="fa-solid fa-location-dot"></i> Spot</span>
          <span class="val" id="paySpot">—</span>
        </div>
        <div class="row">
          <span class="lbl"><i class="fa-solid fa-clock"></i> Duration</span>
          <span class="val" id="payDuration">—</span>
        </div>
        <div class="row">
          <span class="lbl"><i class="fa-solid fa-tag"></i> Rate</span>
          <span class="val" id="payDetail">—</span>
        </div>
        <div class="row total">
          <span class="lbl">Total</span>
          <span class="val"><span id="payAmount">0</span> L</span>
        </div>
      </div>

      <div class="pay-method-grid">
        <div class="pay-method selected" data-method="card" onclick="selectPayMethod('card')">
          <i class="fa-solid fa-credit-card"></i>
          <div class="name">Card</div>
          <div class="desc">Visa / Mastercard</div>
        </div>
        <div class="pay-method" data-method="cash" onclick="selectPayMethod('cash')">
          <i class="fa-solid fa-money-bill-wave"></i>
          <div class="name">Cash</div>
          <div class="desc">Pay at counter</div>
        </div>
        <div class="pay-method" data-method="paypal" onclick="selectPayMethod('paypal')">
          <i class="fa-brands fa-paypal"></i>
          <div class="name">PayPal</div>
          <div class="desc">Pay with your account</div>
        </div>
      </div>

      <div id="payCardForm" class="pay-card-form show">
        <div class="form-row">
          <label>Card Number</label>
          <input type="text" id="cardNumber" placeholder="1234 5678 9012 3456" maxlength="19" inputmode="numeric">
        </div>
        <div class="form-row split">
          <div>
            <label>Expiry</label>
            <input type="text" id="cardExpiry" placeholder="MM/YY" maxlength="5">
          </div>
          <div>
            <label>CVV</label>
            <input type="text" id="cardCvv" placeholder="123" maxlength="4" inputmode="numeric">
          </div>
        </div>
        <div class="form-row">
          <label>Name on Card</label>
          <input type="text" id="cardName" placeholder="John Doe">
        </div>
      </div>

      <div id="payPaypalBox" class="pay-paypal-box">
        <div id="paypalButtonContainer"></div>
        <p class="pay-paypal-hint">
          <i class="fa-solid fa-circle-info"></i>
          Sandbox mode — use a PayPal test account to complete the payment.
        </p>
      </div>

      <div class="pay-actions">
        <button class="cancel" onclick="closePayModal()">Cancel</button>
        <button class="confirm" id="payConfirmBtn" onclick="confirmPayment()">
          <i class="fa-solid fa-check"></i> Pay
        </button>
      </div>
    </div>
  </div>

  <div id="historyModal" class="pay-modal-bg">
    <div class="pay-modal history-modal">
      <h2><i class="fa-solid fa-receipt" style="color:#1cc7d0;"></i> Payment History</h2>
      <p class="sub">All your bills, downloadable anytime.</p>

      <div class="history-search-wrap">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="historySearch" class="history-search"
               placeholder="Search by bill, spot, method or date" autocomplete="off">
      </div>

      <div id="historyList" class="history-list">
        <div class="history-empty">
          <i class="fa-solid fa-circle-notch fa-spin"></i> Loading…
        </div>
      </div>

      <div class="pay-actions">
        <button class="cancel" onclick="closeHistoryModal()">Close</button>
      </div>
    </div>
  </div>

  <script src="https://www.paypal.com/sdk/js?client-id=sb&currency=USD&intent=capture"></script>
  <script src="assets/app.js"></script>

</body>
</html>