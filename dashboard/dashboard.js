const API_BASE = '../api';

const rupiah = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

const methodLabel = {
  cash: 'Tunai',
  qris: 'QRIS',
  transfer: 'Transfer',
  tempo: 'Tempo',
};

const receivableStatusLabel = {
  open: 'Belum dibayar',
  partial: 'Sebagian',
  paid: 'Lunas',
};

const emptyDashboard = {
  summary: {
    revenue: 0,
    transactions: 0,
    average_sale: 0,
    gross_margin: 0,
    revenue_growth: 0,
    low_stock: 0,
    active_shifts: 0,
  },
  hourly_sales: [],
  payments: [],
  recent_transactions: [],
  low_stock_products: [],
  receivables: [],
  quotations: [],
  tenant: {
    outlet_name: 'Outlet',
  },
};

let productCache = [];
let businessTaxConfig = {
  enabled: false,
  rate: 0,
};
let businessFeatureConfig = {
  quotationEnabled: true,
};
let currentUserRole = 'cashier';
let currentUserId = 0;
let activityLogPage = 1;
const activityLogsPerPage = 25;
let productTablePage = 1;
let productTablePageSize = 10;
let transactionPage = 1;
let transactionPageSize = 50;
let transactionTotalPages = 1;

let purchasePage = 1;
let purchasePageSize = 50;
let purchaseTotalPages = 1;
let salesReportFilter = { start_date: '', end_date: '', payment_method: '', source: '' };
let purchaseReportFilter = { start_date: '', end_date: '', status: '' };
let productSearchQuery = '';
let productTypeFilter = 'all';
let productStockFilter = 'all';
let productLoyverseFilter = 'all';
let barcodeCameraScanner = null;
let barcodeCameraRunning = false;
let barcodeCameraSession = 0;
let barcodeCameraLibraryPromise = null;
let productBarcodeCameraScanner = null;
let productBarcodeCameraRunning = false;
let productBarcodeCameraSession = 0;
let productBarcodeCameraTarget = null;
let supportChatLastMessageId = 0;
let supportChatPollTimer = null;
let supportChatPolling = false;
let supportChatOpen = false;
let supportChatEnabled = false;
let supportChatConversationId = 0;
let supportChatViewingHistory = false;
const supportChatRenderedIds = new Set();

const restrictedManagerPages = new Set(['access', 'businessProfile', 'integrations', 'logs']);

const viewTitles = {
  dashboard: 'Dashboard Operasional',
  sales: 'Penjualan',
  quotations: 'Quotation',
  stock: 'Produk & Stok',
  purchases: 'Purchase Order',
  receivables: 'Piutang',
  salesReport: 'Laporan Penjualan',
  purchaseReport: 'Laporan Pembelian',
  profile: 'Profil',
  access: 'Akses',
  businessProfile: 'Profil Bisnis',
  integrations: 'Integrasi',
  logs: 'Log',
};

const pageLinks = {
  dashboard: 'index.html',
  sales: 'sales.html',
  quotations: 'quotations.html',
  stock: 'products.html',
  purchases: 'purchases.html',
  receivables: 'receivables.html',
  salesReport: 'sales-report.html',
  purchaseReport: 'purchase-report.html',
  profile: 'profile.html',
  access: 'access.html',
  businessProfile: 'business-profile.html',
  integrations: 'integrations.html',
  logs: 'logs.html',
};

const pageTemplates = {
  dashboard: `
    <section class="metric-grid" aria-label="Ringkasan metrik">
      <article class="metric-card">
        <span>Omzet hari ini</span>
        <strong data-metric="revenue">Rp 0</strong>
        <small class="trend good" data-metric="revenue_growth">0% dari kemarin</small>
      </article>
      <article class="metric-card">
        <span>Transaksi</span>
        <strong data-metric="transactions">0</strong>
        <small data-metric="average_sale">Rata-rata Rp 0</small>
      </article>
      <article class="metric-card">
        <span>Margin kotor</span>
        <strong data-metric="gross_margin">0%</strong>
        <small class="trend good">Belum ada data</small>
      </article>
      <article class="metric-card warning">
        <span>Stok kritis</span>
        <strong data-metric="low_stock">0 SKU</strong>
        <small data-metric="active_shifts">0 shift aktif</small>
      </article>
    </section>

    <section class="dashboard-grid">
      <article class="panel sales-panel">
        <div class="panel-head">
          <div>
            <span class="label">Penjualan</span>
            <h2>Per jam hari ini</h2>
          </div>
          <span class="chip">Live</span>
        </div>
        <div class="bar-chart is-empty" aria-label="Grafik penjualan per jam" data-hourly-chart></div>
      </article>

      <article class="panel">
        <div class="panel-head">
          <div>
            <span class="label">Kas & pembayaran</span>
            <h2>Metode masuk</h2>
          </div>
        </div>
        <div class="payment-list" data-payment-list></div>
      </article>
    </section>

    <section class="lower-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Transaksi terbaru</span>
            <h2>Aktivitas kasir</h2>
          </div>
          <a href="sales.html">Lihat semua</a>
        </div>
        <div class="data-table transaction-table" role="table" aria-label="Transaksi terbaru" data-transaction-table></div>
      </article>

      <aside class="panel stock-panel">
        <div class="panel-head">
          <div>
            <span class="label">Stok perlu tindakan</span>
            <h2>Restock</h2>
          </div>
        </div>
        <div class="stock-list" data-low-stock-list></div>
      </aside>
    </section>
  `,
  sales: `
    <section class="action-grid" id="transaksi-baru" aria-label="Aksi penjualan">
      <article class="panel action-panel">
        <div class="panel-head">
          <div>
            <span class="label">Aksi cepat</span>
            <h2>Penjualan</h2>
          </div>
        </div>

        <div class="quick-actions">
          <button class="btn btn-secondary" type="button" data-open-modal="transaction">Transaksi baru</button>
          <button class="btn btn-ghost" type="button" data-open-modal="sales-import">Import penjualan sebelumnya</button>
          <a class="btn btn-ghost" href="${API_BASE}/sales-import.php?action=template">Unduh template</a>
        </div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="sales-import" role="dialog" aria-modal="true" aria-labelledby="sales-import-modal-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Penjualan lama</span>
            <h2 id="sales-import-modal-title">Import Excel</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-sales-import-form enctype="multipart/form-data">
          <p class="notice">
            Isi sheet Penjualan dan Item Penjualan. Satu invoice bisa memiliki beberapa item dengan invoice_no yang sama.
          </p>
          <label>
            <span>File Excel (.xlsx)</span>
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
          </label>
          <div class="quick-actions">
            <button class="btn btn-secondary" type="submit">Import penjualan</button>
            <a class="btn btn-ghost" href="${API_BASE}/sales-import.php?action=template">Unduh template</a>
          </div>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="transaction" role="dialog" aria-modal="true" aria-labelledby="transaction-modal-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Kasir</span>
            <h2 id="transaction-modal-title">Transaksi baru</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-transaction-form>
          <div class="barcode-input-row">
            <label class="barcode-scan-field">
              <span>Scan barcode</span>
              <input type="text" data-barcode-scan autocomplete="off" placeholder="Scan lalu tekan Enter" />
            </label>
            <button class="btn btn-ghost" type="button" data-open-barcode-camera>Kamera</button>
          </div>
          <p class="barcode-feedback" data-barcode-feedback aria-live="polite"></p>
          <div class="barcode-camera-panel is-hidden" data-barcode-camera-panel>
            <div id="transaction-barcode-camera" class="barcode-camera-reader"></div>
            <div class="barcode-camera-controls">
              <label class="is-hidden" data-barcode-camera-select-field>
                <span>Kamera</span>
                <select data-barcode-camera-select></select>
              </label>
              <button class="btn btn-ghost" type="button" data-stop-barcode-camera>Tutup kamera</button>
            </div>
            <p class="barcode-camera-status" data-barcode-camera-status aria-live="polite"></p>
          </div>
          <div class="transaction-items" data-transaction-items>
            <div class="transaction-item-row" data-transaction-item>
              <label>
                <span>Produk</span>
                <select name="product_id" data-product-select required></select>
              </label>
              <label>
                <span>Qty</span>
                <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
              </label>
              <label>
                <span>Diskon %</span>
                <input type="number" name="discount_rate" min="0" max="100" step="0.01" value="0" required />
              </label>
              <button class="icon-button" type="button" data-remove-transaction-item aria-label="Hapus item">Hapus</button>
            </div>
          </div>
          <button class="btn btn-ghost" type="button" data-add-transaction-item>Tambah item</button>
          <div class="transaction-summary" aria-label="Ringkasan transaksi">
            <div><span>Subtotal</span><strong data-transaction-gross>Rp 0</strong></div>
            <div><span>Diskon</span><strong data-transaction-discount>Rp 0</strong></div>
            <div><span>Setelah diskon</span><strong data-transaction-subtotal>Rp 0</strong></div>
            <div><span data-transaction-tax-label>Pajak</span><strong data-transaction-tax>Rp 0</strong></div>
            <div><span>Total</span><strong data-transaction-total>Rp 0</strong></div>
          </div>
          <label>
            <span>Metode bayar</span>
            <select name="payment_method" required>
              <option value="cash">Tunai</option>
              <option value="qris">QRIS</option>
              <option value="transfer">Transfer</option>
              <option value="tempo">Tempo</option>
            </select>
          </label>
          <label>
            <span>Nama pelanggan</span>
            <input type="text" name="customer_name" placeholder="Opsional" />
          </label>
          <label>
            <span>No. HP pelanggan</span>
            <input type="tel" name="customer_phone" placeholder="Opsional" />
          </label>
          <div class="tempo-fields is-hidden" data-tempo-fields>
            <label>
              <span>Jatuh tempo piutang</span>
              <input type="date" name="due_date" />
            </label>
            <div class="form-columns">
              <label>
                <span>DP piutang</span>
                <input type="number" name="down_payment" min="0" value="0" />
              </label>
              <label>
                <span>Metode DP</span>
                <select name="down_payment_method">
                  <option value="cash">Tunai</option>
                  <option value="qris">QRIS</option>
                  <option value="transfer">Transfer</option>
                </select>
              </label>
            </div>
          </div>
          <button class="btn btn-secondary" type="submit">Simpan transaksi</button>
        </form>
      </article>
    </div>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Transaksi</span>
            <h2>Invoice penjualan</h2>
          </div>
        </div>
        <div class="product-table-controls" aria-label="Filter penjualan">
          <label>
            <span>Per halaman</span>
            <select data-transaction-page-size>
              <option value="25">25 transaksi</option>
              <option value="50" selected>50 transaksi</option>
              <option value="100">100 transaksi</option>
            </select>
          </label>
        </div>
        <div class="data-table transaction-table" role="table" aria-label="Transaksi penjualan" data-transaction-table></div>
        <div data-transaction-pagination></div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="transaction-detail" role="dialog" aria-modal="true" aria-labelledby="transaction-detail-title">
      <article class="modal-panel detail-modal">
        <div class="panel-head">
          <div>
            <span class="label">Detail penjualan</span>
            <h2 id="transaction-detail-title" data-transaction-detail-title>Transaksi</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>
        <div data-transaction-detail-body></div>
      </article>
    </div>
  `,
  quotations: `
    <section class="action-grid" id="quotation-baru" aria-label="Aksi quotation">
      <article class="panel action-panel">
        <div class="panel-head">
          <div>
            <span class="label">Aksi cepat</span>
            <h2>Quotation</h2>
          </div>
        </div>

        <div class="quick-actions">
          <button class="btn btn-secondary" type="button" data-open-modal="quotation">Buat quotation</button>
        </div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="quotation" role="dialog" aria-modal="true" aria-labelledby="quotation-modal-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Penawaran</span>
            <h2 id="quotation-modal-title">Buat quotation</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-quotation-form>
          <label>
            <span>Produk</span>
            <select name="product_id" data-product-select required></select>
          </label>
          <div class="form-columns">
            <label>
              <span>Qty</span>
              <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
            </label>
            <label>
              <span>Berlaku sampai</span>
              <input type="date" name="valid_until" />
            </label>
          </div>
          <label>
            <span>Nama pelanggan</span>
            <input type="text" name="customer_name" required />
          </label>
          <label>
            <span>No. HP pelanggan</span>
            <input type="tel" name="customer_phone" placeholder="Opsional" />
          </label>
          <label>
            <span>Catatan</span>
            <textarea name="notes" rows="3" placeholder="Opsional"></textarea>
          </label>
          <button class="btn btn-secondary" type="submit">Simpan quotation</button>
        </form>
      </article>
    </div>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Riwayat quotation</span>
            <h2>Penawaran</h2>
          </div>
        </div>
        <div class="data-table quotation-table" role="table" aria-label="Riwayat quotation" data-quotation-table></div>
      </article>
    </section>
  `,
  stock: `
    <section class="action-grid" id="produk-baru" aria-label="Aksi produk">
      <article class="panel action-panel">
        <div class="panel-head">
          <div>
            <span class="label">Aksi cepat</span>
            <h2>Produk & stok</h2>
          </div>
        </div>

        <div class="quick-actions">
          <button class="btn btn-secondary" type="button" data-open-modal="product">Tambah produk</button>
          <button class="btn btn-ghost" type="button" data-open-modal="product-import">Import Excel</button>
          <button class="btn btn-ghost" type="button" data-open-modal="product-edit-import">Edit batch Excel</button>
          <a class="btn btn-ghost" href="${API_BASE}/product-import.php?action=template">Unduh template</a>
          <button class="btn btn-ghost" type="button" data-loyverse-push-products>Push semua ke Loyverse</button>
          <button class="btn btn-ghost" type="button" data-loyverse-push-changed>Push perubahan</button>
        </div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="product-import" role="dialog" aria-modal="true" aria-labelledby="product-import-modal-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Produk</span>
            <h2 id="product-import-modal-title">Import Excel</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-product-import-form enctype="multipart/form-data">
          <p class="notice">
            Isi sheet Produk dan Komponen. Lihat sheet Contoh Produk dan Contoh Komponen sebagai panduan.
          </p>
          <label>
            <span>File Excel (.xlsx)</span>
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
          </label>
          <div class="quick-actions">
            <button class="btn btn-secondary" type="submit">Import produk</button>
            <a class="btn btn-ghost" href="${API_BASE}/product-import.php?action=template">Unduh template</a>
          </div>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="product-edit-import" role="dialog" aria-modal="true" aria-labelledby="product-edit-import-modal-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Produk</span>
            <h2 id="product-edit-import-modal-title">Edit Batch via Excel</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-product-edit-import-form enctype="multipart/form-data">
          <p class="notice">
            Unduh template terlebih dahulu untuk mendapatkan data produk saat ini. Ubah kolom yang diinginkan (nama, harga, stok, dll), lalu upload kembali. SKU adalah kunci pencocokan — jangan diubah.
          </p>
          <label>
            <span>File Excel (.xlsx)</span>
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
          </label>
          <div class="quick-actions">
            <button class="btn btn-secondary" type="submit">Update produk</button>
            <a class="btn btn-ghost" href="${API_BASE}/product-edit-import.php?action=template">Unduh template edit</a>
          </div>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="product" role="dialog" aria-modal="true" aria-labelledby="product-modal-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Produk</span>
            <h2 id="product-modal-title">Tambah produk</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-product-form>
          <input type="hidden" name="id" />
          <label>
            <span>SKU</span>
            <input type="text" name="sku" maxlength="40" required />
          </label>
          <div class="barcode-input-row">
            <label>
              <span>Barcode</span>
              <input type="text" name="barcode" maxlength="80" autocomplete="off" placeholder="Scan atau ketik barcode" />
            </label>
            <button class="btn btn-ghost" type="button" data-open-product-barcode-camera>Kamera</button>
          </div>
          <label>
            <span>Nama produk</span>
            <input type="text" name="name" maxlength="64" required />
          </label>
          <label>
            <span>Kategori</span>
            <input type="text" name="category" maxlength="512" />
          </label>
          <label>
            <span>Jenis produk</span>
            <select name="is_composite" data-product-kind>
              <option value="0">Produk biasa</option>
              <option value="1">Composite / Paket</option>
            </select>
          </label>
          <label>
            <span>Tipe jual</span>
            <select name="sold_by_weight">
              <option value="0">Satuan / Each</option>
              <option value="1">Volume / Berat</option>
            </select>
          </label>
          <div class="form-columns" data-regular-product-fields>
            <label>
              <span>Stok</span>
              <input type="number" name="stock_qty" min="0" step="0.001" required />
            </label>
            <label>
              <span>Min stok</span>
              <input type="number" name="min_stock" min="0" step="0.001" required />
            </label>
          </div>
          <div class="composite-fields is-hidden" data-composite-fields>
            <span class="label">Komponen per paket</span>
            <div class="composite-items" data-composite-items>
              <div class="composite-item-row" data-composite-item>
                <label>
                  <span>Komponen</span>
                  <select name="component_product_id" data-component-product-select disabled></select>
                </label>
                <label>
                  <span>Qty</span>
                  <input type="number" name="component_quantity" min="0.001" step="0.001" value="1" disabled />
                </label>
                <button class="icon-button" type="button" data-remove-composite-item disabled>Hapus</button>
              </div>
            </div>
            <button class="btn btn-ghost" type="button" data-add-composite-item>Tambah komponen</button>
          </div>
          <div class="form-columns">
            <label data-product-cost-field>
              <span>Harga beli</span>
              <input type="number" name="cost_price" min="0" step="0.01" required />
            </label>
            <label>
              <span>Harga jual</span>
              <input type="number" name="sale_price" min="0" step="0.01" required />
            </label>
          </div>
          <button class="btn btn-secondary" type="submit" data-product-submit>Tambah produk</button>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="product-barcode" role="dialog" aria-modal="true" aria-labelledby="product-barcode-modal-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Produk</span>
            <h2 id="product-barcode-modal-title">Atur barcode</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-product-barcode-form>
          <input type="hidden" name="id" />
          <label>
            <span>Produk</span>
            <input type="text" name="product_name" readonly />
          </label>
          <div class="barcode-input-row">
            <label>
              <span>Barcode</span>
              <input type="text" name="barcode" maxlength="80" autocomplete="off" placeholder="Scan atau ketik barcode" />
            </label>
            <button class="btn btn-ghost" type="button" data-open-product-barcode-camera>Kamera</button>
          </div>
          <button class="btn btn-secondary" type="submit">Simpan barcode</button>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="product-barcode-camera" role="dialog" aria-modal="true" aria-labelledby="product-barcode-camera-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Pemindai</span>
            <h2 id="product-barcode-camera-title">Scan barcode produk</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>
        <div id="product-barcode-camera-reader" class="barcode-camera-reader"></div>
        <div class="barcode-camera-controls">
          <label class="is-hidden" data-product-barcode-camera-select-field>
            <span>Kamera</span>
            <select data-product-barcode-camera-select></select>
          </label>
          <button class="btn btn-ghost" type="button" data-stop-product-barcode-camera>Tutup kamera</button>
        </div>
        <p class="barcode-camera-status" data-product-barcode-camera-status aria-live="polite"></p>
      </article>
    </div>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Master produk</span>
            <h2>Produk & stok</h2>
          </div>
        </div>
        <div class="product-table-controls" aria-label="Filter produk">
          <label>
            <span>Jenis</span>
            <select data-product-type-filter>
              <option value="all">Semua jenis</option>
              <option value="regular">Satuan</option>
              <option value="volume">Volume / berat</option>
              <option value="composite">Composite / paket</option>
            </select>
          </label>
          <label>
            <span>Stok</span>
            <select data-product-stock-filter>
              <option value="all">Semua stok</option>
              <option value="ready">Stok tersedia</option>
              <option value="low">Stok menipis</option>
              <option value="empty">Stok habis</option>
            </select>
          </label>
          <label>
            <span>Loyverse</span>
            <select data-product-loyverse-filter>
              <option value="all">Semua status</option>
              <option value="synced">Terintegrasi</option>
              <option value="unsynced">Belum terintegrasi</option>
            </select>
          </label>
          <label>
            <span>Per halaman</span>
            <select data-product-page-size>
              <option value="10">10 produk</option>
              <option value="25">25 produk</option>
              <option value="50">50 produk</option>
              <option value="100">100 produk</option>
            </select>
          </label>
        </div>
        <div class="data-table product-table" role="table" aria-label="Produk dan stok" data-product-table></div>
        <div class="pagination-bar" data-product-pagination></div>
      </article>
    </section>
  `,
  purchases: `
    <section class="action-grid" id="pembelian-baru" aria-label="Aksi pembelian">
      <article class="panel action-panel">
        <div class="panel-head">
          <div>
            <span class="label">Aksi cepat</span>
            <h2>Purchase Order</h2>
          </div>
        </div>

        <div class="quick-actions">
          <button class="btn btn-secondary" type="button" data-open-modal="purchase">Buat purchase order</button>
          <button class="btn btn-ghost" type="button" data-open-modal="purchase-receive">Terima barang</button>
          <button class="btn btn-ghost" type="button" data-open-modal="purchase-import">Import PO sebelumnya</button>
          <a class="btn btn-ghost" href="${API_BASE}/purchase-import.php?action=template">Unduh template</a>
        </div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="purchase" role="dialog" aria-modal="true" aria-labelledby="purchase-modal-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Pembelian</span>
            <h2 id="purchase-modal-title">Purchase Order</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-purchase-form>
          <label>
            <span>Nomor PO</span>
            <input type="text" name="invoice_no" placeholder="Otomatis saat disimpan" readonly />
          </label>
          <label>
            <span>Supplier</span>
            <input type="text" name="supplier_name" placeholder="Supplier utama" />
          </label>
          <div class="purchase-items" data-purchase-items>
            <div class="purchase-item-row" data-purchase-item>
              <label>
                <span>Produk</span>
                <select name="product_id" data-product-select required></select>
              </label>
              <label>
                <span>Qty</span>
                <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
              </label>
              <label>
                <span>Harga beli satuan</span>
                <input type="number" name="unit_cost" min="0" value="0" required />
              </label>
              <button class="icon-button" type="button" data-remove-purchase-item aria-label="Hapus item">Hapus</button>
            </div>
          </div>
          <button class="btn btn-ghost" type="button" data-add-purchase-item>Tambah item</button>
          <div class="transaction-summary">
            <div><span>Total PO</span><strong data-purchase-total>Rp 0</strong></div>
          </div>
          <button class="btn btn-secondary" type="submit">Simpan purchase order</button>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="purchase-receive" role="dialog" aria-modal="true" aria-labelledby="receive-modal-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Terima barang</span>
            <h2 id="receive-modal-title">Terima Purchase Order</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-receive-form>
          <label>
            <span>Nomor PO</span>
            <select name="id" data-pending-purchase-select required></select>
          </label>
          <label>
            <span>Biaya lain</span>
            <input type="number" name="additional_cost" min="0" value="0" required />
          </label>
          <button class="btn btn-secondary" type="submit">Terima barang</button>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="purchase-import" role="dialog" aria-modal="true" aria-labelledby="purchase-import-modal-title">
      <article class="modal-panel modal-panel-small">
        <div class="panel-head">
          <div>
            <span class="label">Purchase Order</span>
            <h2 id="purchase-import-modal-title">Import PO Excel</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>

        <form class="dashboard-form" data-purchase-import-form enctype="multipart/form-data">
          <p class="notice">
            Isi sheet Purchase Order dan Item PO. Satu PO bisa memiliki beberapa item dengan invoice_no yang sama. Gunakan terima_stok = ya jika barang sudah diterima dan stok sudah bertambah.
          </p>
          <label>
            <span>File Excel (.xlsx)</span>
            <input type="file" name="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required />
          </label>
          <div class="quick-actions">
            <button class="btn btn-secondary" type="submit">Import PO</button>
            <a class="btn btn-ghost" href="${API_BASE}/purchase-import.php?action=template">Unduh template</a>
          </div>
        </form>
      </article>
    </div>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Riwayat purchase order</span>
            <h2>Purchase Order</h2>
          </div>
        </div>
        <div class="product-table-controls" aria-label="Filter pembelian">
          <label>
            <span>Per halaman</span>
            <select data-purchase-page-size>
              <option value="25">25 PO</option>
              <option value="50" selected>50 PO</option>
              <option value="100">100 PO</option>
            </select>
          </label>
        </div>
        <div class="data-table purchase-table" role="table" aria-label="Riwayat purchase order" data-purchase-table></div>
        <div data-purchase-pagination></div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="purchase-detail" role="dialog" aria-modal="true" aria-labelledby="purchase-detail-title">
      <article class="modal-panel detail-modal">
        <div class="panel-head">
          <div>
            <span class="label">Detail pembelian</span>
            <h2 id="purchase-detail-title" data-purchase-detail-title>Purchase Order</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>
        <div data-purchase-detail-body></div>
      </article>
    </div>
  `,
  receivables: `
    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Piutang</span>
            <h2>Tagihan pelanggan</h2>
          </div>
        </div>
        <div class="data-table receivable-table" role="table" aria-label="Daftar piutang" data-receivable-table></div>
      </article>
    </section>

    <div class="app-modal is-hidden" data-modal="receivable-payment" role="dialog" aria-modal="true" aria-labelledby="receivable-payment-title">
      <article class="modal-panel">
        <div class="panel-head">
          <div>
            <span class="label">Piutang</span>
            <h2 id="receivable-payment-title">Pembayaran</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>
        <form class="dashboard-form" data-receivable-payment-form>
          <input type="hidden" name="id" />
          <div class="detail-summary compact-summary">
            <div><span>Invoice</span><strong data-payment-invoice>-</strong></div>
            <div><span>Pelanggan</span><strong data-payment-customer>-</strong></div>
            <div><span>Sisa</span><strong data-payment-remaining>Rp 0</strong></div>
          </div>
          <label>
            <span>Nominal bayar</span>
            <input type="number" name="amount" min="1" required />
          </label>
          <label>
            <span>Metode bayar</span>
            <select name="payment_method" required>
              <option value="cash">Tunai</option>
              <option value="qris">QRIS</option>
              <option value="transfer">Transfer</option>
            </select>
          </label>
          <label>
            <span>Catatan</span>
            <textarea name="notes" placeholder="Opsional"></textarea>
          </label>
          <button class="btn btn-secondary" type="submit">Simpan pembayaran</button>
        </form>
      </article>
    </div>

    <div class="app-modal is-hidden" data-modal="receivable-detail" role="dialog" aria-modal="true" aria-labelledby="receivable-detail-title">
      <article class="modal-panel detail-modal">
        <div class="panel-head">
          <div>
            <span class="label">Detail piutang</span>
            <h2 id="receivable-detail-title" data-receivable-detail-title>Piutang</h2>
          </div>
          <button class="icon-button" type="button" data-close-modal aria-label="Tutup">Tutup</button>
        </div>
        <div data-receivable-detail-body></div>
      </article>
    </div>
  `,
  salesReport: `
    <section class="metric-grid" aria-label="Ringkasan laporan penjualan">
      <article class="metric-card">
        <span>Total omzet</span>
        <strong data-sales-report="total_amount">Rp 0</strong>
        <small data-sales-report="count">0 transaksi</small>
      </article>
      <article class="metric-card">
        <span>Modal terjual</span>
        <strong data-sales-report="cost_amount">Rp 0</strong>
        <small>Akumulasi HPP</small>
      </article>
      <article class="metric-card">
        <span>Laba kotor</span>
        <strong data-sales-report="gross_profit">Rp 0</strong>
        <small>Subtotal dikurangi HPP</small>
      </article>
      <article class="metric-card">
        <span>Total diskon</span>
        <strong data-sales-report="discount_amount">Rp 0</strong>
        <small>Pengurang nilai penjualan</small>
      </article>
      <article class="metric-card warning">
        <span>Pajak keluaran</span>
        <strong data-sales-report="tax_amount">Rp 0</strong>
        <small>Tidak dihitung sebagai laba</small>
      </article>
    </section>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Laporan</span>
            <h2>Penjualan</h2>
          </div>
        </div>
        <div class="data-table sales-report-table" role="table" aria-label="Laporan penjualan" data-sales-report-table></div>
      </article>
    </section>
  `,
  purchaseReport: `
    <section class="metric-grid" aria-label="Ringkasan laporan pembelian">
      <article class="metric-card">
        <span>Total pembelian</span>
        <strong data-purchase-report="total_amount">Rp 0</strong>
        <small data-purchase-report="count">0 purchase order</small>
      </article>
      <article class="metric-card">
        <span>Biaya lain</span>
        <strong data-purchase-report="additional_cost">Rp 0</strong>
        <small>Ongkir dan biaya tambahan</small>
      </article>
      <article class="metric-card">
        <span>Diterima</span>
        <strong data-purchase-report="received_count">0 PO</strong>
        <small>Masuk stok</small>
      </article>
      <article class="metric-card warning">
        <span>Status</span>
        <strong>PO & Terima Barang</strong>
        <small>Untuk kontrol HPP</small>
      </article>
    </section>

    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Laporan</span>
            <h2>Pembelian</h2>
          </div>
        </div>
        <div class="data-table purchase-report-table" role="table" aria-label="Laporan pembelian" data-purchase-report-table></div>
      </article>
    </section>
  `,
  profile: `
    <section class="lower-grid single-grid">
      <article class="panel">
        <div class="panel-head">
          <div>
            <span class="label">Pengaturan</span>
            <h2>Profil</h2>
          </div>
        </div>

        <form class="dashboard-form settings-form" data-profile-form>
          <label>
            <span>Nama</span>
            <input type="text" name="name" data-profile-name required />
          </label>
          <label>
            <span>Email</span>
            <input type="email" data-profile-email readonly />
          </label>
          <label>
            <span>Role</span>
            <input type="text" data-profile-role readonly />
          </label>
          <button class="btn btn-secondary" type="submit">Simpan profil</button>
        </form>
      </article>
      <article class="panel">
        <div class="panel-head">
          <div>
            <span class="label">Keamanan</span>
            <h2>Ubah password</h2>
          </div>
        </div>

        <form class="dashboard-form settings-form" data-password-form>
          <label>
            <span>Password saat ini</span>
            <input type="password" name="current_password" autocomplete="current-password" required />
          </label>
          <div class="form-columns">
            <label>
              <span>Password baru</span>
              <input type="password" name="new_password" autocomplete="new-password" minlength="6" required />
            </label>
            <label>
              <span>Konfirmasi password baru</span>
              <input type="password" name="password_confirmation" autocomplete="new-password" minlength="6" required />
            </label>
          </div>
          <button class="btn btn-secondary" type="submit">Ubah password</button>
        </form>
      </article>
    </section>
  `,
  access: `
    <section class="lower-grid single-grid">
      <article class="panel" data-team-panel>
        <div class="panel-head">
          <div>
            <span class="label">Akses</span>
            <h2>Tim outlet</h2>
          </div>
        </div>
        <form class="dashboard-form settings-form" data-team-form>
          <div class="form-columns">
            <label>
              <span>Nama</span>
              <input type="text" name="name" required />
            </label>
            <label>
              <span>Email</span>
              <input type="email" name="email" required />
            </label>
          </div>
          <div class="form-columns">
            <label>
              <span>Password awal</span>
              <input type="password" name="password" minlength="6" required />
            </label>
            <label>
              <span>Role</span>
              <select name="role">
                <option value="manager">Manager</option>
                <option value="cashier">Cashier</option>
              </select>
            </label>
          </div>
          <button class="btn btn-secondary" type="submit">Buat user</button>
        </form>
        <div class="data-table team-table" role="table" aria-label="User outlet" data-team-table></div>
      </article>
    </section>
  `,
  businessProfile: `
    <section class="lower-grid single-grid">
      <article class="panel">
        <div class="panel-head">
          <div>
            <span class="label">Pengaturan</span>
            <h2>Profil Bisnis</h2>
          </div>
        </div>

        <form class="dashboard-form settings-form" data-business-form enctype="multipart/form-data">
          <div class="business-logo-preview">
            <span data-business-logo-placeholder>Logo usaha</span>
            <img src="" alt="Logo usaha" data-business-logo />
          </div>
          <label>
            <span>Nama usaha</span>
            <input type="text" name="name" data-business-name required />
          </label>
          <label>
            <span>Alamat</span>
            <textarea name="address" rows="3" data-business-address></textarea>
          </label>
          <div class="form-columns">
            <label>
              <span>Bank penagihan</span>
              <input type="text" name="billing_bank_name" data-business-billing-bank placeholder="BCA / Mandiri / BRI" />
            </label>
            <label>
              <span>No rekening</span>
              <input type="text" name="billing_account_number" data-business-billing-number placeholder="Nomor rekening" />
            </label>
          </div>
          <label>
            <span>Nama pemilik rekening</span>
            <input type="text" name="billing_account_name" data-business-billing-name placeholder="Nama pemilik rekening" />
          </label>
          <label class="checkbox-field">
            <input type="checkbox" name="tax_enabled" value="1" data-business-tax-enabled />
            <span>Aktifkan pajak invoice</span>
          </label>
          <label>
            <span>Pajak invoice (%)</span>
            <input type="number" name="tax_rate" data-business-tax-rate min="0" max="100" step="0.01" value="0" />
          </label>
          <label class="checkbox-field">
            <input type="checkbox" name="quotation_enabled" value="1" data-business-quotation-enabled />
            <span>Tampilkan fitur Quotation</span>
          </label>
          <label>
            <span>Nomor WhatsApp Notifikasi</span>
            <input type="tel" name="whatsapp_number" data-business-whatsapp placeholder="08xxxxxxxxxx" />
          </label>
          <label>
            <span>Logo usaha</span>
            <input type="file" name="logo" accept="image/png,image/jpeg,image/webp" />
          </label>
          <button class="btn btn-secondary" type="submit">Simpan profil bisnis</button>
        </form>
      </article>
    </section>
  `,
  integrations: `
    <section class="lower-grid single-grid">
      <article class="panel integration-panel">
        <div class="panel-head">
          <div>
            <span class="label">Loyverse</span>
            <h2>Integrasi akun</h2>
          </div>
          <span class="chip" data-loyverse-chip>Memuat</span>
        </div>

        <div class="integration-state" data-loyverse-status>
          <p class="notice">Memuat status integrasi Loyverse.</p>
        </div>
      </article>
    </section>
  `,
  logs: `
    <section class="lower-grid single-grid">
      <article class="panel table-panel">
        <div class="panel-head">
          <div>
            <span class="label">Log aktivitas</span>
            <h2>Semua kegiatan</h2>
          </div>
        </div>
        <div class="data-table activity-log-table" role="table" aria-label="Log semua kegiatan" data-activity-log-table></div>
        <div class="pagination-bar" data-activity-log-pagination></div>
      </article>
    </section>
  `,
};

