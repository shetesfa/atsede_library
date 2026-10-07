    </div><!-- /.page -->
  </div><!-- /.main-area -->
</div><!-- /.app-shell -->

<?php if (empty($hideChrome)): include __DIR__ . '/bottomnav.php'; endif; ?>

<div id="toast-stack"></div>



<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php 
$cleanBase = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/') . '/'; 
?>
<script>window.APP_BASE = <?= json_encode($cleanBase) ?>;</script>
<script src="<?= $cleanBase ?>assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="<?= $cleanBase ?>assets/js/offline-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/offline-engine.js') ?>"></script>
<?php $f = flash('msg'); if ($f): ?>
<script>document.addEventListener('DOMContentLoaded',()=>toast(<?= json_encode($f['msg']) ?>, <?= json_encode($f['type']) ?>));</script>
<?php endif; ?>
</body>
</html>
