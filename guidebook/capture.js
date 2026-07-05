const screen = new URLSearchParams(window.location.search).get('screen') || 'dashboard';

const navItems = [
  ['dashboard', 'Dashboard'],
  ['section', 'Penjualan'],
  ['sales', 'Penjualan'],
  ['quotations', 'Quotation'],
  ['products', 'Produk & Stok'],
  ['purchases', 'Purchase Order'],
  ['receivables', 'Piutang'],
  ['section', 'Laporan'],
  ['reports', 'Laporan Penjualan'],
  ['section', 'Pengaturan'],
  ['settings', 'Profil Bisnis'],
  ['integrations', 'Integrasi'],
  ['access', 'Akses'],
  ['admin', 'Administrator'],
];

const rupiah = (value) => `Rp ${Number(value).toLocaleString('id-ID')}`;

const table = (headers, rows, columns = `repeat(${headers.length}, 1fr)`) => `
  <div class="capture-table" style="--columns:${columns}">
    <div class="capture-row head">${headers.map((header) => `<span>${header}</span>`).join('')}</div>
    ${rows.map((row) => `<div class="capture-row">${row.map((cell) => `<span>${cell}</span>`).join('')}</div>`).join('')}
  </div>
`;

const templates = {
  dashboard: {
    title: 'Dashboard Operasional',
    actions: '<input class="capture-search" value="Cari transaksi atau produk" readonly />',
    content: `
      <section class="capture-metrics">
        <article class="panel capture-metric"><span>Omzet hari ini</span><strong>${rupiah(6850000)}</strong><small>+12,4% dari kemarin</small></article>
        <article class="panel capture-metric"><span>Transaksi</span><strong>43</strong><small>Rata-rata ${rupiah(159302)}</small></article>
        <article class="panel capture-metric"><span>Margin kotor</span><strong>31,8%</strong><small>Setelah HPP, sebelum pajak</small></article>
        <article class="panel capture-metric"><span>Stok kritis</span><strong>4 SKU</strong><small>Perlu dibuatkan PO</small></article>
      </section>
      <section class="capture-grid">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Penjualan</span><h2>Per jam hari ini</h2></div><span class="capture-status">Live</span></div>
          <div class="capture-chart">
            ${[34, 48, 66, 44, 78, 61, 88, 70].map((height, index) => `<span style="--height:${height}%"><small>${String(index + 9).padStart(2, '0')}.00</small></span>`).join('')}
          </div>
        </article>
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Stok perlu tindakan</span><h2>Restock</h2></div></div>
          <div class="capture-list">
            <article><div><strong>Baterai 9V</strong><br /><span>Sisa 2, minimum 5</span></div><button class="capture-button">PO</button></article>
            <article><div><strong>Kopi Arabika 1 kg</strong><br /><span>Sisa 3, minimum 8</span></div><button class="capture-button">PO</button></article>
            <article><div><strong>Kertas Thermal</strong><br /><span>Sisa 1, minimum 10</span></div><button class="capture-button">PO</button></article>
          </div>
        </article>
      </section>
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Transaksi terbaru</span><h2>Aktivitas kasir</h2></div></div>
          ${table(
            ['Invoice', 'Kasir', 'Metode', 'Total', 'Status', 'Dokumen'],
            [
              ['INV-260612-0043', 'Raka', 'QRIS', `<strong>${rupiah(285000)}</strong>`, '<span class="capture-status">Lunas</span>', 'Preview'],
              ['INV-260612-0042', 'Nadia', 'Tempo', `<strong>${rupiah(1450000)}</strong>`, '<span class="capture-status warning">Piutang</span>', 'Preview'],
              ['INV-260612-0041', 'Raka', 'Tunai', `<strong>${rupiah(175000)}</strong>`, '<span class="capture-status">Lunas</span>', 'Preview'],
            ],
            '1.1fr .8fr .7fr .9fr .7fr .7fr',
          )}
        </article>
      </section>
    `,
  },
  sales: {
    title: 'Penjualan',
    actions: '<input class="capture-search" value="Cari transaksi" readonly /><button class="capture-button primary">Transaksi baru</button>',
    content: `
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Riwayat penjualan</span><h2>Invoice penjualan</h2></div></div>
          ${table(
            ['Invoice', 'Pelanggan', 'Metode', 'Subtotal', 'Pajak', 'Total', 'Status'],
            [
              ['INV-260612-0043', 'Pelanggan umum', 'QRIS', rupiah(260000), rupiah(28600), `<strong>${rupiah(288600)}</strong>`, '<span class="capture-status">Lunas</span>'],
              ['INV-260612-0042', 'PT Contoh Niaga', 'Tempo', rupiah(1400000), rupiah(154000), `<strong>${rupiah(1554000)}</strong>`, '<span class="capture-status warning">Piutang</span>'],
            ],
            '1fr 1.1fr .7fr .8fr .7fr .85fr .7fr',
          )}
        </article>
      </section>
      <div class="capture-modal-backdrop">
        <article class="capture-modal">
          <div class="capture-modal-head"><div><span class="label">Penjualan</span><h2>Transaksi baru</h2></div><button class="capture-button">Tutup</button></div>
          <form class="capture-form">
            <div class="capture-form-grid">
              <label>Metode pembayaran<select class="capture-input"><option>QRIS</option></select></label>
              <label>Pelanggan<input class="capture-input" value="Pelanggan umum" readonly /></label>
            </div>
            <div class="capture-item-row">
              <label>Produk<select class="capture-input"><option>Kopi Arabika 1 kg</option></select></label>
              <label>Qty<input class="capture-input" value="2" readonly /></label>
              <label>Diskon %<input class="capture-input" value="5" readonly /></label>
              <button class="capture-button">X</button>
            </div>
            <div class="capture-item-row">
              <label>Produk<select class="capture-input"><option>Tumbler 500 ml</option></select></label>
              <label>Qty<input class="capture-input" value="1" readonly /></label>
              <label>Diskon %<input class="capture-input" value="0" readonly /></label>
              <button class="capture-button">X</button>
            </div>
            <button class="capture-button">Tambah produk</button>
            <div class="capture-summary">
              <div><span>Subtotal</span><strong>${rupiah(260000)}</strong></div>
              <div><span>Pajak 11%</span><strong>${rupiah(28600)}</strong></div>
              <div class="total"><span>Total</span><strong>${rupiah(288600)}</strong></div>
            </div>
            <button class="capture-button primary">Simpan transaksi</button>
          </form>
        </article>
      </div>
    `,
  },
  products: {
    title: 'Produk & Stok',
    actions: '<input class="capture-search" value="Cari produk" readonly /><button class="capture-button primary">Tambah produk</button>',
    content: `
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Master produk</span><h2>Produk & stok</h2></div><button class="capture-button">Import Excel</button></div>
          <div class="capture-filters">
            <label>Jenis<select class="capture-select"><option>Semua jenis</option></select></label>
            <label>Stok<select class="capture-select"><option>Semua stok</option></select></label>
            <label>Loyverse<select class="capture-select"><option>Semua status</option></select></label>
            <label>Per halaman<select class="capture-select"><option>10 produk</option></select></label>
          </div>
          ${table(
            ['SKU', 'Barcode', 'Produk', 'Tipe', 'Stok', 'Beli', 'Jual', 'Loyverse', 'Aksi'],
            [
              ['BAT-9V', '899100100901', '<strong>Baterai 9V</strong>', 'Satuan', '<strong>28</strong>', rupiah(8500), rupiah(12000), '<span class="capture-status">Terintegrasi</span>', '<select class="capture-select"><option>Aksi</option><option>Edit produk</option></select>'],
              ['KOPI-1KG', '899100100902', '<strong>Kopi Arabika 1 kg</strong>', 'Volume', '<strong>14,5</strong>', rupiah(85000), rupiah(125000), '<span class="capture-status">Terintegrasi</span>', '<select class="capture-select"><option>Aksi</option><option>Edit produk</option></select>'],
              ['ROKOK-PK12', '-', '<strong>Rokok 1 bungkus</strong><br /><small>12x Rokok per batang</small>', 'Composite', '<strong>16</strong>', rupiah(24000), rupiah(28000), '<span class="capture-status warning">Belum</span>', '<select class="capture-select"><option>Aksi</option><option>Edit produk</option></select>'],
              ['TMB-500', '899100100904', '<strong>Tumbler 500 ml</strong>', 'Satuan', '<strong>42</strong>', rupiah(45000), rupiah(75000), '<span class="capture-status">Terintegrasi</span>', '<select class="capture-select"><option>Aksi</option><option>Edit produk</option></select>'],
            ],
            '.7fr .9fr 1.25fr .65fr .5fr .7fr .7fr .8fr .75fr',
          )}
          <div class="capture-pagination"><button class="capture-button">Sebelumnya</button><span>1-10 dari 38 produk | Halaman 1 dari 4</span><button class="capture-button">Berikutnya</button></div>
        </article>
      </section>
    `,
  },
  purchases: {
    title: 'Purchase Order',
    actions: '<input class="capture-search" value="Cari purchase order" readonly /><button class="capture-button primary">Buat purchase order</button>',
    content: `
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Riwayat pembelian</span><h2>Purchase Order</h2></div><button class="capture-button">Terima barang</button></div>
          ${table(
            ['Nomor PO', 'Supplier', 'Tanggal', 'Total', 'Status', 'Aksi'],
            [
              ['PO-260612-0012', 'PT Sumber Makmur', '12 Jun 2026', `<strong>${rupiah(1725000)}</strong>`, '<span class="capture-status warning">Menunggu</span>', 'Detail'],
              ['PO-260611-0011', 'CV Niaga Jaya', '11 Jun 2026', `<strong>${rupiah(850000)}</strong>`, '<span class="capture-status">Diterima</span>', 'Detail'],
            ],
            '1fr 1.2fr .8fr .9fr .8fr .6fr',
          )}
        </article>
      </section>
      <div class="capture-modal-backdrop">
        <article class="capture-modal">
          <div class="capture-modal-head"><div><span class="label">Pembelian</span><h2>Purchase Order baru</h2></div><button class="capture-button">Tutup</button></div>
          <form class="capture-form">
            <div class="capture-form-grid">
              <label>Nomor PO<input class="capture-input" value="Otomatis saat disimpan" readonly /></label>
              <label>Supplier<input class="capture-input" value="PT Sumber Makmur" readonly /></label>
            </div>
            <div class="capture-item-row">
              <label>Produk<select class="capture-input"><option>Baterai 9V</option></select></label>
              <label>Qty<input class="capture-input" value="100" readonly /></label>
              <label>Harga beli<input class="capture-input" value="8500" readonly /></label>
              <button class="capture-button">X</button>
            </div>
            <div class="capture-item-row">
              <label>Produk<select class="capture-input"><option>Kertas Thermal</option></select></label>
              <label>Qty<input class="capture-input" value="50" readonly /></label>
              <label>Harga beli<input class="capture-input" value="17500" readonly /></label>
              <button class="capture-button">X</button>
            </div>
            <button class="capture-button">Tambah barang</button>
            <div class="capture-summary"><div class="total"><span>Total PO</span><strong>${rupiah(1725000)}</strong></div></div>
            <button class="capture-button primary">Simpan purchase order</button>
          </form>
        </article>
      </div>
    `,
  },
  receivables: {
    title: 'Piutang',
    actions: '<input class="capture-search" value="Cari piutang" readonly />',
    content: `
      <section class="capture-metrics">
        <article class="panel capture-metric"><span>Total piutang</span><strong>${rupiah(8425000)}</strong><small>7 invoice aktif</small></article>
        <article class="panel capture-metric"><span>Sudah dibayar</span><strong>${rupiah(3150000)}</strong><small>Termasuk DP</small></article>
        <article class="panel capture-metric"><span>Sisa tagihan</span><strong>${rupiah(5275000)}</strong><small>Belum jatuh tempo</small></article>
        <article class="panel capture-metric"><span>Jatuh tempo</span><strong>2</strong><small>Perlu ditagih</small></article>
      </section>
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Tagihan pelanggan</span><h2>Status pembayaran</h2></div></div>
          ${table(
            ['Invoice', 'Pelanggan', 'Tagihan', 'Dibayar', 'Sisa', 'Tempo', 'Status', 'Aksi'],
            [
              ['INV-260612-0042', 'PT Contoh Niaga', rupiah(1554000), rupiah(500000), `<strong>${rupiah(1054000)}</strong>`, '19 Jun 2026', '<span class="capture-status warning">Sebagian</span>', 'Bayar'],
              ['INV-260610-0037', 'Toko Nusantara', rupiah(2750000), rupiah(0), `<strong>${rupiah(2750000)}</strong>`, '17 Jun 2026', '<span class="capture-status warning">Belum dibayar</span>', 'Bayar'],
              ['INV-260605-0028', 'CV Maju Bersama', rupiah(1200000), rupiah(1200000), `<strong>${rupiah(0)}</strong>`, '12 Jun 2026', '<span class="capture-status">Lunas</span>', 'Detail'],
            ],
            '1fr 1.1fr .85fr .85fr .85fr .8fr .9fr .6fr',
          )}
        </article>
      </section>
      <div class="capture-modal-backdrop">
        <article class="capture-modal" style="width:560px">
          <div class="capture-modal-head"><div><span class="label">Pembayaran piutang</span><h2>INV-260612-0042</h2></div><button class="capture-button">Tutup</button></div>
          <form class="capture-form">
            <div class="capture-summary">
              <div><span>Pelanggan</span><strong>PT Contoh Niaga</strong></div>
              <div><span>Sisa tagihan</span><strong>${rupiah(1054000)}</strong></div>
            </div>
            <div class="capture-form-grid">
              <label>Nominal pembayaran<input class="capture-input" value="554000" readonly /></label>
              <label>Metode<select class="capture-input"><option>Transfer</option></select></label>
            </div>
            <button class="capture-button primary">Simpan pembayaran & buat invoice</button>
          </form>
        </article>
      </div>
    `,
  },
  reports: {
    title: 'Laporan Penjualan',
    actions: '<input class="capture-search" value="Cari laporan penjualan" readonly /><button class="capture-button">Unduh laporan</button>',
    content: `
      <section class="capture-metrics">
        <article class="panel capture-metric"><span>Penjualan bersih</span><strong>${rupiah(42850000)}</strong><small>Periode 1-12 Juni</small></article>
        <article class="panel capture-metric"><span>Diskon</span><strong>${rupiah(725000)}</strong><small>Tidak termasuk pajak</small></article>
        <article class="panel capture-metric"><span>Pajak</span><strong>${rupiah(4633750)}</strong><small>Bukan keuntungan</small></article>
        <article class="panel capture-metric"><span>Laba kotor</span><strong>${rupiah(13280000)}</strong><small>Penjualan bersih - HPP</small></article>
      </section>
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Rincian laporan</span><h2>Penjualan per invoice</h2></div></div>
          ${table(
            ['Tanggal', 'Invoice', 'Pelanggan', 'Subtotal', 'Diskon', 'Pajak', 'Total', 'HPP', 'Laba'],
            [
              ['12 Jun', 'INV-0043', 'Umum', rupiah(260000), rupiah(10000), rupiah(27500), rupiah(277500), rupiah(165000), `<strong>${rupiah(85000)}</strong>`],
              ['12 Jun', 'INV-0042', 'PT Contoh', rupiah(1400000), rupiah(0), rupiah(154000), rupiah(1554000), rupiah(950000), `<strong>${rupiah(450000)}</strong>`],
              ['11 Jun', 'INV-0039', 'Umum', rupiah(475000), rupiah(25000), rupiah(49500), rupiah(499500), rupiah(310000), `<strong>${rupiah(140000)}</strong>`],
            ],
            '.7fr .8fr .9fr .85fr .75fr .75fr .85fr .8fr .8fr',
          )}
          <p class="capture-note">Kolom pajak dipisahkan dan tidak dihitung sebagai laba usaha.</p>
        </article>
      </section>
    `,
  },
  settings: {
    title: 'Profil Bisnis',
    actions: '<input class="capture-search" value="Cari pengaturan bisnis" readonly />',
    content: `
      <section class="capture-grid">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Identitas outlet</span><h2>Profil bisnis</h2></div></div>
          <form class="capture-form">
            <div class="capture-form-grid">
              <label>Nama usaha<input class="capture-input" value="Toko Demo Akurata" readonly /></label>
              <label>Alamat<input class="capture-input" value="Jl. Contoh No. 10, Bandung" readonly /></label>
            </div>
            <div class="capture-form-grid">
              <div class="capture-logo-box"><img src="../logo.png" alt="Logo usaha" /></div>
              <label>Logo usaha<input class="capture-input" value="logo-usaha.png" readonly /></label>
            </div>
            <div class="capture-form-grid">
              <label>Bank penagihan<input class="capture-input" value="Bank BCA" readonly /></label>
              <label>Nomor rekening<input class="capture-input" value="1234567890" readonly /></label>
            </div>
            <label>Nama pemilik rekening<input class="capture-input" value="Toko Demo Akurata" readonly /></label>
            <button class="capture-button primary">Simpan profil bisnis</button>
          </form>
        </article>
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Dokumen & fitur</span><h2>Pengaturan transaksi</h2></div></div>
          <form class="capture-form">
            <label><input type="checkbox" checked /> Aktifkan pajak invoice</label>
            <label>Pajak invoice (%)<input class="capture-input" value="11" readonly /></label>
            <label><input type="checkbox" checked /> Tampilkan fitur Quotation</label>
            <p class="capture-note">Logo, rekening, dan pajak akan diterapkan pada invoice serta dokumen terkait.</p>
          </form>
        </article>
      </section>
    `,
  },
  integrations: {
    title: 'Integrasi',
    actions: '<button class="capture-button primary">Push produk</button><button class="capture-button">Tarik penjualan</button>',
    content: `
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Loyverse</span><h2>Integrasi akun</h2></div><span class="capture-status">Terhubung</span></div>
          <section class="capture-metrics" style="grid-template-columns:repeat(3,1fr)">
            <article class="panel capture-metric"><span>Akun</span><strong style="font-size:16px">Akurata Demo</strong><small>Merchant Loyverse</small></article>
            <article class="panel capture-metric"><span>POS aktif</span><strong style="font-size:16px">Toko Utama</strong><small>Tujuan stok & transaksi</small></article>
            <article class="panel capture-metric"><span>Terakhir sinkron</span><strong style="font-size:16px">12 Jun 19.30</strong><small>Produk dan receipt</small></article>
          </section>
          <div class="capture-grid" style="grid-template-columns:1fr 1fr">
            <div class="capture-list">
              <article><div><strong>Toko Utama</strong><br /><span>Jl. Contoh No. 10</span></div><span class="capture-status">POS aktif</span></article>
              <article><div><strong>Gudang</strong><br /><span>Jl. Industri No. 2</span></div><button class="capture-button">Pilih</button></article>
            </div>
            <div class="capture-summary">
              <div><span>Produk tersinkron</span><strong>34 / 38</strong></div>
              <div><span>Pajak Loyverse</span><strong>PPN 11%</strong></div>
              <div><span>Receipt terakhir</span><strong>RCP-20491</strong></div>
            </div>
          </div>
          <p class="capture-note">Gunakan Push produk untuk mengirim produk baru/perubahan. Gunakan Tarik penjualan untuk membaca receipt Loyverse dan membuat invoice Akurata.</p>
        </article>
      </section>
    `,
  },
  access: {
    title: 'Akses',
    actions: '<input class="capture-search" value="Cari user" readonly /><button class="capture-button primary">Tambah manager</button>',
    content: `
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Tim outlet</span><h2>Pengguna & peran</h2></div></div>
          ${table(
            ['Nama', 'Email', 'Peran', 'Status', 'Aksi'],
            [
              ['Rian Setia', 'owner@example.com', '<strong>Owner</strong>', '<span class="capture-status">Aktif</span>', 'Ubah password'],
              ['Raka', 'manager@example.com', '<strong>Manager</strong>', '<span class="capture-status">Aktif</span>', 'Hapus akun'],
              ['Nadia', 'kasir@example.com', '<strong>Cashier</strong>', '<span class="capture-status">Aktif</span>', 'Hapus akun'],
            ],
            '1fr 1.4fr .8fr .7fr .8fr',
          )}
          <p class="capture-note">Manager tidak dapat membuka Integrasi, Log, dan Profil Bisnis. Owner memiliki akses penuh pada outlet.</p>
        </article>
      </section>
      <section class="capture-chat">
        <header><strong>Customer Service</strong><span>Online</span></header>
        <div class="capture-chat-body">
          <div class="capture-message out">Halo, saya membutuhkan bantuan sinkron stok.</div>
          <div class="capture-message">Baik, kami bantu cek koneksi POS aktif Anda.</div>
          <div class="capture-message out">POS yang dipakai adalah Toko Utama.</div>
        </div>
        <footer><input class="capture-input" value="Tulis pesan..." readonly /><button class="capture-button primary">Kirim</button></footer>
      </section>
    `,
  },
  admin: {
    title: 'Administrator',
    actions: '<input class="capture-search" value="Cari outlet atau akun" readonly />',
    content: `
      <section class="capture-metrics">
        <article class="panel capture-admin-stat"><span>Total outlet</span><strong>18</strong><small>16 aktif</small></article>
        <article class="panel capture-admin-stat"><span>Total pengguna</span><strong>47</strong><small>Owner, manager, cashier</small></article>
        <article class="panel capture-admin-stat"><span>Chat terbuka</span><strong>3</strong><small>Menunggu balasan</small></article>
        <article class="panel capture-admin-stat"><span>Integrasi aktif</span><strong>12</strong><small>Terhubung ke Loyverse</small></article>
      </section>
      <section class="capture-grid single">
        <article class="panel capture-panel">
          <div class="capture-panel-head"><div><span class="label">Manajemen tenant</span><h2>Outlet terdaftar</h2></div></div>
          ${table(
            ['Outlet', 'Owner', 'Pengguna', 'Loyverse', 'Status', 'Aksi'],
            [
              ['Toko Demo Akurata', 'Rian Setia', '4 user', '<span class="capture-status">Terhubung</span>', '<span class="capture-status">Aktif</span>', '<button class="capture-button primary">Impersonate</button>'],
              ['Kedai Nusantara', 'Dewi Ananda', '3 user', '<span class="capture-status warning">Belum</span>', '<span class="capture-status">Aktif</span>', '<button class="capture-button primary">Impersonate</button>'],
              ['Retail Sejahtera', 'Fajar Putra', '5 user', '<span class="capture-status">Terhubung</span>', '<span class="capture-status">Aktif</span>', '<button class="capture-button primary">Impersonate</button>'],
            ],
            '1.2fr 1fr .65fr .8fr .7fr .8fr',
          )}
          <p class="capture-note">Administrator dapat mengelola outlet, akun, chat CS, dan masuk sebagai outlet melalui impersonate.</p>
        </article>
      </section>
    `,
  },
};

const selected = templates[screen] || templates.dashboard;
document.querySelector('[data-capture-title]').textContent = selected.title;
document.querySelector('[data-capture-actions]').innerHTML = selected.actions;
document.querySelector('[data-capture-content]').innerHTML = selected.content;
document.querySelector('[data-capture-nav]').innerHTML = navItems.map(([id, label]) => (
  id === 'section'
    ? `<span>${label}</span>`
    : `<a class="${id === screen ? 'active' : ''}">${label}</a>`
)).join('');
