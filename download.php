<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

$pageTitle = 'የሞባይል መተግበሪያ ማውረጃ (Android APK)';
include __DIR__ . '/includes/header.php';
?>

<div class="card card-pad text-center mb-4" style="max-width:480px;margin:20px auto;border:2px solid var(--gold);border-radius:18px;box-shadow:0 10px 30px rgba(0,0,0,0.08);">
  
  <div style="width:90px;height:90px;margin:0 auto 16px;border-radius:20px;overflow:hidden;border:2px solid var(--gold);padding:4px;background:#fff;box-shadow:0 4px 15px rgba(0,0,0,0.1);">
    <img src="<?= $base ?>assets/icons/icon-192.png" alt="አርማ" style="width:100%;height:100%;object-fit:contain;display:block;">
  </div>

  <h2 class="font-display" style="font-size:1.35rem;color:var(--navy);margin:0 0 6px;">
    ቤተ ይትባረክ የሞባይል መተግበሪያ
  </h2>
  <div class="text-muted" style="font-size:.86rem;margin-bottom:14px;">
    ስሪት 1.0.0 (Android APK) · መጠን፦ 5.9 MB
  </div>

  <div style="background:var(--slate-50);border:1px solid var(--line);border-radius:12px;padding:12px;font-size:.84rem;line-height:1.7;color:var(--ink);text-align:left;margin-bottom:20px;">
    <div>✓ <strong>የቀጥታ ካሜራ ስካነር፦</strong> መጻሕፍትን በስልክ ካሜራ በቅጽበት ለመቃኘት</div>
    <div>✓ <strong>የውሰትና ተመላሽ ቁጥጥር፦</strong> ለላይብረሪያንና ለአድሚን</div>
    <div>✓ <strong>ሙሉ ከመስመር ውጭ (Offline) ድጋፍ፦</strong> ያለ ኢንተርኔት ይሰራል</div>
    <div>✓ <strong>ቀጥታ ግንኙነት፦</strong> ከኮምፒዩተሩ ዳታቤዝ ጋር በኔትወርክ ይገናኛል</div>
  </div>

  <a href="<?= $base ?>download/atsede_library.apk" class="btn btn-gold btn-block btn-lg" style="font-weight:800;font-size:1.05rem;padding:14px;border-radius:12px;box-shadow:0 4px 15px rgba(212,175,55,0.4);">
    <i class="bi bi-cloud-arrow-down-fill"></i> መተግበሪያውን አውርድ (Download APK)
  </a>

  <div class="text-muted mt-3" style="font-size:.78rem;line-height:1.5;">
    ፋይሉ ከወረደ በኋላ ስልክዎ ላይ «Open» በማለት ይጫኑት። «Unknown sources / Install unknown apps» ቢጠይቅዎት «Allow / ፍቀድ» ይበሉት።
  </div>
</div>

<div class="text-center mt-3 mb-4">
  <a href="<?= $base ?>index.php" class="text-muted" style="font-size:.85rem;">
    <i class="bi bi-arrow-left"></i> ወደ ዋናው ገጽ ተመለስ
  </a>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
