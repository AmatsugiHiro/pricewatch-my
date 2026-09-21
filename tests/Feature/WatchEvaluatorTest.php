<?php

namespace Tests\Feature;

use App\Enums\WatchDirection;
use App\Models\DailyItemStatePrice;
use App\Models\Item;
use App\Models\PriceAlert;
use App\Models\User;
use App\Models\WatchItem;
use App\Notifications\PriceAlertNotification;
use App\Services\Alerts\WatchEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WatchEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    private const DATE = '2026-08-30';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function evaluator(): WatchEvaluator
    {
        return $this->app->make(WatchEvaluator::class);
    }

    private function rollup(int $itemCode, string $state, float $average, int $samples = 100): void
    {
        DailyItemStatePrice::factory()
            ->on(self::DATE)
            ->inState($state)
            ->averaging($average, $samples)
            ->create(['item_code' => $itemCode]);
    }

    private function watch(User $user, int $itemCode, ?string $state, float $threshold, WatchDirection $direction = WatchDirection::Below): WatchItem
    {
        return WatchItem::factory()->create([
            'user_id' => $user->id,
            'item_code' => $itemCode,
            'state' => $state,
            'threshold_price' => $threshold,
            'direction' => $direction,
        ]);
    }

    #[Test]
    public function it_notifies_when_a_price_falls_to_the_threshold(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 7.50);
        $this->watch($user, 1, 'Selangor', 8.00);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(1, $result->breached);
        $this->assertSame(1, $result->notified);
        Notification::assertSentTo($user, PriceAlertNotification::class);
    }

    #[Test]
    public function it_records_the_breach_as_an_alert_row(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 7.50);
        $watch = $this->watch($user, 1, 'Selangor', 8.00);

        $this->evaluator()->evaluate(self::DATE);

        $this->assertDatabaseHas('price_alerts', [
            'watch_item_id' => $watch->id,
            'observed_on' => self::DATE,
        ]);

        $alert = PriceAlert::query()->firstOrFail();
        $this->assertEquals(7.50, $alert->observed_price);
        $this->assertNotNull($alert->notified_at);
        $this->assertNotNull($watch->fresh()->last_notified_at);
    }

    #[Test]
    public function re_running_the_same_day_does_not_notify_twice(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 7.50);
        $this->watch($user, 1, 'Selangor', 8.00);

        $this->evaluator()->evaluate(self::DATE);
        $second = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(1, $second->breached);
        $this->assertSame(0, $second->notified, 'The second pass must not re-notify.');
        $this->assertSame(1, $second->alreadyNotified());
        $this->assertSame(1, PriceAlert::query()->count());
        Notification::assertSentToTimes($user, PriceAlertNotification::class, 1);
    }

    #[Test]
    public function it_does_not_notify_when_the_threshold_is_not_met(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 9.50);
        $this->watch($user, 1, 'Selangor', 8.00);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(0, $result->breached);
        Notification::assertNothingSent();
    }

    #[Test]
    public function an_above_watch_fires_when_the_price_rises(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 12.00);
        $this->watch($user, 1, 'Selangor', 10.00, WatchDirection::Above);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(1, $result->notified);
    }

    #[Test]
    public function a_watch_on_a_scope_with_no_price_is_skipped_not_failed(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 7.50);
        $this->watch($user, 1, 'Terengganu', 8.00);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(1, $result->evaluated);
        $this->assertSame(1, $result->withoutData);
        $this->assertSame(0, $result->breached);
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_national_watch_is_judged_on_the_sample_weighted_average(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();

        // Weighted:   (10.00*100 + 20.00*900) / 1000 = 19.00
        // Unweighted: (10.00 + 20.00) / 2            = 15.00
        $this->rollup(1, 'Selangor', 10.00, 100);
        $this->rollup(1, 'Perak', 20.00, 900);

        // A threshold of 16.00 sits between the two figures, so this fires only if
        // the evaluator wrongly used the unweighted mean.
        $this->watch($user, 1, null, 16.00, WatchDirection::Below);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(0, $result->breached, 'The national figure must be sample-weighted.');
        Notification::assertNothingSent();
    }

    #[Test]
    public function a_national_watch_fires_against_the_weighted_figure(): void
    {
        $user = User::factory()->create();
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 10.00, 100);
        $this->rollup(1, 'Perak', 20.00, 900);

        $this->watch($user, 1, null, 19.50, WatchDirection::Below);

        $this->assertSame(1, $this->evaluator()->evaluate(self::DATE)->notified);
    }

    #[Test]
    public function it_does_nothing_when_no_one_is_watching(): void
    {
        Item::factory()->withCode(1)->create();
        $this->rollup(1, 'Selangor', 7.50);

        $result = $this->evaluator()->evaluate(self::DATE);

        $this->assertSame(0, $result->evaluated);
        Notification::assertNothingSent();
    }

    #[Test]
    public function it_does_nothing_when_there_is_no_data_at_all(): void
    {
        $result = $this->evaluator()->evaluate();

        $this->assertSame(0, $result->evaluated);
    }

    #[Test]
    public function checking_many_watches_does_not_cost_a_query_each(): void
    {
        $user = User::factory()->create();

        for ($code = 1; $code <= 25; $code++) {
            Item::factory()->withCode($code)->create();
            // Comfortably above every threshold, so none of them breach and the
            // count reflects lookup cost alone.
            $this->rollup($code, 'Selangor', 50.00);
            $this->watch($user, $code, 'Selangor', 1.00);
        }

        DB::flushQueryLog();
        DB::enableQueryLog();

        $result = $this->evaluator()->evaluate(self::DATE);

        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(25, $result->evaluated);
        $this->assertLessThanOrEqual(
            5,
            $queryCount,
            "Evaluating 25 watches should not scale with the number of watches; used {$queryCount} queries."
        );
    }
}
