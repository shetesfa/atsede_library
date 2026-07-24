<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role(['librarian','admin']);

$user = current_user();
$categories = mysqli_query($conn, "SELECT * FROM categories ORDER BY name ASC");
$rooms = mysqli_query($conn, "SELECT * FROM rooms ORDER BY name ASC");

$borrowStatusLabels = ['available'=>__('available'),'restricted'=>__('restricted'),'reference'=>__('reference'),'archived'=>__('archived')];
$copyStatusLabels = ['available'=>__('available'),'borrowed'=>__('borrowed'),'lost'=>__('lost'),'damaged'=>__('damaged'),'archived'=>__('archived')];

$duplicateMatch = null; // set when a possible duplicate title+author is detected

// ---- Create / Update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_book'])) {
    csrf_verify();
    $bookId = (int)($_POST['book_id'] ?? 0);
    $title = clean($_POST['title'] ?? '');
    $author = clean($_POST['author'] ?? '');
    $categoryId = (int)($_POST['category_id'] ?? 0);
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $year = clean($_POST['year'] ?? '') ?: null;
    $publisher = clean($_POST['publisher'] ?? '') ?: null;
    $description = clean($_POST['description'] ?? '') ?: null;
    $price = ($_POST['price'] ?? '') !== '' ? (float)$_POST['price'] : null;
    $roomId = (int)($_POST['room_id'] ?? 0) ?: null;
    $shelfId = (int)($_POST['shelf_id'] ?? 0) ?: null;
    $position = clean($_POST['position'] ?? '') ?: null;
    $borrowStatus = in_array($_POST['borrow_status'] ?? '', ['available','restricted','reference','archived']) ? $_POST['borrow_status'] : 'available';
    $confirmDuplicate = !empty($_POST['confirm_duplicate']);

    $coverName = null;
    if (!empty($_FILES['cover_image']['name'])) {
        @mkdir(UPLOAD_DIR, 0755, true);
        $ext = strtolower(pathinfo($_FILES['cover_image']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg','jpeg','png','webp'])) {
            $coverName = 'cover_' . time() . '_' . random_int(1000,9999) . '.' . $ext;
            move_uploaded_file($_FILES['cover_image']['tmp_name'], UPLOAD_DIR . $coverName);
        }
    }

    if ($title === '' || $author === '' || $categoryId === 0) {
        flash('msg', 'የመጽሐፍ ስም፣ ደራሲ እና ምድብ ያስፈልጋሉ።', 'danger');
        redirect('books.php');
    }

    // ---- Duplicate check (new books only) ----
    if ($bookId === 0 && !$confirmDuplicate) {
        $dupStmt = mysqli_prepare($conn, "SELECT * FROM books WHERE LOWER(title)=LOWER(?) AND LOWER(author)=LOWER(?) LIMIT 1");
        mysqli_stmt_bind_param($dupStmt, 'ss', $title, $author);
        mysqli_stmt_execute($dupStmt);
        $duplicateMatch = mysqli_fetch_assoc(mysqli_stmt_get_result($dupStmt));

        if ($duplicateMatch) {
            // Stash this submission so either choice below can act on it without re-uploading the file
            $_SESSION['pending_book'] = [
                'title'=>$title,'author'=>$author,'category_id'=>$categoryId,'quantity'=>$quantity,
                'year'=>$year,'publisher'=>$publisher,'description'=>$description,'price'=>$price,
                'room_id'=>$roomId,'shelf_id'=>$shelfId,'position'=>$position,'borrow_status'=>$borrowStatus,
                'cover_name'=>$coverName,
            ];
            $_SESSION['pending_duplicate_id'] = $duplicateMatch['id'];
            // fall through to render the page with the duplicate-warning sheet open
        }
    }

    if (!$duplicateMatch) {
        if ($bookId > 0) {
            $sql = "UPDATE books SET title=?, author=?, category_id=?, quantity=?, publication_year=?, publisher=?, description=?, price=?, room_id=?, shelf_id=?, position=?, borrow_status=?" . ($coverName ? ", cover_image=?" : "") . " WHERE id=?";
            $stmt = mysqli_prepare($conn, $sql);
            if ($coverName) {
                mysqli_stmt_bind_param($stmt, 'ssiisssdiisssi', $title, $author, $categoryId, $quantity, $year, $publisher, $description, $price, $roomId, $shelfId, $position, $borrowStatus, $coverName, $bookId);
            } else {
                mysqli_stmt_bind_param($stmt, 'ssiisssdiissi', $title, $author, $categoryId, $quantity, $year, $publisher, $description, $price, $roomId, $shelfId, $position, $borrowStatus, $bookId);
            }
            mysqli_stmt_execute($stmt);
            audit($conn, $user['id'], 'book_updated', "book_id:$bookId");
            flash('msg', 'መጽሐፉ ዘምኗል።', 'success');
        } else {
            $stmt = mysqli_prepare($conn, "INSERT INTO books (title, author, category_id, quantity, publication_year, publisher, description, price, cover_image, room_id, shelf_id, position, borrow_status, created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $createdBy = (int)$user['id'];
            mysqli_stmt_bind_param($stmt, 'ssiisssdsiissi', $title, $author, $categoryId, $quantity, $year, $publisher, $description, $price, $coverName, $roomId, $shelfId, $position, $borrowStatus, $createdBy);
            mysqli_stmt_execute($stmt);
            $bookId = mysqli_insert_id($conn);

            $codes = next_codes_for_category($conn, $categoryId, $quantity);
            foreach ($codes as $code) {
                $stmt2 = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code) VALUES (?,?)");
                mysqli_stmt_bind_param($stmt2, 'is', $bookId, $code);
                mysqli_stmt_execute($stmt2);
            }
            notify_broadcast($conn, 'አዲስ መጽሐፍ ታክሏል', "\"$title\" በ$author ወደ ቤተ መጻሕፍት ገብቷል።", 'new_book', 'book.php?id=' . $bookId);
            audit($conn, $user['id'], 'book_created', "book_id:$bookId codes:" . implode(',', $codes));
            flash('msg', 'መጽሐፉ ከእነዚህ ኮዶች ጋር ታክሏል፦ ' . implode(', ', $codes), 'success');
        }
        unset($_SESSION['pending_book'], $_SESSION['pending_duplicate_id']);
        redirect('books.php');
    }
}

