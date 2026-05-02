// ═══════════════════════════════════════════════════════════════════════════
//  PARKSTER — app.js
//  • Landing page animations
//  • Auth modal
//  • Dashboard / Parking map
//  • PayPal payment + reserve.php DB sync
// ═══════════════════════════════════════════════════════════════════════════

const IS_LOGGED_IN    = document.body.dataset.loggedIn === 'true';
const HAS_LOGIN_ERR   = document.body.dataset.loginErr === 'true';
const FORCE_REGISTER  = document.body.dataset.forceRegister === 'true';
const ALL_SPOTS       = JSON.parse(document.body.dataset.spots || '[]');
const TOTAL_SPOTS     = parseInt(document.body.dataset.totalSpots  || '0');
const FREE_SPOTS_DB   = parseInt(document.body.dataset.freeSpots   || '0');
const RESERVED_SPOTS  = parseInt(document.body.dataset.reservedSpots  || '0');
const OCCUPIED_SPOTS  = parseInt(document.body.dataset.occupiedSpots  || '0');

// Prices & constants
const RATE_PER_HOUR = 150;    // ALL (Albanian Lek)
const BASE_FEE      = 20;     // ALL
const LEK_TO_EUR    = 0.0096; // approximate conversion

// ── Seed stat elements on page load ──────────────────────────────────────────
if (document.getElementById('statSpots'))    document.getElementById('statSpots').textContent    = TOTAL_SPOTS;
if (document.getElementById('statFree'))     document.getElementById('statFree').textContent     = FREE_SPOTS_DB;
if (document.getElementById('statOccupied')) document.getElementById('statOccupied').textContent = OCCUPIED_SPOTS;
if (document.getElementById('liveSpots'))    document.getElementById('liveSpots').textContent    = FREE_SPOTS_DB;

// ── Page switch ───────────────────────────────────────────────────────────────
document.getElementById('page-landing').style.display   = IS_LOGGED_IN ? 'none'  : 'block';
document.getElementById('page-dashboard').style.display = IS_LOGGED_IN ? 'block' : 'none';
if (!IS_LOGGED_IN && FORCE_REGISTER) openAuth('register');
else if (!IS_LOGGED_IN && HAS_LOGIN_ERR) openAuth('login');

// ── Custom cursor (landing only) ──────────────────────────────────────────────
if (!IS_LOGGED_IN) {
  const cur = document.getElementById('cursor');
  const rng = document.getElementById('cursorRing');
  let mx=0,my=0,rx=0,ry=0;
  document.addEventListener('mousemove', e => {
    mx=e.clientX; my=e.clientY;
    cur.style.left=(mx-6)+'px'; cur.style.top=(my-6)+'px';
  });
  (function loop(){
    rx+=(mx-rx-18)*.12; ry+=(my-ry-18)*.12;
    rng.style.left=rx+'px'; rng.style.top=ry+'px';
    requestAnimationFrame(loop);
  })();
  document.querySelectorAll('button,a').forEach(el => {
    el.addEventListener('mouseenter', () => { cur.style.transform='scale(2.5)'; rng.style.transform='scale(1.5)'; rng.style.opacity='.8'; });
    el.addEventListener('mouseleave', () => { cur.style.transform=''; rng.style.transform=''; rng.style.opacity='.5'; });
  });
}

// ── Scroll reveal ─────────────────────────────────────────────────────────────
document.querySelectorAll('.reveal').forEach(r => {
  new IntersectionObserver(entries => entries.forEach(e => {
    if (e.isIntersecting) e.target.classList.add('visible');
  }), { threshold: .1 }).observe(r);
});

// ── Auth modal ────────────────────────────────────────────────────────────────
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
document.getElementById('authOverlay').addEventListener('click', function(e) { if (e.target === this) closeAuth(); });
document.addEventListener('keydown', e => { if (e.key === 'Escape') { closeAuth(); closePP(); } });

// ── Dashboard nav ─────────────────────────────────────────────────────────────
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
  } else {
    navParking.classList.add('active');
    navDashboard.classList.remove('active');
    viewDashboard.style.display = 'none';
    viewParking.style.display   = 'block';
    if (!mapBuilt) buildMap();
  }
}

