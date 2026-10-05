# የደህንነት እና አስተማማኝነት ማሻሻያ መዝገብ (Hardening Changelog: P0 - P2)

ይህ ሰነድ በ "ዓጸደ ማርያም ቤተ-መጻሕፍት" (Atsede Library) ፕሮጀክት ላይ ከ **T1 እስከ T13** የተከናወኑትን ሁሉንም የደህንነት፣ አስተማማኝነት፣ የዳታቤዝ እና የተጠቃሚ ተሞክሮ ማሻሻያዎችን በዝርዝር ይዟል።

---

## ማጠቃለያ (Overview)
- **ቅርንጫፍ (Branch)**: `hardening/p0-p2`
- **የስራ መደብ**: ከፍተኛ የ PHP/MySQL ደህንነት እና አስተማማኝነት መሀንዲስ
- **ቴክኖሎጂዎች**: PHP 8.2, MySQL/MariaDB, Vanilla JS, PWA (Service Worker), Telegram Bot API, PHPUnit 11

---

## የተከናወኑ ተግባራት ዝርዝር (Tasks Detail)

### T1. የምስጢሮች እና የሪፖዚቶሪ ጥበቃ (Repo Hygiene)
- **የተቀየሩ ፋይሎች**: `.gitignore`, `config.local.php.example`, `config.remote.php.example`, `config.php`
- **የተከናወኑ ስራዎች**:
  - የዳታቤዝ ምስጢሮች፣ የይለፍ ቃሎች እና ቶከኖች ወደፊት ወደ Git እንዳይገቡ `.gitignore` ተስተካክሏል።
  - በስህተት ተከታትለው የነበሩ ሚስጥራዊ ፋይሎች (`backups/*.sql`, `tmp/sessions/*`, `uploads/avatars/*`, `config.local.php`) ከ Git ታሪክ ሳይፋቁ በ `git rm --cached` ከክትትል እንዲወጡ ተደርገዋል።
  - `config.local.php.example` እና `config.remote.php.example` ምንም አይነት እውነተኛ ሚስጥር ሳይይዙ ተዘጋጅተዋል።
  - `config.php` የዳታቤዝ መረጃዎችን ከ `config.local.php` ወይም ከ Environment Variables ብቻ እንዲያነብ ተደርጓል።

---

### T2. የማዋቀሪያ ስክሪፕቶች ደህንነት (Setup Scripts Security)
- **የተቀየሩ ፋይሎች**: `setup.php`, `setup_webhook.php`
- **የተከናወኑ ስራዎች**:
  - `setup.php` ድጋሚ እንዳይከፈት የመቆለፊያ ፋይል (`database/.installed`) እንዲኖረው ተደርጓል፤ ፋይሉ ካለ 404 መልሶ ይወጣል።
  - ነባሪው ደካማ የይለፍ ቃል ("Admin@123") ሙሉ በሙሉ ተወግዷል።
  - የ CSRF ጥበቃ እና የአድሚን ክፍለ-ጊዜ (Admin Session) ማረጋገጫ ተካቷል።
  - `setup_webhook.php` በዘፈቀደ በሚመነጭ ሚስጥራዊ ቶከን (`secret_token`) ብቻ እንዲሰራ ተደርጓል።

---

### T3. የፎቶ እና ፋይል መስቀያ ጥበቃ (File Upload Security)
- **የተቀየሩ ፋይሎች**: `includes/auth.php`, `includes/functions.php`, `member/profile.php`, `uploads/avatars/.htaccess`, `uploads/covers/.htaccess`
- **የተከናወኑ ስራዎች**:
  - የመስቀያ ማውጫዎች (`uploads/avatars/`, `uploads/covers/`) ውስጥ `.htaccess` በመጠቀም የ PHP እና ሌሎች ስክሪፕቶች አፈጻጸም (`SetHandler none`, `deny from all`) ሙሉ በሙሉ ታግዷል።
  - `secure_process_image()` ተግባር ተዘጋጅቶ ትክክለኛ MIME Type (`image/jpeg`, `image/png`, `image/webp`) ማረጋገጫ ተሰጥቷል።
  - ተንኮል-አዘል ስክሪፕቶች እንዳይደበቁ ፋይሉ በ PHP GD ላይብረሪ ዳግም ተመክሮ (re-encoded) እና እንደ አዲስ እንዲቀመጥ ተደርጓል።
  - በፋይል ስሞች ውስጥ ልዩ ቁምፊዎችን እና path traversal ጥቃቶችን ለመከላከል በ `bin2hex(random_bytes(16))` አዲስ ስም እንዲሰጣቸው ተደርጓል።

