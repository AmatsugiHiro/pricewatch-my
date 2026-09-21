<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\DailyItemStatePriceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A pre-computed daily rollup of prices for one item in one state.
 */
class DailyItemStatePrice extends Model
{
    /** @use HasFactory<DailyItemStatePriceFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'item_code',
        'state',
        'min_price',
        'max_price',
        'avg_price',
        'sample_count',
    ];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'min_price' => 'decimal:2',
            'max_price' => 'decimal:2',
            'avg_price' => 'decimal:4',
            'sample_count' => 'integer',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_code', 'item_code');
    }

    /** @param Builder<$this> $query */
    public function scopeForItem(Builder $query, int $itemCode): void
    {
        $query->where('item_code', $itemCode);
    }

    /** @param Builder<$this> $query */
    public function scopeInState(Builder $query, ?string $state): void
    {
        if ($state !== null && $state !== '') {
            $query->where('state', $state);
        }
    }
}