// ---- Duplicate decision: add as copies to the existing title ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_existing'])) {
    csrf_verify();
    $existingId = (int)$_POST['existing_book_id'];
    $pending = $_SESSION['pending_book'] ?? null;
    if ($pending && $existingId > 0) {
        $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM books WHERE id=$existingId"));
        if ($existing) {
            $addQty = (int)$pending['quantity'];
            $codes = next_codes_for_category($conn, $existing['category_id'], $addQty);
            foreach ($codes as $code) {
                $stmt2 = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code) VALUES (?,?)");
                mysqli_stmt_bind_param($stmt2, 'is', $existingId, $code);
                mysqli_stmt_execute($stmt2);
            }
            mysqli_query($conn, "UPDATE books SET quantity = quantity + $addQty WHERE id=$existingId");
            audit($conn, $user['id'], 'book_copies_added_existing', "book_id:$existingId codes:" . implode(',', $codes));
            flash('msg', 'ለነባሩ መጽሐፍ እነዚህ አዳዲስ ቅጂዎች ታክለዋል፦ ' . implode(', ', $codes), 'success');
        }
    }
    unset($_SESSION['pending_book'], $_SESSION['pending_duplicate_id']);
    redirect('books.php');
}

// ---- Duplicate decision: create as a separate new record anyway ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['force_create_new'])) {
    csrf_verify();
    $p = $_SESSION['pending_book'] ?? null;
    if ($p) {
        $stmt = mysqli_prepare($conn, "INSERT INTO books (title, author, category_id, quantity, publication_year, publisher, description, price, cover_image, room_id, shelf_id, position, borrow_status, created_by)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $createdBy = (int)$user['id'];
        mysqli_stmt_bind_param($stmt, 'ssiisssdsiissi', $p['title'], $p['author'], $p['category_id'], $p['quantity'], $p['year'], $p['publisher'], $p['description'], $p['price'], $p['cover_name'], $p['room_id'], $p['shelf_id'], $p['position'], $p['borrow_status'], $createdBy);
        mysqli_stmt_execute($stmt);
        $newBookId = mysqli_insert_id($conn);
        $codes = next_codes_for_category($conn, $p['category_id'], $p['quantity']);
        foreach ($codes as $code) {
            $stmt2 = mysqli_prepare($conn, "INSERT INTO book_copies (book_id, copy_code) VALUES (?,?)");
            mysqli_stmt_bind_param($stmt2, 'is', $newBookId, $code);
            mysqli_stmt_execute($stmt2);
        }
        notify_broadcast($conn, 'አዲስ መጽሐፍ ታክሏል', "\"{$p['title']}\" በ{$p['author']} ወደ ቤተ መጻሕፍት ገብቷል።", 'new_book', 'book.php?id=' . $newBookId);
        audit($conn, $user['id'], 'book_created_forced', "book_id:$newBookId codes:" . implode(',', $codes));
        flash('msg', 'እንደ አዲስ መዝገብ ታክሏል። ኮዶች፦ ' . implode(', ', $codes), 'success');
    }
    unset($_SESSION['pending_book'], $_SESSION['pending_duplicate_id']);
    redirect('books.php');
}

