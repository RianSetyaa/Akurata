/* ===========================
   Akurata POS Terminal Logic
   =========================== */

const API_BASE = '../api';

/* ---------- State ---------- */

let currentUser = null;
let products = [];
let cart = [];
let taxRate = 0;
let taxEnabled = false;
let paymentMethod = 'cash';
let lastTransaction = null;
let outletName = '';

/* ---------- Helpers ---------- */

const rupiah = new Intl.NumberFormat('id-ID');

const $ = (selector) => document.querySelector(selector);
const $$ = (selector) => [...document.querySelectorAll(selector)];

const apiRequest = async (endpoint, options = {}) => {
  const url = `${API_BASE}/${endpoint}`;
  const response = await fetch(url, {
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/json', ...(options.headers || {}) },
    ...options,
  });
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) {
    throw new Error(payload.error || 'Request gagal.');
  }
  return payload;
};

const showToast = (message, type = 'error') => {
  const container = $('[data-pos-toast]');
  if (!container) return;
  const el = document.createElement('div');
  el.className = `pos-toast-item${type === 'success' ? ' is-success' : type === 'error' ? ' is-error' : ''}`;
  el.textContent = message;
  container.append(el);
  setTimeout(() => {
    el.style.opacity = '0';
    el.style.transition = 'opacity 0.3s';
    setTimeout(() => el.remove(), 300);
  }, 4000);
};

const parseNumber = (value) => {
  if (typeof value === 'number') return value;
  if (typeof value === 'string') return parseFloat(value.replace(/[^0-9.-]/g, '')) || 0;
  return 0;
};

/* =========================================================
   LOGIN PAGE LOGIC
   ========================================================= */

const loginForm = $('[data-pos-login-form]');
if (loginForm) {
  // Check if already logged in
  fetch(`${API_BASE}/me.php`, { credentials: 'same-origin' })
    .then((r) => (r.ok ? r.json() : null))
    .then((payload) => {
      if (payload?.user) {
        window.location.replace('index.html');
      }
    })
    .catch(() => {});

  loginForm.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const button = form.querySelector('button[type="submit"]');
    const previousText = button.textContent;
    button.disabled = true;
    button.textContent = 'Memproses...';

    try {
      const data = Object.fromEntries(new FormData(form).entries());
      await apiRequest('login.php', {
        method: 'POST',
        body: JSON.stringify({
          identity: data.identity,
          password: data.password,
          remember: form.querySelector('[name="remember"]').checked,
        }),
      });
      window.location.replace('index.html');
    } catch (error) {
      showToast(error.message, 'error');
      button.disabled = false;
      button.textContent = previousText;
    }
  });
}

/* =========================================================
   POS TERMINAL LOGIC
   ========================================================= */

const isTerminal = document.body.classList.contains('pos-terminal');

if (isTerminal) {
  initTerminal();
}

async function initTerminal() {
  // Auth check
  try {
    const payload = await apiRequest('me.php');
    currentUser = payload.user;
    if (!currentUser) {
      window.location.replace('login.html');
      return;
    }
    $('[data-cashier-name]').textContent = currentUser.name || 'Kasir';
    $('[data-outlet-name]').textContent = currentUser.outlet_name || 'Outlet';
    outletName = currentUser.outlet_name || 'Outlet';
  } catch {
    window.location.replace('login.html');
    return;
  }

  // Load settings for tax rate
  try {
    const settings = await apiRequest('settings.php');
    const business = settings?.business;
    if (business) {
      taxEnabled = Number(business.tax_enabled) === 1;
      taxRate = taxEnabled ? Number(business.tax_rate) || 0 : 0;
      if (business.name) outletName = business.name;
      $('[data-outlet-name]').textContent = outletName;
    }
    if (!taxEnabled) {
      $('[data-tax-label]').textContent = 'Pajak (0%)';
    } else {
      $('[data-tax-label]').textContent = `Pajak (${taxRate}%)`;
    }
  } catch {
    // Tax defaults to 0
  }

  // Load products
  await loadProducts();

  // Bind events
  bindEvents();
}

/* ---------- Products ---------- */

