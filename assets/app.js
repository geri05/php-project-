const IS_LOGGED_IN    = document.body.dataset.loggedIn === 'true';
const HAS_LOGIN_ERR   = document.body.dataset.loginErr === 'true';
const FORCE_REGISTER  = document.body.dataset.forceRegister === 'true';
const ALL_SPOTS       = JSON.parse(document.body.dataset.spots || '[]');
const TOTAL_SPOTS     = parseInt(document.body.dataset.totalSpots || '240');

// ── Page switch ───────────────────────────────────
document.getElementById('page-landing').style.display   = IS_LOGGED_IN ? 'none'  : 'block';
document.getElementById('page-dashboard').style.display = IS_LOGGED_IN ? 'block' : 'none';
if (!IS_LOGGED_IN && FORCE_REGISTER) openAuth('register');
else if (!IS_LOGGED_IN && HAS_LOGIN_ERR) openAuth('login');

// ── Custom cursor (landing) ───────────────────────
if (!IS_LOGGED_IN) {
  const cur = document.getElementById('cursor');
  const rng = document.getElementById('cursorRing');
  let mx=0,my=0,rx=0,ry=0;
  document.addEventListener('mousemove',e=>{ mx=e.clientX;my=e.clientY; cur.style.left=(mx-6)+'px'; cur.style.top=(my-6)+'px'; });
  (function loop(){ rx+=(mx-rx-18)*.12; ry+=(my-ry-18)*.12; rng.style.left=rx+'px'; rng.style.top=ry+'px'; requestAnimationFrame(loop); })();
  document.querySelectorAll('button,a').forEach(el=>{
    el.addEventListener('mouseenter',()=>{ cur.style.transform='scale(2.5)'; rng.style.transform='scale(1.5)'; rng.style.opacity='.8'; });
    el.addEventListener('mouseleave',()=>{ cur.style.transform=''; rng.style.transform=''; rng.style.opacity='.5'; });
  });
}

// ── Scroll reveal ─────────────────────────────────
new IntersectionObserver((entries)=>{
  entries.forEach(e=>{ if(e.isIntersecting) e.target.classList.add('visible'); });
},{threshold:.1}).observe && document.querySelectorAll('.reveal').forEach(r=>{
  new IntersectionObserver(entries=>entries.forEach(e=>{ if(e.isIntersecting) e.target.classList.add('visible'); }),{threshold:.1}).observe(r);
});

// ── Auth modal ────────────────────────────────────
function openAuth(tab){
  document.getElementById('authOverlay').classList.add('open');
  switchTab(tab||'login');
  document.body.style.overflow='hidden';
}
function closeAuth(){
  document.getElementById('authOverlay').classList.remove('open');
  document.body.style.overflow='';
}
function switchTab(tab){
  document.querySelectorAll('.auth-tab').forEach(t=>t.classList.remove('active'));
  document.querySelectorAll('.auth-form').forEach(f=>f.classList.remove('active'));
  const T=tab.charAt(0).toUpperCase()+tab.slice(1);
  document.getElementById('tab'+T).classList.add('active');
  document.getElementById('form'+T).classList.add('active');
}
document.getElementById('authOverlay').addEventListener('click',function(e){ if(e.target===this) closeAuth(); });
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeAuth(); });

// ── DASHBOARD UI LOGIC ────────────────────────────

const navDashboard = document.getElementById('nav-dashboard');
const navParking = document.getElementById('nav-parking');
const viewDashboard = document.getElementById('view-dashboard');
const viewParking = document.getElementById('view-parking');
let mapBuilt = false;

function dbView(v) {
    if(v === 'dash') {
        navDashboard.classList.add('active');
        navParking.classList.remove('active');
        viewDashboard.style.display = 'flex';
        viewParking.style.display = 'none';
    } else {
        navParking.classList.add('active');
        navDashboard.classList.remove('active');
        viewDashboard.style.display = 'none';
        viewParking.style.display = 'block';
        if(!mapBuilt) buildMap();
    }
}

// Generimi i Hartës me të dhënat nga PHP
function buildMap() {
    mapBuilt = true;
    const container = document.getElementById('map-container');
    if(!ALL_SPOTS.length) { container.innerHTML = '<p>Nuk ka vende të konfiguruara.</p>'; return; }

    const zones = {};
    ALL_SPOTS.forEach(s => { 
        if(!zones[s.zone]) zones[s.zone] = []; 
        zones[s.zone].push(s); 
    });
    container.innerHTML = '';

    Object.keys(zones).sort().forEach(z => {
        let sectorHTML = `<div class="sector-container"><h3 class="sector-title">Sektori ${z}</h3><div class="spot-grid">`;
        zones[z].forEach(spot => {
            let classes = 'spot-item';
            let onClick = `onclick="bookSpot('${spot.id}')"`;
            if (spot.status !== 'available') {
                classes += ' occupied';
                onClick = '';
            }
            sectorHTML += `<div id="spot-${spot.id}" class="${classes}" ${onClick}>${spot.id}</div>`;
        });
        sectorHTML += `</div></div>`;
        container.innerHTML += sectorHTML;
    });
}

