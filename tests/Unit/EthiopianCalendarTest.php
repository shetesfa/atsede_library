<?php
declare(strict_types=1);

namespace Tests\Unit;

use Tests\TestCase;

class EthiopianCalendarTest extends TestCase
{
    public function testKnownDatesConversion(): void
    {
        // 2026-09-11 -> 1 Meskerem 2019
        $p1 = gregorianToEthParts('2026-09-11');
        $this->assertNotNull($p1);
        $this->assertSame(2019, $p1['year']);
        $this->assertSame(1, $p1['month']);
        $this->assertSame(1, $p1['day']);

        // 2026-09-10 -> 5 Pagume 2018 (2018 is standard year, 5 days in Pagume)
        $p2 = gregorianToEthParts('2026-09-10');
        $this->assertNotNull($p2);
        $this->assertSame(2018, $p2['year']);
        $this->assertSame(13, $p2['month']);
        $this->assertSame(5, $p2['day']);

        // 2027-09-11 -> 6 Pagume 2019 (2019 is leap year, 2019 % 4 === 3, 6 days in Pagume)
        $p3 = gregorianToEthParts('2027-09-11');
        $this->assertNotNull($p3);
        $this->assertSame(2019, $p3['year']);
        $this->assertSame(13, $p3['month']);
        $this->assertSame(6, $p3['day']);

        // 2027-09-12 -> 1 Meskerem 2020 (following leap year Pagume 6)
        $p4 = gregorianToEthParts('2027-09-12');
        $this->assertNotNull($p4);
        $this->assertSame(2020, $p4['year']);
        $this->assertSame(1, $p4['month']);
        $this->assertSame(1, $p4['day']);

        // 2024-01-01 -> 22 Tahsas 2016 (year 2016 started Sep 12, 2023)
        $p5 = gregorianToEthParts('2024-01-01');
        $this->assertNotNull($p5);
        $this->assertSame(2016, $p5['year']);
        $this->assertSame(4, $p5['month']);
        $this->assertSame(22, $p5['day']);
    }

    public function testPagumeBoundaries(): void
    {
        // 2018 Pagume has 5 days (from 2026-09-06 to 2026-09-10)
        $pStart = gregorianToEthParts('2026-09-06');
        $this->assertSame(13, $pStart['month']);
        $this->assertSame(1, $pStart['day']);

        $pEnd = gregorianToEthParts('2026-09-10');
        $this->assertSame(13, $pEnd['month']);
        $this->assertSame(5, $pEnd['day']);

        // 2019 Pagume has 6 days (from 2027-09-06 to 2027-09-11)
        $pLeapStart = gregorianToEthParts('2027-09-06');
        $this->assertSame(13, $pLeapStart['month']);
        $this->assertSame(1, $pLeapStart['day']);

        $pLeapEnd = gregorianToEthParts('2027-09-11');
        $this->assertSame(13, $pLeapEnd['month']);
        $this->assertSame(6, $pLeapEnd['day']);
    }

    public function testFormatDateAndBillingMonth(): void
    {
        $formatted = formatDate('2026-09-11');
        $this->assertSame('1 መስከረም 2019 ዓ.ም.', $formatted);

        $emptyDate = formatDate(null);
        $this->assertSame('—', $emptyDate);

        $billing = current_billing_month();
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}$/', $billing);

        $monthLabel = format_billing_month_amharic('2019-01');
        $this->assertSame('መስከረም 2019 ዓ.ም.', $monthLabel);
    }

    public function testUniversalMultiCenturyConversion(): void
    {
        // Historic: Battle of Adwa victory (Yekatit 23, 1888 <-> March 1, 1896)
        $adwa = gregorianToEthParts('1896-03-01');
        $this->assertSame(1888, $adwa['year']);
        $this->assertSame(6, $adwa['month']);
        $this->assertSame(23, $adwa['day']);
        $this->assertSame('1896-03-01', ethiopianToGregorian(1888, 6, 23));

        // Ethiopian Millennium (Meskerem 1, 2000 <-> September 12, 2007)
        $mil = gregorianToEthParts('2007-09-12');
        $this->assertSame(2000, $mil['year']);
        $this->assertSame(1, $mil['month']);
        $this->assertSame(1, $mil['day']);
        $this->assertSame('2007-09-12', ethiopianToGregorian(2000, 1, 1));

        // Ginbot 20, 1983 <-> May 28, 1991
        $g20 = gregorianToEthParts('1991-05-28');
        $this->assertSame(1983, $g20['year']);
        $this->assertSame(9, $g20['month']);
        $this->assertSame(20, $g20['day']);
        $this->assertSame('1991-05-28', ethiopianToGregorian(1983, 9, 20));

        // Patriots Victory Day (Miyazya 27, 1933 <-> May 5, 1941)
        $patriot = gregorianToEthParts('1941-05-05');
        $this->assertSame(1933, $patriot['year']);
        $this->assertSame(8, $patriot['month']);
        $this->assertSame(27, $patriot['day']);
        $this->assertSame('1941-05-05', ethiopianToGregorian(1933, 8, 27));

        // Far future: January 1, 2050 <-> Tahsas 23, 2042
        $future = gregorianToEthParts('2050-01-01');
        $this->assertSame(2042, $future['year']);
        $this->assertSame(4, $future['month']);
        $this->assertSame(23, $future['day']);
        $this->assertSame('2050-01-01', ethiopianToGregorian(2042, 4, 23));
    }

    public function testBidirectionalConsistencyAcrossYears(): void
    {
        // 100 sample dates across multiple years and centuries
        $sampleYears = [1900, 1941, 1974, 1991, 2000, 2010, 2020, 2024, 2026, 2027, 2030, 2050];
        foreach ($sampleYears as $y) {
            foreach ([1, 4, 7, 9, 12] as $m) {
                $greg = sprintf('%04d-%02d-15', $y, $m);
                $eth = gregorianToEthParts($greg);
                $this->assertNotNull($eth, "Conversion failed for $greg");
                $back = ethiopianToGregorian($eth['year'], $eth['month'], $eth['day']);
                $this->assertSame($greg, $back, "Bidirectional roundtrip failed for $greg");
            }
        }
    }
}
