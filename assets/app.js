let spots = DYNAMIC_SPOTS; 
let currentFilter = 'all';
let selectedSpot = null;
let isLoggedIn = USER_IS_LOGGED_IN; 

function getStatusConfig(status) {
  switch (status) {
    case 'available': return { label: 'Available', color: 'bg-emerald-500', text: 'text-emerald-500', bg: 'bg-emerald-50', border: 'border-emerald-200', icon: 'check-circle-2', borderSide: 'emerald-500' };
    case 'occupied': return { label: 'Occupied', color: 'bg-rose-500', text: 'text-rose-500', bg: 'bg-rose-50', border: 'border-rose-200', icon: 'car', borderSide: 'rose-500' };
    case 'reserved': return { label: 'Reserved', color: 'bg-orange-500', text: 'text-orange-500', bg: 'bg-orange-50', border: 'border-orange-200', icon: 'lock', borderSide: 'orange-500' };
    default: return { label: 'Unknown', color: 'bg-gray-500', text: 'text-gray-500', bg: 'bg-gray-50', border: 'border-gray-200', icon: 'car', borderSide: 'gray-500' };
  }
}

function renderDashboard() {
  document.getElementById('stat-total').innerText = spots.length;
  document.getElementById('stat-available').innerText = spots.filter(s => s.status === 'available').length;
  document.getElementById('stat-reserved').innerText = spots.filter(s => s.status === 'reserved').length;
  document.getElementById('stat-occupied').innerText = spots.filter(s => s.status === 'occupied').length;

  const filteredSpots = spots.filter(spot => currentFilter === 'all' || spot.status === currentFilter);
  
  const spotsA = filteredSpots.filter(s => s.zone === 'A');
  const spotsB = filteredSpots.filter(s => s.zone === 'B');
  const spotsC = filteredSpots.filter(s => s.zone === 'C');

  document.getElementById('zone-a').innerHTML = spotsA.map(spot => createSpotHTML(spot)).join('');
  document.getElementById('zone-b').innerHTML = spotsB.map(spot => createSpotHTML(spot)).join('');
  document.getElementById('zone-c').innerHTML = spotsC.map(spot => createSpotHTML(spot)).join('');

  lucide.createIcons();
}

function createSpotHTML(spot) {
  const config = getStatusConfig(spot.status);
  const isRightSide = (spot.zone === 'C');

  let containerClasses = `parking-spot relative h-[52px] sm:h-[60px] w-full cursor-pointer group flex items-center justify-between px-3 sm:px-4 box-border shrink-0 `;
  
  if (isRightSide) {
      containerClasses += `rounded-r-xl border-y-2 border-r-4 border-l-0 `; 
      if(spot.status === 'available') containerClasses += `border-gray-200 border-r-emerald-500 bg-white`;
      if(spot.status === 'occupied') containerClasses += `border-gray-200 border-r-rose-500 bg-gray-50`;
      if(spot.status === 'reserved') containerClasses += `border-gray-200 border-r-orange-500 bg-white`;
  } else {
      containerClasses += `rounded-l-xl border-y-2 border-l-4 border-r-0 `;
      if(spot.status === 'available') containerClasses += `border-gray-200 border-l-emerald-500 bg-white`;
      if(spot.status === 'occupied') containerClasses += `border-gray-200 border-l-rose-500 bg-gray-50`;
      if(spot.status === 'reserved') containerClasses += `border-gray-200 border-l-orange-500 bg-white`;
  }

  let iconHTML = '';
  if(spot.status === 'occupied') iconHTML = `<i data-lucide="car" class="w-5 h-5 sm:w-6 sm:h-6 ${config.text} ${isRightSide ? '-scale-x-100' : ''}"></i>`;
  else if(spot.status === 'reserved') iconHTML = `<i data-lucide="lock" class="w-4 h-4 sm:w-5 sm:h-5 ${config.text}"></i>`;
  else if(spot.status === 'available') iconHTML = `<div class="w-2 h-2 rounded-full bg-emerald-500 opacity-50"></div>`;

  let innerContent = '';
  if (isRightSide) {
      innerContent = `
        <div class="flex flex-col items-center gap-1">${iconHTML}</div>
        <span class="font-bold text-gray-400 text-xs sm:text-sm">${spot.id}</span>
      `;
  } else {
      innerContent = `
        <span class="font-bold text-gray-400 text-xs sm:text-sm">${spot.id}</span>
        <div class="flex flex-col items-center gap-1">${iconHTML}</div>
      `;
  }

  return `
    <div data-id="${spot.id}" class="${containerClasses}">
      ${innerContent}
    </div>
  `;
}

