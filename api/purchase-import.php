<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$action = (string) ($_GET['action'] ?? 'import');

/* ---------- XLSX helpers (same approach as sales-import.php) ---------- */

function pi_excel_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function pi_excel_xml_value($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function pi_sheet_xml(array $rows): string
{
    $xmlRows = [];
    $numericHeaders = ['qty', 'harga_beli'];
    $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $rows[0] ?? []);

    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $reference = pi_excel_column_name($columnIndex + 1) . ($rowIndex + 1);
            $isNumericColumn = in_array($headers[$columnIndex] ?? '', $numericHeaders, true);
            if ($rowIndex > 0 && $isNumericColumn && is_numeric($value) && $value !== '') {
                $cells[] = '<c r="' . $reference . '"><v>' . pi_excel_xml_value($value) . '</v></c>';
            } else {
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t>'
                    . pi_excel_xml_value($value) . '</t></is></c>';
            }
        }
        $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $lastColumn = pi_excel_column_name(count($rows[0] ?? []));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="' . count($rows[0] ?? []) . '" width="20" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . '1"/>'
        . '</worksheet>';
}

function send_purchase_import_template(): void
{
    if (!class_exists('ZipArchive')) {
        json_response(['error' => 'Extension PHP zip belum aktif.'], 500);
    }

    $poHeaders = [['invoice_no', 'tanggal', 'supplier', 'terima_stok']];
    $itemHeaders = [['invoice_no', 'sku', 'qty', 'harga_beli']];
    $poExamples = [
        $poHeaders[0],
        ['PO-LAMA-001', '2026-06-01', 'PT Supplier A', 'tidak'],
        ['PO-LAMA-002', '2026-06-05', 'CV Grosir Jaya', 'ya'],
    ];
    $itemExamples = [
        $itemHeaders[0],
        ['PO-LAMA-001', 'BATERAI-9V', 100, 6500],
        ['PO-LAMA-001', 'KABEL-USB', 50, 14000],
        ['PO-LAMA-002', 'BERAS-5KG', 20, 62000],
    ];

    $temp = tempnam(sys_get_temp_dir(), 'akurata-po-');
    if ($temp === false) {
        json_response(['error' => 'File template sementara tidak dapat dibuat.'], 500);
    }

    $zip = new ZipArchive();
    if ($zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($temp);
        json_response(['error' => 'Template Excel tidak dapat dibuat.'], 500);
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet4.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Purchase Order" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Item PO" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Contoh PO" sheetId="3" r:id="rId3"/>'
        . '<sheet name="Contoh Item" sheetId="4" r:id="rId4"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
        . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet4.xml"/>'
        . '<Relationship Id="rId5" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font/><font><b/></font></fonts>'
        . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
        . '<borders count="1"><border/></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '</styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml', pi_sheet_xml($poHeaders));
    $zip->addFromString('xl/worksheets/sheet2.xml', pi_sheet_xml($itemHeaders));
    $zip->addFromString('xl/worksheets/sheet3.xml', pi_sheet_xml($poExamples));
    $zip->addFromString('xl/worksheets/sheet4.xml', pi_sheet_xml($itemExamples));
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-import-po-akurata.xlsx"');
    header('Content-Length: ' . filesize($temp));
    readfile($temp);
    @unlink($temp);
    exit;
}

/* ---------- XLSX reader (same approach as sales-import.php) ---------- */

function pi_normalized_zip_path(string $base, string $target): string
{
    $parts = explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : $base . '/' . $target);
    $result = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { array_pop($result); continue; }
        $result[] = $part;
    }
    return implode('/', $result);
}

function pi_xlsx_shared_strings(ZipArchive $zip): array
{
    $content = $zip->getFromName('xl/sharedStrings.xml');
    if ($content === false) return [];
    $xml = simplexml_load_string($content);
    if (!$xml) throw new RuntimeException('Shared strings Excel tidak valid.');
    $strings = [];
    foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
        $text = '';
        foreach ($item->xpath('.//*[local-name()="t"]') ?: [] as $node) {
            $text .= (string) $node;
        }
        $strings[] = $text;
    }
    return $strings;
}

