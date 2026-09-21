<?php

namespace Tests\Feature;

use App\Models\Item;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\WatchItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UserRelationsTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function a_user_has_many_watch_items(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        Item::factory()->withCode(2)->create();

        WatchItem::factory()->create(['user_id' => $user->id, 'item_code' => 1, 'state' => 'Selangor']);
        WatchItem::factory()->create(['user_id' => $user->id, 'item_code' => 2, 'state' => 'Selangor']);
        WatchItem::factory()->create(['user_id' => User::factory(), 'item_code' => 1, 'state' => 'Perak']);

        $this->assertSame(2, $user->watchItems()->count());
    }

    #[Test]
    public function a_user_reaches_their_alerts_through_their_watches(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();

        $watch = WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);

        PriceAlert::factory()->create(['watch_item_id' => $watch->id, 'observed_on' => '2026-08-29']);
        PriceAlert::factory()->create(['watch_item_id' => $watch->id, 'observed_on' => '2026-08-30']);

        // Another user's alert must not leak in.
        $otherWatch = WatchItem::factory()->create(['item_code' => 1, 'state' => 'Perak']);
        PriceAlert::factory()->create(['watch_item_id' => $otherWatch->id, 'observed_on' => '2026-08-30']);

        $this->assertSame(2, $user->priceAlerts()->count());
    }

    #[Test]
    public function deleting_a_user_removes_their_watches_and_alerts(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();

        $watch = WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => 1,
            'state' => 'Selangor',
        ]);
        PriceAlert::factory()->create(['watch_item_id' => $watch->id]);

        $user->delete();

        // Both cascades are declared in the migrations; this proves they fire.
        $this->assertDatabaseCount('watch_items', 0);
        $this->assertDatabaseCount('price_alerts', 0);
    }
}
