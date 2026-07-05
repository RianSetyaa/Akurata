<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$action = (string) ($_GET['action'] ?? 'import');

function sales_import_excel_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function sales_import_excel_xml_value($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sales_import_sheet_xml(array $rows): string
{
    $xmlRows = [];
    $numericHeaders = ['qty', 'harga_jual', 'diskon_persen', 'harga_modal', 'dp', 'pajak_persen'];
    $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $rows[0] ?? []);

    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $reference = sales_import_excel_column_name($columnIndex + 1) . ($rowIndex + 1);
            $isNumericColumn = in_array($headers[$columnIndex] ?? '', $numericHeaders, true);
            if ($rowIndex > 0 && $isNumericColumn && is_numeric($value) && $value !== '') {
                $cells[] = '<c r="' . $reference . '"><v>' . sales_import_excel_xml_value($value) . '</v></c>';
            } else {
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t>'
                    . sales_import_excel_xml_value($value) . '</t></is></c>';
            }
        }
        $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $lastColumn = sales_import_excel_column_name(count($rows[0] ?? []));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="' . count($rows[0] ?? []) . '" width="20" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . '1"/>'
        . '</worksheet>';
}

function send_sales_import_template(): void
{
    if (!class_exists('ZipArchive')) {
        json_response(['error' => 'Extension PHP zip belum aktif.'], 500);
    }

    $saleHeaders = [[
        'invoice_no',
        'tanggal',
        'pelanggan',
        'no_hp',
        'metode_bayar',
        'jatuh_tempo',
        'dp',
        'metode_dp',
        'pajak_persen',
        'potong_stok',
    ]];
    $itemHeaders = [[
        'invoice_no',
        'sku',
        'qty',
        'harga_jual',
        'diskon_persen',
        'harga_modal',
    ]];
    $saleExamples = [
        $saleHeaders[0],
        ['INV-LAMA-001', '2026-06-01 10:30:00', 'Budi', '08123456789', 'cash', '', 0, '', 0, 'tidak'],
        ['INV-LAMA-002', '2026-06-02 14:15:00', 'Siti', '08111111111', 'tempo', '2026-06-09', 50000, 'transfer', 11, 'ya'],
    ];
    $itemExamples = [
        $itemHeaders[0],
        ['INV-LAMA-001', 'BATERAI-9V', 2, 10000, 0, 7000],
        ['INV-LAMA-001', 'KABEL-USB', 1, 25000, 10, 15000],
        ['INV-LAMA-002', 'BERAS-5KG', 1, 75000, 0, 65000],
    ];

    $temp = tempnam(sys_get_temp_dir(), 'akurata-sales-');
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
        . '<sheets><sheet name="Penjualan" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Item Penjualan" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Contoh Penjualan" sheetId="3" r:id="rId3"/>'
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
    $zip->addFromString('xl/worksheets/sheet1.xml', sales_import_sheet_xml($saleHeaders));
    $zip->addFromString('xl/worksheets/sheet2.xml', sales_import_sheet_xml($itemHeaders));
    $zip->addFromString('xl/worksheets/sheet3.xml', sales_import_sheet_xml($saleExamples));
    $zip->addFromString('xl/worksheets/sheet4.xml', sales_import_sheet_xml($itemExamples));
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-import-penjualan-akurata.xlsx"');
    header('Content-Length: ' . filesize($temp));
    readfile($temp);
    @unlink($temp);
    exit;
}

function sales_import_normalized_zip_path(string $base, string $target): string
{
    $parts = explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : $base . '/' . $target);
    $result = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') {
            continue;
        }
        if ($part === '..') {
            array_pop($result);
            continue;
        }
        $result[] = $part;
    }
    return implode('/', $result);
}

function sales_import_xlsx_shared_strings(ZipArchive $zip): array
{
    $content = $zip->getFromName('xl/sharedStrings.xml');
    if ($content === false) {
        return [];
    }
    $xml = simplexml_load_string($content);
    if (!$xml) {
        throw new RuntimeException('Shared strings Excel tidak valid.');
    }

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

function sales_import_xlsx_sheet_rows(ZipArchive $zip, string $path, array $sharedStrings): array
{
    $stat = $zip->statName($path);
    if (!$stat || (int) ($stat['size'] ?? 0) > 20 * 1024 * 1024) {
        throw new RuntimeException('Sheet Excel terlalu besar atau tidak ditemukan.');
    }
    $content = $zip->getFromName($path);
    $xml = $content !== false ? simplexml_load_string($content) : false;
    if (!$xml) {
        throw new RuntimeException('Isi sheet Excel tidak valid.');
    }

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
            if ($column <= 0) {
                continue;
            }

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
            $rows[] = [
                'number' => (int) ((string) ($row->attributes()['r'] ?? count($rows) + 1)),
                'values' => $values,
            ];
        }
    }
    return $rows;
}

