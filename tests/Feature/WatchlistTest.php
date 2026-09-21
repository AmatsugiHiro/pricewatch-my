<?php

namespace Tests\Feature;

use App\Enums\WatchDirection;
use App\Livewire\Watchlist;
use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\User;
use App\Models\WatchItem;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WatchlistTest extends TestCase
{
    use RefreshDatabase;

    private function priced(int $code, string $name, string $state, float $price): Item
    {
        $item = Item::factory()->withCode($code)->create(['item' => $name]);

        DailyItemStatePrice::factory()
            ->on('2026-08-30')
            ->inState($state)
            ->averaging($price)
            ->create(['item_code' => $code]);

        return $item;
    }

    #[Test]
    public function it_shows_an_empty_state_before_anything_is_watched(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(Watchlist::class)
            ->assertOk()
            ->assertSee('Nothing on your watchlist yet');
    }

    #[Test]
    public function it_lists_the_users_watches_with_the_current_price(): void
    {
        $this->priced(1, 'AYAM BERSIH - STANDARD', 'Selangor', 9.50);
        $user = User::factory()->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
            'threshold_price' => 8.00,
            'direction' => WatchDirection::Below,
        ]);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->assertSee('Ayam Bersih - Standard')
            ->assertSee('9.50')
            ->assertSee('8.00');
    }

    #[Test]
    public function it_marks_a_watch_whose_threshold_has_been_met(): void
    {
        $this->priced(1, 'AYAM BERSIH', 'Selangor', 7.50);
        $user = User::factory()->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
            'threshold_price' => 8.00,
            'direction' => WatchDirection::Below,
        ]);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->assertSee('Threshold met');
    }

    #[Test]
    public function it_shows_how_far_an_unmet_watch_still_has_to_go(): void
    {
        $this->priced(1, 'AYAM BERSIH', 'Selangor', 9.50);
        $user = User::factory()->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
            'threshold_price' => 8.00,
            'direction' => WatchDirection::Below,
        ]);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->assertDontSee('Threshold met')
            ->assertSee('1.50');
    }

    #[Test]
    public function it_copes_with_a_watch_on_a_scope_that_has_no_price(): void
    {
        Item::factory()->withCode(1)->create(['item' => 'AYAM BERSIH']);
        $user = User::factory()->create();

        WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Terengganu',
        ]);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->assertOk()
            ->assertSee('No data');
    }

    #[Test]
    public function a_user_only_sees_their_own_watches(): void
    {
        $this->priced(1, 'AYAM MINE', 'Selangor', 9.50);
        $this->priced(2, 'AYAM THEIRS', 'Selangor', 9.50);

        $user = User::factory()->create();
        $other = User::factory()->create();

        WatchItem::factory()->create(['user_id' => $user->id, 'item_code' => 1, 'state' => 'Selangor']);
        WatchItem::factory()->create(['user_id' => $other->id, 'item_code' => 2, 'state' => 'Selangor']);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->assertSee('Ayam Mine')
            ->assertDontSee('Ayam Theirs');
    }

    #[Test]
    public function a_user_can_remove_their_own_watch(): void
    {
        $this->priced(1, 'AYAM BERSIH', 'Selangor', 9.50);
        $user = User::factory()->create();

        $watch = WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);

        Livewire::actingAs($user)
            ->test(Watchlist::class)
            ->call('removeWatch', $watch->id);

        $this->assertDatabaseMissing('watch_items', ['id' => $watch->id]);
    }

    #[Test]
    public function a_user_cannot_remove_someone_elses_watch(): void
    {
        $this->priced(1, 'AYAM BERSIH', 'Selangor', 9.50);
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        $watch = WatchItem::factory()->create([
            'user_id' => $owner->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);

        // The id is guessable, so the lookup must be scoped to the current user
        // rather than merely hidden from the interface. Scoped that way, another
        // user's row does not exist as far as this query is concerned.
        try {
            Livewire::actingAs($intruder)
                ->test(Watchlist::class)
                ->call('removeWatch', $watch->id);

            $this->fail("Removing another user's watch should not have succeeded.");
        } catch (ModelNotFoundException) {
            // Expected.
        }

        $this->assertDatabaseHas('watch_items', ['id' => $watch->id]);
    }
}
