/*
 * app.js — Client-side logic for Parkster. Handles page switching (landing / dashboard),
 * the live parking map, spot selection, reservation and occupancy flows, the active-session
 * timer card, the payment modal, profile photo upload, and the 6-digit email-verification view.
 */

const IS_LOGGED_IN   = document.body.dataset.loggedIn === 'true';
const HAS_LOGIN_ERR  = document.body.dataset.loginErr === 'true';
const FORCE_REGISTER = document.body.dataset.forceRegister === 'true';
const ALL_SPOTS      = JSON.parse(document.body.dataset.spots || '[]');
const TOTAL_SPOTS    = parseInt(document.body.dataset.totalSpots  || '0');
const FREE_SPOTS     = parseInt(document.body.dataset.freeSpots   || '0');
const RESERVED_SPOTS = parseInt(document.body.dataset.reservedSpots || '0');
const OCCUPIED_SPOTS = parseInt(document.body.dataset.occupiedSpots || '0');

if (document.getElementById('statSpots'))    document.getElementById('statSpots').textContent    = TOTAL_SPOTS;
if (document.getElementById('statFree'))     document.getElementById('statFree').textContent     = FREE_SPOTS;
if (document.getElementById('statOccupied')) document.getElementById('statOccupied').textContent = OCCUPIED_SPOTS;
if (document.getElementById('liveSpots'))    document.getElementById('liveSpots').textContent    = FREE_SPOTS;

document.getElementById('page-landing').style.display   = IS_LOGGED_IN ? 'none'  : 'block';
document.getElementById('page-dashboard').style.display = IS_LOGGED_IN ? 'block' : 'none';
if (!IS_LOGGED_IN && FORCE_REGISTER)  openAuth('register');
else if (!IS_LOGGED_IN && HAS_LOGIN_ERR) openAuth('login');

if (!IS_LOGGED_IN) {
  const cur = document.getElementById('cursor');
  const rng = document.getElementById('cursorRing');
  let mx=0, my=0, rx=0, ry=0;
  document.addEventListener('mousemove', e => {
    mx = e.clientX; my = e.clientY;
    cur.style.left = (mx-6)+'px'; cur.style.top = (my-6)+'px';
  });
  (function loop(){
    rx += (mx-rx-18)*.12; ry += (my-ry-18)*.12;
    rng.style.left = rx+'px'; rng.style.top = ry+'px';
    requestAnimationFrame(loop);
  })();
  document.querySelectorAll('button,a').forEach(el => {
    el.addEventListener('mouseenter', () => { cur.style.transform='scale(2.5)'; rng.style.transform='scale(1.5)'; rng.style.opacity='.8'; });
    el.addEventListener('mouseleave', () => { cur.style.transform='';           rng.style.transform='';           rng.style.opacity='.5'; });
  });
}

document.querySelectorAll('.reveal').forEach(r => {
  new IntersectionObserver(entries => entries.forEach(e => {
    if (e.isIntersecting) e.target.classList.add('visible');
  }), { threshold: .1 }).observe(r);
});

function openAuth(tab) {
  document.getElementById('authOverlay').classList.add('open');
  switchTab(tab || 'login');
  document.body.style.overflow = 'hidden';
}
function closeAuth() {
  document.getElementById('authOverlay').classList.remove('open');
  document.body.style.overflow = '';
}
function switchTab(tab) {
  document.querySelectorAll('.auth-tab').forEach(t => t.classList.remove('active'));
  document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
  const T = tab.charAt(0).toUpperCase() + tab.slice(1);
  document.getElementById('tab'+T).classList.add('active');
  document.getElementById('form'+T).classList.add('active');
}
document.getElementById('authOverlay')?.addEventListener('click', function(e){ if(e.target===this) closeAuth(); });
document.addEventListener('keydown', e => {
  if(e.key==='Escape') {
    closeAuth();
    closeReserveModal();
  }
});

const navDashboard  = document.getElementById('nav-dashboard');
const navParking    = document.getElementById('nav-parking');
const viewDashboard = document.getElementById('view-dashboard');
const viewParking   = document.getElementById('view-parking');
let mapBuilt = false;

