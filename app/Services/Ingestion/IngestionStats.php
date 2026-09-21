<?php

namespace App\Services\Ingestion;

/**
 * The outcome of one pass over a source file.
 */
final readonly class IngestionStats
{
    /**
     * @param  array<int, int>  $unknownItemCodes  Item codes present in the data but absent from the lookup.
     * @param  array<int, int>  $unknownPremiseCodes  Premise codes present in the data but absent from the lookup.
     */
    public function __construct(
        public int $rowsRead = 0,
        public int $rowsUpserted = 0,
        public int $rowsQuarantined = 0,
        public int $rowsMalformed = 0,
        public array $unknownItemCodes = [],
        public array $unknownPremiseCodes = [],
        public ?string $checksum = null,
        public bool $skipped = false,
    ) {}

    /**
     * The upstream file is byte-identical to one already ingested, so there is
     * nothing to do.
     */
    public static function unchanged(string $checksum): self
    {
        return new self(checksum: $checksum, skipped: true);
    }

    /**
     * Share of rows rejected by validation, as a percentage.
     */
    public function quarantineRate(): float
    {
        return $this->rowsRead === 0
            ? 0.0
            : round($this->rowsQuarantined / $this->rowsRead * 100, 4);
    }

    /**
     * Rows rejected because a referenced item or premise does not exist upstream,
     * as opposed to rows that were structurally malformed.
     */
    public function rowsOrphaned(): int
    {
        return max(0, $this->rowsQuarantined - $this->rowsMalformed);
    }

    /**
     * @return array{items: array<int, int>, premises: array<int, int>}|null
     */
    public function unknownCodesPayload(): ?array
    {
        if ($this->unknownItemCodes === [] && $this->unknownPremiseCodes === []) {
            return null;
        }

        // Cap the stored lists. A pathological file should not write a megabyte of
        // JSON into an audit row; the counts above already carry the magnitude.
        return [
            'items' => array_slice($this->unknownItemCodes, 0, 200),
            'premises' => array_slice($this->unknownPremiseCodes, 0, 200),
        ];
    }
}
