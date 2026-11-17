const ALL_ORDERS = [
  { id: 1001, product: 'Black Forest Cake', date: '2026-03-26', time: '11:51 PM', status: 'done',    qty: 1, price: 9999 },
  { id: 1002, product: 'Mango Cheesecake',  date: '2026-03-26', time: '2:15 PM',  status: 'done',    qty: 2, price: 3998 },
  { id: 1003, product: 'Red Velvet Slice',  date: '2026-03-25', time: '9:30 AM',  status: 'done',    qty: 3, price: 5997 },
  { id: 1004, product: 'Black Forest Cake', date: '2026-03-25', time: '3:45 PM',  status: 'pending', qty: 1, price: 9999 },
  { id: 1005, product: 'Mango Cheesecake',  date: '2026-03-24', time: '12:00 PM', status: 'done',    qty: 1, price: 1999 },
  { id: 1006, product: 'Strawberry Tart',   date: '2026-03-24', time: '4:10 PM',  status: 'done',    qty: 4, price: 8796 },
  { id: 1007, product: 'Red Velvet Slice',  date: '2026-03-23', time: '10:20 AM', status: 'voided',  qty: 2, price: 3998 },
  { id: 1008, product: 'Strawberry Tart',   date: '2026-03-22', time: '1:00 PM',  status: 'done',    qty: 2, price: 4398 },
  { id: 1009, product: 'Black Forest Cake', date: '2026-03-21', time: '6:30 PM',  status: 'done',    qty: 1, price: 9999 },
  { id: 1010, product: 'Mango Cheesecake',  date: '2026-03-20', time: '3:00 PM',  status: 'done',    qty: 3, price: 5997 },
  { id: 1011, product: 'Red Velvet Slice',  date: '2026-03-19', time: '11:00 AM', status: 'done',    qty: 1, price: 1999 },
  { id: 1012, product: 'Strawberry Tart',   date: '2026-03-18', time: '5:00 PM',  status: 'pending', qty: 2, price: 4398 },
  { id: 1013, product: 'Black Forest Cake', date: '2026-03-17', time: '8:45 PM',  status: 'done',    qty: 1, price: 9999 },
  { id: 1014, product: 'Mango Cheesecake',  date: '2026-03-15', time: '2:30 PM',  status: 'done',    qty: 2, price: 3998 },
  { id: 1015, product: 'Red Velvet Slice',  date: '2026-03-10', time: '10:00 AM', status: 'done',    qty: 5, price: 9995 },
];

let revenueChart = null;
let productChart = null;

const PRODUCT_COLORS = ['#185FA5', '#3B6D11', '#D85A30', '#854F0B', '#9932CC'];

function fmt(n) {
  return 'Php ' + Math.round(n).toLocaleString('en-PH');
}

function getDatesInRange(start, end) {
  const dates = [];
  const cur = new Date(start + 'T00:00:00');
  const last = new Date(end + 'T00:00:00');
  while (cur <= last) {
    dates.push(cur.toISOString().slice(0, 10));
    cur.setDate(cur.getDate() + 1);
  }
  return dates;
}

function setDefaultDates() {
  const today = new Date();
  const firstOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
  document.getElementById('startDate').value = firstOfMonth.toISOString().slice(0, 10);
  document.getElementById('endDate').value = today.toISOString().slice(0, 10);
}

function applyFilter() {
  const start = document.getElementById('startDate').value;
  const end   = document.getElementById('endDate').value;
  const statusFilter = document.getElementById('statusFilter').value;

  if (!start || !end || start > end) {
    alert('Please set a valid date range.');
    return;
  }

  let filtered = ALL_ORDERS.filter(o => o.date >= start && o.date <= end);

  renderMetrics(filtered, start, end);
  renderRevenueChart(filtered, start, end);
  renderProductChart(filtered);

  if (statusFilter !== 'all') {
    filtered = filtered.filter(o => o.status === statusFilter);
  }
  renderOrders(filtered);
}

// ── Metric Cards (3 cards, no AOV) ────────────────────────────────────────────
function renderMetrics(data, start, end) {
  const done  = data.filter(o => o.status === 'done');
  const total = done.reduce((a, o) => a + o.price, 0);
  const qty   = done.reduce((a, o) => a + o.qty, 0);
  const days  = getDatesInRange(start, end).length;

  document.getElementById('metricsGrid').innerHTML = `
    <div class="metric-card" id="revenue-cont">
      <div class="metric-label">Total Revenue</div>
      <div class="metric-value">${fmt(total)}</div>
      <div class="metric-sub">${days}-day period</div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Orders Completed</div>
      <div class="metric-value">${done.length}</div>
      <div class="metric-sub">${data.length} total incl. other statuses</div>
    </div>
    <div class="metric-card">
      <div class="metric-label">Units Sold</div>
      <div class="metric-value">${qty}</div>
      <div class="metric-sub">across all products</div>
    </div>
  `;
}

