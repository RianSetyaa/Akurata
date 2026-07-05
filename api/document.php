<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$pdo = db();
$type = $_GET['type'] ?? '';
$id = (int) ($_GET['id'] ?? 0);

if (!in_array($type, ['invoice', 'quotation', 'payment'], true) || $id <= 0) {
    json_response(['error' => 'Dokumen tidak valid.'], 422);
}

function e($value): string
{
    return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function rupiah_doc($value): string
{
    return 'Rp ' . number_format((float) ($value ?? 0), 0, ',', '.');
}

if ($type === 'invoice') {
    $stmt = $pdo->prepare("
        SELECT t.id, t.invoice_no AS number, t.payment_method, t.payment_status,
               t.subtotal_amount, t.tax_rate, t.tax_amount, t.total_amount,
               t.sold_at AS document_date, c.name AS customer, c.phone AS customer_phone,
               u.name AS maker, o.name AS outlet_name, o.address AS outlet_address, o.logo_path AS outlet_logo,
               o.billing_bank_name, o.billing_account_name, o.billing_account_number
        FROM transactions t
        JOIN users u ON u.id = t.user_id
        JOIN outlets o ON o.id = t.outlet_id
        LEFT JOIN customers c ON c.id = t.customer_id
        WHERE t.id = :id
          AND t.outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);
    $document = $stmt->fetch();

    $itemStmt = $pdo->prepare("
        SELECT p.name, ti.qty, ti.unit_price, ti.subtotal
        FROM transaction_items ti
        JOIN products p ON p.id = ti.product_id
        WHERE ti.transaction_id = :id
        ORDER BY ti.id ASC
    ");
    $title = 'Invoice';
    $filenamePrefix = 'invoice';
} elseif ($type === 'quotation') {
    $stmt = $pdo->prepare("
        SELECT q.id, q.quote_no AS number, q.status AS payment_status,
               q.subtotal_amount, q.tax_rate, q.tax_amount, q.total_amount,
               q.valid_until, q.notes, q.quoted_at AS document_date,
               c.name AS customer, c.phone AS customer_phone,
               u.name AS maker, o.name AS outlet_name, o.address AS outlet_address, o.logo_path AS outlet_logo,
               o.billing_bank_name, o.billing_account_name, o.billing_account_number
        FROM quotations q
        JOIN users u ON u.id = q.user_id
        JOIN outlets o ON o.id = q.outlet_id
        LEFT JOIN customers c ON c.id = q.customer_id
        WHERE q.id = :id
          AND q.outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);
    $document = $stmt->fetch();

    $itemStmt = $pdo->prepare("
        SELECT p.name, qi.qty, qi.unit_price, qi.subtotal
        FROM quotation_items qi
        JOIN products p ON p.id = qi.product_id
        WHERE qi.quotation_id = :id
        ORDER BY qi.id ASC
    ");
    $title = 'Quotation';
    $filenamePrefix = 'quotation';
} else {
    $stmt = $pdo->prepare("
        SELECT rp.id, rp.payment_no AS number, rp.payment_method, r.status AS payment_status,
               rp.amount AS total_amount, rp.notes, rp.paid_at AS document_date,
               t.invoice_no AS original_invoice_no, r.amount AS receivable_amount,
               r.paid_amount AS receivable_paid_amount, (r.amount - r.paid_amount) AS receivable_remaining_amount,
               c.name AS customer, c.phone AS customer_phone,
               u.name AS maker, o.name AS outlet_name, o.address AS outlet_address, o.logo_path AS outlet_logo,
               o.billing_bank_name, o.billing_account_name, o.billing_account_number
        FROM receivable_payments rp
        JOIN receivables r ON r.id = rp.receivable_id
        JOIN transactions t ON t.id = r.transaction_id
        JOIN users u ON u.id = rp.user_id
        JOIN outlets o ON o.id = rp.outlet_id
        LEFT JOIN customers c ON c.id = r.customer_id
        WHERE rp.id = :id
          AND rp.outlet_id = :outlet_id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id, ':outlet_id' => $outletId]);
    $document = $stmt->fetch();

    $items = $document ? [[
        'name' => 'Pembayaran piutang ' . (string) $document['original_invoice_no'],
        'qty' => 1,
        'unit_price' => $document['total_amount'],
        'subtotal' => $document['total_amount'],
    ]] : [];
    $itemStmt = null;
    $title = 'Invoice Pembayaran';
    $filenamePrefix = 'payment';
}

if (!$document) {
    json_response(['error' => 'Dokumen tidak ditemukan.'], 404);
}

if ($itemStmt !== null) {
    $itemStmt->execute([':id' => $id]);
    $items = $itemStmt->fetchAll();
}
$subtotalAmount = (float) ($document['subtotal_amount'] ?? $document['total_amount'] ?? 0);
$taxRate = (float) ($document['tax_rate'] ?? 0);
$taxAmount = (float) ($document['tax_amount'] ?? 0);
$totalAmount = (float) ($document['total_amount'] ?? ($subtotalAmount + $taxAmount));
$filename = $filenamePrefix . '-' . preg_replace('/[^A-Za-z0-9-]/', '-', (string) $document['number']) . '.pdf';
$backPath = $type === 'quotation' ? '../dashboard/quotations.html' : ($type === 'payment' ? '../dashboard/receivables.html' : '../dashboard/sales.html');
$logoPath = !empty($document['outlet_logo']) ? '../' . ltrim((string) $document['outlet_logo'], '/') : '../logo.png';
$documentLabel = match ($type) {
    'quotation' => 'QUOTATION',
    'payment' => 'INVOICE',
    default => 'INVOICE',
};

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($title) ?> <?= e($document['number']) ?></title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 32px;
      color: #111827;
      font-family: Arial, sans-serif;
      background: #f3f6f9;
    }
    .preview-toolbar {
      max-width: 900px;
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
      padding: 24mm 22mm;
      background: #fff;
      border: 1px solid #e5e7eb;
      box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12);
    }
    .top {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      gap: 24px;
      border-bottom: 2px solid #0f172a;
      padding-bottom: 18px;
    }
    .document-title {
      margin: 0;
      color: #030712;
      font-size: 44px;
      font-weight: 900;
      line-height: 1;
      letter-spacing: 0.03em;
      text-transform: uppercase;
    }
    .brand-doc {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 12px;
      max-width: 52%;
    }
    .brand-doc img {
      width: 54px;
      height: 54px;
      object-fit: contain;
      border: 1px solid #e5e7eb;
      border-radius: 8px;
      background: #fff;
    }
    .brand-doc h2 {
      margin: 0;
      color: #111827;
      font-size: 23px;
      font-weight: 700;
      line-height: 1.15;
      letter-spacing: 0.01em;
      text-transform: uppercase;
    }
    .brand-doc p,
    p {
      margin: 4px 0;
      color: #374151;
      font-size: 15px;
      line-height: 1.35;
    }
    .meta {
      margin: 32px 0 28px;
      display: grid;
      grid-template-columns: minmax(0, 1fr) 240px;
      gap: 16px;
    }
    .meta h2,
    .payment h2,
    .thanks h2 {
      margin: 0 0 6px;
      color: #030712;
      font-size: 16px;
      font-weight: 900;
      line-height: 1.2;
      text-transform: uppercase;
    }
    .meta-right {
      text-align: right;
    }
    .meta-right div + div {
      margin-top: 18px;
    }
    .invoice-table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      table-layout: fixed;
    }
    .invoice-table th {
      padding: 0 8px 12px;
      color: #030712;
      font-size: 15px;
      font-weight: 900;
      text-align: left;
      text-transform: uppercase;
    }
    .invoice-table td {
      padding: 13px 8px;
      border-bottom: 1px solid #111827;
      background: #e7e7e7;
      color: #111827;
      font-size: 15px;
    }
    .invoice-table th:nth-child(1),
    .invoice-table td:nth-child(1) {
      padding-left: 20px;
    }
    .invoice-table th:nth-child(2),
    .invoice-table td:nth-child(2) {
      text-align: right;
    }
    .invoice-table th:nth-child(3),
    .invoice-table td:nth-child(3) {
      text-align: center;
    }
    .invoice-table th:nth-child(4),
    .invoice-table td:nth-child(4) {
      padding-right: 20px;
      text-align: right;
    }
    .invoice-table tbody tr:last-child td {
      border-bottom: 0;
    }
    .right { text-align: right; }
    .bottom {
      margin-top: 24px;
      display: grid;
      grid-template-columns: minmax(0, 1fr) 280px;
      gap: 24px;
      align-items: start;
    }
    .totals {
      display: grid;
      gap: 8px;
    }
    .totals div {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 16px;
      align-items: baseline;
    }
    .totals span {
      color: #111827;
      font-size: 15px;
      font-weight: 600;
      text-align: right;
      text-transform: uppercase;
    }
    .totals strong {
      color: #111827;
      font-size: 15px;
      text-align: right;
    }
    .totals .grand span,
    .totals .grand strong {
      font-weight: 900;
    }
    .notes { margin-top: 24px; padding: 16px; background: #f8fafc; border: 1px solid #e5e7eb; }
    .footer {
      margin-top: 78px;
      display: grid;
      grid-template-columns: minmax(0, 1fr) 280px;
      gap: 24px;
      align-items: end;
    }
    .signature {
      text-align: center;
    }
    .signature-line {
      width: 180px;
      margin: 0 auto 8px;
      border-top: 1px solid #111827;
    }

    @page {
      size: A4;
      margin: 0;
    }

    @media print {
      body {
        padding: 0;
        background: #fff;
      }
      .preview-toolbar {
        display: none;
      }
      .paper {
        width: auto;
        min-height: 297mm;
        margin: 0;
        border: 0;
        box-shadow: none;
      }
    }

    @media (max-width: 860px) {
      body { padding: 16px; }
      .paper {
        width: 100%;
        min-height: auto;
        padding: 20px;
      }
      .top,
      .meta,
      .bottom,
      .footer {
        grid-template-columns: 1fr;
        flex-direction: column;
      }
      .brand-doc {
        max-width: 100%;
        justify-content: flex-start;
      }
      .meta-right,
      .totals span,
      .totals strong {
        text-align: left;
      }
      .preview-toolbar {
        justify-content: stretch;
      }
      .preview-toolbar button,
      .preview-toolbar a {
        flex: 1;
        justify-content: center;
      }
    }
  </style>
</head>
<body>
  <nav class="preview-toolbar" aria-label="Aksi dokumen">
    <a href="<?= e($backPath) ?>">Kembali</a>
    <button class="primary" type="button" onclick="window.print()">Unduh PDF</button>
  </nav>

  <main class="paper">
    <section class="top">
      <div>
        <h1 class="document-title"><?= e($documentLabel) ?></h1>
      </div>
      <div class="brand-doc right">
        <div>
          <h2><?= e($document['outlet_name']) ?></h2>
          <p><?= e($document['outlet_address']) ?></p>
        </div>
        <img src="<?= e($logoPath) ?>" alt="Logo <?= e($document['outlet_name']) ?>">
      </div>
    </section>

    <section class="meta">
      <div>
        <h2>Kepada :</h2>
        <p><?= e($document['customer'] ?? 'Pelanggan umum') ?></p>
        <p><?= e($document['customer_phone']) ?></p>
      </div>
      <div class="meta-right">
        <div>
          <h2>Tanggal :</h2>
          <p><?= e($document['document_date']) ?></p>
        </div>
        <div>
          <h2>No <?= e($documentLabel) ?> :</h2>
          <p><?= e($document['number']) ?></p>
        </div>
        <?php if ($type === 'quotation') : ?>
          <div>
            <h2>Berlaku :</h2>
            <p><?= e($document['valid_until'] ?? '-') ?></p>
          </div>
        <?php elseif ($type === 'payment') : ?>
          <div>
            <h2>Invoice asal :</h2>
            <p><?= e($document['original_invoice_no'] ?? '-') ?></p>
          </div>
        <?php endif; ?>
      </div>
    </section>

    <table class="invoice-table">
      <colgroup>
        <col style="width: 44%;">
        <col style="width: 23%;">
        <col style="width: 10%;">
        <col style="width: 23%;">
      </colgroup>
      <thead>
        <tr>
          <th>Keterangan</th>
          <th class="right">Harga</th>
          <th class="right">Jml</th>
          <th class="right">Subtotal</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item) : ?>
          <tr>
            <td><?= e($item['name']) ?></td>
            <td class="right"><?= e(rupiah_doc($item['unit_price'])) ?></td>
            <td class="right"><?= e(quantity($item['qty'])) ?></td>
            <td class="right"><?= e(rupiah_doc($item['subtotal'])) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <section class="bottom">
      <div class="payment">
        <h2>Pembayaran :</h2>
        <?php if ($type === 'invoice' && ($document['payment_method'] ?? '') === 'tempo') : ?>
          <p>Bank : <?= e($document['billing_bank_name'] ?: '-') ?></p>
          <p>Nama : <?= e($document['billing_account_name'] ?: $document['outlet_name']) ?></p>
          <p>No Rek : <?= e($document['billing_account_number'] ?: '-') ?></p>
        <?php elseif ($type === 'payment') : ?>
          <p>Metode : <?= e($document['payment_method']) ?></p>
          <p>Sisa tagihan : <?= e(rupiah_doc($document['receivable_remaining_amount'] ?? 0)) ?></p>
          <p>Status : <?= e($document['payment_status']) ?></p>
        <?php else : ?>
          <p>Nama : <?= e($document['outlet_name']) ?></p>
          <p>Status : <?= e($document['payment_status']) ?></p>
        <?php endif; ?>
      </div>
      <div class="totals">
        <div><span>Sub total :</span><strong><?= e(rupiah_doc($subtotalAmount)) ?></strong></div>
        <div><span>Pajak<?= $taxRate > 0 ? ' ' . e($taxRate) . '%' : '' ?> :</span><strong><?= e(rupiah_doc($taxAmount)) ?></strong></div>
        <div class="grand"><span>Total :</span><strong><?= e(rupiah_doc($totalAmount)) ?></strong></div>
      </div>
    </section>

    <?php if (in_array($type, ['quotation', 'payment'], true) && !empty($document['notes'])) : ?>
      <section class="notes">
        <h2>Catatan</h2>
        <p><?= nl2br(e($document['notes'])) ?></p>
      </section>
    <?php endif; ?>

    <section class="footer">
      <div class="thanks">
        <h2>Terimakasih atas</h2>
        <h2><?= $type === 'quotation' ? 'kepercayaan anda' : ($type === 'payment' ? 'pembayaran anda' : 'pembelian anda') ?></h2>
      </div>
      <div class="signature">
        <div class="signature-line"></div>
        <p><?= e($document['maker']) ?></p>
      </div>
    </section>
  </main>
  <script>
    document.title = <?= json_encode($filename, JSON_UNESCAPED_SLASHES) ?>;
  </script>
</body>
</html>