// ---- Duplicate decision: cancel, discard the pending submission ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_duplicate'])) {
    csrf_verify();
    unset($_SESSION['pending_book'], $_SESSION['pending_duplicate_id']);
    redirect('books.php');
}

// ---- Delete ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_book'])) {
    csrf_verify();
    $bookId = (int)$_POST['book_id'];
    $active = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE book_id=$bookId AND status='borrowed'"))['c'];
    if ($active > 0) {
        flash('msg', 'ማጥፋት አይቻልም፦ ቅጂዎች በስራ ላይ ናቸው።', 'danger');
    } else {
        mysqli_query($conn, "DELETE FROM books WHERE id=$bookId");
        audit($conn, $user['id'], 'book_deleted', "book_id:$bookId");
        flash('msg', 'መጽሐፉ ጠፍቷል።', 'success');
    }
    redirect('books.php');
}

// ---- Copy status / code update ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_copy'])) {
    csrf_verify();
    $copyId = (int)$_POST['copy_id'];
    $newCode = clean($_POST['copy_code'] ?? '');
    $newStatus = in_array($_POST['copy_status'] ?? '', ['available','borrowed','lost','damaged','archived']) ? $_POST['copy_status'] : 'available';
    $stmt = mysqli_prepare($conn, "UPDATE book_copies SET copy_code=?, status=? WHERE id=?");
    mysqli_stmt_bind_param($stmt, 'ssi', $newCode, $newStatus, $copyId);
    mysqli_stmt_execute($stmt);
    flash('msg', 'ቅጂው ዘምኗል።', 'success');
    redirect('books.php?copies=' . (int)$_POST['book_id_ref']);
}

// ---- Pull duplicate state back up after a redirect-free render ----
if (!$duplicateMatch && !empty($_SESSION['pending_duplicate_id'])) {
    $duplicateMatch = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM books WHERE id=" . (int)$_SESSION['pending_duplicate_id']));
}

