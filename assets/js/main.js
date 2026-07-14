/* SwissBricks — main.js */

// ── Spring scroll to centred element ─────────────────────
function springScrollTo(el) {
  const rect        = el.getBoundingClientRect();
  const destination = window.scrollY + rect.top + rect.height / 2 - window.innerHeight / 2;
  const start       = window.scrollY;
  const distance    = destination - start;
  const stiffness   = 18;
  const damping     = 6;
  let pos = 0, vel = 0, lastTime = null;

  function step(ts) {
    if (!lastTime) { lastTime = ts; requestAnimationFrame(step); return; }
    const dt    = Math.min((ts - lastTime) / 1000, 0.05);
    lastTime    = ts;
    const force = -stiffness * (pos - 1) - damping * vel;
    vel += force * dt;
    pos += vel * dt;
    window.scrollTo(0, start + distance * pos);
    if (Math.abs(pos - 1) < 0.0008 && Math.abs(vel) < 0.0008) {
      window.scrollTo(0, start + distance); return;
    }
    requestAnimationFrame(step);
  }
  requestAnimationFrame(step);
}

// Intercept any link pointing to a hash — same-page or full URL
document.addEventListener('click', function (e) {
  const link = e.target.closest('a[href]');
  if (!link) return;
  const href = link.getAttribute('href');
  if (!href || !href.includes('#')) return;
  const id     = href.split('#')[1];
  const target = document.getElementById(id);
  if (!target) return;
  e.preventDefault();
  springScrollTo(target);
});

// On page load with hash (arriving from another page via footer Sale link)
window.addEventListener('load', function () {
  if (!window.location.hash) return;
  const id     = window.location.hash.slice(1);
  const target = document.getElementById(id);
  if (!target) return;
  history.replaceState(null, '', window.location.pathname);
  window.scrollTo(0, 0);
  setTimeout(function () { springScrollTo(target); }, 80);
});

// Auto-detect base path for AJAX calls
const _BASE = window.location.pathname.startsWith('/swissbricks') ? '/swissbricks' : '';

// ── Cart badge update ─────────────────────────────────────
function updateCartBadge(count) {
  const badge = document.querySelector('.cart-badge');
  if (!badge) return;
  badge.textContent = count;
  badge.style.display = count > 0 ? 'flex' : 'none';
}

// ── Add to cart ───────────────────────────────────────────
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.add-to-cart-btn');
  if (!btn) return;
  e.preventDefault();

  const productId = btn.dataset.id;
  const qtyInput  = document.querySelector(`.qty-input[data-id="${productId}"]`);
  const qty       = qtyInput ? parseInt(qtyInput.value) || 1 : 1;

  const originalText = btn.textContent;
  btn.textContent = 'Adding…';
  btn.disabled    = true;

  fetch(_BASE + '/pages/cart_action.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    `action=add&product_id=${productId}&qty=${qty}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        updateCartBadge(data.cart_count);
        btn.textContent = 'Added ✓';
        setTimeout(() => { btn.textContent = originalText; btn.disabled = false; }, 1800);
      } else {
        btn.textContent = originalText;
        btn.disabled    = false;
      }
    })
    .catch(() => { btn.textContent = originalText; btn.disabled = false; });
});

// ── Cart quantity update ──────────────────────────────────
document.addEventListener('click', function (e) {
  const btn = e.target.closest('.cart-qty-btn');
  if (!btn) return;

  const productId = btn.dataset.id;
  const input     = document.querySelector(`.cart-qty-input[data-id="${productId}"]`);
  if (!input) return;

  let qty = parseInt(input.value) || 1;
  if (btn.dataset.action === 'minus') qty = Math.max(1, qty - 1);
  if (btn.dataset.action === 'plus')  qty = Math.min(99, qty + 1);
  input.value = qty;

  updateCartQtyAjax(productId, qty);
});

document.addEventListener('change', function (e) {
  if (!e.target.classList.contains('cart-qty-input')) return;
  const productId = e.target.dataset.id;
  let qty = Math.max(1, Math.min(99, parseInt(e.target.value) || 1));
  e.target.value = qty;
  updateCartQtyAjax(productId, qty);
});

function updateCartQtyAjax(productId, qty) {
  fetch(_BASE + '/pages/cart_action.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    `action=update&product_id=${productId}&qty=${qty}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        updateCartBadge(data.cart_count);
        const subtotalEl = document.querySelector(`.cart-subtotal[data-id="${productId}"]`);
        if (subtotalEl) subtotalEl.textContent = data.formatted_subtotal;
        const totalEl = document.querySelector('.cart-total-value');
        if (totalEl) totalEl.textContent = data.formatted_total;
      }
    })
    .catch(() => {});
}