function pi_xlsx_sheet_rows(ZipArchive $zip, string $path, array $sharedStrings): array
{
    $stat = $zip->statName($path);
    if (!$stat || (int) ($stat['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new RuntimeException('Sheet Excel terlalu besar atau tidak ditemukan.');
    }
    $content = $zip->getFromName($path);
    $xml = $content !== false ? simplexml_load_string($content) : false;
    if (!$xml) throw new RuntimeException('Isi sheet Excel tidak valid.');

    $rows = [];
    foreach ($xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [] as $row) {
        $values = [];
        foreach ($row->xpath('./*[local-name()="c"]') ?: [] as $cell) {
            $attributes = $cell->attributes();
            $reference = (string) ($attributes['r'] ?? '');
            preg_match('/^[A-Z]+/', strtoupper($reference), $matches);
            $letters = $matches[0] ?? '';
            $column = 0;
            foreach (str_split($letters) as $letter) {
                $column = ($column * 26) + (ord($letter) - 64);
            }
            if ($column <= 0) continue;
            $type = (string) ($attributes['t'] ?? '');
            $valueNodes = $cell->xpath('./*[local-name()="v"]') ?: [];
            $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
            if ($type === 's') {
                $value = $sharedStrings[(int) $value] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = '';
                foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $textNode) {
                    $value .= (string) $textNode;
                }
            }
            $values[$column - 1] = trim($value);
        }
        if ($values) {
            ksort($values);
            $rows[] = ['number' => (int) ((string) ($row->attributes()['r'] ?? count($rows) + 1)), 'values' => $values];
        }
    }
    return $rows;
}

function pi_xlsx_read_sheets(string $path): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Extension PHP zip belum aktif.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('File Excel tidak dapat dibuka.');
    }
    try {
        $workbookContent = $zip->getFromName('xl/workbook.xml');
        $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbook = $workbookContent !== false ? simplexml_load_string($workbookContent) : false;
        $relationships = $relsContent !== false ? simplexml_load_string($relsContent) : false;
        if (!$workbook || !$relationships) {
            throw new RuntimeException('Struktur workbook Excel tidak valid.');
        }
        $targets = [];
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') ?: [] as $relationship) {
            $attrs = $relationship->attributes();
            $targets[(string) $attrs['Id']] = pi_normalized_zip_path('xl', (string) $attrs['Target']);
        }
        $sharedStrings = pi_xlsx_shared_strings($zip);
        $sheets = [];
        foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relationAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationId = (string) ($relationAttrs['id'] ?? '');
            $sheetPath = $targets[$relationId] ?? '';
            if ($sheetPath !== '') {
                $sheets[strtolower(trim((string) $attrs['name']))] = pi_xlsx_sheet_rows($zip, $sheetPath, $sharedStrings);
            }
        }
        return $sheets;
    } finally {
        $zip->close();
    }
}

function pi_sheet_records(array $rows, array $requiredHeaders, string $sheetName): array
{
    if (!$rows) throw new RuntimeException("Sheet {$sheetName} kosong.");
    $headerRow = array_shift($rows);
    $headers = [];
    foreach ($headerRow['values'] as $index => $header) {
        $headers[$index] = strtolower(trim((string) $header));
    }
    foreach ($requiredHeaders as $header) {
        if (!in_array($header, $headers, true)) {
            throw new RuntimeException("Kolom {$header} tidak ditemukan pada sheet {$sheetName}.");
        }
    }
    $records = [];
    foreach ($rows as $row) {
        $record = ['_row' => $row['number']];
        foreach ($headers as $index => $header) {
            $record[$header] = trim((string) ($row['values'][$index] ?? ''));
        }
        if (array_filter($record, static fn ($value, $key) => $key !== '_row' && $value !== '', ARRAY_FILTER_USE_BOTH)) {
            $records[] = $record;
        }
    }
    return $records;
}