let retryTimer = null;
let isDashboardReady = false;

const asArray = (value) => (Array.isArray(value) ? value : []);

const asObject = (value, fallback = {}) => (
  value && typeof value === 'object' && !Array.isArray(value) ? value : fallback
);

const escapeMarkup = (value) => String(value ?? '')
  .replaceAll('&', '&amp;')
  .replaceAll('<', '&lt;')
  .replaceAll('>', '&gt;')
  .replaceAll('"', '&quot;')
  .replaceAll("'", '&#039;');

const setText = (selector, value) => {
  const element = document.querySelector(selector);
  if (element) {
    element.textContent = value;
  }
};

const searchPlaceholders = {
  dashboard: 'Cari transaksi atau produk',
  sales: 'Cari transaksi',
  quotations: 'Cari quotation',
  stock: 'Cari produk',
  purchases: 'Cari purchase order',
  receivables: 'Cari piutang',
  salesReport: 'Cari laporan penjualan',
  purchaseReport: 'Cari laporan pembelian',
  profile: 'Cari pengaturan profil',
  access: 'Cari user',
  businessProfile: 'Cari pengaturan bisnis',
  integrations: 'Cari integrasi',
  logs: 'Cari log pada halaman ini',
};

const ensureGlobalSearch = () => {
  const header = document.querySelector('.app-header');
  if (!header || document.body.classList.contains('is-access-denied')) return;

  let actions = header.querySelector('.header-actions');
  if (!actions) {
    actions = document.createElement('div');
    actions.className = 'header-actions';
    header.append(actions);
  }

  let input = actions.querySelector('[data-global-search]') || actions.querySelector('.search-field input');
  if (input) {
    input.dataset.globalSearch = 'true';
  }
  if (!input) {
    const label = document.createElement('label');
    label.className = 'search-field';
    label.innerHTML = `
      <span class="sr-only">Cari data halaman</span>
      <input type="search" data-global-search />
    `;
    actions.prepend(label);
    input = label.querySelector('input');
  }

  input.placeholder = searchPlaceholders[currentPage()] || 'Cari data';
};

const searchableElements = () => {
  const content = document.querySelector('[data-page-content]');
  if (!content) return [];

  const granular = [
    ...content.querySelectorAll('.table-row:not(.table-head)'),
    ...content.querySelectorAll('.stock-list > article'),
    ...content.querySelectorAll('.payment-list > *'),
    ...content.querySelectorAll('.integration-state > *'),
  ];

  return granular.length ? [...new Set(granular)] : [...content.querySelectorAll('.panel')];
};

const normalizedSearchText = (value) => String(value || '')
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '')
  .toLowerCase()
  .trim();

const applyGlobalSearch = (value) => {
  const query = normalizedSearchText(value);

  if (currentPage() === 'stock' && document.querySelector('[data-product-table]')) {
    productSearchQuery = query;
    productTablePage = 1;
    renderProductTable();
    return;
  }

  const elements = searchableElements();
  let visibleCount = 0;

  for (const element of elements) {
    const matches = query === '' || normalizedSearchText(element.textContent).includes(query);
    element.classList.toggle('is-search-hidden', !matches);
    if (matches) visibleCount += 1;
  }

  const content = document.querySelector('[data-page-content]');
  if (!content) return;

  let emptyState = content.querySelector('[data-search-empty]');
  if (!emptyState) {
    emptyState = document.createElement('p');
    emptyState.className = 'search-empty notice';
    emptyState.dataset.searchEmpty = 'true';
    emptyState.textContent = 'Data yang dicari tidak ditemukan pada halaman ini.';
    content.append(emptyState);
  }
  emptyState.hidden = query === '' || elements.length === 0 || visibleCount > 0;
};

const setNavDropdownState = (group, expanded) => {
  const toggle = group?.querySelector('[data-sidebar-toggle]');
  const panel = group?.querySelector('[data-sidebar-panel]');
  if (!toggle || !panel) return;

  toggle.setAttribute('aria-expanded', String(expanded));
  toggle.classList.toggle('is-expanded', expanded);
  panel.hidden = !expanded;
};

const initializeNavDropdowns = () => {
  const topNavLinks = document.querySelector('.top-nav-links');
  if (!topNavLinks || topNavLinks.dataset.dropdownsReady === 'true') return;

  topNavLinks.dataset.dropdownsReady = 'true';

  for (const [index, section] of [...topNavLinks.querySelectorAll('.side-section')].entries()) {
    const group = document.createElement('div');
    const toggle = document.createElement('button');
    const panel = document.createElement('div');
    const panelId = `nav-section-${index + 1}`;

    group.className = 'side-dropdown-group';
    toggle.type = 'button';
    toggle.className = 'side-section-toggle';
    toggle.dataset.sidebarToggle = 'true';
    toggle.setAttribute('aria-controls', panelId);
    toggle.textContent = section.textContent.trim();

    panel.id = panelId;
    panel.className = 'side-dropdown';
    panel.dataset.sidebarPanel = 'true';

    section.insertAdjacentElement('beforebegin', group);
    group.append(toggle, panel);

    let cursor = section.nextElementSibling;
    while (cursor && !cursor.classList.contains('side-section') && !cursor.matches('[data-logout]')) {
      const next = cursor.nextElementSibling;
      panel.append(cursor);
      cursor = next;
    }
    section.remove();

    const containsCurrentPage = Boolean(panel.querySelector(`[data-nav="${currentPage()}"]`));
    setNavDropdownState(group, containsCurrentPage);

    toggle.addEventListener('click', () => {
      setNavDropdownState(group, toggle.getAttribute('aria-expanded') !== 'true');
    });
  }
};

const closeMobileNavigation = () => {
  document.body.classList.remove('is-mobile-menu-open');
  const toggle = document.querySelector('[data-mobile-menu-toggle]');
  const topNav = document.querySelector('.top-nav');
  toggle?.setAttribute('aria-expanded', 'false');
  toggle?.setAttribute('aria-label', 'Buka menu navigasi');
  if (window.matchMedia('(max-width: 900px)').matches) {
    const restoreFocus = Boolean(topNav?.contains(document.activeElement));
    topNav?.querySelector('.top-nav-links')?.setAttribute('inert', '');
    topNav?.querySelector('.top-nav-links')?.setAttribute('aria-hidden', 'true');
    if (restoreFocus) {
      toggle?.focus();
    }
  }
};

const openMobileNavigation = () => {
  document.body.classList.add('is-mobile-menu-open');
  const toggle = document.querySelector('[data-mobile-menu-toggle]');
  const topNav = document.querySelector('.top-nav');
  toggle?.setAttribute('aria-expanded', 'true');
  toggle?.setAttribute('aria-label', 'Tutup menu navigasi');
  topNav?.querySelector('.top-nav-links')?.removeAttribute('inert');
  topNav?.querySelector('.top-nav-links')?.setAttribute('aria-hidden', 'false');
  topNav?.querySelector('.top-nav-links a, .top-nav-links button')?.focus();
};

const initializeMobileNavigation = () => {
  const topNav = document.querySelector('.top-nav');
  if (!topNav || topNav.querySelector('[data-mobile-menu-toggle]')) return;

  const toggle = document.createElement('button');
  toggle.type = 'button';
  toggle.className = 'mobile-menu-toggle';
  toggle.dataset.mobileMenuToggle = 'true';
  toggle.setAttribute('aria-expanded', 'false');
  toggle.setAttribute('aria-label', 'Buka menu navigasi');
  toggle.title = 'Menu';
  toggle.innerHTML = '<span></span><span></span><span></span>';
  topNav.append(toggle);

  const backdrop = document.createElement('button');
  backdrop.type = 'button';
  backdrop.className = 'sidebar-backdrop';
  backdrop.dataset.mobileMenuClose = 'true';
  backdrop.setAttribute('aria-label', 'Tutup menu navigasi');
  document.body.append(backdrop);

  toggle.addEventListener('click', () => {
    if (document.body.classList.contains('is-mobile-menu-open')) {
      closeMobileNavigation();
    } else {
      openMobileNavigation();
    }
  });

  backdrop.addEventListener('click', () => {
    closeMobileNavigation();
    toggle.focus();
  });

  topNav.querySelector('.top-nav-links')?.addEventListener('click', (event) => {
    if (event.target.closest('a') && window.matchMedia('(max-width: 900px)').matches) {
      closeMobileNavigation();
    }
  });

  window.addEventListener('resize', () => {
    if (!window.matchMedia('(max-width: 900px)').matches) {
      closeMobileNavigation();
      const navLinks = topNav.querySelector('.top-nav-links');
      navLinks?.removeAttribute('inert');
      navLinks?.removeAttribute('aria-hidden');
    } else if (!document.body.classList.contains('is-mobile-menu-open')) {
      const navLinks = topNav.querySelector('.top-nav-links');
      navLinks?.setAttribute('inert', '');
      navLinks?.setAttribute('aria-hidden', 'true');
    }
  });

  closeMobileNavigation();
};

