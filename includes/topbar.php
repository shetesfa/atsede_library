<header class="topbar">
  <div class="brand">
    <span class="crest">
      <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="<?= e($siteName) ?>" style="width:100%;height:100%;object-fit:contain;display:block;">
      <?php else: ?><i class="bi bi-book-half"></i><?php endif; ?>
    </span>
    <span class="brand-text"><?= e($siteName) ?></span>
  </div>

  <a href="<?= $base ?><?= $role === 'guest' ? 'search.php' : ($role === 'member' ? 'search.php' : 'librarian/books.php') ?>" class="icon-btn" title="<?= __('search') ?>">
    <i class="bi bi-search"></i>
  </a>

  <div class="spacer"></div>



  <?php if ($user): ?>
    <a href="<?= $base ?><?= $role === 'member' ? 'member/notifications.php' : ($role === 'librarian' ? 'librarian/notifications.php' : 'admin/notifications.php') ?>" class="icon-btn" title="<?= __('notifications') ?>">
      <i class="bi bi-bell"></i>
      <span id="notif-dot" class="dot" style="display:none;">0</span>
    </a>
    <a href="<?= $base ?><?= $role === 'member' ? 'member/profile.php' : ($role === 'admin' ? 'admin/profile.php' : 'librarian/dashboard.php') ?>" class="icon-btn" title="<?= __('profile') ?>">
      <i class="bi bi-person-circle"></i>
    </a>
  <?php else: ?>
    <a href="<?= $base ?>login.php" class="icon-btn" title="<?= __('login') ?>"><i class="bi bi-box-arrow-in-right"></i></a>
  <?php endif; ?>
</header>
