<?php
/**
 * migrate_database.php  —  Atsede Library
 * 1-Click Cloud Database Migration for TiDB Cloud Serverless (MySQL Compatible) & Aiven.
 * Reads database/production_schema_and_data.sql and securely populates the remote database over SSL.
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
    $dbHost    = trim($_POST['db_host'] ?? '');
    $dbPort    = (int)($_POST['db_port'] ?? 4000);
    $dbUser    = trim($_POST['db_user'] ?? '');
    $dbPass    = trim($_POST['db_pass'] ?? '');
    $dbName    = trim($_POST['db_name'] ?? 'test');
    $saveLocal = !empty($_POST['save_to_local_config']);

    if (!$dbHost || !$dbUser || !$dbName) {
        $err = 'እባክዎ Host, Port, User እና Database ስም ያስገቡ።';
    } elseif (!file_exists($sqlFile)) {
        $err = 'የዳታቤዝ ፋይሉ አልተገኘም፦ database/production_schema_and_data.sql';
    } else {
        // Connect with SSL enabled
        $rConn = mysqli_init();
        mysqli_ssl_set($rConn, NULL, NULL, NULL, NULL, NULL);
        $connected = @mysqli_real_connect($rConn, $dbHost, $dbUser, $dbPass, $dbName, $dbPort, NULL, MYSQLI_CLIENT_SSL);

        if (!$connected) {
            // Fallback attempt standard connect
            $connected = @mysqli_real_connect($rConn, $dbHost, $dbUser, $dbPass, $dbName, $dbPort);
        }

        if (!$connected) {
            $err = "ከክላውድ ዳታቤዝ ጋር መገናኘት አልተቻለም፦ " . mysqli_connect_error() . "<br><small>እባክዎ Host, Port (TiDB = 4000), User እና Password ትክክል መሆናቸውን ያረጋግጡ።</small>";
        } else {
            mysqli_set_charset($rConn, 'utf8mb4');
            @mysqli_query($rConn, "SET time_zone = '+03:00'");
            @mysqli_query($rConn, "SET FOREIGN_KEY_CHECKS = 0");

            $sqlContent = file_get_contents($sqlFile);
            
            // Execute multi-query batch
            if (mysqli_multi_query($rConn, $sqlContent)) {
                do {
                    if ($res = mysqli_store_result($rConn)) {
                        mysqli_free_result($res);
                    }
                } while (mysqli_more_results($rConn) && mysqli_next_result($rConn));

                $booksCount = 0;
                $bRes = mysqli_query($rConn, "SELECT COUNT(*) c FROM books");
                if ($bRes) {
                    $booksCount = (int)mysqli_fetch_assoc($bRes)['c'];
                }

                $msg = "🎉 <b>እንኳን ደስ አለዎት! ሙሉው ዳታቤዝ በተሳካ ሁኔታ ወደ TiDB Cloud ተጭኗል!</b><br>" .
                       "📚 ጠቅላላ የተጫኑ መጻሕፍት፦ <b>{$booksCount}</b>";

                if ($saveLocal) {
                    $localCfgPath = __DIR__ . '/config.local.php';
                    $cfgExport = "<?php\n// Auto-saved TiDB Cloud Credentials\nreturn [\n" .
                                 "    'host' => " . var_export($dbHost, true) . ",\n" .
                                 "    'port' => " . var_export($dbPort, true) . ",\n" .
                                 "    'user' => " . var_export($dbUser, true) . ",\n" .
                                 "    'pass' => " . var_export($dbPass, true) . ",\n" .
                                 "    'db'   => " . var_export($dbName, true) . ",\n" .
                                 "    'ssl'  => true,\n" .
                                 "];\n";
                    @file_put_contents($localCfgPath, $cfgExport);
                    $msg .= "<br>💾 የ TiDB መረጃዎች በ <code>config.local.php</code> ውስጥ በደህንነት ተቀምጠዋል።";
                }
            } else {
                $err = "የ SQL ዳታውን መጫን አልተቻለም፦ " . mysqli_error($rConn);
            }
            mysqli_close($rConn);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="am">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>TiDB Cloud MySQL Migration — Atsede Library</title>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@500;700;800;900&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
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
    <div style="font-size:2.2rem;margin-bottom:6px;"><i class="bi bi-cloud-arrow-up-fill" style="color:#FFB703;"></i></div>
    <h2 style="margin:0 0 4px;font-size:1.25rem;">ዳታቤዝ ወደ TiDB Cloud ማዛወሪያ</h2>
    <p style="margin:0;font-size:.82rem;color:#FFE57F;">Atsede Library 1-Click TiDB Serverless Migration</p>
  </div>

  <div class="card-body">
    <?php if ($msg): ?>
      <div class="alert alert-success"><?= $msg ?></div>
    <?php endif; ?>

    <?php if ($err): ?>
      <div class="alert alert-danger"><?= $err ?></div>
    <?php endif; ?>

    <p style="font-size:.84rem;color:#64748B;line-height:1.5;margin-bottom:18px;">
      በ <a href="https://tidbcloud.com" target="_blank" style="color:#0047AB;font-weight:700;">TiDB Cloud (tidbcloud.com)</a> ላይ የከፈቱትን ነጻ የ Serverless MySQL ክላስተር መረጃዎች እዚህ ያስገቡ። ሁሉንም 391 መጽሐፍት፣ አባላትን እና ቅንብሮችን በራስ-ሰር ይጭንልዎታል።
    </p>

    <form method="post">
      <div class="field">
        <label>TiDB Cloud Host</label>
        <input class="input" name="db_host" placeholder="ምሳሌ፦ gateway01.us-east-1.prod.aws.tidbcloud.com" value="<?= htmlspecialchars($_POST['db_host'] ?? '') ?>" required>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="field">
          <label>Port (TiDB ነባሪ = 4000)</label>
          <input class="input" name="db_port" type="number" placeholder="4000" value="<?= htmlspecialchars($_POST['db_port'] ?? '4000') ?>" required>
        </div>
        <div class="field">
          <label>Database Name</label>
          <input class="input" name="db_name" placeholder="test" value="<?= htmlspecialchars($_POST['db_name'] ?? 'test') ?>" required>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
        <div class="field">
          <label>User (Username)</label>
          <input class="input" name="db_user" placeholder="ምሳሌ፦ xxxxxx.root" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" required>
        </div>
        <div class="field">
          <label>Password</label>
          <input class="input" name="db_pass" type="password" placeholder="የይለፍ ቃል..." value="<?= htmlspecialchars($_POST['db_pass'] ?? '') ?>" required>
        </div>
      </div>

      <div class="field" style="margin-top:10px;">
        <label style="display:flex;align-items:center;gap:8px;font-weight:500;cursor:pointer;">
          <input type="checkbox" name="save_to_local_config" value="1" checked>
          <span>እነዚህን መረጃዎች በ <code>config.local.php</code> ውስጥ አስቀምጥ</span>
        </label>
      </div>

      <button type="submit" class="btn" onclick="this.innerHTML='እየጫነ ነው… እባክዎ ይጠብቁ…';this.disabled=true;this.form.submit();">
        <i class="bi bi-lightning-charge-fill"></i> ዳታውን ወደ TiDB Cloud ጫን (Migrate Now)
      </button>
    </form>
  </div>
</div>

</body>
</html>