function dbView(v) {
  if (v === 'dash') {
    navDashboard.classList.add('active');
    navParking.classList.remove('active');
    viewDashboard.style.display = 'flex';
    viewParking.style.display   = 'none';
    if (IS_LOGGED_IN) loadUserStatus();
  } else {
    navParking.classList.add('active');
    navDashboard.classList.remove('active');
    viewDashboard.style.display = 'none';
    viewParking.style.display   = 'block';
    if (!mapBuilt) buildMap();
  }
}

let selectedSpot = null;

function carSVG(color) {
  const c = color || '#6aaa50';
  return `<svg class="car" viewBox="0 0 34 58" xmlns="http://www.w3.org/2000/svg">
    <rect x="5" y="10" width="24" height="36" rx="4" fill="${c}" opacity=".75"/>
    <path d="M9 10 L11 4 L23 4 L25 10z" fill="${c}" opacity=".85"/>
    <path d="M9 46 L11 54 L23 54 L25 46z" fill="${c}" opacity=".75"/>
    <rect x="9" y="5" width="16" height="7" rx="1" fill="rgba(180,220,255,.35)"/>
    <rect x="9" y="46" width="16" height="6" rx="1" fill="rgba(150,150,150,.25)"/>
    <rect x="3" y="12" width="5" height="9" rx="2" fill="#1a1a1a"/>
    <rect x="26" y="12" width="5" height="9" rx="2" fill="#1a1a1a"/>
    <rect x="3" y="37" width="5" height="9" rx="2" fill="#1a1a1a"/>
    <rect x="26" y="37" width="5" height="9" rx="2" fill="#1a1a1a"/>
    <rect x="6" y="7"  width="8" height="2.5" rx="1" fill="rgba(255,60,60,.7)"/>
    <rect x="20" y="7" width="8" height="2.5" rx="1" fill="rgba(255,60,60,.7)"/>
    <rect x="6" y="49" width="8" height="2.5" rx="1" fill="rgba(255,230,100,.8)"/>
    <rect x="20" y="49" width="8" height="2.5" rx="1" fill="rgba(255,230,100,.8)"/>
    <rect x="15" y="5" width="4" height="6" rx="1" fill="rgba(255,255,255,.12)"/>
  </svg>`;
}

function buildMap() {
  mapBuilt = true;
  const g = document.getElementById('garage');
  if (!ALL_SPOTS.length) {
    g.innerHTML = '<p style="color:#fff;padding:20px;">No spots configured in the database.</p>';
    return;
  }

  const zones = {};
  ALL_SPOTS.forEach(s => {
    if (!zones[s.zone]) zones[s.zone] = [];
    zones[s.zone].push(s);
  });

  g.innerHTML = '';
  let rowCount = 0;
  const zoneKeys = Object.keys(zones).sort();

  zoneKeys.forEach(z => {
    const wrap = document.createElement('div');
    wrap.className = 'row-wrap';

    const lbl = document.createElement('div');
    lbl.className = 'row-label';
    lbl.textContent = z;
    wrap.appendChild(lbl);

    const row = document.createElement('div');
    row.className = 'spot-row';

    zones[z].forEach(spot => {
      row.appendChild(buildSpotEl(spot));
    });

    wrap.appendChild(row);
    g.appendChild(wrap);

    rowCount++;
    if (rowCount % 2 === 0 && rowCount < zoneKeys.length) {
      const aisle = document.createElement('div');
      aisle.className = 'aisle';
      g.appendChild(aisle);
    }
  });

  updateStats();
}

function buildSpotEl(spot) {
  const status = spot.status;
  const cssClass = status === 'available' ? 'free'
                 : status === 'reserved'  ? 'reserved'
                 : 'taken';

  const el = document.createElement('div');
  el.className      = 'spot ' + cssClass;
  if (spot.mine) el.classList.add('mine');
  el.dataset.id     = spot.id;
  el.dataset.status = status;

  const sb = document.createElement('div');
  sb.className = 'spot-status';
  sb.textContent = status === 'available' ? 'FREE'
                 : status === 'reserved'  ? 'RES'
                 : '';
  el.appendChild(sb);

  if (status === 'occupied') {
    const carColor = spot.mine ? '#1cc7d0' : '#ef4444';
    el.innerHTML += carSVG(carColor);
  } else if (status === 'reserved') {
    const carColor = spot.mine ? '#1cc7d0' : '#f8961e';
    el.innerHTML += carSVG(carColor);
  } else {
    el.onclick = () => selectSpot(spot, el);
  }

  const num = document.createElement('div');
  num.className = 'spot-num';
  num.textContent = spot.id;
  el.appendChild(num);

  return el;
}