// ---- Data for list ----
$q = clean($_GET['q'] ?? '');
$categoryFilter = (int)($_GET['category'] ?? 0);
$where = "1=1";
if ($q !== '') {
    $like = mysqli_real_escape_string($conn, $q);
    $where .= " AND (b.title LIKE '%$like%' OR b.author LIKE '%$like%')";
}
if ($categoryFilter > 0) {
    $where .= " AND b.category_id=$categoryFilter";
}
$filterCategory = null;
if ($categoryFilter > 0) {
    $filterCategory = mysqli_fetch_assoc(mysqli_query($conn, "SELECT name FROM categories WHERE id=$categoryFilter"));
}
$books = mysqli_query($conn, "
  SELECT b.*, c.name AS category_name,
    (SELECT COUNT(*) FROM book_copies WHERE book_id=b.id) AS copy_count,
    (SELECT COUNT(*) FROM book_copies WHERE book_id=b.id AND status='available') AS available_count
  FROM books b LEFT JOIN categories c ON c.id=b.category_id WHERE $where ORDER BY b.created_at DESC");

$editBook = null;
if (!empty($_GET['edit'])) {
    $editBook = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM books WHERE id=" . (int)$_GET['edit']));
}

$editShelves = null;
if ($editBook && $editBook['room_id']) {
    $editShelves = mysqli_query($conn, "SELECT * FROM shelves WHERE room_id=" . (int)$editBook['room_id'] . " ORDER BY id ASC");
}

$copiesBookId = (int)($_GET['copies'] ?? 0);
$copiesBook = null; $copiesList = null;
if ($copiesBookId) {
    $copiesBook = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM books WHERE id=$copiesBookId"));
    $copiesList = mysqli_query($conn, "SELECT * FROM book_copies WHERE book_id=$copiesBookId ORDER BY copy_code ASC");
}

$pageTitle = __('books');
$activeKey = 'books';
include __DIR__ . '/../includes/header.php';
?>

<div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
  <form method="get" style="flex:1;min-width:200px;"><div class="input-group"><i class="bi bi-search"></i><input class="input" name="q" value="<?= e($q) ?>" placeholder="መጻሕፍትን ይፈልጉ…"><?php if ($categoryFilter): ?><input type="hidden" name="category" value="<?= $categoryFilter ?>"><?php endif; ?></div></form>
  <button class="btn btn-gold" onclick="openSheet('book-sheet')"><i class="bi bi-plus-lg"></i> <?= __('add') ?></button>
</div>

<?php if ($filterCategory): ?>
<div class="card card-pad mb-3" style="display:flex;justify-content:space-between;align-items:center;padding:10px 14px;">
  <span><strong>ምድብ፦</strong> <?= e($filterCategory['name']) ?></span>
  <a href="books.php" class="btn btn-outline btn-sm">ሁሉንም መጻሕፍት</a>
</div>
<?php endif; ?>

<div class="table-wrap">
  <table class="app-table app-stack">
    <thead><tr><th>የመጽሐፍ ስም</th><th>ምድብ</th><th>ቅጂዎች</th><th>ሁኔታ</th></tr></thead>
    <tbody>
      <?php while ($b = mysqli_fetch_assoc($books)): ?>
      <tr style="cursor:pointer;" onclick="openBookDetail(<?= htmlspecialchars(json_encode([
        'id' => (int)$b['id'],
        'title' => $b['title'],
        'author' => $b['author'],
        'category_name' => $b['category_name'] ?: '—',
        'copy_count' => (int)$b['copy_count'],
        'available_count' => (int)$b['available_count'],
        'borrow_status' => borrow_status_label($b['borrow_status']),
        'borrow_status_class' => borrow_status_class($b['borrow_status']),
        'publication_year' => $b['publication_year'] ?: '—',
        'publisher' => $b['publisher'] ?: '—',
        'price' => $b['price'] !== null ? number_format((float)$b['price'], 2) . ' ብር' : '—',
        'description' => $b['description'] ?: '—',
        'created_at' => formatDate($b['created_at']),
      ]), ENT_QUOTES, 'UTF-8') ?>)">
        <td data-label="የመጽሐፍ ስም"><strong><?= e($b['title']) ?></strong><br><span class="text-muted" style="font-size:.76rem;"><?= e($b['author']) ?></span></td>
        <td data-label="ምድብ"><?= e($b['category_name']) ?></td>
        <td data-label="ቅጂዎች"><span class="shelf-tag" onclick="event.stopPropagation(); location.href='?copies=<?= (int)$b['id'] ?><?= $categoryFilter ? '&category='.$categoryFilter : '' ?>'"><?= (int)$b['available_count'] ?>/<?= (int)$b['copy_count'] ?> ነፃ</span></td>
        <td data-label="ሁኔታ"><span class="badge <?= borrow_status_class($b['borrow_status']) ?>"><?= borrow_status_label($b['borrow_status']) ?></span></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<!-- Book detail sheet -->
<div class="sheet-overlay" id="book-detail-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" id="bd-title">መጽሐፍ</div>
    <div id="bd-body" style="font-size:.86rem;line-height:1.65;"></div>
    <div style="display:flex;gap:8px;margin-top:14px;">
      <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('book-detail-sheet')"><?= __('close') ?></button>
      <a class="btn btn-gold btn-block" id="bd-edit-btn" href="#" onclick="closeSheet('book-detail-sheet')"><i class="bi bi-pencil"></i> አርም</a>
    </div>
    <button type="button" class="btn btn-outline btn-block mt-2" id="bd-delete-btn" style="color:var(--danger);border-color:var(--danger);display:none;" onclick=""><i class="bi bi-trash"></i> መጽሐፍ ሰርዝ</button>
  </div>
</div>

