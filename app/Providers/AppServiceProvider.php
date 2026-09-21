<?php

namespace App\Providers;

use App\Services\Ingestion\DatasetSource;
use App\Services\Ingestion\IngestionRunRecorder;
use App\Services\Ingestion\LookupImporter;
use App\Services\Ingestion\PriceCatcherImporter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // These services take scalar configuration, so the container cannot build
        // them by reflection alone. Binding them here keeps config reading in one
        // place and lets tests swap in a fake DatasetSource.
        $this->app->singleton(DatasetSource::class, fn () => DatasetSource::fromConfig());

        $this->app->singleton(IngestionRunRecorder::class, fn () => new IngestionRunRecorder);

        $this->app->bind(LookupImporter::class, fn ($app) => new LookupImporter(
            $app->make(DatasetSource::class),
            $app->make(IngestionRunRecorder::class),
            (int) config('pricewatch.chunk_size'),
        ));

        $this->app->bind(PriceCatcherImporter::class, fn ($app) => new PriceCatcherImporter(
            $app->make(DatasetSource::class),
            $app->make(IngestionRunRecorder::class),
            (int) config('pricewatch.chunk_size'),
        ));
    }

    public function boot(): void
    {
        // Fail loudly in development when a relation is used without being loaded,
        // when a fillable attribute is missing, or when an attribute is accessed
        // that was never selected. All three are silent in production by default
        // and are the usual cause of N+1 queries slipping into a release.
        Model::shouldBeStrict($this->app->isLocal());

        // Any query slower than 500ms during development is worth knowing about
        // while there are 20 million rows in the fact table.
        if ($this->app->isLocal()) {
            DB::whenQueryingForLongerThan(500, function ($connection, $event) {
                logger()->warning('Slow query', [
                    'sql' => $event->sql,
                    'time_ms' => $event->time,
                ]);
            });
        }
    }
}