function selectSpot(spot, el) {
  document.querySelectorAll('.spot.selected').forEach(s => s.classList.remove('selected'));
  if (selectedSpot && selectedSpot.id === spot.id) {
    selectedSpot = null;
    updateSidebar();
    return;
  }
  selectedSpot = spot;
  el.classList.add('selected');
  updateSidebar();
}

function updateSidebar() {
  const empty = document.getElementById('sel-empty');
  const info  = document.getElementById('sel-info');

  if (!selectedSpot) {
    empty.style.display = 'block';
    info.style.display  = 'none';
    return;
  }

  empty.style.display = 'none';
  info.style.display  = 'block';
  document.getElementById('si-id').textContent = selectedSpot.id;
}

function updateStats() {
  const spots = document.querySelectorAll('.spot');
  let f = 0, r = 0, t = 0;
  spots.forEach(s => {
    if      (s.classList.contains('free'))     f++;
    else if (s.classList.contains('reserved')) r++;
    else if (s.classList.contains('taken'))    t++;
  });
  document.querySelectorAll('#cnt-f').forEach(el => el.textContent = f);
  document.querySelectorAll('#cnt-r').forEach(el => el.textContent = r);
  document.querySelectorAll('#cnt-t').forEach(el => el.textContent = t);
}

function openReserveModal() {
  if (!selectedSpot) return;
  document.getElementById('rmSpotId').textContent = selectedSpot.id;
  document.getElementById('rmSlider').value = 1;
  updateSliderDisplay(1);
  document.getElementById('reserveModal').classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeReserveModal() {
  document.getElementById('reserveModal')?.classList.remove('open');
  document.body.style.overflow = '';
}

function updateSliderDisplay(h) {
  document.getElementById('rmHours').textContent = h;
  document.getElementById('rmHoursLbl').textContent = 'HOURS';
}

document.getElementById('rmSlider')?.addEventListener('input', e => {
  updateSliderDisplay(parseInt(e.target.value));
});

document.getElementById('reserveModal')?.addEventListener('click', e => {
  if (e.target.id === 'reserveModal') closeReserveModal();
});

function confirmReserve() {
  if (!selectedSpot) return;
  const hours = parseInt(document.getElementById('rmSlider').value);
  const btn   = document.getElementById('rmConfirm');
  btn.disabled = true;
  btn.textContent = 'Reserving...';

  fetch('functions/reserve.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      spot_number:    selectedSpot.id,
      duration_hours: hours
    })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      toast('✓ Spot ' + selectedSpot.id + ' reserved for ' + hours + ' hour(s)!');
      closeReserveModal();
      setTimeout(() => {
        window.location.href = 'index.php';
      }, 1000);
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
      btn.disabled = false;
      btn.textContent = 'Confirm Reservation';
    }
  })
  .catch(err => {
    alert('Network error: ' + err.message);
    btn.disabled = false;
    btn.textContent = 'Confirm Reservation';
  });
}

function confirmOccupy() {
  if (!selectedSpot) return;
  if (!confirm('Confirm: do you want to occupy spot ' + selectedSpot.id + '?')) return;

  fetch('functions/occupy.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ spot_number: selectedSpot.id })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      toast('✓ Spot ' + selectedSpot.id + ' occupied successfully!');
      setTimeout(() => {
        window.location.href = 'index.php';
      }, 1000);
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => alert('Network error: ' + err.message));
}

let timerInterval = null;
let userStatus    = null;

function loadUserStatus() {
  if (!IS_LOGGED_IN) return;

  fetch('functions/get_user_status.php', { cache: 'no-store' })
    .then(r => r.json())
    .then(data => {
      userStatus = data;
      renderStatusCard();
    })
    .catch(err => {
      console.error('Status fetch error:', err);
      renderEmptyCard('Connection error');
    });
}

