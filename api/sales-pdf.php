<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();

$month = $_GET['month'] ?? '';
$startDate = $_GET['start_date'] ?? '';
$endDate = $_GET['end_date'] ?? '';
$paymentMethod = $_GET['payment_method'] ?? '';
$source = $_GET['source'] ?? '';

function e_pdf($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah_pdf($value): string
{
    return 'Rp ' . number_format((float) ($value ?? 0), 0, ',', '.');
}

$periodLabel = '';
if ($startDate !== '' && $endDate !== '') {
    $periodLabel = date('d/m/Y', strtotime($startDate)) . ' — ' . date('d/m/Y', strtotime($endDate));
} elseif ($month !== '') {
    $timestamp = strtotime($month . '-01');
    if ($timestamp !== false) {
        $periodLabel = date('F Y', $timestamp);
    }
}

$where = "WHERE t.outlet_id = :outlet_id AND t.payment_status <> 'void'";
$params = [':outlet_id' => $outletId];

if ($startDate !== '' && $endDate !== '') {
    $where .= ' AND DATE(t.sold_at) >= :start_date AND DATE(t.sold_at) <= :end_date';
    $params[':start_date'] = $startDate;
    $params[':end_date'] = $endDate;
} elseif ($month !== '') {
    $where .= ' AND DATE_FORMAT(t.sold_at, \'%Y-%m\') = :month';
    $params[':month'] = $month;
}

if ($paymentMethod !== '') {
    $where .= ' AND t.payment_method = :payment_method';
    $params[':payment_method'] = $paymentMethod;
}

if ($source !== '') {
    $where .= ' AND t.source = :source';
    $params[':source'] = $source;
}

$summaryWhere = str_replace('t.', '', $where);

$summaryStmt = $pdo->prepare("
    SELECT COUNT(*) AS count_all,
           COALESCE(SUM(CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END), 0) AS subtotal_amount,
           COALESCE(SUM(discount_amount), 0) AS discount_amount,
           COALESCE(SUM(tax_amount), 0) AS tax_amount,
           COALESCE(SUM(total_amount), 0) AS total_amount,
           COALESCE(SUM(cost_amount), 0) AS cost_amount,
           COALESCE(SUM((CASE WHEN subtotal_amount > 0 THEN subtotal_amount ELSE total_amount - tax_amount END) - cost_amount), 0) AS gross_profit
    FROM transactions
    {$summaryWhere}
");
$summaryStmt->execute($params);
$summary = $summaryStmt->fetch();

$stmt = $pdo->prepare("
    SELECT t.id, t.invoice_no, t.payment_method, t.payment_status,
           CASE WHEN t.subtotal_amount > 0 THEN t.subtotal_amount ELSE t.total_amount - t.tax_amount END AS subtotal_amount,
           t.discount_amount, t.tax_amount, t.total_amount,
           t.cost_amount, t.source, t.sold_at, u.name AS cashier, c.name AS customer
    FROM transactions t
    JOIN users u ON u.id = t.user_id
    LEFT JOIN customers c ON c.id = t.customer_id
    {$where}
    ORDER BY t.sold_at ASC
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$outletStmt = $pdo->prepare("SELECT name FROM outlets WHERE id = :id LIMIT 1");
$outletStmt->execute([':id' => $outletId]);
$outlet = $outletStmt->fetch();

$reportTitle = 'Laporan Penjualan';
$filterParts = [];
if ($periodLabel !== '') {
    $filterParts[] = $periodLabel;
}
$methodLabels = ['cash' => 'Tunai', 'qris' => 'QRIS', 'transfer' => 'Transfer', 'tempo' => 'Tempo'];
if ($paymentMethod !== '' && isset($methodLabels[$paymentMethod])) {
    $filterParts[] = $methodLabels[$paymentMethod];
}
if ($source !== '') {
    $filterParts[] = ucfirst($source);
}
$reportSubtitle = count($filterParts) > 0 ? implode(' • ', $filterParts) : 'Semua Data';
$generatedAt = date('d/m/Y H:i');

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e_pdf($reportTitle) ?></title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 32px;
      color: #111827;
      font-family: Arial, sans-serif;
      background: #f3f6f9;
      font-size: 13px;
    }
    .preview-toolbar {
      max-width: 210mm;
      margin: 0 auto 16px;
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }
    .preview-toolbar button,
    .preview-toolbar a {
      min-height: 40px;
      padding: 0 14px;
      display: inline-flex;
      align-items: center;
      border: 1px solid #dbe3ea;
      border-radius: 8px;
      background: #fff;
      color: #111827;
      font-size: 14px;
      font-weight: 700;
      text-decoration: none;
      cursor: pointer;
    }
    .preview-toolbar .primary {
      border-color: #06b6d4;
      background: #06b6d4;
      color: #fff;
    }
    .paper {
      width: 210mm;
      min-height: 297mm;
      margin: 0 auto;
      padding: 20mm 18mm;
      background: #fff;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
    }
    .header {
      border-bottom: 2px solid #0f172a;
      padding-bottom: 16px;
      margin-bottom: 24px;
    }
    .header h1 {
      margin: 0 0 4px;
      color: #030712;
      font-size: 28px;
      font-weight: 900;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .header p {
      margin: 0;
      color: #374151;
      font-size: 14px;
    }
    .summary-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 24px;
    }
    .summary-card {
      padding: 14px;
      background: #f8fafc;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
    }
    .summary-card span {
      display: block;
      color: #374151;
      font-size: 12px;
      font-weight: 600;
      text-transform: uppercase;
      margin-bottom: 4px;
    }
    .summary-card strong {
      display: block;
      color: #111827;
      font-size: 18px;
      font-weight: 900;
    }
    .summary-card.highlight {
      background: #06b6d4;
      border-color: #06b6d4;
      color: #fff;
    }
    .summary-card.highlight span,
    .summary-card.highlight strong {
      color: #fff;
    }
    .table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      font-size: 12px;
    }
    .table th {
      padding: 8px 6px;
      color: #030712;
      font-size: 12px;
      font-weight: 900;
      text-align: left;
      text-transform: uppercase;
      border-bottom: 2px solid #0f172a;
    }
    .table td {
      padding: 8px 6px;
      border-bottom: 1px solid #e5e7eb;
      color: #111827;
    }
    .table th:nth-child(1),
    .table td:nth-child(1) { width: 16%; }
    .table th:nth-child(2),
    .table td:nth-child(2) { width: 14%; text-align: center; }
    .table th:nth-child(3),
    .table td:nth-child(3) { width: 18%; }
    .table th:nth-child(4),
    .table td:nth-child(4) { width: 12%; text-align: center; }
    .table th:nth-child(5),
    .table td:nth-child(5) { width: 14%; text-align: right; }
    .table th:nth-child(6),
    .table td:nth-child(6) { width: 14%; text-align: right; }
    .table th:nth-child(7),
    .table td:nth-child(7) { width: 12%; text-align: right; font-weight: 700; }
    .right { text-align: right; }
    .footer {
      margin-top: 32px;
      padding-top: 16px;
      border-top: 1px solid #e5e7eb;
      color: #374151;
      font-size: 12px;
      display: flex;
      justify-content: space-between;
    }
    @page {
      size: A4;
      margin: 0;
    }
    @media print {
      body { padding: 0; background: #fff; }
      .preview-toolbar { display: none; }
      .paper {
        width: auto;
        min-height: 297mm;
        margin: 0;
        border: 0;
        box-shadow: none;
      }
    }
  </style>
</head>
<body>
  <nav class="preview-toolbar" aria-label="Aksi dokumen">
    <a href="../dashboard/sales-report.html">Kembali</a>
    <button class="primary" type="button" onclick="window.print()">Unduh PDF</button>
  </nav>

  <main class="paper">
    <section class="header">
      <h1><?= e_pdf($reportTitle) ?></h1>
      <p><?= e_pdf($outlet['name'] ?? 'Outlet') ?> • <?= e_pdf($reportSubtitle) ?> • Dicetak: <?= e_pdf($generatedAt) ?></p>
    </section>

    <section class="summary-grid">
      <div class="summary-card">
        <span>Total Penjualan</span>
        <strong><?= e_pdf(rupiah_pdf($summary['total_amount'])) ?></strong>
      </div>
      <div class="summary-card">
        <span>Laba Kotor</span>
        <strong><?= e_pdf(rupiah_pdf($summary['gross_profit'])) ?></strong>
      </div>
      <div class="summary-card">
        <span>Jumlah Transaksi</span>
        <strong><?= e_pdf((int) $summary['count_all']) ?> transaksi</strong>
      </div>
      <div class="summary-card">
        <span>Subtotal</span>
        <strong><?= e_pdf(rupiah_pdf($summary['subtotal_amount'])) ?></strong>
      </div>
      <div class="summary-card">
        <span>Diskon</span>
        <strong><?= e_pdf(rupiah_pdf($summary['discount_amount'])) ?></strong>
      </div>
      <div class="summary-card">
        <span>Pajak</span>
        <strong><?= e_pdf(rupiah_pdf($summary['tax_amount'])) ?></strong>
      </div>
    </section>

    <table class="table">
      <thead>
        <tr>
          <th>Invoice</th>
          <th>Tanggal</th>
          <th>Customer</th>
          <th>Metode</th>
          <th>Subtotal</th>
          <th>Total</th>
          <th>Laba</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row) : ?>
          <tr>
            <td><?= e_pdf($row['invoice_no']) ?></td>
            <td><?= e_pdf(date('d/m/Y', strtotime($row['sold_at']))) ?></td>
            <td><?= e_pdf($row['customer'] ?? 'Pelanggan umum') ?></td>
            <td><?= e_pdf($row['payment_method']) ?></td>
            <td class="right"><?= e_pdf(rupiah_pdf($row['subtotal_amount'])) ?></td>
            <td class="right"><?= e_pdf(rupiah_pdf($row['total_amount'])) ?></td>
            <td class="right"><?= e_pdf(rupiah_pdf((float) $row['subtotal_amount'] - (float) $row['cost_amount'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <section class="footer">
      <span>Akurata POS</span>
      <span>Halaman 1 dari 1</span>
    </section>
  </main>
</body>
</html>
