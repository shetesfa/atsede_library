<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_category'])) {
    csrf_verify();
    $name = clean($_POST['name'] ?? '');
    if ($name !== '') {
        $stmt = mysqli_prepare($conn, "INSERT INTO categories (name, description, icon) VALUES (?, '', 'bi-book')");
        mysqli_stmt_bind_param($stmt, 's', $name);
        if (mysqli_stmt_execute($stmt)) { flash('msg', 'ምድብ ታክሏል።', 'success'); }
        else { flash('msg', 'በዚህ ስም ምድብ አስቀድሞ አለ።', 'danger'); }
    }
    redirect('categories.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_category'])) {
    csrf_verify();
    $id = (int)$_POST['id'];
    $inUse = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE category_id=$id"))['c'];
    if ($inUse > 0) {
        flash('msg', 'ማጥፋት አይቻልም፦ መጻሕፍት ለዚህ ምድብ ተመድበዋል።', 'danger');
    } else {
        mysqli_query($conn, "DELETE FROM categories WHERE id=$id");
        flash('msg', 'ምድቡ ጠፍቷል።', 'success');
    }
    redirect('categories.php');
}

$categories = mysqli_query($conn, "SELECT c.*, (SELECT COUNT(*) FROM books WHERE category_id=c.id) AS book_count FROM categories c ORDER BY c.name ASC");

$pageTitle = __('categories');
$activeKey = 'categories';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
  <button class="btn btn-gold" onclick="openSheet('cat-sheet')"><i class="bi bi-plus-lg"></i> ምድብ ጨምር</button>
</div>

<div class="row g-2">
  <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
    <div class="col-6 col-md-4">
      <div class="card card-pad" style="display:flex;align-items:center;gap:12px;">
        <i class="bi bi-book" style="font-size:1.4rem;color:var(--gold-600);"></i>
        <div style="flex:1;">
          <a href="../librarian/books.php?category=<?= (int)$c['id'] ?>" style="font-weight:700;color:var(--navy);text-decoration:none;display:block;"><?= e($c['name']) ?></a>
          <div class="text-muted" style="font-size:.76rem;"><?= (int)$c['book_count'] ?> መጻሕፍት</div>
        </div>
        <?php if ((int)$c['book_count'] === 0): ?>
        <form method="post" onsubmit="return confirmAction('ይህን ምድብ ማጥፋት ይፈልጋሉ?', this)">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
          <button class="btn btn-outline btn-sm" name="delete_category" value="1" style="color:var(--danger);border-color:var(--danger);"><i class="bi bi-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  <?php endwhile; ?>
</div>

<div class="sheet-overlay" id="cat-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">ምድብ ጨምር</div>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label>ስም</label><input class="input" name="name" required placeholder="የምድብ ስም"></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('cat-sheet')"><?= __("cancel") ?></button>
        <button class="btn btn-gold btn-block" name="add_category" value="1"><?= __("add") ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
