<?php
$roleLabels = ['admin'=>'አስተዳዳሪ','librarian'=>'ቤተ-መጻሕፍት ኃላፊ','member'=>'አባል'];
?>
<aside class="sidebar">
  <div class="brand">
    <span class="crest">
      <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">
      <?php else: ?><i class="bi bi-book-half"></i><?php endif; ?>
    </span>
    <span class="brand-text"><?= e($siteName) ?></span>
  </div>
  <nav>
    <?php foreach ($navItems as $item): ?>
      <a href="<?= e($item['href']) ?>" class="<?= $activeKey === $item['key'] ? 'active' : '' ?>">
        <i class="bi <?= e($item['icon']) ?>"></i> <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>
    <a href="javascript:void(0)" id="pwa-install-sidebar" style="display:none;" onclick="installApp()">
      <i class="bi bi-arrow-down-square"></i> መተግበሪያውን ይጫኑ
    </a>
  </nav>
  <div class="sidebar-foot">
    <?php if ($user): ?>
      <div style="color:#fff;font-weight:700;font-size:.85rem;margin-bottom:4px;"><?= e($user['full_name']) ?></div>
      <div style="margin-bottom:10px;"><?= e($roleLabels[$role] ?? $role) ?></div>
      <a href="<?= $base ?>logout.php" style="color:var(--gold);font-weight:700;"><i class="bi bi-box-arrow-right"></i> <?= __('sign_out') ?></a>
    <?php else: ?>
      የኢትዮጵያ ኦርቶዶክስ ተዋህዶ ቤተ ክርስቲያን ሀያት አምባሳደር ቅዱስ ጊዮርጊስ ቅድስት ኪዳነምህረት ቤ/ን <span style="font-weight:800;font-family:'Noto Serif Ethiopic',serif;color:var(--gold);font-size:1rem;">አጸደ ትጉሃን ሰንበት ትምህርት ቤት</span>
    <?php endif; ?>
  </div>
</aside>