function updateFilterUI() {
  document.querySelectorAll('.filter-card').forEach(card => {
    const filterType = card.getAttribute('data-filter');
    card.className = "filter-card p-4 rounded-2xl cursor-pointer transition-all border border-gray-100 bg-white shadow-sm hover:border-gray-300";
    
    if (currentFilter === filterType) {
      if(filterType === 'available') card.classList.add('ring-2', 'ring-emerald-500', 'border-transparent', 'bg-emerald-50');
      if(filterType === 'reserved') card.classList.add('ring-2', 'ring-orange-500', 'border-transparent', 'bg-orange-50');
      if(filterType === 'occupied') card.classList.add('ring-2', 'ring-rose-500', 'border-transparent', 'bg-rose-50');
    }
  });
}

const backdrop = document.getElementById('modal-backdrop');
const sheet = document.getElementById('modal-sheet');

function openModal(spotId) {
  selectedSpot = spots.find(s => s.id === spotId);
  if(!selectedSpot) return;

  const config = getStatusConfig(selectedSpot.status);

  document.getElementById('modal-title').innerText = `Spot ${selectedSpot.id}`;
  document.getElementById('modal-subtitle').innerText = `Standard Parking • Zone ${selectedSpot.zone}`;
  document.getElementById('modal-status-text').innerText = config.label;
  
  const statusCard = document.getElementById('modal-status-card');
  const iconWrap = document.getElementById('modal-status-icon-wrap');
  
  statusCard.className = `p-4 rounded-2xl flex items-center gap-4 ${config.bg}`;
  iconWrap.className = `p-3 rounded-xl bg-white shadow-sm ${config.text}`;
  iconWrap.innerHTML = `<i data-lucide="${config.icon}" class="w-6 h-6"></i>`;
  document.getElementById('modal-status-text').className = `text-lg font-bold capitalize ${config.text}`;

  const detailsBox = document.getElementById('modal-occupant-details');
  if (selectedSpot.status === 'occupied') {
    detailsBox.classList.remove('hidden');
    document.getElementById('modal-license').innerText = selectedSpot.occupant || 'Unknown';
    document.getElementById('modal-duration').innerText = selectedSpot.timeElapsed || '--';
  } else {
    detailsBox.classList.add('hidden');
  }

  const actionsContainer = document.getElementById('modal-actions');
  if (!isLoggedIn) {
    actionsContainer.innerHTML = `<button id="modal-login-btn" class="flex-1 bg-gray-900 text-white font-medium py-3.5 rounded-xl hover:bg-gray-800 transition-colors shadow-sm">Login to take action</button>`;
    
    document.getElementById('modal-login-btn').addEventListener('click', () => {
      closeModal();
      showLoginScreen();
      toggleToLogin(); 
    });
  } else {
    if (selectedSpot.status === 'available') {
      actionsContainer.innerHTML = `<button class="flex-1 bg-gray-900 text-white font-medium py-3.5 rounded-xl hover:bg-gray-800 transition-colors">Book Spot</button>`;
    } else if (selectedSpot.status === 'reserved') {
      actionsContainer.innerHTML = `<button class="flex-1 bg-gray-900 text-white font-medium py-3.5 rounded-xl hover:bg-gray-800 transition-colors">Check-in Vehicle</button>`;
    } else if (selectedSpot.status === 'occupied') {
      actionsContainer.innerHTML = `<button class="flex-1 bg-white border-2 border-gray-200 text-gray-900 font-medium py-3.5 rounded-xl hover:border-gray-900 transition-colors">Release Spot</button>`;
    }
  }

  backdrop.classList.remove('hidden');
  setTimeout(() => {
    backdrop.classList.remove('opacity-0');
    sheet.classList.remove('modal-hidden');
  }, 10);
  
  lucide.createIcons();
}