function sales_import_xlsx_read_sheets(string $path): array
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
            $targets[(string) $attrs['Id']] = sales_import_normalized_zip_path('xl', (string) $attrs['Target']);
        }

        $sharedStrings = sales_import_xlsx_shared_strings($zip);
        $sheets = [];
        foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relationAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationId = (string) ($relationAttrs['id'] ?? '');
            $sheetPath = $targets[$relationId] ?? '';
            if ($sheetPath !== '') {
                $sheets[strtolower(trim((string) $attrs['name']))] = sales_import_xlsx_sheet_rows($zip, $sheetPath, $sharedStrings);
            }
        }
        return $sheets;
    } finally {
        $zip->close();
    }
}

function sales_import_sheet_records(array $rows, array $requiredHeaders, string $sheetName): array
{
    if (!$rows) {
        throw new RuntimeException("Sheet {$sheetName} kosong.");
    }
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

function sales_import_error_response(array $errors): void
{
    $visible = array_slice($errors, 0, 8);
    $message = implode(' ', $visible);
    if (count($errors) > count($visible)) {
        $message .= ' Dan ' . (count($errors) - count($visible)) . ' error lainnya.';
    }
    json_response(['error' => $message, 'errors' => $errors], 422);
}

function sales_import_bool(string $value): bool
{
    $normalized = strtolower(trim($value));
    return in_array($normalized, ['1', 'ya', 'yes', 'y', 'true', 'potong'], true);
}

function sales_import_payment_method(string $value): ?string
{
    $normalized = strtolower(trim($value));
    return match ($normalized) {
        '', 'cash', 'tunai', 'kas' => 'cash',
        'qris', 'qr' => 'qris',
        'transfer', 'bank' => 'transfer',
        'tempo', 'piutang', 'hutang' => 'tempo',
        default => null,
    };
}

function sales_import_mysql_datetime(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $serial = (float) $value;
        if ($serial > 0) {
            $seconds = (int) round(($serial - 25569) * 86400);
            return gmdate('Y-m-d H:i:s', $seconds);
        }
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return null;
    }
    return date('Y-m-d H:i:s', $timestamp);
}

function sales_import_mysql_date(string $value): ?string
{
    $datetime = sales_import_mysql_datetime($value);
    return $datetime !== null ? substr($datetime, 0, 10) : null;
}

if ($action === 'template') {
    require_method('GET');
    send_sales_import_template();
}

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
    $sheets = sales_import_xlsx_read_sheets($tmpName);
    $saleRows = sales_import_sheet_records(
        $sheets['penjualan'] ?? [],
        ['invoice_no', 'tanggal', 'pelanggan', 'no_hp', 'metode_bayar', 'jatuh_tempo', 'dp', 'metode_dp', 'pajak_persen', 'potong_stok'],
        'Penjualan'
    );
    $itemRows = sales_import_sheet_records(
        $sheets['item penjualan'] ?? [],
        ['invoice_no', 'sku', 'qty', 'harga_jual', 'diskon_persen', 'harga_modal'],
        'Item Penjualan'
    );
} catch (Throwable $error) {
    json_response(['error' => $error->getMessage()], 422);
}

if (!$saleRows) {
    json_response(['error' => 'Sheet Penjualan belum berisi data.'], 422);
}
if (!$itemRows) {
    json_response(['error' => 'Sheet Item Penjualan belum berisi data.'], 422);
}
if (count($saleRows) > 500) {
    json_response(['error' => 'Maksimal 500 invoice per import.'], 422);
}
if (count($itemRows) > 3000) {
    json_response(['error' => 'Maksimal 3.000 item per import.'], 422);
}