// Logjika e Rezervimit dhe Sliderit
const sliderTrack = document.getElementById('sliderTrack');
const notifBadge = document.getElementById('notif-badge');
let maxScrolls = 0; // Përtej kartës 'Historiku'

function bookSpot(spotId) {
    const spotEl = document.getElementById(`spot-${spotId}`);
    
    if(spotEl.classList.contains('occupied')) {
        alert('Ky vend është i zënë!');
        return;
    }
    
    if(!confirm(`Dëshironi të rezervoni vendin ${spotId}?`)) return;

    spotEl.classList.add('occupied');
    notifBadge.style.display = 'flex';
    
    // Kthehu automatikisht te Dashboard
    dbView('dash');

    // Krijojmë kartën e re me stilin Snaphunt
    const now = new Date().toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'});
    const newCard = document.createElement('div');
    newCard.className = 'job-card';
    newCard.innerHTML = `
        <div class="job-banner" style="background-color: #1a1a1a;">
            <div class="company-logo" style="background:#fff;"><strong style="color:var(--teal); font-size:20px;">${spotId}</strong></div>
            <div class="no-match-badge" style="color: var(--teal);">RESERVED</div>
        </div>
        <div class="job-body">
            <div class="job-company">Parkim Aktiv</div>
            <div class="job-title">Automjeti juaj u regjistrua me sukses në Sektorin ${spotId.charAt(0)}</div>
            <div class="job-location">Vendi fizik: ${spotId}</div>
            <div class="open-badge" style="border-color: #2dde98; color: #2dde98;">ACTIVE</div>
            <div class="status-bars">
                <span class="active" style="background:#2dde98;"></span><span class="active" style="background:#2dde98;"></span><span class="active" style="background:#2dde98;"></span><span></span><span></span>
            </div>
            <div class="job-footer-text">
                <i class="fa-solid fa-clock" style="color: #2dde98;"></i>
                <div>Koha e hyrjes:<br><span style="color:#2dde98; font-size: 11px;">${now}</span></div>
            </div>
            <div class="withdraw-btn" style="color: #ff6c5f;" onclick="endSession(this, '${spotId}')">
                <i class="fa-solid fa-circle-xmark"></i> Përfundo & Paguaj
            </div>
        </div>
    `;
    
    sliderTrack.insertBefore(newCard, sliderTrack.firstChild);
    maxScrolls++;
}

function endSession(btnElement, spotId) {
    if(!confirm(`Përfundo sesionin për vendin ${spotId}? Pagesa do të procesohet.`)) return;
    
    btnElement.closest('.job-card').remove();
    
    const spotEl = document.getElementById(`spot-${spotId}`);
    if(spotEl) spotEl.classList.remove('occupied');
    
    maxScrolls = Math.max(0, maxScrolls - 1);
    if(maxScrolls === 0) {
        notifBadge.style.display = 'none';
    }
    
    alert(`Pagesa për vendin ${spotId} u krye me sukses!`);
}

// Lëvizja e Sliderit
const prevBtn = document.getElementById('prevBtn');
const nextBtn = document.getElementById('nextBtn');
let position = 0;
const itemWidth = 340; // Gjerësia (320) + gap (20)

nextBtn.addEventListener('click', () => {
    if (position < maxScrolls) { // Lejon scroll vetëm aq karta sa janë
        position++;
        sliderTrack.style.transform = `translateX(-${position * itemWidth}px)`;
    }
});

prevBtn.addEventListener('click', () => {
    if (position > 0) {
        position--;
        sliderTrack.style.transform = `translateX(-${position * itemWidth}px)`;
    }
});

// Numëruesi i Vendeve të Lira
(function ctr(id,t){ const el=document.getElementById(id); if(!el) return; let n=0; const s=Math.ceil(t/60); const i=setInterval(()=>{ n+=s; if(n>=t){el.textContent=t;clearInterval(i);}else el.textContent=n; },24); })('statSpots', TOTAL_SPOTS);
let liveN=24;
setInterval(()=>{ liveN=Math.max(5,Math.min(40,liveN+Math.floor(Math.random()*5-2))); ['liveSpots','statFree'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent=liveN; }); },4000);