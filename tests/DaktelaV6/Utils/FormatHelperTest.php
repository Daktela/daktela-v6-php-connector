<?php


namespace DaktelaV6\Utils;


use Daktela\DaktelaV6\Utils\FormatHelper;

use PHPUnit\Framework\TestCase;

use function PHPUnit\Framework\assertEquals;

class FormatHelperTest extends TestCase
{
    public function testNullCheck() {
        self::assertNull(FormatHelper::getNormalizedPhoneNumber(null));
    }

    public function testFormatCzechNumber()
    {
        self::assertEquals('00420773794604', FormatHelper::getNormalizedPhoneNumber('00420773794604'));
        self::assertEquals('00420773794604', FormatHelper::getNormalizedPhoneNumber('+420773794604'));
        self::assertEquals('00420773794604', FormatHelper::getNormalizedPhoneNumber('773794604'));
        self::assertEquals('00420773794604', FormatHelper::getNormalizedPhoneNumber('420773794604'));
        self::assertEquals('+420773794604', FormatHelper::getNormalizedPhoneNumber('00420773794604', true));
        self::assertEquals('+420773794604', FormatHelper::getNormalizedPhoneNumber('+420773794604', true));
        self::assertEquals('+420773794604', FormatHelper::getNormalizedPhoneNumber('773794604', true));
        self::assertEquals('+420773794604', FormatHelper::getNormalizedPhoneNumber('420773794604', true));
    }

    public function testFormatSlovakNumber()
    {
        self::assertEquals('00421123456789', FormatHelper::getNormalizedPhoneNumber('+421123456789'));
        self::assertEquals('00421123456789', FormatHelper::getNormalizedPhoneNumber('00421123456789'));
        self::assertEquals('00421123456789', FormatHelper::getNormalizedPhoneNumber('421123456789', false, '421', 12));
        self::assertEquals('+421123456789', FormatHelper::getNormalizedPhoneNumber('00421123456789', true, '421', 12));
        self::assertEquals('+421123456789', FormatHelper::getNormalizedPhoneNumber('+421123456789', true, '421', 12));
    }
}
