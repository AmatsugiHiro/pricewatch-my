<?php

namespace Tests\Feature;

use App\Models\IngestionRun;
use App\Models\Item;
use App\Models\Premise;
use App\Services\Ingestion\LookupImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StagesSourceFiles;
use Tests\TestCase;

class LookupImporterTest extends TestCase
{
    use RefreshDatabase;
    use StagesSourceFiles;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useTemporaryStagingPath();
        config(['pricewatch.chunk_size' => 2]);
    }

    private function importer(): LookupImporter
    {
        return $this->app->make(LookupImporter::class);
    }

    #[Test]
    public function it_imports_items(): void
    {
        $this->stage('lookup_item.csv', <<<'CSV'
        item_code,item,unit,item_group,item_category
        1,AYAM BERSIH - STANDARD,1kg,BARANGAN SEGAR,AYAM
        2,AYAM BERSIH - SUPER,1kg,BARANGAN SEGAR,AYAM
        CSV);

        $run = $this->importer()->importItems();

        $this->assertSame(IngestionRun::STATUS_COMPLETED, $run->status);
        $this->assertSame(2, $run->rows_upserted);
        $this->assertDatabaseHas('items', [
            'item_code' => 1,
            'item' => 'AYAM BERSIH - STANDARD',
            'unit' => '1kg',
            'item_category' => 'AYAM',
        ]);
    }

    #[Test]
    public function it_skips_the_unknown_sentinel_row_the_publisher_ships(): void
    {
        // data.gov.my includes a "-1" row in both lookups with empty fields. It has
        // no real-world referent and would not fit an unsigned primary key.
        $this->stage('lookup_item.csv', <<<'CSV'
        item_code,item,unit,item_group,item_category
        -1,,,,
        1,AYAM BERSIH - STANDARD,1kg,BARANGAN SEGAR,AYAM
        CSV);

        $run = $this->importer()->importItems();

        $this->assertSame(2, $run->rows_read);
        $this->assertSame(1, $run->rows_upserted);
        $this->assertSame(1, $run->rows_quarantined);
        $this->assertSame(1, Item::query()->count());
    }

    #[Test]
    public function it_imports_premises_including_quoted_addresses(): void
    {
        $this->stage('lookup_premise.csv', <<<'CSV'
        premise_code,premise,address,premise_type,state,district
        -1,Unknown Premise,Unknown Address,,,
        2,PASAR BESAR IPOH,"JALAN LAKSAMANA,TAMAN JUBILEE,30300 IPOH, PERAK",Pasar Basah ,Perak,Kinta
        CSV);

        $run = $this->importer()->importPremises();

        $this->assertSame(1, $run->rows_upserted);

        $premise = Premise::query()->findOrFail(2);
        $this->assertSame('PASAR BESAR IPOH', $premise->premise);
        $this->assertSame('JALAN LAKSAMANA,TAMAN JUBILEE,30300 IPOH, PERAK', $premise->address);
        $this->assertSame('Perak', $premise->state);
        // The source ships "Pasar Basah " with a trailing space; untrimmed it would
        // split one category into two in every filter dropdown.
        $this->assertSame('Pasar Basah', $premise->premise_type);
    }

    #[Test]
    public function reimporting_updates_a_renamed_item_in_place(): void
    {
        $this->stage('lookup_item.csv', "item_code,item,unit,item_group,item_category\n1,AYAM STANDARD,1kg,SEGAR,AYAM\n");
        $this->importer()->importItems();

        $this->stage('lookup_item.csv', "item_code,item,unit,item_group,item_category\n1,AYAM BERSIH - STANDARD,1kg,SEGAR,AYAM\n");
        $this->importer()->importItems();

        $this->assertSame(1, Item::query()->count());
        $this->assertSame('AYAM BERSIH - STANDARD', Item::query()->findOrFail(1)->item);
    }

    #[Test]
    public function it_skips_an_unchanged_lookup_file(): void
    {
        $this->stage('lookup_item.csv', "item_code,item,unit,item_group,item_category\n1,AYAM,1kg,SEGAR,AYAM\n");

        $this->importer()->importItems();
        $second = $this->importer()->importItems();

        $this->assertSame(IngestionRun::STATUS_SKIPPED, $second->status);
    }
}
