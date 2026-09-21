<?php

namespace App\Services\Ingestion;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Resolves and downloads the upstream open-data CSVs.
 */
final class DatasetSource
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $stagingPath,
        private readonly int $timeout,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            rtrim((string) config('pricewatch.base_url'), '/'),
            (string) config('pricewatch.staging_path'),
            (int) config('pricewatch.download_timeout'),
        );
    }

    public function filename(string $dataset, ?string $period = null): string
    {
        return $period === null
            ? "{$dataset}.csv"
            : "{$dataset}_{$period}.csv";
    }

    public function url(string $dataset, ?string $period = null): string
    {
        return $this->baseUrl.'/'.$this->filename($dataset, $period);
    }

    public function localPath(string $dataset, ?string $period = null): string
    {
        return $this->stagingPath.DIRECTORY_SEPARATOR.$this->filename($dataset, $period);
    }

    /**
     * Download the dataset to local staging and return the absolute path.
     *
     * The response is streamed straight to disk with sink(), so a 48 MB file never
     * passes through PHP's memory. An already-staged file is reused unless $force
     * is set, which keeps repeated development runs off the public data portal.
     */
    public function fetch(string $dataset, ?string $period = null, bool $force = false): string
    {
        $path = $this->localPath($dataset, $period);

        if (! $force && is_file($path) && filesize($path) > 0) {
            return $path;
        }

        if (! is_dir($this->stagingPath) && ! mkdir($this->stagingPath, 0775, true) && ! is_dir($this->stagingPath)) {
            throw new RuntimeException("Unable to create staging directory: {$this->stagingPath}");
        }

        $url = $this->url($dataset, $period);

        // Download to a temporary name first so an interrupted transfer can never be
        // mistaken for a complete, cached file on the next run.
        $temporaryPath = $path.'.partial';

        $response = Http::timeout($this->timeout)
            ->sink($temporaryPath)
            ->get($url);

        if (! $response->successful()) {
            @unlink($temporaryPath);

            throw new RuntimeException(
                "Failed to download {$url} (HTTP {$response->status()})."
            );
        }

        if (! is_file($temporaryPath) || filesize($temporaryPath) === 0) {
            @unlink($temporaryPath);

            throw new RuntimeException("Downloaded an empty file from {$url}.");
        }

        @unlink($path);

        if (! rename($temporaryPath, $path)) {
            throw new RuntimeException("Unable to move downloaded file into place: {$path}");
        }

        return $path;
    }
}
