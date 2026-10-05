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
}
