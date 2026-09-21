<?php

namespace Tests\Concerns;

use App\Services\Ingestion\DatasetSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Lets a test put a source CSV where the importer expects to find one.
 *
 * DatasetSource::fetch() reuses an already-staged file rather than downloading, so
 * pre-placing a fixture exercises the whole import path with no HTTP involved and
 * no network flakiness. The download itself is covered separately in
 * DatasetSourceTest, which fakes the HTTP client.
 */
trait StagesSourceFiles
{
    private string $stagingPath;

    protected function useTemporaryStagingPath(): void
    {
        $this->stagingPath = storage_path('framework/testing/pricecatcher-'.Str::random(12));

        File::ensureDirectoryExists($this->stagingPath);
        config(['pricewatch.staging_path' => $this->stagingPath]);

        // DatasetSource is a singleton built from config at resolve time, so discard
        // any instance created before this override.
        $this->app->forgetInstance(DatasetSource::class);

        $this->beforeApplicationDestroyed(function (): void {
            File::deleteDirectory($this->stagingPath);
        });
    }

    protected function stage(string $filename, string $contents): string
    {
        $path = $this->stagingPath.DIRECTORY_SEPARATOR.$filename;

        File::put($path, $contents);

        return $path;
    }

    /**
     * @param  array<int, array{string, int, int, float|string}>  $rows
     */
    protected function stagePriceCsv(string $period, array $rows): string
    {
        $csv = "date,premise_code,item_code,price\n";

        foreach ($rows as $row) {
            $csv .= implode(',', $row)."\n";
        }

        return $this->stage("pricecatcher_{$period}.csv", $csv);
    }
}