const enhanceMobileTables = (root = document) => {
  for (const table of root.querySelectorAll('.data-table')) {
    const labels = [...table.querySelectorAll('.table-head > *')]
      .map((cell) => cell.textContent.trim());

    for (const row of table.querySelectorAll('.table-row:not(.table-head)')) {
      const cells = [...row.children];
      const isEmptyRow = cells.length === 1;

      row.classList.toggle('table-empty-row', isEmptyRow);
      cells.forEach((cell, index) => {
        if (!isEmptyRow) {
          cell.dataset.columnLabel = labels[index] || '';
        }
      });
    }
  }
};

const observeResponsiveTables = () => {
  const content = document.querySelector('[data-page-content]');
  if (!content || content.dataset.responsiveTablesReady === 'true') return;

  content.dataset.responsiveTablesReady = 'true';
  const observer = new MutationObserver(() => enhanceMobileTables(content));
  observer.observe(content, { childList: true, subtree: true });
  enhanceMobileTables(content);
};

const applyRolePermissions = (role) => {
  currentUserRole = role || 'cashier';
  configureSupportChat(currentUserRole);
  document.body.classList.remove('is-access-denied');
  const isManager = currentUserRole === 'manager';
  const isAdministrator = currentUserRole === 'administrator';
  const topNavLinks = document.querySelector('.top-nav-links');
  let accessLink = document.querySelector('[data-nav="access"]');
  const profileLink = document.querySelector('[data-nav="profile"]');

  if (topNavLinks && profileLink && !accessLink) {
    accessLink = document.createElement('a');
    accessLink.className = 'sub-link';
    accessLink.href = 'access.html';
    accessLink.dataset.nav = 'access';
    accessLink.textContent = 'Akses';
    profileLink.insertAdjacentElement('afterend', accessLink);
  }

  for (const nav of document.querySelectorAll('[data-nav="access"], [data-nav="businessProfile"], [data-nav="integrations"], [data-nav="logs"]')) {
    nav.hidden = isManager;
  }

  let adminLink = document.querySelector('[data-admin-link]');
  if (isAdministrator && topNavLinks && !adminLink) {
    adminLink = document.createElement('a');
    adminLink.href = '../administrator/index.html';
    adminLink.dataset.adminLink = 'true';
    adminLink.textContent = 'Administrator';
    topNavLinks.insertBefore(adminLink, topNavLinks.querySelector('[data-logout]'));
  } else if (adminLink) {
    adminLink.hidden = !isAdministrator;
  }

  for (const group of document.querySelectorAll('.side-dropdown-group')) {
    const links = [...group.querySelectorAll('[data-sidebar-panel] > a, [data-sidebar-panel] > button')];
    group.hidden = links.length > 0 && links.every((link) => link.hidden);
  }

  if (typeof setView === 'function') {
    setView(currentPage());
  }
};

const renderAccessDenied = () => {
  const container = document.querySelector('[data-page-content]');
  if (!container) return;

  document.body.classList.add('is-access-denied');
  setText('[data-page-title]', 'Akses ditolak');
  container.innerHTML = `
    <section class="access-denied-state" aria-labelledby="access-denied-title">
      <span class="label">Akses dibatasi</span>
      <h2 id="access-denied-title">Laman ini tidak bisa Anda akses</h2>
      <p>Akun Manager tidak memiliki izin untuk membuka bagian ini.</p>
      <a class="btn btn-secondary" href="index.html">Kembali ke Dashboard</a>
    </section>
  `;
};

const renderFeatureDisabled = (featureName) => {
  const container = document.querySelector('[data-page-content]');
  if (!container) return;

  setText('[data-page-title]', featureName);
  container.innerHTML = `
    <section class="access-denied-state" aria-labelledby="feature-disabled-title">
      <span class="label">Fitur dinonaktifkan</span>
      <h2 id="feature-disabled-title">${featureName} tidak ditampilkan</h2>
      <p>Owner menonaktifkan fitur ini melalui pengaturan Profil Bisnis.</p>
      <a class="btn btn-secondary" href="index.html">Kembali ke Dashboard</a>
    </section>
  `;
};

const productOptionsHtml = (products, includeComposite = true, includePlaceholder = false) => {
  const available = includeComposite ? products : products.filter((product) => !product.is_composite);
  const placeholder = includePlaceholder ? '<option value="">Pilih produk</option>' : '';
  return available.length
    ? placeholder + available.map((product) => `<option value="${product.id}">${product.name} - stok ${product.stock_qty}${product.is_composite ? ' (paket)' : product.sold_by_weight ? ' (volume)' : ''}</option>`).join('')
    : '<option value="">Belum ada produk</option>';
};

const componentOptionsHtml = (products, excludedProductId = 0) => {
  const components = products.filter(
    (product) => !product.is_composite && Number(product.id) !== Number(excludedProductId),
  );
  return components.length
    ? components.map((product) => `<option value="${product.id}">${product.name} - ${product.sku}</option>`).join('')
    : '<option value="">Belum ada produk komponen</option>';
};

const updateProductKindFields = (form = document.querySelector('[data-product-form]')) => {
  if (!form) return;
  const isComposite = form.querySelector('[name="is_composite"]')?.value === '1';
  const compositeFields = form.querySelector('[data-composite-fields]');
  const regularFields = form.querySelector('[data-regular-product-fields]');
  const soldByWeight = form.querySelector('[name="sold_by_weight"]');
  const costInput = form.querySelector('[name="cost_price"]');

  compositeFields?.classList.toggle('is-hidden', !isComposite);
  regularFields?.classList.toggle('is-hidden', isComposite);
  if (soldByWeight) {
    soldByWeight.disabled = isComposite;
    if (isComposite) soldByWeight.value = '0';
  }
  if (costInput) {
    costInput.disabled = isComposite;
    costInput.required = !isComposite;
    if (isComposite) costInput.value = '0';
  }

  for (const field of regularFields?.querySelectorAll('input, select') ?? []) {
    field.disabled = isComposite;
    field.required = !isComposite;
    if (isComposite) field.value = '0';
  }
  for (const field of compositeFields?.querySelectorAll('input, select, [data-remove-composite-item]') ?? []) {
    field.disabled = !isComposite;
  }
};

const productCompositeItems = (form) => {
  const components = new Map();
  for (const row of form.querySelectorAll('[data-composite-item]')) {
    const productId = Number(row.querySelector('[name="component_product_id"]')?.value || 0);
    const quantityValue = Number(row.querySelector('[name="component_quantity"]')?.value || 0);
    if (productId > 0 && quantityValue > 0) {
      components.set(productId, (components.get(productId) || 0) + quantityValue);
    }
  }
  return [...components.entries()].map(([product_id, quantityValue]) => ({
    product_id,
    quantity: quantityValue,
  }));
};

const quickPackageSku = (baseSku) => {
  const base = `${String(baseSku || 'PACK').trim().slice(0, 35)}-PK`;
  let candidate = base;
  let suffix = 2;

  while (productCache.some((product) => String(product.sku).toLowerCase() === candidate.toLowerCase())) {
    const number = `-${suffix}`;
    candidate = `${base.slice(0, 40 - number.length)}${number}`;
    suffix += 1;
  }

  return candidate;
};

const resetProductForm = (form = document.querySelector('[data-product-form]')) => {
  if (!form) return;

  form.reset();
  form.querySelectorAll('.notice').forEach((notice) => notice.remove());
  const idInput = form.querySelector('[name="id"]');
  if (idInput) idInput.value = '';

  const rows = [...form.querySelectorAll('[data-composite-item]')];
  for (const row of rows.slice(1)) {
    row.remove();
  }

  const firstRow = rows[0];
  const componentSelect = firstRow?.querySelector('[name="component_product_id"]');
  const componentQty = firstRow?.querySelector('[name="component_quantity"]');
  if (componentSelect) {
    componentSelect.innerHTML = componentOptionsHtml(productCache);
    componentSelect.selectedIndex = 0;
  }
  if (componentQty) componentQty.value = '1';

  const title = document.querySelector('#product-modal-title');
  const submitButton = form.querySelector('[data-product-submit]');
  if (title) title.textContent = 'Tambah produk';
  if (submitButton) submitButton.textContent = 'Tambah produk';
  updateProductKindFields(form);
};

const compositeItemRowHtml = (component = null, excludedProductId = 0) => `
  <div class="composite-item-row" data-composite-item>
    <label>
      <span>Komponen</span>
      <select name="component_product_id" data-component-product-select>
        ${componentOptionsHtml(productCache, excludedProductId)}
      </select>
    </label>
    <label>
      <span>Qty</span>
      <input
        type="number"
        name="component_quantity"
        min="0.001"
        step="0.001"
        value="${component?.quantity ?? 1}"
      />
    </label>
    <button class="icon-button" type="button" data-remove-composite-item>Hapus</button>
  </div>
`;

const prepareProductEditForm = (product) => {
  const form = document.querySelector('[data-product-form]');
  if (!form || !product) return;

  resetProductForm(form);
  form.querySelector('[name="id"]').value = String(product.id);
  form.querySelector('[name="sku"]').value = product.sku || '';
  form.querySelector('[name="barcode"]').value = product.barcode || '';
  form.querySelector('[name="name"]').value = product.name || '';
  form.querySelector('[name="category"]').value = product.category || '';
  form.querySelector('[name="is_composite"]').value = product.is_composite ? '1' : '0';
  form.querySelector('[name="sold_by_weight"]').value = product.sold_by_weight ? '1' : '0';

  const componentList = form.querySelector('[data-composite-items]');
  if (componentList) {
    const components = product.is_composite && product.components?.length
      ? product.components
      : [null];
    componentList.innerHTML = components
      .map((component) => compositeItemRowHtml(component, product.id))
      .join('');
    components.forEach((component, index) => {
      if (!component) return;
      const row = componentList.querySelectorAll('[data-composite-item]')[index];
      const select = row?.querySelector('[name="component_product_id"]');
      if (select) select.value = String(component.product_id);
    });
  }

  updateProductKindFields(form);
  if (!product.is_composite) {
    form.querySelector('[name="stock_qty"]').value = String(product.stock_qty ?? 0);
    form.querySelector('[name="min_stock"]').value = String(product.min_stock ?? 0);
    form.querySelector('[name="cost_price"]').value = String(product.cost_price ?? 0);
  }
  form.querySelector('[name="sale_price"]').value = String(product.sale_price ?? 0);

  const title = document.querySelector('#product-modal-title');
  const submitButton = form.querySelector('[data-product-submit]');
  if (title) title.textContent = 'Edit produk';
  if (submitButton) submitButton.textContent = 'Simpan perubahan';
  openModal('product');
};

const prepareQuickPackageForm = (product) => {
  const form = document.querySelector('[data-product-form]');
  if (!form || !product || product.is_composite) return;

  resetProductForm(form);
  const rows = [...form.querySelectorAll('[data-composite-item]')];

  const firstRow = rows[0];
  const componentSelect = firstRow?.querySelector('[name="component_product_id"]');
  const componentQty = firstRow?.querySelector('[name="component_quantity"]');
  if (componentSelect) {
    componentSelect.innerHTML = componentOptionsHtml(productCache);
    componentSelect.value = String(product.id);
  }
  if (componentQty) {
    componentQty.value = '12';
  }

  form.querySelector('[name="is_composite"]').value = '1';
  form.querySelector('[name="sku"]').value = quickPackageSku(product.sku);
  form.querySelector('[name="name"]').value = `${product.name} - 1 Bungkus`.slice(0, 64);
  form.querySelector('[name="category"]').value = String(product.category || '').slice(0, 512);
  form.querySelector('[name="sale_price"]').value = String(Math.max(0, Number(product.sale_price || 0) * 12));
  updateProductKindFields(form);
  openModal('product');

  window.setTimeout(() => {
    componentQty?.focus();
    componentQty?.select();
  }, 0);
};

const transactionFormItems = (form) => {
  const items = [];

  for (const row of form.querySelectorAll('[data-transaction-item]')) {
    const productId = Number(row.querySelector('[name="product_id"]')?.value || 0);
    const qty = Number(row.querySelector('[name="qty"]')?.value || 0);
    const discountRate = Number(row.querySelector('[name="discount_rate"]')?.value || 0);

    if (productId > 0 && qty > 0 && discountRate >= 0 && discountRate <= 100) {
      items.push({ product_id: productId, qty, discount_rate: discountRate });
    }
  }

  return items;
};

const purchaseFormItems = (form) => {
  const items = [];
  for (const row of form.querySelectorAll('[data-purchase-item]')) {
    const productId = Number(row.querySelector('[name="product_id"]')?.value || 0);
    const qty = Number(row.querySelector('[name="qty"]')?.value || 0);
    const unitCost = Number(row.querySelector('[name="unit_cost"]')?.value || 0);
    if (productId > 0 && qty > 0 && unitCost >= 0) {
      items.push({ product_id: productId, qty, unit_cost: unitCost });
    }
  }
  return items;
};

const updatePurchaseSummary = (form = document.querySelector('[data-purchase-form]')) => {
  if (!form) return;
  const total = purchaseFormItems(form)
    .reduce((sum, item) => sum + (item.qty * item.unit_cost), 0);
  const target = form.querySelector('[data-purchase-total]');
  if (target) target.textContent = rupiah.format(total);
};

const addProductToTransaction = (form, productId) => {
  const list = form?.querySelector('[data-transaction-items]');
  if (!list) return false;

  const rows = [...list.querySelectorAll('[data-transaction-item]')];
  const existingRow = rows.find((row) => Number(row.querySelector('[name="product_id"]')?.value || 0) === productId);
  if (existingRow) {
    const qtyInput = existingRow.querySelector('[name="qty"]');
    qtyInput.value = String(Number(qtyInput.value || 0) + 1);
    updateTransactionSummary(form);
    return true;
  }

  const unusedRow = rows.find((row) => !Number(row.querySelector('[name="product_id"]')?.value || 0));
  if (unusedRow) {
    unusedRow.querySelector('[name="product_id"]').value = String(productId);
    unusedRow.querySelector('[name="qty"]').value = '1';
    updateTransactionSummary(form);
    return true;
  }

  list.insertAdjacentHTML('beforeend', `
    <div class="transaction-item-row" data-transaction-item>
      <label>
        <span>Produk</span>
        <select name="product_id" data-product-select required>${productOptionsHtml(productCache, true, true)}</select>
      </label>
      <label>
        <span>Qty</span>
        <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
      </label>
      <button class="icon-button" type="button" data-remove-transaction-item aria-label="Hapus item">Hapus</button>
    </div>
  `);
  const newRow = list.querySelector('[data-transaction-item]:last-child');
  newRow.querySelector('[name="product_id"]').value = String(productId);
  updateTransactionSummary(form);
  return true;
};

const setBarcodeFeedback = (form, message = '', type = '') => {
  const feedback = form?.querySelector('[data-barcode-feedback]');
  if (!feedback) return;
  feedback.textContent = message;
  feedback.classList.toggle('is-success', type === 'success');
  feedback.classList.toggle('is-error', type === 'error');
};

const processTransactionBarcode = (form, barcode) => {
  const normalizedBarcode = String(barcode || '').trim();
  if (!normalizedBarcode) return false;

  const product = productCache.find((row) => String(row.barcode || '') === normalizedBarcode);
  if (!product) {
    setBarcodeFeedback(form, `Barcode ${normalizedBarcode} tidak ditemukan.`, 'error');
    return false;
  }

  addProductToTransaction(form, Number(product.id));
  setBarcodeFeedback(form, `${product.name} ditambahkan.`, 'success');
  return true;
};

const barcodeCameraErrorMessage = (error) => {
  const name = error?.name || '';
  if (name === 'NotAllowedError') return 'Izin kamera ditolak. Izinkan kamera melalui pengaturan browser.';
  if (name === 'NotFoundError') return 'Kamera tidak ditemukan pada perangkat ini.';
  if (name === 'NotReadableError') return 'Kamera sedang digunakan aplikasi lain.';
  if (!window.isSecureContext) return 'Kamera hanya dapat digunakan melalui HTTPS.';
  return error?.message || (typeof error === 'string' ? error : 'Kamera tidak dapat dibuka.');
};

const ensureBarcodeCameraLibrary = () => {
  if (typeof Html5Qrcode !== 'undefined' && typeof Html5QrcodeSupportedFormats !== 'undefined') {
    return Promise.resolve();
  }
  if (barcodeCameraLibraryPromise) return barcodeCameraLibraryPromise;

  barcodeCameraLibraryPromise = new Promise((resolve, reject) => {
    const script = document.createElement('script');
    script.src = 'vendor/html5-qrcode.min.js?v=2.3.8';
    script.onload = () => {
      if (typeof Html5Qrcode !== 'undefined' && typeof Html5QrcodeSupportedFormats !== 'undefined') {
        resolve();
        return;
      }
      barcodeCameraLibraryPromise = null;
      reject(new Error('Komponen pemindai kamera tidak valid.'));
    };
    script.onerror = () => {
      barcodeCameraLibraryPromise = null;
      reject(new Error('Komponen pemindai kamera tidak berhasil dimuat.'));
    };
    document.head.append(script);
  });

  return barcodeCameraLibraryPromise;
};

const barcodeCameraFormats = () => [
  Html5QrcodeSupportedFormats.CODABAR,
  Html5QrcodeSupportedFormats.CODE_39,
  Html5QrcodeSupportedFormats.CODE_93,
  Html5QrcodeSupportedFormats.CODE_128,
  Html5QrcodeSupportedFormats.EAN_13,
  Html5QrcodeSupportedFormats.EAN_8,
  Html5QrcodeSupportedFormats.ITF,
  Html5QrcodeSupportedFormats.UPC_A,
  Html5QrcodeSupportedFormats.UPC_E,
  Html5QrcodeSupportedFormats.UPC_EAN_EXTENSION,
];

const setBarcodeCameraStatus = (form, message = '', type = '') => {
  const status = form?.querySelector('[data-barcode-camera-status]');
  if (!status) return;
  status.textContent = message;
  status.classList.toggle('is-error', type === 'error');
};

const stopBarcodeCamera = async (hidePanel = true) => {
  barcodeCameraSession += 1;
  const form = document.querySelector('[data-transaction-form]');
  const panel = form?.querySelector('[data-barcode-camera-panel]');
  const openButton = form?.querySelector('[data-open-barcode-camera]');
  const scanner = barcodeCameraScanner;

  barcodeCameraScanner = null;
  if (scanner && barcodeCameraRunning) {
    try {
      await scanner.stop();
    } catch {
      // Camera may already be stopped by the browser.
    }
  }
  barcodeCameraRunning = false;

  if (scanner) {
    try {
      scanner.clear();
    } catch {
      // The scanner element can already be empty after navigation.
    }
  }

  if (hidePanel) panel?.classList.add('is-hidden');
  if (openButton) openButton.disabled = false;
};

const startBarcodeCamera = async (form, requestedDeviceId = '') => {
  if (!form) return;

  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    const message = 'Kamera memerlukan HTTPS dan browser yang mendukung akses kamera.';
    setBarcodeFeedback(form, message, 'error');
    return;
  }

  await stopBarcodeCamera(false);
  const session = ++barcodeCameraSession;
  const panel = form.querySelector('[data-barcode-camera-panel]');
  const openButton = form.querySelector('[data-open-barcode-camera]');
  const cameraSelect = form.querySelector('[data-barcode-camera-select]');
  const selectField = form.querySelector('[data-barcode-camera-select-field]');

  panel?.classList.remove('is-hidden');
  if (openButton) openButton.disabled = true;
  setBarcodeCameraStatus(form, 'Meminta izin kamera...');

  try {
    await ensureBarcodeCameraLibrary();
    if (session !== barcodeCameraSession) return;

    const cameras = await Html5Qrcode.getCameras();
    if (session !== barcodeCameraSession) return;
    if (!cameras.length) throw Object.assign(new Error('Kamera tidak ditemukan pada perangkat ini.'), { name: 'NotFoundError' });

    if (cameraSelect) {
      cameraSelect.replaceChildren(...cameras.map((camera, index) => (
        new Option(camera.label || `Kamera ${index + 1}`, camera.id)
      )));
    }
    selectField?.classList.toggle('is-hidden', cameras.length < 2);

    const preferredCamera = cameras.find((camera) => /back|rear|environment|belakang/i.test(camera.label));
    const selectedDeviceId = cameras.some((camera) => camera.id === requestedDeviceId)
      ? requestedDeviceId
      : preferredCamera?.id || cameras[0].id;
    if (cameraSelect) cameraSelect.value = selectedDeviceId;

    const scanner = new Html5Qrcode('transaction-barcode-camera', { formatsToSupport: barcodeCameraFormats() }, false);
    barcodeCameraScanner = scanner;

    const cameraStart = scanner.start(
      selectedDeviceId,
      {
        fps: 10,
        aspectRatio: 1.777778,
        qrbox: (width, height) => ({
          width: Math.max(180, Math.min(Math.floor(width * 0.86), 420)),
          height: Math.max(90, Math.min(Math.floor(height * 0.34), 150)),
        }),
      },
      (decodedText) => {
        if (session !== barcodeCameraSession) return;
        if (processTransactionBarcode(form, decodedText)) {
          setBarcodeCameraStatus(form, 'Barcode berhasil dibaca.');
          void stopBarcodeCamera(true);
        } else {
          setBarcodeCameraStatus(form, `Barcode ${decodedText} belum terdaftar.`, 'error');
        }
      },
      () => {},
    );
    barcodeCameraRunning = true;
    await cameraStart;

    if (session !== barcodeCameraSession) {
      try {
        await scanner.stop();
      } catch {
        // The session was cancelled while the camera was starting.
      }
      return;
    }

    setBarcodeCameraStatus(form, 'Arahkan barcode ke kotak pemindai.');
  } catch (error) {
    if (session !== barcodeCameraSession) return;
    await stopBarcodeCamera(false);
    const message = barcodeCameraErrorMessage(error);
    setBarcodeFeedback(form, message, 'error');
    setBarcodeCameraStatus(form, message, 'error');
  }
};

const setProductBarcodeCameraStatus = (message = '', type = '') => {
  const status = document.querySelector('[data-product-barcode-camera-status]');
  if (!status) return;
  status.textContent = message;
  status.classList.toggle('is-error', type === 'error');
};

