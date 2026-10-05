# የፈተና እና ማረጋገጫ መመሪያ (Testing & Verification Guide)

ይህ ሰነድ በ "ዓጸደ ማርያም ቤተ-መጻሕፍት" (Atsede Library) ፕሮጀክት ውስጥ የተካተቱትን የ PHPUnit የደህንነት እና አስተማማኝነት ፈተናዎች እንዴት ማስኬድ እና ማረጋገጥ እንደሚቻል ያብራራል።

---

## 1. የፈተና አካባቢ እና ዝግጅት (Test Environment)
- **የ PHP ስሪት**: PHP 8.2+
- **የፈተና ማዕቀፍ**: PHPUnit 11
- **የፈተና ዳታቤዝ**: `atsede_test` (MariaDB/MySQL በ `localhost:3306`)
- **የማዋቀሪያ ፋይሎች**:
  - `phpunit.xml`: የፈተና ማዋቀሪያ እና የ Suite ፍቺዎች
  - `tests/bootstrap.php`: የፈተና ዳታቤዝ አገናኝ፣ የትራንዛክሽን ሮልባክ እና የረዳት ተግባራት ማዕከል

### በ bootstrap.php የተካተቱ ረዳት ተግባራት (Test Helpers):
- `create_user($role, $status)`: ተጠቃሚዎችን በተለያዩ ሚናዎች ለመፍጠር
- `create_member($userId)`: የአባል መረጃዎችን ለመፍጠር
- `create_book_with_copies($numCopies)`: መጽሐፍትን ከቅጂዎቻቸው ጋር ለማዘጋጀት
- `login_as($role, $userId)`: የሴሽን ክፍለ-ጊዜ ለመምሰል
- `reset_test_telegram_messages()` / `get_test_telegram_messages()`: የቴሌግራም መላኪያዎችን በፈተና ወቅት ለመከታተል

---

## 2. ፈተናዎችን ማስኬድ (Running Tests)

### ሙሉውን የፈተና ስብስብ ለማስኬድ:
```bash
.\vendor\bin\phpunit
```

### የተወሰኑ የፈተና ክፍሎችን ብቻ ለማስኬድ:
```bash
# የኢትዮጵያ ካላንደር ፈተናዎች
.\vendor\bin\phpunit tests/Unit/EthiopianCalendarTest.php

# የቤተ-መጻሕፍት ሰርቪስ (ውሰት፣ መመለስ፣ ቅጣት) ፈተናዎች
.\vendor\bin\phpunit tests/Feature/LibraryServiceTest.php

# የማሳወቂያ ስርዓት እና ማሳሰቢያ ፈተናዎች
.\vendor\bin\phpunit tests/Feature/NotificationDeliveryTest.php

# የመግቢያ ሙከራ ገደብ (Login Throttling) ፈተናዎች
.\vendor\bin\phpunit tests/Security/LoginThrottleTest.php

# ከመስመር ውጭ ማመሳሰል (Offline Sync) ፈተናዎች
.\vendor\bin\phpunit tests/Feature/OfflineSyncTest.php
```

---

## 3. የተካተቱ የፈተና ዓይነቶች (Test Suites)

| የፈተና ፋይል | የተፈተነው ተግባር | የፈተና ብዛት |
| :--- | :--- | :--- |
| `tests/Unit/EthiopianCalendarTest.php` | የኢትዮጵያ ዘመን አቆጣጠር ስሌቶች፣ ጳጉሜ 6 (ሊፕ ዓመት)፣ ባለብዙ ክፍለ-ዘመን የስነ-ፈለክ ቀመር፣ የሁለትዮሽ ለውጥ (bidirectional) እና የክፍያ ወር ስሌቶች | 5 ፈተናዎች (172 assertions) |
| `tests/Feature/LibraryServiceTest.php` | መጽሐፍ ማዋስ፣ መመለስ፣ ማደስ፣ የጠፉ/የተጎዱ ቅጂዎች ጥበቃ፣ ያልተከፈለ ቅጣት ገደብ፣ የቅጣት አከፋፈል እና ይቅርታ | 9 ፈተናዎች |
| `tests/Feature/NotificationDeliveryTest.php` | ባለብዙ ቻናል ማሳወቂያዎች፣ የመላኪያ ኦዲት፣ የክሮን ማሳሰቢያ ድግግሞሽ መከላከያ (deduplication)፣ እና የተነበቡ ማሳወቂያዎች መከታተያ | 5 ፈተናዎች |
| `tests/Security/LoginThrottleTest.php` | በ 10 የተሳሳቱ የይለፍ ቃል ሙከራዎች ለ 20 ደቂቃ የሚደረግ እገዳ፣ ትክክለኛ መግቢያ ሲደረግ የቆጣሪ መጽዳት | 4 ፈተናዎች |
| `tests/Feature/OfflineSyncTest.php` | ከመስመር ውጭ ማመሳሰል ሚና ማረጋገጫ፣ Idempotency፣ የብቃት ማረጋገጫ እና ትራንዛክሽን | 6 ፈተናዎች |
| `tests/Feature/ScanAndCopyTest.php` | የ QR ኮድ እና የቅጂ መለያ ቅድሚያ አሰጣጥ፣ የአባላት መከለል | 5 ፈተናዎች |
| `tests/Security/TelegramHardeningTest.php` | የቴሌግራም ሚስጥራዊ ቶከን ማረጋገጫ፣ የ 48-ሰዓት ጊዜያዊ ኮድ ማብቂያ | 4 ፈተናዎች |
| `tests/Security/UploadSecurityTest.php` | የተንኮል-አዘል ፋይሎች መስቀያ መከላከል፣ MIME Type ማረጋገጫ እና በ GD ዳግም ማመስጠር | 4 ፈተናዎች |

---

## 4. የቅርብ ጊዜ የፈተና ውጤት ማረጋገጫ (Latest Verification Results)

```text
PHPUnit 11.5.57 by Sebastian Bergmann and contributors.

Runtime:       PHP 8.2.12
Configuration: C:\xampp\htdocs\atsede_library\phpunit.xml

..................................................                50 / 50 (100%)

Time: 00:18.528, Memory: 12.00 MB

OK (50 tests, 368 assertions)
```

- **ጠቅላላ የፈተናዎች ብዛት**: 50
- **ጠቅላላ የተረጋገጡ ነጥቦች (Assertions)**: 368
- **የማለፍ ምጣኔ**: 100% (ምንም አይነት ውድቀት ወይም ስህተት የለም)
