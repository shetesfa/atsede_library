<?php
/**
 * tools/import_books_from_docx.php
 * Imports books and categories from Word (.docx) document into Atsede Library database.
 * Supports clean replacement (--apply) with standard Amharic category names and unique copy codes.
 */

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

function normalize_category_name(string $rawHeading, int $tableNo): string {
    $heading = trim(preg_replace('/\s+/u', ' ', $rawHeading));
    
    if (mb_strpos($heading, 'አንድምታ') !== false) {
        return 'የአንድምታ መጽሐፍት ክፍል';
    }
    if (mb_strpos($heading, 'ገድላት') !== false) {
        return 'የገድላትና ድርሳናት መጽሐፍት ክፍል';
    }
    if (mb_strpos($heading, 'መሰረተ ሃይማኖት') !== false || mb_strpos($heading, 'መሠረተ ሃይማኖት') !== false) {
        return 'የመሰረተ ሃይማኖት ክፍል';
    }
    if (mb_strpos($heading, 'ትምህርት እና ምክር') !== false || mb_strpos($heading, 'ትምህርትና ምክር') !== false) {
        return 'የትምህርት እና ምክር አዘል ክፍል';
    }
    if (mb_strpos($heading, 'ታሪክ') !== false) {
        return 'የታሪክ መጽሐፍት ክፍል';
    }
    if (mb_strpos($heading, 'ሥነምግባር') !== false || mb_strpos($heading, 'ስነምግባር') !== false) {
        return 'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'ኮርስ መስጫ') !== false) {
        return 'የኮርስ መስጫ መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'ሥርዓተ ቤተክርስቲያን') !== false || mb_strpos($heading, 'ስርዓተ ቤተክርስቲያን') !== false) {
        return 'የሥርዓተ ቤተክርስቲያን መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'ሺኖዳ') !== false) {
        return 'የአቡነ ሺኖዳ መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'ጸሎት') !== false && mb_strpos($heading, 'ዜማ') !== false) {
        return 'የጸሎትና የዜማ መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'መጽሐፍ ቅዱስ') !== false) {
        return 'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል';
    }
    if (mb_strpos($heading, 'ሥርጉተ ሥላሴ') !== false || mb_strpos($heading, 'ስርጉተ ስላሴ') !== false) {
        return 'የሥርጉተ ሥላሴ መጽሐፍት ክፍል';
    }
    
    // Fallback extraction
    $marker = 'ሰ/ት/ቤት';
    $start = mb_strpos($heading, $marker);
    $end = mb_strpos($heading, 'ክፍል', $start === false ? 0 : $start);
    if ($start !== false && $end !== false && $end > $start) {
        $start += mb_strlen($marker);
        $name = trim(mb_substr($heading, $start, $end - $start));
        $cat = preg_replace('/\s+/u', ' ', $name . ' ክፍል');
        $cat = str_replace('መጻፍሕፍት', 'መጻሕፍት', $cat);
        if (!preg_match('/^የ/u', $cat)) {
            $cat = 'የ' . $cat;
        }
        return $cat;
    }

    return 'ምድብ ' . $tableNo;
}

