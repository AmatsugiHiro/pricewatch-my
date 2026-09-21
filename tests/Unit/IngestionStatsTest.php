<?php

namespace Tests\Unit;

use App\Services\Ingestion\IngestionStats;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class IngestionStatsTest extends TestCase
{
    #[Test]
    public function it_reports_the_quarantine_rate_as_a_percentage(): void
    {
        $stats = new IngestionStats(rowsRead: 1_000_000, rowsQuarantined: 16_636);

        $this->assertSame(1.6636, $stats->quarantineRate());
    }

    #[Test]
    public function it_reports_a_zero_rate_rather_than_dividing_by_zero(): void
    {
        $this->assertSame(0.0, (new IngestionStats)->quarantineRate());
    }

    #[Test]
    public function it_separates_orphaned_rows_from_malformed_ones(): void
    {
        $stats = new IngestionStats(
            rowsRead: 100,
            rowsQuarantined: 30,
            rowsMalformed: 12,
        );

        $this->assertSame(18, $stats->rowsOrphaned());
    }

    #[Test]
    public function it_has_no_unknown_code_payload_when_everything_resolved(): void
    {
        $this->assertNull((new IngestionStats(rowsRead: 10))->unknownCodesPayload());
    }

    #[Test]
    public function it_records_the_unknown_codes_it_encountered(): void
    {
        $stats = new IngestionStats(
            unknownItemCodes: [2023, 2035],
            unknownPremiseCodes: [9999],
        );

        $this->assertSame(
            ['items' => [2023, 2035], 'premises' => [9999]],
            $stats->unknownCodesPayload(),
        );
    }

    #[Test]
    public function it_caps_the_stored_code_lists_so_one_bad_file_cannot_bloat_the_audit_row(): void
    {
        $stats = new IngestionStats(unknownItemCodes: range(1, 5_000));

        $payload = $stats->unknownCodesPayload();

        $this->assertCount(200, $payload['items']);
        $this->assertSame(1, $payload['items'][0]);
    }

    #[Test]
    public function an_unchanged_source_is_marked_skipped(): void
    {
        $stats = IngestionStats::unchanged('abc123');

        $this->assertTrue($stats->skipped);
        $this->assertSame('abc123', $stats->checksum);
        $this->assertSame(0, $stats->rowsRead);
    }
}