function renderStatusCard() {
  const container = document.getElementById('activeStatusContainer');
  if (!container) return;

  if (timerInterval) {
    clearInterval(timerInterval);
    timerInterval = null;
  }

  if (!userStatus || userStatus.type === 'none') {
    renderEmptyCard();
    return;
  }

  const serverNow = userStatus.now ? new Date(userStatus.now).getTime() : Date.now();
  const clientNow = Date.now();
  const skew      = serverNow - clientNow;

  if (userStatus.type === 'reservation') {
    const endMs = new Date(userStatus.end_time).getTime();
    container.innerHTML = `
      <div class="timer-card is-reservation" id="liveTimerCard">
        <div class="label-row">
          <span class="badge"><i class="fa-solid fa-clock"></i> RESERVATION</span>
          <span class="spot-id">${userStatus.spot_number}</span>
        </div>
        <div class="timer-display" id="liveTimerDisplay">--:--:--</div>
        <div class="timer-sub">Time remaining</div>
        <button class="occupy-now-btn" onclick="convertToSession()">
          <i class="fa-solid fa-car-side"></i> OCCUPY NOW
        </button>
        <button class="end-btn" onclick="endActive('reservation')">
          <i class="fa-solid fa-xmark"></i> CANCEL RESERVATION
        </button>
      </div>`;

    const tick = () => {
      const remaining = endMs - (Date.now() + skew);
      const display   = document.getElementById('liveTimerDisplay');
      const card      = document.getElementById('liveTimerCard');
      if (!display) return;
      if (remaining <= 0) {
        display.textContent = 'EXPIRED';
        card?.classList.add('expired');
        clearInterval(timerInterval);
        setTimeout(loadUserStatus, 2000);
        return;
      }
      display.textContent = formatDuration(remaining, true);
    };
    tick();
    timerInterval = setInterval(tick, 1000);

  } else if (userStatus.type === 'session') {
    const startMs = new Date(userStatus.entry_time).getTime();
    const initialFee = userStatus.current_fee || 0;
    const initialDetail = userStatus.fee_detail || '—';

    container.innerHTML = `
      <div class="timer-card is-session" id="liveTimerCard">
        <div class="label-row">
          <span class="badge"><i class="fa-solid fa-car-side"></i> PARKED</span>
          <span class="spot-id">${userStatus.spot_number}</span>
        </div>
        <div class="timer-display" id="liveTimerDisplay">00:00:00</div>
        <div class="timer-sub">Time elapsed</div>
        <div class="fee-row">
          <div>
            <div class="fee-label">Current rate</div>
            <div id="liveFeeDetail" style="font-size:11px;color:#888;margin-top:2px;">${initialDetail}</div>
          </div>
          <div class="fee-amount"><span id="liveFeeAmount">${initialFee}</span> L</div>
        </div>
        <button class="pay-btn" onclick="openPayModal()">
          <i class="fa-solid fa-credit-card"></i> PAY & FREE SPOT
        </button>
      </div>`;

    let lastFeeRefresh = Date.now();

    const tick = () => {
      const elapsed = (Date.now() + skew) - startMs;
      const display = document.getElementById('liveTimerDisplay');
      if (!display) return;
      display.textContent = formatDuration(elapsed, false);

      updateClientSideFee(elapsed);

      if (Date.now() - lastFeeRefresh > 30000) {
        lastFeeRefresh = Date.now();
        loadUserStatus();
      }
    };
    tick();
    timerInterval = setInterval(tick, 1000);
  }
}

function updateClientSideFee(elapsedMs) {
  const PRICE_HOUR  = 150;
  const PRICE_DAY   = 800;
  const PRICE_MONTH = 12000;

  let sec = Math.max(60, Math.floor(elapsedMs / 1000));
  const hours  = Math.ceil(sec / 3600);
  const days   = Math.ceil(sec / 86400);

  let amount = 0;
  let detail = '';

  if (sec <= 5 * 3600) {
    amount = hours * PRICE_HOUR;
    detail = `${hours} hour(s) × ${PRICE_HOUR} L`;
  } else if (sec <= 24 * 3600) {
    amount = PRICE_DAY;
    detail = '1 day (Pro plan)';
  } else if (sec <= 30 * 86400) {
    amount = days * PRICE_DAY;
    detail = `${days} day(s) × ${PRICE_DAY} L`;
  } else {
    const monthsFull = Math.floor(sec / (86400 * 30));
    const remainder  = sec - (monthsFull * 86400 * 30);
    const extraDays  = Math.ceil(remainder / 86400);
    amount = (monthsFull * PRICE_MONTH) + (extraDays * PRICE_DAY);
    detail = `${monthsFull} month(s) + ${extraDays} day(s)`;
  }

  const a = document.getElementById('liveFeeAmount');
  const d = document.getElementById('liveFeeDetail');
  if (a) a.textContent = amount;
  if (d) d.textContent = detail;
}

