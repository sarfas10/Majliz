// assets/dashboard.js - client logic to call APIs and render UI
document.addEventListener('DOMContentLoaded', function(){
  initMenu();
  loadAdhan();
  loadStats();
  loadNews();
  initCalendar();
  loadNotifications();

  document.getElementById('refresh-news').addEventListener('click', loadNews);
  document.getElementById('mark-all-read').addEventListener('click', markAllRead);
});

/* Sidebar menu */
function initMenu(){
  document.querySelectorAll('.menu-btn').forEach(btn=>{
    btn.addEventListener('click', function(){
      document.querySelectorAll('.menu-btn').forEach(b=>b.classList.remove('active'));
      this.classList.add('active');
    });
  });
}

/* ADHAN */
async function loadAdhan(){
  const container = document.getElementById('adhan-row');
  container.innerHTML = 'Loading...';
  try {
    const res = await fetch('api/adhan.php');
    const j = await res.json();
    container.innerHTML = '';
    if (j.success){
      const times = j.timings;
      const icons = {'Fajr':'🌅','Dhuhr':'☀️','Asr':'🌇','Maghrib':'🌆','Isha':'🌌'};
      // mark next prayer dynamically
      let nextPrayer = guessNextPrayer(times);
      Object.keys(times).forEach(k=>{
        const card = document.createElement('div');
        card.className = 'adhan-card' + (k===nextPrayer ? ' next' : '');
        card.innerHTML = `<div class="top"><div style="font-size:20px">${icons[k]||'🕋'}</div><div style="font-weight:700">${k}</div></div><div style="margin-top:auto;font-weight:700">${times[k]}</div>`;
        container.appendChild(card);
      });
    } else {
      container.innerText = 'Failed to load adhan times';
    }
  } catch (e) {
    console.error(e);
    container.innerText = 'Failed to load adhan times';
  }
}

function guessNextPrayer(times){
  // times: "HH:MM" possibly with (DST) or +xx; keep it simple: compare to now
  const now = new Date();
  const pairs = [];
  for (const k in times){
    let t = times[k].replace('(+05)','').trim();
    // support HH:MM or H:MM
    const m = t.match(/(\d{1,2}):(\d{2})/);
    if (!m) continue;
    const hh = parseInt(m[1],10), mm = parseInt(m[2],10);
    const d = new Date(now.getFullYear(), now.getMonth(), now.getDate(), hh, mm);
    pairs.push({k,d});
  }
  pairs.sort((a,b)=>a.d - b.d);
  for (let p of pairs){
    if (p.d > now) return p.k;
  }
  return pairs.length ? pairs[0].k : 'Asr';
}

/* Stats (static demo) */
function loadStats(){
  const stats = [
    ['📊 Total Sales','$1k'],
    ['📄 Total Order','300'],
    ['👨‍👩‍👧 Total Families','235'],
    ['🧍 Members','1294'],
    ['📅 Events','4'],
    ['📄 Requests','12'],
  ];
  const grid = document.getElementById('stats-grid');
  grid.innerHTML = '';
  stats.forEach(s=>{
    const card = document.createElement('div');
    card.className = 'stat-card';
    card.innerHTML = `<div style="font-size:18px">${s[0]}</div><div style="font-size:18px;font-weight:700;margin-top:6px">${s[1]}</div><div style="font-size:11px;color:#605e5c;margin-top:auto">${s[0].split(' ').slice(1).join(' ')}</div>`;
    grid.appendChild(card);
  });
}

/* NEWS */
async function loadNews(){
  const container = document.getElementById('news-list');
  container.innerHTML = 'Loading...';
  try {
    const r = await fetch('api/rss.php');
    const j = await r.json();
    container.innerHTML = '';
    if (j.success){
      if (!j.articles || j.articles.length===0) {
        container.innerHTML = '<div style="padding:12px;color:#605e5c">No recent news</div>';
        return;
      }
      j.articles.forEach(a=>{
        const card = document.createElement('div');
        card.className = 'news-card';
        const img = a.image ? `<img src="${a.image}" alt="" onerror="this.style.display='none'"/>` : '';
        card.innerHTML = `${img}<h3 style="margin:6px 0">${a.title}</h3><p style="color:#605e5c">${a.description.slice(0,220)}</p><a href="${a.url}" target="_blank">Read more →</a>`;
        container.appendChild(card);
      });
    } else {
      container.innerHTML = '<div style="padding:12px;color:#605e5c">Failed to load news</div>';
    }
  } catch(e){
    console.error(e);
    container.innerHTML = '<div style="padding:12px;color:#605e5c">Failed to load news</div>';
  }
}

