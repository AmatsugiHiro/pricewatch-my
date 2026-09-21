<?php

namespace App\Models;

use Database\Factories\ItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    /** @use HasFactory<ItemFactory> */
    use HasFactory;

    protected $primaryKey = 'item_code';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'item_code',
        'item',
        'unit',
        'item_group',
        'item_category',
    ];

    /** @return HasMany<PriceRecord, $this> */
    public function priceRecords(): HasMany
    {
        return $this->hasMany(PriceRecord::class, 'item_code', 'item_code');
    }

    /** @return HasMany<DailyItemStatePrice, $this> */
    public function dailyPrices(): HasMany
    {
        return $this->hasMany(DailyItemStatePrice::class, 'item_code', 'item_code');
    }

    /** @return HasMany<WatchItem, $this> */
    public function watchItems(): HasMany
    {
        return $this->hasMany(WatchItem::class, 'item_code', 'item_code');
    }

    /**
     * "AYAM BERSIH - STANDARD" is how the source publishes names. Title-casing it
     * keeps the UI readable without mutating the stored source value.
     */
    public function displayName(): string
    {
        return ucwords(mb_strtolower($this->item), ' -/()');
    }
}