$stmt = $pdo->prepare("
    SELECT id, sku, name, stock_qty, cost_price, sale_price, sold_by_weight, is_composite
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
");
$stmt->execute([':outlet_id' => $outletId]);
$productsBySku = [];
foreach ($stmt->fetchAll() as $product) {
    $productsBySku[strtolower((string) $product['sku'])] = $product;
}

$componentStmt = $pdo->prepare("
    SELECT pc.product_id, pc.component_product_id, pc.quantity, p.name, p.stock_qty, p.cost_price
    FROM product_components pc
    JOIN products p ON p.id = pc.component_product_id
    WHERE pc.outlet_id = :outlet_id
      AND p.is_active = 1
");
$componentStmt->execute([':outlet_id' => $outletId]);
$componentsByProduct = [];
foreach ($componentStmt->fetchAll() as $component) {
    $componentsByProduct[(int) $component['product_id']][] = $component;
}

$invoiceCheck = $pdo->prepare("
    SELECT id
    FROM transactions
    WHERE outlet_id = :outlet_id
      AND invoice_no = :invoice_no
    LIMIT 1
");

$errors = [];
$sales = [];
$fileInvoices = [];
foreach ($saleRows as $row) {
    $rowNumber = (int) $row['_row'];
    $invoiceNo = trim((string) $row['invoice_no']);
    $invoiceKey = strtolower($invoiceNo);
    $paymentMethod = sales_import_payment_method((string) $row['metode_bayar']);
    $downPaymentMethod = trim((string) $row['metode_dp']) !== ''
        ? sales_import_payment_method((string) $row['metode_dp'])
        : 'cash';
    $soldAt = sales_import_mysql_datetime((string) $row['tanggal']);
    $dueDate = sales_import_mysql_date((string) $row['jatuh_tempo']);
    $downPayment = max(0, (float) ($row['dp'] ?? 0));
    $taxRate = trim((string) ($row['pajak_persen'] ?? '')) !== ''
        ? max(0, min(100, (float) $row['pajak_persen']))
        : null;

    if ($invoiceNo === '' || strlen($invoiceNo) > 40) {
        $errors[] = "Penjualan baris {$rowNumber}: invoice_no wajib 1-40 karakter.";
        continue;
    }
    if (isset($fileInvoices[$invoiceKey])) {
        $errors[] = "Penjualan baris {$rowNumber}: invoice_no {$invoiceNo} duplikat di file.";
        continue;
    }
    $invoiceCheck->execute([
        ':outlet_id' => $outletId,
        ':invoice_no' => $invoiceNo,
    ]);
    if ($invoiceCheck->fetch()) {
        $errors[] = "Penjualan baris {$rowNumber}: invoice_no {$invoiceNo} sudah ada.";
    }
    if (!$soldAt) {
        $errors[] = "Penjualan baris {$rowNumber}: tanggal tidak valid.";
    }
    if (!$paymentMethod) {
        $errors[] = "Penjualan baris {$rowNumber}: metode_bayar tidak valid.";
    }
    if ($downPaymentMethod === 'tempo') {
        $errors[] = "Penjualan baris {$rowNumber}: metode_dp tidak boleh tempo.";
    }
    if ($paymentMethod !== 'tempo' && $downPayment > 0) {
        $errors[] = "Penjualan baris {$rowNumber}: DP hanya digunakan untuk penjualan tempo.";
    }

    $fileInvoices[$invoiceKey] = true;
    $sales[$invoiceKey] = [
        'row' => $rowNumber,
        'invoice_no' => $invoiceNo,
        'sold_at' => $soldAt,
        'customer_name' => limited_text(trim((string) $row['pelanggan']), 140),
        'customer_phone' => limited_text(trim((string) $row['no_hp']), 40),
        'payment_method' => $paymentMethod ?? 'cash',
        'due_date' => $dueDate,
        'down_payment' => $downPayment,
        'down_payment_method' => $downPaymentMethod ?: 'cash',
        'tax_rate' => $taxRate,
        'apply_stock' => sales_import_bool((string) $row['potong_stok']),
        'items' => [],
    ];
}

foreach ($itemRows as $row) {
    $rowNumber = (int) $row['_row'];
    $invoiceNo = trim((string) $row['invoice_no']);
    $invoiceKey = strtolower($invoiceNo);
    $sku = trim((string) $row['sku']);
    $product = $productsBySku[strtolower($sku)] ?? null;
    $qty = (float) ($row['qty'] ?? 0);
    $discountRate = max(0, min(100, (float) ($row['diskon_persen'] ?? 0)));

    if (!isset($sales[$invoiceKey])) {
        $errors[] = "Item baris {$rowNumber}: invoice_no {$invoiceNo} tidak ditemukan pada sheet Penjualan.";
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
    if ((int) $product['is_composite'] !== 1 && (int) $product['sold_by_weight'] !== 1 && !quantity_is_whole($qty)) {
        $errors[] = "Item baris {$rowNumber}: qty {$product['name']} harus bilangan bulat.";
    }

    $unitPrice = trim((string) ($row['harga_jual'] ?? '')) !== ''
        ? max(0, (float) $row['harga_jual'])
        : (float) $product['sale_price'];
    $unitCost = trim((string) ($row['harga_modal'] ?? '')) !== ''
        ? max(0, (float) $row['harga_modal'])
        : (float) $product['cost_price'];

    if ((int) $product['is_composite'] === 1) {
        $components = $componentsByProduct[(int) $product['id']] ?? [];
        if (!$components) {
            $errors[] = "Item baris {$rowNumber}: produk paket {$product['name']} belum memiliki komponen.";
        }
        if (trim((string) ($row['harga_modal'] ?? '')) === '') {
            $unitCost = 0.0;
            foreach ($components as $component) {
                $unitCost += (float) $component['cost_price'] * (float) $component['quantity'];
            }
        }
    }

    $grossSubtotal = $unitPrice * $qty;
    $discountAmount = round($grossSubtotal * $discountRate / 100, 2);
    $subtotal = max(0, $grossSubtotal - $discountAmount);
    $sales[$invoiceKey]['items'][] = [
        'row' => $rowNumber,
        'product_id' => (int) $product['id'],
        'product_name' => (string) $product['name'],
        'qty' => $qty,
        'unit_price' => $unitPrice,
        'unit_cost' => $unitCost,
        'discount_rate' => $discountRate,
        'discount_amount' => $discountAmount,
        'subtotal' => $subtotal,
    ];
}

$stockRequirements = [];
foreach ($sales as $sale) {
    if (!$sale['items']) {
        $errors[] = "Penjualan baris {$sale['row']}: belum memiliki item.";
        continue;
    }
    if ($sale['payment_method'] === 'tempo' && $sale['customer_name'] === '') {
        $errors[] = "Penjualan baris {$sale['row']}: pelanggan wajib diisi untuk transaksi tempo.";
    }
    if ($sale['payment_method'] === 'tempo' && !$sale['due_date']) {
        $errors[] = "Penjualan baris {$sale['row']}: jatuh_tempo wajib diisi untuk transaksi tempo.";
    }

    if (!$sale['apply_stock']) {
        continue;
    }

    foreach ($sale['items'] as $item) {
        $productId = (int) $item['product_id'];
        $product = null;
        foreach ($productsBySku as $lookupProduct) {
            if ((int) $lookupProduct['id'] === $productId) {
                $product = $lookupProduct;
                break;
            }
        }
        if (!$product) {
            continue;
        }

        if ((int) $product['is_composite'] === 1) {
            foreach ($componentsByProduct[$productId] ?? [] as $component) {
                $componentId = (int) $component['component_product_id'];
                $stockRequirements[$componentId]['qty'] = ($stockRequirements[$componentId]['qty'] ?? 0) + ((float) $component['quantity'] * (float) $item['qty']);
                $stockRequirements[$componentId]['name'] = (string) $component['name'];
            }
        } else {
            $stockRequirements[$productId]['qty'] = ($stockRequirements[$productId]['qty'] ?? 0) + (float) $item['qty'];
            $stockRequirements[$productId]['name'] = (string) $product['name'];
        }
    }
}

if ($stockRequirements) {
    $stockStmt = $pdo->prepare("
        SELECT id, stock_qty
        FROM products
        WHERE outlet_id = :outlet_id
          AND id = :id
        LIMIT 1
    ");
    foreach ($stockRequirements as $productId => $requirement) {
        $stockStmt->execute([
            ':outlet_id' => $outletId,
            ':id' => $productId,
        ]);
        $stock = $stockStmt->fetch();
        if (!$stock || (float) $stock['stock_qty'] < (float) $requirement['qty']) {
            $errors[] = 'Stok ' . $requirement['name'] . ' tidak cukup untuk import yang memotong stok.';
        }
    }
}

if ($errors) {
    sales_import_error_response($errors);
}

$customerFind = $pdo->prepare("
    SELECT id
    FROM customers
    WHERE outlet_id = :outlet_id
      AND name = :name
      AND (:phone_value = '' OR phone = :phone)
    ORDER BY id DESC
    LIMIT 1
");
$customerInsert = $pdo->prepare("
    INSERT INTO customers (outlet_id, name, phone)
    VALUES (:outlet_id, :name, :phone)
");

$insertedCount = 0;
$itemCount = 0;
$receivableCount = 0;
$stockAppliedCount = 0;
$pdo->beginTransaction();
try {
    foreach ($sales as $sale) {
        $customerId = null;
        if ($sale['customer_name'] !== '') {
            $customerFind->execute([
                ':outlet_id' => $outletId,
                ':name' => $sale['customer_name'],
                ':phone' => $sale['customer_phone'],
                ':phone_value' => $sale['customer_phone'],
            ]);
            $customerId = (int) ($customerFind->fetch()['id'] ?? 0);
            if ($customerId <= 0) {
                $customerInsert->execute([
                    ':outlet_id' => $outletId,
                    ':name' => $sale['customer_name'],
                    ':phone' => $sale['customer_phone'] !== '' ? $sale['customer_phone'] : null,
                ]);
                $customerId = (int) $pdo->lastInsertId();
            }
        }

        $grossAmount = 0.0;
        $discountAmount = 0.0;
        $subtotalAmount = 0.0;
        $costAmount = 0.0;
        foreach ($sale['items'] as $item) {
            $grossAmount += (float) $item['unit_price'] * (float) $item['qty'];
            $discountAmount += (float) $item['discount_amount'];
            $subtotalAmount += (float) $item['subtotal'];
            $costAmount += (float) $item['unit_cost'] * (float) $item['qty'];
        }

        if ($sale['tax_rate'] !== null) {
            $taxRate = (float) $sale['tax_rate'];
            $taxAmount = round($subtotalAmount * $taxRate / 100, 2);
            $grandTotal = $subtotalAmount + $taxAmount;
        } else {
            $tax = tax_breakdown($pdo, $outletId, $subtotalAmount);
            $taxRate = (float) $tax['tax_rate'];
            $taxAmount = (float) $tax['tax_amount'];
            $grandTotal = (float) $tax['total_amount'];
        }

        if ((float) $sale['down_payment'] > $grandTotal) {
            throw new RuntimeException('DP invoice ' . $sale['invoice_no'] . ' tidak boleh lebih besar dari total transaksi.');
        }

        $isTempo = $sale['payment_method'] === 'tempo';
        $paymentStatus = $isTempo && (float) $sale['down_payment'] < $grandTotal ? 'receivable' : 'paid';
        $stmt = $pdo->prepare("
            INSERT INTO transactions (
                outlet_id, user_id, customer_id, invoice_no, payment_method, payment_status,
                subtotal_amount, discount_amount, tax_rate, tax_amount, total_amount, cost_amount,
                source, sold_at, stock_applied_at
            )
            VALUES (
                :outlet_id, :user_id, :customer_id, :invoice_no, :payment_method, :payment_status,
                :subtotal_amount, :discount_amount, :tax_rate, :tax_amount, :total_amount, :cost_amount,
                'import', :sold_at, :stock_applied_at
            )
        ");
        $stmt->execute([
            ':outlet_id' => $outletId,
            ':user_id' => $userId,
            ':customer_id' => $customerId ?: null,
            ':invoice_no' => $sale['invoice_no'],
            ':payment_method' => $sale['payment_method'],
            ':payment_status' => $paymentStatus,
            ':subtotal_amount' => $subtotalAmount,
            ':discount_amount' => $discountAmount,
            ':tax_rate' => $taxRate,
            ':tax_amount' => $taxAmount,
            ':total_amount' => $grandTotal,
            ':cost_amount' => $costAmount,
            ':sold_at' => $sale['sold_at'],
            ':stock_applied_at' => $sale['apply_stock'] ? date('Y-m-d H:i:s') : null,
        ]);
        $transactionId = (int) $pdo->lastInsertId();

        $itemInsert = $pdo->prepare("
            INSERT INTO transaction_items (
                transaction_id, product_id, qty, unit_price, unit_cost,
                discount_rate, discount_amount, subtotal
            )
            VALUES (
                :transaction_id, :product_id, :qty, :unit_price, :unit_cost,
                :discount_rate, :discount_amount, :subtotal
            )
        ");
        foreach ($sale['items'] as $item) {
            $itemInsert->execute([
                ':transaction_id' => $transactionId,
                ':product_id' => $item['product_id'],
                ':qty' => $item['qty'],
                ':unit_price' => $item['unit_price'],
                ':unit_cost' => $item['unit_cost'],
                ':discount_rate' => $item['discount_rate'],
                ':discount_amount' => $item['discount_amount'],
                ':subtotal' => $item['subtotal'],
            ]);
            $itemCount++;
        }

        if ($sale['apply_stock']) {
            $saleStockRequirements = [];
            foreach ($sale['items'] as $item) {
                $productId = (int) $item['product_id'];
                $product = null;
                foreach ($productsBySku as $lookupProduct) {
                    if ((int) $lookupProduct['id'] === $productId) {
                        $product = $lookupProduct;
                        break;
                    }
                }
                if (!$product) {
                    continue;
                }
                if ((int) $product['is_composite'] === 1) {
                    foreach ($componentsByProduct[$productId] ?? [] as $component) {
                        $componentId = (int) $component['component_product_id'];
                        $saleStockRequirements[$componentId] = ($saleStockRequirements[$componentId] ?? 0) + ((float) $component['quantity'] * (float) $item['qty']);
                    }
                } else {
                    $saleStockRequirements[$productId] = ($saleStockRequirements[$productId] ?? 0) + (float) $item['qty'];
                }
            }
            $stockUpdate = $pdo->prepare("
                UPDATE products
                SET stock_qty = stock_qty - :qty
                WHERE outlet_id = :outlet_id
                  AND id = :product_id
            ");
            foreach ($saleStockRequirements as $productId => $qty) {
                $stockUpdate->execute([
                    ':outlet_id' => $outletId,
                    ':product_id' => $productId,
                    ':qty' => $qty,
                ]);
                notify_low_stock_if_needed($pdo, $outletId, $productId, $qty);
            }
            $stockAppliedCount++;
        }

        if ($isTempo) {
            $receivableStatus = match (true) {
                (float) $sale['down_payment'] >= $grandTotal => 'paid',
                (float) $sale['down_payment'] > 0 => 'partial',
                default => 'open',
            };
            $receivableInsert = $pdo->prepare("
                INSERT INTO receivables (outlet_id, transaction_id, customer_id, amount, paid_amount, due_date, status, paid_at)
                VALUES (:outlet_id, :transaction_id, :customer_id, :amount, :paid_amount, :due_date, :status, :paid_at)
            ");
            $receivableInsert->execute([
                ':outlet_id' => $outletId,
                ':transaction_id' => $transactionId,
                ':customer_id' => $customerId ?: null,
                ':amount' => $grandTotal,
                ':paid_amount' => $sale['down_payment'],
                ':due_date' => $sale['due_date'],
                ':status' => $receivableStatus,
                ':paid_at' => $receivableStatus === 'paid' ? $sale['sold_at'] : null,
            ]);

            $receivableId = (int) $pdo->lastInsertId();
            if ((float) $sale['down_payment'] > 0) {
                $paymentNo = receivable_payment_number($pdo, $outletId);
                $paymentInsert = $pdo->prepare("
                    INSERT INTO receivable_payments (outlet_id, receivable_id, user_id, payment_no, payment_method, amount, notes, paid_at)
                    VALUES (:outlet_id, :receivable_id, :user_id, :payment_no, :payment_method, :amount, :notes, :paid_at)
                ");
                $paymentInsert->execute([
                    ':outlet_id' => $outletId,
                    ':receivable_id' => $receivableId,
                    ':user_id' => $userId,
                    ':payment_no' => $paymentNo,
                    ':payment_method' => $sale['down_payment_method'],
                    ':amount' => $sale['down_payment'],
                    ':notes' => 'DP import penjualan ' . $sale['invoice_no'],
                    ':paid_at' => $sale['sold_at'],
                ]);
            }
            $receivableCount++;
        }

        $insertedCount++;
    }

    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    json_response(['error' => 'Import dibatalkan: ' . $error->getMessage()], 422);
}

audit_log($pdo, $outletId, $userId, 'sales_import', 'transaction', null, 'Penjualan sebelumnya diimpor dari Excel.', [
    'transactions' => $insertedCount,
    'items' => $itemCount,
    'receivables' => $receivableCount,
    'stock_applied' => $stockAppliedCount,
]);

json_response([
    'message' => "{$insertedCount} invoice penjualan berhasil diimpor.",
    'transactions' => $insertedCount,
    'items' => $itemCount,
    'receivables' => $receivableCount,
    'stock_applied' => $stockAppliedCount,
], 201);
