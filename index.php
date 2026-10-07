<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/nav_config.php';

$pageTitle = __('home');
$activeKey = 'home';

$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY name ASC");

$newBooks = mysqli_query($conn, "
  SELECT b.*, c.name AS category_name,
    (SELECT COUNT(*) FROM book_copies bc WHERE bc.book_id=b.id AND bc.status='available') AS available_count
  FROM books b LEFT JOIN categories c ON c.id=b.category_id
  WHERE b.borrow_status != 'archived'
  ORDER BY b.created_at DESC, b.id DESC LIMIT 8
");

$totalBooks = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE borrow_status != 'archived'"))['c'] ?? 0;
$totalCategories = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM categories"))['c'] ?? 0;

include __DIR__ . '/includes/header.php';
?>

<div class="hero-banner">
  <h1 class="font-display">እንኳን ወደ <?= e($siteName) ?> በደህና መጡ</h1>
  <p>መደርደሪያዎቻችንን ይፈልጉ፣ አዳዲስ መጻሕፍትን ያግኙ፣ እና በቀላሉ ይዋሱ — ለቤተ ክርስቲያናችንና ለትምህርት ቤታችን ማህበረሰብ የተዘጋጀ።</p>
  <form action="<?= $base ?>search.php" method="get" class="mt-3">
    <div class="input-group" style="display:flex;align-items:center;gap:8px;background:rgba(255,255,255,.12);border-radius:14px;padding:4px;">
      <i class="bi bi-search" style="color:rgba(255,255,255,.7);"></i>
      <input class="input" style="flex:1;min-width:0;background:transparent;border:0;color:#fff;" name="q" placeholder="በመጽሐፍ ስም፣ በደራሲ ወይም በኮድ ይፈልጉ…">
      <button class="btn btn-gold btn-sm" type="submit"><i class="bi bi-search"></i> ፈልግ</button>
    </div>
  </form>
  <div style="display:flex;gap:14px;margin-top:16px;font-size:.78rem;color:rgba(255,255,255,.75);align-items:center;flex-wrap:wrap;">
    <span><i class="bi bi-book"></i> <?= (int)$totalBooks ?> መጻሕፍት</span>
    <span><i class="bi bi-grid"></i> <?= (int)$totalCategories ?> ምድቦች</span>
    <a href="<?= $base ?>shelf_3d.php" style="color:#fff;text-decoration:none;font-weight:700;display:inline-flex;align-items:center;gap:5px;background:linear-gradient(135deg, #0284C7, #0369A1);padding:4px 12px;border-radius:99px;border:1px solid rgba(56,189,248,0.5);box-shadow:0 0 10px rgba(2,132,199,0.4);">
      <i class="bi bi-box"></i> 3D የመደርደሪያ እይታ
    </a>
  </div>
</div>

<?php if (!current_user()): ?>
<div class="card card-pad" style="display:flex;align-items:center;gap:14px;margin-bottom:18px;">
  <i class="bi bi-person-plus" style="font-size:1.6rem;color:var(--gold-600);"></i>
  <div style="flex:1;">
    <div style="font-weight:700;color:var(--navy);">እስካሁን አባል አልሆኑም?</div>
    <div class="text-muted" style="font-size:.82rem;">ለመዋስና ማሳወቂያ ለማግኘት በነፃ ይመዝገቡ።</div>
  </div>
  <a href="<?= $base ?>register.php" class="btn btn-navy btn-sm"><?= __('join') ?></a>
</div>
<?php endif; ?>


<div class="section-title" id="categories">በምድብ ይዩ</div>
<div class="row g-2 g-md-3">
  <?php while ($cat = mysqli_fetch_assoc($categories)): ?>
    <div class="col-3 col-md-2">
      <a href="<?= $base ?>search.php?category=<?= (int)$cat['id'] ?>" class="category-chip" style="display:flex;">
        <i class="bi <?= e($cat['icon'] ?: 'bi-book') ?>"></i>
        <span><?= e($cat['name']) ?></span>
      </a>
    </div>
  <?php endwhile; ?>
</div>

<div class="section-title">
  አዲስ የመጡ መጻሕፍት
  <a href="<?= $base ?>search.php?sort=new" class="see-all"><?= __('see_all') ?> <i class="bi bi-arrow-right"></i></a>
</div>
<div class="row g-2 g-md-3">
  <?php if (mysqli_num_rows($newBooks) === 0): ?>
    <div class="empty-state" style="width:100%;">
      <i class="bi bi-book"></i>
      <h4>እስካሁን መጽሐፍ የለም</h4>
      <p>ሲጨመር አዳዲስ መጻሕፍት እዚህ ይታያሉ።</p>
    </div>
  <?php endif; ?>
  <?php while ($b = mysqli_fetch_assoc($newBooks)): ?>
    <div class="col-6 col-sm-4 col-md-3 col-lg-2">
      <a href="<?= $base ?>book.php?id=<?= (int)$b['id'] ?>" class="book-card card-hover" style="text-decoration:none;">
        <div class="book-cover">
          <?= book_cover_html($b['cover_image']) ?>
        </div>
        <div class="book-body">
          <div class="book-title"><?= e($b['title']) ?></div>
          <?php $authDisp = (!empty(trim($b['author'] ?? '')) && strtolower($b['author']) !== 'unwritten' && $b['author'] !== 'ጸሃፊው አልተገለጸም') ? $b['author'] : 'ጸሃፊው አልተገለጸም'; ?>
          <div class="book-author" style="<?= $authDisp === 'ጸሃፊው አልተገለጸም' ? 'font-style:italic;opacity:0.85;' : '' ?>"><?= e($authDisp) ?></div>
          <div class="book-meta">
            <span class="badge <?= borrow_status_class($b['borrow_status']) ?>"><?= borrow_status_label($b['borrow_status']) ?></span>
            <span class="shelf-tag"><i class="bi bi-geo-alt"></i><?= (int)$b['available_count'] ?> ቀርቷል</span>
          </div>
        </div>
      </a>
    </div>
  <?php endwhile; ?>
</div>

<div class="divider-label">በመጨረሻ</div>
<div class="card card-pad" style="text-align:center;">
  <i class="bi bi-search" style="font-size:1.6rem;color:var(--gold-600);"></i>
  <h4 class="font-display" style="margin:10px 0 6px;">የፈለጉትን አላገኙም?</h4>
  <p class="text-muted" style="font-size:.85rem;">ይንገሩን፤ ቤተ መጻሕፍታችን ላይ ለማከል እንመለከተዋለን።</p>
  <a href="<?= $base ?>search.php" class="btn btn-outline-gold">ካታሎግ ይፈልጉ</a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
