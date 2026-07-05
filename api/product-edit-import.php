<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

$user = require_auth();
$outletId = (int) $user['outlet_id'];
$userId = (int) $user['id'];
$action = (string) ($_GET['action'] ?? 'import');

/* ---------- Excel helpers (prefixed to avoid collision with product-import.php) ---------- */

function pei_excel_column_name(int $index): string
{
    $name = '';
    while ($index > 0) {
        $index--;
        $name = chr(65 + ($index % 26)) . $name;
        $index = intdiv($index, 26);
    }
    return $name;
}

function pei_excel_xml_value($value): string
{
    return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function pei_excel_sheet_xml(array $rows): string
{
    $xmlRows = [];
    $numericHeaders = ['stok', 'min_stok', 'harga_beli', 'harga_jual'];
    $headers = array_map(static fn ($value) => strtolower(trim((string) $value)), $rows[0] ?? []);
    foreach ($rows as $rowIndex => $row) {
        $cells = [];
        foreach (array_values($row) as $columnIndex => $value) {
            $reference = pei_excel_column_name($columnIndex + 1) . ($rowIndex + 1);
            $isNumericColumn = in_array($headers[$columnIndex] ?? '', $numericHeaders, true);
            if ($rowIndex > 0 && $isNumericColumn && is_numeric($value) && $value !== '') {
                $cells[] = '<c r="' . $reference . '"><v>' . pei_excel_xml_value($value) . '</v></c>';
            } else {
                $style = $rowIndex === 0 ? ' s="1"' : '';
                $cells[] = '<c r="' . $reference . '" t="inlineStr"' . $style . '><is><t>'
                    . pei_excel_xml_value($value) . '</t></is></c>';
            }
        }
        $xmlRows[] = '<row r="' . ($rowIndex + 1) . '">' . implode('', $cells) . '</row>';
    }

    $lastColumn = pei_excel_column_name(count($rows[0] ?? []));
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<cols><col min="1" max="' . count($rows[0] ?? []) . '" width="18" customWidth="1"/></cols>'
        . '<sheetData>' . implode('', $xmlRows) . '</sheetData>'
        . '<autoFilter ref="A1:' . $lastColumn . '1"/>'
        . '</worksheet>';
}

function pei_normalized_zip_path(string $base, string $target): string
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

function pei_xlsx_shared_strings(ZipArchive $zip): array
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

function pei_xlsx_sheet_rows(ZipArchive $zip, string $path, array $sharedStrings): array
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

function pei_xlsx_read_sheets(string $path): array
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
            $targets[(string) $attrs['Id']] = pei_normalized_zip_path('xl', (string) $attrs['Target']);
        }

        $sharedStrings = pei_xlsx_shared_strings($zip);
        $sheets = [];
        foreach ($workbook->xpath('//*[local-name()="sheet"]') ?: [] as $sheet) {
            $attrs = $sheet->attributes();
            $relationAttrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
            $relationId = (string) ($relationAttrs['id'] ?? '');
            $sheetPath = $targets[$relationId] ?? '';
            if ($sheetPath !== '') {
                $sheets[strtolower(trim((string) $attrs['name']))] = pei_xlsx_sheet_rows($zip, $sheetPath, $sharedStrings);
            }
        }
        return $sheets;
    } finally {
        $zip->close();
    }
}

function pei_sheet_records(array $rows, array $requiredHeaders, string $sheetName): array
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

function pei_import_error_response(array $errors): void
{
    $visible = array_slice($errors, 0, 8);
    $message = implode(' ', $visible);
    if (count($errors) > count($visible)) {
        $message .= ' Dan ' . (count($errors) - count($visible)) . ' error lainnya.';
    }
    json_response(['error' => $message, 'errors' => $errors], 422);
}

/* ---------- Template: download pre-filled Excel ---------- */

