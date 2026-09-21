<?php

namespace App\Models;

use Database\Factories\PremiseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Premise extends Model
{
    /** @use HasFactory<PremiseFactory> */
    use HasFactory;

    protected $primaryKey = 'premise_code';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'premise_code',
        'premise',
        'address',
        'premise_type',
        'state',
        'district',
    ];

    /** @return HasMany<PriceRecord, $this> */
    public function priceRecords(): HasMany
    {
        return $this->hasMany(PriceRecord::class, 'premise_code', 'premise_code');
    }
}
