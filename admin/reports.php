<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/nav_config.php';
require_role('admin');

$statusBreakdown = mysqli_query($conn, "SELECT borrow_status, COUNT(*) c FROM books GROUP BY borrow_status");
$statusData = ['available'=>0,'restricted'=>0,'reference'=>0,'archived'=>0];
while ($r = mysqli_fetch_assoc($statusBreakdown)) $statusData[$r['borrow_status']] = (int)$r['c'];
$totalBooks = array_sum($statusData);

$topBooks = mysqli_query($conn, "
  SELECT b.title, COUNT(*) borrows FROM borrow_records br JOIN books b ON b.id=br.book_id
  GROUP BY br.book_id ORDER BY borrows DESC LIMIT 10");

$monthly = mysqli_query($conn, "
  SELECT DATE_FORMAT(borrowed_at, '%b %y') label, COUNT(*) c FROM borrow_records
  WHERE borrowed_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY DATE_FORMAT(borrowed_at, '%Y-%m') ORDER BY DATE_FORMAT(borrowed_at, '%Y-%m') ASC");
$monthLabels = []; $monthCounts = [];
$totalBorrows6m = 0;
while ($r = mysqli_fetch_assoc($monthly)) {
    $monthLabels[] = $r['label'];
    $monthCounts[] = (int)$r['c'];
    $totalBorrows6m += (int)$r['c'];
}

$categoryBreakdown = mysqli_query($conn, "SELECT c.name, COUNT(*) c FROM books b JOIN categories c ON c.id=b.category_id GROUP BY c.id ORDER BY c DESC");
$catLabels = []; $catCounts = [];
while ($r = mysqli_fetch_assoc($categoryBreakdown)) { $catLabels[] = $r['name']; $catCounts[] = (int)$r['c']; }

$registeredBooks = mysqli_query($conn, "
  SELECT b.title, b.author, c.name AS category_name, b.created_at,
    (SELECT COUNT(*) FROM book_copies WHERE book_id=b.id) AS copies
  FROM books b LEFT JOIN categories c ON c.id=b.category_id
  ORDER BY b.created_at DESC LIMIT 100");

$recentRegistrations = mysqli_query($conn, "
  SELECT DATE(created_at) d, COUNT(*) c FROM books
  WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
  GROUP BY DATE(created_at) ORDER BY d DESC LIMIT 30");

$totalMembers = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM users WHERE role='member' AND status='active'"))['c'];
$pendingRequests = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_requests WHERE status='pending'"))['c'];
$activeLoans = (int)mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) c FROM borrow_records WHERE status='borrowed'"))['c'];

$pageTitle = __('reports');
$activeKey = 'reports';
include __DIR__ . '/../includes/header.php';
?>

<div class="print-header">
  <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?><span class="crest" style="background:radial-gradient(circle at 30% 30%,var(--gold),var(--gold-600));border-radius:50%;display:flex;align-items:center;justify-content:center;color:var(--navy);"><i class="bi bi-book-half"></i></span><?php endif; ?>
  <div>
    <div style="font-family:var(--font-display);font-weight:700;font-size:1.1rem;color:var(--navy);"><?= e($siteName) ?></div>
    <div class="text-muted" style="font-size:.8rem;">ሪፖርት የተዘጋጀበት ቀን፦ <?= formatDate(date('Y-m-d')) ?></div>
  </div>
</div>

<div class="no-print" style="display:flex;justify-content:flex-end;margin-bottom:10px;">
  <button class="btn btn-outline btn-sm" onclick="window.print()"><i class="bi bi-printer"></i> ሪፖርት አትም</button>
</div>

<div class="section-title" style="margin-top:0;">የማጠቃለያ ሪፖርት (ጽሁፍ)</div>
<div class="card card-pad mb-3" style="font-size:.88rem;line-height:1.75;">
  <p><strong>ጠቅላላ መጻሕፍት፦</strong> <?= $totalBooks ?> — ከእነዚህ <?= $statusData['available'] ?> ይገኛሉ፣ <?= $statusData['restricted'] ?> ለውሰት ያልተፈቀዱ፣ <?= $statusData['reference'] ?> ለንባብ ብቻ፣ <?= $statusData['archived'] ?> በማህደር።</p>
  <p><strong>ንቁ አባላት፦</strong> <?= $totalMembers ?> · <strong>በመጠባበቅ ላይ ያሉ ጥያቄዎች፦</strong> <?= $pendingRequests ?> · <strong>አሁን በውሰት ላይ፦</strong> <?= $activeLoans ?> መጽሐፍት።</p>
  <p><strong>ባለፉት 6 ወራት ጠቅላላ ውሶች፦</strong> <?= $totalBorrows6m ?><?php if ($monthLabels): ?> — <?php
    $parts = [];
    foreach ($monthLabels as $i => $lbl) $parts[] = "$lbl: {$monthCounts[$i]}";
    echo implode(' · ', $parts);
  ?><?php endif; ?>.</p>
  <?php if ($catLabels): ?>
  <p><strong>መጻሕፍት በምድብ፦</strong> <?php
    $parts = [];
    foreach ($catLabels as $i => $lbl) $parts[] = "$lbl ({$catCounts[$i]})";
    echo implode(' · ', $parts);
  ?>.</p>
  <?php endif; ?>
</div>

<div class="section-title">የመጻሕፍት ምዝገባ (ቀን እና ጊዜ)</div>
<div class="table-wrap mb-3">
  <table class="app-table app-stack">
    <thead><tr><th>የመጽሐፍ ስም</th><th>ደራሲ</th><th>ምድብ</th><th>ቅጂዎች</th><th>የተመዘገበበት</th></tr></thead>
    <tbody>
      <?php if (mysqli_num_rows($registeredBooks) === 0): ?>
        <tr><td colspan="5"><div class="empty-state"><i class="bi bi-journal"></i><h4>እስካሁን መጽሐፍ አልተመዘገበም</h4></div></td></tr>
      <?php endif; ?>
      <?php while ($rb = mysqli_fetch_assoc($registeredBooks)): ?>
      <tr>
        <td data-label="ስም"><?= e($rb['title']) ?></td>
        <td data-label="ደራሲ"><?= e($rb['author']) ?></td>
        <td data-label="ምድብ"><?= e($rb['category_name'] ?: '—') ?></td>
        <td data-label="ቅጂዎች"><?= (int)$rb['copies'] ?></td>
        <td data-label="ተመዝግቦ"><?= formatDate($rb['created_at']) ?> · <?= date('H:i', strtotime($rb['created_at'])) ?></td>
      </tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<?php if (mysqli_num_rows($recentRegistrations) > 0): ?>
<div class="section-title">ዕለታዊ ምዝገባ (6 ወር)</div>
<div class="card card-pad mb-3" style="font-size:.86rem;line-height:1.7;">
  <?php while ($rr = mysqli_fetch_assoc($recentRegistrations)): ?>
    <div><?= formatDate($rr['d']) ?> — <strong><?= (int)$rr['c'] ?></strong> መጽሐፍ(ት) ተመዝግቧል</div>
  <?php endwhile; ?>
</div>
<?php endif; ?>

<div class="section-title">የውሰት እንቅስቃሴ (6 ወር)</div>
<div class="card card-pad mb-3">
  <p class="text-muted" style="font-size:.84rem;margin-bottom:10px;"><?php
    if (!$monthLabels) echo 'እስካሁን የውሰት ታሪክ የለም።';
    else {
      $parts = [];
      foreach ($monthLabels as $i => $lbl) $parts[] = "$lbl ውስጥ {$monthCounts[$i]} ውሶች";
      echo implode(' · ', $parts) . '።';
    }
  ?></p>
  <canvas id="trendChart" height="160"></canvas>
</div>

<div class="row g-2">
  <div class="col-12 col-md-6">
    <div class="section-title">መጻሕፍት በሁኔታ</div>
    <div class="card card-pad mb-3">
      <p style="font-size:.84rem;margin-bottom:10px;">ይገኛል <?= $statusData['available'] ?> · restricted <?= $statusData['restricted'] ?> · reference <?= $statusData['reference'] ?> · archived <?= $statusData['archived'] ?></p>
      <canvas id="statusChart" height="200"></canvas>
    </div>
  </div>
  <div class="col-12 col-md-6">
    <div class="section-title">መጻሕፍት በምድብ</div>
    <div class="card card-pad mb-3">
      <?php if ($catLabels): ?><p style="font-size:.84rem;margin-bottom:10px;"><?php
        $parts = [];
        foreach ($catLabels as $i => $lbl) $parts[] = "$lbl: {$catCounts[$i]}";
        echo implode(' · ', $parts);
      ?></p><?php endif; ?>
      <canvas id="catChart" height="200"></canvas>
    </div>
  </div>
</div>

<div class="section-title">በብዛት የተወሰዱ መጻሕፍት</div>
<div class="table-wrap">
  <table class="app-table app-stack">
    <thead><tr><th>የመጽሐፍ ስም</th><th>የተወሰደበት ብዛት</th></tr></thead>
    <tbody>
      <?php if (mysqli_num_rows($topBooks) === 0): ?><tr><td colspan="2"><div class="empty-state"><i class="bi bi-bar-chart"></i><h4>እስካሁን የውሰት ታሪክ የለም</h4></div></td></tr><?php endif; ?>
      <?php while ($b = mysqli_fetch_assoc($topBooks)): ?>
        <tr><td data-label="የመጽሐፍ ስም"><?= e($b['title']) ?></td><td data-label="ብዛት"><span class="badge badge-gold"><?= (int)$b['borrows'] ?></span></td></tr>
      <?php endwhile; ?>
    </tbody>
  </table>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
const navy = '#0F172A', gold = '#D4AF37', success='#16A34A', warning='#F59E0B', danger='#DC2626';
new Chart(document.getElementById('trendChart'), {
  type: 'line',
  data: { labels: <?= json_encode($monthLabels) ?>, datasets: [{ label: 'ውሶች', data: <?= json_encode($monthCounts) ?>, borderColor: gold, backgroundColor: 'rgba(212,175,55,.15)', fill: true, tension: .35 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
new Chart(document.getElementById('statusChart'), {
  type: 'doughnut',
  data: { labels: ['ይገኛል','ለውሰት ያልተፈቀደ','ለንባብ ብቻ','ማህደር'],
    datasets: [{ data: [<?= $statusData['available'] ?>,<?= $statusData['restricted'] ?>,<?= $statusData['reference'] ?>,<?= $statusData['archived'] ?>], backgroundColor: [success, warning, gold, danger] }] },
  options: { plugins: { legend: { position: 'bottom' } } }
});
new Chart(document.getElementById('catChart'), {
  type: 'bar',
  data: { labels: <?= json_encode($catLabels) ?>, datasets: [{ data: <?= json_encode($catCounts) ?>, backgroundColor: navy, borderRadius: 6 }] },
  options: { plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true } } }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