if ($action === 'template') {
    require_method('GET');

    if (!class_exists('ZipArchive')) {
        json_response(['error' => 'Extension PHP zip belum aktif.'], 500);
    }

    $pdo = db();
    $stmt = $pdo->prepare("
        SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
               sold_by_weight, is_composite
        FROM products
        WHERE outlet_id = :outlet_id
          AND is_active = 1
        ORDER BY name ASC
    ");
    $stmt->execute([':outlet_id' => $outletId]);
    $products = $stmt->fetchAll();

    $productHeaders = [
        ['sku', 'barcode', 'nama', 'kategori', 'stok', 'min_stok', 'harga_beli', 'harga_jual', 'tipe_jual'],
    ];
    $productRows = [];
    foreach ($products as $product) {
        $isComposite = (int) $product['is_composite'] === 1;
        $sellType = $isComposite
            ? 'satuan'
            : ((int) $product['sold_by_weight'] === 1 ? 'volume' : 'satuan');
        $productRows[] = [
            $product['sku'],
            $product['barcode'] ?? '',
            $product['name'],
            $product['category'] ?? '',
            $isComposite ? '' : (string) $product['stock_qty'],
            $isComposite ? '' : (string) $product['min_stock'],
            $isComposite ? '' : (string) $product['cost_price'],
            (string) $product['sale_price'],
            $sellType,
        ];
    }

    if (!$productRows) {
        $productData = $productHeaders;
    } else {
        $productData = array_merge($productHeaders, $productRows);
    }

    $infoRows = [
        ['Panduan Edit Produk via Excel'],
        [''],
        ['Kolom', 'Keterangan'],
        ['sku', 'SKU produk (JANGAN diubah — dipakai sebagai kunci pencocokan)'],
        ['barcode', 'Barcode produk (boleh dikosongkan)'],
        ['nama', 'Nama produk (1-64 karakter)'],
        ['kategori', 'Kategori/deskripsi produk'],
        ['stok', 'Stok saat ini (kosongkan untuk produk paket)'],
        ['min_stok', 'Stok minimum (kosongkan untuk produk paket)'],
        ['harga_beli', 'Harga beli / HPP (kosongkan untuk produk paket)'],
        ['harga_jual', 'Harga jual'],
        ['tipe_jual', 'satuan atau volume (volume untuk qty desimal)'],
    ];

    $temp = tempnam(sys_get_temp_dir(), 'akurata-product-edit-');
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
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
        . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Edit Produk" sheetId="1" r:id="rId1"/>'
        . '<sheet name="Panduan" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
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
    $zip->addFromString('xl/worksheets/sheet1.xml', pei_excel_sheet_xml($productData));
    $zip->addFromString('xl/worksheets/sheet2.xml', pei_excel_sheet_xml($infoRows));
    $zip->close();

    header_remove('Content-Type');
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="template-edit-produk-akurata.xlsx"');
    header('Content-Length: ' . filesize($temp));
    readfile($temp);
    @unlink($temp);
    exit;
}

/* ---------- Import: process uploaded Excel ---------- */

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
    $sheets = pei_xlsx_read_sheets($tmpName);
    $productRows = pei_sheet_records(
        $sheets['edit produk'] ?? [],
        ['sku', 'nama', 'harga_jual'],
        'Edit Produk'
    );
} catch (Throwable $error) {
    json_response(['error' => $error->getMessage()], 422);
}

if (!$productRows) {
    json_response(['error' => 'Sheet Edit Produk belum berisi data.'], 422);
}
if (count($productRows) > 1000) {
    json_response(['error' => 'Maksimal 1.000 produk per import.'], 422);
}