<!-- Delete confirmation sheet -->
<div class="sheet-overlay" id="delete-book-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title" style="color:var(--danger);">መጽሐፍ ማጥፋት</div>
    <p class="text-muted" style="font-size:.84rem;" id="delete-book-name"></p>
    <p style="font-size:.84rem;">ለማጥፋት ከታች <strong>«ይህን መጽሐፍ ሰርዝ»</strong> በትክክል ይፃፉ።</p>
    <form method="post" id="delete-book-form" onsubmit="return confirmBookDelete(this)">
      <?= csrf_field() ?>
      <input type="hidden" name="book_id" id="delete-book-id" value="">
      <div class="field"><input class="input" id="delete-book-confirm" placeholder="ይህን መጽሐፍ ሰርዝ" autocomplete="off"></div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('delete-book-sheet')"><?= __('cancel') ?></button>
        <button class="btn btn-block" name="delete_book" value="1" style="background:var(--danger);color:#fff;border:none;">ሰርዝ</button>
      </div>
    </form>
  </div>
</div>

<!-- Add / Edit sheet -->
<div class="sheet-overlay <?= ($editBook || !empty($_GET['new'])) && !$duplicateMatch ? 'show' : '' ?>" id="book-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title"><?= $editBook ? 'መጽሐፍ አርም' : 'አዲስ መጽሐፍ ጨምር' ?></div>
    <form method="post" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="book_id" value="<?= (int)($editBook['id'] ?? 0) ?>">
      <div class="row g-2">
        <div class="col-12"><div class="field"><label><?= __('book_name') ?></label><input class="input" name="title" required value="<?= e($editBook['title'] ?? '') ?>"></div></div>
        <div class="col-12"><div class="field"><label><?= __('author') ?></label><input class="input" name="author" required value="<?= e($editBook['author'] ?? '') ?>"></div></div>
        <div class="col-6">
          <div class="field"><label><?= __('category') ?></label>
            <select class="input" name="category_id" id="category_id" required>
              <option value="">ይምረጡ…</option>
              <?php mysqli_data_seek($categories, 0); while ($c = mysqli_fetch_assoc($categories)): ?>
                <option value="<?= (int)$c['id'] ?>" <?= (($editBook['category_id'] ?? 0) == $c['id']) ? 'selected' : '' ?>><?= e($c['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="col-6">
          <div class="field"><label><?= __('quantity') ?></label><input class="input" type="number" min="1" name="quantity" id="quantity" value="<?= e($editBook['quantity'] ?? 1) ?>" <?= $editBook ? 'disabled' : '' ?> <?= $editBook ? '' : 'required' ?>>
            <?php if ($editBook): ?><input type="hidden" name="quantity" value="<?= (int)$editBook['quantity'] ?>"><div class="hint">ግል ቅጂዎችን ለየብቻ ያስተናግዱ።</div><?php endif; ?>
          </div>
        </div>
        <?php if (!$editBook): ?>
        <div class="col-12"><div class="hint" id="code-preview" style="margin:-6px 0 6px;color:var(--gold-600);font-weight:600;"></div></div>
        <?php endif; ?>
        <div class="col-6"><div class="field"><label><?= __('publication_year') ?></label><input class="input" name="year" value="<?= e($editBook['publication_year'] ?? '') ?>"></div></div>
        <div class="col-6"><div class="field"><label><?= __('price') ?> (ብር)</label><input class="input" type="number" step="0.01" name="price" value="<?= e($editBook['price'] ?? '') ?>"></div></div>
        <div class="col-12"><div class="field"><label><?= __('publisher') ?></label><input class="input" name="publisher" value="<?= e($editBook['publisher'] ?? '') ?>"></div></div>
        <div class="col-6">
          <div class="field"><label><?= __('room') ?></label>
            <select class="input" name="room_id" id="room_id">
              <option value="0">—</option>
              <?php mysqli_data_seek($rooms, 0); while ($r = mysqli_fetch_assoc($rooms)): ?>
                <option value="<?= (int)$r['id'] ?>" <?= (($editBook['room_id'] ?? 0) == $r['id']) ? 'selected' : '' ?>><?= e($r['name']) ?></option>
              <?php endwhile; ?>
            </select>
          </div>
        </div>
        <div class="col-6">
          <div class="field"><label><?= __('shelf') ?></label>
            <select class="input" name="shelf_id" id="shelf_id">
              <option value="0">—</option>
              <?php if ($editShelves): $si = 0; mysqli_data_seek($editShelves, 0); while ($s = mysqli_fetch_assoc($editShelves)): ?>
                <option value="<?= (int)$s['id'] ?>" <?= (($editBook['shelf_id'] ?? 0) == $s['id']) ? 'selected' : '' ?>><?= e(shelf_display_name($si)) ?></option>
              <?php $si++; endwhile; endif; ?>
            </select>
          </div>
        </div>
        <div class="col-12"><div class="field"><label><?= __('position') ?></label><input class="input" name="position" placeholder="ለምሳሌ፦ የላይ መደርደሪያ፣ ግራ" value="<?= e($editBook['position'] ?? '') ?>"></div></div>
        <div class="col-12">
          <div class="field"><label><?= __('borrow_status') ?></label>
            <select class="input" name="borrow_status">
              <?php foreach ($borrowStatusLabels as $val=>$lbl): ?>
                <option value="<?= $val ?>" <?= ($editBook['borrow_status'] ?? 'available') === $val ? 'selected' : '' ?>><?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="col-12"><div class="field"><label><?= __('cover_image') ?></label><input class="input" type="file" name="cover_image" accept="image/*"></div></div>
        <div class="col-12"><div class="field"><label><?= __('description') ?></label><textarea class="input" name="description" rows="3"><?= e($editBook['description'] ?? '') ?></textarea></div></div>
      </div>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('book-sheet')"><?= __('cancel') ?></button>
        <button class="btn btn-gold btn-block" name="save_book" value="1"><?= $editBook ? __('save_changes') : 'መጽሐፍ ጨምር' ?></button>
      </div>
      <?php if ($editBook): ?>
      <button type="button" class="btn btn-outline btn-block mt-2" style="color:var(--danger);border-color:var(--danger);" onclick="openDeleteBook(<?= (int)$editBook['id'] ?>, '<?= e(addslashes($editBook['title'])) ?>')"><i class="bi bi-trash"></i> መጽሐፍ ሰርዝ</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<!-- Duplicate warning sheet -->
<div class="sheet-overlay <?= $duplicateMatch ? 'show' : '' ?>" id="dup-sheet">
  <div class="sheet" style="text-align:center;">
    <div class="sheet-handle"></div>
    <i class="bi bi-exclamation-triangle" style="font-size:2rem;color:var(--warning);"></i>
    <div class="sheet-title" style="margin-top:8px;">ተመሳሳይ መጽሐፍ ተገኝቷል</div>
    <p class="text-muted" style="font-size:.86rem;">"<strong><?= e($duplicateMatch['title'] ?? '') ?></strong>" በ<?= e($duplicateMatch['author'] ?? '') ?> ስም ቀደም ሲል ተመዝግቧል። ምን ማድረግ ይፈልጋሉ?</p>
    <form method="post" class="mt-2">
      <?= csrf_field() ?>
      <input type="hidden" name="existing_book_id" value="<?= (int)($duplicateMatch['id'] ?? 0) ?>">
      <button class="btn btn-gold btn-block mb-2" name="add_to_existing" value="1"><i class="bi bi-plus-circle"></i> ለነባሩ መጽሐፍ ቅጂዎችን ጨምር</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-outline btn-block mb-2" name="force_create_new" value="1"><i class="bi bi-journal-plus"></i> እንደ አዲስ የተለየ መዝገብ ፍጠር</button>
    </form>
    <form method="post">
      <?= csrf_field() ?>
      <button class="btn btn-ghost btn-block" name="cancel_duplicate" value="1"><?= __('cancel') ?></button>
    </form>
  </div>
</div>

<!-- Copies management sheet -->
<div class="sheet-overlay <?= $copiesBookId ? 'show' : '' ?>" id="copies-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">ቅጂዎች — <?= e($copiesBook['title'] ?? '') ?></div>
    <?php if ($copiesList): mysqli_data_seek($copiesList, 0); while ($c = mysqli_fetch_assoc($copiesList)): ?>
      <form method="post" style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
        <?= csrf_field() ?>
        <input type="hidden" name="copy_id" value="<?= (int)$c['id'] ?>">
        <input type="hidden" name="book_id_ref" value="<?= $copiesBookId ?>">
        <input class="input mono" name="copy_code" value="<?= e($c['copy_code']) ?>" style="width:90px;flex:0 0 90px;">
        <select class="input" name="copy_status" style="flex:1;">
          <?php foreach ($copyStatusLabels as $sKey => $sLbl): ?>
            <option value="<?= $sKey ?>" <?= $c['status']===$sKey?'selected':'' ?>><?= e($sLbl) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-navy btn-sm" name="update_copy" value="1"><i class="bi bi-save"></i></button>
      </form>
    <?php endwhile; endif; ?>
    <button class="btn btn-outline btn-block" type="button" onclick="closeSheet('copies-sheet')"><?= __('close') ?></button>
  </div>
</div>

<script>
let deleteConfirmStep = 0;

function openBookDetail(b) {
  document.getElementById('bd-title').textContent = b.title;
  document.getElementById('bd-body').innerHTML =
    '<div style="margin-bottom:8px;"><span class="text-muted">ደራሲ፦</span> ' + b.author + '</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ምድብ፦</span> ' + b.category_name + '</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ቅጂዎች፦</span> ' + b.available_count + '/' + b.copy_count + ' ነፃ</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ሁኔታ፦</span> <span class="badge ' + b.borrow_status_class + '">' + b.borrow_status + '</span></div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ዓ.ም፦</span> ' + b.publication_year + '</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">አሳራ፦</span> ' + b.publisher + '</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ዋጋ፦</span> ' + b.price + '</div>' +
    '<div style="margin-bottom:8px;"><span class="text-muted">ተመዝግቦ፦</span> ' + b.created_at + '</div>' +
    (b.description !== '—' ? '<div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line);"><span class="text-muted">መግለጫ፦</span><br>' + b.description + '</div>' : '');
  document.getElementById('bd-edit-btn').href = '?edit=' + b.id + '<?= $categoryFilter ? '&category='.$categoryFilter : '' ?>';
  const delBtn = document.getElementById('bd-delete-btn');
  delBtn.style.display = 'block';
  delBtn.onclick = () => { closeSheet('book-detail-sheet'); openDeleteBook(b.id, b.title); };
  openSheet('book-detail-sheet');
}

function openDeleteBook(id, title) {
  closeSheet('book-sheet');
  deleteConfirmStep = 0;
  document.getElementById('delete-book-id').value = id;
  document.getElementById('delete-book-name').textContent = '«' + title + '»';
  document.getElementById('delete-book-confirm').value = '';
  openSheet('delete-book-sheet');
}

function confirmBookDelete(form) {
  const phrase = document.getElementById('delete-book-confirm').value.trim();
  if (phrase !== 'ይህን መጽሐፍ ሰርዝ') {
    toast('«ይህን መጽሐፍ ሰርዝ» በትክክል ይፃፉ።', 'warning');
    return false;
  }
  deleteConfirmStep++;
  if (deleteConfirmStep < 3) {
    toast('ማረጋገጫ ' + deleteConfirmStep + '/3 — እንደገና «ሰርዝ» ይጫኑ።', 'warning');
    return false;
  }
  return true;
}

const catSelect = document.getElementById('category_id');
const qtyInput = document.getElementById('quantity');
const preview = document.getElementById('code-preview');
function updateCodePreview() {
  if (!catSelect || !preview || !catSelect.value) { if(preview) preview.textContent=''; return; }
  fetch(window.APP_BASE + 'ajax/preview_codes.php?category_id=' + catSelect.value + '&quantity=' + (qtyInput.value || 1))
    .then(r => r.json()).then(d => { preview.textContent = d.codes && d.codes.length ? 'የሚፈጠሩ ኮዶች: ' + d.codes.join(', ') : ''; });
}
if (catSelect) { catSelect.addEventListener('change', updateCodePreview); qtyInput.addEventListener('input', debounce(updateCodePreview, 250)); }

const roomSelect = document.getElementById('room_id');
const shelfSelect = document.getElementById('shelf_id');
if (roomSelect) {
  roomSelect.addEventListener('change', () => {
    shelfSelect.innerHTML = '<option value="0">—</option>';
    if (!roomSelect.value || roomSelect.value === '0') return;
    fetch(window.APP_BASE + 'ajax/shelves_by_room.php?room_id=' + roomSelect.value)
      .then(r => r.json()).then(d => {
        (d.shelves || []).forEach(s => {
          const opt = document.createElement('option');
          opt.value = s.id; opt.textContent = s.name;
          shelfSelect.appendChild(opt);
        });
      });
  });
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
