<?php

declare(strict_types=1);

namespace Daktela\Tests\Unit\Utils;

use Daktela\DaktelaV6\Utils\FormatHelper;
use PHPUnit\Framework\TestCase;

class FormatHelperTest extends TestCase
{
    public function testNullCheck(): void
    {
        self::assertNull(FormatHelper::getNormalizedPhoneNumber(null));
    }

    public function testFormatCzechNumber(): void
    {
        self::assertSame('00420773794604', FormatHelper::getNormalizedPhoneNumber('00420773794604'));
        self::assertSame('00420773794604', FormatHelper::getNormalizedPhoneNumber('+420773794604'));
        self::assertSame('00420773794604', FormatHelper::getNormalizedPhoneNumber('773794604'));
        self::assertSame('00420773794604', FormatHelper::getNormalizedPhoneNumber('420773794604'));
        self::assertSame('00420773794604', FormatHelper::getNormalizedPhoneNumber('773 794 604'));
        self::assertSame('+420773794604', FormatHelper::getNormalizedPhoneNumber('00420773794604', true));
        self::assertSame('+420773794604', FormatHelper::getNormalizedPhoneNumber('+420773794604', true));
        self::assertSame('+420773794604', FormatHelper::getNormalizedPhoneNumber('773794604', true));
        self::assertSame('+420773794604', FormatHelper::getNormalizedPhoneNumber('420773794604', true));
    }

    public function testFormatSlovakNumber(): void
    {
        self::assertSame('00421123456789', FormatHelper::getNormalizedPhoneNumber('+421123456789'));
        self::assertSame('00421123456789', FormatHelper::getNormalizedPhoneNumber('00421123456789'));
        self::assertSame(
            '00421123456789',
            FormatHelper::getNormalizedPhoneNumber('421123456789', false, '421', 12)
        );
        self::assertSame(
            '+421123456789',
            FormatHelper::getNormalizedPhoneNumber('00421123456789', true, '421', 12)
        );
        self::assertSame(
            '+421123456789',
            FormatHelper::getNormalizedPhoneNumber('+421123456789', true, '421', 12)
        );
    }
}
