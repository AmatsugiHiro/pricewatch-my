<?php

namespace Tests\Feature;

use App\Enums\WatchDirection;
use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\PriceAlert;
use App\Models\PriceRecord;
use App\Models\User;
use App\Models\WatchItem;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StagesSourceFiles;
use Tests\TestCase;

/**
 * End-to-end coverage of the sync command itself.
 *
 * The importer, aggregator and evaluator each had thorough unit coverage, but
 * nothing ran the command that wires them together with alerts switched on. A
 * `$this->app` that does not exist on Illuminate\Console\Command therefore
 * reached production and only surfaced in CI, after a nine-minute import had
 * already committed. Testing the pieces is not the same as testing the seam.
 */
class SyncPriceDataTest extends TestCase
{
    use RefreshDatabase;
    use StagesSourceFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStagingPath();
        config(['pricewatch.chunk_size' => 2]);
        Notification::fake();

        $this->stage('lookup_item.csv', <<<'CSV'
        item_code,item,unit,item_group,item_category
        1,AYAM BERSIH - STANDARD,1kg,BARANGAN SEGAR,AYAM
        CSV);

        $this->stage('lookup_premise.csv', <<<'CSV'
        premise_code,premise,address,premise_type,state,district
        100,PASAR BESAR IPOH,JALAN LAKSAMANA,Pasar Basah,Perak,Kinta
        CSV);

        $this->stagePriceCsv('2026-01', [
            ['2026-01-01', 100, 1, '9.00'],
            ['2026-01-02', 100, 1, '7.50'],
        ]);
    }

    #[Test]
    public function it_runs_the_whole_pipeline_including_the_alert_step(): void
    {
        // No --skip-alerts: this is the branch that was broken in production.
        $this->artisan('pricewatch:sync --month=2026-01')->assertSuccessful();

        $this->assertSame(2, PriceRecord::query()->count());
        $this->assertGreaterThan(0, DailyItemStatePrice::query()->count());
    }

    #[Test]
    public function it_notifies_a_watcher_whose_threshold_the_new_data_crosses(): void
    {
        $user = User::factory()->create();

        // The item does not exist until the lookups are imported by the command,
        // so the watch is created against the code the import will introduce.
        Item::factory()->withCode(1)->create(['item' => 'AYAM BERSIH - STANDARD']);

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Perak',
            'threshold_price' => 8.00,
            'direction' => WatchDirection::Below,
        ]);

        $this->artisan('pricewatch:sync --month=2026-01')
            ->expectsOutputToContain('Evaluating watchlists')
            ->assertSuccessful();

        // 7.50 on the latest day is below the 8.00 threshold.
        $this->assertSame(1, PriceAlert::query()->count());
        Notification::assertSentTo($user, PriceAlertNotification::class);
    }

    #[Test]
    public function skip_alerts_leaves_the_watchlist_alone(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Perak',
            'threshold_price' => 8.00,
            'direction' => WatchDirection::Below,
        ]);

        $this->artisan('pricewatch:sync --month=2026-01 --skip-alerts')
            ->doesntExpectOutputToContain('Evaluating watchlists')
            ->assertSuccessful();

        $this->assertSame(0, PriceAlert::query()->count());
        Notification::assertNothingSent();
    }

    #[Test]
    public function skipping_aggregation_also_skips_alerts(): void
    {
        // Evaluating watches against a rollup that was not rebuilt would judge
        // them on stale prices, so the two are deliberately coupled.
        $this->artisan('pricewatch:sync --month=2026-01 --skip-aggregate')
            ->doesntExpectOutputToContain('Evaluating watchlists')
            ->assertSuccessful();

        $this->assertSame(0, DailyItemStatePrice::query()->count());
    }

    #[Test]
    public function lookups_only_stops_before_touching_prices(): void
    {
        $this->artisan('pricewatch:sync --lookups-only')->assertSuccessful();

        $this->assertSame(1, Item::query()->count());
        $this->assertSame(0, PriceRecord::query()->count());
    }

    #[Test]
    public function it_rejects_a_malformed_period(): void
    {
        $this->artisan('pricewatch:sync --month=January-2026')->assertFailed();
    }

    #[Test]
    public function it_clears_the_shared_cache_so_the_web_tier_sees_new_data(): void
    {
        cache()->put('pricewatch:latest-date', '1999-01-01', 600);
        cache()->put('pricewatch:coverage', ['observations' => 0], 600);

        $this->artisan('pricewatch:sync --month=2026-01')->assertSuccessful();

        // Ingestion runs in a different process from the web tier, so a stale
        // cache here means the live site keeps reporting the previous day.
        $this->assertNull(cache()->get('pricewatch:latest-date'));
        $this->assertNull(cache()->get('pricewatch:coverage'));
    }
}