const stopProductBarcodeCamera = async (closeScannerModal = false) => {
  productBarcodeCameraSession += 1;
  const scanner = productBarcodeCameraScanner;
  productBarcodeCameraScanner = null;

  if (scanner && productBarcodeCameraRunning) {
    try {
      await scanner.stop();
    } catch {
      // Camera may already be stopped by the browser.
    }
  }
  productBarcodeCameraRunning = false;

  if (scanner) {
    try {
      scanner.clear();
    } catch {
      // Scanner UI may already be removed.
    }
  }

  if (closeScannerModal) {
    document.querySelector('[data-modal="product-barcode-camera"]')?.classList.add('is-hidden');
    if (!document.querySelector('.app-modal:not(.is-hidden)')) {
      document.body.classList.remove('has-modal-open');
    }
  }
};

const startProductBarcodeCamera = async (targetInput, requestedDeviceId = '') => {
  if (!targetInput) return;
  const sourceForm = targetInput.closest('form');

  if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
    showNotice(sourceForm, 'Kamera memerlukan HTTPS dan browser yang mendukung akses kamera.', 'error');
    return;
  }

  await stopBarcodeCamera(true);
  await stopProductBarcodeCamera(false);
  productBarcodeCameraTarget = targetInput;
  const session = ++productBarcodeCameraSession;
  openModal('product-barcode-camera');
  setProductBarcodeCameraStatus('Meminta izin kamera...');

  try {
    await ensureBarcodeCameraLibrary();
    if (session !== productBarcodeCameraSession) return;

    const cameras = await Html5Qrcode.getCameras();
    if (session !== productBarcodeCameraSession) return;
    if (!cameras.length) throw Object.assign(new Error('Kamera tidak ditemukan pada perangkat ini.'), { name: 'NotFoundError' });

    const cameraSelect = document.querySelector('[data-product-barcode-camera-select]');
    const selectField = document.querySelector('[data-product-barcode-camera-select-field]');
    if (cameraSelect) {
      cameraSelect.replaceChildren(...cameras.map((camera, index) => (
        new Option(camera.label || `Kamera ${index + 1}`, camera.id)
      )));
    }
    selectField?.classList.toggle('is-hidden', cameras.length < 2);

    const preferredCamera = cameras.find((camera) => /back|rear|environment|belakang/i.test(camera.label));
    const selectedDeviceId = cameras.some((camera) => camera.id === requestedDeviceId)
      ? requestedDeviceId
      : preferredCamera?.id || cameras[0].id;
    if (cameraSelect) cameraSelect.value = selectedDeviceId;

    const scanner = new Html5Qrcode(
      'product-barcode-camera-reader',
      { formatsToSupport: barcodeCameraFormats() },
      false,
    );
    productBarcodeCameraScanner = scanner;
    const cameraStart = scanner.start(
      selectedDeviceId,
      {
        fps: 10,
        aspectRatio: 1.777778,
        qrbox: (width, height) => ({
          width: Math.max(180, Math.min(Math.floor(width * 0.86), 420)),
          height: Math.max(90, Math.min(Math.floor(height * 0.34), 150)),
        }),
      },
      (decodedText) => {
        if (session !== productBarcodeCameraSession || !productBarcodeCameraTarget) return;
        productBarcodeCameraTarget.value = String(decodedText || '').trim();
        productBarcodeCameraTarget.dispatchEvent(new Event('input', { bubbles: true }));
        const scannedInput = productBarcodeCameraTarget;
        void stopProductBarcodeCamera(true).then(() => scannedInput.focus());
      },
      () => {},
    );
    productBarcodeCameraRunning = true;
    await cameraStart;

    if (session !== productBarcodeCameraSession) {
      try {
        await scanner.stop();
      } catch {
        // The scanner was closed while starting.
      }
      return;
    }

    setProductBarcodeCameraStatus('Arahkan barcode ke kotak pemindai.');
  } catch (error) {
    if (session !== productBarcodeCameraSession) return;
    await stopProductBarcodeCamera(false);
    const message = barcodeCameraErrorMessage(error);
    setProductBarcodeCameraStatus(message, 'error');
    showNotice(sourceForm, message, 'error');
  }
};

const updateTempoFields = (form) => {
  const paymentMethod = form.querySelector('[name="payment_method"]')?.value;
  const tempoFields = form.querySelector('[data-tempo-fields]');
  if (!tempoFields) return;

  const isTempo = paymentMethod === 'tempo';
  tempoFields.classList.toggle('is-hidden', !isTempo);

  for (const field of tempoFields.querySelectorAll('input, select')) {
    field.disabled = !isTempo;
    if (!isTempo && field.name === 'down_payment') {
      field.value = '0';
    }
  }
};

const updateTransactionSummary = (form = document.querySelector('[data-transaction-form]')) => {
  if (!form) return;
  updateTempoFields(form);

  const items = transactionFormItems(form);
  const gross = items.reduce((total, item) => {
    const product = productCache.find((row) => Number(row.id) === Number(item.product_id));
    return total + (product ? Number(product.sale_price || 0) * Number(item.qty || 0) : 0);
  }, 0);
  const discount = items.reduce((total, item) => {
    const product = productCache.find((row) => Number(row.id) === Number(item.product_id));
    const lineGross = product ? Number(product.sale_price || 0) * Number(item.qty || 0) : 0;
    return total + (lineGross * Number(item.discount_rate || 0) / 100);
  }, 0);
  const subtotal = Math.max(0, gross - discount);
  const taxRate = businessTaxConfig.enabled ? Number(businessTaxConfig.rate || 0) : 0;
  const taxAmount = Math.round(subtotal * taxRate / 100);
  const total = subtotal + taxAmount;

  setText('[data-transaction-gross]', rupiah.format(gross));
  setText('[data-transaction-discount]', rupiah.format(discount));
  setText('[data-transaction-subtotal]', rupiah.format(subtotal));
  setText('[data-transaction-tax-label]', taxRate > 0 ? `Pajak ${taxRate}%` : 'Pajak');
  setText('[data-transaction-tax]', rupiah.format(taxAmount));
  setText('[data-transaction-total]', rupiah.format(total));
};

const request = async (endpoint, options = {}) => {
  const response = await fetch(`${API_BASE}/${endpoint}`, {
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers ?? {}),
    },
    ...options,
  });

  let payload = null;
  try {
    payload = await response.json();
  } catch {
    payload = { error: 'Server tidak mengirim JSON yang valid.' };
  }

  if (!response.ok) {
    const error = new Error(payload.error || 'Request gagal.');
    error.status = response.status;
    throw error;
  }

  return payload;
};

const ensureToastRegion = () => {
  let region = document.querySelector('[data-toast-region]');
  if (region) return region;

  region = document.createElement('div');
  region.className = 'toast-region';
  region.dataset.toastRegion = 'true';
  region.setAttribute('aria-live', 'polite');
  region.setAttribute('aria-atomic', 'false');
  document.body.append(region);
  return region;
};

const showToast = (message, type = 'error', duration = 6000) => {
  const text = String(message || '').trim();
  if (!text) return;

  const region = ensureToastRegion();
  if ([...region.querySelectorAll('.app-toast p')].some((item) => item.textContent === text)) return;
  const toast = document.createElement('div');
  toast.className = `app-toast ${type === 'success' ? 'is-success' : 'is-danger'}`;
  toast.setAttribute('role', type === 'success' ? 'status' : 'alert');

  const content = document.createElement('div');
  const title = document.createElement('strong');
  title.textContent = type === 'success' ? 'Berhasil' : 'Terjadi masalah';
  const body = document.createElement('p');
  body.textContent = text;
  content.append(title, body);

  const close = document.createElement('button');
  close.type = 'button';
  close.textContent = 'Tutup';
  close.setAttribute('aria-label', 'Tutup notifikasi');

  let timer = null;
  const dismiss = () => {
    window.clearTimeout(timer);
    toast.classList.add('is-leaving');
    window.setTimeout(() => toast.remove(), 180);
  };
  close.addEventListener('click', dismiss);
  toast.append(content, close);
  region.append(toast);
  timer = window.setTimeout(dismiss, duration);
};

const ensureSupportChatWidget = () => {
  if (document.querySelector('[data-support-chat-widget]')) return;

  document.body.insertAdjacentHTML('beforeend', `
    <div class="support-chat-widget" data-support-chat-widget>
      <button class="support-chat-toggle" type="button" data-support-chat-toggle aria-expanded="false" aria-controls="support-chat-panel">
        <span>CS</span>
        <strong class="is-hidden" data-support-chat-badge>0</strong>
      </button>
      <section class="support-chat-panel is-hidden" id="support-chat-panel" data-support-chat-panel aria-label="Percakapan dengan customer service">
        <header>
          <div>
            <strong>Customer Service</strong>
            <span data-support-chat-state>Siap membantu</span>
          </div>
          <button class="icon-button" type="button" data-support-chat-close aria-label="Tutup chat">Tutup</button>
        </header>
        <div class="support-chat-toolbar">
          <button type="button" data-support-chat-history>Riwayat</button>
          <button class="is-hidden" type="button" data-support-chat-end>Akhiri sesi</button>
        </div>
        <div class="support-chat-history is-hidden" data-support-chat-history-panel></div>
        <div class="support-chat-messages" data-support-chat-messages>
          <p class="support-chat-empty" data-support-chat-empty>Silakan kirim pesan. Tim administrator akan membalas dari panel CS.</p>
        </div>
        <form class="support-chat-form" data-support-chat-form>
          <label class="sr-only" for="support-chat-message">Pesan</label>
          <textarea id="support-chat-message" name="message" maxlength="2000" rows="2" placeholder="Tulis pesan..." required></textarea>
          <button class="btn btn-secondary" type="submit">Kirim</button>
        </form>
      </section>
    </div>
  `);
};

const setSupportChatState = (message) => {
  const target = document.querySelector('[data-support-chat-state]');
  if (target) target.textContent = message;
};

const setSupportChatBadge = (count) => {
  const badge = document.querySelector('[data-support-chat-badge]');
  if (!badge) return;
  const total = Math.max(0, Number(count || 0));
  badge.textContent = total > 99 ? '99+' : String(total);
  badge.classList.toggle('is-hidden', total === 0 || supportChatOpen);
};

const resetSupportChatMessages = (message = 'Silakan kirim pesan. Tim administrator akan membalas dari panel CS.') => {
  supportChatLastMessageId = 0;
  supportChatRenderedIds.clear();
  const container = document.querySelector('[data-support-chat-messages]');
  if (container) {
    container.innerHTML = `<p class="support-chat-empty" data-support-chat-empty>${message}</p>`;
  }
};

const setSupportChatMode = (historyMode) => {
  supportChatViewingHistory = historyMode;
  document.querySelector('[data-support-chat-history-panel]')?.classList.toggle('is-hidden', !historyMode);
  document.querySelector('[data-support-chat-messages]')?.classList.toggle('is-hidden', historyMode);
  document.querySelector('[data-support-chat-form]')?.classList.toggle('is-hidden', historyMode);
  const historyButton = document.querySelector('[data-support-chat-history]');
  if (historyButton) historyButton.textContent = historyMode ? 'Kembali' : 'Riwayat';
};

const renderSupportChatHistory = (rows) => {
  const container = document.querySelector('[data-support-chat-history-panel]');
  if (!container) return;
  const histories = asArray(rows).filter((row) => row.status === 'closed');
  container.innerHTML = '';

  if (!histories.length) {
    const empty = document.createElement('p');
    empty.className = 'support-chat-empty';
    empty.textContent = 'Belum ada riwayat percakapan.';
    container.append(empty);
    return;
  }

  for (const history of histories) {
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.supportChatHistoryId = history.id;
    const title = document.createElement('strong');
    title.textContent = `Sesi #${history.id}`;
    const preview = document.createElement('span');
    preview.textContent = history.last_message || 'Percakapan tanpa pesan';
    const date = document.createElement('small');
    date.textContent = history.closed_at || history.last_message_at || history.created_at || '';
    button.append(title, preview, date);
    container.append(button);
  }
};

const appendSupportChatMessages = (messages) => {
  const container = document.querySelector('[data-support-chat-messages]');
  if (!container) return;
  let appended = false;

  for (const message of asArray(messages)) {
    const messageId = Number(message.id || 0);
    if (!messageId || supportChatRenderedIds.has(messageId)) continue;
    supportChatRenderedIds.add(messageId);
    supportChatLastMessageId = Math.max(supportChatLastMessageId, messageId);

    const item = document.createElement('article');
    item.className = `support-chat-message ${message.sender_type === 'administrator' ? 'is-admin' : 'is-outlet'}`;
    const meta = document.createElement('span');
    meta.textContent = `${message.sender_name || 'User'} · ${message.created_at || ''}`;
    const body = document.createElement('p');
    body.textContent = message.message || '';
    item.append(meta, body);
    container.append(item);
    appended = true;
  }

  container.querySelector('[data-support-chat-empty]')?.classList.toggle('is-hidden', supportChatRenderedIds.size > 0);
  if (appended && supportChatOpen) {
    container.scrollTop = container.scrollHeight;
  }
};

const scheduleSupportChatPoll = (delay = 0) => {
  window.clearTimeout(supportChatPollTimer);
  if (!supportChatEnabled) return;
  supportChatPollTimer = window.setTimeout(pollSupportChat, delay);
};

const pollSupportChat = async () => {
  if (!supportChatEnabled || supportChatPolling) return;
  supportChatPolling = true;

  try {
    const wait = supportChatViewingHistory ? 0 : (supportChatOpen ? 15 : 5);
    const conversationQuery = supportChatViewingHistory && supportChatConversationId
      ? `&conversation_id=${supportChatConversationId}`
      : '';
    const payload = await request(
      `support-chat.php?since_id=${supportChatLastMessageId}&wait=${wait}&mark_read=${supportChatOpen ? 1 : 0}${conversationQuery}`,
    );
    if (!supportChatViewingHistory) {
      supportChatConversationId = Number(payload.conversation?.id || 0);
    }
    appendSupportChatMessages(payload.messages);
    renderSupportChatHistory(payload.conversations);
    const unreadCount = asArray(payload.conversations)
      .filter((row) => row.status === 'open')
      .reduce((total, row) => total + Number(row.outlet_unread_count || 0), 0);
    setSupportChatBadge(supportChatOpen ? 0 : unreadCount);
    const endButton = document.querySelector('[data-support-chat-end]');
    endButton?.classList.toggle('is-hidden', supportChatViewingHistory || payload.conversation?.status !== 'open');
    setSupportChatState(
      supportChatViewingHistory
        ? `Riwayat sesi #${supportChatConversationId}`
        : (payload.conversation ? 'Terhubung ke administrator' : 'Mulai sesi baru'),
    );
    scheduleSupportChatPoll(supportChatViewingHistory ? 10000 : (supportChatOpen ? 0 : 5000));
  } catch (error) {
    setSupportChatState(error.status === 409 ? 'Fitur CS belum diaktifkan' : 'Mencoba menyambungkan kembali');
    if (error.status !== 401 && error.status !== 409) {
      scheduleSupportChatPoll(5000);
    }
  } finally {
    supportChatPolling = false;
  }
};

const configureSupportChat = (role) => {
  supportChatEnabled = role !== 'administrator';

  if (!supportChatEnabled) {
    document.querySelector('[data-support-chat-widget]')?.remove();
    window.clearTimeout(supportChatPollTimer);
    return;
  }

  ensureSupportChatWidget();
  scheduleSupportChatPoll(0);
};

const getDashboard = async () => {
  const dashboard = await request('dashboard.php');
  return asObject(dashboard, emptyDashboard);
};

const getProducts = async () => {
  const payload = await request('products.php');
  return asArray(payload.products);
};

const getTransactions = async (page = 1, perPage = 50) => {
  const payload = await request(`transactions.php?page=${page}&per_page=${perPage}`);
  return {
    transactions: asArray(payload.transactions),
    pagination: payload.pagination || null,
  };
};

const getPurchases = async (page = 1, perPage = 50) => {
  const payload = await request(`purchases.php?page=${page}&per_page=${perPage}`);
  return {
    purchases: asArray(payload.purchases),
    pagination: payload.pagination || null,
  };
};

const getReceivables = async () => {
  const payload = await request('receivables.php');
  return asArray(payload.receivables);
};

const getSettings = async () => {
  try {
    return await request('settings.php');
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    return { profile: null, business: null, error: error.message };
  }
};

const getReport = async (type, filters = {}) => {
  try {
    const params = new URLSearchParams({ type });
    Object.entries(filters).forEach(([key, value]) => {
      if (value !== '' && value != null) params.append(key, value);
    });
    return await request(`reports.php?${params.toString()}`);
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    return { summary: {}, rows: [], error: error.message };
  }
};

const emptyLogPayload = {
  logs: [],
  pagination: {
    page: 1,
    per_page: activityLogsPerPage,
    total: 0,
    total_pages: 1,
  },
};

const getActivityLogs = async (page = activityLogPage) => {
  if (currentPage() !== 'logs') {
    return emptyLogPayload;
  }

  try {
    const payload = await request(`activity-logs.php?page=${page}&per_page=${activityLogsPerPage}`);
    return {
      logs: asArray(payload.logs),
      pagination: asObject(payload.pagination, emptyLogPayload.pagination),
    };
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    console.warn('Log aktivitas belum siap:', error.message);
    return {
      logs: [{
        created_at: '-',
        user_name: 'Sistem',
        action: 'setup_required',
        entity_type: 'database',
        entity_id: null,
        description: error.message,
        ip_address: '-',
      }],
      pagination: emptyLogPayload.pagination,
    };
  }
};

const getTeamUsers = async () => {
  try {
    const payload = await request('users.php');
    return asArray(payload.users);
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    return [];
  }
};

const getQuotations = async () => {
  try {
    const payload = await request('quotations.php');
    return asArray(payload.quotations);
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    console.warn('Quotation belum siap:', error.message);
    return [];
  }
};

const getLoyverseStatus = async (includeStores = currentPage() === 'integrations') => {
  try {
    return await request(`loyverse.php?action=status${includeStores ? '&include_stores=1' : ''}`);
  } catch (error) {
    if (error.status === 401) {
      throw error;
    }

    return {
      configured: true,
      connected: false,
      error: error.message,
      connect_url: `${API_BASE}/loyverse.php?action=connect`,
    };
  }
};

const importLoyverseSales = async () => {
  return request('loyverse.php?action=import_receipts', { method: 'POST' });
};

const loyverseSyncMessage = (payload, fallback) => {
  const errors = asArray(payload?.errors);
  if (!errors.length) {
    return payload?.message || fallback;
  }

  const first = errors[0];
  const name = first.name || first.sku || first.receipt_number || 'Data pertama';
  return `${payload?.message || fallback}\n${name}: ${first.error || 'Error tidak diketahui.'}`;
};

const pushAllLoyverseProducts = async (button) => {
  const products = [...productCache].sort((left, right) => {
    const kindOrder = Number(Boolean(left.is_composite)) - Number(Boolean(right.is_composite));
    return kindOrder || String(left.name || '').localeCompare(String(right.name || ''), 'id');
  });
  if (!products.length) {
    return {
      message: 'Belum ada produk aktif untuk dipush ke Loyverse.',
      synced: 0,
      failed: 0,
      errors: [],
    };
  }

  const batchSize = 10;
  let synced = 0;
  let failed = 0;
  const errors = [];

  for (let index = 0; index < products.length; index += batchSize) {
    const batch = products.slice(index, index + batchSize);
    const processed = Math.min(index + batch.length, products.length);
    button.textContent = `Mengirim ${processed}/${products.length}`;

    let payload;
    try {
      payload = await request('loyverse.php?action=push_products', {
        method: 'POST',
        body: JSON.stringify({ ids: batch.map((product) => Number(product.id)) }),
      });
    } catch (error) {
      const progress = synced || failed
        ? ` Sebelum terhenti: ${synced} berhasil dan ${failed} gagal.`
        : '';
      throw new Error(`${error.message}${progress}`);
    }
    synced += Number(payload.synced || 0);
    failed += Number(payload.failed || 0);
    errors.push(...asArray(payload.errors));
  }

  return {
    message: errors.length
      ? `${synced} produk berhasil, ${failed} gagal.`
      : `${synced} produk berhasil dipush ke Loyverse.`,
    synced,
    failed,
    errors,
  };
};

const submitFormData = async (endpoint, data) => {
  const response = await fetch(`${API_BASE}/${endpoint}`, {
    method: 'POST',
    credentials: 'same-origin',
    body: data,
  });

  const text = await response.text();
  let payload = {};
  try {
    payload = text ? JSON.parse(text) : {};
  } catch {
    const cleanText = text.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim();
    throw new Error(cleanText || 'Server tidak mengirim JSON yang valid.');
  }

  if (!response.ok) {
    throw new Error(payload.error || 'Request gagal.');
  }

  return payload;
};

const setLoadingState = (isLoading, message = 'Menghubungkan ke database...') => {
  document.body.classList.toggle('is-loading-data', isLoading);
  for (const element of document.querySelectorAll('[data-db-status]')) {
    element.remove();
  }

  if (isLoading) {
    const content = document.querySelector('.content');
    content?.insertAdjacentHTML(
      'afterbegin',
      `<div class="db-status" data-db-status>
        <span class="loader" aria-hidden="true"></span>
        <div>
          <strong>${message}</strong>
          <p>Dashboard akan mencoba lagi otomatis sampai data berhasil dimuat.</p>
        </div>
      </div>`
    );
  }

  for (const control of document.querySelectorAll('.dashboard-form input, .dashboard-form select, .dashboard-form textarea, .dashboard-form button')) {
    control.disabled = isLoading;
  }
};

