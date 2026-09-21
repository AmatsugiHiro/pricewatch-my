<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A calendar date, stored as exactly 'Y-m-d' on every driver.
 *
 * Laravel's built-in 'date' cast writes through Model::fromDateTime(), which uses
 * the *connection's* datetime format. On MySQL that lands in a DATE column and is
 * truncated to 'Y-m-d' regardless; on SQLite, whose typing is dynamic, the literal
 * '2026-08-30 00:00:00' is stored instead. Every `where('date', '2026-08-30')`
 * then silently matches nothing under SQLite while working perfectly under MySQL —
 * which is exactly the kind of divergence that makes a test suite lie about
 * production.
 *
 * Pinning the stored representation here removes the divergence at its source,
 * rather than scattering whereDate() through the query layer (which would also
 * stop MySQL using the indexes these tables are built around).
 *
 * @implements CastsAttributes<CarbonImmutable, \DateTimeInterface|string>
 */
final class DateOnly implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse($value)->startOfDay();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : CarbonImmutable::parse($value)->toDateString();
    }
}