async function loadProducts() {
  try {
    const payload = await apiRequest('products.php');
    products = (payload.products || []).map((p) => ({
      ...p,
      _price: parseNumber(p.sale_price),
      _stock: parseNumber(p.stock_qty),
      _barcode: (p.barcode || '').toLowerCase(),
      _sku: (p.sku || '').toLowerCase(),
      _name: (p.name || '').toLowerCase(),
    }));
    renderProductGrid('');
  } catch (error) {
    $('[data-pos-product-grid]').innerHTML = `<div class="pos-no-products">Gagal memuat produk: ${error.message}</div>`;
  }
}

function renderProductGrid(query) {
  const grid = $('[data-pos-product-grid]');
  if (!grid) return;

  const q = (query || '').toLowerCase().trim();
  const filtered = q
    ? products.filter((p) =>
        p._name.includes(q) || p._sku.includes(q) || p._barcode.includes(q)
      )
    : products;

  if (filtered.length === 0) {
    grid.innerHTML = `<div class="pos-no-products">${q ? 'Produk tidak ditemukan.' : 'Belum ada produk.'}</div>`;
    return;
  }

  grid.innerHTML = filtered
    .map((p) => {
      const isOutOfStock = p._stock <= 0 && !p.is_composite;
      const stockClass = p._stock <= (parseNumber(p.min_stock) || 0) ? ' is-low' : '';
      const disabled = isOutOfStock ? ' is-disabled' : '';
      const badge = p.is_composite ? '<span class="card-badge">Paket</span>' : p.sold_by_weight ? '<span class="card-badge">Volume</span>' : '';
      return `
        <div class="pos-product-card${disabled}" data-product-id="${p.id}" role="button" tabindex="0">
          ${badge}
          <span class="card-name">${escapeHtml(p.name)}</span>
          <span class="card-price">Rp ${rupiah.format(p._price)}</span>
          <span class="card-stock${stockClass}">Stok: ${p.stock_qty}</span>
        </div>
      `;
    })
    .join('');
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str;
  return div.innerHTML;
}

/* ---------- Cart ---------- */

function addToCart(productId) {
  const product = products.find((p) => p.id === productId);
  if (!product) return;

  const existing = cart.find((item) => item.product_id === productId);
  if (existing) {
    if (!product.sold_by_weight && !product.is_composite) {
      const newQty = existing.qty + 1;
      if (!product.is_composite && newQty > product._stock) {
        showToast(`Stok ${product.name} tidak cukup.`, 'error');
        return;
      }
      existing.qty = newQty;
    } else {
      existing.qty = Math.round((existing.qty + 0.5) * 1000) / 1000;
    }
  } else {
    if (!product.is_composite && product._stock <= 0) {
      showToast(`Stok ${product.name} habis.`, 'error');
      return;
    }
    cart.push({
      product_id: product.id,
      name: product.name,
      qty: 1,
      unit_price: product._price,
      discount_rate: 0,
      sold_by_weight: product.sold_by_weight,
      is_composite: product.is_composite,
    });
  }

  renderCart();
}

function removeFromCart(productId) {
  cart = cart.filter((item) => item.product_id !== productId);
  renderCart();
}

function updateCartQty(productId, newQty) {
  const item = cart.find((i) => i.product_id === productId);
  if (!item) return;

  const product = products.find((p) => p.id === productId);
  const qty = Math.max(item.sold_by_weight ? 0.001 : 1, newQty);

  if (product && !product.is_composite && qty > product._stock) {
    showToast(`Stok ${product.name} tidak cukup.`, 'error');
    return;
  }

  item.qty = item.sold_by_weight ? Math.round(qty * 1000) / 1000 : Math.round(qty);
  renderCart();
}

function updateCartDiscount(productId, discountRate) {
  const item = cart.find((i) => i.product_id === productId);
  if (!item) return;
  item.discount_rate = Math.max(0, Math.min(100, discountRate));
  renderCart();
}

function clearCart() {
  if (cart.length === 0) return;
  cart = [];
  renderCart();
}

function getCartTotals() {
  let gross = 0;
  let totalDiscount = 0;

  for (const item of cart) {
    const lineGross = item.unit_price * item.qty;
    const lineDiscount = Math.round(lineGross * item.discount_rate / 100 * 100) / 100;
    gross += lineGross;
    totalDiscount += lineDiscount;
  }

  const afterDiscount = Math.max(0, gross - totalDiscount);
  const effectiveTaxRate = taxEnabled ? taxRate : 0;
  const taxAmount = Math.round(afterDiscount * effectiveTaxRate / 100 * 100) / 100;
  const total = afterDiscount + taxAmount;

  return { gross, totalDiscount, afterDiscount, taxAmount, total, taxRate: effectiveTaxRate };
}

