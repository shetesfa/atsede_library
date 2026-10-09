<?php
/**
 * shelf_3d.php — 3D Interactive Bookshelf Visualizer (Online & Offline)
 * Atsede Library
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Fetch all categories and their books for offline/standalone rendering
$categoriesQuery = mysqli_query($conn, "SELECT id, name, description, icon FROM categories ORDER BY id ASC");
$categories = [];
while ($cat = mysqli_fetch_assoc($categoriesQuery)) {
    $categories[$cat['id']] = $cat;
}

$booksQuery = mysqli_query($conn, "
    SELECT b.id, b.title, b.author, b.category_id, b.quantity, b.publication_year, b.publisher, b.price, b.position,
           s.name AS shelf_name,
           GROUP_CONCAT(CONCAT(bc.copy_code, ':', bc.status) ORDER BY bc.id SEPARATOR ', ') AS copies_info
    FROM books b
    LEFT JOIN shelves s ON s.id = b.shelf_id
    LEFT JOIN book_copies bc ON bc.book_id = b.id
    GROUP BY b.id
    ORDER BY b.title ASC
");

$books = [];
$booksByCategory = [];
while ($b = mysqli_fetch_assoc($booksQuery)) {
    $cId = (int)$b['category_id'];
    $copies = [];
    if (!empty($b['copies_info'])) {
        foreach (explode(', ', $b['copies_info']) as $cp) {
            $parts = explode(':', $cp);
            $copies[] = [
                'code' => $parts[0] ?? '',
                'status' => $parts[1] ?? 'available'
            ];
        }
    }
    $b['copies'] = $copies;
    $books[] = $b;
    $booksByCategory[$cId][] = $b;
}

// Category Mapping to Hotspots (based on physical layout and user's 3D image)
$shelfHotspots = [
    1 => [ // የአንድምታ መጽሐፍት ክፍል
        'num' => 1,
        'cat_id' => 1,
        'name' => 'የአንድምታ መጽሐፍት ክፍል',
        'color' => '#EAB308',
        'border' => '#FACC15',
        'x' => 10.5, 'y' => 18.4, 'w' => 22.5, 'h' => 20.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ግራ)'
    ],
    2 => [ // የገድላትና ድርሳናት መጽሐፍት ክፍል
        'num' => 2,
        'cat_id' => 2,
        'name' => 'የገድላትና ድርሳናት መጽሐፍት ክፍል',
        'color' => '#A855F7',
        'border' => '#C084FC',
        'x' => 33.7, 'y' => 18.4, 'w' => 19.0, 'h' => 20.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - መካከለኛ)'
    ],
    7 => [ // የኮርስ መስጫ መጻሕፍት ክፍል
        'num' => 3,
        'cat_id' => 7,
        'name' => 'የኮርስ መስጫ መጻሕፍት ክፍል',
        'color' => '#22C55E',
        'border' => '#4ADE80',
        'x' => 62.0, 'y' => 17.1, 'w' => 17.1, 'h' => 20.4,
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ)'
    ],
    9 => [ // የአቡነ ሺኖዳ መጻሕፍት ክፍል
        'num' => 4,
        'cat_id' => 9,
        'name' => 'የአቡነ ሺኖዳ መጻሕፍት ክፍል',
        'color' => '#06B6D4',
        'border' => '#22D3EE',
        'x' => 79.9, 'y' => 11.3, 'w' => 17.8, 'h' => 26.2,
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ጎን)'
    ],
    4 => [ // የትምህርት እና ምክር አዘል ክፍል
        'num' => 5,
        'cat_id' => 4,
        'name' => 'የትምህርት እና ምክር አዘል ክፍል',
        'color' => '#10B981',
        'border' => '#34D399',
        'x' => 10.5, 'y' => 40.8, 'w' => 22.5, 'h' => 20.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - መካከለኛ)'
    ],
    3 => [ // የመሰረተ ሃይማኖት ክፍል
        'num' => 6,
        'cat_id' => 3,
        'name' => 'የመሰረተ ሃይማኖት ክፍል',
        'color' => '#EF4444',
        'border' => '#F87171',
        'x' => 33.7, 'y' => 40.8, 'w' => 19.0, 'h' => 20.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - ግራ)'
    ],
    12 => [ // የሥርጉተ ሥላሴ መጽሐፍት ክፍል
        'num' => 7,
        'cat_id' => 12,
        'name' => 'የሥርጉተ ሥላሴ መጽሐፍት ክፍል',
        'color' => '#8B5CF6',
        'border' => '#A78BFA',
        'x' => 10.5, 'y' => 63.1, 'w' => 22.5, 'h' => 21.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '1ኛ ረድፍ (ታችኛ - ግራ እና መካከለኛ)'
    ],
    5 => [ // የታሪክ መጽሐፍት ክፍል
        'num' => 8,
        'cat_id' => 5,
        'name' => 'የታሪክ መጽሐፍት ክፍል',
        'color' => '#0284C7',
        'border' => '#38BDF8',
        'x' => 33.7, 'y' => 63.1, 'w' => 19.0, 'h' => 21.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '3ኛ ረድፍ (ላይኛ - ቀኝ)'
    ],
    10 => [ // የጸሎትና የዜማ መጻሕፍት ክፍል
        'num' => 9,
        'cat_id' => 10,
        'name' => 'የጸሎትና የዜማ መጻሕፍት ክፍል',
        'color' => '#F97316',
        'border' => '#FB923C',
        'x' => 61.5, 'y' => 59.2, 'w' => 17.6, 'h' => 19.4,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ - ቀኝ)'
    ],
    6 => [ // የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል
        'num' => 10,
        'cat_id' => 6,
        'name' => 'የክርስቲያናዊ ሥነምግባር መጻሕፍት ክፍል',
        'color' => '#EC4899',
        'border' => '#F472B6',
        'x' => 79.1, 'y' => 60.2, 'w' => 18.1, 'h' => 26.2,
        'shelf' => 'ፊት ለፊት መደርደሪያ', 'level' => '1ኛ ረድፍ (ታችኛ - ቀኝ)'
    ],
    11 => [ // የመጽሐፍ ቅዱስ መጻሕፍት ክፍል
        'num' => 11,
        'cat_id' => 11,
        'name' => 'የመጽሐፍ ቅዱስ መጻሕፍት ክፍል',
        'color' => '#38BDF8',
        'border' => '#7DD3FC',
        'x' => 61.7, 'y' => 39.8, 'w' => 17.4, 'h' => 18.4,
        'shelf' => 'በቀኝ በኩል መደርደሪያ', 'level' => '2ኛ ረድፍ (መካከለኛ)'
    ],
];

?>
<!DOCTYPE html>
<html lang="am">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title>3D የመጽሐፍት መደርደሪያ — አጸደ ቤተ-መጻሕፍት</title>
<link rel="stylesheet" href="assets/lib/fonts/fonts.css">
<link rel="stylesheet" href="assets/lib/bootstrap-icons/bootstrap-icons.css">
<style>
  :root {
    --bg-dark: #071527;
    --navy-bar: #0B2545;
    --navy-surface: #0E3059;
    --gold: #C59B27;
    --gold-glow: rgba(197, 155, 39, 0.4);
    --text-light: #F8FAFC;
    --text-muted: #94A3B8;
    --border-dark: rgba(255, 255, 255, 0.12);
  }

  * { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }

  body {
    background: var(--bg-dark);
    color: var(--text-light);
    font-family: 'Noto Sans Ethiopic', sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
    overflow-x: hidden;
  }

  /* Top Navigation Bar */
  .top-navbar {
    background: linear-gradient(180deg, #061A33 0%, #0A2548 100%);
    border-bottom: 1px solid var(--border-dark);
    padding: 10px 16px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    z-index: 50;
    box-shadow: 0 4px 20px rgba(0, 0, 0, 0.4);
  }

  .brand-title {
    display: flex;
    align-items: center;
    gap: 10px;
    font-family: 'Noto Serif Ethiopic', serif;
    font-size: 1.15rem;
    font-weight: 700;
    color: #fff;
    white-space: nowrap;
    text-decoration: none;
  }
  .brand-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: rgba(255, 255, 255, 0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    border: 1px solid rgba(255, 255, 255, 0.2);
  }

  /* Search Box */
  .search-container {
    flex: 1;
    max-width: 480px;
    position: relative;
  }
  .search-input {
    width: 100%;
    padding: 8px 14px 8px 38px;
    background: rgba(255, 255, 255, 0.08);
    border: 1px solid rgba(255, 255, 255, 0.2);
    border-radius: 99px;
    color: #fff;
    font-family: inherit;
    font-size: 0.88rem;
    outline: none;
    transition: all 0.2s;
  }
  .search-input:focus {
    background: rgba(255, 255, 255, 0.15);
    border-color: var(--gold);
    box-shadow: 0 0 12px var(--gold-glow);
  }
  .search-input::placeholder { color: rgba(255, 255, 255, 0.6); }
  .search-icon {
    position: absolute;
    left: 14px;
    top: 50%;
    transform: translateY(-50%);
    color: rgba(255, 255, 255, 0.6);
    font-size: 0.95rem;
  }

  /* Right Action Badges */
  .nav-actions {
    display: flex;
    align-items: center;
    gap: 8px;
    white-space: nowrap;
  }
  .badge-3d {
    background: linear-gradient(135deg, #0284C7, #0369A1);
    color: #fff;
    font-weight: 800;
    font-size: 0.76rem;
    padding: 6px 12px;
    border-radius: 8px;
    border: 1px solid rgba(56, 189, 248, 0.4);
    box-shadow: 0 0 10px rgba(2, 132, 199, 0.3);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 4px;
  }
  .btn-nav-home {
    background: rgba(255, 255, 255, 0.1);
    color: #fff;
    border: 1px solid rgba(255, 255, 255, 0.2);
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 0.82rem;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: background 0.2s;
  }
  .btn-nav-home:hover { background: rgba(255, 255, 255, 0.2); }

  /* 3D Shelf Stage & Canvas */
  .shelf-viewport {
    flex: 1;
    position: relative;
    background: #020813;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    perspective: 1200px;
    min-height: 480px;
  }

  .shelf-stage {
    position: relative;
    width: 100%;
    max-width: 1100px;
    aspect-ratio: 1024 / 515;
    background: #000;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.8);
    transition: transform 0.4s cubic-bezier(0.16, 1, 0.3, 1);
    transform-style: preserve-3d;
  }

  .shelf-stage.is-3d {
    transform: rotateX(6deg) rotateY(-4deg) scale(0.97);
  }

  .shelf-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    user-select: none;
    -webkit-user-drag: none;
  }

  /* Hotspot Overlays (Interactive Glowing Boxes) */
  .hotspot-box {
    position: absolute;
    border: 2.5px solid transparent;
    border-radius: 12px;
    cursor: pointer;
    transition: all 0.25s ease;
    z-index: 10;
    display: flex;
    align-items: flex-start;
    justify-content: flex-start;
    padding: 4px 6px;
  }

  .hotspot-box:hover, .hotspot-box.active {
    border-color: #fff !important;
    background: rgba(255, 255, 255, 0.12);
    box-shadow: 0 0 25px currentColor, inset 0 0 15px currentColor;
    transform: translateZ(25px) scale(1.02);
  }

  .hotspot-box.pulsing {
    animation: neonPulse 1.2s infinite alternate;
  }

  @keyframes neonPulse {
    0% { transform: scale(1); filter: brightness(1); }
    100% { transform: scale(1.04); filter: brightness(1.4); }
  }

  .hotspot-pill {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    font-size: 0.72rem;
    font-weight: 800;
    padding: 3px 8px;
    border-radius: 99px;
    color: #fff;
    background: rgba(0, 0, 0, 0.8);
    border: 1.5px solid currentColor;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.5);
    pointer-events: none;
  }

  .hotspot-badge-num {
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    color: #000;
    font-weight: 900;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.65rem;
  }

  /* Floating 3D Controls */
  .floating-controls {
    position: absolute;
    right: 18px;
    bottom: 24px;
    display: flex;
    flex-direction: column;
    gap: 8px;
    z-index: 30;
  }

  .float-btn {
    width: 40px;
    height: 40px;
    border-radius: 10px;
    background: rgba(11, 37, 69, 0.85);
    backdrop-filter: blur(8px);
    border: 1px solid rgba(255, 255, 255, 0.2);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.1rem;
    cursor: pointer;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.5);
    transition: all 0.2s;
  }
  .float-btn:hover { background: var(--navy-bar); border-color: var(--gold); color: var(--gold); }
  .float-btn:active { transform: scale(0.92); }

  /* Bottom Legend Category Bar */
  .bottom-shelf-bar {
    background: linear-gradient(180deg, #081B34 0%, #051224 100%);
    border-top: 1px solid var(--border-dark);
    padding: 14px 16px 18px;
    z-index: 40;
    box-shadow: 0 -6px 25px rgba(0, 0, 0, 0.5);
  }

  .cat-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
    margin-bottom: 12px;
  }
  @media (max-width: 900px) {
    .cat-grid { grid-template-columns: repeat(2, 1fr); }
  }

  .cat-card-btn {
    background: rgba(255, 255, 255, 0.05);
    border: 1.5px solid rgba(255, 255, 255, 0.12);
    border-radius: 12px;
    padding: 8px 10px;
    display: flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    color: #fff;
    text-align: left;
    transition: all 0.2s;
    user-select: none;
  }
  .cat-card-btn:hover, .cat-card-btn.active {
    background: rgba(255, 255, 255, 0.15);
    border-color: var(--card-color, var(--gold));
    box-shadow: 0 0 15px rgba(255, 255, 255, 0.1);
    transform: translateY(-2px);
  }

  .cat-card-num {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    color: #fff;
    background: var(--card-color, #0284C7);
    font-weight: 800;
    font-size: 0.75rem;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .cat-card-name {
    font-size: 0.8rem;
    font-weight: 700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    flex: 1;
  }

  .cat-card-icon {
    font-size: 0.95rem;
    color: var(--card-color, var(--gold));
  }

  .instruction-card {
    background: rgba(255, 255, 255, 0.04);
    border: 1px dashed rgba(255, 255, 255, 0.2);
    border-radius: 10px;
    padding: 8px 14px;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    color: #94A3B8;
    font-size: 0.82rem;
  }

  /* Drawer / Bottom Sheet for Book Listings */
  .book-drawer {
    position: fixed;
    inset: auto 0 0 0;
    max-height: 75vh;
    background: #0C1E38;
    border-top: 2px solid var(--gold);
    border-radius: 20px 20px 0 0;
    box-shadow: 0 -10px 40px rgba(0, 0, 0, 0.8);
    display: flex;
    flex-direction: column;
    z-index: 100;
    transform: translateY(105%);
    transition: transform 0.35s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .book-drawer.open {
    transform: translateY(0);
  }

  .drawer-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border-dark);
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }
  .drawer-title {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .drawer-close {
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: rgba(255, 255, 255, 0.1);
    border: none;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    font-size: 1.2rem;
  }

  .drawer-body {
    padding: 16px 20px;
    overflow-y: auto;
    flex: 1;
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 12px;
  }

  .drawer-book-card {
    background: rgba(255, 255, 255, 0.05);
    border: 1px solid rgba(255, 255, 255, 0.1);
    border-radius: 12px;
    padding: 12px 14px;
    display: flex;
    flex-direction: column;
    gap: 6px;
    transition: all 0.2s;
  }
  .drawer-book-card:hover {
    background: rgba(255, 255, 255, 0.09);
    border-color: var(--gold);
    transform: translateY(-2px);
  }
  .b-title { font-weight: 700; font-size: 0.92rem; color: #fff; line-height: 1.4; }
  .b-author { color: #94A3B8; font-size: 0.8rem; }
  .b-meta { display: flex; align-items: center; justify-content: space-between; font-size: 0.76rem; color: #CBD5E1; margin-top: 4px; }
  .b-copies { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
  .copy-tag {
    font-size: 0.7rem;
    padding: 2px 6px;
    border-radius: 6px;
    background: rgba(2, 132, 199, 0.2);
    border: 1px solid rgba(2, 132, 199, 0.4);
    color: #7DD3FC;
    font-family: monospace;
  }

  /* Backdrop Overlay */
  .drawer-backdrop {
    position: fixed;
    inset: 0;
    background: rgba(0, 0, 0, 0.6);
    backdrop-filter: blur(4px);
    z-index: 90;
    display: none;
  }
  .drawer-backdrop.show { display: block; }
</style>
</head>
<body>

<!-- Top Navbar -->
<header class="top-navbar">
  <a href="index.php" class="brand-title">
    <div class="brand-icon"><i class="bi bi-book-half"></i></div>
    <span>የመጽሐፍት ቤት መጽሐፍት</span>
  </a>

  <!-- Live Search Bar -->
  <div class="search-container">
    <i class="bi bi-search search-icon"></i>
    <input type="text" id="shelfSearchInput" class="search-input" placeholder="ምድብ፣ መጽሐፍ ወይም ደራሲ ፈልግ..." oninput="handleShelfSearch(this.value)">
  </div>

  <div class="nav-actions">
    <button type="button" class="badge-3d" id="btnToggle3D" onclick="toggle3DMode()">
      <i class="bi bi-box"></i> 3D
    </button>
    <a href="index.php" class="btn-nav-home">
      <i class="bi bi-house-door"></i> ወደ መጀመሪያ
    </a>
  </div>
</header>

<!-- Main Shelf 3D Viewport -->
<main class="shelf-viewport" id="shelfViewport">
  <div class="shelf-stage is-3d" id="shelfStage">
    <img src="./assets/shelf_canvas.jpg" alt="የመጽሐፍት መደርደሪያ" class="shelf-img" id="shelfImage">

    <!-- Interactive Hotspots -->
    <?php foreach ($shelfHotspots as $catId => $h): ?>
      <?php 
        $bCount = count($booksByCategory[$catId] ?? []);
      ?>
      <div class="hotspot-box" id="hotspot-<?= $catId ?>"
           style="left: <?= $h['x'] ?>%; top: <?= $h['y'] ?>%; width: <?= $h['w'] ?>%; height: <?= $h['h'] ?>%; color: <?= $h['border'] ?>;"
           onclick="openCategoryBooks(<?= $catId ?>)"
           title="<?= htmlspecialchars($h['name']) ?> (<?= $bCount ?> መጽሐፍት)">
        <div class="hotspot-pill" style="border-color: <?= $h['color'] ?>;">
          <span class="hotspot-badge-num" style="background: <?= $h['color'] ?>; color: #fff;"><?= $h['num'] ?></span>
          <span><?= htmlspecialchars($h['name']) ?></span>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- Floating 3D Controls -->
  <div class="floating-controls">
    <button type="button" class="float-btn" title="3D እይታ መቀያየሪያ" onclick="toggle3DMode()">
      <i class="bi bi-box"></i>
    </button>
    <button type="button" class="float-btn" title="አጉላ (+)" onclick="zoomShelf(1.2)">
      <i class="bi bi-plus-lg"></i>
    </button>
    <button type="button" class="float-btn" title="አሳንስ (-)" onclick="zoomShelf(0.8)">
      <i class="bi bi-dash-lg"></i>
    </button>
    <button type="button" class="float-btn" title="ወደ መጀመሪያ እይታ አስተካክል" onclick="resetShelfView()">
      <i class="bi bi-fullscreen"></i>
    </button>
  </div>
</main>

<!-- Bottom Shelf Legend / Category Grid -->
<footer class="bottom-shelf-bar">
  <div class="cat-grid">
    <?php foreach ($shelfHotspots as $catId => $h): ?>
      <?php 
        $bCount = count($booksByCategory[$catId] ?? []);
      ?>
      <div class="cat-card-btn" id="cat-card-<?= $catId ?>" 
           style="--card-color: <?= $h['color'] ?>;"
           onclick="openCategoryBooks(<?= $catId ?>)">
        <span class="cat-card-num" style="background: <?= $h['color'] ?>;"><?= $h['num'] ?></span>
        <span class="cat-card-name"><?= htmlspecialchars($h['name']) ?></span>
        <i class="bi bi-book cat-card-icon"></i>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="instruction-card">
    <i class="bi bi-hand-index-thumb"></i>
    <span>እባክዎን የሚፈልጉትን ክፍል ይምረጡ፡ በ3D በረድፍ መስተጋብራዊ ቦታ ይደርሳል!</span>
  </div>
</footer>

<!-- Book Listings Drawer (Bottom Sheet) -->
<div class="drawer-backdrop" id="drawerBackdrop" onclick="closeBookDrawer()"></div>
<div class="book-drawer" id="bookDrawer">
  <div class="drawer-header">
    <div class="drawer-title">
      <div id="drawerBadgeNum" class="hotspot-badge-num" style="width:28px;height:28px;font-size:0.9rem;"></div>
      <div>
        <h2 id="drawerCatTitle" style="font-size:1.15rem;font-weight:700;color:#fff;"></h2>
        <div id="drawerCatSub" style="font-size:0.8rem;color:#94A3B8;"></div>
      </div>
    </div>
    <button type="button" class="drawer-close" onclick="closeBookDrawer()">&times;</button>
  </div>
  <div class="drawer-body" id="drawerBooksList"></div>
</div>

<!-- Embedded Offline Payload -->
<script>
const OFFLINE_BOOKS = <?= json_encode($books, JSON_UNESCAPED_UNICODE) ?>;
const OFFLINE_CATEGORIES = <?= json_encode($categories, JSON_UNESCAPED_UNICODE) ?>;
const OFFLINE_HOTSPOTS = <?= json_encode($shelfHotspots, JSON_UNESCAPED_UNICODE) ?>;

// Store in LocalStorage for 100% offline persistence
try {
  localStorage.setItem('atsede_3d_books', JSON.stringify(OFFLINE_BOOKS));
  localStorage.setItem('atsede_3d_categories', JSON.stringify(OFFLINE_CATEGORIES));
} catch (e) {
  console.log('LocalStorage write error', e);
}

let is3D = true;
let currentZoom = 1;
let activeCatId = null;

const stage = document.getElementById('shelfStage');

// Toggle 3D Perspective Tilt
function toggle3DMode() {
  is3D = !is3D;
  const btn = document.getElementById('btnToggle3D');
  if (is3D) {
    stage.classList.add('is-3d');
    btn.style.boxShadow = '0 0 12px rgba(2, 132, 199, 0.8)';
  } else {
    stage.classList.remove('is-3d');
    stage.style.transform = `scale(${currentZoom})`;
    btn.style.boxShadow = 'none';
  }
}

// Zoom In/Out
function zoomShelf(factor) {
  currentZoom = Math.min(Math.max(currentZoom * factor, 0.7), 2.5);
  if (is3D) {
    stage.style.transform = `rotateX(6deg) rotateY(-4deg) scale(${currentZoom * 0.97})`;
  } else {
    stage.style.transform = `scale(${currentZoom})`;
  }
}

// Reset View
function resetShelfView() {
  currentZoom = 1;
  is3D = true;
  stage.classList.add('is-3d');
  stage.style.transform = 'rotateX(6deg) rotateY(-4deg) scale(0.97)';
  clearActiveHotspots();
}

function clearActiveHotspots() {
  document.querySelectorAll('.hotspot-box').forEach(el => {
    el.classList.remove('active', 'pulsing');
  });
  document.querySelectorAll('.cat-card-btn').forEach(el => {
    el.classList.remove('active');
  });
}

// Open Books in Drawer
function openCategoryBooks(catId) {
  activeCatId = catId;
  clearActiveHotspots();

  const hotspot = document.getElementById(`hotspot-${catId}`);
  const card = document.getElementById(`cat-card-${catId}`);
  if (hotspot) hotspot.classList.add('active', 'pulsing');
  if (card) card.classList.add('active');

  const info = OFFLINE_HOTSPOTS[catId];
  if (!info) return;

  const bBadge = document.getElementById('drawerBadgeNum');
  bBadge.innerText = info.num;
  bBadge.style.background = info.color;

  document.getElementById('drawerCatTitle').innerText = info.name;
  
  const booksInCat = OFFLINE_BOOKS.filter(b => parseInt(b.category_id) === parseInt(catId));
  document.getElementById('drawerCatSub').innerText = `${info.shelf} • ${info.level} • ${booksInCat.length} መጽሐፍት`;

  const list = document.getElementById('drawerBooksList');
  list.innerHTML = '';

  if (booksInCat.length === 0) {
    list.innerHTML = '<div style="color:#94A3B8;grid-column:1/-1;text-align:center;padding:24px;">በዚህ ክፍል ውስጥ የተመዘገቡ መጻሕፍት የሉም።</div>';
  } else {
    booksInCat.forEach(b => {
      const card = document.createElement('div');
      card.className = 'drawer-book-card';
      
      let copiesHtml = '';
      if (b.copies && b.copies.length > 0) {
        copiesHtml = '<div class="b-copies">' + b.copies.map(c => 
          `<span class="copy-tag" title="ሁኔታ፦ ${c.status}">${c.code}</span>`
        ).join('') + '</div>';
      }

      card.innerHTML = `
        <div class="b-title">${escapeHtml(b.title)}</div>
        <div class="b-author"><i class="bi bi-person"></i> ${escapeHtml(b.author || 'ያልተገለጸ')}</div>
        <div class="b-meta">
          <span><i class="bi bi-calendar3"></i> ${b.publication_year || '—'} ዓ.ም</span>
          <span>${b.quantity} ቅጂዎች</span>
        </div>
        ${copiesHtml}
      `;
      list.appendChild(card);
    });
  }

  document.getElementById('bookDrawer').classList.add('open');
  document.getElementById('drawerBackdrop').classList.add('show');
}

function closeBookDrawer() {
  document.getElementById('bookDrawer').classList.remove('open');
  document.getElementById('drawerBackdrop').classList.remove('show');
}

// Live Search handler
function handleShelfSearch(query) {
  const q = query.trim().toLowerCase();
  if (!q) {
    clearActiveHotspots();
    return;
  }

  // Find matching books
  const matchedBooks = OFFLINE_BOOKS.filter(b => 
    b.title.toLowerCase().includes(q) || 
    (b.author && b.author.toLowerCase().includes(q))
  );

  clearActiveHotspots();

  if (matchedBooks.length > 0) {
    // Collect matched category IDs
    const matchedCatIds = [...new Set(matchedBooks.map(b => parseInt(b.category_id)))];
    matchedCatIds.forEach(catId => {
      const h = document.getElementById(`hotspot-${catId}`);
      const c = document.getElementById(`cat-card-${catId}`);
      if (h) h.classList.add('pulsing');
      if (c) c.classList.add('active');
    });

    // If exact single match category, open drawer
    if (matchedCatIds.length === 1) {
      openCategoryBooks(matchedCatIds[0]);
    }
  }
}

// Mouse movement interactive 3D parallax
const viewport = document.getElementById('shelfViewport');
viewport.addEventListener('mousemove', (e) => {
  if (!is3D) return;
  const rect = viewport.getBoundingClientRect();
  const x = (e.clientX - rect.left) / rect.width - 0.5;
  const y = (e.clientY - rect.top) / rect.height - 0.5;
  
  const rotX = 6 - (y * 12);
  const rotY = -4 + (x * 12);
  stage.style.transform = `rotateX(${rotX}deg) rotateY(${rotY}deg) scale(${currentZoom * 0.97})`;
});

viewport.addEventListener('mouseleave', () => {
  if (is3D) {
    stage.style.transform = `rotateX(6deg) rotateY(-4deg) scale(${currentZoom * 0.97})`;
  }
});

// Auto-open category if ?cat=X is in URL (for shelf QR scans)
window.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const catParam = urlParams.get('cat');
  if (catParam) {
    setTimeout(() => {
      openCategoryBooks(parseInt(catParam));
    }, 400);
  }
});

function escapeHtml(str) {
  if (!str) return '';
  return str.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}
</script>

</body>
</html>