---

### T4. የቴሌግራም ቦት እና ዌብሁክ ጥበቃ (Telegram Webhook & Integration Hardening)
- **የተቀየሩ ፋይሎች**: `telegram/webhook.php`, `includes/functions.php`, `database/migrations/003_telegram_hardening.sql`
- **የተከናወኑ ስራዎች**:
  - ቴሌግራም ወደ ዌብሁክ የሚልካቸውን ጥሪዎች ለማረጋገጥ `X-Telegram-Bot-Api-Secret-Token` ራስጌ (header) ማረጋገጫ ተተግብሯል።
  - አንድ ወጥ የሆነ `telegram_api()` ተግባር ተዘጋጅቶ በ `CURLOPT_TIMEOUT`፣ ጥብቅ ስህተት መቆጣጠሪያ እና የሎግ ጥበቃ ተጠናክሯል።
  - የአባል አካውንት ከቴሌግራም ጋር ሲገናኝ የሚሰጠው ጊዜያዊ የማረጋገጫ ኮድ በ 48 ሰዓት እንዲያበቃ (`expires_at`) እና 1 ጊዜ ብቻ እንዲያገለግል (`used_at`) ተደርጓል።

---

### T5. ከመስመር ውጭ ማመሳሰል እና ውድድር መከላከል (Offline Sync Hardening & Concurrency)
- **የተቀየሩ ፋይሎች**: `ajax/offline_sync.php`
- **የተከናወኑ ስራዎች**:
  - የአድሚን ወይም የላይብረሪያን ሚና ማረጋገጫ (`librarian` / `admin`) ተካቷል።
  - በአንድ ጊዜ የሚደረጉ ድርጊቶችን እና የውሂብ ውድድርን (race condition) ለመከላከል የዳታቤዝ ትራንዛክሽን ከ `SELECT ... FOR UPDATE` ጋር ተተግብሯል።
  - የመጽሐፉ ሁኔታ 'lost' ወይም 'damaged' ከሆነ ወደ 'available' እንዳይመለስ ተከልክሏል።
  - አንድ ተግባር በተደጋጋሚ እንዳይፈጸም በ `client_uuid` ላይ የተመሰረተ Idempotency ማረጋገጫ ተሰጥቷል።
  - አባሉ ያልተከፈለ ቅጣት ካለበት ወይም ከታገደ ውሰት እንዳይፈቀድ ጥብቅ ብቃት ማረጋገጫ ተካቷል።

---

### T6. የ QR እና የባርኮድ ቅኝት ሞዴል (Scan & Copy Model Hardening)
- **የተቀየሩ ፋይሎች**: `scan.php`, `ajax/offline_bootstrap.php`, `database/migrations/004_book_copies_unique_qr.sql`
- **የተከናወኑ ስራዎች**:
  - ለደህንነት ሲባል `scan.php` ለአባላት ተዘግቶ ለሰራተኞች (`admin`/`librarian`) ብቻ እንዲሆን ተደርጓል።
  - አደገኛው ነባሪ አሰራር (`copy_id || 1`) ሙሉ በሙሉ ተወግዷል።
  - የ QR ቅኝት ቅድሚያ አሰጣጥ ተስተካክሏል፡ በመጀመሪያ የኮፒው ትክክለኛ QR ኮድ፣ ከዚያ የኮፒው ኮድ፣ በመጨረሻም የመጽሐፉ አይዲ ከትክክለኛ ቅጂ ጋር እንዲያያዝ ተደርጓል።
  - `book_copies` ሰንጠረዥ ላይ በ `(book_id, copy_code)` ጥምረት ላይ `UNIQUE` ገደብ ተጥሏል።

---

### T7. የክፍለ-ጊዜ እና የመለያ ጥበቃ (Session, Login Throttling & Member Privacy)
- **የተቀየሩ ፋይሎች**: `includes/auth.php`, `login.php`, `member_info.php`, `database/migrations/005_card_token_and_login_throttle.sql`
- **የተከናወኑ ስራዎች**:
  - ጥብቅ የሴሽን ኩኪ ደህንነት ተዋቅሯል፡ `session.cookie_httponly = 1`፣ `session.cookie_samesite = 'Lax'`፣ `session.use_strict_mode = 1` እና የስም ለውጥ ወደ `atsede_sess_id`።
  - **Login Throttling**: በተከታታይ **10 የተሳሳቱ ሙከራዎች** ከተደረጉ በኋላ መለያው ወይም አይፒው **ለ 20 ደቂቃ ይታገዳል** (`10 mistake 20 min close🔐`)።
  - የአባላት ካርድ ደህንነት ተጠናክሯል፡ በ `member_info.php` አባላት በ ID ቁጥር በቀላሉ እንዳይታዩ በ 64-ቁምፊ ሚስጥራዊ `card_token` ብቻ እንዲገኙ ተደርጓል።