function renderCart() {
  const container = $('[data-cart-items]');
  const emptyEl = $('[data-cart-empty]');
  const countEl = $('[data-cart-count]');
  const checkoutBtn = $('[data-pos-checkout]');

  if (!container) return;

  const totalItems = cart.reduce((sum, item) => sum + item.qty, 0);
  countEl.textContent = `${cart.length} item`;
  checkoutBtn.disabled = cart.length === 0;

  if (cart.length === 0) {
    container.innerHTML = '<div class="pos-cart-empty">Keranjang kosong</div>';
    updateSummary();
    return;
  }

  container.innerHTML = cart
    .map((item) => {
      const lineGross = item.unit_price * item.qty;
      const lineDiscount = Math.round(lineGross * item.discount_rate / 100 * 100) / 100;
      const subtotal = Math.max(0, lineGross - lineDiscount);
      const step = item.sold_by_weight ? '0.001' : '1';
      const minVal = item.sold_by_weight ? '0.001' : '1';

      return `
        <div class="pos-cart-item" data-cart-product-id="${item.product_id}">
          <div>
            <div class="cart-item-name">${escapeHtml(item.name)}</div>
            <div class="cart-item-price">Rp ${rupiah.format(item.unit_price)}</div>
            <div class="cart-item-controls">
              <button class="cart-qty-btn" type="button" data-qty-action="decrease">&minus;</button>
              <input class="cart-qty-input" type="number" min="${minVal}" step="${step}" value="${item.qty}" data-qty-input />
              <button class="cart-qty-btn" type="button" data-qty-action="increase">&plus;</button>
            </div>
            <div class="cart-item-discount">
              Diskon:
              <input type="number" min="0" max="100" step="0.01" value="${item.discount_rate}" data-discount-input />%
              ${lineDiscount > 0 ? `<span>(-Rp ${rupiah.format(lineDiscount)})</span>` : ''}
            </div>
          </div>
          <div class="cart-item-right">
            <span class="cart-item-subtotal">Rp ${rupiah.format(subtotal)}</span>
            <button class="cart-item-remove" type="button" data-remove-item>Hapus</button>
          </div>
        </div>
      `;
    })
    .join('');

  updateSummary();
}

function updateSummary() {
  const totals = getCartTotals();
  $('[data-summary-gross]').textContent = `Rp ${rupiah.format(totals.gross)}`;
  $('[data-summary-discount]').textContent = `Rp ${rupiah.format(totals.totalDiscount)}`;
  $('[data-summary-tax]').textContent = `Rp ${rupiah.format(totals.taxAmount)}`;
  $('[data-summary-total]').textContent = `Rp ${rupiah.format(totals.total)}`;
}

/* ---------- Payment Method ---------- */

function setPaymentMethod(method) {
  paymentMethod = method;
  $$('.pos-payment-method-btn').forEach((btn) => {
    btn.classList.toggle('is-active', btn.dataset.paymentMethod === method);
  });

  const isTempo = method === 'tempo';
  $('[data-tempo-section]').style.display = isTempo ? '' : 'none';
  $('[data-tempo-detail]').style.display = isTempo ? '' : 'none';
}

/* ---------- Checkout ---------- */

