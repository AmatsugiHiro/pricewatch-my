<?php

namespace App\Notifications;

use App\Models\PriceAlert;
use App\Models\WatchItem;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Queued, so that evaluating a large watchlist is not held up by the mail
 * transport. Note that this requires a queue worker: with the default
 * QUEUE_CONNECTION=database, `php artisan queue:work` must be running, or these
 * will sit in the jobs table unsent.
 */
class PriceAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly PriceAlert $alert,
        public readonly WatchItem $watch,
        public readonly float $observedPrice,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $item = $this->watch->item->displayName();
        $where = $this->watch->locationLabel();
        $price = 'RM '.number_format($this->observedPrice, 2);
        $threshold = 'RM '.number_format((float) $this->watch->threshold_price, 2);

        return (new MailMessage)
            ->subject("{$item} is now {$price} in {$where}")
            ->greeting('Price alert')
            ->line("**{$item}** ({$this->watch->item->unit}) in **{$where}** is now **{$price}**.")
            ->line("You asked to be told when it {$this->watch->direction->label()} {$threshold}.")
            ->action('View price history', route('items.show', [
                'itemCode' => $this->watch->item_code,
                'state' => $this->watch->state ?? '',
            ]))
            ->line('Observed on '.$this->alert->observed_on->format('j M Y').'.')
            ->salutation('— PriceWatch MY');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'watch_item_id' => $this->watch->id,
            'item_code' => $this->watch->item_code,
            'state' => $this->watch->state,
            'observed_price' => $this->observedPrice,
            'threshold_price' => (float) $this->watch->threshold_price,
        ];
    }
}