// ═══════════════════════════════════════════════════════════════════════════
//  GARAGE MAP
// ═══════════════════════════════════════════════════════════════════════════
let selectedSpot = null;
let duration     = 1;

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
    <rect x="6" y="7" width="8" height="2.5" rx="1" fill="rgba(255,60,60,.7)"/>
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
    g.innerHTML = '<p style="color:#fff;padding:20px">Nuk ka vende të konfiguruara.</p>';
    return;
  }

  // Group by zone
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
      const el = buildSpotEl(spot);
      row.appendChild(el);
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

// Build a single spot DOM element based on its DB status
function buildSpotEl(spot) {
  const status = spot.status; // 'available', 'reserved', 'occupied'

  const el = document.createElement('div');
  const cssClass = status === 'available' ? 'free'
                 : status === 'reserved'  ? 'reserved'
                 : 'taken';
  el.className      = 'spot ' + cssClass;
  el.dataset.id     = spot.id;
  el.dataset.status = status;

  const sb = document.createElement('div');
  sb.className = 'spot-status';
  sb.textContent = status === 'available' ? 'LIRË'
                 : status === 'reserved'  ? 'RES'
                 : '';
  el.appendChild(sb);

  if (status === 'occupied') {
    el.innerHTML += carSVG('#6aaa50');
  } else if (status === 'reserved') {
    el.innerHTML += carSVG('#c8a830');
    // Reserved spots are not clickable by others
  } else {
    // available — clickable
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
  const empty    = document.getElementById('sel-empty');
  const info     = document.getElementById('sel-info');
  const payBtn   = document.getElementById('pay-btn');
  const timesBox = document.getElementById('times-box');

  if (!selectedSpot) {
    empty.style.display    = 'block';
    info.style.display     = 'none';
    payBtn.style.display   = 'none';
    timesBox.style.display = 'none';
    return;
  }

  empty.style.display    = 'none';
  info.style.display     = 'block';
  payBtn.style.display   = 'block';
  timesBox.style.display = 'block';

  document.getElementById('si-id').textContent     = selectedSpot.id;
  document.getElementById('si-type').textContent   = 'Standard — Sektori ' + selectedSpot.zone;
  document.getElementById('si-status').textContent = 'I lirë';
  document.getElementById('si-rate').textContent   = RATE_PER_HOUR + ' L';
  document.getElementById('pr-rate').textContent   = RATE_PER_HOUR + ' L';
  document.getElementById('pr-dur').textContent    = duration + ' orë';
  document.getElementById('pr-total').textContent  = totalLek() + ' L';

  const now = new Date();
  const out = new Date(now.getTime() + duration * 3600000);
  document.getElementById('t-in').textContent  = fmtTime(now);
  document.getElementById('t-out').textContent = fmtTime(out);
}

function totalLek() { return RATE_PER_HOUR * duration + BASE_FEE; }
function totalEur() { return (totalLek() * LEK_TO_EUR).toFixed(2); }
function fmtTime(d) { return d.toTimeString().slice(0, 5); }

// Duration buttons
document.getElementById('dur-row')?.addEventListener('click', e => {
  const b = e.target.closest('.dur');
  if (!b) return;
  document.querySelectorAll('.dur').forEach(x => x.classList.remove('active'));
  b.classList.add('active');
  duration = parseInt(b.dataset.h);
  updateSidebar();
});

function updateStats() {
  let f=0, r=0, t=0;
  document.querySelectorAll('.spot').forEach(s => {
    if      (s.classList.contains('free'))     f++;
    else if (s.classList.contains('reserved')) r++;
    else if (s.classList.contains('taken'))    t++;
  });
  const el = id => document.getElementById(id);
  if (el('cnt-f')) el('cnt-f').textContent = f;
  if (el('cnt-r')) el('cnt-r').textContent = r;
  if (el('cnt-t')) el('cnt-t').textContent = t;
}

// ── "Konfirmo & Paguaj" button → open PayPal modal ───────────────────────────
function doPay() {
  if (!selectedSpot) return;
  openPP();
}

// ═══════════════════════════════════════════════════════════════════════════
//  PAYPAL MODAL
// ═══════════════════════════════════════════════════════════════════════════
let ppRendered = false;

function openPP() {
  if (!selectedSpot) return;
  const overlay = document.getElementById('ppOverlay');

  const now = new Date();
  const out = new Date(now.getTime() + duration * 3600000);

  document.getElementById('pp-spot').textContent  = selectedSpot.id;
  document.getElementById('pp-zone').textContent  = 'Sektori ' + selectedSpot.zone;
  document.getElementById('pp-dur').textContent   = duration + (duration === 1 ? ' orë' : ' orë');
  document.getElementById('pp-in').textContent    = fmtTime(now);
  document.getElementById('pp-out').textContent   = fmtTime(out);
  document.getElementById('pp-total').textContent = totalLek() + ' L';
  document.getElementById('pp-eur').textContent   = '≈ €' + totalEur();

  ppStatus('', false);
  overlay.classList.add('open');
  document.body.style.overflow = 'hidden';

  if (!ppRendered) renderPayPalButtons();
}

function closePP() {
  document.getElementById('ppOverlay').classList.remove('open');
  document.body.style.overflow = '';
}

document.getElementById('ppClose')?.addEventListener('click', closePP);
document.getElementById('ppOverlay')?.addEventListener('click', function(e) {
  if (e.target === this) closePP();
});

function ppStatus(msg, isError) {
  const el = document.getElementById('pp-status');
  if (!msg) { el.style.display = 'none'; return; }
  el.style.display = 'block';
  el.className = 'pp-status ' + (isError ? 'pp-error' : 'pp-success');
  el.textContent = msg;
}

function renderPayPalButtons() {
  ppRendered = true;
  paypal.Buttons({
    style: {
      layout: 'vertical',
      color:  'gold',
      shape:  'rect',
      label:  'pay',
      height: 44,
    },

    createOrder: function(data, actions) {
      return actions.order.create({
        purchase_units: [{
          description: 'Parkster — Vend ' + (selectedSpot ? selectedSpot.id : ''),
          amount: {
            currency_code: 'EUR',
            value: totalEur(),
          }
        }]
      });
    },

    onApprove: function(data, actions) {
      ppStatus('Duke procesuar pagesën…', false);

      return actions.order.capture().then(function(details) {
        const orderID = details.id;
        const body = {
          spot_number:     selectedSpot.id,
          duration_hours:  duration,
          paypal_order_id: orderID,
          amount:          parseFloat(totalEur()),
        };

        return fetch('reserve.php', {
          method:  'POST',
          headers: { 'Content-Type': 'application/json' },
          body:    JSON.stringify(body),
        })
        .then(r => r.json())
        .then(res => {
          if (res.success) {
            ppStatus('✓ Pagesa u krye! Rezervimi u konfirmua.', false);
            onReservationSuccess(res);
          } else {
            ppStatus('Gabim: ' + (res.error || 'Rezervimi dështoi.'), true);
          }
        })
        .catch(() => ppStatus('Gabim rrjeti. Ju lutemi kontaktoni mbështetjen.', true));
      });
    },

    onError: function(err) {
      ppStatus('Pagesa dështoi. Provoni përsëri.', true);
      console.error('PayPal error:', err);
    },

    onCancel: function() {
      ppStatus('Pagesa u anulua.', true);
    },
  }).render('#paypal-button-container');
}

// Called after successful payment + DB write
function onReservationSuccess(res) {
  const spotId = res.spot_number;

  const el = document.querySelector(`.spot[data-id="${spotId}"]`);
  if (el) {
    el.classList.remove('free', 'selected');
    el.classList.add('reserved');
    el.dataset.status = 'reserved';
    el.onclick = null;
    const sb = el.querySelector('.spot-status');
    if (sb) sb.textContent = 'RES';
    const oldCar = el.querySelector('.car');
    if (oldCar) oldCar.remove();
    el.innerHTML += carSVG('#c8a830');
    const numEl = document.createElement('div');
    numEl.className = 'spot-num';
    numEl.textContent = spotId;
    el.appendChild(numEl);
  }

  toast('✓ Vendi ' + spotId + ' u rezervua me sukses!');
  createJobCard(spotId, res);

  selectedSpot = null;
  updateSidebar();
  updateStats();

  ppRendered = false;
  document.getElementById('paypal-button-container').innerHTML = '';

  setTimeout(() => {
    closePP();
    setTimeout(() => dbView('dash'), 400);
  }, 1800);
}

// ═══════════════════════════════════════════════════════════════════════════
//  SESSION CARDS (SLIDER)
// ═══════════════════════════════════════════════════════════════════════════
const sliderTrack = document.getElementById('sliderTrack');
const notifBadge  = document.getElementById('notif-badge');
let maxScrolls = 0;

function createJobCard(spotId, res) {
  notifBadge.style.display = 'flex';

  const now     = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const endTime = new Date(Date.now() + duration * 3600000)
                    .toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
  const total   = totalLek();

  const newCard = document.createElement('div');
  newCard.className = 'job-card';
  newCard.dataset.spotId = spotId;
  newCard.innerHTML = `
    <div class="job-banner" style="background:linear-gradient(135deg,#1a1a1a,#2a2a2a);">
      <div class="company-logo" style="background:#fff;">
        <strong style="color:var(--teal);font-size:18px;">${spotId}</strong>
      </div>
      <div class="no-match-badge" style="color:var(--teal);">RESERVED</div>
    </div>
    <div class="job-body">
      <div class="job-company">Parkim Aktiv</div>
      <div class="job-title">Automjeti u rezervua me sukses në Sektorin ${spotId.charAt(0)}</div>
      <div class="job-location">Vendi: ${spotId} &nbsp;|&nbsp; ${duration} orë &nbsp;|&nbsp; ${total} L</div>
      <div class="open-badge" style="border-color:#2dde98;color:#2dde98;">ACTIVE</div>
      <div class="status-bars">
        <span class="active" style="background:#2dde98;"></span>
        <span class="active" style="background:#2dde98;"></span>
        <span class="active" style="background:#2dde98;"></span>
        <span></span><span></span>
      </div>
      <div class="job-footer-text">
        <i class="fa-solid fa-clock" style="color:#2dde98;"></i>
        <div>Check-in: <span style="color:#2dde98;font-size:11px;">${now}</span><br>
             Check-out: <span style="color:#c8a830;font-size:11px;">${endTime}</span></div>
      </div>
      <div class="pp-paid-badge"><i class="fa-brands fa-paypal"></i> PayPal — €${parseFloat(res.amount).toFixed(2)}</div>
      <div class="withdraw-btn" style="color:#ff6c5f;" onclick="endSession(this,'${spotId}')">
        <i class="fa-solid fa-circle-xmark"></i> Përfundo Sesionin
      </div>
    </div>
  `;

  sliderTrack.insertBefore(newCard, sliderTrack.firstChild);
  maxScrolls++;
}

function endSession(btnElement, spotId) {
  if (!confirm(`Përfundo sesionin për vendin ${spotId}?`)) return;

  btnElement.closest('.job-card').remove();
  maxScrolls = Math.max(0, maxScrolls - 1);
  if (maxScrolls === 0) notifBadge.style.display = 'none';

  const el = document.querySelector(`.spot[data-id="${spotId}"]`);
  if (el) {
    el.classList.remove('taken', 'reserved', 'selected');
    el.classList.add('free');
    el.dataset.status = 'available';
    const car = el.querySelector('.car');
    if (car) car.remove();
    const sb = el.querySelector('.spot-status');
    if (sb) sb.textContent = 'LIRË';
    el.onclick = () => selectSpot(
      { id: spotId, zone: spotId.charAt(0), status: 'available', type: 'standard' },
      el
    );
  }

  updateStats();
  toast('Sesioni për vendin ' + spotId + ' u mbyll me sukses!');
}

function toast(msg) {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3000);
}