function renderEmptyCard(msg) {
  const container = document.getElementById('activeStatusContainer');
  if (!container) return;
  container.innerHTML = `
    <div class="empty-card">
      <i class="fa-solid fa-circle-check"></i>
      <div style="font-weight:700;margin-bottom:6px;color:#666;">${msg || 'No active session'}</div>
      <div style="font-size:12px;">Click "Find a Spot" to get started</div>
    </div>`;
}

function formatDuration(ms, isCountdown) {
  if (ms < 0) ms = 0;
  const totalSec = Math.floor(ms / 1000);
  const days  = Math.floor(totalSec / 86400);
  const hours = Math.floor((totalSec % 86400) / 3600);
  const mins  = Math.floor((totalSec % 3600) / 60);
  const secs  = totalSec % 60;

  const pad = n => String(n).padStart(2, '0');

  if (days > 0) {
    return `${days}d ${pad(hours)}:${pad(mins)}:${pad(secs)}`;
  }
  return `${pad(hours)}:${pad(mins)}:${pad(secs)}`;
}

function endActive(type) {
  if (type === 'session') {
    openPayModal();
    return;
  }

  if (!confirm('Confirm: do you want to cancel the reservation?')) return;

  fetch('functions/end_session.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ type })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      toast(data.message || '✓ Completed successfully');
      setTimeout(() => window.location.href = 'index.php', 800);
    } else if (data.requires_payment) {
      openPayModal();
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => alert('Network error: ' + err.message));
}

function convertToSession() {
  if (!confirm('Do you want to activate the session now? The timer will start from 0.')) return;

  fetch('functions/convert_reservation.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' }
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      toast(data.message || '✓ Session activated');
      setTimeout(() => window.location.href = 'index.php', 600);
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
    }
  })
  .catch(err => alert('Network error: ' + err.message));
}

let selectedPayMethod = 'card';