function pi_error_response(array $errors): void
{
    $visible = array_slice($errors, 0, 8);
    $message = implode(' ', $visible);
    if (count($errors) > count($visible)) {
        $message .= ' Dan ' . (count($errors) - count($visible)) . ' error lainnya.';
    }
    json_response(['error' => $message, 'errors' => $errors], 422);
}

function pi_bool(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'ya', 'yes', 'y', 'true', 'terima'], true);
}

function pi_mysql_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') return null;
    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial > 0) {
            $seconds = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d H:i:s', $seconds);
        }
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) return null;
    return date('Y-m-d H:i:s', $timestamp);
}

/* ---------- Template download ---------- */

if ($action === 'template') {
    require_method('GET');
    send_purchase_import_template();
}

/* ---------- Import processing ---------- */

require_method('POST');
$pdo = db();

if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
    json_response(['error' => 'File Excel wajib dipilih.'], 422);
}
$upload = $_FILES['file'];
if ((int) ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    json_response(['error' => 'Upload file Excel gagal.'], 422);
}
if ((int) ($upload['size'] ?? 0) > 5 * 1024 * 1024) {
    json_response(['error' => 'Ukuran file Excel maksimal 5MB.'], 422);
}
$tmpName = (string) ($upload['tmp_name'] ?? '');
$extension = strtolower(pathinfo((string) ($upload['name'] ?? ''), PATHINFO_EXTENSION));
if ($tmpName === '' || !is_uploaded_file($tmpName) || $extension !== 'xlsx') {
    json_response(['error' => 'File harus berupa Excel .xlsx yang valid.'], 422);
}

try {
    $sheets = pi_xlsx_read_sheets($tmpName);
    $poRows = pi_sheet_records(
        $sheets['purchase order'] ?? [],
        ['invoice_no', 'tanggal', 'supplier', 'terima_stok'],
        'Purchase Order'
    );
    $itemRows = pi_sheet_records(
        $sheets['item po'] ?? [],
        ['invoice_no', 'sku', 'qty', 'harga_beli'],
        'Item PO'
    );
} catch (Throwable $error) {
    json_response(['error' => $error->getMessage()], 422);
}

if (!$poRows) {
    json_response(['error' => 'Sheet Purchase Order belum berisi data.'], 422);
}
if (!$itemRows) {
    json_response(['error' => 'Sheet Item PO belum berisi data.'], 422);
}
if (count($poRows) > 500) {
    json_response(['error' => 'Maksimal 500 purchase order per import.'], 422);
}
if (count($itemRows) > 3000) {
    json_response(['error' => 'Maksimal 3.000 item per import.'], 422);
}

/* ---------- Load products ---------- */