function closeModal() {
  sheet.classList.add('modal-hidden');
  backdrop.classList.add('opacity-0');
  setTimeout(() => {
    backdrop.classList.add('hidden');
    selectedSpot = null;
  }, 300);
}

function showLoginScreen() {
  document.getElementById('dashboard-screen').classList.add('hidden');
  document.getElementById('login-screen').classList.remove('hidden');
}

function showDashboardScreen() {
  document.getElementById('login-screen').classList.add('hidden');
  document.getElementById('dashboard-screen').classList.remove('hidden');
}

function updateHeaderNav() {
  if (isLoggedIn) {
    document.getElementById('nav-login-btn').classList.add('hidden');
    document.getElementById('nav-register-btn').classList.add('hidden');
    document.getElementById('logout-btn').classList.remove('hidden');
  } else {
    document.getElementById('nav-login-btn').classList.remove('hidden');
    document.getElementById('nav-register-btn').classList.remove('hidden');
    document.getElementById('logout-btn').classList.add('hidden');
  }
}

const loginForm = document.getElementById('login-form');
const registerForm = document.getElementById('register-form');
const authTitle = document.getElementById('auth-title');
const authSubtitle = document.getElementById('auth-subtitle');

function toggleToRegister() {
  loginForm.classList.add('hidden');
  registerForm.classList.remove('hidden');
  authTitle.innerText = "Create Account";
  authSubtitle.innerText = "Fill in the details to register";
}

function toggleToLogin() {
  registerForm.classList.add('hidden');
  loginForm.classList.remove('hidden');
  authTitle.innerText = "AutoSystemPark";
  authSubtitle.innerText = "Sign in to manage the space";
}

document.addEventListener('DOMContentLoaded', () => {
  lucide.createIcons();
  
  updateHeaderNav();
  
  document.getElementById('switch-to-register').addEventListener('click', toggleToRegister);
  document.getElementById('switch-to-login').addEventListener('click', toggleToLogin);
  
  if (FORCE_SHOW_REGISTER) {
      showLoginScreen();
      toggleToRegister();
  } else if (FORCE_SHOW_LOGIN) {
      showLoginScreen();
      toggleToLogin();
  } else {
      showDashboardScreen(); 
  }
  
  renderDashboard();

  document.getElementById('nav-login-btn').addEventListener('click', () => {
    showLoginScreen();
    toggleToLogin(); 
  });

  document.getElementById('nav-register-btn').addEventListener('click', () => {
    showLoginScreen();
    toggleToRegister(); 
  });

  document.getElementById('back-to-dashboard-btn').addEventListener('click', () => {
    showDashboardScreen();
  });

  document.querySelectorAll('.filter-card').forEach(card => {
    card.addEventListener('click', (e) => {
      const type = card.getAttribute('data-filter');
      currentFilter = currentFilter === type ? 'all' : type;
      updateFilterUI();
      renderDashboard();
    });
  });

  const handleMapClick = (e) => {
    const spotElement = e.target.closest('.parking-spot');
    if (spotElement) {
      const spotId = spotElement.getAttribute('data-id');
      openModal(spotId);
    }
  };
  
  document.getElementById('zone-a').addEventListener('click', handleMapClick);
  document.getElementById('zone-b').addEventListener('click', handleMapClick);
  document.getElementById('zone-c').addEventListener('click', handleMapClick); 

  document.getElementById('modal-close').addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);
});