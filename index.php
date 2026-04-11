<?php
session_start();
require_once 'includes/db.php'; 

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
        'id' => $spot['spot_number'],
        'zone' => $zoneLetter,
        'status' => $spot['status'], 
        'type' => 'standard', 
        'occupant' => null, 
        'timeElapsed' => null
    ];
}
$spots_json = json_encode($js_spots);
$is_logged_in_js = isset($_SESSION['user_id']) ? 'true' : 'false';

$has_login_error = isset($_GET['error']) ? 'true' : 'false';
$force_register_view = isset($_GET['show_register']) ? 'true' : 'false';

$error_msg = isset($_SESSION['login_error']) ? $_SESSION['login_error'] : '';
$reg_error = isset($_SESSION['register_error']) ? $_SESSION['register_error'] : '';

unset($_SESSION['login_error'], $_SESSION['register_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>AutoSystemPark</title>
  
  <script src="https://cdn.tailwindcss.com"></script>
  <script src="https://unpkg.com/lucide@latest"></script>
  <link rel="stylesheet" href="style.css">

  <script>
    const DYNAMIC_SPOTS = <?php echo $spots_json; ?>;
    const USER_IS_LOGGED_IN = <?php echo $is_logged_in_js; ?>;
    const FORCE_SHOW_LOGIN = <?php echo $has_login_error; ?>;
    const FORCE_SHOW_REGISTER = <?php echo $force_register_view; ?>;
  </script>
</head>
<body class="text-gray-900 selection:bg-emerald-100 selection:text-emerald-900">

  <div id="login-screen" class="hidden min-h-screen flex flex-col justify-center items-center p-6 relative overflow-hidden py-12">
    <div class="absolute top-[-10%] left-[-10%] w-96 h-96 bg-blue-100 rounded-full mix-blend-multiply filter blur-3xl opacity-50"></div>
    <div class="absolute bottom-[-10%] right-[-10%] w-96 h-96 bg-emerald-100 rounded-full mix-blend-multiply filter blur-3xl opacity-50"></div>

    <div class="w-full max-w-md bg-white rounded-3xl shadow-[0_8px_30px_rgb(0,0,0,0.04)] p-8 relative z-10 border border-gray-100">
      <div class="flex justify-center mb-6">
        <div class="w-16 h-16 bg-gray-900 rounded-2xl flex items-center justify-center shadow-lg">
          <i data-lucide="car" class="text-white w-8 h-8"></i>
        </div>
      </div>
      
      <div class="text-center mb-6">
        <h1 id="auth-title" class="text-2xl font-bold text-gray-900 tracking-tight">AutoSistemPark</h1>
        <p id="auth-subtitle" class="text-gray-500 mt-2 text-sm">Sign in to manage the space</p>
        
        <?php if($error_msg): ?>
            <p class="text-rose-500 mt-3 text-sm font-bold bg-rose-50 py-2 rounded-lg"><?php echo $error_msg; ?></p>
        <?php endif; ?>
        <?php if($reg_error): ?>
            <p class="text-rose-500 mt-3 text-sm font-bold bg-rose-50 py-2 rounded-lg"><?php echo $reg_error; ?></p>
        <?php endif; ?>
      </div>

      <form id="login-form" method="POST" action="login.php" class="space-y-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Email</label>
          <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-gray-900 outline-none text-gray-900">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1.5">Password</label>
          <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-gray-900 outline-none text-gray-900">
        </div>
        <div class="flex items-center justify-between pb-2">
          <label class="flex items-center space-x-2 cursor-pointer">
            <input type="checkbox" name="remember_me" class="rounded border-gray-300 text-gray-900 focus:ring-gray-900 w-4 h-4">
            <span class="text-sm text-gray-600">Remember me</span>
          </label>
        </div>
        <button type="submit" class="w-full bg-gray-900 hover:bg-gray-800 text-white font-medium py-3.5 rounded-xl transition-all shadow-md mt-2">Login</button>
        <div class="text-center pt-2">
          <span class="text-sm text-gray-500">Don't have an account?</span>
          <button type="button" id="switch-to-register" class="text-sm font-bold text-emerald-600 hover:text-emerald-700 ml-1">Register</button>
        </div>
      </form>

      <form id="register-form" method="POST" action="register.php" class="space-y-4 hidden">
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">First Name</label>
            <input type="text" name="first_name" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 outline-none text-gray-900">
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Last Name</label>
            <input type="text" name="last_name" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 outline-none text-gray-900">
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
          <input type="email" name="email" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 outline-none text-gray-900">
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
          <input type="password" name="password" required class="w-full px-4 py-3 rounded-xl border border-gray-200 bg-gray-50 focus:bg-white focus:ring-2 focus:ring-emerald-500 outline-none text-gray-900">
        </div>
        <button type="submit" class="w-full bg-emerald-500 hover:bg-emerald-600 text-white font-medium py-3.5 rounded-xl transition-all shadow-md mt-4">Create Account</button>
        <div class="text-center pt-2">
          <span class="text-sm text-gray-500">Already have an account?</span>
          <button type="button" id="switch-to-login" class="text-sm font-bold text-gray-900 hover:text-gray-700 ml-1">Login</button>
        </div>
      </form>

      <div class="text-center mt-6">
         <button type="button" id="back-to-dashboard-btn" class="text-sm font-medium text-gray-400 hover:text-gray-600 transition-colors">← Back to Dashboard</button>
      </div>
    </div>
  </div>

  <div id="dashboard-screen" class="min-h-screen pb-24 lg:pb-8">
    <header class="bg-white sticky top-0 z-30 px-6 py-4 shadow-[0_2px_10px_rgb(0,0,0,0.02)] border-b border-gray-100 flex justify-between items-center">
      <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-gray-900 rounded-xl flex items-center justify-center">
          <i data-lucide="map" class="text-white w-5 h-5"></i>
        </div>
        <div>
          <h1 class="font-bold text-gray-900 leading-tight">AutoSystemPark</h1>
          <p class="text-xs text-gray-500 font-medium">Live Map</p>
        </div>
      </div>
      
      <div class="flex items-center gap-3">
        <button id="nav-login-btn" class="hidden h-[40px] px-5 bg-gray-900 text-white rounded-xl text-sm font-medium hover:bg-gray-800 transition-colors shadow-sm flex items-center justify-center">
          Login
        </button>
        <button id="nav-register-btn" class="hidden h-[40px] px-5 bg-gray-900 text-white rounded-xl text-sm font-medium hover:bg-gray-800 transition-colors shadow-sm flex items-center justify-center">
          Register
        </button>
        <a href="logout.php" id="logout-btn" class="hidden h-[40px] px-5 bg-gray-900 text-white rounded-xl text-sm font-medium hover:bg-gray-800 transition-colors shadow-sm flex items-center justify-center" title="Logout">
          Logout
        </a>
      </div>
    </header>

    <main class="max-w-5xl mx-auto p-4 sm:p-6 lg:p-8 space-y-6">
      <div class="grid grid-cols-4 gap-3">
        <div class="col-span-4 sm:col-span-1 bg-white p-4 rounded-2xl shadow-sm border border-gray-100">
          <p class="text-sm text-gray-500 font-medium mb-1">Total Capacity</p>
          <p id="stat-total" class="text-2xl font-bold text-gray-900">0</p>
        </div>
        <div class="col-span-4 sm:col-span-3 grid grid-cols-3 gap-3">
          <div class="filter-card p-4 rounded-2xl cursor-pointer transition-all border border-gray-100 bg-white shadow-sm" data-filter="available">
            <div class="flex items-center gap-2 mb-1">
              <div class="w-2.5 h-2.5 rounded-full bg-emerald-500"></div>
              <p class="text-sm text-gray-500 font-medium">Available</p>
            </div>
            <p id="stat-available" class="text-2xl font-bold text-gray-900">0</p>
          </div>
          <div class="filter-card p-4 rounded-2xl cursor-pointer transition-all border border-gray-100 bg-white shadow-sm" data-filter="reserved">
            <div class="flex items-center gap-2 mb-1">
              <div class="w-2.5 h-2.5 rounded-full bg-orange-500"></div>
              <p class="text-sm text-gray-500 font-medium">Reserved</p>
            </div>
            <p id="stat-reserved" class="text-2xl font-bold text-gray-900">0</p>
          </div>
          <div class="filter-card p-4 rounded-2xl cursor-pointer transition-all border border-gray-100 bg-white shadow-sm" data-filter="occupied">
            <div class="flex items-center gap-2 mb-1">
              <div class="w-2.5 h-2.5 rounded-full bg-rose-500"></div>
              <p class="text-sm text-gray-500 font-medium">Occupied</p>
            </div>
            <p id="stat-occupied" class="text-2xl font-bold text-gray-900">0</p>
          </div>
        </div>
      </div>

      <div class="flex items-center justify-between pt-2">
        <h2 class="text-lg font-bold text-gray-900">Parking Layout</h2>
      </div>

      <div class="bg-white rounded-3xl shadow-sm border border-gray-100 p-4 sm:p-8">
        
        <div class="grid grid-cols-[1fr_30px_1fr_30px_1fr] sm:grid-cols-[1fr_50px_1fr_50px_1fr] gap-x-2 sm:gap-x-4 max-w-5xl mx-auto">
          
          <div id="zone-a" class="flex flex-col gap-3"></div>

          <div class="bg-gray-50/50 border-x border-gray-100 rounded-lg flex flex-col justify-between py-12 items-center">
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
             <i data-lucide="arrow-down" class="text-gray-400 w-5 h-5"></i>
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
             <i data-lucide="arrow-down" class="text-gray-400 w-5 h-5"></i>
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
          </div>

          <div id="zone-b" class="flex flex-col gap-3"></div>

          <div class="bg-gray-50/50 border-x border-gray-100 rounded-lg flex flex-col justify-between py-12 items-center">
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
             <i data-lucide="arrow-down" class="text-gray-400 w-5 h-5"></i>
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
             <i data-lucide="arrow-down" class="text-gray-400 w-5 h-5"></i>
             <div class="w-1 h-12 bg-gray-300 rounded-full"></div>
          </div>

          <div id="zone-c" class="flex flex-col gap-3"></div>

        </div>

      </div>
    </main>
  </div>

  <div id="modal-backdrop" class="fixed inset-0 bg-gray-900/30 backdrop-blur-sm z-40 hidden transition-opacity opacity-0"></div>
  <div id="modal-sheet" class="modal-hidden fixed bottom-0 left-0 right-0 sm:top-1/2 sm:left-1/2 sm:w-full sm:max-w-md sm:h-auto sm:bottom-auto bg-white rounded-t-3xl sm:rounded-3xl z-50 p-6 shadow-2xl border border-gray-100 flex flex-col">
    <div class="w-12 h-1.5 bg-gray-200 rounded-full mx-auto mb-6 sm:hidden"></div>
    <div class="flex justify-between items-start mb-6">
      <div>
        <h3 id="modal-title" class="text-2xl font-bold text-gray-900">Spot --</h3>
        <p id="modal-subtitle" class="text-gray-500 text-sm capitalize">Parking -- • Zone --</p>
      </div>
      <button id="modal-close" class="p-2 bg-gray-50 rounded-full text-gray-500 hover:bg-gray-100">
        <i data-lucide="x" class="w-5 h-5"></i>
      </button>
    </div>
    <div class="space-y-4 mb-8">
      <div id="modal-status-card" class="p-4 rounded-2xl flex items-center gap-4 bg-gray-50">
        <div id="modal-status-icon-wrap" class="p-3 rounded-xl bg-white shadow-sm text-gray-500">
          <i id="modal-status-icon" data-lucide="car" class="w-6 h-6"></i>
        </div>
        <div>
          <p class="text-sm font-medium text-gray-500 mb-0.5">Current Status</p>
          <p id="modal-status-text" class="text-lg font-bold capitalize text-gray-500">--</p>
        </div>
      </div>
      <div id="modal-occupant-details" class="grid grid-cols-2 gap-3 hidden">
        <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/50">
          <p class="text-xs text-gray-500 mb-1 flex items-center gap-1"><i data-lucide="car" class="w-3 h-3"></i> License Plate</p>
          <p id="modal-license" class="font-bold text-gray-900">--</p>
        </div>
        <div class="border border-gray-100 rounded-2xl p-4 bg-gray-50/50">
          <p class="text-xs text-gray-500 mb-1 flex items-center gap-1"><i data-lucide="clock" class="w-3 h-3"></i> Duration</p>
          <p id="modal-duration" class="font-bold text-gray-900">--</p>
        </div>
      </div>
    </div>
    <div id="modal-actions" class="flex gap-3 mt-auto"></div>
  </div>

  <script src="app.js?v=5"></script>
</body>
</html>