// ── Cart remove (AJAX with server-side fallback) ──────────
document.addEventListener('submit', function (e) {
  const form = e.target.closest('.cart-remove-form');
  if (!form) return;
  e.preventDefault();

  const productId = form.querySelector('[name="remove_id"]').value;
  const row       = form.closest('tr') || form.closest('.cart-item-row');

  fetch(_BASE + '/pages/cart_action.php', {
    method:  'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body:    `action=remove&product_id=${productId}`
  })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        updateCartBadge(data.cart_count);
        if (row) {
          row.style.opacity    = '0';
          row.style.transform  = 'translateX(-16px)';
          row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
          setTimeout(() => { row.remove(); updateCartSummary(data); }, 320);
        } else {
          location.reload();
        }
      }
    })
    .catch(() => form.submit());
});

function updateCartSummary(data) {
  const totalEl = document.querySelector('.cart-total-value');
  if (totalEl) totalEl.textContent = data.formatted_total;
  const countEl = document.querySelector('.cart-item-count');
  if (countEl) countEl.textContent = data.cart_count + (data.cart_count === 1 ? ' item' : ' items');
  if (data.cart_count === 0) location.reload();
}

// ── User dropdown ─────────────────────────────────────────
const userMenuBtn = document.getElementById('userMenuBtn');
const userDropdown = document.getElementById('userDropdown');
if (userMenuBtn && userDropdown) {
  userMenuBtn.addEventListener('click', function (e) {
    e.stopPropagation();
    userDropdown.classList.toggle('open');
  });
  document.addEventListener('click', function () {
    userDropdown.classList.remove('open');
  });
}

// ── Hamburger ─────────────────────────────────────────────
const hamburger  = document.getElementById('hamburger');
const mobileNav  = document.getElementById('mobileNav');
if (hamburger && mobileNav) {
  hamburger.addEventListener('click', function () {
    mobileNav.classList.toggle('open');
  });
}

// ── Payment method selection ──────────────────────────────
document.querySelectorAll('.payment-method-card').forEach(card => {
  card.addEventListener('click', function () {
    document.querySelectorAll('.payment-method-card').forEach(c => c.classList.remove('selected'));
    this.classList.add('selected');
    const radio = this.querySelector('input[type="radio"]');
    if (radio) radio.checked = true;
  });
});

// ── Countdown timer ───────────────────────────────────────
const countdownEl = document.getElementById('countdown');
if (countdownEl) {
  const ts = parseInt(countdownEl.dataset.target, 10);
  if (!ts || ts <= Date.now()) {
    // No valid end time — hide countdown and reload to clear expired deal
    countdownEl.style.display = 'none';
    if (ts && ts <= Date.now()) location.reload();
  } else {
    const target = new Date(ts);
    const hEl = document.getElementById('cd-hours');
    const mEl = document.getElementById('cd-mins');
    const sEl = document.getElementById('cd-secs');
    const pad = n => String(n).padStart(2, '0');
    const tick = () => {
      const diff = target - Date.now();
      if (diff <= 0) {
        if (hEl) hEl.textContent = '00';
        if (mEl) mEl.textContent = '00';
        if (sEl) sEl.textContent = '00';
        // Reload so server clears the expired deal and removes the section
        setTimeout(() => location.reload(), 1000);
        return;
      }
      const h = Math.floor(diff / 3600000);
      const m = Math.floor((diff % 3600000) / 60000);
      const s = Math.floor((diff % 60000) / 1000);
      if (hEl) hEl.textContent = pad(h);
      if (mEl) mEl.textContent = pad(m);
      if (sEl) sEl.textContent = pad(s);
    };
    tick();
    setInterval(tick, 1000);
  }
}

// ── Scroll reveal ─────────────────────────────────────────
const revealEls = document.querySelectorAll('.reveal');
if (revealEls.length) {
  const observer = new IntersectionObserver(
    entries => entries.forEach(entry => { if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); } }),
    { threshold: 0.1 }
  );
  revealEls.forEach(el => observer.observe(el));
}

// ── Account tab switching ─────────────────────────────────
document.querySelectorAll('.account-tab-link').forEach(link => {
  link.addEventListener('click', function (e) {
    e.preventDefault();
    const tab = this.dataset.tab;
    document.querySelectorAll('.account-tab-link').forEach(l => l.classList.remove('active'));
    document.querySelectorAll('.account-tab-content').forEach(c => c.style.display = 'none');
    this.classList.add('active');
    const target = document.getElementById('tab-' + tab);
    if (target) target.style.display = 'block';
    history.replaceState(null, '', '?tab=' + tab);
  });
});
// Activate correct tab on load
(function () {
  const params = new URLSearchParams(window.location.search);
  const tab    = params.get('tab') || 'orders';
  const link   = document.querySelector(`.account-tab-link[data-tab="${tab}"]`);
  if (link) link.click();
})();