function get_category_metadata(string $catName): array {
    $meta = [
        'የአንድምታ መጽሐፍት ክፍል' => [
            'icon' => 'bi-journal-text',
            'desc' => 'የብሉያት፣ ሐዲሳትና ሊቃውንት አንድምታ ትርጓሜያት'
        ],
        'የገድላትና ድርሳናት መጽሐፍት ክፍል' => [
            'icon' => 'bi-shield-check',
            'desc' => 'የቅዱሳን ገድላት፣ ድርሳናትና ተአምራት'
        ],
        'የመሰረተ ሃይማኖት ክፍል' => [
            'icon' => 'bi-mortarboard',
            'desc' => 'ዶግማ፣ ቀኖና እና የመሰረተ ሃይማኖት ትምህርቶች'
        ],
        'የትምህርት እና ምክር አዘል ክፍል' => [
            'icon' => 'bi-lightbulb',
            'desc' => 'መንፈሳዊ ምክር፣ ተግሳጽ እና ሕይወታዊ ትምህርቶች'
        ],
        'የታሪክ መጽሐፍት ክፍል' => [
            'icon' => 'bi-hourglass-split',
            'desc' => 'የቤተክርስቲያን፣ ገዳማትና ቅዱሳን ታሪክ'
        ],
        'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል' => [
            'icon' => 'bi-heart',
            'desc' => 'ክርስቲያናዊ ምግባር፣ ጾም፣ ጸሎትና ንስሐ'
        ],
        'የኮርስ መስጫ መጻሕፍት ክፍል' => [
            'icon' => 'bi-journal-bookmark',
            'desc' => 'የሰንበት ትምህርት ቤት ኮርሶችና ማስተማሪያዎች'
        ],
        'የሥርዓተ ቤተክርስቲያን መጻሕፍት ክፍል' => [
            'icon' => 'bi-bank',
            'desc' => 'ፍትሐ ነገሥት፣ ቃለ አዋዲና የቤተክርስቲያን ሥርዓት'
        ],
        'የአቡነ ሺኖዳ መጻሕፍት ክፍል' => [
            'icon' => 'bi-person-check',
            'desc' => 'በብፁዕ ወቅዱስ አቡነ ሺኖዳ የተዘጋጁ ድርሰቶችና ትርጉሞች'
        ],
        'የጸሎትና የዜማ መጻሕፍት ክፍል' => [
            'icon' => 'bi-music-note-beamed',
            'desc' => 'ቅዳሴ፣ ጸዋትወ ዜማ፣ ድጓና የጸሎት መጻሕፍት'
        ],
        'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል' => [
            'icon' => 'bi-book',
            'desc' => 'ብሉይና ሐዲስ ኪዳን መጻሕፍት ቅዱሳት'
        ],
        'የሥርጉተ ሥላሴ መጽሐፍት ክፍል' => [
            'icon' => 'bi-collection',
            'desc' => 'የሥርጉተ ሥላሴ ልዩ የመጽሐፍት ስብስብ'
        ],
    ];

    return $meta[$catName] ?? ['icon' => 'bi-book', 'desc' => $catName];
}

function parse_codes(string $codes): array {
    $codes = trim($codes);
    if ($codes === '') return [];

    // Handle range e.g. 27A-27J or 48A-48E
    if (preg_match('/(\d+)\s*([A-Za-z])\s*[-–—]\s*(?:\1)?\s*([A-Za-z])/u', $codes, $rm)) {
        $num = $rm[1];
        $startChar = ord(strtoupper($rm[2]));
        $endChar = ord(strtoupper($rm[3]));
        $out = [];
        for ($c = $startChar; $c <= $endChar; $c++) {
            $out[] = sprintf('%02d%c', (int)$num, $c);
        }
        return $out;
    }

    // Match individual codes: e.g. "08 A", "08B", "12A", "13 B", "01", "27A"
    preg_match_all('/\d+\s*[A-Za-z]?/u', $codes, $matches);
    $out = [];
    foreach ($matches[0] as $m) {
        $clean = trim(preg_replace('/\s+/u', '', $m));
        if ($clean !== '') {
            $out[] = $clean;
        }
    }

    return array_values(array_unique($out));
}

function parse_year_and_qty(?string $yearRaw, ?string $qtyRaw, array $codes): array {
    $yearRaw = trim($yearRaw ?? '');
    $qtyRaw = trim($qtyRaw ?? '');
    
    $pubYear = null;
    $quantity = 1;

    // Check if yearRaw has 4-digit Ethiopian/Gregorian year (e.g. 1980 - 2030)
    if (preg_match('/\b(?:19|20)\d{2}\b/u', $yearRaw, $ym)) {
        $pubYear = $ym[0];
    }

    // Determine quantity
    if (preg_match('/\b\d+\b/u', $qtyRaw, $qm)) {
        $q = (int)$qm[0];
        if ($q > 0 && $q <= 100) {
            $quantity = $q;
        }
    } elseif ($pubYear === null && preg_match('/^[1-9]\d?$/u', $yearRaw, $sm)) {
        // Year column was used for quantity (e.g. Table 26)
        $quantity = (int)$sm[0];
    } elseif (count($codes) > 0) {
        $quantity = count($codes);
    }

    if (count($codes) > $quantity) {
        $quantity = count($codes);
    }

    return [$pubYear, $quantity];
}

