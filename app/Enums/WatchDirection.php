<?php

namespace App\Enums;

enum WatchDirection: string
{
    /** Notify when the tracked price falls to or below the threshold. */
    case Below = 'below';

    /** Notify when the tracked price rises to or above the threshold. */
    case Above = 'above';

    public function label(): string
    {
        return match ($this) {
            self::Below => 'drops to or below',
            self::Above => 'rises to or above',
        };
    }

    /**
     * Does an observed price cross this threshold?
     */
    public function isBreached(float $observed, float $threshold): bool
    {
        return match ($this) {
            self::Below => $observed <= $threshold,
            self::Above => $observed >= $threshold,
        };
    }
}