---

### T8. የጊዜ ቀጠና እና የኢትዮጵያ ዘመን አቆጣጠር (Timezone & Ethiopian Calendar)
- **የተቀየሩ ፋይሎች**: `config.php`, `cron/daily_reminders.php`, `includes/functions.php`, `tests/Unit/EthiopianCalendarTest.php`
- **የተከናወኑ ስራዎች**:
  - የ PHP እና የ MySQL የሰዓት ቀጠና ወደ `Africa/Addis_Ababa` (`+03:00`) ተመሳስሏል።
  - **ለሁሉም ዓመታት የሚሰራ ራስ-ሰር የካላንደር ቀመር (Universal JDN Algorithm for All Years)**:
    - በ `gregorianToEthParts()` ውስጥ የነበረው ውሱን እና ለተወሰኑ ዓመታት ብቻ የተዘጋጀው ቀመር ሙሉ በሙሉ ተወግዶ በዓለም-አቀፍ የስነ-ፈለክ ቀመር (Astronomical Julian Day Number Algorithm) ተተክቷል።
    - ለማንኛውም ዓመት (ያለፉትን ክፍለ-ዘመናት፣ የአሁኑን እና የወደፊቱን ዘመናት በሙሉ - ከ 1 ዓ.ም. ጀምሮ እስከ መጨረሻው) የጎርጎሪዮስ እና የኢትዮጵያን ካላንደር በሁለትዮሽ አቅጣጫ (`gregorianToEthParts()` እና `ethiopianToGregorian()`) በ 100% ትክክለኛነት በራስ-ሰር ያሰላል።
    - በጎርጎሪዮሳዊ ሊፕ ዓመት የካቲት 29 ቀን እና በኢትዮጵያ ዘመነ ሉቃስ ጳጉሜ 6 ቀናት የሚፈጠረውን የቀናት መዛባት ሙሉ በሙሉ ያስቀረ እና የ 4 ዓመት ዑደትን (1461 ቀናት) በጥብቅ የተከተለ ነው።
    - የ PHP `ext-calendar` extension ቢኖርም ባይኖርም ራሱን ችሎ የሚሰራ ንጹህ የሂሳብ ቀመር (pure PHP algorithm fallback) ተካቷል።
  - በ `cron/daily_reminders.php` ወርሃዊ የአባልነት ክፍያ ማሳሰቢያ የሚላከው በጎርጎሪዮሳዊ ሳይሆን **በኢትዮጵያ ወር 1ኛ ቀን** እንዲሆን ተደርጓል።
  - በ SQL ውስጥ ቁጥሮችን የሚያበላሸው የ `number_format()` አሰራር ተወግዶ በቀጥታ በ `float` እንዲካተት ተደርጓል።

---

### T9. ማዕከላዊ የቤተ-መጻሕፍት ሰርቪስ (Centralized LibraryService)
- **የተቀየሩ ፋይሎች**: `includes/LibraryService.php`, `librarian/returns.php`, `librarian/requests.php`, `qr.php`, `book.php`, `ajax/offline_sync.php`, `librarian/fines.php`, `database/migrations/006_library_service_settings.sql`
- **የተከናወኑ ስራዎች**:
  - መጻሕፍትን ለማዋስ፣ ለመመለስ፣ ለማደስ እና ቅጣቶችን ለማስተዳደር አንድ ማዕከላዊ `LibraryService.php` ተዘጋጅቷል።
  - ሁሉም ስራዎች በ `DB Transaction` እና በ `SELECT ... FOR UPDATE` row-locking ጥበቃ ይሰራሉ።
  - የተበላሹ ወይም የጠፉ ቅጂዎች (`lost`/`damaged`) በምንም ሁኔታ ወደ `available` እንዳይቀየሩ ተደርጓል።
  - ያልተከፈለ ቅጣት ያለባቸው አባላት ተጨማሪ መጽሐፍ እንዳይዋሱ በ `settings.max_unpaid_fine` ገደብ ተጥሏል።
  - አዲስ የቅጣት ማስተዳደሪያ ገጽ `librarian/fines.php` (ቅጣት መቀበል፣ ይቅርታ በምክንያት ማድረግ፣ ደረሰኝ ማተም) ተዘጋጅቷል።