$stmt = $pdo->prepare("
    SELECT id, sku, name, cost_price, sold_by_weight, is_composite
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
");
$stmt->execute([':outlet_id' => $outletId]);
$productsBySku = [];
foreach ($stmt->fetchAll() as $product) {
    $productsBySku[strtolower((string) $product['sku'])] = $product;
}

/* ---------- Check duplicate invoice ---------- */

$invoiceCheck = $pdo->prepare("
    SELECT id
    FROM purchases
    WHERE outlet_id = :outlet_id
      AND invoice_no = :invoice_no
    LIMIT 1
");

/* ---------- Validate PO headers ---------- */

$errors = [];
$orders = [];
$fileInvoices = [];
foreach ($poRows as $row) {
    $rowNumber = (int) $row['_row'];
    $invoiceNo = trim((string) $row['invoice_no']);
    $invoiceKey = strtolower($invoiceNo);
    $purchasedAt = pi_mysql_datetime((string) $row['tanggal']);
    $receiveStock = pi_bool((string) $row['terima_stok']);

    if ($invoiceNo === '' || strlen($invoiceNo) > 60) {
        $errors[] = "PO baris {$rowNumber}: invoice_no wajib 1-60 karakter.";
        continue;
    }
    if (isset($fileInvoices[$invoiceKey])) {
        $errors[] = "PO baris {$rowNumber}: invoice_no {$invoiceNo} duplikat di file.";
        continue;
    }
    $invoiceCheck->execute([':outlet_id' => $outletId, ':invoice_no' => $invoiceNo]);
    if ($invoiceCheck->fetch()) {
        $errors[] = "PO baris {$rowNumber}: invoice_no {$invoiceNo} sudah ada.";
    }
    if (!$purchasedAt) {
        $errors[] = "PO baris {$rowNumber}: tanggal tidak valid.";
    }

    $fileInvoices[$invoiceKey] = true;
    $orders[$invoiceKey] = [
        'row' => $rowNumber,
        'invoice_no' => $invoiceNo,
        'purchased_at' => $purchasedAt,
        'supplier_name' => limited_text(trim((string) $row['supplier']), 140),
        'receive_stock' => $receiveStock,
        'items' => [],
    ];
}

/* ---------- Validate items ---------- */

foreach ($itemRows as $row) {
    $rowNumber = (int) $row['_row'];
    $invoiceNo = trim((string) $row['invoice_no']);
    $invoiceKey = strtolower($invoiceNo);
    $sku = trim((string) $row['sku']);
    $product = $productsBySku[strtolower($sku)] ?? null;
    $qty = (float) ($row['qty'] ?? 0);

    if (!isset($orders[$invoiceKey])) {
        $errors[] = "Item baris {$rowNumber}: invoice_no {$invoiceNo} tidak ditemukan pada sheet Purchase Order.";
        continue;
    }
    if (!$product) {
        $errors[] = "Item baris {$rowNumber}: SKU {$sku} tidak ditemukan pada produk aktif.";
        continue;
    }
    if ($qty <= 0) {
        $errors[] = "Item baris {$rowNumber}: qty wajib lebih dari 0.";
        continue;
    }
    if ((int) $product['is_composite'] === 1) {
        $errors[] = "Item baris {$rowNumber}: produk composite {$product['name']} tidak bisa di-PO.";
        continue;
    }
    if ((int) $product['sold_by_weight'] !== 1 && !quantity_is_whole($qty)) {
        $errors[] = "Item baris {$rowNumber}: qty {$product['name']} harus bilangan bulat.";
        continue;
    }

    $unitCost = trim((string) ($row['harga_beli'] ?? '')) !== ''
        ? max(0, (float) $row['harga_beli'])
        : (float) $product['cost_price'];

    $orders[$invoiceKey]['items'][] = [
        'row' => $rowNumber,
        'product_id' => (int) $product['id'],
        'product_name' => (string) $product['name'],
        'qty' => $qty,
        'unit_cost' => $unitCost,
        'subtotal' => $qty * $unitCost,
    ];
}

/* ---------- Validate each order has items ---------- */

foreach ($orders as $order) {
    if (!$order['items']) {
        $errors[] = "PO baris {$order['row']}: belum memiliki item.";
    }
}

if ($errors) {
    pi_error_response($errors);
}

/* ---------- Supplier lookup/insert ---------- */

$supplierFind = $pdo->prepare("
    SELECT id
    FROM suppliers
    WHERE outlet_id = :outlet_id
      AND name = :name
    ORDER BY id DESC
    LIMIT 1
");
$supplierInsert = $pdo->prepare("
    INSERT INTO suppliers (outlet_id, name)
    VALUES (:outlet_id, :name)
");

/* ---------- Insert POs ---------- */

$insertedCount = 0;
$itemCount = 0;
$stockReceivedCount = 0;
$pdo->beginTransaction();
try {
    foreach ($orders as $order) {
        $supplierId = null;
        if ($order['supplier_name'] !== '') {
            $supplierFind->execute([
                ':outlet_id' => $outletId,
                ':name' => $order['supplier_name'],
            ]);
            $supplierId = (int) ($supplierFind->fetch()['id'] ?? 0);
            if ($supplierId <= 0) {
                $supplierInsert->execute([
                    ':outlet_id' => $outletId,
                    ':name' => $order['supplier_name'],
                ]);
                $supplierId = (int) $pdo->lastInsertId();
            }
        }

        $totalAmount = 0.0;
        foreach ($order['items'] as $item) {
            $totalAmount += (float) $item['subtotal'];
        }

        $status = $order['receive_stock'] ? 'received' : 'ordered';
        $receivedAt = $order['receive_stock'] ? $order['purchased_at'] : null;

        $stmt = $pdo->prepare("
            INSERT INTO purchases (outlet_id, supplier_id, invoice_no, total_amount, status, purchased_at, received_at)
            VALUES (:outlet_id, :supplier_id, :invoice_no, :total_amount, :status, :purchased_at, :received_at)
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':supplier_id' => $supplierId,
            ':invoice_no' => $order['invoice_no'],
            ':total_amount' => $totalAmount,
            ':status' => $status,
            ':purchased_at' => $order['purchased_at'],
            ':received_at' => $receivedAt,
        ]);
        $purchaseId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare("
            INSERT INTO purchase_items (purchase_id, product_id, qty, unit_cost, subtotal)
            VALUES (:purchase_id, :product_id, :qty, :unit_cost, :subtotal)
        ");
        foreach ($order['items'] as $item) {
            $itemInsert->execute([
                ':purchase_id' => $purchaseId,
                ':product_id' => $item['product_id'],
                ':qty' => $item['qty'],
                ':unit_cost' => $item['unit_cost'],
                ':subtotal' => $item['subtotal'],
            ]);
            $itemCount++;
        }

        /* ---------- Apply stock if terima_stok = ya ---------- */

        if ($order['receive_stock']) {
            $totalQty = array_sum(array_map(fn ($i) => (float) $i['qty'], $order['items']));
            $extraCostPerPcs = 0.0;

            foreach ($order['items'] as $item) {
                $qty = (float) $item['qty'];
                $hppPo = (float) $item['unit_cost'] + $extraCostPerPcs;

                $prevStmt = $pdo->prepare("SELECT cost_price FROM products WHERE id = :id AND outlet_id = :outlet_id LIMIT 1");
                $prevStmt->execute([':id' => $item['product_id'], ':outlet_id' => $outletId]);
                $previousCost = (float) ($prevStmt->fetch()['cost_price'] ?? 0);
                $newCost = $previousCost > 0 ? (($hppPo + $previousCost) / 2) : $hppPo;

                $stockUpdate = $pdo->prepare("
                    UPDATE products
                    SET stock_qty = stock_qty + :qty,
                        cost_price = :cost_price
                    WHERE id = :product_id
                      AND outlet_id = :outlet_id
                ");
                $stockUpdate->execute([
                    ':qty' => $qty,
                    ':cost_price' => $newCost,
                    ':product_id' => $item['product_id'],
                    ':outlet_id' => $outletId,
                ]);
            }
            $stockReceivedCount++;
        }

        $insertedCount++;
    }

    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    json_response(['error' => 'Import dibatalkan: ' . $error->getMessage()], 422);
}

audit_log($pdo, $outletId, $userId, 'purchase_import', 'purchase', null, 'Purchase order diimpor dari Excel.', [
    'purchases' => $insertedCount,
    'items' => $itemCount,
    'stock_received' => $stockReceivedCount,
]);

json_response([
    'message' => "{$insertedCount} purchase order berhasil diimpor.",
    'purchases' => $insertedCount,
    'items' => $itemCount,
    'stock_received' => $stockReceivedCount,
], 201);
