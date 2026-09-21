<?php

namespace Tests\Unit;

use App\Services\Ingestion\CsvStream;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class CsvStreamTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();
        $this->path = tempnam(sys_get_temp_dir(), 'csvstream').'.csv';
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        parent::tearDown();
    }

    private function write(string $contents): string
    {
        file_put_contents($this->path, $contents);

        return $this->path;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function read(string $contents): array
    {
        return iterator_to_array(CsvStream::rows($this->write($contents)), false);
    }

    #[Test]
    public function it_maps_each_row_onto_the_header(): void
    {
        $rows = $this->read("date,premise_code,item_code,price\n2026-08-01,2,1,10.20\n");

        $this->assertSame([[
            'date' => '2026-08-01',
            'premise_code' => '2',
            'item_code' => '1',
            'price' => '10.20',
        ]], $rows);
    }

    #[Test]
    public function it_strips_a_utf8_byte_order_mark_from_the_first_column_name(): void
    {
        $rows = $this->read("\xEF\xBB\xBFdate,price\n2026-08-01,10.20\n");

        // Without stripping, the key would be "\xEF\xBB\xBFdate" and every lookup of
        // 'date' in the importer would silently miss.
        $this->assertArrayHasKey('date', $rows[0]);
        $this->assertSame('2026-08-01', $rows[0]['date']);
    }

    #[Test]
    public function it_skips_blank_lines(): void
    {
        $rows = $this->read("date,price\n2026-08-01,10.20\n\n2026-08-02,11.00\n");

        $this->assertCount(2, $rows);
    }

    #[Test]
    public function it_skips_rows_with_the_wrong_number_of_columns(): void
    {
        $rows = $this->read("date,premise_code,item_code,price\n2026-08-01,2,1\n2026-08-01,2,1,10.20\n");

        $this->assertCount(1, $rows);
        $this->assertSame('10.20', $rows[0]['price']);
    }

    #[Test]
    public function it_honours_quoted_fields_containing_commas(): void
    {
        // Premise addresses in the real lookup are full of commas inside quotes.
        $rows = $this->read("premise_code,address\n2,\"JALAN LAKSAMANA, TAMAN JUBILEE, IPOH\"\n");

        $this->assertSame('JALAN LAKSAMANA, TAMAN JUBILEE, IPOH', $rows[0]['address']);
    }

    #[Test]
    public function it_treats_a_backslash_as_data_rather_than_an_escape_character(): void
    {
        // PHP's default escape character is a backslash, which is not RFC 4180 and
        // would swallow the following quote in an address like "NO 5\".
        $rows = $this->read("premise_code,address\n2,\"LOT 4991\\\"\n");

        $this->assertSame('LOT 4991\\', $rows[0]['address']);
    }

    #[Test]
    public function it_trims_whitespace_around_header_names(): void
    {
        $rows = $this->read("date , price \n2026-08-01,10.20\n");

        $this->assertArrayHasKey('date', $rows[0]);
        $this->assertArrayHasKey('price', $rows[0]);
    }

    #[Test]
    public function it_yields_nothing_for_an_empty_file(): void
    {
        $this->assertSame([], $this->read(''));
    }

    #[Test]
    public function it_throws_when_the_file_cannot_be_opened(): void
    {
        $this->expectException(RuntimeException::class);

        iterator_to_array(CsvStream::rows(sys_get_temp_dir().'/does-not-exist-'.uniqid().'.csv'));
    }

    #[Test]
    public function it_reads_a_large_file_without_growing_memory(): void
    {
        // The whole point of the generator. 200k rows is ~5 MB on disk; reading it
        // into an array would cost tens of megabytes, so a flat profile here is what
        // distinguishes streaming from slurping.
        $handle = fopen($this->path, 'wb');
        fwrite($handle, "date,premise_code,item_code,price\n");
        for ($i = 0; $i < 200_000; $i++) {
            fwrite($handle, "2026-08-01,2,1,10.20\n");
        }
        fclose($handle);

        $before = memory_get_usage(true);
        $count = 0;

        foreach (CsvStream::rows($this->path) as $row) {
            $count++;
        }

        $growth = memory_get_usage(true) - $before;

        $this->assertSame(200_000, $count);
        $this->assertLessThan(
            2 * 1024 * 1024,
            $growth,
            'Streaming 200k rows should not grow resident memory by more than 2 MB.'
        );
    }
}