// ── Daily Revenue Chart (revenue only, no orders line) ────────────────────────
function renderRevenueChart(data, start, end) {
  const done  = data.filter(o => o.status === 'done');
  const dates = getDatesInRange(start, end);

  const revByDay = {};
  dates.forEach(d => { revByDay[d] = 0; });
  done.forEach(o => {
    revByDay[o.date] = (revByDay[o.date] || 0) + o.price;
  });

  const labels  = dates.map(d => { const p = d.split('-'); return p[1] + '/' + p[2]; });
  const revData = dates.map(d => revByDay[d]);

  if (revenueChart) revenueChart.destroy();

  revenueChart = new Chart(document.getElementById('revenueChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [
        {
          label: 'Revenue',
          data: revData,
          backgroundColor: revData.map(v => v > 0 ? '#f7ce00ba' : '#85B7EB'),
          borderRadius: 6,
          borderSkipped: false,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: {
            label: ctx => ' ' + fmt(ctx.parsed.y)
          }
        }
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { autoSkip: true, maxTicksLimit: 14, maxRotation: 45 }
        },
        y: {
          ticks: {
            callback: v => 'Php ' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
          },
          grid: { color: 'rgba(0,0,0,0.05)' }
        }
      }
    }
  });
}

// ── Product Chart ──────────────────────────────────────────────────────────────
function renderProductChart(data) {
  const done = data.filter(o => o.status === 'done');
  const revByProd = {};
  done.forEach(o => {
    revByProd[o.product] = (revByProd[o.product] || 0) + o.price;
  });

  const prods  = Object.keys(revByProd).sort((a, b) => revByProd[b] - revByProd[a]);
  const vals   = prods.map(p => revByProd[p]);
  const colors = prods.map((_, i) => PRODUCT_COLORS[i % PRODUCT_COLORS.length]);

  if (productChart) productChart.destroy();

  productChart = new Chart(document.getElementById('productChart'), {
    type: 'bar',
    data: {
      labels: prods,
      datasets: [{
        data: vals,
        backgroundColor: colors,
        borderRadius: 6,
        borderSkipped: false,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          callbacks: { label: ctx => ' ' + fmt(ctx.parsed.y) }
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: {
          ticks: {
            callback: v => 'Php ' + (v >= 1000 ? (v / 1000).toFixed(0) + 'k' : v)
          },
          grid: { color: 'rgba(0,0,0,0.05)' }
        }
      }
    }
  });
}

// ── Orders Table ───────────────────────────────────────────────────────────────
function statusBadge(s) {
  const map = {
    done:    { label: 'Completed', cls: 'badge-done' },
    pending: { label: 'Pending',   cls: 'badge-pend' },
    voided:  { label: 'Voided',    cls: 'badge-void' },
  };
  const m = map[s] || { label: s, cls: '' };
  return `<span class="status-badge ${m.cls}">${m.label}</span>`;
}

function renderOrders(data) {
  document.getElementById('orderCount').textContent = data.length + ' records';

  if (!data.length) {
    document.getElementById('orderTableBody').innerHTML =
      '<div style="text-align:center; padding:2rem; color:grey; font-size:13px;">No orders found for this selection.</div>';
    return;
  }

  const sorted = [...data].sort((a, b) =>
    (b.date + b.time).localeCompare(a.date + a.time)
  );

  document.getElementById('orderTableBody').innerHTML = sorted.map(o => `
    <div class="sales-rows">
      <div class="history-col"><p>#${o.id}</p></div>
      <div class="history-col"><p>${o.product}</p></div>
      <div class="history-col"><p>${o.date}</p><p>${o.time}</p></div>
      <div class="history-col">${statusBadge(o.status)}</div>
      <div class="history-col"><p>x${o.qty}</p></div>
      <div class="history-col"><p>Php</p><p>${o.price.toLocaleString('en-PH')}</p></div>
    </div>
  `).join('');
}

// ── CSV Download ───────────────────────────────────────────────────────────────
function downloadCSV() {
  const start = document.getElementById('startDate').value;
  const end   = document.getElementById('endDate').value;

  if (!start || !end) { alert('Please set a date range first.'); return; }

  const data = ALL_ORDERS.filter(o => o.date >= start && o.date <= end);
  if (!data.length) { alert('No data to download for this range.'); return; }

  const header = 'Order ID,Product,Date,Time,Status,Qty,Total (PHP)';
  const rows   = data.map(o =>
    `${o.id},"${o.product}",${o.date},${o.time},${o.status},${o.qty},${o.price}`
  );
  const csv  = [header, ...rows].join('\n');
  const blob = new Blob([csv], { type: 'text/csv' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `sales_${start}_to_${end}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// ── Init ───────────────────────────────────────────────────────────────────────
setDefaultDates();
applyFilter();