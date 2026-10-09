<?php
/**
 * print_shelf_labels.php — Printable Shelf Labels & Color-Coded Book Catalog
 * Ready for A4 Print (Ctrl + P) and direct Word (.docx) download.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Category metadata with exact colors matching 3D visualizer
$catMeta = [
    1 => [
        'num' => 1,
        'name' => 'የአንድምታ መጽሐፍት ክፍል',
        'color' => '#EAB308',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ግራ)'
    ],
    2 => [
        'num' => 2,
        'name' => 'የገድላትና ድርሳናት መጽሐፍት ክፍል',
        'color' => '#A855F7',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - መካከለኛ)'
    ],
    7 => [
        'num' => 3,
        'name' => 'የኮርስ መስጫ መጻሕፍት ክፍል',
        'color' => '#22C55E',
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ)'
    ],
    9 => [
        'num' => 4,
        'name' => 'የአቡነ ሺኖዳ መጻሕፍት ክፍል',
        'color' => '#06B6D4',
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ጎን)'
    ],
    4 => [
        'num' => 5,
        'name' => 'የትምህርት እና ምክር አዘል ክፍል',
        'color' => '#10B981',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - መካከለኛ)'
    ],
    3 => [
        'num' => 6,
        'name' => 'የመሰረተ ሃይማኖት ክፍል',
        'color' => '#EF4444',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - ግራ)'
    ],
    12 => [
        'num' => 7,
        'name' => 'የሥርጉተ ሥላሴ መጽሐፍት ክፍል',
        'color' => '#8B5CF6',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '1ኛ ረድፍ (ታችኛ - ግራ እና መካከለኛ)'
    ],
    5 => [
        'num' => 8,
        'name' => 'የታሪክ መጽሐፍት ክፍል',
        'color' => '#0284C7',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ቀኝ)'
    ],
    10 => [
        'num' => 9,
        'name' => 'የጸሎትና የዜማ መጻሕፍት ክፍል',
        'color' => '#F97316',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - ቀኝ)'
    ],
    6 => [
        'num' => 10,
        'name' => 'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል',
        'color' => '#EC4899',
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '1ኛ ረድፍ (ታችኛ - ቀኝ)'
    ],
    11 => [
        'num' => 11,
        'name' => 'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል',
        'color' => '#38BDF8',
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ)'
    ],
];

// Fetch books
$booksRes = mysqli_query($conn, "
    SELECT b.id, b.title, b.author, b.category_id, b.quantity, b.publication_year, b.price, b.position,
           GROUP_CONCAT(bc.copy_code ORDER BY bc.id SEPARATOR ', ') AS copies_info
    FROM books b
    LEFT JOIN book_copies bc ON bc.book_id = b.id
    GROUP BY b.id
    ORDER BY b.category_id ASC, b.title ASC
");

$booksByCat = [];
while ($b = mysqli_fetch_assoc($booksRes)) {
    $cId = (int)$b['category_id'];
    $booksByCat[$cId][] = $b;
}

$view = $_GET['view'] ?? 'labels'; // 'labels' or 'catalog'
?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<title>የመደርደሪያ መለያዎች እና የመጻሕፍት ማውጫ — አጸደ ቤተ-መጻሕፍት</title>
<link rel="stylesheet" href="assets/lib/fonts/fonts.css">
<link rel="stylesheet" href="assets/lib/bootstrap-icons/bootstrap-icons.css">
<!-- QR Code Library for dynamic high-res rendering -->
<script src="./assets/js/qrcode.min.js"></script>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    background: #F1F5F9;
    color: #0F172A;
    font-family: 'Noto Sans Ethiopic', sans-serif;
    padding: 20px;
    font-size: 13.5px;
  }

  /* No-print Action Toolbar */
  .no-print-bar {
    background: #0B2545;
    color: #fff;
    padding: 12px 20px;
    border-radius: 12px;
    margin-bottom: 24px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 16px;
    flex-wrap: wrap;
    box-shadow: 0 4px 15px rgba(0,0,0,0.15);
  }
  .no-print-bar a, .no-print-bar button {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: 8px;
    font-weight: 700;
    text-decoration: none;
    font-size: 0.88rem;
    cursor: pointer;
    border: none;
    font-family: inherit;
  }
  .btn-print { background: #C59B27; color: #fff; }
  .btn-print:hover { background: #b0891f; }
  .btn-word { background: #1E40AF; color: #fff; }
  .btn-switch { background: rgba(255,255,255,0.15); color: #fff; }
  .btn-switch.active { background: #fff; color: #0B2545; }

  /* Page Layout (A4 size standard) */
  .page-container {
    max-width: 900px;
    margin: 0 auto;
    background: #fff;
    padding: 30px;
    border-radius: 12px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.06);
  }

  /* ================= LABELS VIEW ================= */
  .labels-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 20px;
  }

  .shelf-label-card {
    border: 2px dashed #CBD5E1;
    border-radius: 14px;
    padding: 16px;
    position: relative;
    background: #fff;
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    page-break-inside: avoid;
    box-shadow: 0 2px 8px rgba(0,0,0,0.04);
  }
  .shelf-label-card::before {
    content: '✂️ ቁረጥ';
    position: absolute;
    top: -10px;
    right: 14px;
    font-size: 0.7rem;
    background: #fff;
    padding: 0 6px;
    color: #94A3B8;
  }

  .label-header {
    border-bottom: 2px solid var(--cat-color);
    padding-bottom: 8px;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
  }
  .cat-num-badge {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--cat-color);
    color: #fff;
    font-weight: 800;
    font-size: 0.85rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
  .cat-name-title {
    font-size: 1.05rem;
    font-weight: 800;
    color: #0F172A;
    line-height: 1.3;
  }

  .label-body {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .label-details {
    flex: 1;
    font-size: 0.84rem;
    line-height: 1.6;
  }
  .shelf-badge-loc {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 6px;
    background: rgba(0,0,0,0.05);
    font-weight: 700;
    color: var(--cat-color);
    margin-bottom: 4px;
  }
  .qr-wrapper {
    width: 95px;
    height: 95px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    border: 1px solid #E2E8F0;
    border-radius: 8px;
    padding: 4px;
  }
  .qr-wrapper canvas { width: 100% !important; height: 100% !important; }

  .label-footer {
    margin-top: 10px;
    padding-top: 6px;
    border-top: 1px dotted #E2E8F0;
    font-size: 0.72rem;
    color: #64748B;
    text-align: center;
  }

  /* ================= CATALOG VIEW ================= */
  .catalog-cat-section {
    margin-bottom: 28px;
    page-break-inside: avoid;
  }
  .cat-banner {
    background: var(--cat-color);
    color: #fff;
    padding: 10px 14px;
    border-radius: 8px 8px 0 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
  }
  .cat-banner-title {
    font-size: 1.05rem;
    font-weight: 800;
  }
  .cat-banner-sub {
    font-size: 0.82rem;
    opacity: 0.95;
  }
  .cat-banner-qr {
    width: 55px;
    height: 55px;
    background: #fff;
    border-radius: 6px;
    padding: 2px;
    display: flex;
    align-items: center;
    justify-content: center;
  }
  .cat-banner-qr canvas { width: 100% !important; height: 100% !important; }

  .books-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82rem;
    border: 1px solid #CBD5E1;
    border-top: none;
  }
  .books-table th {
    background: #0B2545;
    color: #fff;
    font-weight: 700;
    padding: 6px 10px;
    text-align: left;
    font-size: 0.8rem;
  }
  .books-table td {
    padding: 6px 10px;
    border-bottom: 1px solid #E2E8F0;
    vertical-align: top;
  }
  .books-table tr:nth-child(even) { background: #F8FAFC; }
  .code-pill {
    display: inline-block;
    padding: 1px 6px;
    border-radius: 4px;
    background: rgba(11, 37, 69, 0.08);
    font-family: monospace;
    font-weight: 700;
    color: #0B2545;
    font-size: 0.76rem;
  }

  /* Print Media Query */
  @media print {
    body { background: #fff; padding: 0; }
    .no-print-bar { display: none !important; }
    .page-container { box-shadow: none; padding: 0; max-width: 100%; }
    .shelf-label-card { box-shadow: none; border-color: #94A3B8; }
  }
</style>
</head>
<body>

<!-- Navigation / Action Bar -->
<div class="no-print-bar">
  <div style="display:flex;align-items:center;gap:12px;">
    <span style="font-weight:800;font-size:1.05rem;">🖨️ የቤተ-መጻሕፍት የህትመት ማዕከል</span>
    <a href="?view=labels" class="btn-switch <?= $view === 'labels' ? 'active' : '' ?>">
      <i class="bi bi-tag"></i> የመደርደሪያ መለያዎች (Labels)
    </a>
    <a href="?view=catalog" class="btn-switch <?= $view === 'catalog' ? 'active' : '' ?>">
      <i class="bi bi-journal-text"></i> ሙሉ የመጻሕፍት ማውጫ (Catalog)
    </a>
  </div>

  <div style="display:flex;align-items:center;gap:10px;">
    <a href="docs/የመደርደሪያ_መለያ_ካርዶች_ከነQR_ኮድ.docx" class="btn-word" download>
      <i class="bi bi-file-earmark-word"></i> መለያዎች (Word)
    </a>
    <a href="docs/የቤተመጽሐፍት_መጽሐፍት_ሙሉ_ማውጫ_በቀለም.docx" class="btn-word" download>
      <i class="bi bi-file-earmark-word"></i> ማውጫ (Word)
    </a>
    <button type="button" class="btn-print" onclick="window.print()">
      <i class="bi bi-printer"></i> አትም / Save as PDF
    </button>
  </div>
</div>

<div class="page-container">

  <?php if ($view === 'labels'): ?>
    <!-- ================= LABELS VIEW ================= -->
    <div style="text-align:center;margin-bottom:20px;">
      <h1 style="font-family:'Noto Serif Ethiopic',serif;font-size:1.4rem;color:#0B2545;">የአጸደ ትጉሃን ሰ/ት/ቤት ቤተ-መጻሕፍት</h1>
      <p style="color:#64748B;font-size:.88rem;margin-top:2px;">ለመደርደሪያ የሚለጠፉ የመለያ ካርዶች ከነ QR ኮድ (Shelf Tags with QR)</p>
    </div>

    <div class="labels-grid">
      <?php foreach ($catMeta as $catId => $m): ?>
        <?php 
          $bList = $booksByCat[$catId] ?? [];
          $copyCount = array_sum(array_column($bList, 'quantity'));
        ?>
        <div class="shelf-label-card" style="--cat-color: <?= $m['color'] ?>;">
          <div class="label-header">
            <div class="cat-num-badge"><?= $m['num'] ?></div>
            <div class="cat-name-title"><?= htmlspecialchars($m['name']) ?></div>
          </div>
          <div class="label-body">
            <div class="label-details">
              <div class="shelf-badge-loc">📍 <?= $m['shelf'] ?></div>
              <div style="font-weight:700;color:#334155;margin-bottom:4px;"><?= $m['level'] ?></div>
              <div style="color:#64748B;">📚 <strong><?= count($bList) ?></strong> መጻሕፍት | <strong><?= $copyCount ?></strong> ቅጂዎች</div>
            </div>
            <div class="qr-wrapper" id="qr-box-<?= $catId ?>"></div>
          </div>
          <div class="label-footer">
            📱 በስልክ ካሜራ ስካን በማድረግ የዚህን መደርደሪያ መጻሕፍት ይመልከቱ ✂️
          </div>
        </div>
      <?php endforeach; ?>
    </div>

  <?php else: ?>
    <!-- ================= CATALOG VIEW ================= -->
    <div style="text-align:center;margin-bottom:24px;">
      <h1 style="font-family:'Noto Serif Ethiopic',serif;font-size:1.5rem;color:#0B2545;">የአጸደ ትጉሃን ሰ/ት/ቤት ቤተ-መጻሕፍት</h1>
      <p style="font-weight:700;color:#0284C7;font-size:1.05rem;margin-top:2px;">የመጻሕፍት ሙሉ ማውጫ ዝርዝር (Color-Coded Catalog)</p>
      <p style="color:#64748B;font-size:.82rem;margin-top:2px;">በየመደርደሪያውና በየምድቡ ቀለም ተለይቶ የተዘጋጀ የእጅ መፈለጊያ ማውጫ</p>
    </div>

    <?php foreach ($catMeta as $catId => $m): ?>
      <?php 
        $bList = $booksByCat[$catId] ?? [];
        if (empty($bList)) continue;
      ?>
      <div class="catalog-cat-section" style="--cat-color: <?= $m['color'] ?>;">
        <div class="cat-banner">
          <div>
            <div class="cat-banner-title">[<?= $m['num'] ?>] <?= htmlspecialchars($m['name']) ?></div>
            <div class="cat-banner-sub">📍 <?= $m['shelf'] ?> • <?= $m['level'] ?> | ጠቅላላ፦ <?= count($bList) ?> መጻሕፍት</div>
          </div>
          <div class="cat-banner-qr" id="cat-banner-qr-<?= $catId ?>"></div>
        </div>

        <table class="books-table">
          <thead>
            <tr>
              <th style="width:40px;text-align:center;">ተ.ቁ</th>
              <th>የመጽሐፉ ስም</th>
              <th>ደራሲ</th>
              <th style="width:65px;text-align:center;">ዓ.ም</th>
              <th style="width:45px;text-align:center;">ብዛት</th>
              <th style="width:130px;">የቅጂ ኮዶች</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bList as $idx => $book): ?>
              <tr>
                <td style="text-align:center;color:#64748B;"><?= $idx + 1 ?></td>
                <td><strong><?= htmlspecialchars($book['title']) ?></strong></td>
                <td style="color:#475569;"><?= htmlspecialchars($book['author'] ?: '—') ?></td>
                <td style="text-align:center;color:#64748B;"><?= $book['publication_year'] ?: '—' ?></td>
                <td style="text-align:center;font-weight:700;"><?= $book['quantity'] ?></td>
                <td>
                  <?php if (!empty($book['copies_info'])): ?>
                    <span class="code-pill"><?= htmlspecialchars($book['copies_info']) ?></span>
                  <?php else: ?>
                    <span style="color:#94A3B8;">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>

</div>

<!-- QR Code Generator Engine -->
<script>
const CAT_LIST = <?= json_encode(array_keys($catMeta)) ?>;

// Dynamic Base URL Resolution
const baseUrl = window.location.protocol + '//' + window.location.host + window.location.pathname.replace('print_shelf_labels.php', '');

CAT_LIST.forEach(catId => {
  const targetUrl = baseUrl + 'shelf_3d.php?cat=' + catId;

  // Render Label QR
  const labelBox = document.getElementById('qr-box-' + catId);
  if (labelBox) {
    new QRCode(labelBox, {
      text: targetUrl,
      width: 90,
      height: 90,
      colorDark: "#000000",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.M
    });
  }

  // Render Catalog Banner QR
  const bannerBox = document.getElementById('cat-banner-qr-' + catId);
  if (bannerBox) {
    new QRCode(bannerBox, {
      text: targetUrl,
      width: 55,
      height: 55,
      colorDark: "#000000",
      colorLight: "#ffffff",
      correctLevel: QRCode.CorrectLevel.M
    });
  }
});
</script>

</body>
</html>
