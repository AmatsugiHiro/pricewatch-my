<?php

namespace Tests\Feature;

use App\Livewire\ItemBrowser;
use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\Premise;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemBrowserTest extends TestCase
{
    use RefreshDatabase;

    private function seedItem(int $code, string $name, string $state, float $price, string $category = 'AYAM'): Item
    {
        $item = Item::factory()->withCode($code)->create([
            'item' => $name,
            'item_category' => $category,
        ]);

        Premise::factory()->inState($state)->create();

        DailyItemStatePrice::factory()
            ->on('2026-08-30')
            ->inState($state)
            ->averaging($price)
            ->create(['item_code' => $code]);

        return $item;
    }

    #[Test]
    public function the_landing_page_loads(): void
    {
        $this->get('/')->assertOk();
    }

    #[Test]
    public function it_lists_items_with_their_latest_price(): void
    {
        $this->seedItem(1, 'AYAM BERSIH - STANDARD', 'Selangor', 9.50);

        Livewire::test(ItemBrowser::class)
            ->assertOk()
            ->assertSee('Ayam Bersih - Standard')
            ->assertSee('9.50');
    }

    #[Test]
    public function it_filters_by_search_term(): void
    {
        $this->seedItem(1, 'AYAM BERSIH - STANDARD', 'Selangor', 9.50);
        $this->seedItem(2, 'TELUR GRED A', 'Selangor', 0.45, 'TELUR');

        Livewire::test(ItemBrowser::class)
            ->set('search', 'telur')
            ->assertSee('Telur Gred A')
            ->assertDontSee('Ayam Bersih');
    }

    #[Test]
    public function it_filters_by_state(): void
    {
        $this->seedItem(1, 'AYAM SELANGOR', 'Selangor', 9.50);
        $this->seedItem(2, 'AYAM PERAK', 'Perak', 8.00);

        Livewire::test(ItemBrowser::class)
            ->set('state', 'Perak')
            ->assertSee('Ayam Perak')
            ->assertDontSee('Ayam Selangor');
    }

    #[Test]
    public function it_filters_by_category(): void
    {
        $this->seedItem(1, 'AYAM BERSIH', 'Selangor', 9.50, 'AYAM');
        $this->seedItem(2, 'TELUR GRED A', 'Selangor', 0.45, 'TELUR');

        Livewire::test(ItemBrowser::class)
            ->set('category', 'TELUR')
            ->assertSee('Telur Gred A')
            ->assertDontSee('Ayam Bersih');
    }

    #[Test]
    public function changing_a_filter_returns_to_the_first_page(): void
    {
        for ($code = 1; $code <= 30; $code++) {
            $this->seedItem($code, "ITEM {$code}", 'Selangor', 5.00);
        }

        // Stranding the user on page 3 of a one-page result set is the bug this
        // guards against.
        Livewire::test(ItemBrowser::class)
            ->set('paginators.page', 2)
            ->set('search', 'ITEM 1')
            ->assertSet('paginators.page', 1);
    }

    #[Test]
    public function clearing_filters_resets_every_field(): void
    {
        $this->seedItem(1, 'AYAM BERSIH', 'Selangor', 9.50);

        Livewire::test(ItemBrowser::class)
            ->set('search', 'ayam')
            ->set('state', 'Selangor')
            ->set('category', 'AYAM')
            ->call('clearFilters')
            ->assertSet('search', '')
            ->assertSet('state', '')
            ->assertSet('category', '');
    }

    #[Test]
    public function it_tells_the_user_when_nothing_matches(): void
    {
        $this->seedItem(1, 'AYAM BERSIH', 'Selangor', 9.50);

        Livewire::test(ItemBrowser::class)
            ->set('search', 'zzzznotanitem')
            ->assertSee('No items match those filters');
    }

    #[Test]
    public function it_renders_without_any_data_loaded(): void
    {
        // A freshly cloned install has no rollups yet; the page must not blow up.
        Livewire::test(ItemBrowser::class)->assertOk();
    }
}
