<?php
$sessionDir = __DIR__ . '/../tmp/sessions';
if (!is_dir($sessionDir)) {
    mkdir($sessionDir, 0775, true);
}
ini_set('session.save_path', $sessionDir);

require_once __DIR__ . '/../config.php';

$docxPath = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);

if ($docxPath === '' || !is_file($docxPath)) {
    fwrite(STDERR, "Usage: php tools/import_books_from_docx.php <path-to-docx> [--apply]\n");
    exit(1);
}

function cell_text(DOMXPath $xp, DOMNode $node): string {
    $parts = [];
    foreach ($xp->query('.//w:t', $node) as $textNode) {
        $parts[] = $textNode->textContent;
    }
    return trim(preg_replace('/\s+/u', ' ', implode('', $parts)));
}

function extract_category(string $heading, int $tableNo): string {
    $marker = 'ሰ/ት/ቤት';
    $start = strpos($heading, $marker);
    $end = strpos($heading, 'ክፍል', $start === false ? 0 : $start);
    if ($start !== false && $end !== false && $end > $start) {
        $start += strlen($marker);
        $name = trim(substr($heading, $start, $end - $start));
        return preg_replace('/\s+/u', ' ', $name . ' ክፍል');
    }
    return 'ምድብ ' . $tableNo;
}

function first_year(?string $value): ?string {
    if ($value && preg_match('/\d{4}/u', $value, $m)) {
        return $m[0];
    }
    return null;
}

function first_money(?string $value): ?float {
    if ($value && preg_match('/\d+(?:\.\d+)?/u', $value, $m)) {
        return (float)$m[0];
    }
    return null;
}

function split_codes(string $codes): array {
    $codes = trim($codes);
    if ($codes === '') return [];
    $parts = preg_split('/[፣,]+/u', $codes);
    $out = [];
    foreach ($parts as $part) {
        $code = trim(preg_replace('/\s+/u', ' ', $part));
        if ($code !== '') $out[] = $code;
    }
    return array_values(array_unique($out));
}

function quantity_from(string $quantity, array $codes): int {
    if (preg_match('/\d+/u', $quantity, $m) && (int)$m[0] > 0) {
        $value = (int)$m[0];
        if ($value <= 50) return $value;
    }
    if (count($codes) > 0) return count($codes);
    return 1;
}

function ensure_codes(array $codes, int $quantity, int $rowNo): array {
    $result = $codes;
    for ($i = count($result) + 1; $i <= $quantity; $i++) {
        $result[] = sprintf('AUTO-%03d-%02d', $rowNo, $i);
    }
    return array_slice($result, 0, $quantity);
}

function parse_docx_books(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open DOCX file.');
    }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('word/document.xml not found in DOCX.');
    }

    $dom = new DOMDocument();
    $dom->loadXML($xml);
    $xp = new DOMXPath($dom);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');

    $body = $xp->query('/w:document/w:body')->item(0);
    $currentHeading = '';
    $tableNo = 0;
    $books = [];
    $debug = getenv('IMPORT_DEBUG') === '1';

    foreach ($body->childNodes as $child) {
        if (!$child instanceof DOMElement) continue;
        if ($child->localName === 'p') {
            $text = cell_text($xp, $child);
            if ($text !== '' && strpos($text, 'ክፍል') !== false) {
                $currentHeading = $text;
                if ($debug) echo "HEADING: {$currentHeading}" . PHP_EOL;
            }
            continue;
        }
        if ($child->localName !== 'tbl') continue;

        $tableNo++;
        $category = extract_category($currentHeading, $tableNo);
        if ($debug) echo "TABLE {$tableNo}: {$category}" . PHP_EOL;
        $rowNo = 0;
        foreach ($xp->query('./w:tr', $child) as $row) {
            $rowNo++;
            if ($rowNo === 1) continue;
            $cells = [];
            foreach ($xp->query('./w:tc', $row) as $cell) {
                $cells[] = cell_text($xp, $cell);
            }
            $title = trim($cells[1] ?? '');
            if ($title === '') continue;

            $rawCodes = split_codes($cells[7] ?? '');
            $quantity = quantity_from($cells[4] ?? '', $rawCodes);
            $codes = ensure_codes($rawCodes, $quantity, $rowNo);

            $books[] = [
                'category' => $category,
                'title' => $title,
                'author' => trim($cells[2] ?? ''),
                'publication_year' => first_year($cells[3] ?? ''),
                'quantity' => $quantity,
                'price' => first_money($cells[5] ?? ''),
                'publisher' => trim($cells[6] ?? ''),
                'codes' => $codes,
            ];
        }
    }

    return $books;
}

