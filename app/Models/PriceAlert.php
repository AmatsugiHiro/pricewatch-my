<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\PriceAlertFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A recorded threshold breach. One row per watch per observed day, enforced by a
 * unique index so that re-running aggregation cannot notify a user twice.
 */
class PriceAlert extends Model
{
    /** @use HasFactory<PriceAlertFactory> */
    use HasFactory;

    protected $fillable = [
        'watch_item_id',
        'observed_on',
        'observed_price',
        'threshold_price',
        'notified_at',
    ];

    protected function casts(): array
    {
        return [
            'observed_on' => DateOnly::class,
            'observed_price' => 'decimal:4',
            'threshold_price' => 'decimal:2',
            'notified_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WatchItem, $this> */
    public function watchItem(): BelongsTo
    {
        return $this->belongsTo(WatchItem::class);
    }
}
