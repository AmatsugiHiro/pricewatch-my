<?php

namespace Tests\Feature;

use App\Models\IngestionRun;
use App\Services\Ingestion\DatasetSource;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\StagesSourceFiles;
use Tests\TestCase;

class DatasetSourceTest extends TestCase
{
    use StagesSourceFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStagingPath();
        config(['pricewatch.base_url' => 'https://storage.data.gov.my/pricecatcher']);
    }

    private function source(): DatasetSource
    {
        return $this->app->make(DatasetSource::class);
    }

    #[Test]
    public function it_builds_the_published_url_for_a_monthly_price_file(): void
    {
        $this->assertSame(
            'https://storage.data.gov.my/pricecatcher/pricecatcher_2026-08.csv',
            $this->source()->url(IngestionRun::DATASET_PRICES, '2026-08'),
        );
    }

    #[Test]
    public function it_builds_the_published_url_for_a_lookup_file(): void
    {
        $this->assertSame(
            'https://storage.data.gov.my/pricecatcher/lookup_item.csv',
            $this->source()->url(IngestionRun::DATASET_ITEMS),
        );
    }

    #[Test]
    public function it_reuses_a_staged_file_instead_of_downloading_again(): void
    {
        Http::fake();

        $staged = $this->stage('pricecatcher_2026-08.csv', "date,premise_code,item_code,price\n");

        $path = $this->source()->fetch(IngestionRun::DATASET_PRICES, '2026-08');

        $this->assertSame($staged, $path);
        Http::assertNothingSent();
    }

    #[Test]
    public function forcing_a_fetch_redownloads_even_when_a_file_is_staged(): void
    {
        $this->stage('pricecatcher_2026-08.csv', "stale\n");

        Http::fake([
            '*' => Http::response("date,premise_code,item_code,price\n2026-08-01,2,1,10.20\n"),
        ]);

        $path = $this->source()->fetch(IngestionRun::DATASET_PRICES, '2026-08', force: true);

        Http::assertSentCount(1);
        $this->assertStringContainsString('2026-08-01', file_get_contents($path));
    }

    #[Test]
    public function it_raises_a_clear_error_when_the_publisher_returns_a_failure(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HTTP 404');

        $this->source()->fetch(IngestionRun::DATASET_PRICES, '2099-01');
    }

    #[Test]
    public function a_failed_download_leaves_no_partial_file_to_be_mistaken_for_a_cache_hit(): void
    {
        Http::fake(['*' => Http::response('Not Found', 404)]);

        try {
            $this->source()->fetch(IngestionRun::DATASET_PRICES, '2099-01');
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFileDoesNotExist($this->source()->localPath(IngestionRun::DATASET_PRICES, '2099-01'));
        $this->assertFileDoesNotExist($this->source()->localPath(IngestionRun::DATASET_PRICES, '2099-01').'.partial');
    }
}
