<?php

namespace App\Models;

use App\Casts\DateOnly;
use Database\Factories\PriceRecordFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One observed price for one item at one premise on one day.
 *
 * This model exists for reads and tests. The ingestion pipeline deliberately does
 * NOT use it for writes: hydrating 1.6 million Eloquent models per month would be
 * orders of magnitude slower than the chunked query-builder upsert in
 * App\Services\Ingestion\PriceCatcherImporter.
 */
class PriceRecord extends Model
{
    /** @use HasFactory<PriceRecordFactory> */
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'premise_code',
        'item_code',
        'price',
    ];

    protected function casts(): array
    {
        return [
            'date' => DateOnly::class,
            'price' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_code', 'item_code');
    }

    /** @return BelongsTo<Premise, $this> */
    public function premise(): BelongsTo
    {
        return $this->belongsTo(Premise::class, 'premise_code', 'premise_code');
    }
}
