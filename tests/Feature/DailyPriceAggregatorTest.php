<?php

namespace Tests\Feature;

use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\Premise;
use App\Models\PriceRecord;
use App\Services\Aggregation\DailyPriceAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DailyPriceAggregatorTest extends TestCase
{
    use RefreshDatabase;

    private function aggregator(): DailyPriceAggregator
    {
        return $this->app->make(DailyPriceAggregator::class);
    }

    private function observation(string $date, int $premise, int $item, float $price): void
    {
        PriceRecord::factory()->create([
            'date' => $date,
            'premise_code' => $premise,
            'item_code' => $item,
            'price' => $price,
        ]);
    }

    private function rollupFor(string $date, int $item, string $state): DailyItemStatePrice
    {
        return DailyItemStatePrice::query()
            ->where('date', $date)
            ->where('item_code', $item)
            ->where('state', $state)
            ->firstOrFail();
    }

    #[Test]
    public function it_rolls_observations_up_per_date_item_and_state(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
        Premise::factory()->withCode(101)->inState('Selangor')->create();
        Premise::factory()->withCode(200)->inState('Perak')->create();

        $this->observation('2026-01-01', 100, 1, 10.00);
        $this->observation('2026-01-01', 101, 1, 14.00);
        $this->observation('2026-01-01', 200, 1, 20.00);

        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $selangor = $this->rollupFor('2026-01-01', 1, 'Selangor');
        $this->assertEquals(10.00, $selangor->min_price);
        $this->assertEquals(14.00, $selangor->max_price);
        $this->assertEquals(12.00, $selangor->avg_price);
        $this->assertSame(2, $selangor->sample_count);

        $perak = $this->rollupFor('2026-01-01', 1, 'Perak');
        $this->assertEquals(20.00, $perak->avg_price);
        $this->assertSame(1, $perak->sample_count);
    }

    #[Test]
    public function it_keeps_each_day_separate(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();

        $this->observation('2026-01-01', 100, 1, 10.00);
        $this->observation('2026-01-02', 100, 1, 18.00);

        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertEquals(10.00, $this->rollupFor('2026-01-01', 1, 'Selangor')->avg_price);
        $this->assertEquals(18.00, $this->rollupFor('2026-01-02', 1, 'Selangor')->avg_price);
    }

    #[Test]
    public function it_excludes_premises_with_no_state_rather_than_inventing_a_bucket(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
        Premise::factory()->withCode(999)->withoutState()->create();

        $this->observation('2026-01-01', 100, 1, 10.00);
        $this->observation('2026-01-01', 999, 1, 99.00);

        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(1, DailyItemStatePrice::query()->count());
        $this->assertEquals(10.00, $this->rollupFor('2026-01-01', 1, 'Selangor')->avg_price);
    }

    #[Test]
    public function rebuilding_twice_does_not_duplicate_rollups(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
        $this->observation('2026-01-01', 100, 1, 10.00);

        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(1, DailyItemStatePrice::query()->count());
    }

    #[Test]
    public function a_rebuild_reflects_corrected_underlying_prices(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
        $this->observation('2026-01-01', 100, 1, 10.00);

        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));
        $this->assertEquals(10.00, $this->rollupFor('2026-01-01', 1, 'Selangor')->avg_price);

        PriceRecord::query()->update(['price' => 15.00]);
        $this->aggregator()->rebuild(Carbon::parse('2026-01-01'), Carbon::parse('2026-01-31'));

        $this->assertSame(1, DailyItemStatePrice::query()->count());
        $this->assertEquals(15.00, $this->rollupFor('2026-01-01', 1, 'Selangor')->avg_price);
    }

    #[Test]
    public function it_only_rebuilds_the_requested_date_range(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();

        $this->observation('2026-01-15', 100, 1, 10.00);
        $this->observation('2026-02-15', 100, 1, 20.00);

        $this->aggregator()->rebuildPeriod('2026-01');

        $this->assertSame(1, DailyItemStatePrice::query()->count());
        $this->assertEquals(10.00, $this->rollupFor('2026-01-15', 1, 'Selangor')->avg_price);
    }
}
