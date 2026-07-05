<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$action = (string) ($_GET['action'] ?? 'import');

function excel_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function excel_xml_value($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function excel_sheet_xml(array $rows): string
{
    $xmlRows = [];
    $numericHeaders = ['stok', 'min_stok', 'harga_beli', 'harga_jual', 'qty'];
    $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $rows[0] ?? []);
    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $reference = excel_column_name($columnIndex + 1) . ($rowIndex + 1);
            $isNumericColumn = in_array($headers[$columnIndex] ?? '', $numericHeaders, true);
            if ($rowIndex > 0 && $isNumericColumn && is_numeric($value) && $value !== '') {
                $cells[] = '<c r="' . $reference . '"><v>' . excel_xml_value($value) . '</v></c>';
            } else {
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t>'
                    . excel_xml_value($value) . '</t></is></c>';
            }
        }
        $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $lastColumn = excel_column_name(count($rows[0] ?? []));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="' . count($rows[0] ?? []) . '" width="18" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . '1"/>'
        . '</worksheet>';
}

function send_product_template(): void
{
    if (!class_exists('ZipArchive')) {
        json_response(['error' => 'Extension PHP zip belum aktif.'], 500);
    }

    $productHeaders = [
        ['sku', 'barcode', 'nama', 'kategori', 'jenis_produk', 'tipe_jual', 'stok', 'min_stok', 'harga_beli', 'harga_jual'],
    ];
    $componentHeaders = [
        ['sku_paket', 'sku_komponen', 'qty'],
    ];
    $productExamples = [
        ['sku', 'barcode', 'nama', 'kategori', 'jenis_produk', 'tipe_jual', 'stok', 'min_stok', 'harga_beli', 'harga_jual'],
        ['ROKOK-A-BTG', '899000000001', 'Rokok A - Batang', 'Rokok', 'biasa', 'satuan', 120, 12, 1500, 2000],
        ['ROKOK-A-BKS', '899000000002', 'Rokok A - 1 Bungkus', 'Rokok', 'paket', 'satuan', 0, 0, 0, 24000],
        ['BERAS-5KG', '899000000003', 'Beras Premium 5 Kg', 'Sembako', 'biasa', 'volume', 50, 5, 65000, 75000],
    ];
    $componentExamples = [
        ['sku_paket', 'sku_komponen', 'qty'],
        ['ROKOK-A-BKS', 'ROKOK-A-BTG', 12],
    ];

    $temp = tempnam(sys_get_temp_dir(), 'akurata-products-');
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
        . '<sheets><sheet name="Produk" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Komponen" sheetId="2" r:id="rId2"/>'
        . '<sheet name="Contoh Produk" sheetId="3" r:id="rId3"/>'
        . '<sheet name="Contoh Komponen" sheetId="4" r:id="rId4"/></sheets></workbook>');
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
    $zip->addFromString('xl/worksheets/sheet1.xml', excel_sheet_xml($productHeaders));
    $zip->addFromString('xl/worksheets/sheet2.xml', excel_sheet_xml($componentHeaders));
    $zip->addFromString('xl/worksheets/sheet3.xml', excel_sheet_xml($productExamples));
    $zip->addFromString('xl/worksheets/sheet4.xml', excel_sheet_xml($componentExamples));
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-import-produk-akurata.xlsx"');
    header('Content-Length: ' . filesize($temp));
    readfile($temp);
    @unlink($temp);
    exit;
}

function normalized_zip_path(string $base, string $target): string
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

function xlsx_shared_strings(ZipArchive $zip): array
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

function xlsx_sheet_rows(ZipArchive $zip, string $path, array $sharedStrings): array
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

function xlsx_read_sheets(string $path): array
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
            $targets[(string) $attrs['Id']] = normalized_zip_path('xl', (string) $attrs['Target']);
        }

        $sharedStrings = xlsx_shared_strings($zip);
        $sheets = [];
        foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relationAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationId = (string) ($relationAttrs['id'] ?? '');
            $sheetPath = $targets[$relationId] ?? '';
            if ($sheetPath !== '') {
                $sheets[strtolower(trim((string) $attrs['name']))] = xlsx_sheet_rows($zip, $sheetPath, $sharedStrings);
            }
        }
        return $sheets;
    } finally {
        $zip->close();
    }
}

function sheet_records(array $rows, array $requiredHeaders, string $sheetName): array
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

function import_error_response(array $errors): void
{
    $visible = array_slice($errors, 0, 8);
    $message = implode(' ', $visible);
    if (count($errors) > count($visible)) {
        $message .= ' Dan ' . (count($errors) - count($visible)) . ' error lainnya.';
    }
    json_response(['error' => $message, 'errors' => $errors], 422);
}

