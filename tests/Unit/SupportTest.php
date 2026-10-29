<?php

declare(strict_types=1);

namespace RoyaltyFusion\Afip\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RoyaltyFusion\Afip\Support\Cbu;
use RoyaltyFusion\Afip\Support\Cuit;
use RoyaltyFusion\Afip\Support\Date;
use RoyaltyFusion\Afip\Support\Num;

final class SupportTest extends TestCase
{
    public function testCuitValidation(): void
    {
        self::assertTrue(Cuit::isValid('20111111112'));
        self::assertTrue(Cuit::isValid('30-50001091-2'));
        self::assertTrue(Cuit::isValid('20267565393'));
        self::assertFalse(Cuit::isValid('20111111111'));
        self::assertFalse(Cuit::isValid('2011111111'));
        self::assertFalse(Cuit::isValid('99111111112'));
        self::assertSame('30-50001091-2', Cuit::format('30500010912'));
        self::assertTrue(Cuit::isLegalEntity('30500010912'));
    }

    public function testCbuValidation(): void
    {
        self::assertTrue(Cbu::isValid('2850590940090418135201'));
        self::assertFalse(Cbu::isValid('2850590940090418135202'));
        self::assertFalse(Cbu::isValid('285059094009041813520'));
    }

    public function testNumRounding(): void
    {
        self::assertSame(1.01, Num::round(1.005));
        self::assertSame(-1.01, Num::round(-1.005));
        self::assertSame(17.35, Num::round(82.64 * 0.21));
        self::assertSame('1234.50', Num::format(1234.5));
        self::assertSame('1350.5', Num::formatCompact(1350.5));
        self::assertSame(3, Num::decimals(1.234));
        self::assertSame('1.234,56', Num::display(1234.56));
        self::assertTrue(Num::equals(100.0, 100.01, 0.01));
        self::assertFalse(Num::equals(100.0, 100.02, 0.01));
    }

    public function testDates(): void
    {
        $d = Date::fromAfip('20261029');
        self::assertNotNull($d);
        self::assertSame('2026-10-29', $d->format('Y-m-d'));
        self::assertNull(Date::fromAfip(''));
        self::assertNull(Date::fromAfip('NULL'));
        self::assertSame('20261029', Date::toAfip($d));
        self::assertSame(3, Date::diffDays(new \DateTimeImmutable('2026-10-29'), new \DateTimeImmutable('2026-11-01')));
        self::assertSame(-3, Date::diffDays(new \DateTimeImmutable('2026-11-01'), new \DateTimeImmutable('2026-10-29')));
    }
}