function first_money(?string $value): ?float {
    if ($value && preg_match('/\d+(?:\.\d+)?/u', $value, $m)) {
        return (float)$m[0];
    }
    return 0.00;
}

function generate_codes(array $existingCodes, int $quantity, string $categoryPrefix, int $bookIndex): array {
    $result = array_values(array_unique($existingCodes));
    
    if (empty($result)) {
        if ($quantity === 1) {
            $result[] = sprintf('%s-%03d', $categoryPrefix, $bookIndex);
        } else {
            for ($i = 1; $i <= $quantity; $i++) {
                $suffix = chr(64 + $i);
                $result[] = sprintf('%s-%03d%s', $categoryPrefix, $bookIndex, $suffix);
            }
        }
    } else {
        // If we have fewer codes than quantity, expand
        $base = preg_replace('/[A-Za-z]$/u', '', $result[0]);
        for ($i = count($result) + 1; $i <= $quantity; $i++) {
            $candidate = sprintf('%s%c', $base, 64 + $i);
            $sub = 1;
            while (in_array($candidate, $result, true)) {
                $candidate = sprintf('%s-%02d', $base, $sub++);
            }
            $result[] = $candidate;
        }
    }

    // Final deduplication guarantee within this book
    $final = [];
    foreach ($result as $c) {
        $clean = $c;
        $counter = 1;
        while (in_array($clean, $final, true)) {
            $clean = $c . '-' . ($counter++);
        }
        $final[] = $clean;
    }

    return array_slice($final, 0, $quantity);
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
    $catBookCounters = [];

    // Category code prefixes for clean barcodes
    $catPrefixes = [
        'የአንድምታ መጽሐፍት ክፍል' => 'AND',
        'የገድላትና ድርሳናት መጽሐፍት ክፍል' => 'GED',
        'የመሰረተ ሃይማኖት ክፍል' => 'MES',
        'የትምህርት እና ምክር አዘል ክፍል' => 'TEM',
        'የታሪክ መጽሐፍት ክፍል' => 'TAR',
        'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል' => 'SNE',
        'የኮርስ መስጫ መጻሕፍት ክፍል' => 'CRS',
        'የሥርዓተ ቤተክርስቲያን መጻሕፍት ክፍል' => 'SER',
        'የአቡነ ሺኖዳ መጻሕፍት ክፍል' => 'SHN',
        'የጸሎትና የዜማ መጻሕፍት ክፍል' => 'ZEM',
        'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል' => 'KDS',
        'የሥርጉተ ሥላሴ መጽሐፍት ክፍል' => 'SRG',
    ];

    foreach ($body->childNodes as $child) {
        if (!$child instanceof DOMElement) continue;
        if ($child->localName === 'p') {
            $text = cell_text($xp, $child);
            if ($text !== '' && mb_strpos($text, 'ክፍል') !== false && (mb_strpos($text, 'ቤተመጽሐፍት') !== false || mb_strpos($text, 'መመዝገቢያ') !== false)) {
                $currentHeading = $text;
            }
            continue;
        }
        if ($child->localName !== 'tbl') continue;

        $tableNo++;
        $category = normalize_category_name($currentHeading, $tableNo);
        $prefix = $catPrefixes[$category] ?? sprintf('CAT%02d', $tableNo);

        $rowNo = 0;
        foreach ($xp->query('./w:tr', $child) as $row) {
            $rowNo++;
            if ($rowNo === 1) continue; // Skip header row
            $cells = [];
            foreach ($xp->query('./w:tc', $row) as $cell) {
                $cells[] = cell_text($xp, $cell);
            }
            $title = trim($cells[1] ?? '');
            if ($title === '') continue; // Skip empty rows

            $catBookCounters[$category] = ($catBookCounters[$category] ?? 0) + 1;
            $bookIdx = $catBookCounters[$category];

            $rawCodes = parse_codes($cells[7] ?? '');
            list($year, $quantity) = parse_year_and_qty($cells[3] ?? '', $cells[4] ?? '', $rawCodes);
            $codes = generate_codes($rawCodes, $quantity, $prefix, $bookIdx);

            $books[] = [
                'category' => $category,
                'title' => $title,
                'author' => trim($cells[2] ?? ''),
                'publication_year' => $year,
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
$categoryCopies = [];
foreach ($books as $book) {
    $categoryCounts[$book['category']] = ($categoryCounts[$book['category']] ?? 0) + 1;
    $categoryCopies[$book['category']] = ($categoryCopies[$book['category']] ?? 0) + $book['quantity'];
}

echo "========================================================\n";
echo "ATSDE LIBRARY - DOCX IMPORT PREVIEW\n";
echo "========================================================\n";
echo "Total Categories: " . count($categoryCounts) . "\n";
echo "Total Books:      " . count($books) . "\n";
echo "Total Copies:     " . array_sum(array_column($books, 'quantity')) . "\n";
echo "--------------------------------------------------------\n";
$i = 1;
foreach ($categoryCounts as $category => $count) {
    $copies = $categoryCopies[$category];
    printf("%2d. %-36s | %3d መጽሐፍት | %3d ቅጂዎች\n", $i++, $category, $count, $copies);
}
echo "========================================================\n";

if (!$apply) {
    echo "\n[INFO] Dry run completed. To execute and replace database data, run:\n";
    echo "       php tools/import_books_from_docx.php \"{$docxPath}\" --apply\n\n";
    exit(0);
}

$borrowed = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) AS c FROM borrow_records WHERE status='borrowed'"))['c'] ?? 0;
if ((int)$borrowed > 0) {
    fwrite(STDERR, "Import aborted: active borrow records exist ({$borrowed} books currently borrowed).\n");
    exit(1);
}

echo "\nExecuting database replacement...\n";

mysqli_begin_transaction($conn);
try {
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=0");
    mysqli_query($conn, "DELETE FROM borrow_requests");
    mysqli_query($conn, "DELETE FROM favorites");
    mysqli_query($conn, "DELETE FROM book_availability_alerts");
    mysqli_query($conn, "DELETE FROM book_copies");
    mysqli_query($conn, "DELETE FROM books");
    mysqli_query($conn, "DELETE FROM categories");
    mysqli_query($conn, "ALTER TABLE book_copies AUTO_INCREMENT=1");
    mysqli_query($conn, "ALTER TABLE books AUTO_INCREMENT=1");
    mysqli_query($conn, "ALTER TABLE categories AUTO_INCREMENT=1");
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");

    $categoryIds = [];
    $categoryStmt = mysqli_prepare($conn, "INSERT INTO categories (name, description, icon) VALUES (?, ?, ?)");
    foreach (array_keys($categoryCounts) as $category) {
        $meta = get_category_metadata($category);
        mysqli_stmt_bind_param($categoryStmt, 'sss', $category, $meta['desc'], $meta['icon']);
        mysqli_stmt_execute($categoryStmt);
        $categoryIds[$category] = mysqli_insert_id($conn);
    }

    $shelfLayout = [
        // የቀኝ ጎን መደርደሪያ
        'የኮርስ መስጫ መጻሕፍት ክፍል' => ['shelf_id' => 2, 'position' => '3ኛ ረድፍ (ላይኛ)'],
        'የአቡነ ሺኖዳ መጻሕፍት ክፍል' => ['shelf_id' => 2, 'position' => '3ኛ ረድፍ (ላይኛ - ጎን)'],
        'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል' => ['shelf_id' => 2, 'position' => '2ኛ ረድፍ (መካከለኛ)'],

        // የፊት ጎን መደርደሪያ
        'የአንድምታ መጽሐፍት ክፍል' => ['shelf_id' => 1, 'position' => '3ኛ ረድፍ (ላይኛ - ግራ)'],
        'የገድላትና ድርሳናት መጽሐፍት ክፍል' => ['shelf_id' => 1, 'position' => '3ኛ ረድፍ (ላይኛ - መካከለኛ)'],
        'የታሪክ መጽሐፍት ክፍል' => ['shelf_id' => 1, 'position' => '3ኛ ረድፍ (ላይኛ - ቀኝ)'],
        
        'የመሰረተ ሃይማኖት ክፍል' => ['shelf_id' => 1, 'position' => '2ኛ ረድፍ (መካከለኛ - ግራ)'],
        'የትምህርት እና ምክር አዘል ክፍል' => ['shelf_id' => 1, 'position' => '2ኛ ረድፍ (መካከለኛ - መካከለኛ)'],
        'የጸሎትና የዜማ መጻሕፍት ክፍል' => ['shelf_id' => 1, 'position' => '2ኛ ረድፍ (መካከለኛ - ቀኝ)'],
        
        'የሥርጉተ ሥላሴ መጽሐፍት ክፍል' => ['shelf_id' => 1, 'position' => '1ኛ ረድፍ (ታችኛ - ግራ እና መካከለኛ)'],
        'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል' => ['shelf_id' => 1, 'position' => '1ኛ ረድፍ (ታችኛ - ቀኝ)'],
        'የሥርዓተ ቤተክርስቲያን መጻሕፍት ክፍል' => ['shelf_id' => 1, 'position' => 'ያልተመደበ'],
    ];

    $bookStmt = mysqli_prepare($conn, "INSERT INTO books (title, author, category_id, quantity, publication_year, publisher, price, borrow_status, is_borrowable, room_id, shelf_id, position) VALUES (?, ?, ?, ?, ?, ?, ?, 'available', 1, 1, ?, ?)");
    $copyStmt = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code, qr_identifier, status) VALUES (?, ?, ?, 'available')");

    $insertedBooks = 0;
    $insertedCopies = 0;

    foreach ($books as $book) {
        $categoryId = $categoryIds[$book['category']];
        $year = $book['publication_year'];
        $price = $book['price'];
        $shelfId = $shelfLayout[$book['category']]['shelf_id'] ?? 1;
        $position = $shelfLayout[$book['category']]['position'] ?? '1ኛ ረድፍ';
        
        mysqli_stmt_bind_param(
            $bookStmt,
            'ssiissdis',
            $book['title'],
            $book['author'],
            $categoryId,
            $book['quantity'],
            $year,
            $book['publisher'],
            $price,
            $shelfId,
            $position
        );
        mysqli_stmt_execute($bookStmt);
        $bookId = mysqli_insert_id($conn);
        $insertedBooks++;

        foreach ($book['codes'] as $code) {
            $qrIdentifier = 'ATS-COPY-' . strtoupper(substr(md5($bookId . '_' . $code . '_' . bin2hex(random_bytes(6))), 0, 12));
            mysqli_stmt_bind_param($copyStmt, 'iss', $bookId, $code, $qrIdentifier);
            mysqli_stmt_execute($copyStmt);
            $insertedCopies++;
        }
    }

    mysqli_commit($conn);
    echo "\nSUCCESS: Database successfully replaced!\n";
    echo "  - Categories created: " . count($categoryIds) . "\n";
    echo "  - Books inserted:     " . $insertedBooks . "\n";
    echo "  - Book copies created: " . $insertedCopies . "\n\n";
} catch (Throwable $e) {
    mysqli_rollback($conn);
    mysqli_query($conn, "SET FOREIGN_KEY_CHECKS=1");
    fwrite(STDERR, "Import failed: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n");
    exit(1);
}