$books = parse_docx_books($docxPath);
$categoryCounts = [];
foreach ($books as $book) {
    $categoryCounts[$book['category']] = ($categoryCounts[$book['category']] ?? 0) + 1;
}

echo "Parsed categories: " . count($categoryCounts) . PHP_EOL;
echo "Parsed books: " . count($books) . PHP_EOL;
echo "Parsed copies: " . array_sum(array_column($books, 'quantity')) . PHP_EOL;
foreach ($categoryCounts as $category => $count) {
    echo " - {$category}: {$count}" . PHP_EOL;
}

if (!$apply) {
    echo PHP_EOL . "Dry run only. Re-run with --apply to replace categories/books/book_copies." . PHP_EOL;
    exit(0);
}

$borrowed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM borrow_records WHERE status='borrowed'"))['c'] ?? 0;
if ((int)$borrowed > 0) {
    fwrite(STDERR, "Import aborted: active borrow records exist.\n");
    exit(1);
}

mysqli_begin_transaction($conn);
try {
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");
    mysqli_query($conn, "DELETE FROM borrow_requests");
    mysqli_query($conn, "DELETE FROM book_copies");
    mysqli_query($conn, "DELETE FROM books");
    mysqli_query($conn, "DELETE FROM categories");
    mysqli_query($conn, "ALTER TABLE book_copies AUTO_INCREMENT=1");
    mysqli_query($conn, "ALTER TABLE books AUTO_INCREMENT=1");
    mysqli_query($conn, "ALTER TABLE categories AUTO_INCREMENT=1");
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");

    $categoryIds = [];
    $categoryStmt = mysqli_prepare($conn, "INSERT INTO categories (name, description, icon) VALUES (?, ?, 'bi-book')");
    foreach (array_keys($categoryCounts) as $category) {
        $description = $category;
        mysqli_stmt_bind_param($categoryStmt, 'ss', $category, $description);
        mysqli_stmt_execute($categoryStmt);
        $categoryIds[$category] = mysqli_insert_id($conn);
    }

    $bookStmt = mysqli_prepare($conn, "INSERT INTO books (title, author, category_id, quantity, publication_year, publisher, price, borrow_status) VALUES (?, ?, ?, ?, ?, ?, ?, 'available')");
    $copyStmt = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code) VALUES (?, ?)");

    foreach ($books as $book) {
        $categoryId = $categoryIds[$book['category']];
        $year = $book['publication_year'];
        $price = $book['price'];
        mysqli_stmt_bind_param(
            $bookStmt,
            'ssiissd',
            $book['title'],
            $book['author'],
            $categoryId,
            $book['quantity'],
            $year,
            $book['publisher'],
            $price
        );
        mysqli_stmt_execute($bookStmt);
        $bookId = mysqli_insert_id($conn);
        foreach ($book['codes'] as $code) {
            mysqli_stmt_bind_param($copyStmt, 'is', $bookId, $code);
            mysqli_stmt_execute($copyStmt);
        }
    }

    mysqli_commit($conn);
    echo PHP_EOL . "Import applied successfully." . PHP_EOL;
} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
    fwrite(STDERR, "Import failed: " . $e->getMessage() . PHP_EOL);
    exit(1);
}
