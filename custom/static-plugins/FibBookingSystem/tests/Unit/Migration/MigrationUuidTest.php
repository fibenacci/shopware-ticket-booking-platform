<?php

declare(strict_types=1);

namespace FibBookingSystem\Tests\Unit\Migration;

use FibBookingSystem\Migration\Migration1717000000CreateBookingTables;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Shopware\Core\Framework\Uuid\Uuid;

class MigrationUuidTest extends TestCase
{
    public function testMigrationUsesStableUuidGenerationInsteadOfHardcodedHexIds(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(Migration1717000000CreateBookingTables::class))->getFileName());

        static::assertStringContainsString('Uuid::fromStringToHex', $source);
        static::assertDoesNotMatchRegularExpression('/[\'"][0-9a-f]{32}[\'"]/', $source);
        static::assertTrue(Uuid::isValid($this->stableId('number-range.reservation')));
    }

    private function stableId(string $name): string
    {
        return Uuid::fromStringToHex('fib-booking-system.' . $name);
    }
}