async function checkout() {
  if (cart.length === 0) return;

  const totals = getCartTotals();
  const checkoutBtn = $('[data-pos-checkout]');
  const previousText = checkoutBtn.textContent;
  checkoutBtn.disabled = true;
  checkoutBtn.textContent = 'Memproses...';

  // Validate tempo fields
  if (paymentMethod === 'tempo') {
    const customerName = $('[data-customer-name]').value.trim();
    if (!customerName) {
      showToast('Nama pelanggan wajib diisi untuk penjualan tempo.', 'error');
      checkoutBtn.disabled = false;
      checkoutBtn.textContent = previousText;
      return;
    }
  }

  const items = cart.map((item) => ({
    product_id: item.product_id,
    qty: item.qty,
    discount_rate: item.discount_rate,
  }));

  const body = {
    payment_method: paymentMethod,
    items,
  };

  if (paymentMethod === 'tempo') {
    body.customer_name = $('[data-customer-name]').value.trim();
    body.customer_phone = $('[data-customer-phone]').value.trim();
    body.due_date = $('[data-due-date]').value || '';
    body.down_payment = Number($('[data-down-payment]').value || 0);
    body.down_payment_method = $('[data-dp-method]').value || 'cash';
  }

  try {
    const result = await apiRequest('transactions.php', {
      method: 'POST',
      body: JSON.stringify(body),
    });

    lastTransaction = {
      ...result,
      items: cart.map((item) => {
        const lineGross = item.unit_price * item.qty;
        const lineDiscount = Math.round(lineGross * item.discount_rate / 100 * 100) / 100;
        const subtotal = Math.max(0, lineGross - lineDiscount);
        return { ...item, lineGross, lineDiscount, subtotal };
      }),
      totals,
      paymentMethod,
      cashierName: currentUser?.name || 'Kasir',
      date: new Date(),
    };

    showReceipt(lastTransaction);
    showToast(`${result.invoice_no} tersimpan: Rp ${rupiah.format(parseNumber(result.total_amount))}`, 'success');

    // Reset cart
    cart = [];
    renderCart();
    setPaymentMethod('cash');
    $('[data-customer-name]').value = '';
    $('[data-customer-phone]').value = '';
    $('[data-due-date]').value = '';
    $('[data-down-payment]').value = '0';
  } catch (error) {
    showToast(error.message, 'error');
  } finally {
    checkoutBtn.disabled = cart.length === 0;
    checkoutBtn.textContent = 'Bayar';
  }
}

/* ---------- Receipt ---------- */

function showReceipt(tx) {
  const overlay = $('[data-receipt-overlay]');
  const content = $('[data-receipt-content]');
  if (!overlay || !content) return;

  const dateStr = tx.date
    ? new Date(tx.date).toLocaleString('id-ID', {
        day: '2-digit', month: '2-digit', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
      })
    : '';

  const itemsHtml = tx.items
    .map((item) => {
      const parts = [];
      parts.push(`<div class="receipt-item-row"><div class="item-name">${escapeHtml(item.name)}</div>`);
      let detailLine = `${item.qty} x Rp ${rupiah.format(item.unit_price)}`;
      if (item.discount_rate > 0) {
        detailLine += ` (-${item.discount_rate}%)`;
      }
      parts.push(`<div class="item-detail"><span>${detailLine}</span><span>Rp ${rupiah.format(item.subtotal)}</span></div>`);
      parts.push('</div>');
      return parts.join('');
    })
    .join('');

  const paymentLabels = { cash: 'Tunai', qris: 'QRIS', transfer: 'Transfer', tempo: 'Tempo' };

  content.innerHTML = `
    <div class="receipt-center">
      <strong>${escapeHtml(outletName)}</strong><br />
      <small>${dateStr}</small>
    </div>
    <div class="receipt-line"></div>
    <div class="receipt-row">
      <span>No: ${escapeHtml(tx.invoice_no || '')}</span>
      <span>${escapeHtml(tx.cashierName)}</span>
    </div>
    <div class="receipt-line"></div>
    ${itemsHtml}
    <div class="receipt-line"></div>
    <div class="receipt-row">
      <span>Subtotal</span><strong>Rp ${rupiah.format(tx.totals.gross)}</strong>
    </div>
    ${tx.totals.totalDiscount > 0 ? `<div class="receipt-row"><span>Diskon</span><strong>-Rp ${rupiah.format(tx.totals.totalDiscount)}</strong></div>` : ''}
    ${tx.totals.taxAmount > 0 ? `<div class="receipt-row"><span>Pajak (${tx.totals.taxRate}%)</span><strong>Rp ${rupiah.format(tx.totals.taxAmount)}</strong></div>` : ''}
    <div class="receipt-line"></div>
    <div class="receipt-total-row">
      <span>TOTAL</span><strong>Rp ${rupiah.format(tx.totals.total)}</strong>
    </div>
    <div class="receipt-line"></div>
    <div class="receipt-row">
      <span>Pembayaran</span><span>${paymentLabels[tx.paymentMethod] || tx.paymentMethod}</span>
    </div>
    <div class="receipt-line"></div>
    <div class="receipt-center">
      <small>Terima kasih</small>
    </div>
  `;

  overlay.classList.remove('is-hidden');
}

