<?php

namespace App\Services\Alerts;

final readonly class WatchEvaluationResult
{
    public function __construct(
        public int $evaluated = 0,
        public int $withoutData = 0,
        public int $breached = 0,
        public int $notified = 0,
    ) {}

    /**
     * Breaches that were already recorded on an earlier pass, and so did not
     * produce a second notification.
     */
    public function alreadyNotified(): int
    {
        return max(0, $this->breached - $this->notified);
    }
}
