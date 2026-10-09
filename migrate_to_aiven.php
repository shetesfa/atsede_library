<?php
/**
 * migrate_to_aiven.php  —  Atsede Library
 * Utility script to migrate local database structure & data to Aiven Cloud MySQL in 1 click.
 * Accessible locally or with admin authorization.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/functions.php';

// Security guard: Only allow from localhost OR logged-in admin
$isLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', 'localhost'], true);
if (!$isLocal) {
    require_role('admin');
}

$msg = '';
$err = '';
$sqlFile = __DIR__ . '/database/production_schema_and_data.sql';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $aHost = trim($_POST['aiven_host'] ?? '');
    $aPort = (int)($_POST['aiven_port'] ?? 3306);
    $aUser = trim($_POST['aiven_user'] ?? 'avnadmin');
    $aPass = trim($_POST['aiven_pass'] ?? '');
    $aDb   = trim($_POST['aiven_db']   ?? 'defaultdb');
    $saveLocal = !empty($_POST['save_to_local_config']);

    if (!$aHost || !$aUser || !$aDb) {
        $err = 'እባክዎ የ Aiven Host, Port, User እና Database ስም ያስገቡ።';
    } elseif (!file_exists($sqlFile)) {
        $err = 'የዳታቤዝ ፋይሉ አልተገኘም፦ database/production_schema_and_data.sql';
    } else {
        // Connect to Aiven with SSL
        $aConn = mysqli_init();
        mysqli_ssl_set($aConn, NULL, NULL, NULL, NULL, NULL);
        $connected = @mysqli_real_connect($aConn, $aHost, $aUser, $aPass, $aDb, $aPort, NULL, MYSQLI_CLIENT_SSL);

        if (!$connected) {
            $err = "ከ Aiven MySQL ጋር መገናኘት አልተቻለም፦ " . mysqli_connect_error() . "<br><small>እባክዎ Host, Port, User እና Password ትክክል መሆናቸውን ያረጋግጡ።</small>";
        } else {
            mysqli_set_charset($aConn, 'utf8mb4');
            @mysqli_query($aConn, "SET time_zone = '+03:00'");
            @mysqli_query($aConn, "SET FOREIGN_KEY_CHECKS = 0");

            $sqlContent = file_get_contents($sqlFile);
            
            // Execute multi query or batch queries
            if (mysqli_multi_query($aConn, $sqlContent)) {
                do {
                    if ($res = mysqli_store_result($aConn)) {
                        mysqli_free_result($res);
                    }
                } while (mysqli_more_results($aConn) && mysqli_next_result($aConn));

                $booksCount = (int)mysqli_fetch_assoc(mysqli_query($aConn, "SELECT COUNT(*) c FROM books"))['c'];
                $msg = "🎉 <b>እንኳን ደስ አለዎት! ሙሉው ዳታቤዝ በተሳካ ሁኔታ ወደ Aiven ተጭኗል!</b><br>" .
                       "📚 ጠቅላላ የተጫኑ መጻሕፍት፦ <b>{$booksCount}</b>";

                if ($saveLocal) {
                    $localCfgPath = __DIR__ . '/config.local.php';
                    $cfgExport = "<?php\n// Auto-saved Aiven Cloud Credentials\nreturn [\n" .
                                 "    'host' => " . var_export($aHost, true) . ",\n" .
                                 "    'port' => " . var_export($aPort, true) . ",\n" .
                                 "    'user' => " . var_export($aUser, true) . ",\n" .
                                 "    'pass' => " . var_export($aPass, true) . ",\n" .
                                 "    'db'   => " . var_export($aDb, true) . ",\n" .
                                 "    'ssl'  => true,\n" .
                                 "];\n";
                    @file_put_contents($localCfgPath, $cfgExport);
                    $msg .= "<br>💾 የ Aiven መረጃዎች በ <code>config.local.php</code> ውስጥ በደህንነት ተቀምጠዋል።";
                }
            } else {
                $err = "የ SQL ዳታውን መጫን አልተቻለም፦ " . mysqli_error($aConn);
            }
            mysqli_close($aConn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Aiven Cloud MySQL Migration — Atsede Library</title>
  <link rel="stylesheet" href="assets/lib/fonts/fonts.css">
  <link rel="stylesheet" href="assets/lib/bootstrap-icons/bootstrap-icons.css">
  <style>
    body {
      font-family: 'Plus Jakarta Sans', system-ui, sans-serif;
      background: radial-gradient(circle at 50% 20%, #0D2847 0%, #051324 100%);
      color: #1E293B;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px 16px;
      margin: 0;
    }
    .card {
      background: #fff;
      border-radius: 20px;
      max-width: 520px;
      width: 100%;
      overflow: hidden;
      box-shadow: 0 20px 50px rgba(0,0,0,0.35);
    }
    .card-header {
      background: linear-gradient(135deg, #0A2540 0%, #0047AB 100%);
      color: #fff;
      padding: 24px 22px;
      text-align: center;
      border-bottom: 4px solid #FFB703;
    }
    .card-body {
      padding: 24px 22px;
    }
    .field {
      margin-bottom: 14px;
    }
    .field label {
      display: block;
      font-size: 0.8rem;
      font-weight: 700;
      color: #0A2540;
      margin-bottom: 4px;
    }
    .input {
      width: 100%;
      box-sizing: border-box;
      padding: 10px 14px;
      border: 1.5px solid #CBD5E1;
      border-radius: 10px;
      font-size: 0.9rem;
      outline: none;
      transition: border-color .2s;
    }
    .input:focus {
      border-color: #0047AB;
    }
    .btn {
      display: block;
      width: 100%;
      padding: 12px;
      background: #0047AB;
      color: #FFB703;
      border: none;
      border-radius: 12px;
      font-size: 0.95rem;
      font-weight: 800;
      cursor: pointer;
      box-shadow: 0 4px 14px rgba(0,71,171,0.3);
      transition: transform .1s;
    }
    .btn:hover {
      background: #003a8c;
    }
    .alert {
      padding: 12px 14px;
      border-radius: 10px;
      font-size: 0.86rem;
      margin-bottom: 16px;
      line-height: 1.5;
    }
    .alert-success {
      background: #DCFCE7;
      border: 1px solid #86EFAC;
      color: #14532D;
    }
    .alert-danger {
      background: #FEE2E2;
      border: 1px solid #FCA5A5;
      color: #7F1D1D;
    }
  </style>
</head>
<body>

<div class="card">
  <div class="card-header">
    <div style="font-size:2rem;margin-bottom:6px;"><i class="bi bi-cloud-arrow-up-fill" style="color:#FFB703;"></i></div>
    <h2 style="margin:0 0 4px;font-size:1.25rem;">ዳታቤዝ ወደ Aiven Cloud ማዛወሪያ</h2>
    <p style="margin:0;font-size:.82rem;color:#FFE57F;">Atsede Library 1-Click Database Migration</p>
  </div>

  <div class="card-body">
    <?php if ($msg): ?>
      <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= $err ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:#64748B;line-height:1.5;margin-bottom:18px;">
      በ <a href="https://aiven.io" target="_blank" style="color:#0047AB;font-weight:700;">Aiven.io</a> ላይ የከፈቱትን ነጻ MySQL Service መረጃዎች እዚህ ያስገቡ። ሁሉንም 391 መጽሐፍት፣ ተጠቃሚዎችና ቅንብሮች በራስ-ሰር ይጭንልዎታል።
    </p>

    <form method="post">
      <div class="field">
        <label>Aiven Host (Service URI / Hostname)</label>
        <input class="input" name="aiven_host" placeholder="ምሳሌ፦ mysql-xxxx-xxxx.aivencloud.com" value="<?= htmlspecialchars($_POST['aiven_host'] ?? '') ?>" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="field">
          <label>Port</label>
          <input class="input" name="aiven_port" type="number" placeholder="ምሳሌ፦ 12345" value="<?= htmlspecialchars($_POST['aiven_port'] ?? '3306') ?>" required>
        </div>
        <div class="field">
          <label>Database Name</label>
          <input class="input" name="aiven_db" placeholder="defaultdb" value="<?= htmlspecialchars($_POST['aiven_db'] ?? 'defaultdb') ?>" required>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="field">
          <label>User</label>
          <input class="input" name="aiven_user" placeholder="avnadmin" value="<?= htmlspecialchars($_POST['aiven_user'] ?? 'avnadmin') ?>" required>
        </div>
        <div class="field">
          <label>Password</label>
          <input class="input" name="aiven_pass" type="password" placeholder="የይለፍ ቃል..." value="<?= htmlspecialchars($_POST['aiven_pass'] ?? '') ?>" required>
        </div>
      </div>

      <div class="field" style="margin-top:10px;">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
          <input type="checkbox" name="save_to_local_config" value="1" checked>
          <span>እነዚህን መረጃዎች በ <code>config.local.php</code> ውስጥ አስቀምጥ</span>
        </label>
      </div>

      <button type="submit" class="btn" onclick="this.innerHTML='እየጫነ ነው… እባክዎ ይጠብቁ…';this.disabled=true;this.form.submit();">
        <i class="bi bi-lightning-charge-fill"></i> ዳታውን ወደ Aiven ጫን (Migrate Now)
      </button>
    </form>
  </div>
</div>

</body>
</html>