const renderSummary = (summary) => {
  summary = asObject(summary, emptyDashboard.summary);
  setText('[data-metric="revenue"]', rupiah.format(summary.revenue));
  setText('[data-metric="transactions"]', String(summary.transactions));
  setText('[data-metric="average_sale"]', `Rata-rata ${rupiah.format(summary.average_sale)}`);
  setText('[data-metric="gross_margin"]', `${summary.gross_margin}%`);
  setText('[data-metric="revenue_growth"]', `${summary.revenue_growth >= 0 ? '+' : ''}${summary.revenue_growth}% dari kemarin`);
  setText('[data-metric="low_stock"]', `${summary.low_stock} SKU`);
  setText('[data-metric="active_shifts"]', `${summary.active_shifts} shift aktif`);
};

const renderHourly = (rows) => {
  rows = asArray(rows);
  const chart = document.querySelector('[data-hourly-chart]');
  if (!chart) return;

  chart.classList.toggle('is-empty', rows.length === 0);
  if (rows.length === 0) {
    chart.innerHTML = '<p class="notice">Belum ada data penjualan hari ini.</p>';
    return;
  }

  const max = Math.max(...rows.map((row) => row.total), 1);
  chart.innerHTML = rows
    .map((row) => {
      const height = Math.max(12, Math.round((row.total / max) * 100));
      return `<span style="--h: ${height}%"><em>${row.hour}</em></span>`;
    })
    .join('');
};

const renderPayments = (rows) => {
  rows = asArray(rows);
  const list = document.querySelector('[data-payment-list]');
  if (!list) return;

  list.innerHTML = rows.length
    ? rows
      .map((row) => `
      <div>
        <span>${methodLabel[row.method] ?? row.method}</span>
        <strong>${rupiah.format(row.total)}</strong>
      </div>
    `)
      .join('')
    : '<p class="notice">Belum ada pembayaran masuk.</p>';
};

const renderTransactions = (rows, pagination) => {
  rows = asArray(rows);
  const table = document.querySelector('[data-transaction-table]');
  if (!table) return;

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader" class="col-check"><input type="checkbox" data-check-all="transactions" aria-label="Pilih Semua" /></span>
      <span role="columnheader">PO</span>
      <span role="columnheader">Kasir</span>
      <span role="columnheader">Metode</span>
      <span role="columnheader">Total</span>
      <span role="columnheader">Laba Bersih</span>
      <span role="columnheader">Status</span>
      <span role="columnheader">Dokumen</span>
      <span role="columnheader">Aksi</span>
    </div>
    ${rows.length
      ? rows
        .map((row) => {
          const netProfit = Number(row.net_profit ?? 0);
          const profitClass = netProfit >= 0 ? 'profit-positive' : 'profit-negative';
          return `
        <div class="table-row" role="row">
          <span class="col-check"><input type="checkbox" data-check-item="transactions" value="${row.id}" aria-label="Pilih Transaksi" /></span>
          <span>${row.invoice_no}</span>
          <span>${row.cashier}</span>
          <span>${methodLabel[row.payment_method] ?? row.payment_method}</span>
          <strong>${rupiah.format(row.total_amount)}</strong>
          <span class="${profitClass}">${rupiah.format(netProfit)}</span>
          <span class="status ${row.payment_status === 'paid' ? 'paid' : 'due'}">
            ${row.payment_status === 'paid' ? 'Lunas' : 'Piutang'}
          </span>
          <a class="doc-link" href="${API_BASE}/document.php?type=invoice&id=${row.id}" target="_blank" rel="noopener">Preview</a>
          <button class="table-action" type="button" data-view-transaction="${row.id}">Detail</button>
        </div>
      `;
        })
        .join('')
      : '<div class="table-row"><span>Belum ada transaksi.</span></div>'}
  `;

  const paginationContainer = document.querySelector('[data-transaction-pagination]');
  if (pagination && paginationContainer) {
    transactionTotalPages = pagination.total_pages || 1;
    paginationContainer.innerHTML = `
      <div class="product-table-controls" style="justify-content: space-between; margin-top: 16px;">
        <span class="muted-copy">Halaman ${pagination.page} dari ${pagination.total_pages} (${pagination.total} transaksi)</span>
        <div style="display: flex; gap: 8px;">
          <button class="btn btn-ghost" type="button" data-transaction-page="${pagination.page - 1}" ${pagination.page <= 1 ? 'disabled' : ''}>Sebelumnya</button>
          <button class="btn btn-ghost" type="button" data-transaction-page="${pagination.page + 1}" ${pagination.page >= pagination.total_pages ? 'disabled' : ''}>Selanjutnya</button>
        </div>
      </div>
    `;
  }
};

const renderLowStock = (rows) => {
  rows = asArray(rows);
  const list = document.querySelector('[data-low-stock-list]');
  if (!list) return;

  list.innerHTML = rows.length
    ? rows
        .map((row) => `
          <article>
            <div>
              <strong>${row.name}</strong>
              <span>Sisa ${row.stock_qty}, minimum ${row.min_stock}</span>
            </div>
            <button type="button" data-restock-product="${row.id}">PO</button>
          </article>
        `)
        .join('')
    : '<p class="notice">Tidak ada stok kritis.</p>';
};

const productMatchesTableFilters = (product) => {
  const typeMatches = productTypeFilter === 'all'
    || (productTypeFilter === 'regular' && !product.is_composite && !product.sold_by_weight)
    || (productTypeFilter === 'volume' && !product.is_composite && product.sold_by_weight)
    || (productTypeFilter === 'composite' && product.is_composite);

  const stock = Number(product.stock_qty || 0);
  const minStock = Number(product.min_stock || 0);
  const stockMatches = productStockFilter === 'all'
    || (productStockFilter === 'ready' && stock > minStock)
    || (productStockFilter === 'low' && stock > 0 && stock <= minStock)
    || (productStockFilter === 'empty' && stock <= 0);

  const loyverseMatches = productLoyverseFilter === 'all'
    || (productLoyverseFilter === 'synced' && product.loyverse_synced)
    || (productLoyverseFilter === 'unsynced' && !product.loyverse_synced);

  const searchText = normalizedSearchText([
    product.sku,
    product.barcode,
    product.name,
    product.category,
    product.is_composite ? 'composite paket' : product.sold_by_weight ? 'volume berat' : 'satuan',
    product.loyverse_synced ? 'terintegrasi loyverse' : 'belum terintegrasi',
  ].join(' '));
  const searchMatches = productSearchQuery === '' || searchText.includes(productSearchQuery);

  return typeMatches && stockMatches && loyverseMatches && searchMatches;
};

const productActionOptions = (product) => `
  <option value="">Aksi</option>
  <option value="edit">Edit produk</option>
  ${!product.is_composite ? '<option value="package">Buat paket</option>' : ''}
  <option value="barcode">Atur barcode</option>
  <option value="loyverse">${product.loyverse_synced ? 'Update Loyverse' : 'Push ke Loyverse'}</option>
  <option value="delete">Hapus produk</option>
`;

const renderProductTable = () => {
  const table = document.querySelector('[data-product-table]');
  if (!table) return;

  const filteredProducts = productCache.filter(productMatchesTableFilters);
  const totalProducts = filteredProducts.length;
  const totalPages = Math.max(1, Math.ceil(totalProducts / productTablePageSize));
  productTablePage = Math.min(Math.max(1, productTablePage), totalPages);

  const startIndex = (productTablePage - 1) * productTablePageSize;
  const visibleProducts = filteredProducts.slice(startIndex, startIndex + productTablePageSize);
  const endIndex = Math.min(startIndex + visibleProducts.length, totalProducts);

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader" class="col-check"><input type="checkbox" data-check-all="products" aria-label="Pilih Semua" /></span>
      <span role="columnheader">SKU</span>
      <span role="columnheader">Barcode</span>
      <span role="columnheader">Produk</span>
      <span role="columnheader">Kategori</span>
      <span role="columnheader">Tipe</span>
      <span role="columnheader">Stok</span>
      <span role="columnheader">Beli</span>
      <span role="columnheader">Jual</span>
      <span role="columnheader">Loyverse</span>
      <span role="columnheader">Aksi</span>
    </div>
    ${visibleProducts.length
      ? visibleProducts.map((product) => `
        <div class="table-row" role="row">
          <span class="col-check"><input type="checkbox" data-check-item="products" value="${product.id}" aria-label="Pilih Produk" /></span>
          <span>${product.sku}</span>
          <span>${product.barcode || '-'}</span>
          <span class="product-name-cell">
            <strong>${product.name}</strong>
            ${product.is_composite && product.components?.length
              ? `<small>${product.components.map((component) => `${component.quantity}x ${component.name}`).join(', ')}</small>`
              : ''}
          </span>
          <span>${product.category || '-'}</span>
          <span>${product.is_composite ? 'Composite' : product.sold_by_weight ? 'Volume' : 'Satuan'}</span>
          <strong>${product.stock_qty}</strong>
          <strong>${rupiah.format(product.cost_price)}</strong>
          <strong>${rupiah.format(product.sale_price)}</strong>
          <span class="status ${product.loyverse_synced ? 'paid' : 'due'}">
            ${product.loyverse_synced ? 'Terintegrasi' : 'Belum'}
          </span>
          <label class="product-action-select">
            <span class="sr-only">Aksi untuk ${product.name}</span>
            <select data-product-action data-product-id="${product.id}">
              ${productActionOptions(product)}
            </select>
          </label>
        </div>
      `).join('')
      : '<div class="table-row"><span>Tidak ada produk yang sesuai filter.</span></div>'}
  `;

  const typeSelect = document.querySelector('[data-product-type-filter]');
  const stockSelect = document.querySelector('[data-product-stock-filter]');
  const loyverseSelect = document.querySelector('[data-product-loyverse-filter]');
  const pageSizeSelect = document.querySelector('[data-product-page-size]');
  if (typeSelect) typeSelect.value = productTypeFilter;
  if (stockSelect) stockSelect.value = productStockFilter;
  if (loyverseSelect) loyverseSelect.value = productLoyverseFilter;
  if (pageSizeSelect) pageSizeSelect.value = String(productTablePageSize);

  const pagination = document.querySelector('[data-product-pagination]');
  if (pagination) {
    pagination.innerHTML = `
      <span class="muted-copy">Halaman ${productTablePage} dari ${totalPages} (${totalProducts} produk)</span>
      <div class="pagination-actions">
        <button class="btn btn-ghost" type="button" data-product-page="${productTablePage - 1}" ${productTablePage <= 1 ? 'disabled' : ''}>Sebelumnya</button>
        <button class="btn btn-ghost" type="button" data-product-page="${productTablePage + 1}" ${productTablePage >= totalPages ? 'disabled' : ''}>Selanjutnya</button>
      </div>
    `;
  }

  enhanceMobileTables(table.parentElement || document);
};

const renderProducts = (products) => {
  products = asArray(products);
  productCache = products;

  for (const select of document.querySelectorAll('[data-product-select]')) {
    const current = select.value;
    const includeComposite = !select.closest('[data-purchase-form]');
    const includePlaceholder = Boolean(select.closest('[data-transaction-form]'));
    const availableProducts = includeComposite ? products : products.filter((product) => !product.is_composite);
    select.innerHTML = productOptionsHtml(products, includeComposite, includePlaceholder);
    select.disabled = availableProducts.length === 0 || document.body.classList.contains('is-loading-data');

    if (current && availableProducts.some((product) => String(product.id) === current)) {
      select.value = current;
    }

    const submitButton = select.closest('form')?.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = availableProducts.length === 0 || document.body.classList.contains('is-loading-data');
    }
  }

  for (const select of document.querySelectorAll('[data-component-product-select]')) {
    const current = select.value;
    const editedProductId = Number(select.closest('[data-product-form]')?.querySelector('[name="id"]')?.value || 0);
    select.innerHTML = componentOptionsHtml(products, editedProductId);
    if (current && products.some(
      (product) => String(product.id) === current
        && !product.is_composite
        && Number(product.id) !== editedProductId,
    )) {
      select.value = current;
    }
  }
  updateProductKindFields();
  renderProductTable();
};

const renderReceivables = (rows) => {
  rows = asArray(rows);
  const table = document.querySelector('[data-receivable-table]');
  if (!table) return;

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">Invoice</span>
      <span role="columnheader">Pelanggan</span>
      <span role="columnheader">Tagihan</span>
      <span role="columnheader">Dibayar</span>
      <span role="columnheader">Sisa</span>
      <span role="columnheader">Tempo</span>
      <span role="columnheader">Status</span>
      <span role="columnheader">Aksi</span>
    </div>
    ${rows.length
      ? rows
          .map((row) => {
            const remaining = Number(row.remaining_amount ?? row.amount ?? 0);
            const isPaid = row.status === 'paid';
            return `
              <div class="table-row" role="row">
                <span>${row.invoice_no}</span>
                <span>${row.customer || 'Pelanggan tempo'}</span>
                <strong>${rupiah.format(row.amount)}</strong>
                <strong>${rupiah.format(row.paid_amount || 0)}</strong>
                <strong>${rupiah.format(remaining)}</strong>
                <span>${row.due_date || '-'}</span>
                <span class="status ${isPaid ? 'paid' : row.status === 'partial' ? 'partial' : 'due'}">
                  ${receivableStatusLabel[row.status] ?? row.status}
                </span>
                <span class="inline-actions">
                  ${isPaid
                    ? '<span>-</span>'
                    : `<button class="table-action" type="button" data-open-receivable-payment="${row.id}" data-receivable-invoice="${encodeURIComponent(row.invoice_no || '-')}" data-receivable-customer="${encodeURIComponent(row.customer || 'Pelanggan tempo')}" data-receivable-remaining="${remaining}">Bayar</button>`}
                  <button class="table-action" type="button" data-view-receivable="${row.id}">Detail</button>
                  ${row.last_payment_id
                    ? `<a class="doc-link" href="${API_BASE}/document.php?type=payment&id=${row.last_payment_id}" target="_blank" rel="noopener">Invoice bayar</a>`
                    : ''}
                </span>
              </div>
            `;
          })
          .join('')
      : '<div class="table-row"><span>Tidak ada piutang.</span></div>'}
  `;
};

const renderPurchases = (rows, pagination) => {
  rows = asArray(rows);
  const table = document.querySelector('[data-purchase-table]');
  if (!table) return;

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader" class="col-check"><input type="checkbox" data-check-all="purchases" aria-label="Pilih Semua" /></span>
      <span role="columnheader">PO</span>
      <span role="columnheader">Supplier</span>
      <span role="columnheader">Tanggal</span>
      <span role="columnheader">Total</span>
      <span role="columnheader">Status</span>
      <span role="columnheader">Aksi</span>
      <span role="columnheader">Detail</span>
    </div>
    ${rows.length
      ? rows
          .map((purchase) => `
            <div class="table-row" role="row">
              <span class="col-check"><input type="checkbox" data-check-item="purchases" value="${purchase.id}" aria-label="Pilih Purchase" /></span>
              <span>${purchase.invoice_no || `PO-${purchase.id}`}</span>
              <span>${purchase.supplier || 'Supplier belum diisi'}</span>
              <span>${purchase.purchased_at || '-'}</span>
              <strong>${rupiah.format(purchase.total_amount)}</strong>
              <span class="status ${purchase.status === 'received' ? 'paid' : 'due'}">
                ${purchase.status === 'received' ? 'Diterima' : 'Menunggu'}
              </span>
              ${purchase.status === 'received'
                ? '<span>-</span>'
                : `<button class="table-action" type="button" data-receive-purchase="${purchase.id}" data-receive-po="${purchase.invoice_no || `PO-${purchase.id}`}">Terima barang</button>`}
              <button class="table-action" type="button" data-view-purchase="${purchase.id}">Detail</button>
            </div>
          `)
          .join('')
      : '<div class="table-row"><span>Belum ada purchase order.</span></div>'}
  `;

  for (const select of document.querySelectorAll('[data-pending-purchase-select]')) {
    const pending = rows.filter((purchase) => purchase.status !== 'received');
    const current = select.value;

    select.innerHTML = pending.length
      ? pending
          .map((purchase) => `<option value="${purchase.id}">${purchase.invoice_no || `PO-${purchase.id}`} - ${purchase.supplier || 'Supplier belum diisi'}</option>`)
          .join('')
      : '<option value="">Tidak ada PO menunggu</option>';

    select.disabled = pending.length === 0 || document.body.classList.contains('is-loading-data');

    if (current && pending.some((purchase) => String(purchase.id) === current)) {
      select.value = current;
    }

    const submitButton = select.closest('form')?.querySelector('button[type="submit"]');
    if (submitButton) {
      submitButton.disabled = pending.length === 0 || document.body.classList.contains('is-loading-data');
    }
  }

  const paginationContainer = document.querySelector('[data-purchase-pagination]');
  if (pagination && paginationContainer) {
    purchaseTotalPages = pagination.total_pages || 1;
    paginationContainer.innerHTML = `
      <div class="pagination-bar">
        <span class="muted-copy">Halaman ${pagination.page} dari ${pagination.total_pages} (${pagination.total_records} PO)</span>
        <div class="pagination-actions">
          <button class="btn btn-ghost" type="button" data-purchase-page="${pagination.page - 1}" ${pagination.page <= 1 ? 'disabled' : ''}>Sebelumnya</button>
          <button class="btn btn-ghost" type="button" data-purchase-page="${pagination.page + 1}" ${pagination.page >= pagination.total_pages ? 'disabled' : ''}>Selanjutnya</button>
        </div>
      </div>
    `;
  } else if (paginationContainer) {
    paginationContainer.innerHTML = '';
  }
};

const renderSettings = (settings) => {
  const profile = settings?.profile ?? {};
  const business = settings?.business ?? {};
  applyRolePermissions(profile.role || 'cashier');
  currentUserId = Number(profile.id || 0);
  businessTaxConfig = {
    enabled: Number(business.tax_enabled || 0) === 1,
    rate: Number(business.tax_rate || 0),
  };
  businessFeatureConfig = {
    quotationEnabled: Number(business.quotation_enabled ?? 1) === 1,
  };

  const quotationNav = document.querySelector('[data-nav="quotations"]');
  if (quotationNav) quotationNav.hidden = !businessFeatureConfig.quotationEnabled;

  const profileName = document.querySelector('[data-profile-name]');
  if (profileName) profileName.value = profile.name || '';

  const profileEmail = document.querySelector('[data-profile-email]');
  if (profileEmail) profileEmail.value = profile.email || '';

  const profileRole = document.querySelector('[data-profile-role]');
  if (profileRole) profileRole.value = profile.role || '';

  const businessName = document.querySelector('[data-business-name]');
  if (businessName) businessName.value = business.name || '';

  const businessAddress = document.querySelector('[data-business-address]');
  if (businessAddress) businessAddress.value = business.address || '';

  const billingBank = document.querySelector('[data-business-billing-bank]');
  if (billingBank) billingBank.value = business.billing_bank_name || '';

  const billingNumber = document.querySelector('[data-business-billing-number]');
  if (billingNumber) billingNumber.value = business.billing_account_number || '';

  const billingName = document.querySelector('[data-business-billing-name]');
  if (billingName) billingName.value = business.billing_account_name || '';

  const taxEnabled = document.querySelector('[data-business-tax-enabled]');
  if (taxEnabled) taxEnabled.checked = Number(business.tax_enabled || 0) === 1;

  const taxRate = document.querySelector('[data-business-tax-rate]');
  if (taxRate) taxRate.value = business.tax_rate ?? 0;

  const quotationEnabled = document.querySelector('[data-business-quotation-enabled]');
  if (quotationEnabled) quotationEnabled.checked = businessFeatureConfig.quotationEnabled;

  const whatsappNumber = document.querySelector('[data-business-whatsapp]');
  if (whatsappNumber) whatsappNumber.value = business.whatsapp_number || '';

  const logo = document.querySelector('[data-business-logo]');
  const logoPlaceholder = document.querySelector('[data-business-logo-placeholder]');
  if (logo) {
    const logoPath = business.logo_path ? `../${business.logo_path}` : '';
    logo.src = logoPath;
    logo.hidden = !logoPath;
  }
  if (logoPlaceholder) {
    logoPlaceholder.hidden = Boolean(business.logo_path);
  }

  updateTransactionSummary();
};

const renderTeamUsers = (users) => {
  const panel = document.querySelector('[data-team-panel]');
  const table = document.querySelector('[data-team-table]');
  if (!panel || !table) return;

  const canManageTeam = ['owner', 'administrator'].includes(currentUserRole);
  panel.hidden = !canManageTeam;
  if (!canManageTeam) return;

  users = asArray(users);
  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">Nama</span>
      <span role="columnheader">Email</span>
      <span role="columnheader">Role</span>
      <span role="columnheader">Aksi</span>
    </div>
    ${users.length
      ? users.map((row) => `
        <div class="table-row" role="row">
          <span>${row.name}</span>
          <span>${row.email}</span>
          <strong>${row.role}</strong>
          ${Number(row.id) === currentUserId || !['manager', 'cashier'].includes(row.role)
            ? '<span>-</span>'
            : `<button class="btn btn-ghost" type="button" data-delete-user="${row.id}" data-user-name="${row.name}">Hapus akun</button>`}
        </div>
      `).join('')
      : '<div class="table-row"><span>Belum ada user.</span></div>'}
  `;
};

const renderSalesReport = (report) => {
  const summary = asObject(report?.summary);
  setText('[data-sales-report="total_amount"]', rupiah.format(summary.total_amount || 0));
  setText('[data-sales-report="tax_amount"]', rupiah.format(summary.tax_amount || 0));
  setText('[data-sales-report="discount_amount"]', rupiah.format(summary.discount_amount || 0));
  setText('[data-sales-report="cost_amount"]', rupiah.format(summary.cost_amount || 0));
  setText('[data-sales-report="gross_profit"]', rupiah.format(summary.gross_profit || 0));
  setText('[data-sales-report="count"]', `${summary.count || 0} transaksi`);

  const table = document.querySelector('[data-sales-report-table]');
  if (!table) return;

  const rows = asArray(report?.rows);
  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">Invoice</span>
      <span role="columnheader">Tanggal</span>
      <span role="columnheader">Customer</span>
      <span role="columnheader">Sumber</span>
      <span role="columnheader">Subtotal</span>
      <span role="columnheader">Diskon</span>
      <span role="columnheader">Pajak</span>
      <span role="columnheader">Total</span>
      <span role="columnheader">Laba</span>
    </div>
    ${rows.length
      ? rows.map((row) => `
        <div class="table-row" role="row">
          <span>${row.invoice_no}</span>
          <span>${row.date || '-'}</span>
          <span>${row.customer || 'Pelanggan umum'}</span>
          <span>${row.source || 'akurata'}</span>
          <strong>${rupiah.format((row.subtotal_amount || 0) + (row.discount_amount || 0))}</strong>
          <strong>${rupiah.format(row.discount_amount || 0)}</strong>
          <strong>${rupiah.format(row.tax_amount || 0)}</strong>
          <strong>${rupiah.format(row.total_amount)}</strong>
          <strong>${rupiah.format(row.gross_profit)}</strong>
        </div>
      `).join('')
      : '<div class="table-row"><span>Belum ada data penjualan.</span></div>'}
  `;
};

