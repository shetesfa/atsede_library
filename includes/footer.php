    </div><!-- /.page -->
  </div><!-- /.main-area -->
</div><!-- /.app-shell -->

<?php if (empty($hideChrome)): include __DIR__ . '/bottomnav.php'; endif; ?>

<div id="toast-stack"></div>



<?php 
$cleanBase = rtrim(defined('BASE_URL') ? BASE_URL : '/', '/') . '/'; 
?>
<script src="<?= $cleanBase ?>assets/lib/bootstrap/bootstrap.bundle.min.js"></script>
<script>window.APP_BASE = <?= json_encode($cleanBase) ?>;</script>
<script src="<?= $cleanBase ?>assets/js/app.js?v=<?= filemtime(__DIR__ . '/../assets/js/app.js') ?>"></script>
<script src="<?= $cleanBase ?>assets/js/offline-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/offline-engine.js') ?>"></script>
<?php $f = flash('msg'); if ($f): ?>
<script>document.addEventListener('DOMContentLoaded',()=>toast(<?= json_encode($f['msg']) ?>, <?= json_encode($f['type']) ?>));</script>
<?php endif; ?>
</body>
</html>
