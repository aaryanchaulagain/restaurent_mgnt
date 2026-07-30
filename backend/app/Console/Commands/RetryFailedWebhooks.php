<?php

namespace App\Console\Commands;

use App\Domain\Payments\Enums\WebhookProcessingStatus;
use App\Domain\Payments\Services\StripeWebhookService;
use App\Models\PaymentWebhookEvent;
use Illuminate\Console\Command;
use Throwable;

class RetryFailedWebhooks extends Command
{
    protected $signature = 'payments:retry-webhooks';

    protected $description = 'Retry failed Stripe webhook events that are within the retry budget.';

    public function handle(StripeWebhookService $webhooks): int
    {
        $maxAttempts = (int) config('payments.webhook_max_retries', 10);

        $events = PaymentWebhookEvent::query()
            ->where('processing_status', WebhookProcessingStatus::Failed->value)
            ->where('processing_attempts', '<', $maxAttempts)
            ->orderBy('id')
            ->limit(50)
            ->get();

        $count = 0;
        foreach ($events as $event) {
            try {
                $webhooks->retry($event);
                $count++;
            } catch (Throwable $e) {
                $this->warn("Failed to retry webhook {$event->public_id}: {$e->getMessage()}");
            }
        }

        $this->info("Retried {$count} webhook events.");

        return self::SUCCESS;
    }
}
