<?php

namespace App\Models;

use App\Enums\WatchDirection;
use App\Services\Queries\PriceQueries;
use Database\Factories\WatchItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A user's standing interest in one item, optionally narrowed to one state.
 */
class WatchItem extends Model
{
    /** @use HasFactory<WatchItemFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'item_code',
        'state',
        'threshold_price',
        'direction',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold_price' => 'decimal:2',
            'direction' => WatchDirection::class,
            'last_notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_code', 'item_code');
    }

    /** @return HasMany<PriceAlert, $this> */
    public function alerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    /**
     * A watch with no state tracks the national weighted average instead.
     */
    public function isNational(): bool
    {
        return $this->state === null || $this->state === '';
    }

    /**
     * Named locationLabel rather than scopeLabel: Eloquent reserves the "scope"
     * prefix for query scopes, and would route WatchItem::label() here with a
     * Builder argument this method does not accept.
     */
    public function locationLabel(): string
    {
        return $this->isNational() ? 'Malaysia' : $this->state;
    }

    public function isBreachedBy(float $observedPrice): bool
    {
        return $this->direction->isBreached($observedPrice, (float) $this->threshold_price);
    }

    /**
     * Does this user already watch this item at this scope?
     *
     * The unique index covers the state-specific case, but SQL treats every NULL
     * as distinct inside a unique index, so it cannot stop a second *national*
     * watch on the same item. That gap is closed here and covered by a test.
     */
    public static function existsFor(int $userId, int $itemCode, ?string $state): bool
    {
        return static::query()
            ->where('user_id', $userId)
            ->where('item_code', $itemCode)
            ->when(
                $state === null || $state === '',
                fn ($query) => $query->whereNull('state'),
                fn ($query) => $query->where('state', $state),
            )
            ->exists();
    }

    /**
     * The key this watch's price is filed under in PriceQueries::observedPrices().
     */
    public function priceKey(): string
    {
        return PriceQueries::scopeKey(
            $this->item_code,
            $this->isNational() ? null : $this->state,
        );
    }
}
