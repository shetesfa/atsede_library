<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$user = current_user();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_room'])) {
    csrf_verify();
    $name = clean($_POST['name'] ?? '');
    if ($name !== '') {
        $stmt = mysqli_prepare($conn, "INSERT INTO rooms (name) VALUES (?)");
        mysqli_stmt_bind_param($stmt, 's', $name);
        mysqli_stmt_execute($stmt);
        flash('msg', 'አዳራሽ ታክሏል።', 'success');
    }
    redirect('rooms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_shelf'])) {
    csrf_verify();
    $roomId = (int)$_POST['room_id'];
    if ($roomId > 0) {
        $name = next_shelf_name($conn, $roomId);
        $stmt = mysqli_prepare($conn, "INSERT INTO shelves (room_id, name) VALUES (?,?)");
        mysqli_stmt_bind_param($stmt, 'is', $roomId, $name);
        mysqli_stmt_execute($stmt);
        flash('msg', "$name ታክሏል።", 'success');
    }
    redirect('rooms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_room'])) {
    csrf_verify();
    $id = (int)$_POST['id'];
    $inUse = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE room_id=$id"))['c'];
    if ($inUse > 0) {
        flash('msg', 'ማጥፋት አይቻልም፦ መጻሕፍት ለዚህ አዳራሽ ተመድበዋል።', 'danger');
    } else {
        mysqli_query($conn, "DELETE FROM rooms WHERE id=$id");
        flash('msg', 'አዳራሹ ጠፍቷል።', 'success');
    }
    redirect('rooms.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_shelf'])) {
    csrf_verify();
    $id = (int)$_POST['id'];
    $inUse = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM books WHERE shelf_id=$id"))['c'];
    if ($inUse > 0) {
        flash('msg', 'ማጥፋት አይቻልም፦ መጻሕፍት ለዚህ መደርደሪያ ተመድበዋል።', 'danger');
    } else {
        mysqli_query($conn, "DELETE FROM shelves WHERE id=$id");
        flash('msg', 'መደርደሪያው ጠፍቷል።', 'success');
    }
    redirect('rooms.php');
}

$rooms = mysqli_query($conn, "SELECT * FROM rooms ORDER BY name ASC");

$pageTitle = __('rooms_shelves');
$activeKey = 'rooms';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
  <button class="btn btn-gold" onclick="openSheet('room-sheet')"><i class="bi bi-plus-lg"></i> አዳራሽ ጨምር</button>
</div>

<?php mysqli_data_seek($rooms, 0); while ($room = mysqli_fetch_assoc($rooms)): ?>
  <?php $shelves = mysqli_query($conn, "SELECT * FROM shelves WHERE room_id=" . (int)$room['id'] . " ORDER BY id ASC"); ?>
  <div class="card card-pad mb-3">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
      <div style="font-weight:700;color:var(--navy);display:flex;align-items:center;gap:8px;"><i class="bi bi-door-open" style="color:var(--gold-600);"></i> <?= e($room['name']) ?></div>
      <div style="display:flex;gap:6px;">
        <form method="post" style="display:inline;">
          <?= csrf_field() ?><input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
          <button class="btn btn-outline btn-sm" name="add_shelf" value="1"><i class="bi bi-plus-lg"></i> መደርደሪያ ጨምር</button>
        </form>
        <form method="post" onsubmit="return confirmAction('ይህን አዳራሽ ማጥፋት ይፈልጋሉ?', this)">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$room['id'] ?>">
          <button class="btn btn-outline btn-sm" name="delete_room" value="1" style="color:var(--danger);border-color:var(--danger);"><i class="bi bi-trash"></i></button>
        </form>
      </div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:8px;">
      <?php if (mysqli_num_rows($shelves) === 0): ?>
        <span class="text-muted" style="font-size:.82rem;">እስካሁን መደርደሪያ አልተጨመረም።</span>
      <?php endif; ?>
      <?php $shelfIdx = 0; while ($s = mysqli_fetch_assoc($shelves)): ?>
        <form method="post" style="display:flex;align-items:center;gap:6px;background:var(--paper);border:1px solid var(--line);border-radius:10px;padding:6px 10px;">
          <i class="bi bi-bookshelf" style="color:var(--gold-600);"></i> <?= e(shelf_display_name($shelfIdx)) ?>
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
          <button class="btn btn-ghost btn-sm" name="delete_shelf" value="1" style="color:var(--danger);padding:2px 6px;" onclick="return confirmAction('ይህን መደርደሪያ ማጥፋት ይፈልጋሉ?', this.form)" type="submit"><i class="bi bi-x"></i></button>
        </form>
      <?php $shelfIdx++; endwhile; ?>
    </div>
  </div>
<?php endwhile; ?>

<div class="sheet-overlay" id="room-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">አዳራሽ ጨምር</div>
    <form method="post">
      <?= csrf_field() ?>
      <div class="field"><label>የአዳራሽ ስም</label><input class="input" name="name" placeholder="ለምሳሌ፦ ዋናው አዳራሽ" required></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('room-sheet')"><?= __('cancel') ?></button>
        <button class="btn btn-gold btn-block" name="add_room" value="1"><?= __('add') ?></button>
      </div>
    </form>
  </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
