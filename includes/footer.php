    </div><!-- /.page -->
  </div><!-- /.main-area -->
</div><!-- /.app-shell -->

<?php if (empty($hideChrome)): include __DIR__ . '/bottomnav.php'; endif; ?>

<div id="toast-stack"></div>

<div class="install-banner" id="install-banner" style="display:none;position:fixed;left:12px;right:12px;bottom:calc(var(--bottomnav-h) + 12px);z-index:60;">
  <i class="bi bi-phone"></i>
  <div class="txt"><?= e($siteName) ?>ን በስልክዎ ላይ ይጫኑ፣ በቀላሉ ለመክፈት።</div>
  <button class="btn btn-gold btn-sm" onclick="installApp()">ይጫኑ</button>
  <button class="btn btn-ghost btn-sm" onclick="dismissInstallBanner()"><i class="bi bi-x"></i></button>
</div>

<div class="sheet-overlay" id="install-help-sheet">
  <div class="sheet" style="max-width:420px;">
    <div class="sheet-handle"></div>
    <div class="sheet-title">መተግበሪያውን በስልክ ላይ መጫን</div>
    <p class="text-muted" style="font-size:.86rem;line-height:1.55;">
      አንዳንድ ስልኮች ቀጥታ የመጫኛ መልዕክት አያቀርቡም። ከታች ያለውን ይከተሉ፦
    </p>
    <div style="display:grid;gap:10px;margin:12px 0 14px;">
      <div class="card card-pad" id="install-help-android" style="padding:12px 14px;">
        <strong style="color:var(--navy);">Android</strong>
        <div class="text-muted" style="font-size:.82rem;">የአሳሹን ሜኑ (⋮) ይክፈቱ፣ ከዚያ <strong>Install app</strong> ወይም <strong>Add to Home screen</strong> ይምረጡ።</div>
      </div>
      <div class="card card-pad" id="install-help-ios" style="padding:12px 14px;display:none;">
        <strong style="color:var(--navy);">iPhone / iPad (Safari)</strong>
        <ol class="text-muted" style="font-size:.82rem;margin:8px 0 0 18px;padding:0;line-height:1.55;">
          <li>በSafari ይክፈቱ (Chrome አይሰራም)</li>
          <li>በታች <strong>Share</strong> <i class="bi bi-box-arrow-up"></i> ቁልፍን ይጫኑ</li>
          <li><strong>Add to Home Screen</strong> ይምረጡ</li>
          <li><strong>Add</strong> ይጫኑ</li>
        </ol>
      </div>
    </div>
    <button class="btn btn-gold btn-block" type="button" onclick="closeSheet('install-help-sheet')">ገባ</button>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php $baseUrl = defined('BASE_URL') ? BASE_URL : '/'; ?>
<script src="<?= $baseUrl ?>assets/js/app.js"></script>
<?php $f = flash('msg'); if ($f): ?>
<script>document.addEventListener('DOMContentLoaded',()=>toast(<?= json_encode($f['msg']) ?>, <?= json_encode($f['type']) ?>));</script>
<?php endif; ?>
</body>
</html>