---

### T10. ባለብዙ ቻናል የማሳወቂያ ስርዓት (Multi-Channel Notifier)
- **የተቀየሩ ፋይሎች**: `includes/notifier.php`, `cron/daily_reminders.php`, `member/notifications.php`, `database/migrations/007_notification_system.sql`, `tests/Feature/NotificationDeliveryTest.php`
- **የተከናወኑ ስራዎች**:
  - ባለብዙ ቻናል ማሳወቂያ መላኪያ `notify_user()` ተዘጋጅቷል (`inapp`, `push`, `telegram`)።
  - የማሳወቂያ መላኪያ ሁኔታ ኦዲት ለማድረግ `notification_deliveries` ሰንጠረዥ ተካቷል።
  - በ `cron/daily_reminders.php` ላይ ተደጋጋሚ ማሳሰቢያ በአንድ ቀን ውስጥ እንዳይላክ በ `reminder_log` ቴብል አማካኝነት Deduplication ተተግብሯል።
  - ክሮን ከውጭ እንዳይጠራ በ `CRON_TOKEN` ጥበቃ ተደርጓል።
  - በ `member/notifications.php` "ሁሉንም አንብቤያለሁ" እና ነጠላ ማሳወቂያዎችን የማንበብ ሁኔታ በ `notification_reads` ሰንጠረዥ አማካኝነት ተተግብሯል።

---

### T11 እና T12. የ PWA፣ የአገልግሎት ሰራተኛ እና የሞባይል ተሞክሮ ማሻሻያ (PWA & Mobile UX)
- **የተቀየሩ ፋይሎች**: `sw.js`, `assets/manifest.json`, `assets/css/style.css`
- **የተከናወኑ ስራዎች**:
  - የ Service Worker መሸጎጫ (Cache Versioning) ወደ `atsede-v12` በማደስ አሮጌ ፋይሎች ወዲያውኑ እንዲጸዱ ተደርጓል።
  - `manifest.json` እና `manifest.php` ውስጥ ስሙ ወደ "ቤተ ይትባረክ ቤተ-መጽሃፍት" እንዲሁም አጭር ስሙ ወደ "ቤተ ይትባረክ" ተስተካክሏል።
  - በ CSS ውስጥ የሞባይል ደህንነቱ የተጠበቀ ገደብ (`env(safe-area-inset-*)`) ተካቷል።
  - በንክኪ የሚሰሩ አዝራሮች እና ሊንኮች ቢያንስ 44x44 ፒክስል ስፋት እንዲኖራቸው ተደርጓል።
  - በሞባይል ሳፋሪ ላይ የሚፈጠረውን አላስፈላጊ ማጉላት (auto-zoom) ለመከላከል የፎርም ጽሁፍ መጠን ቢያንስ 16px እንዲሆን ተደርጓል።

---

### T13. የዳታቤዝ ስኪማ እና የስርዓት ስራዎች (Schema, Migrations & Ops)
- **የተቀየሩ ፋይሎች**: `database/schema.sql`, `tools/migrate.php`, `tools/backup.php`, `config.php`, `logs/.htaccess`
- **የተከናወኑ ስራዎች**:
  - የቅርብ ጊዜውን የዳታቤዝ ሁኔታ የያዘ ንጹህ፣ ሚስጥሮች እና መረጃዎች የሌሉበት `database/schema.sql` በ UTF-8 ተዘጋጅቷል።
  - ማይግሬሽኖችን በቅደም ተከተል የሚያስኬድ እና በ `schema_migrations` አማካኝነት ድግግሞሽን የሚከላከል (idempotent) `tools/migrate.php` ተዘጋጅቷል።
  - ዳታቤዝ dump የሚያደርግ፣ የተጫኑ ፎቶዎችን የሚያመጨውቅ (zip)፣ እና የ 7 ዕለታዊ + 4 ሳምንታዊ ፖሊሲ ያለው `tools/backup.php` ተዘጋጅቷል።
  - በምርት አካባቢ (production) ለተጠቃሚዎች የ SQL ስህተት እንዳይታይ በ `logs/error.log` የሚመዘገብ ማዕከላዊ የ Error Handler እና ሰላማዊ የአማርኛ ስህተት መልእክት በ `config.php` ተዋቅሯል።
