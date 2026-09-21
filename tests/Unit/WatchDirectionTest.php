<?php

namespace Tests\Unit;

use App\Enums\WatchDirection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class WatchDirectionTest extends TestCase
{
    /**
     * @return array<string, array{WatchDirection, float, float, bool}>
     */
    public static function thresholds(): array
    {
        return [
            'below: under the threshold breaches' => [WatchDirection::Below, 8.90, 10.00, true],
            'below: exactly at the threshold breaches' => [WatchDirection::Below, 10.00, 10.00, true],
            'below: over the threshold does not' => [WatchDirection::Below, 10.01, 10.00, false],
            'above: over the threshold breaches' => [WatchDirection::Above, 12.00, 10.00, true],
            'above: exactly at the threshold breaches' => [WatchDirection::Above, 10.00, 10.00, true],
            'above: under the threshold does not' => [WatchDirection::Above, 9.99, 10.00, false],
        ];
    }

    #[Test]
    #[DataProvider('thresholds')]
    public function it_decides_whether_a_price_breaches_a_threshold(
        WatchDirection $direction,
        float $observed,
        float $threshold,
        bool $expected,
    ): void {
        $this->assertSame($expected, $direction->isBreached($observed, $threshold));
    }

    #[Test]
    public function it_is_backed_by_the_strings_stored_in_the_database(): void
    {
        $this->assertSame('below', WatchDirection::Below->value);
        $this->assertSame('above', WatchDirection::Above->value);
    }
}
