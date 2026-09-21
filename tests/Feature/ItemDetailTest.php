<?php

namespace Tests\Feature;

use App\Enums\WatchDirection;
use App\Livewire\ItemDetail;
use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\User;
use App\Models\WatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ItemDetailTest extends TestCase
{
    use RefreshDatabase;

    private function seedSeries(int $code = 1, string $state = 'Selangor'): Item
    {
        $item = Item::factory()->withCode($code)->create(['item' => 'AYAM BERSIH - STANDARD']);

        foreach (range(1, 20) as $day) {
            DailyItemStatePrice::factory()
                ->on(sprintf('2026-08-%02d', $day))
                ->inState($state)
                ->averaging(9.00 + ($day / 100))
                ->create(['item_code' => $code]);
        }

        return $item;
    }

    #[Test]
    public function it_shows_an_item_with_its_price_trend(): void
    {
        $this->seedSeries();

        Livewire::test(ItemDetail::class, ['itemCode' => 1])
            ->assertOk()
            ->assertSee('Ayam Bersih - Standard')
            ->assertSee('Price trend');
    }

    #[Test]
    public function an_unknown_item_returns_a_404(): void
    {
        $this->get('/item/999999')->assertNotFound();
    }

    #[Test]
    public function the_hover_marker_never_binds_an_empty_svg_coordinate(): void
    {
        $this->seedSeries();

        // While nothing is hovered, `active?.x` is undefined and Alpine binds it as
        // an empty string, which SVG rejects with "Expected length" and which fills
        // the browser console with parse errors on every page view. PHPUnit cannot
        // see SVG parse errors, so this asserts on the markup that causes them.
        $html = Livewire::test(ItemDetail::class, ['itemCode' => 1])->html();

        foreach ([':x1', ':x2', ':cx', ':cy'] as $binding) {
            $this->assertStringNotContainsString(
                $binding.'="active?.x"',
                $html,
                "{$binding} must fall back to a number rather than binding undefined."
            );
            $this->assertStringNotContainsString($binding.'="active?.y"', $html);
        }

        $this->assertStringContainsString(':x1="active?.x ?? 0"', $html);
        $this->assertStringContainsString(':cy="active?.y ?? 0"', $html);
    }

    #[Test]
    public function the_chart_is_keyed_so_a_range_change_rebuilds_its_hover_state(): void
    {
        $this->seedSeries();

        // Livewire morphs the DOM in place and Alpine only evaluates x-data when an
        // element is created, so without a key that varies with the range the
        // tooltip would keep serving the previous range's points.
        Livewire::test(ItemDetail::class, ['itemCode' => 1])
            ->assertSee('chart-90-', false)
            ->call('setRange', 30)
            ->assertSee('chart-30-', false);
    }

    #[Test]
    public function it_only_accepts_a_range_it_offers(): void
    {
        $this->seedSeries();

        // A crafted query string must not be able to ask for an arbitrary scan.
        Livewire::test(ItemDetail::class, ['itemCode' => 1])
            ->call('setRange', 9999)
            ->assertSet('days', 90)
            ->call('setRange', 30)
            ->assertSet('days', 30);
    }

    #[Test]
    public function a_guest_is_invited_to_sign_in_rather_than_shown_a_watch_form(): void
    {
        $this->seedSeries();

        Livewire::test(ItemDetail::class, ['itemCode' => 1])
            ->assertSee('Get told when this price moves')
            ->assertDontSee('Threshold (RM)');
    }

    #[Test]
    public function a_signed_in_user_can_watch_an_item(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('state', 'Selangor')
            ->set('threshold', '8.50')
            ->set('direction', 'below')
            ->call('saveWatch')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('watch_items', [
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
            'threshold_price' => 8.50,
            'direction' => 'below',
        ]);
    }

    #[Test]
    public function a_watch_with_no_state_selected_is_stored_as_national(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '8.50')
            ->call('saveWatch')
            ->assertHasNoErrors();

        $watch = WatchItem::query()->firstOrFail();
        $this->assertNull($watch->state);
        $this->assertTrue($watch->isNational());
    }

    #[Test]
    public function it_rejects_a_duplicate_national_watch_the_unique_index_cannot_catch(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        WatchItem::factory()->national()->create([
            'user_id' => $user->id,
            'item_code' => 1,
        ]);

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '8.50')
            ->call('saveWatch')
            ->assertHasErrors('threshold');

        $this->assertSame(1, WatchItem::query()->count());
    }

    #[Test]
    public function it_rejects_a_threshold_that_is_not_a_positive_number(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '0')
            ->call('saveWatch')
            ->assertHasErrors('threshold');

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '-5')
            ->call('saveWatch')
            ->assertHasErrors('threshold');

        $this->assertSame(0, WatchItem::query()->count());
    }

    #[Test]
    public function a_user_can_stop_watching(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('state', 'Selangor')
            ->call('removeWatch');

        $this->assertSame(0, WatchItem::query()->count());
    }

    #[Test]
    public function one_user_cannot_see_or_remove_another_users_watch(): void
    {
        $this->seedSeries();
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $watch = WatchItem::factory()->create([
            'user_id' => $owner->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);

        Livewire::actingAs($intruder)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('state', 'Selangor')
            ->call('removeWatch');

        $this->assertDatabaseHas('watch_items', ['id' => $watch->id]);
    }

    #[Test]
    public function the_threshold_is_prefilled_just_below_the_current_price(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(ItemDetail::class, ['itemCode' => 1]);

        $this->assertNotSame('', $component->get('threshold'));
        $this->assertLessThan(9.20, (float) $component->get('threshold'));
    }

    #[Test]
    public function the_watch_direction_must_be_a_known_enum_value(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '8.50')
            ->set('direction', 'sideways')
            ->call('saveWatch')
            ->assertHasErrors('direction');
    }

    #[Test]
    public function an_above_watch_is_stored_with_that_direction(): void
    {
        $this->seedSeries();
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(ItemDetail::class, ['itemCode' => 1])
            ->set('threshold', '12.00')
            ->set('direction', 'above')
            ->call('saveWatch')
            ->assertHasNoErrors();

        $this->assertSame(WatchDirection::Above, WatchItem::query()->firstOrFail()->direction);
    }
}