function openPayModal() {
  if (!userStatus || userStatus.type !== 'session') {
    alert('You have no active session to pay.');
    return;
  }

  const modal     = document.getElementById('payModal');
  const spotEl    = document.getElementById('paySpot');
  const durEl     = document.getElementById('payDuration');
  const detailEl  = document.getElementById('payDetail');
  const amountEl  = document.getElementById('payAmount');

  if (!modal) return;

  const startMs   = new Date(userStatus.entry_time).getTime();
  const elapsedMs = Date.now() - startMs;

  const PRICE_HOUR  = 150;
  const PRICE_DAY   = 800;
  const PRICE_MONTH = 12000;

  let sec = Math.max(60, Math.floor(elapsedMs / 1000));
  const hours = Math.ceil(sec / 3600);
  const days  = Math.ceil(sec / 86400);

  let amount = 0;
  let detail = '';
  if (sec <= 5 * 3600) {
    amount = hours * PRICE_HOUR;
    detail = `${hours} hour(s) × ${PRICE_HOUR} L`;
  } else if (sec <= 24 * 3600) {
    amount = PRICE_DAY;
    detail = '1 day (Pro plan)';
  } else if (sec <= 30 * 86400) {
    amount = days * PRICE_DAY;
    detail = `${days} day(s) × ${PRICE_DAY} L`;
  } else {
    const monthsFull = Math.floor(sec / (86400 * 30));
    const remainder  = sec - (monthsFull * 86400 * 30);
    const extraDays  = Math.ceil(remainder / 86400);
    amount = (monthsFull * PRICE_MONTH) + (extraDays * PRICE_DAY);
    detail = `${monthsFull} month(s) + ${extraDays} day(s)`;
  }

  spotEl.textContent   = userStatus.spot_number;
  durEl.textContent    = formatDuration(elapsedMs, false);
  detailEl.textContent = detail;
  amountEl.textContent = amount;

  modal.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closePayModal() {
  const modal = document.getElementById('payModal');
  if (!modal) return;
  modal.classList.remove('open');
  document.body.style.overflow = '';
}

function selectPayMethod(method) {
  selectedPayMethod = method;
  document.querySelectorAll('.pay-method').forEach(el => {
    el.classList.toggle('selected', el.dataset.method === method);
  });
  const cardForm = document.getElementById('payCardForm');
  if (cardForm) cardForm.classList.toggle('show', method === 'card');
}

function confirmPayment() {
  if (selectedPayMethod === 'card') {
    const num    = document.getElementById('cardNumber')?.value.replace(/\s/g, '') || '';
    const expiry = document.getElementById('cardExpiry')?.value || '';
    const cvv    = document.getElementById('cardCvv')?.value || '';
    const name   = document.getElementById('cardName')?.value.trim() || '';

    if (!/^\d{13,19}$/.test(num))       { alert('Invalid card number.'); return; }
    if (!/^\d{2}\/\d{2}$/.test(expiry)) { alert('Expiry must be in MM/YY format.'); return; }
    if (!/^\d{3,4}$/.test(cvv))         { alert('Invalid CVV.'); return; }
    if (name.length < 2)                { alert('Please enter the name on the card.'); return; }
  }

  const btn = document.getElementById('payConfirmBtn');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Processing...';
  }

  fetch('functions/pay_session.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ payment_method: selectedPayMethod })
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      toast(`✓ Paid ${data.amount} L (${selectedPayMethod === 'card' ? 'Card' : 'Cash'})`);
      closePayModal();
      setTimeout(() => window.location.href = 'index.php', 1000);
    } else {
      alert('Error: ' + (data.error || 'Unknown error'));
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-check"></i> Pay';
      }
    }
  })
  .catch(err => {
    alert('Network error: ' + err.message);
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-check"></i> Pay';
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  const cn = document.getElementById('cardNumber');
  if (cn) {
    cn.addEventListener('input', e => {
      let v = e.target.value.replace(/\s/g, '').replace(/\D/g, '');
      e.target.value = v.match(/.{1,4}/g)?.join(' ') || '';
    });
  }
  const ce = document.getElementById('cardExpiry');
  if (ce) {
    ce.addEventListener('input', e => {
      let v = e.target.value.replace(/\D/g, '');
      if (v.length >= 3) v = v.slice(0, 2) + '/' + v.slice(2, 4);
      e.target.value = v;
    });
  }
  const cv = document.getElementById('cardCvv');
  if (cv) {
    cv.addEventListener('input', e => {
      e.target.value = e.target.value.replace(/\D/g, '');
    });
  }

  document.getElementById('payModal')?.addEventListener('click', function(e) {
    if (e.target === this) closePayModal();
  });
});

function toast(msg) {
  const t = document.getElementById('toast');
  if (!t) {
    const tmp = document.createElement('div');
    tmp.style.cssText = 'position:fixed;top:20px;right:20px;background:#28a745;color:#fff;padding:12px 22px;border-radius:6px;z-index:9999;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,.15);';
    tmp.textContent = msg;
    document.body.appendChild(tmp);
    setTimeout(() => tmp.remove(), 2800);
    return;
  }
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 2800);
}

if (IS_LOGGED_IN) {
  loadUserStatus();
  setInterval(loadUserStatus, 60000);
}

function openModal()  { document.getElementById('editProfileModal').style.display = 'flex'; }
function closeModal() { document.getElementById('editProfileModal').style.display = 'none'; }

document.addEventListener('click', function(e) {
  var modal = document.getElementById('editProfileModal');
  if (modal && e.target === modal) closeModal();
});

document.addEventListener('DOMContentLoaded', function() {
  var photoInput = document.getElementById('photoFileInput');
  if (photoInput) {
    photoInput.addEventListener('change', function() {
      if (this.files && this.files.length > 0) {
        document.getElementById('photoUploadForm').submit();
      }
    });
  }
});

