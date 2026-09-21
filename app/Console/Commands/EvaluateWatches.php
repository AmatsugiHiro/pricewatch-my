<?php

namespace App\Console\Commands;

use App\Services\Alerts\WatchEvaluator;
use Illuminate\Console\Command;

class EvaluateWatches extends Command
{
    protected $signature = 'pricewatch:alerts
        {--date= : Evaluate against a specific day (YYYY-MM-DD) instead of the latest}';

    protected $description = 'Check every watchlist entry against the latest prices and notify on new breaches';

    public function handle(WatchEvaluator $evaluator): int
    {
        $date = $this->option('date');

        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $this->components->error("Date must be formatted YYYY-MM-DD, got '{$date}'.");

            return self::FAILURE;
        }

        $result = $evaluator->evaluate(is_string($date) && $date !== '' ? $date : null);

        $this->components->twoColumnDetail('Watches evaluated', number_format($result->evaluated));
        $this->components->twoColumnDetail('No price for that scope', number_format($result->withoutData));
        $this->components->twoColumnDetail('Thresholds breached', number_format($result->breached));
        $this->components->twoColumnDetail('Notifications sent', number_format($result->notified));
        $this->components->twoColumnDetail('Already alerted today', number_format($result->alreadyNotified()));

        if ($result->notified > 0 && config('queue.default') !== 'sync') {
            $this->newLine();
            $this->components->warn(sprintf(
                'Notifications are queued on the "%s" connection. Run `php artisan queue:work` or they will not be delivered.',
                config('queue.default'),
            ));
        }

        return self::SUCCESS;
    }
}