const renderPurchaseReport = (report) => {
  const summary = asObject(report?.summary);
  setText('[data-purchase-report="total_amount"]', rupiah.format(summary.total_amount || 0));
  setText('[data-purchase-report="additional_cost"]', rupiah.format(summary.additional_cost || 0));
  setText('[data-purchase-report="received_count"]', `${summary.received_count || 0} PO`);
  setText('[data-purchase-report="count"]', `${summary.count || 0} purchase order`);

  const table = document.querySelector('[data-purchase-report-table]');
  if (!table) return;

  const rows = asArray(report?.rows);
  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">PO</span>
      <span role="columnheader">Tanggal</span>
      <span role="columnheader">Supplier</span>
      <span role="columnheader">Status</span>
      <span role="columnheader">Total</span>
      <span role="columnheader">Biaya lain</span>
    </div>
    ${rows.length
      ? rows.map((row) => `
        <div class="table-row" role="row">
          <span>${row.invoice_no || `PO-${row.id}`}</span>
          <span>${row.date || '-'}</span>
          <span>${row.supplier || 'Supplier belum diisi'}</span>
          <span class="status ${row.status === 'received' ? 'paid' : 'due'}">${row.status === 'received' ? 'Diterima' : 'Menunggu'}</span>
          <strong>${rupiah.format(row.total_amount)}</strong>
          <strong>${rupiah.format(row.additional_cost)}</strong>
        </div>
      `).join('')
      : '<div class="table-row"><span>Belum ada data pembelian.</span></div>'}
  `;
};

const renderLogs = (payload) => {
  const table = document.querySelector('[data-activity-log-table]');
  if (!table) return;

  const rows = asArray(payload?.logs);
  const pagination = asObject(payload?.pagination, emptyLogPayload.pagination);
  const page = Number(pagination.page || 1);
  const totalPages = Math.max(1, Number(pagination.total_pages || 1));
  const total = Number(pagination.total || 0);

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">Waktu</span>
      <span role="columnheader">User</span>
      <span role="columnheader">Aksi</span>
      <span role="columnheader">Objek</span>
      <span role="columnheader">Keterangan</span>
      <span role="columnheader">IP</span>
    </div>
    ${rows.length
      ? rows.map((row) => `
        <div class="table-row" role="row">
          <span>${row.created_at || '-'}</span>
          <span>${row.user_name || 'Sistem'}</span>
          <span>${row.action || '-'}</span>
          <span>${row.entity_type || '-'}${row.entity_id ? ` #${row.entity_id}` : ''}</span>
          <span>${row.description || '-'}</span>
          <span>${row.ip_address || '-'}</span>
        </div>
      `).join('')
      : '<div class="table-row"><span>Belum ada log aktivitas.</span></div>'}
  `;

  const paginationTarget = document.querySelector('[data-activity-log-pagination]');
  if (!paginationTarget) return;

  paginationTarget.innerHTML = `
    <span class="muted-copy">Halaman ${page} dari ${totalPages} (${total} log)</span>
    <div class="pagination-actions">
      <button class="btn btn-ghost" type="button" data-log-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Sebelumnya</button>
      <button class="btn btn-ghost" type="button" data-log-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Selanjutnya</button>
    </div>
  `;
};

const renderTransactionDetail = (payload) => {
  const transaction = payload?.transaction;
  const items = asArray(payload?.items);
  const title = document.querySelector('[data-transaction-detail-title]');
  const body = document.querySelector('[data-transaction-detail-body]');
  if (!transaction || !body) return;

  if (title) {
    title.textContent = transaction.invoice_no;
  }

  body.innerHTML = `
    <section class="detail-summary">
      <div><span>Pelanggan</span><strong>${transaction.customer || 'Pelanggan umum'}</strong></div>
      <div><span>Kasir</span><strong>${transaction.cashier || '-'}</strong></div>
      <div><span>Tanggal</span><strong>${transaction.sold_at || '-'}</strong></div>
      <div><span>Metode</span><strong>${methodLabel[transaction.payment_method] ?? transaction.payment_method}</strong></div>
      <div><span>Subtotal</span><strong>${rupiah.format(transaction.gross_amount || transaction.subtotal_amount || 0)}</strong></div>
      <div><span>Diskon</span><strong>${rupiah.format(transaction.discount_amount || 0)}</strong></div>
      <div><span>Setelah diskon</span><strong>${rupiah.format(transaction.subtotal_amount || 0)}</strong></div>
      <div><span>Pajak${Number(transaction.tax_rate || 0) > 0 ? ` ${transaction.tax_rate}%` : ''}</span><strong>${rupiah.format(transaction.tax_amount || 0)}</strong></div>
      <div><span>Total</span><strong>${rupiah.format(transaction.total_amount)}</strong></div>
      <div><span>Laba kotor</span><strong>${rupiah.format(transaction.gross_profit || 0)}</strong></div>
    </section>
    <div class="data-table detail-item-table transaction-detail-table" role="table" aria-label="Item transaksi">
      <div class="table-row table-head" role="row">
        <span role="columnheader">Produk</span>
        <span role="columnheader">Qty</span>
        <span role="columnheader">Harga</span>
        <span role="columnheader">Diskon</span>
        <span role="columnheader">Subtotal</span>
      </div>
      ${items.length
        ? items.map((item) => `
          <div class="table-row" role="row">
            <span>${item.name}</span>
            <span>${item.qty}</span>
            <strong>${rupiah.format(item.unit_price)}</strong>
            <strong>${item.discount_rate > 0 ? `${item.discount_rate}% (${rupiah.format(item.discount_amount)})` : '-'}</strong>
            <strong>${rupiah.format(item.subtotal)}</strong>
          </div>
        `).join('')
        : '<div class="table-row"><span>Belum ada item.</span></div>'}
    </div>
  `;
};

const renderPurchaseDetail = (payload) => {
  const purchase = payload?.purchase;
  const items = asArray(payload?.items);
  const title = document.querySelector('[data-purchase-detail-title]');
  const body = document.querySelector('[data-purchase-detail-body]');
  if (!purchase || !body) return;

  if (title) {
    title.textContent = purchase.invoice_no || `PO-${purchase.id}`;
  }

  body.innerHTML = `
    <section class="detail-summary">
      <div><span>Supplier</span><strong>${purchase.supplier || 'Supplier belum diisi'}</strong></div>
      <div><span>Tanggal PO</span><strong>${purchase.purchased_at || '-'}</strong></div>
      <div><span>Diterima</span><strong>${purchase.received_at || '-'}</strong></div>
      <div><span>Status</span><strong>${purchase.status === 'received' ? 'Diterima' : 'Menunggu'}</strong></div>
      <div><span>Total</span><strong>${rupiah.format(purchase.total_amount)}</strong></div>
      <div><span>Biaya lain</span><strong>${rupiah.format(purchase.additional_cost || 0)}</strong></div>
    </section>
    <div class="data-table detail-item-table" role="table" aria-label="Item purchase order">
      <div class="table-row table-head" role="row">
        <span role="columnheader">Produk</span>
        <span role="columnheader">Qty</span>
        <span role="columnheader">Harga beli</span>
        <span role="columnheader">Subtotal</span>
      </div>
      ${items.length
        ? items.map((item) => `
          <div class="table-row" role="row">
            <span>${item.name}</span>
            <span>${item.qty}</span>
            <strong>${rupiah.format(item.unit_cost)}</strong>
            <strong>${rupiah.format(item.subtotal)}</strong>
          </div>
        `).join('')
        : '<div class="table-row"><span>Belum ada item.</span></div>'}
    </div>
  `;
};

const renderReceivableDetail = (payload) => {
  const receivable = payload?.receivable;
  const payments = asArray(payload?.payments);
  const title = document.querySelector('[data-receivable-detail-title]');
  const body = document.querySelector('[data-receivable-detail-body]');
  if (!receivable || !body) return;

  if (title) {
    title.textContent = receivable.invoice_no;
  }

  body.innerHTML = `
    <section class="detail-summary">
      <div><span>Pelanggan</span><strong>${receivable.customer || 'Pelanggan tempo'}</strong></div>
      <div><span>Jatuh tempo</span><strong>${receivable.due_date || '-'}</strong></div>
      <div><span>Status</span><strong>${receivableStatusLabel[receivable.status] ?? receivable.status}</strong></div>
      <div><span>Total tagihan</span><strong>${rupiah.format(receivable.amount)}</strong></div>
      <div><span>Terbayar</span><strong>${rupiah.format(receivable.paid_amount || 0)}</strong></div>
      <div><span>Sisa</span><strong>${rupiah.format(receivable.remaining_amount || 0)}</strong></div>
    </section>
    <form class="dashboard-form compact-form" data-receivable-terms-form>
      <input type="hidden" name="id" value="${receivable.id}" />
      <label>
        <span>Edit jatuh tempo</span>
        <input type="date" name="due_date" value="${receivable.due_date || ''}" />
      </label>
      <button class="btn btn-secondary" type="submit">Simpan tempo</button>
    </form>
    <div class="data-table payment-history-table" role="table" aria-label="Riwayat pembayaran piutang">
      <div class="table-row table-head" role="row">
        <span role="columnheader">Nomor</span>
        <span role="columnheader">Tanggal</span>
        <span role="columnheader">Metode</span>
        <span role="columnheader">Kasir</span>
        <span role="columnheader">Nominal</span>
        <span role="columnheader">Dokumen</span>
      </div>
      ${payments.length
        ? payments.map((payment) => `
          <div class="table-row" role="row">
            <span>${payment.payment_no}</span>
            <span>${payment.paid_at || '-'}</span>
            <span>${methodLabel[payment.payment_method] ?? payment.payment_method}</span>
            <span>${payment.cashier || '-'}</span>
            <strong>${rupiah.format(payment.amount)}</strong>
            <a class="doc-link" href="${API_BASE}/document.php?type=payment&id=${payment.id}" target="_blank" rel="noopener">Preview</a>
          </div>
        `).join('')
        : '<div class="table-row"><span>Belum ada pembayaran.</span></div>'}
    </div>
  `;
};

const renderLoyverseHeaderActions = (status) => {
  const actions = document.querySelector('[data-loyverse-header-actions]');
  if (!actions) return;

  if (status?.connected) {
    actions.innerHTML = `
      <button class="btn btn-secondary" type="button" data-loyverse-push-products>Push semua produk</button>
      <button class="btn btn-ghost" type="button" data-loyverse-push-changed>Push perubahan</button>
      <button class="btn btn-ghost" type="button" data-loyverse-import-sales>Tarik penjualan</button>
      <button class="btn btn-ghost" type="button" data-loyverse-refresh-taxes>Repair pajak lama</button>
    `;
    ensureGlobalSearch();
    return;
  }

  actions.innerHTML = `
    <a class="btn btn-secondary" href="${status?.connect_url || `${API_BASE}/loyverse.php?action=connect`}">Connect Loyverse</a>
  `;
  ensureGlobalSearch();
};

const renderLoyverseStatus = (status) => {
  const panel = document.querySelector('[data-loyverse-status]');
  renderLoyverseHeaderActions(status);
  if (!panel) return;

  const chip = document.querySelector('[data-loyverse-chip]');
  const integration = status?.integration ?? null;
  const stores = asArray(status?.stores);
  const selectedStoreId = String(status?.selected_store?.id || '');

  if (chip) {
    chip.textContent = status?.connected ? 'Terhubung' : 'Belum terhubung';
  }

  if (status?.error) {
    showToast(`Status Loyverse gagal dibaca: ${status.error}`);
    panel.innerHTML = `
      <p class="muted-copy">Pastikan file API terbaru sudah terupload dan migration Loyverse sync sudah dijalankan.</p>
    `;
    return;
  }

  if (!status?.configured) {
    showToast('Client ID dan Client Secret Loyverse belum diset di server.');
    panel.innerHTML = `
      <p class="muted-copy">Set env <strong>LOYVERSE_CLIENT_ID</strong>, <strong>LOYVERSE_CLIENT_SECRET</strong>, dan redirect URI di Loyverse Developer.</p>
    `;
    return;
  }

  if (!status.connected) {
    panel.innerHTML = `
      <p class="notice">Hubungkan outlet ini ke akun Loyverse. User akan diarahkan ke halaman login dan authorize Loyverse.</p>
      <a class="btn btn-secondary" href="${status.connect_url || `${API_BASE}/loyverse.php?action=connect`}">Connect Loyverse</a>
    `;
    return;
  }

  if (status.sync_error) {
    showToast(`Auto tarik penjualan gagal: ${status.sync_error}`);
  }

  panel.innerHTML = `
    <div class="integration-detail">
      <div>
        <span>Akun</span>
        <strong>${integration?.merchant_name || 'Loyverse merchant'}</strong>
      </div>
      <div>
        <span>Email</span>
        <strong>${integration?.merchant_email || '-'}</strong>
      </div>
      <div>
        <span>Token berlaku sampai</span>
        <strong>${integration?.expires_at || '-'}</strong>
      </div>
      <div>
        <span>Sync produk</span>
        <strong>${integration?.last_product_sync_at || '-'}</strong>
      </div>
      <div>
        <span>Sync penjualan</span>
        <strong>${integration?.last_receipt_sync_at || '-'}</strong>
      </div>
    </div>
    <div class="loyverse-store-section">
      <div class="loyverse-store-heading">
        <div>
          <span class="label">POS terdaftar</span>
          <h3>Pilih POS untuk sinkronisasi</h3>
        </div>
        <a class="btn btn-ghost" href="https://r.loyverse.com/dashboard/" target="_blank" rel="noopener">
          Tambah atau ubah di Loyverse
        </a>
      </div>
      ${!status.store_mapping_ready
        ? '<p class="notice">Jalankan migration <strong>2026_06_12_loyverse_store_mapping.sql</strong> agar POS aktif dapat disimpan.</p>'
        : ''}
      ${status.stores_error
        ? `<p class="notice danger">Daftar POS gagal dibaca: ${escapeMarkup(status.stores_error)}</p>`
        : ''}
      <div class="loyverse-store-list">
        ${stores.length
          ? stores.map((store) => {
            const isSelected = String(store.id) === selectedStoreId;
            return `
              <div class="loyverse-store-row">
                <div>
                  <strong>${escapeMarkup(store.name || 'POS tanpa nama')}</strong>
                  <span>${escapeMarkup(store.address || (store.active === false ? 'POS nonaktif' : 'POS Loyverse'))}</span>
                </div>
                ${isSelected
                  ? '<span class="status paid">POS aktif</span>'
                  : `<button
                      class="table-action"
                      type="button"
                      data-loyverse-select-store="${escapeMarkup(store.id)}"
                      ${status.store_mapping_ready ? '' : 'disabled'}
                    >Pilih</button>`}
              </div>
            `;
          }).join('')
          : (!status.stores_error ? '<p class="notice">Belum ada POS yang terbaca dari akun Loyverse.</p>' : '')}
      </div>
      <p class="muted-copy">Nama POS dan POS baru dikelola di Loyverse Back Office. Akurata menyimpan POS yang dipilih untuk tujuan stok, produk, pajak, dan transaksi.</p>
    </div>
    <div class="quick-actions">
      <button class="btn btn-secondary" type="button" data-loyverse-push-products>Push semua produk ke Loyverse</button>
      <button class="btn btn-ghost" type="button" data-loyverse-push-changed>Push perubahan saja</button>
      <button class="btn btn-ghost" type="button" data-loyverse-import-sales>Tarik penjualan Loyverse</button>
      <button class="btn btn-ghost" type="button" data-loyverse-refresh-taxes>Repair pajak lama</button>
      <button class="btn btn-ghost" type="button" data-loyverse-disconnect>Putus integrasi</button>
    </div>
  `;
};

const renderQuotations = (rows) => {
  rows = asArray(rows);
  const table = document.querySelector('[data-quotation-table]');
  if (!table) return;

  table.innerHTML = `
    <div class="table-row table-head" role="row">
      <span role="columnheader">Nomor</span>
      <span role="columnheader">Pelanggan</span>
      <span role="columnheader">Berlaku</span>
      <span role="columnheader">Total</span>
      <span role="columnheader">Status</span>
      <span role="columnheader">Dokumen</span>
    </div>
    ${rows.length
      ? rows
          .map((quote) => `
            <div class="table-row" role="row">
              <span>${quote.quote_no}</span>
              <span>${quote.customer}</span>
              <span>${quote.valid_until || '-'}</span>
              <strong>${rupiah.format(quote.total_amount)}</strong>
              <span class="status paid">${quote.status === 'sent' ? 'Terkirim' : quote.status}</span>
              <a class="doc-link" href="${API_BASE}/document.php?type=quotation&id=${quote.id}" target="_blank" rel="noopener">Preview</a>
            </div>
          `)
          .join('')
      : '<div class="table-row"><span>Belum ada quotation.</span></div>'}
  `;
};

const showNotice = (form, message, type = 'success') => {
  if (type === 'error') {
    showToast(message, 'error');
    return;
  }
  form.querySelector('.notice')?.remove();
  form.insertAdjacentHTML('afterbegin', `<p class="notice ${type}">${message}</p>`);
};

const formPayload = (form) => Object.fromEntries(new FormData(form).entries());

const openModal = (name) => {
  const modal = document.querySelector(`[data-modal="${name}"]`);
  if (!modal) return;

  modal.classList.remove('is-hidden');
  document.body.classList.add('has-modal-open');
  window.setTimeout(() => {
    modal.querySelector('select, input:not([type="hidden"]), textarea, button')?.focus();
  }, 0);
};

const closeModal = (name) => {
  const modal = name
    ? document.querySelector(`[data-modal="${name}"]`)
    : document.querySelector('.app-modal:not(.is-hidden)');

  if (!modal) return;

  if (modal.dataset.modal === 'transaction') {
    void stopBarcodeCamera(true);
  }
  if (modal.dataset.modal === 'product-barcode-camera') {
    void stopProductBarcodeCamera(false);
  }
  modal.classList.add('is-hidden');
  if (!document.querySelector('.app-modal:not(.is-hidden)')) {
    document.body.classList.remove('has-modal-open');
  }
};

const currentPage = () => {
  const hashPage = window.location.hash.slice(1);
  const bodyPage = document.body.dataset.page || 'dashboard';

  if (viewTitles[hashPage]) {
    return hashPage;
  }

  return viewTitles[bodyPage] ? bodyPage : 'dashboard';
};

const renderPageContent = () => {
  const container = document.querySelector('[data-page-content]');
  if (!container) return;

  const page = currentPage();
  container.innerHTML = pageTemplates[page] || pageTemplates.dashboard;

  if (window.location.hash === '#transaksi-baru') {
    window.requestAnimationFrame(() => openModal('transaction'));
    return;
  }

  if (window.location.hash === '#produk-baru') {
    window.requestAnimationFrame(() => openModal('product'));
    return;
  }

  if (window.location.hash === '#pembelian-baru') {
    window.requestAnimationFrame(() => openModal('purchase'));
    return;
  }

  if (window.location.hash === '#quotation-baru') {
    window.requestAnimationFrame(() => openModal('quotation'));
    return;
  }

  if (window.location.hash) {
    window.requestAnimationFrame(() => {
      document.querySelector(window.location.hash)?.scrollIntoView({ block: 'start' });
    });
  }
};

const loadDashboard = async () => {
  const page = currentPage();
  let loyverse = await getLoyverseStatus();

  if (loyverse.connected && page === 'sales') {
    try {
      await importLoyverseSales();
      loyverse = await getLoyverseStatus();
    } catch (error) {
      console.warn('Auto tarik penjualan Loyverse gagal:', error.message);
      loyverse = { ...loyverse, sync_error: error.message };
    }
  }

  const [dashboard, products, transactions, purchases, receivables, quotations, settings, salesReport, purchaseReport, activityLogs, teamUsers] = await Promise.all([
    getDashboard(),
    getProducts(),
    getTransactions(transactionPage, transactionPageSize),
    getPurchases(purchasePage, purchasePageSize),
    getReceivables(),
    getQuotations(),
    getSettings(),
    getReport('sales', salesReportFilter),
    getReport('purchases', purchaseReportFilter),
    page === 'logs' ? getActivityLogs(activityLogPage) : Promise.resolve(emptyLogPayload),
    getTeamUsers(),
  ]);
  if (retryTimer) {
    window.clearTimeout(retryTimer);
    retryTimer = null;
  }

  isDashboardReady = true;
  setLoadingState(false);
  const dashboardData = { ...emptyDashboard, ...asObject(dashboard, emptyDashboard) };
  setText('[data-outlet-name]', dashboardData.tenant?.outlet_name || 'Outlet');
  renderSummary(dashboardData.summary);
  renderHourly(dashboardData.hourly_sales);
  renderPayments(dashboardData.payments);
  renderTransactions(currentPage() === 'dashboard' ? dashboardData.recent_transactions : transactions.transactions, currentPage() === 'dashboard' ? null : transactions.pagination);
  renderLowStock(dashboardData.low_stock_products);
  renderReceivables(currentPage() === 'dashboard' ? dashboardData.receivables : receivables);
  renderProducts(products);
  renderPurchases(currentPage() === 'dashboard' ? [] : purchases.purchases, currentPage() === 'dashboard' ? null : purchases.pagination);
  renderQuotations(quotations);
  renderSettings(settings);
  renderTeamUsers(teamUsers);
  if (currentUserRole === 'manager' && restrictedManagerPages.has(currentPage())) {
    renderAccessDenied();
    return;
  }
  if (currentPage() === 'quotations' && !businessFeatureConfig.quotationEnabled) {
    renderFeatureDisabled('Quotation');
    return;
  }
  renderSalesReport(salesReport);
  renderPurchaseReport(purchaseReport);
  renderLogs(activityLogs);
  renderLoyverseStatus(loyverse);
  enhanceMobileTables();
  ensureGlobalSearch();
  applyGlobalSearch(document.querySelector('[data-global-search]')?.value || '');
};

