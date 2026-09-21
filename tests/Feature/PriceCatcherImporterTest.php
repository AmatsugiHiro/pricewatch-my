<?php

namespace Tests\Feature;

use App\Models\IngestionRun;
use App\Models\Item;
use App\Models\Premise;
use App\Models\PriceRecord;
use App\Services\Ingestion\PriceCatcherImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\StagesSourceFiles;
use Tests\TestCase;

class PriceCatcherImporterTest extends TestCase
{
    use RefreshDatabase;
    use StagesSourceFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStagingPath();

        // A tiny chunk size means even a handful of fixture rows exercise the
        // buffer-flush path rather than falling through to the single final flush.
        config(['pricewatch.chunk_size' => 2]);
    }

    private function importer(): PriceCatcherImporter
    {
        return $this->app->make(PriceCatcherImporter::class);
    }

    private function seedLookups(): void
    {
        Item::factory()->withCode(1)->create();
        Item::factory()->withCode(2)->create();
        Premise::factory()->withCode(100)->inState('Selangor')->create();
        Premise::factory()->withCode(200)->inState('Perak')->create();
    }

    #[Test]
    public function it_imports_valid_observations(): void
    {
        $this->seedLookups();

        $this->stagePriceCsv('2026-01', [
            ['2026-01-01', 100, 1, '10.20'],
            ['2026-01-01', 100, 2, '8.50'],
            ['2026-01-02', 200, 1, '11.00'],
        ]);

        $run = $this->importer()->import('2026-01');

        $this->assertSame(IngestionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(3, $run->rows_read);
        $this->assertSame(3, $run->rows_upserted);
        $this->assertSame(0, $run->rows_quarantined);
        $this->assertSame(3, PriceRecord::query()->count());

        $this->assertDatabaseHas('price_records', [
            'date' => '2026-01-01',
            'premise_code' => 100,
            'item_code' => 1,
            'price' => 10.20,
        ]);
    }

    #[Test]
    public function reimporting_a_month_corrects_prices_instead_of_duplicating_rows(): void
    {
        $this->seedLookups();

        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);
        $this->importer()->import('2026-01');

        // The publisher revises the same observation.
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '12.75']]);
        $run = $this->importer()->import('2026-01');

        $this->assertSame(IngestionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(1, PriceRecord::query()->count(), 'The unique key should have collapsed the re-import.');
        $this->assertDatabaseHas('price_records', [
            'date' => '2026-01-01',
            'premise_code' => 100,
            'item_code' => 1,
            'price' => 12.75,
        ]);
    }

    #[Test]
    public function it_skips_the_import_entirely_when_the_source_is_byte_identical(): void
    {
        $this->seedLookups();
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);

        $this->importer()->import('2026-01');
        $second = $this->importer()->import('2026-01');

        $this->assertSame(IngestionRun::STATUS_SKIPPED, $second->status);
        $this->assertSame(0, $second->rows_read);
        $this->assertSame(1, PriceRecord::query()->count());
    }

    #[Test]
    public function forcing_an_import_redownloads_and_reingests(): void
    {
        $this->seedLookups();
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);
        $this->importer()->import('2026-01');

        // force: true deliberately bypasses the staged file, so the download has to
        // be faked or this test would pull ~48 MB from the live data portal.
        Http::fake([
            '*' => Http::response("date,premise_code,item_code,price\n2026-01-01,100,1,13.50\n"),
        ]);

        $forced = $this->importer()->import('2026-01', force: true);

        $this->assertSame(IngestionRun::STATUS_COMPLETED, $forced->status);
        $this->assertSame(1, $forced->rows_read);
        $this->assertSame(1, PriceRecord::query()->count());
        $this->assertDatabaseHas('price_records', ['price' => 13.50]);
    }

    #[Test]
    public function it_quarantines_rows_referencing_an_unknown_item_and_records_the_code(): void
    {
        $this->seedLookups();

        $this->stagePriceCsv('2026-01', [
            ['2026-01-01', 100, 1, '10.20'],
            ['2026-01-01', 100, 2057, '9.10'],   // item 2057 is not in the lookup
            ['2026-01-01', 100, 2094, '9.90'],   // nor is 2094
        ]);

        $run = $this->importer()->import('2026-01');

        $this->assertSame(3, $run->rows_read);
        $this->assertSame(1, $run->rows_upserted);
        $this->assertSame(2, $run->rows_quarantined);
        $this->assertSame(0, $run->rows_malformed, 'These rows are well-formed; only the reference is missing.');
        $this->assertSame([2057, 2094], $run->unknown_codes['items']);
        $this->assertSame([], $run->unknown_codes['premises']);
    }

    #[Test]
    public function it_quarantines_rows_referencing_an_unknown_premise(): void
    {
        $this->seedLookups();

        $this->stagePriceCsv('2026-01', [
            ['2026-01-01', 100, 1, '10.20'],
            ['2026-01-01', 999, 1, '10.20'],
        ]);

        $run = $this->importer()->import('2026-01');

        $this->assertSame(1, $run->rows_quarantined);
        $this->assertSame([999], $run->unknown_codes['premises']);
    }

    #[Test]
    public function it_counts_malformed_rows_separately_from_unresolvable_references(): void
    {
        $this->seedLookups();

        $this->stagePriceCsv('2026-01', [
            ['2026-01-01', 100, 1, '10.20'],    // fine
            ['2026-01-01', 100, 1, '0'],        // non-positive price
            ['2026-01-01', 100, 1, '-3.00'],    // negative price
            ['01-01-2026', 100, 1, '10.20'],    // wrong date format
            ['2026-01-01', 100, 1, 'abc'],      // non-numeric price
            ['2026-01-01', 100, 2057, '9.10'],  // unknown item
        ]);

        $run = $this->importer()->import('2026-01');

        $this->assertSame(6, $run->rows_read);
        $this->assertSame(1, $run->rows_upserted);
        $this->assertSame(5, $run->rows_quarantined);
        $this->assertSame(4, $run->rows_malformed);
        $this->assertSame([2057], $run->unknown_codes['items']);
    }

    #[Test]
    public function it_rejects_a_price_too_large_for_the_column(): void
    {
        $this->seedLookups();

        // decimal(8,2) tops out below 1,000,000. Storing this would truncate
        // silently and poison every average that included it.
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '1000000.00']]);

        $run = $this->importer()->import('2026-01');

        $this->assertSame(0, $run->rows_upserted);
        $this->assertSame(1, $run->rows_malformed);
    }

    #[Test]
    public function it_records_timing_and_memory_for_every_run(): void
    {
        $this->seedLookups();
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);

        $run = $this->importer()->import('2026-01');

        $this->assertNotNull($run->started_at);
        $this->assertNotNull($run->finished_at);
        $this->assertNotNull($run->duration_ms);
        $this->assertGreaterThan(0, $run->peak_memory_bytes);
        $this->assertNotNull($run->source_checksum);
    }

    #[Test]
    public function it_refuses_to_import_prices_before_the_lookups_exist(): void
    {
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Lookup tables are empty');

        $this->importer()->import('2026-01');
    }

    #[Test]
    public function a_failed_run_is_still_recorded_as_an_audit_row(): void
    {
        $this->stagePriceCsv('2026-01', [['2026-01-01', 100, 1, '10.20']]);

        try {
            $this->importer()->import('2026-01');
        } catch (RuntimeException) {
            // expected
        }

        $run = IngestionRun::query()->latest('id')->firstOrFail();

        $this->assertSame(IngestionRun::STATUS_FAILED, $run->status);
        $this->assertStringContainsString('Lookup tables are empty', $run->error);
        $this->assertNotNull($run->finished_at);
    }

    #[Test]
    public function it_rejects_a_period_that_is_not_formatted_as_year_and_month(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('YYYY-MM');

        $this->importer()->import('August 2026');
    }
}
