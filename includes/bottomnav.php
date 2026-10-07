<?php
$primary = array_values(array_filter($navItems, fn($i) => !empty($i['primary'])));
$overflow = array_values(array_filter($navItems, fn($i) => empty($i['primary'])));
$primary = array_slice($primary, 0, 5);
$showMoreSheet = !empty($overflow);
?>
<nav class="bottomnav">
  <?php foreach ($primary as $i => $item): ?>
    <a href="<?= e($item['href']) ?>" class="<?= $activeKey === $item['key'] ? 'active' : '' ?>">
      <i class="bi <?= e($item['icon']) ?>"></i><?= e($item['label']) ?>
    </a>
  <?php endforeach; ?>
  <?php if ($showMoreSheet): ?>
    <a href="javascript:void(0)" onclick="openSheet('more-sheet')">
      <i class="bi bi-three-dots"></i><?= __('more') ?>
    </a>
  <?php endif; ?>
</nav>

<?php if ($showMoreSheet): ?>
<div class="sheet-overlay" id="more-sheet">
  <div class="sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title"><?= __('more') ?></div>
    <?php foreach ($overflow as $item): ?>
      <a href="<?= e($item['href']) ?>" style="display:flex;align-items:center;gap:12px;padding:13px 4px;color:var(--ink);font-weight:600;border-bottom:1px solid var(--line);">
        <i class="bi <?= e($item['icon']) ?>" style="color:var(--gold-600);font-size:1.1rem;"></i> <?= e($item['label']) ?>
      </a>
    <?php endforeach; ?>
    <?php if ($user): ?>
      <a href="<?= $base ?>logout.php" style="display:flex;align-items:center;gap:12px;padding:13px 4px;color:var(--danger);font-weight:700;">
        <i class="bi bi-box-arrow-right"></i> <?= __('sign_out') ?>
      </a>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