function closeReceipt() {
  $('[data-receipt-overlay]')?.classList.add('is-hidden');
}

/* ---------- Logout ---------- */

async function logout() {
  try {
    await apiRequest('logout.php', { method: 'POST' });
  } catch {
    // ignore
  }
  window.location.replace('login.html');
}

/* ---------- Event Binding ---------- */

function bindEvents() {
  // Search / barcode
  const searchInput = $('[data-pos-search]');
  if (searchInput) {
    searchInput.addEventListener('input', (e) => {
      renderProductGrid(e.target.value);
    });

    searchInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        const val = e.target.value.trim().toLowerCase();
        if (!val) return;

        // Try barcode match first
        const barcodeMatch = products.find(
          (p) => p._barcode === val || p._sku === val
        );
        if (barcodeMatch) {
          addToCart(barcodeMatch.id);
          e.target.value = '';
          renderProductGrid('');
          showToast(`${barcodeMatch.name} ditambahkan.`, 'success');
          return;
        }

        // If no exact match, just filter
        renderProductGrid(val);
      }
    });
  }

  // Product grid clicks
  const productGrid = $('[data-pos-product-grid]');
  if (productGrid) {
    productGrid.addEventListener('click', (e) => {
      const card = e.target.closest('[data-product-id]');
      if (!card) return;
      const productId = Number(card.dataset.productId);
      addToCart(productId);
    });

    productGrid.addEventListener('keydown', (e) => {
      if (e.key === 'Enter' || e.key === ' ') {
        const card = e.target.closest('[data-product-id]');
        if (!card) return;
        e.preventDefault();
        addToCart(Number(card.dataset.productId));
      }
    });
  }

  // Cart interactions (event delegation)
  const cartContainer = $('[data-cart-items]');
  if (cartContainer) {
    cartContainer.addEventListener('click', (e) => {
      const item = e.target.closest('[data-cart-product-id]');
      if (!item) return;
      const productId = Number(item.dataset.cartProductId);

      if (e.target.closest('[data-qty-action="decrease"]')) {
        const cartItem = cart.find((i) => i.product_id === productId);
        if (cartItem) {
          const step = cartItem.sold_by_weight ? 0.5 : 1;
          updateCartQty(productId, cartItem.qty - step);
        }
      } else if (e.target.closest('[data-qty-action="increase"]')) {
        const cartItem = cart.find((i) => i.product_id === productId);
        if (cartItem) {
          const step = cartItem.sold_by_weight ? 0.5 : 1;
          updateCartQty(productId, cartItem.qty + step);
        }
      } else if (e.target.closest('[data-remove-item]')) {
        removeFromCart(productId);
      }
    });

    cartContainer.addEventListener('change', (e) => {
      const item = e.target.closest('[data-cart-product-id]');
      if (!item) return;
      const productId = Number(item.dataset.cartProductId);

      if (e.target.matches('[data-qty-input]')) {
        const val = parseFloat(e.target.value) || 0;
        updateCartQty(productId, val);
      } else if (e.target.matches('[data-discount-input]')) {
        const val = parseFloat(e.target.value) || 0;
        updateCartDiscount(productId, val);
      }
    });
  }

  // Cart clear
  $('[data-cart-clear]')?.addEventListener('click', clearCart);

  // Payment method buttons
  $$('.pos-payment-method-btn').forEach((btn) => {
    btn.addEventListener('click', () => {
      setPaymentMethod(btn.dataset.paymentMethod);
    });
  });

  // Checkout
  $('[data-pos-checkout]')?.addEventListener('click', checkout);

  // Receipt modal
  $('[data-receipt-close]')?.addEventListener('click', closeReceipt);
  $('[data-receipt-new]')?.addEventListener('click', closeReceipt);
  $('[data-receipt-print]')?.addEventListener('click', () => {
    window.print();
  });

  // Logout
  $('[data-pos-logout]')?.addEventListener('click', logout);

  // Keyboard shortcut: F2 to focus search
  document.addEventListener('keydown', (e) => {
    if (e.key === 'F2') {
      e.preventDefault();
      searchInput?.focus();
      searchInput?.select();
    }
  });
}