if ($action === 'template') {
    require_method('GET');
    send_product_template();
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
    $sheets = xlsx_read_sheets($tmpName);
    $productRows = sheet_records(
        $sheets['produk'] ?? [],
        ['sku', 'barcode', 'nama', 'kategori', 'jenis_produk', 'tipe_jual', 'stok', 'min_stok', 'harga_beli', 'harga_jual'],
        'Produk'
    );
    $componentRows = sheet_records(
        $sheets['komponen'] ?? [],
        ['sku_paket', 'sku_komponen', 'qty'],
        'Komponen'
    );
} catch (Throwable $error) {
    json_response(['error' => $error->getMessage()], 422);
}

if (!$productRows) {
    json_response(['error' => 'Sheet Produk belum berisi data.'], 422);
}
if (count($productRows) > 1000) {
    json_response(['error' => 'Maksimal 1.000 produk per import.'], 422);
}

$stmt = $pdo->prepare("
    SELECT id, sku, barcode, name, cost_price, is_composite
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
");
$stmt->execute([':outlet_id' => $outletId]);
$existingBySku = [];
$existingBarcodes = [];
foreach ($stmt->fetchAll() as $product) {
    $existingBySku[strtolower((string) $product['sku'])] = $product;
    if (!empty($product['barcode'])) {
        $existingBarcodes[(string) $product['barcode']] = true;
    }
}

$errors = [];
$products = [];
$fileBarcodes = [];
foreach ($productRows as $row) {
    $rowNumber = (int) $row['_row'];
    $sku = trim((string) $row['sku']);
    $key = strtolower($sku);
    $name = trim((string) $row['nama']);
    $barcode = trim((string) $row['barcode']);
    $kind = strtolower(trim((string) $row['jenis_produk']));
    $sellType = strtolower(trim((string) $row['tipe_jual']));
    $isComposite = in_array($kind, ['paket', 'composite'], true);
    $isRegular = in_array($kind, ['biasa', 'regular'], true);
    $soldByWeight = in_array($sellType, ['volume', 'berat'], true);

    if ($sku === '' || strlen($sku) > 40) {
        $errors[] = "Produk baris {$rowNumber}: SKU wajib 1-40 karakter.";
    } elseif (isset($products[$key]) || isset($existingBySku[$key])) {
        $errors[] = "Produk baris {$rowNumber}: SKU {$sku} sudah digunakan.";
    }
    if ($name === '' || strlen($name) > 64) {
        $errors[] = "Produk baris {$rowNumber}: nama wajib 1-64 karakter.";
    }
    if (!$isComposite && !$isRegular) {
        $errors[] = "Produk baris {$rowNumber}: jenis_produk harus biasa atau paket.";
    }
    if (!in_array($sellType, ['satuan', 'each', 'volume', 'berat'], true)) {
        $errors[] = "Produk baris {$rowNumber}: tipe_jual harus satuan atau volume.";
    }
    if ($barcode !== '') {
        if (strlen($barcode) > 80 || isset($fileBarcodes[$barcode]) || isset($existingBarcodes[$barcode])) {
            $errors[] = "Produk baris {$rowNumber}: barcode {$barcode} tidak valid atau sudah digunakan.";
        }
        $fileBarcodes[$barcode] = true;
    }

    $stock = $isComposite ? 0.0 : (float) $row['stok'];
    $minStock = $isComposite ? 0.0 : (float) $row['min_stok'];
    $costPrice = $isComposite ? 0.0 : (float) $row['harga_beli'];
    $salePrice = (float) $row['harga_jual'];
    if ($stock < 0 || $minStock < 0 || $costPrice < 0 || $salePrice < 0) {
        $errors[] = "Produk baris {$rowNumber}: stok dan harga tidak boleh negatif.";
    }
    if (!$isComposite && !$soldByWeight && (!quantity_is_whole($stock) || !quantity_is_whole($minStock))) {
        $errors[] = "Produk baris {$rowNumber}: stok produk satuan harus bilangan bulat.";
    }

    $products[$key] = [
        'row' => $rowNumber,
        'sku' => $sku,
        'barcode' => $barcode !== '' ? $barcode : null,
        'name' => $name,
        'category' => limited_text((string) $row['kategori'], 512),
        'stock_qty' => $stock,
        'min_stock' => $minStock,
        'cost_price' => $costPrice,
        'sale_price' => $salePrice,
        'sold_by_weight' => !$isComposite && $soldByWeight ? 1 : 0,
        'is_composite' => $isComposite ? 1 : 0,
        'components' => [],
    ];
}

foreach ($componentRows as $row) {
    $rowNumber = (int) $row['_row'];
    $packageKey = strtolower(trim((string) $row['sku_paket']));
    $componentKey = strtolower(trim((string) $row['sku_komponen']));
    $qty = (float) $row['qty'];

    if (!isset($products[$packageKey]) || (int) $products[$packageKey]['is_composite'] !== 1) {
        $errors[] = "Komponen baris {$rowNumber}: SKU paket tidak ditemukan atau bukan paket.";
        continue;
    }
    $component = $products[$componentKey] ?? $existingBySku[$componentKey] ?? null;
    if (!$component || (int) ($component['is_composite'] ?? 0) === 1) {
        $errors[] = "Komponen baris {$rowNumber}: SKU komponen tidak ditemukan atau merupakan paket.";
        continue;
    }
    if ($qty <= 0) {
        $errors[] = "Komponen baris {$rowNumber}: qty wajib lebih dari 0.";
        continue;
    }
    $products[$packageKey]['components'][$componentKey] =
        ($products[$packageKey]['components'][$componentKey] ?? 0) + $qty;
}

foreach ($products as $product) {
    if ((int) $product['is_composite'] === 1 && !$product['components']) {
        $errors[] = "Produk baris {$product['row']}: paket {$product['sku']} belum memiliki komponen.";
    }
}
if ($errors) {
    import_error_response($errors);
}

$insertedIds = [];
$regularCount = 0;
$compositeCount = 0;
$pdo->beginTransaction();
try {
    $insert = $pdo->prepare("
        INSERT INTO products (
          outlet_id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
          sold_by_weight, is_composite
        )
        VALUES (
          :outlet_id, :sku, :barcode, :name, :category, :stock_qty, :min_stock, :cost_price, :sale_price,
          :sold_by_weight, :is_composite
        )
    ");

    foreach ([0, 1] as $compositePass) {
        foreach ($products as $key => $product) {
            if ((int) $product['is_composite'] !== $compositePass) {
                continue;
            }

            $costPrice = (float) $product['cost_price'];
            if ($compositePass === 1) {
                $costPrice = 0.0;
                foreach ($product['components'] as $componentKey => $qty) {
                    $component = $products[$componentKey] ?? $existingBySku[$componentKey];
                    $costPrice += (float) $component['cost_price'] * $qty;
                }
            }

            $insert->execute([
                ':outlet_id' => $outletId,
                ':sku' => $product['sku'],
                ':barcode' => $product['barcode'],
                ':name' => $product['name'],
                ':category' => $product['category'],
                ':stock_qty' => $product['stock_qty'],
                ':min_stock' => $product['min_stock'],
                ':cost_price' => $costPrice,
                ':sale_price' => $product['sale_price'],
                ':sold_by_weight' => $product['sold_by_weight'],
                ':is_composite' => $product['is_composite'],
            ]);
            $insertedIds[$key] = (int) $pdo->lastInsertId();
            $products[$key]['id'] = $insertedIds[$key];
            $products[$key]['cost_price'] = $costPrice;
            $compositePass === 1 ? $compositeCount++ : $regularCount++;
        }
    }

    $componentInsert = $pdo->prepare("
        INSERT INTO product_components (outlet_id, product_id, component_product_id, quantity)
        VALUES (:outlet_id, :product_id, :component_product_id, :quantity)
    ");
    foreach ($products as $key => $product) {
        if ((int) $product['is_composite'] !== 1) {
            continue;
        }
        foreach ($product['components'] as $componentKey => $qty) {
            $componentId = $insertedIds[$componentKey] ?? (int) $existingBySku[$componentKey]['id'];
            $componentInsert->execute([
                ':outlet_id' => $outletId,
                ':product_id' => $insertedIds[$key],
                ':component_product_id' => $componentId,
                ':quantity' => $qty,
            ]);
        }
    }

    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    json_response(['error' => 'Import dibatalkan: ' . $error->getMessage()], 422);
}

audit_log($pdo, $outletId, $userId, 'product_import', 'product', null, 'Produk diimpor dari Excel.', [
    'regular' => $regularCount,
    'composite' => $compositeCount,
    'total' => $regularCount + $compositeCount,
]);

json_response([
    'message' => ($regularCount + $compositeCount) . ' produk berhasil diimpor.',
    'regular' => $regularCount,
    'composite' => $compositeCount,
    'total' => $regularCount + $compositeCount,
], 201);
