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
    $customName = clean($_POST['shelf_name'] ?? '');
    if ($roomId > 0) {
        $name = $customName !== '' ? $customName : next_shelf_name($conn, $roomId);
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

$rooms = mysqli_query($conn, "SELECT * FROM rooms ORDER BY id ASC");

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
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
      <div style="font-weight:800;color:var(--navy);font-size:1.05rem;display:flex;align-items:center;gap:8px;">
        <i class="bi bi-door-open text-gold" style="font-size:1.3rem;"></i> <?= e($room['name']) ?>
      </div>
      <div style="display:flex;gap:6px;">
        <form method="post" style="display:inline-flex;gap:4px;">
          <?= csrf_field() ?>
          <input type="hidden" name="room_id" value="<?= (int)$room['id'] ?>">
          <input type="text" name="shelf_name" placeholder="የመደርደሪያ ስም..." class="input" style="padding:4px 8px;font-size:.8rem;width:140px;">
          <button class="btn btn-navy btn-sm" name="add_shelf" value="1"><i class="bi bi-plus-lg"></i> ጨምር</button>
        </form>
      </div>
    </div>
    
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
      <?php if (mysqli_num_rows($shelves) === 0): ?>
        <span class="text-muted" style="font-size:.82rem;">እስካሁን መደርደሪያ አልተጨመረም።</span>
      <?php endif; ?>
      <?php $shelfIdx = 0; while ($s = mysqli_fetch_assoc($shelves)): 
        $bCountRes = mysqli_query($conn, "SELECT COUNT(*) as c FROM books WHERE shelf_id = " . (int)$s['id']);
        $bCount = $bCountRes ? (int)mysqli_fetch_assoc($bCountRes)['c'] : 0;
      ?>
        <div style="display:flex;align-items:center;gap:8px;background:#fff;border:1.5px solid var(--line);border-radius:10px;padding:8px 12px;box-shadow:0 1px 3px rgba(0,0,0,0.04);">
          <i class="bi bi-bookshelf text-gold" style="font-size:1.2rem;"></i>
          <div>
            <div style="font-weight:700;color:var(--navy);font-size:.88rem;"><?= e($s['name'] ?: ('መደርደሪያ ' . ($shelfIdx + 1))) ?></div>
            <div style="font-size:.76rem;color:var(--muted);"><span class="badge <?= $bCount > 0 ? 'badge-success' : 'badge-muted' ?>" style="font-size:.7rem;padding:2px 6px;"><?= $bCount ?> መጻሕፍት</span></div>
          </div>
          <form method="post" style="margin:0;margin-left:6px;">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <button class="btn btn-ghost btn-sm" name="delete_shelf" value="1" style="color:var(--danger);padding:2px 6px;" onclick="return confirmAction('ይህን መደርደሪያ ማጥፋት ይፈልጋሉ?', this.form)" type="submit" title="መደርደሪያ ሰርዝ"><i class="bi bi-x-lg"></i></button>
          </form>
        </div>
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