(function() {
    const SHOW_VERIFY = document.body.dataset.showVerify === 'true';
    const authBox     = document.querySelector('.auth-box');
    const formVerify  = document.getElementById('formVerify');
    const overlay     = document.getElementById('authOverlay');

    window.showVerifyView = function() {
      if (!authBox || !formVerify) return;
      if (overlay) {
        overlay.classList.add('open');
        document.body.style.overflow = 'hidden';
      }
      document.querySelectorAll('.auth-form').forEach(f => f.classList.remove('active'));
      formVerify.classList.add('active');
      authBox.classList.add('verify-mode');

      const first = document.querySelector('#codeRow input');
      if (first) setTimeout(() => first.focus(), 60);
    };

    if (typeof window.openAuth === 'function') {
      const _origOpenAuth = window.openAuth;
      window.openAuth = function(tab) {
        if (authBox) authBox.classList.remove('verify-mode');
        if (formVerify) formVerify.classList.remove('active');
        return _origOpenAuth.call(this, tab);
      };
    }
    if (typeof window.switchTab === 'function') {
      const _origSwitchTab = window.switchTab;
      window.switchTab = function(tab) {
        if (tab === 'login' || tab === 'register') {
          if (authBox) authBox.classList.remove('verify-mode');
          if (formVerify) formVerify.classList.remove('active');
        }
        return _origSwitchTab.call(this, tab);
      };
    }

    if (SHOW_VERIFY) {
      setTimeout(showVerifyView, 50);
    }

    const inputs = document.querySelectorAll('#codeRow input');
    const hidden = document.getElementById('codeHidden');
    const form   = document.getElementById('verifyCodeForm');

    function updateHidden() {
      if (!hidden) return;
      hidden.value = [...inputs].map(x => x.value).join('');
      [...inputs].forEach(x => x.classList.toggle('filled', x.value.length === 1));
    }
    function maybeAutoSubmit() {
      if (!form) return;
      if ([...inputs].every(x => x.value.length === 1)) form.requestSubmit();
    }

    inputs.forEach((inp, i) => {
      inp.addEventListener('input', () => {
        inp.value = inp.value.replace(/\D/g, '').slice(0, 1);
        if (inp.value && i < inputs.length - 1) inputs[i + 1].focus();
        updateHidden();
        maybeAutoSubmit();
      });
      inp.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !inp.value && i > 0) {
          inputs[i - 1].focus();
          inputs[i - 1].value = '';
          updateHidden();
          e.preventDefault();
        }
        if (e.key === 'ArrowLeft'  && i > 0)                   inputs[i - 1].focus();
        if (e.key === 'ArrowRight' && i < inputs.length - 1)   inputs[i + 1].focus();
      });
      inp.addEventListener('paste', e => {
        const data = (e.clipboardData || window.clipboardData)
                       .getData('text').replace(/\D/g, '').slice(0, 6);
        if (!data) return;
        e.preventDefault();
        for (let k = 0; k < data.length && (i + k) < inputs.length; k++) {
          inputs[i + k].value = data[k];
        }
        updateHidden();
        const last = Math.min(i + data.length, inputs.length - 1);
        inputs[last].focus();
        maybeAutoSubmit();
      });
    });

    let secondsLeft = parseInt(document.body.dataset.verifyExpiresIn || '0', 10);
    const cdEl   = document.getElementById('vCountdown');
    const tRow   = document.getElementById('verifyTimer');
    const subBtn = document.getElementById('verifySubmitBtn');
    let tickTimer = null;

    function tick() {
      if (secondsLeft <= 0) {
        if (tRow) {
          tRow.classList.add('expired');
          tRow.innerHTML = '<span class="t">Code has expired — request a new one</span>';
        }
        if (subBtn) subBtn.disabled = true;
        if (tickTimer) clearInterval(tickTimer);
        return;
      }
      secondsLeft--;
      const m = String(Math.floor(secondsLeft / 60)).padStart(2, '0');
      const s = String(secondsLeft % 60).padStart(2, '0');
      if (cdEl) cdEl.textContent = `${m}:${s}`;
    }
    if (secondsLeft > 0 && cdEl) tickTimer = setInterval(tick, 1000);

    const resendBtn   = document.getElementById('vResendBtn');
    const cooldownKey = 'parkster_resend_cooldown';
    function applyCooldown() {
      if (!resendBtn) return;
      const last = parseInt(localStorage.getItem(cooldownKey) || '0', 10);
      const remaining = 60 - Math.floor((Date.now() - last) / 1000);
      if (remaining > 0) {
        resendBtn.disabled = true;
        resendBtn.textContent = `Wait ${remaining}s...`;
        setTimeout(applyCooldown, 1000);
      } else {
        resendBtn.disabled = false;
        resendBtn.textContent = 'Resend';
      }
    }
    const resendForm = document.querySelector('.verify-resend form');
    if (resendForm) {
      resendForm.addEventListener('submit', () => {
        localStorage.setItem(cooldownKey, Date.now().toString());
      });
    }
    applyCooldown();
})();