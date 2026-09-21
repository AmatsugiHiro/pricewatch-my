<?php

namespace Tests\Feature;

use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\Premise;
use App\Models\PriceRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PruneObservationsTest extends TestCase
{
    use RefreshDatabase;

    private function observe(string $date, float $price = 10.00): void
    {
        PriceRecord::factory()->create([
            'date' => $date,
            'premise_code' => 100,
            'item_code' => 1,
            'price' => $price,
        ]);
    }

    private function seedLookups(): void
    {
        Item::factory()->withCode(1)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
    }

    #[Test]
    public function it_deletes_observations_older_than_the_retention_window(): void
    {
        $this->seedLookups();

        $this->observe('2026-01-01');   // 242 days before the latest
        $this->observe('2026-06-01');   // 91 days before
        $this->observe('2026-08-01');   // 30 days before
        $this->observe('2026-08-31');   // the latest

        $this->artisan('pricewatch:prune --keep-days=70')->assertSuccessful();

        $this->assertSame(2, PriceRecord::query()->count());
        $this->assertDatabaseHas('price_records', ['date' => '2026-08-01']);
        $this->assertDatabaseHas('price_records', ['date' => '2026-08-31']);
        $this->assertDatabaseMissing('price_records', ['date' => '2026-01-01']);
        $this->assertDatabaseMissing('price_records', ['date' => '2026-06-01']);
    }

    #[Test]
    public function pruning_never_touches_the_rollup(): void
    {
        $this->seedLookups();
        $this->observe('2026-01-01');
        $this->observe('2026-08-31');

        // A year of rollups, including ones whose raw rows are about to vanish.
        foreach (['2026-01-01', '2026-06-01', '2026-08-31'] as $date) {
            DailyItemStatePrice::factory()->on($date)->inState('Selangor')
                ->averaging(10.00)->create(['item_code' => 1]);
        }

        $this->artisan('pricewatch:prune --keep-days=70')->assertSuccessful();

        // This is the whole premise of the retention design: history stays
        // chartable after the rows behind it are aged out.
        $this->assertSame(3, DailyItemStatePrice::query()->count());
        $this->assertDatabaseHas('daily_item_state_prices', ['date' => '2026-01-01']);
    }

    #[Test]
    public function it_refuses_to_prune_inside_the_reaggregation_window(): void
    {
        $this->seedLookups();
        $this->observe('2026-01-01');
        $this->observe('2026-08-31');

        // The scheduled sync rebuilds two months of rollups from raw records, so a
        // 30-day window would leave it aggregating rows that are no longer there.
        $this->artisan('pricewatch:prune --keep-days=30')
            ->expectsOutputToContain('Refusing to keep only 30 days')
            ->assertFailed();

        $this->assertSame(2, PriceRecord::query()->count());
    }

    #[Test]
    public function force_overrides_the_guard(): void
    {
        $this->seedLookups();
        $this->observe('2026-01-01');
        $this->observe('2026-08-31');

        $this->artisan('pricewatch:prune --keep-days=30 --force')->assertSuccessful();

        $this->assertSame(1, PriceRecord::query()->count());
    }

    #[Test]
    public function a_dry_run_deletes_nothing(): void
    {
        $this->seedLookups();
        $this->observe('2026-01-01');
        $this->observe('2026-08-31');

        $this->artisan('pricewatch:prune --keep-days=70 --dry-run')
            ->expectsOutputToContain('Dry run')
            ->assertSuccessful();

        $this->assertSame(2, PriceRecord::query()->count());
    }

    #[Test]
    public function it_measures_the_window_from_the_latest_observation_not_from_today(): void
    {
        $this->seedLookups();

        // Data ends well in the past. Measuring from today would wipe everything.
        $this->observe('2025-03-01');
        $this->observe('2025-04-20');

        $this->artisan('pricewatch:prune --keep-days=70')->assertSuccessful();

        $this->assertSame(2, PriceRecord::query()->count());
    }

    #[Test]
    public function it_succeeds_on_an_empty_table(): void
    {
        $this->artisan('pricewatch:prune')
            ->expectsOutputToContain('nothing to prune')
            ->assertSuccessful();
    }

    #[Test]
    public function it_deletes_across_multiple_chunks(): void
    {
        $this->seedLookups();

        for ($day = 1; $day <= 12; $day++) {
            $this->observe(sprintf('2026-01-%02d', $day));
        }
        $this->observe('2026-08-31');

        // A chunk size below the row count forces the delete loop to iterate.
        $this->artisan('pricewatch:prune --keep-days=70 --chunk=1000')->assertSuccessful();

        $this->assertSame(1, PriceRecord::query()->count());
    }
}
