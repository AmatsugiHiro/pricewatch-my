<?php

namespace App\Services\Ingestion;

use App\Models\IngestionRun;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Imports the two small reference datasets: items and premises.
 *
 * These are a few hundred KB each, so they could be loaded naively. They use the
 * same streaming path as prices anyway, because that keeps one code path to reason
 * about and one place where source quirks are handled.
 */
final class LookupImporter
{
    public function __construct(
        private readonly DatasetSource $source,
        private readonly IngestionRunRecorder $recorder,
        private readonly int $chunkSize,
    ) {}

    public static function make(): self
    {
        return new self(
            DatasetSource::fromConfig(),
            new IngestionRunRecorder,
            (int) config('pricewatch.chunk_size'),
        );
    }

    public function importItems(bool $force = false): IngestionRun
    {
        return $this->import(
            dataset: IngestionRun::DATASET_ITEMS,
            table: 'items',
            uniqueBy: ['item_code'],
            updateColumns: ['item', 'unit', 'item_group', 'item_category', 'updated_at'],
            mapper: $this->mapItem(...),
            force: $force,
        );
    }

    public function importPremises(bool $force = false): IngestionRun
    {
        return $this->import(
            dataset: IngestionRun::DATASET_PREMISES,
            table: 'premises',
            uniqueBy: ['premise_code'],
            updateColumns: ['premise', 'address', 'premise_type', 'state', 'district', 'updated_at'],
            mapper: $this->mapPremise(...),
            force: $force,
        );
    }

    /**
     * @param  array<int, string>  $uniqueBy
     * @param  array<int, string>  $updateColumns
     * @param  Closure(array<string, string>, string): ?array<string, mixed>  $mapper
     */
    private function import(
        string $dataset,
        string $table,
        array $uniqueBy,
        array $updateColumns,
        Closure $mapper,
        bool $force,
    ): IngestionRun {
        return $this->recorder->record($dataset, null, function (IngestionRun $run) use (
            $dataset, $table, $uniqueBy, $updateColumns, $mapper, $force
        ): IngestionStats {
            $path = $this->source->fetch($dataset, null, $force);
            $checksum = hash_file('sha256', $path);

            if (! $force && $this->alreadyIngested($dataset, $checksum, $run)) {
                return IngestionStats::unchanged($checksum);
            }

            DB::connection()->disableQueryLog();

            $now = now()->toDateTimeString();
            $buffer = [];
            $read = 0;
            $upserted = 0;
            $quarantined = 0;

            foreach (CsvStream::rows($path) as $row) {
                $read++;
                $mapped = $mapper($row, $now);

                if ($mapped === null) {
                    $quarantined++;

                    continue;
                }

                $buffer[] = $mapped;

                if (count($buffer) >= $this->chunkSize) {
                    DB::table($table)->upsert($buffer, $uniqueBy, $updateColumns);
                    $upserted += count($buffer);
                    $buffer = [];
                }
            }

            if ($buffer !== []) {
                DB::table($table)->upsert($buffer, $uniqueBy, $updateColumns);
                $upserted += count($buffer);
            }

            return new IngestionStats(
                rowsRead: $read,
                rowsUpserted: $upserted,
                rowsQuarantined: $quarantined,
                rowsMalformed: $quarantined,
                checksum: $checksum,
            );
        });
    }

    /**
     * @param  array<string, string>  $row
     * @return ?array<string, mixed>
     */
    private function mapItem(array $row, string $now): ?array
    {
        $code = filter_var($row['item_code'] ?? '', FILTER_VALIDATE_INT);
        $name = trim($row['item'] ?? '');

        // Both lookups ship an "unknown" sentinel row with code -1 and empty fields.
        // It has no real-world referent, and would not fit an unsigned column.
        if ($code === false || $code <= 0 || $name === '') {
            return null;
        }

        return [
            'item_code' => $code,
            'item' => Str::limit($name, 255, ''),
            'unit' => $this->nullableString($row['unit'] ?? null, 50),
            'item_group' => $this->nullableString($row['item_group'] ?? null, 100),
            'item_category' => $this->nullableString($row['item_category'] ?? null, 100),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return ?array<string, mixed>
     */
    private function mapPremise(array $row, string $now): ?array
    {
        $code = filter_var($row['premise_code'] ?? '', FILTER_VALIDATE_INT);
        $name = trim($row['premise'] ?? '');

        if ($code === false || $code <= 0 || $name === '') {
            return null;
        }

        return [
            'premise_code' => $code,
            'premise' => Str::limit($name, 255, ''),
            'address' => $this->nullableString($row['address'] ?? null, 1000),
            // Several premise_type values in the source carry a trailing space
            // ("Pasar Basah "), which would otherwise split one category into two
            // in every filter dropdown.
            'premise_type' => $this->nullableString($row['premise_type'] ?? null, 100),
            'state' => $this->nullableString($row['state'] ?? null, 100),
            'district' => $this->nullableString($row['district'] ?? null, 100),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function nullableString(?string $value, int $limit): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : Str::limit($value, $limit, '');
    }

    private function alreadyIngested(string $dataset, string $checksum, IngestionRun $current): bool
    {
        return IngestionRun::query()
            ->where('dataset', $dataset)
            ->where('status', IngestionRun::STATUS_COMPLETED)
            ->where('source_checksum', $checksum)
            ->whereKeyNot($current->getKey())
            ->exists();
    }
}