// ── Slider controls ───────────────────────────────────────────────────────────
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
let position  = 0;
const ITEM_W  = 340;

nextBtn?.addEventListener('click', () => {
  if (position < maxScrolls) {
    position++;
    sliderTrack.style.transform = `translateX(-${position * ITEM_W}px)`;
  }
});
prevBtn?.addEventListener('click', () => {
  if (position > 0) {
    position--;
    sliderTrack.style.transform = `translateX(-${position * ITEM_W}px)`;
  }
});

// ── Landing page counters ─────────────────────────────────────────────────────
(function ctr(id, t) {
  const el = document.getElementById(id);
  if (!el) return;
  let n = 0;
  const s = Math.ceil(t / 60);
  const i = setInterval(() => {
    n += s;
    if (n >= t) { el.textContent = t; clearInterval(i); }
    else el.textContent = n;
  }, 24);
})('statSpots', TOTAL_SPOTS);

// Seed free/live counters from DB, then fluctuate slightly
let liveN = FREE_SPOTS_DB || 24;
['liveSpots', 'statFree'].forEach(id => {
  const el = document.getElementById(id);
  if (el) el.textContent = liveN;
});
setInterval(() => {
  liveN = Math.max(5, Math.min(TOTAL_SPOTS - 5, liveN + Math.floor(Math.random() * 5 - 2)));
  ['liveSpots', 'statFree'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = liveN;
  });
}, 4000);