const loadDashboardUntilConnected = async () => {
  try {
    setLoadingState(!isDashboardReady);
    await loadDashboard();
  } catch (error) {
    if (error.status === 401) {
      window.location.href = '../login.html';
      return;
    }

    console.warn('Menunggu database:', error.message);
    setLoadingState(true, `Menunggu database tersambung (${error.message})`);
    retryTimer = window.setTimeout(loadDashboardUntilConnected, 3000);
  }
};

const setView = (view) => {
  const nextView = viewTitles[view] ? view : 'dashboard';

  for (const link of document.querySelectorAll('[data-nav]')) {
    link.classList.toggle('is-active', link.dataset.nav === nextView);
  }

  for (const group of document.querySelectorAll('.side-dropdown-group')) {
    if (group.querySelector(`[data-nav="${nextView}"]:not([hidden])`)) {
      setNavDropdownState(group, true);
    }
  }

  for (const link of document.querySelectorAll('[data-view-link]')) {
    link.classList.toggle('is-active', link.dataset.viewLink === nextView);
  }

  for (const section of document.querySelectorAll('[data-view-section]')) {
    const allowedViews = section.dataset.viewSection.split(/\s+/);
    section.classList.toggle('is-hidden', !allowedViews.includes(nextView));
  }

  setText('[data-page-title]', viewTitles[nextView]);
};

initializeNavDropdowns();
initializeMobileNavigation();
renderPageContent();
observeResponsiveTables();
ensureGlobalSearch();

document.querySelector('[data-current-date]')?.replaceChildren(
  new Intl.DateTimeFormat('id-ID', {
    weekday: 'long',
    day: 'numeric',
    month: 'long',
    year: 'numeric',
  }).format(new Date())
);

for (const link of document.querySelectorAll('[data-view-link]')) {
  link.addEventListener('click', (event) => {
    event.preventDefault();
    setView(event.currentTarget.dataset.viewLink);
  });
}

for (const link of document.querySelectorAll('[data-open-view]')) {
  link.addEventListener('click', () => {
    window.location.href = pageLinks[link.dataset.openView] || 'index.html';
  });
}

document.addEventListener('input', (event) => {
  if (event.target.matches('[data-global-search]')) {
    applyGlobalSearch(event.target.value);
  }
  if (event.target.closest('[data-purchase-form]')) {
    updatePurchaseSummary(event.target.closest('[data-purchase-form]'));
  }
});

document.addEventListener('input', (event) => {
  if (event.target.closest('[data-transaction-form]')) {
    updateTransactionSummary(event.target.closest('[data-transaction-form]'));
  }
  if (event.target.closest('[data-purchase-form]')) {
    updatePurchaseSummary(event.target.closest('[data-purchase-form]'));
  }
});

document.addEventListener('change', async (event) => {
  if (event.target.matches('[data-product-type-filter]')) {
    productTypeFilter = event.target.value;
    productTablePage = 1;
    renderProductTable();
    return;
  }
  if (event.target.matches('[data-product-stock-filter]')) {
    productStockFilter = event.target.value;
    productTablePage = 1;
    renderProductTable();
    return;
  }
  if (event.target.matches('[data-product-loyverse-filter]')) {
    productLoyverseFilter = event.target.value;
    productTablePage = 1;
    renderProductTable();
    return;
  }
  if (event.target.matches('[data-product-page-size]')) {
    productTablePageSize = Math.min(100, Math.max(10, Number(event.target.value) || 10));
    productTablePage = 1;
    renderProductTable();
    return;
  }
  if (event.target.matches('[data-transaction-page-size]')) {
    transactionPageSize = Math.min(100, Math.max(25, Number(event.target.value) || 50));
    transactionPage = 1;
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-purchase-page-size]')) {
    purchasePageSize = Math.min(100, Math.max(25, Number(event.target.value) || 50));
    purchasePage = 1;
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-product-action]')) {
    const select = event.target;
    const action = select.value;
    const product = productCache.find((row) => Number(row.id) === Number(select.dataset.productId));
    select.value = '';

    if (!action || !product) return;
    if (action === 'edit') {
      prepareProductEditForm(product);
      return;
    }
    if (action === 'package') {
      prepareQuickPackageForm(product);
      return;
    }
    if (action === 'delete') {
      if (!window.confirm(`Apakah Anda yakin ingin menghapus produk ${product.name}? Produk akan disembunyikan tapi riwayat transaksi tidak terpengaruh.`)) return;
      
      select.disabled = true;
      request('products.php', {
        method: 'DELETE',
        body: JSON.stringify({ id: product.id }),
      }).then(() => {
        showToast(`Produk ${product.name} dihapus.`);
        loadDashboard();
      }).catch((err) => {
        showToast(err.message);
        select.disabled = false;
      });
      return;
    }
    if (action === 'barcode') {
      const form = document.querySelector('[data-product-barcode-form]');
      if (form) {
        form.reset();
        form.querySelector('[name="id"]').value = String(product.id);
        form.querySelector('[name="product_name"]').value = product.name;
        form.querySelector('[name="barcode"]').value = product.barcode || '';
        openModal('product-barcode');
      }
      return;
    }
    if (action === 'loyverse') {
      select.disabled = true;
      try {
        const payload = await request('loyverse.php?action=push_product', {
          method: 'POST',
          body: JSON.stringify({ id: Number(product.id) }),
        });
        showToast(payload.message || 'Produk berhasil dipush ke Loyverse.', 'success');
        await loadDashboard();
      } catch (error) {
        showToast(error.message);
        select.disabled = false;
      }
      return;
    }
  }
  if (event.target.closest('[data-transaction-form]')) {
    updateTransactionSummary(event.target.closest('[data-transaction-form]'));
  }
  if (event.target.matches('[data-product-kind]')) {
    updateProductKindFields(event.target.closest('[data-product-form]'));
  }
});

document.addEventListener('submit', async (event) => {
  const form = event.target.closest('[data-transaction-form]');
  if (!form) return;

  event.preventDefault();
  if (!isDashboardReady) return;
  const data = formPayload(form);
  const items = transactionFormItems(form);

  if (items.length === 0) {
    showNotice(form, 'Minimal satu produk wajib dipilih.', 'error');
    return;
  }

  try {
    const payload = await request('transactions.php', {
      method: 'POST',
      body: JSON.stringify({
        payment_method: data.payment_method,
        customer_name: data.customer_name,
        customer_phone: data.customer_phone,
        due_date: data.due_date,
        down_payment: Number(data.down_payment || 0),
        down_payment_method: data.down_payment_method,
        items,
      }),
    });
    const receiptWarning = payload.loyverse_receipt_warning ? ` Receipt Loyverse belum dibuat: ${payload.loyverse_receipt_warning}` : '';
    const stockWarning = payload.loyverse_stock_warning ? ` Stok Loyverse belum sync: ${payload.loyverse_stock_warning}` : '';
    const receiptInfo = payload.loyverse_receipt_number ? ` Receipt Loyverse ${payload.loyverse_receipt_number}.` : '';
    const warning = `${receiptInfo}${receiptWarning}${stockWarning}`;
    showNotice(form, `${payload.invoice_no} tersimpan: ${rupiah.format(payload.total_amount)}.${warning}`);
    form.reset();
    setBarcodeFeedback(form);
    const itemRows = [...form.querySelectorAll('[data-transaction-item]')];
    for (const row of itemRows.slice(1)) {
      row.remove();
    }
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-product-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);
  const productId = Number(data.id || 0);
  const isComposite = data.is_composite === '1';
  const components = isComposite ? productCompositeItems(form) : [];

  if (isComposite && components.length === 0) {
    showNotice(form, 'Produk composite wajib memiliki minimal satu komponen.', 'error');
    return;
  }

  try {
    const payload = await request('products.php', {
      method: productId > 0 ? 'PATCH' : 'POST',
      body: JSON.stringify({
        ...data,
        action: productId > 0 ? 'update' : 'create',
        id: productId || undefined,
        stock_qty: isComposite ? 0 : Number(data.stock_qty),
        min_stock: isComposite ? 0 : Number(data.min_stock),
        cost_price: isComposite ? 0 : Number(data.cost_price),
        sale_price: Number(data.sale_price),
        components,
      }),
    });
    showToast(
      payload.message || (productId > 0 ? 'Produk berhasil diperbarui.' : 'Produk berhasil ditambahkan.'),
      'success',
    );
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    resetProductForm(form);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-product-import-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const submitButton = form.querySelector('button[type="submit"]');
  const previousText = submitButton?.textContent || 'Import produk';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Mengimpor...';
  }

  try {
    const payload = await submitFormData('product-import.php', new FormData(form));
    showToast(payload.message || 'Produk berhasil diimpor.', 'success');
    form.reset();
    closeModal('product-import');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = previousText;
    }
  }
});

document.querySelector('[data-product-edit-import-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const submitButton = form.querySelector('button[type="submit"]');
  const previousText = submitButton?.textContent || 'Update produk';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Memperbarui...';
  }

  try {
    const payload = await submitFormData('product-edit-import.php', new FormData(form));
    showToast(payload.message || 'Produk berhasil diperbarui.', 'success');
    form.reset();
    closeModal('product-edit-import');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = previousText;
    }
  }
});

document.querySelector('[data-sales-import-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const submitButton = form.querySelector('button[type="submit"]');
  const previousText = submitButton?.textContent || 'Import penjualan';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Mengimpor...';
  }

  try {
    const payload = await submitFormData('sales-import.php', new FormData(form));
    showToast(payload.message || 'Penjualan sebelumnya berhasil diimpor.', 'success');
    form.reset();
    closeModal('sales-import');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = previousText;
    }
  }
});

document.querySelector('[data-purchase-import-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const submitButton = form.querySelector('button[type="submit"]');
  const previousText = submitButton?.textContent || 'Import PO';
  if (submitButton) {
    submitButton.disabled = true;
    submitButton.textContent = 'Mengimpor...';
  }

  try {
    const payload = await submitFormData('purchase-import.php', new FormData(form));
    showToast(payload.message || 'Purchase order berhasil diimpor.', 'success');
    form.reset();
    closeModal('purchase-import');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  } finally {
    if (submitButton) {
      submitButton.disabled = false;
      submitButton.textContent = previousText;
    }
  }
});