/* Notifications (static demo) */
function loadNotifications(){
  const list = document.getElementById('notifications-list');
  const samples = [
    {title:'New Member Registration', message:'Ahmad Hassan has registered as a new member', time:'5m', type:'success', read:false},
    {title:'Payment Received', message:'Monthly contribution received from Family ID: 1234', time:'2h', type:'info', read:false},
    {title:'Upcoming Event', message:'Quran recitation competition tomorrow at 7 PM', time:'4h', type:'warning', read:false},
    {title:'Donation Received', message:'Zakat donation of $500 received', time:'4d', type:'success', read:true}
  ];
  list.innerHTML = '';
  samples.forEach(n=>{
    const div = document.createElement('div');
    div.className = 'notification-item';
    div.innerHTML = `<div style="display:flex;justify-content:space-between"><strong style="color:${n.read? '#605e5c':'#323130'}">${n.title}${n.read? '':' •'}</strong><small style="color:#605e5c">${n.time}</small></div><div style="color:${n.read? '#605e5c':'#323130'};margin-top:6px">${n.message}</div>`;
    div.onclick = ()=> { div.style.opacity = 0.6; }
    list.appendChild(div);
  });
}

function markAllRead(){
  document.querySelectorAll('.notification-item').forEach(n=>n.style.opacity = 0.6);
}

/* Hijri Calendar: fetch events from server & render grid close to PyQt layout */
let calendarState = { hijri_year:null, hijri_month:null, events:{} };
function initCalendar(){
  const daysRow = document.getElementById('hijri-days');
  ['Mo','Tu','We','Th','Fr','Sa','Su'].forEach(d=>{
    const e = document.createElement('div'); e.textContent = d; daysRow.appendChild(e);
  });

  document.getElementById('prev-month').addEventListener('click', ()=>changeMonth(-1));
  document.getElementById('next-month').addEventListener('click', ()=>changeMonth(1));
  fetchImportantDates();
}

async function fetchImportantDates(delta=0){
  let url = 'api/important_dates.php';
  if (calendarState.hijri_year && calendarState.hijri_month){
    let y = calendarState.hijri_year;
    let m = calendarState.hijri_month + delta;
    if (m < 1){ m = 12; y -= 1; }
    if (m > 12){ m = 1; y += 1; }
    url += `?hijri_year=${y}&hijri_month=${m}`;
  }
  try {
    const res = await fetch(url);
    const j = await res.json();
    if (j.success){
      calendarState.hijri_year = j.hijri_year;
      calendarState.hijri_month = j.hijri_month;
      calendarState.events = j.events || {};
      renderCalendar();
    }
  } catch(e){
    console.error('Calendar load failed', e);
  }
}

function changeMonth(delta){
  if (!calendarState.hijri_month) { fetchImportantDates(delta); return; }
  let m = calendarState.hijri_month + delta;
  let y = calendarState.hijri_year;
  if (m < 1){ m = 12; y -= 1; }
  if (m > 12){ m = 1; y += 1; }
  fetch(`api/important_dates.php?hijri_year=${y}&hijri_month=${m}`)
    .then(r=>r.json()).then(j=>{
      if (j.success){
        calendarState.hijri_year = j.hijri_year;
        calendarState.hijri_month = j.hijri_month;
        calendarState.events = j.events || {};
        renderCalendar();
      }
    }).catch(console.error);
}

function renderCalendar(){
  const titleEl = document.getElementById('calendar-title');
  const grid = document.getElementById('calendar-grid');
  titleEl.textContent = `${getHijriMonthName(calendarState.hijri_month)} ${calendarState.hijri_year}`;
  grid.innerHTML = '';

  // Simple but visually similar rendering:
  // We will render 30 boxes and put dots for events. (Server provides event day numbers.)
  const daysInMonth = 30;
  // Add 0 offset (you can later replace with server-provided weekday offset for perfect alignment)
  const weekdayOffset = 0;
  for (let i=0;i<weekdayOffset;i++){
    const blank = document.createElement('div'); blank.className='day-cell'; grid.appendChild(blank);
  }
  for (let day=1; day<=daysInMonth; day++){
    const cell = document.createElement('div'); cell.className='day-cell';
    const txt = document.createElement('div'); txt.textContent = day; cell.appendChild(txt);

    if (calendarState.events && calendarState.events[day]){
      const dot = document.createElement('div'); dot.className='dot';
      const evs = calendarState.events[day].join(' ').toLowerCase();
      if (evs.includes('eid')) dot.style.background = '#ff6b6b';
      else if (evs.includes('ramadan') || evs.includes('laylat')) dot.style.background = '#4ecdc4';
      else if (evs.includes('mawlid')) dot.style.background = '#45b7d1';
      else dot.style.background = '#7a60ff';
      cell.appendChild(dot);
    }

    grid.appendChild(cell);
  }

  // important dates list
  const imp = document.getElementById('important-dates');
  imp.innerHTML = '';
  const keys = Object.keys(calendarState.events).sort((a,b)=>a-b);
  if (keys.length === 0) imp.innerHTML = '<div style="color:#605e5c;padding:8px">No special events this month</div>';
  else keys.forEach(k=>{
    const evs = calendarState.events[k].join(', ');
    const div = document.createElement('div'); div.className='event'; div.textContent = `${k} ${getHijriMonthName(calendarState.hijri_month)} — ${evs}`;
    imp.appendChild(div);
  });
}

function getHijriMonthName(num){
  const months = ['Muharram','Safar',"Rabi' al-Awwal","Rabi' al-Thani","Jumada al-Awwal","Jumada al-Thani","Rajab","Sha'ban","Ramadan","Shawwal","Dhu al-Qi'dah","Dhu al-Hijjah"];
  return months[(num||1)-1] || `Month ${num}`;
}