/* Load existing products keyed by lowercase SKU */
$stmt = $pdo->prepare("
    SELECT id, sku, barcode, name, category, stock_qty, min_stock, cost_price, sale_price,
           sold_by_weight, is_composite
    FROM products
    WHERE outlet_id = :outlet_id
      AND is_active = 1
");
$stmt->execute([':outlet_id' => $outletId]);
$existingBySku = [];
$existingBarcodes = [];
foreach ($stmt->fetchAll() as $product) {
    $key = strtolower((string) $product['sku']);
    $existingBySku[$key] = $product;
    if (!empty($product['barcode'])) {
        $existingBarcodes[(string) $product['barcode']] = (int) $product['id'];
    }
}

$errors = [];
$updates = [];
$fileBarcodes = [];

foreach ($productRows as $row) {
    $rowNumber = (int) $row['_row'];
    $sku = trim((string) $row['sku']);
    $key = strtolower($sku);

    if ($sku === '' || strlen($sku) > 40) {
        $errors[] = "Baris {$rowNumber}: SKU wajib 1-40 karakter.";
        continue;
    }

    if (!isset($existingBySku[$key])) {
        $errors[] = "Baris {$rowNumber}: SKU {$sku} tidak ditemukan pada produk aktif.";
        continue;
    }

    $existing = $existingBySku[$key];
    $isComposite = (int) $existing['is_composite'] === 1;

    $name = trim((string) $row['nama']);
    $barcode = trim((string) ($row['barcode'] ?? ''));
    $category = trim((string) ($row['kategori'] ?? ''));
    $sellType = strtolower(trim((string) ($row['tipe_jual'] ?? '')));
    $stock = $isComposite ? 0.0 : (float) ($row['stok'] ?? 0);
    $minStock = $isComposite ? 0.0 : (float) ($row['min_stok'] ?? 0);
    $costPrice = $isComposite ? 0.0 : (float) ($row['harga_beli'] ?? 0);
    $salePrice = (float) $row['harga_jual'];

    if ($name === '' || strlen($name) > 64) {
        $errors[] = "Baris {$rowNumber}: nama wajib 1-64 karakter.";
    }
    if (strlen($category) > 512) {
        $errors[] = "Baris {$rowNumber}: kategori maksimal 512 karakter.";
    }
    if (!$isComposite && ($stock < 0 || $minStock < 0 || $costPrice < 0 || $salePrice < 0)) {
        $errors[] = "Baris {$rowNumber}: stok dan harga tidak boleh negatif.";
    }
    if ($salePrice < 0) {
        $errors[] = "Baris {$rowNumber}: harga jual tidak boleh negatif.";
    }

    $soldByWeight = (int) $existing['sold_by_weight'];
    if ($sellType !== '') {
        if (!in_array($sellType, ['satuan', 'each', 'volume', 'berat'], true)) {
            $errors[] = "Baris {$rowNumber}: tipe_jual harus satuan atau volume.";
        } else {
            $soldByWeight = in_array($sellType, ['volume', 'berat'], true) && !$isComposite ? 1 : 0;
        }
    }

    if (!$isComposite && !$soldByWeight && (!quantity_is_whole($stock) || !quantity_is_whole($minStock))) {
        $errors[] = "Baris {$rowNumber}: stok produk satuan harus bilangan bulat.";
    }

    if ($barcode !== '') {
        if (strlen($barcode) > 80) {
            $errors[] = "Baris {$rowNumber}: barcode maksimal 80 karakter.";
        } elseif (isset($fileBarcodes[$barcode])) {
            $errors[] = "Baris {$rowNumber}: barcode {$barcode} duplikat dalam file.";
        } elseif (isset($existingBarcodes[$barcode]) && $existingBarcodes[$barcode] !== (int) $existing['id']) {
            $errors[] = "Baris {$rowNumber}: barcode {$barcode} sudah digunakan produk lain.";
        } else {
            $fileBarcodes[$barcode] = true;
        }
    }

    $updates[$key] = [
        'id' => (int) $existing['id'],
        'sku' => $sku,
        'barcode' => $barcode !== '' ? $barcode : null,
        'name' => $name,
        'category' => limited_text($category, 512),
        'stock_qty' => $stock,
        'min_stock' => $minStock,
        'cost_price' => $costPrice,
        'sale_price' => $salePrice,
        'sold_by_weight' => $soldByWeight,
    ];
}

if ($errors) {
    pei_import_error_response($errors);
}

$updatedCount = 0;
$pdo->beginTransaction();
try {
    $update = $pdo->prepare("
        UPDATE products
        SET barcode = :barcode,
            name = :name,
            category = :category,
            stock_qty = :stock_qty,
            min_stock = :min_stock,
            cost_price = :cost_price,
            sale_price = :sale_price,
            sold_by_weight = :sold_by_weight,
            loyverse_synced_at = NULL
        WHERE id = :id
          AND outlet_id = :outlet_id
    ");

    foreach ($updates as $data) {
        $update->execute([
            ':id' => $data['id'],
            ':outlet_id' => $outletId,
            ':barcode' => $data['barcode'],
            ':name' => $data['name'],
            ':category' => $data['category'],
            ':stock_qty' => $data['stock_qty'],
            ':min_stock' => $data['min_stock'],
            ':cost_price' => $data['cost_price'],
            ':sale_price' => $data['sale_price'],
            ':sold_by_weight' => $data['sold_by_weight'],
        ]);
        $updatedCount++;
    }

    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    json_response(['error' => 'Edit batch dibatalkan: ' . $error->getMessage()], 422);
}

audit_log($pdo, $outletId, $userId, 'product_batch_edit', 'product', null, 'Produk diedit secara batch via Excel.', [
    'updated' => $updatedCount,
]);

json_response([
    'message' => $updatedCount . ' produk berhasil diperbarui.',
    'updated' => $updatedCount,
], 200);