document.querySelector('[data-product-barcode-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);

  try {
    const payload = await request('products.php', {
      method: 'PATCH',
      body: JSON.stringify({
        id: Number(data.id),
        barcode: data.barcode,
      }),
    });
    showNotice(form, payload.message);
    closeModal('product-barcode');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-quotation-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);

  try {
    const payload = await request('quotations.php', {
      method: 'POST',
      body: JSON.stringify({
        product_id: Number(data.product_id),
        qty: Number(data.qty),
        customer_name: data.customer_name,
        customer_phone: data.customer_phone,
        valid_until: data.valid_until,
        notes: data.notes,
      }),
    });
    showNotice(form, `${payload.quote_no} tersimpan: ${rupiah.format(payload.total_amount)}`);
    form.reset();
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-purchase-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);
  const items = purchaseFormItems(form);
  if (!items.length) {
    showNotice(form, 'Minimal satu produk wajib diisi pada purchase order.', 'error');
    return;
  }

  try {
    const payload = await request('purchases.php', {
      method: 'POST',
      body: JSON.stringify({
        invoice_no: data.invoice_no,
        supplier_name: data.supplier_name,
        items,
      }),
    });
    showNotice(form, `${payload.invoice_no || 'Purchase order'} berhasil disimpan.`);
    form.reset();
    for (const row of [...form.querySelectorAll('[data-purchase-item]')].slice(1)) {
      row.remove();
    }
    updatePurchaseSummary(form);
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-profile-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = new FormData(form);
  data.set('action', 'profile');

  try {
    const payload = await submitFormData('settings.php', data);
    showNotice(form, payload.message || 'Profil berhasil disimpan.');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-password-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = new FormData(form);
  data.set('action', 'password');

  try {
    const payload = await submitFormData('settings.php', data);
    showNotice(form, payload.message || 'Password berhasil diubah.');
    form.reset();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-business-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = new FormData(form);
  data.set('action', 'business');

  try {
    const payload = await submitFormData('settings.php', data);
    const warning = payload.loyverse_warning ? ` ${payload.loyverse_warning}` : '';
    showNotice(form, `${payload.message || 'Profil bisnis berhasil disimpan.'}${warning}`, payload.loyverse_warning ? 'error' : 'success');
    form.querySelector('[name="logo"]').value = '';
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.addEventListener('submit', async (event) => {
  const form = event.target.closest('[data-team-form]');
  if (!form) return;

  event.preventDefault();
  if (!isDashboardReady) return;
  const data = formPayload(form);

  try {
    const payload = await request('users.php', {
      method: 'POST',
      body: JSON.stringify({
        name: data.name,
        email: data.email,
        password: data.password,
        role: data.role,
      }),
    });
    showNotice(form, payload.message || 'User berhasil dibuat.');
    form.reset();
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.addEventListener('click', async (event) => {
  const button = event.target.closest('[data-delete-user]');
  if (!button) return;

  const name = button.dataset.userName || 'user ini';
  if (!window.confirm(`Hapus akun ${name}? User tidak bisa login lagi, tapi histori tetap disimpan.`)) {
    return;
  }

  button.disabled = true;
  const form = document.querySelector('[data-team-form]');
  try {
    const payload = await request('users.php', {
      method: 'DELETE',
      body: JSON.stringify({
        id: Number(button.dataset.deleteUser),
      }),
    });
    await loadDashboard();
    const refreshedForm = document.querySelector('[data-team-form]');
    if (refreshedForm) showNotice(refreshedForm, payload.message || 'Akun user berhasil dihapus.');
  } catch (error) {
    if (form) showNotice(form, error.message, 'error');
    button.disabled = false;
  }
});

document.querySelector('[data-business-form] [name="logo"]')?.addEventListener('change', (event) => {
  const file = event.target.files?.[0];
  const image = document.querySelector('[data-business-logo]');
  const placeholder = document.querySelector('[data-business-logo-placeholder]');

  if (!file || !image) return;

  image.src = URL.createObjectURL(file);
  image.hidden = false;
  if (placeholder) {
    placeholder.hidden = true;
  }
});

document.querySelector('[data-receive-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);

  try {
    const payload = await request('purchases.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'receive',
        id: Number(data.id),
        additional_cost: Number(data.additional_cost),
      }),
    });
    showNotice(form, payload.message || 'Barang berhasil diterima.');
    form.reset();
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.querySelector('[data-receivable-payment-form]')?.addEventListener('submit', async (event) => {
  event.preventDefault();
  if (!isDashboardReady) return;
  const form = event.currentTarget;
  const data = formPayload(form);

  try {
    const payload = await request('receivables.php', {
      method: 'POST',
      body: JSON.stringify({
        id: Number(data.id),
        amount: Number(data.amount),
        payment_method: data.payment_method,
        notes: data.notes,
      }),
    });
    showNotice(form, `${payload.payment_no} tersimpan. Sisa ${rupiah.format(payload.remaining_amount || 0)}.`);
    form.reset();
    closeModal(form.closest('[data-modal]')?.dataset.modal);
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.addEventListener('submit', async (event) => {
  const form = event.target.closest('[data-receivable-terms-form]');
  if (!form) return;

  event.preventDefault();
  if (!isDashboardReady) return;
  const data = formPayload(form);

  try {
    const payload = await request('receivables.php', {
      method: 'POST',
      body: JSON.stringify({
        action: 'terms',
        id: Number(data.id),
        due_date: data.due_date,
      }),
    });
    showNotice(form, payload.message || 'Jatuh tempo berhasil disimpan.');
    closeModal('receivable-detail');
    await loadDashboard();
  } catch (error) {
    showNotice(form, error.message, 'error');
  }
});

document.addEventListener('change', async (event) => {
  if (event.target.matches('[data-sales-filter-start]')) {
    salesReportFilter.start_date = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-sales-filter-end]')) {
    salesReportFilter.end_date = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-sales-filter-method]')) {
    salesReportFilter.payment_method = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-sales-filter-source]')) {
    salesReportFilter.source = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-purchase-filter-start]')) {
    purchaseReportFilter.start_date = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-purchase-filter-end]')) {
    purchaseReportFilter.end_date = event.target.value || '';
    loadDashboard();
    return;
  }
  if (event.target.matches('[data-purchase-filter-status]')) {
    purchaseReportFilter.status = event.target.value || '';
    loadDashboard();
    return;
  }
});

document.addEventListener('click', async (event) => {
  const salesResetButton = event.target.closest('[data-sales-report-reset]');
  if (salesResetButton) {
    salesReportFilter = { start_date: '', end_date: '', payment_method: '', source: '' };
    const startEl = document.querySelector('[data-sales-filter-start]');
    const endEl = document.querySelector('[data-sales-filter-end]');
    const methodEl = document.querySelector('[data-sales-filter-method]');
    const sourceEl = document.querySelector('[data-sales-filter-source]');
    if (startEl) startEl.value = '';
    if (endEl) endEl.value = '';
    if (methodEl) methodEl.value = '';
    if (sourceEl) sourceEl.value = '';
    loadDashboard();
    return;
  }

  const salesPdfButton = event.target.closest('[data-sales-report-pdf]');
  if (salesPdfButton) {
    const params = new URLSearchParams();
    Object.entries(salesReportFilter).forEach(([key, value]) => {
      if (value !== '') params.append(key, value);
    });
    const qs = params.toString();
    const url = qs ? `../api/sales-pdf.php?${qs}` : '../api/sales-pdf.php';
    window.open(url, '_blank');
    return;
  }

  const purchaseResetButton = event.target.closest('[data-purchase-report-reset]');
  if (purchaseResetButton) {
    purchaseReportFilter = { start_date: '', end_date: '', status: '' };
    const startEl = document.querySelector('[data-purchase-filter-start]');
    const endEl = document.querySelector('[data-purchase-filter-end]');
    const statusEl = document.querySelector('[data-purchase-filter-status]');
    if (startEl) startEl.value = '';
    if (endEl) endEl.value = '';
    if (statusEl) statusEl.value = '';
    loadDashboard();
    return;
  }

  const purchasePdfButton = event.target.closest('[data-purchase-report-pdf]');
  if (purchasePdfButton) {
    const params = new URLSearchParams();
    Object.entries(purchaseReportFilter).forEach(([key, value]) => {
      if (value !== '') params.append(key, value);
    });
    const qs = params.toString();
    const url = qs ? `../api/purchase-pdf.php?${qs}` : '../api/purchase-pdf.php';
    window.open(url, '_blank');
    return;
  }
});

document.addEventListener('click', async (event) => {
  const supportToggle = event.target.closest('[data-support-chat-toggle]');
  if (supportToggle) {
    supportChatOpen = true;
    document.querySelector('[data-support-chat-panel]')?.classList.remove('is-hidden');
    supportToggle.setAttribute('aria-expanded', 'true');
    setSupportChatBadge(0);
    const messages = document.querySelector('[data-support-chat-messages]');
    if (messages) messages.scrollTop = messages.scrollHeight;
    document.querySelector('[data-support-chat-form] textarea')?.focus();
    scheduleSupportChatPoll(0);
    return;
  }

  const historyButton = event.target.closest('[data-support-chat-history]');
  if (historyButton) {
    setSupportChatMode(!supportChatViewingHistory);
    if (!supportChatViewingHistory) {
      supportChatConversationId = 0;
      resetSupportChatMessages();
    }
    scheduleSupportChatPoll(0);
    return;
  }

  const historyItem = event.target.closest('[data-support-chat-history-id]');
  if (historyItem) {
    supportChatConversationId = Number(historyItem.dataset.supportChatHistoryId);
    setSupportChatMode(false);
    supportChatViewingHistory = true;
    document.querySelector('[data-support-chat-form]')?.classList.add('is-hidden');
    document.querySelector('[data-support-chat-end]')?.classList.add('is-hidden');
    resetSupportChatMessages('Memuat riwayat percakapan.');
    scheduleSupportChatPoll(0);
    return;
  }

  const endChatButton = event.target.closest('[data-support-chat-end]');
  if (endChatButton && supportChatConversationId) {
    if (!window.confirm('Akhiri sesi chat ini? Percakapan tetap tersimpan di riwayat.')) return;
    endChatButton.disabled = true;
    try {
      await request('support-chat.php', {
        method: 'PATCH',
        body: JSON.stringify({ conversation_id: supportChatConversationId }),
      });
      supportChatConversationId = 0;
      supportChatViewingHistory = false;
      resetSupportChatMessages('Sesi sebelumnya sudah diakhiri. Kirim pesan untuk membuka sesi baru.');
      setSupportChatState('Sesi berakhir');
      endChatButton.classList.add('is-hidden');
      scheduleSupportChatPoll(0);
    } catch (error) {
      setSupportChatState(error.message);
    } finally {
      endChatButton.disabled = false;
    }
    return;
  }

  if (event.target.closest('[data-support-chat-close]')) {
    supportChatOpen = false;
    document.querySelector('[data-support-chat-panel]')?.classList.add('is-hidden');
    document.querySelector('[data-support-chat-toggle]')?.setAttribute('aria-expanded', 'false');
    scheduleSupportChatPoll(1000);
    return;
  }

  const modalButton = event.target.closest('[data-open-modal]');
  if (modalButton) {
    if (modalButton.dataset.openModal === 'product') {
      resetProductForm();
    }
    openModal(modalButton.dataset.openModal);
    return;
  }

  const closeButton = event.target.closest('[data-close-modal]');
  if (closeButton) {
    closeModal(closeButton.closest('[data-modal]')?.dataset.modal);
    return;
  }

  if (event.target.matches('[data-modal]')) {
    closeModal(event.target.dataset.modal);
    return;
  }

  if (!event.target.closest('.side-dropdown-group')) {
    for (const group of document.querySelectorAll('.side-dropdown-group')) {
      setNavDropdownState(group, false);
    }
  }

  const openBarcodeCameraButton = event.target.closest('[data-open-barcode-camera]');
  if (openBarcodeCameraButton) {
    const form = openBarcodeCameraButton.closest('[data-transaction-form]');
    await startBarcodeCamera(form);
    return;
  }

  const stopBarcodeCameraButton = event.target.closest('[data-stop-barcode-camera]');
  if (stopBarcodeCameraButton) {
    await stopBarcodeCamera(true);
    return;
  }

  const openProductBarcodeCameraButton = event.target.closest('[data-open-product-barcode-camera]');
  if (openProductBarcodeCameraButton) {
    const input = openProductBarcodeCameraButton.closest('.barcode-input-row')?.querySelector('[name="barcode"]');
    await startProductBarcodeCamera(input);
    return;
  }

  const stopProductBarcodeCameraButton = event.target.closest('[data-stop-product-barcode-camera]');
  if (stopProductBarcodeCameraButton) {
    await stopProductBarcodeCamera(true);
    productBarcodeCameraTarget?.focus();
    return;
  }

  const addTransactionItemButton = event.target.closest('[data-add-transaction-item]');
  if (addTransactionItemButton) {
    const list = document.querySelector('[data-transaction-items]');
    if (list) {
      list.insertAdjacentHTML('beforeend', `
        <div class="transaction-item-row" data-transaction-item>
          <label>
            <span>Produk</span>
            <select name="product_id" data-product-select required>${productOptionsHtml(productCache, true, true)}</select>
          </label>
          <label>
            <span>Qty</span>
            <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
          </label>
          <label>
            <span>Diskon %</span>
            <input type="number" name="discount_rate" min="0" max="100" step="0.01" value="0" required />
          </label>
          <button class="icon-button" type="button" data-remove-transaction-item aria-label="Hapus item">Hapus</button>
        </div>
      `);
      const latestRow = list.querySelector('[data-transaction-item]:last-child');
      latestRow?.querySelector('select')?.focus();
      updateTransactionSummary(list.closest('[data-transaction-form]'));
    }
    return;
  }

  const addPurchaseItemButton = event.target.closest('[data-add-purchase-item]');
  if (addPurchaseItemButton) {
    const list = document.querySelector('[data-purchase-items]');
    if (list) {
      list.insertAdjacentHTML('beforeend', `
        <div class="purchase-item-row" data-purchase-item>
          <label>
            <span>Produk</span>
            <select name="product_id" data-product-select required>${productOptionsHtml(productCache, false, false)}</select>
          </label>
          <label>
            <span>Qty</span>
            <input type="number" name="qty" min="0.001" step="0.001" value="1" required />
          </label>
          <label>
            <span>Harga beli satuan</span>
            <input type="number" name="unit_cost" min="0" value="0" required />
          </label>
          <button class="icon-button" type="button" data-remove-purchase-item aria-label="Hapus item">Hapus</button>
        </div>
      `);
      list.querySelector('[data-purchase-item]:last-child select')?.focus();
      updatePurchaseSummary(list.closest('[data-purchase-form]'));
    }
    return;
  }

  const removePurchaseItemButton = event.target.closest('[data-remove-purchase-item]');
  if (removePurchaseItemButton) {
    const list = removePurchaseItemButton.closest('[data-purchase-items]');
    if (list?.querySelectorAll('[data-purchase-item]').length > 1) {
      removePurchaseItemButton.closest('[data-purchase-item]')?.remove();
      updatePurchaseSummary(list.closest('[data-purchase-form]'));
    }
    return;
  }

  const editProductBarcodeButton = event.target.closest('[data-edit-product-barcode]');
  if (editProductBarcodeButton) {
    const product = productCache.find((row) => Number(row.id) === Number(editProductBarcodeButton.dataset.editProductBarcode));
    const form = document.querySelector('[data-product-barcode-form]');
    if (product && form) {
      form.reset();
      form.querySelector('[name="id"]').value = String(product.id);
      form.querySelector('[name="product_name"]').value = product.name;
      form.querySelector('[name="barcode"]').value = product.barcode || '';
      openModal('product-barcode');
    }
    return;
  }

  const editProductButton = event.target.closest('[data-edit-product]');
  if (editProductButton) {
    const product = productCache.find((row) => Number(row.id) === Number(editProductButton.dataset.editProduct));
    if (!product) {
      showToast('Produk tidak ditemukan.');
      return;
    }
    prepareProductEditForm(product);
    return;
  }

  const quickPackageButton = event.target.closest('[data-quick-package]');
  if (quickPackageButton) {
    const product = productCache.find((row) => Number(row.id) === Number(quickPackageButton.dataset.quickPackage));
    if (!product) {
      showToast('Produk dasar tidak ditemukan.');
      return;
    }

    prepareQuickPackageForm(product);
    return;
  }

  const addCompositeItemButton = event.target.closest('[data-add-composite-item]');
  if (addCompositeItemButton) {
    const list = document.querySelector('[data-composite-items]');
    if (list) {
      const editedProductId = Number(
        addCompositeItemButton.closest('[data-product-form]')?.querySelector('[name="id"]')?.value || 0,
      );
      list.insertAdjacentHTML('beforeend', compositeItemRowHtml(null, editedProductId));
      list.querySelector('[data-composite-item]:last-child select')?.focus();
    }
    return;
  }

  const removeCompositeItemButton = event.target.closest('[data-remove-composite-item]');
  if (removeCompositeItemButton) {
    const rows = document.querySelectorAll('[data-composite-item]');
    if (rows.length > 1) {
      removeCompositeItemButton.closest('[data-composite-item]')?.remove();
    }
    return;
  }

  const transactionPageButton = event.target.closest('[data-transaction-page]');
  if (transactionPageButton) {
    const page = Number(transactionPageButton.dataset.transactionPage);
    if (page >= 1 && page <= transactionTotalPages) {
      transactionPage = page;
      loadDashboard();
    }
    return;
  }

  const purchasePageButton = event.target.closest('[data-purchase-page]');
  if (purchasePageButton) {
    const page = Number(purchasePageButton.dataset.purchasePage);
    if (page >= 1 && page <= purchaseTotalPages) {
      purchasePage = page;
      loadDashboard();
    }
    return;
  }

  const logPageButton = event.target.closest('[data-log-page]');
  if (logPageButton) {
    const nextPage = Number(logPageButton.dataset.logPage);
    if (nextPage > 0) {
      activityLogPage = nextPage;
      await loadDashboard();
    }
    return;
  }

  const productPageButton = event.target.closest('[data-product-page]');
  if (productPageButton) {
    const nextPage = Number(productPageButton.dataset.productPage);
    if (nextPage > 0) {
      productTablePage = nextPage;
      renderProductTable();
      document.querySelector('[data-product-table]')?.scrollIntoView({ block: 'start', behavior: 'smooth' });
    }
    return;
  }

  const removeTransactionItemButton = event.target.closest('[data-remove-transaction-item]');
  if (removeTransactionItemButton) {
    const rows = document.querySelectorAll('[data-transaction-item]');
    if (rows.length > 1) {
      removeTransactionItemButton.closest('[data-transaction-item]')?.remove();
      updateTransactionSummary(document.querySelector('[data-transaction-form]'));
    }
    return;
  }

  const receiveButton = event.target.closest('[data-receive-purchase]');
  if (receiveButton) {
    const form = document.querySelector('[data-receive-form]');
    if (form) {
      const selectEl = form.querySelector('[name="id"]');
      const costEl = form.querySelector('[name="additional_cost"]');

      if (selectEl) selectEl.value = receiveButton.dataset.receivePurchase ?? '';
      if (costEl) costEl.value = 0;

      openModal('purchase-receive');
    }

    return;
  }

  const viewTransactionButton = event.target.closest('[data-view-transaction]');
  if (viewTransactionButton) {
    try {
      const payload = await request(`transactions.php?id=${Number(viewTransactionButton.dataset.viewTransaction)}`);
      renderTransactionDetail(payload);
      openModal('transaction-detail');
    } catch (error) {
      showToast(error.message);
    }
    return;
  }

  const viewPurchaseButton = event.target.closest('[data-view-purchase]');
  if (viewPurchaseButton) {
    try {
      const payload = await request(`purchases.php?id=${Number(viewPurchaseButton.dataset.viewPurchase)}`);
      renderPurchaseDetail(payload);
      openModal('purchase-detail');
    } catch (error) {
      showToast(error.message);
    }
    return;
  }

  const openReceivablePaymentButton = event.target.closest('[data-open-receivable-payment]');
  if (openReceivablePaymentButton) {
    const form = document.querySelector('[data-receivable-payment-form]');
    if (form) {
      const id = Number(openReceivablePaymentButton.dataset.openReceivablePayment);
      const remaining = Number(openReceivablePaymentButton.dataset.receivableRemaining || 0);
      const idInput = form.querySelector('[name="id"]');
      const amountInput = form.querySelector('[name="amount"]');

      if (idInput) idInput.value = String(id);
      if (amountInput) {
        amountInput.value = String(remaining);
        amountInput.max = String(remaining);
      }

      setText('[data-payment-invoice]', decodeURIComponent(openReceivablePaymentButton.dataset.receivableInvoice || '-'));
      setText('[data-payment-customer]', decodeURIComponent(openReceivablePaymentButton.dataset.receivableCustomer || '-'));
      setText('[data-payment-remaining]', rupiah.format(remaining));
      openModal('receivable-payment');
    }
    return;
  }

  const viewReceivableButton = event.target.closest('[data-view-receivable]');
  if (viewReceivableButton) {
    try {
      const payload = await request(`receivables.php?id=${Number(viewReceivableButton.dataset.viewReceivable)}`);
      renderReceivableDetail(payload);
      openModal('receivable-detail');
    } catch (error) {
      showToast(error.message);
    }
    return;
  }

  const disconnectButton = event.target.closest('[data-loyverse-disconnect]');
  if (disconnectButton) {
    try {
      await request('loyverse.php?action=disconnect', { method: 'POST' });
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    }
    return;
  }

  const selectLoyverseStoreButton = event.target.closest('[data-loyverse-select-store]');
  if (selectLoyverseStoreButton) {
    const previousText = selectLoyverseStoreButton.textContent;
    selectLoyverseStoreButton.disabled = true;
    selectLoyverseStoreButton.textContent = 'Menyimpan...';

    try {
      const payload = await request('loyverse.php?action=select_store', {
        method: 'POST',
        body: JSON.stringify({ store_id: selectLoyverseStoreButton.dataset.loyverseSelectStore }),
      });
      showToast(payload.message || 'POS Loyverse aktif berhasil dipilih.', 'success');
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
      selectLoyverseStoreButton.disabled = false;
      selectLoyverseStoreButton.textContent = previousText;
    }
    return;
  }

  const pushProductsButton = event.target.closest('[data-loyverse-push-products]');
  if (pushProductsButton) {
    const previousText = pushProductsButton.textContent;
    pushProductsButton.disabled = true;
    pushProductsButton.textContent = 'Mengirim produk...';

    try {
      const payload = await pushAllLoyverseProducts(pushProductsButton);
      showToast(
        loyverseSyncMessage(payload, 'Produk berhasil dipush ke Loyverse.'),
        payload.failed ? 'error' : 'success',
      );
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    } finally {
      pushProductsButton.disabled = false;
      pushProductsButton.textContent = previousText;
    }
    return;
  }

  const pushChangedButton = event.target.closest('[data-loyverse-push-changed]');
  if (pushChangedButton) {
    const previousText = pushChangedButton.textContent;
    pushChangedButton.disabled = true;
    pushChangedButton.textContent = 'Mengirim perubahan...';

    try {
      const payload = await request('loyverse.php?action=push_changed', { method: 'POST' });
      showToast(
        loyverseSyncMessage(payload, 'Produk berubah berhasil dipush ke Loyverse.'),
        payload.failed ? 'error' : 'success',
      );
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    } finally {
      pushChangedButton.disabled = false;
      pushChangedButton.textContent = previousText;
    }
    return;
  }

  const pushProductButton = event.target.closest('[data-loyverse-push-product]');
  if (pushProductButton) {
    const previousText = pushProductButton.textContent;
    pushProductButton.disabled = true;
    pushProductButton.textContent = 'Mengirim...';

    try {
      const payload = await request('loyverse.php?action=push_product', {
        method: 'POST',
        body: JSON.stringify({ id: Number(pushProductButton.dataset.loyversePushProduct) }),
      });
      showToast(payload.message || 'Produk berhasil dipush ke Loyverse.', 'success');
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    } finally {
      pushProductButton.disabled = false;
      pushProductButton.textContent = previousText;
    }
    return;
  }

  const importSalesButton = event.target.closest('[data-loyverse-import-sales]');
  if (importSalesButton) {
    const previousText = importSalesButton.textContent;
    importSalesButton.disabled = true;
    importSalesButton.textContent = 'Menarik penjualan...';

    try {
      const payload = await request('loyverse.php?action=import_receipts', { method: 'POST' });
      showToast(loyverseSyncMessage(payload, 'Penjualan Loyverse berhasil diimpor.'), 'success');
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    } finally {
      importSalesButton.disabled = false;
      importSalesButton.textContent = previousText;
    }
    return;
  }

  const refreshTaxesButton = event.target.closest('[data-loyverse-refresh-taxes]');
  if (refreshTaxesButton) {
    const previousText = refreshTaxesButton.textContent;
    refreshTaxesButton.disabled = true;
    refreshTaxesButton.textContent = 'Memperbaiki...';

    try {
      const payload = await request('loyverse.php?action=refresh_receipt_taxes', { method: 'POST' });
      showToast(loyverseSyncMessage(payload, 'Pajak transaksi Loyverse lama berhasil diperbaiki.'), 'success');
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    } finally {
      refreshTaxesButton.disabled = false;
      refreshTaxesButton.textContent = previousText;
    }
    return;
  }

  const restockButton = event.target.closest('[data-restock-product]');
  if (restockButton) {
    const select = document.querySelector('[data-purchase-form] [data-product-select]');
    if (select) {
      select.value = restockButton.dataset.restockProduct;
      document.querySelector('[data-purchase-form]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  const payButton = event.target.closest('[data-pay-receivable]');
  if (payButton) {
    try {
      await request('receivables.php', {
        method: 'PATCH',
        body: JSON.stringify({ id: Number(payButton.dataset.payReceivable) }),
      });
      await loadDashboard();
    } catch (error) {
      showToast(error.message);
    }
  }
});

document.addEventListener('submit', async (event) => {
  const form = event.target.closest('[data-support-chat-form]');
  if (!form) return;
  event.preventDefault();

  const textarea = form.querySelector('[name="message"]');
  const submitButton = form.querySelector('button[type="submit"]');
  const message = textarea?.value.trim() || '';
  if (!message) return;

  submitButton.disabled = true;
  try {
    await request('support-chat.php', {
      method: 'POST',
      body: JSON.stringify({ message }),
    });
    textarea.value = '';
    supportChatViewingHistory = false;
    setSupportChatMode(false);
    setSupportChatState('Pesan terkirim');
    scheduleSupportChatPoll(0);
  } catch (error) {
    setSupportChatState(error.message);
  } finally {
    submitButton.disabled = false;
    textarea?.focus();
  }
});

document.addEventListener('keydown', (event) => {
  if (event.target.matches('[data-product-form] [name="barcode"], [data-product-barcode-form] [name="barcode"]') && event.key === 'Enter') {
    event.preventDefault();
    event.target.blur();
    return;
  }

  if (event.target.matches('[data-barcode-scan]') && event.key === 'Enter') {
    event.preventDefault();
    const input = event.target;
    const form = input.closest('[data-transaction-form]');
    const barcode = input.value.trim();
    if (!barcode) return;

    if (!processTransactionBarcode(form, barcode)) {
      input.select();
      return;
    }

    input.value = '';
    input.focus();
    return;
  }

  if (event.key === 'Escape') {
    closeModal();
    closeMobileNavigation();
  }
});

document.addEventListener('change', async (event) => {
  if (event.target.matches('[data-barcode-camera-select]')) {
    const form = event.target.closest('[data-transaction-form]');
    await startBarcodeCamera(form, event.target.value);
    return;
  }

  if (event.target.matches('[data-product-barcode-camera-select]')) {
    await startProductBarcodeCamera(productBarcodeCameraTarget, event.target.value);
  }
});

window.addEventListener('pagehide', () => {
  supportChatEnabled = false;
  window.clearTimeout(supportChatPollTimer);
  void stopBarcodeCamera(true);
  void stopProductBarcodeCamera(false);
});

document.querySelector('[data-export]')?.addEventListener('click', () => {
  const rows = [...document.querySelectorAll('[data-transaction-table] .table-row:not(.table-head)')]
    .map((row) => [...row.children].map((cell) => `"${cell.textContent.trim()}"`).join(','));
  const blob = new Blob([['Invoice,Kasir,Metode,Total,Laba Bersih,Status,Dokumen,Aksi', ...rows].join('\n')], { type: 'text/csv' });
  const url = URL.createObjectURL(blob);
  const link = document.createElement('a');
  link.href = url;
  link.download = 'transaksi-akurata-pos.csv';
  link.click();
  URL.revokeObjectURL(url);
});

document.querySelector('[data-logout]')?.addEventListener('click', async () => {
  try {
    await request('logout.php', { method: 'POST' });
  } finally {
    window.location.href = '../login.html';
  }
});

renderSummary(emptyDashboard.summary);
renderHourly(emptyDashboard.hourly_sales);
renderPayments(emptyDashboard.payments);
renderTransactions(emptyDashboard.recent_transactions);
renderLowStock(emptyDashboard.low_stock_products);
renderReceivables(emptyDashboard.receivables);
renderProducts([]);
renderPurchases([]);
renderQuotations(emptyDashboard.quotations);
loadDashboardUntilConnected();
setView(currentPage());

// Batch deletion logic for transactions and purchases
document.addEventListener('change', (event) => {
  if (event.target.matches('[data-check-all]')) {
    const type = event.target.dataset.checkAll;
    const checkboxes = document.querySelectorAll(`[data-check-item="${type}"]`);
    checkboxes.forEach((cb) => {
      cb.checked = event.target.checked;
    });
    updateBatchDeleteButton(type);
  } else if (event.target.matches('[data-check-item]')) {
    const type = event.target.dataset.checkItem;
    const allCheckbox = document.querySelector(`[data-check-all="${type}"]`);
    if (allCheckbox) {
      const total = document.querySelectorAll(`[data-check-item="${type}"]`).length;
      const checked = document.querySelectorAll(`[data-check-item="${type}"]:checked`).length;
      allCheckbox.checked = total > 0 && total === checked;
      allCheckbox.indeterminate = checked > 0 && checked < total;
    }
    updateBatchDeleteButton(type);
  }
});

function updateBatchDeleteButton(type) {
  const btn = document.querySelector(`[data-batch-delete="${type}"]`);
  if (!btn) return;
  const checked = document.querySelectorAll(`[data-check-item="${type}"]:checked`).length;
  if (checked > 0) {
    btn.style.display = 'inline-flex';
    const span = btn.querySelector('.batch-count');
    if (span) {
      span.textContent = checked;
    } else {
      btn.innerHTML = `Hapus Terpilih (<span class="batch-count">${checked}</span>)`;
    }
  } else {
    btn.style.display = 'none';
  }
}

document.addEventListener('click', async (event) => {
  if (event.target.closest('[data-batch-delete]')) {
    const btn = event.target.closest('[data-batch-delete]');
    const type = btn.dataset.batchDelete;
    const checkedBoxes = document.querySelectorAll(`[data-check-item="${type}"]:checked`);
    const ids = Array.from(checkedBoxes).map((cb) => Number(cb.value)).filter(id => id > 0);
    
    if (ids.length === 0) return;

    if (!confirm(`Apakah Anda yakin ingin menghapus ${ids.length} data ini secara permanen? Stok akan disesuaikan kembali.`)) {
      return;
    }

    try {
      btn.disabled = true;
      btn.innerHTML = 'Menghapus... <span class="batch-count" style="display:none"></span>';
      
      let endpoint = '';
      if (type === 'transactions') endpoint = 'transactions.php';
      else if (type === 'purchases') endpoint = 'purchases.php';
      else if (type === 'products') endpoint = 'products.php';
      
      const response = await fetch(`${API_BASE}/${endpoint}`, {
        method: 'DELETE',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ ids }),
      });

      const data = await response.json();
      if (!response.ok) {
        throw new Error(data.error || 'Terjadi kesalahan.');
      }

      alert(data.message || 'Data berhasil dihapus.');
      
      // Reset button
      const allCheckbox = document.querySelector(`[data-check-all="${type}"]`);
      if (allCheckbox) {
        allCheckbox.checked = false;
        allCheckbox.indeterminate = false;
      }
      document.querySelectorAll(`[data-check-item="${type}"]`).forEach(cb => cb.checked = false);
      updateBatchDeleteButton(type);

      // Reload table
      loadDashboard();
    } catch (err) {
      alert(err.message);
      updateBatchDeleteButton(type);
    } finally {
      btn.disabled = false;
    }
  }
});

