<?php

namespace App\Services\Operations;

use App\Support\StripeKeyMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Read-only payment/webhook configuration check. Never prints secrets or calls Stripe APIs.
 */
class PaymentsOperationalCheckService
{
    /**
     * @return array{status: string, exit_code: int, report: array<string, mixed>}
     */
    public function evaluate(): array
    {
        $driver = (string) config('payments.driver', 'stripe');
        $secret = filled(config('payments.stripe.secret_key'));
        $pub = filled(config('payments.stripe.publishable_key'));
        $wh = filled(config('payments.stripe.webhook_secret'));
        $webhookRoute = collect(Route::getRoutes())->contains(
            fn ($r) => str_contains($r->uri(), 'webhooks/stripe')
        );

        $failedWebhooks = 0;
        $oldestUnprocessed = null;
        try {
            if (Schema::hasTable('payment_webhook_events')) {
                $failedWebhooks = (int) DB::table('payment_webhook_events')
                    ->whereIn('processing_status', ['failed', 'received', 'processing'])
                    ->where(function ($q) {
                        $q->whereNull('processed_at')
                            ->orWhere('processing_status', 'failed');
                    })
                    ->count();
                $oldestUnprocessed = DB::table('payment_webhook_events')
                    ->whereNull('processed_at')
                    ->where('processing_status', '!=', 'ignored')
                    ->orderBy('created_at')
                    ->value('created_at');
                $oldestUnprocessed = $oldestUnprocessed ? (string) $oldestUnprocessed : null;
            }
        } catch (Throwable) {
            // ignore schema differences
        }

        $fails = [];
        $warnings = [];

        if (! $secret && ! $pub) {
            $warnings[] = 'Stripe keys not configured — card payments unavailable.';
        } else {
            if (! $secret) {
                $fails[] = 'STRIPE_SECRET_KEY missing';
            }
            if (! $pub) {
                $warnings[] = 'STRIPE_PUBLISHABLE_KEY missing';
            }
            if ($secret && ! $wh) {
                $fails[] = 'STRIPE_WEBHOOK_SECRET required when Stripe payments are enabled';
            }
        }

        if (! $webhookRoute) {
            $fails[] = 'Stripe webhook route not registered';
        }

        $secretValue = is_string(config('payments.stripe.secret_key')) ? config('payments.stripe.secret_key') : null;
        $pubValue = is_string(config('payments.stripe.publishable_key')) ? config('payments.stripe.publishable_key') : null;
        $mode = StripeKeyMode::compare($secretValue, $pubValue);
        if (! $mode['consistent']) {
            $fails[] = $mode['message'];
        }

        $report = [
            'payment_driver' => $driver,
            'methods_enabled' => $secret ? ['card_via_stripe'] : [],
            'stripe_secret_configured' => $secret,
            'stripe_publishable_configured' => $pub,
            'webhook_secret_configured' => $wh,
            'webhook_route_registered' => $webhookRoute,
            'signature_verification' => $wh ? 'configured' : 'missing_secret',
            'stripe_mode_consistent' => $mode['consistent'],
            'stripe_secret_mode' => $mode['secret_mode'],
            'stripe_publishable_mode' => $mode['publishable_mode'],
            'failed_or_unprocessed_webhook_count' => $failedWebhooks,
            'oldest_unprocessed_at' => $oldestUnprocessed,
            'live_api_calls' => false,
            'notes' => [
                'This check never prints secret keys or webhook secrets.',
                'This check does not create charges or call Stripe by default.',
                'Webhook security relies on signature verification and idempotent event processing.',
                'Mixed test/live Stripe keys are rejected.',
            ],
            'warnings' => $warnings,
            'failures' => $fails,
        ];

        $status = $fails !== [] ? 'FAIL' : ($warnings !== [] ? 'WARN' : 'PASS');

        return [
            'status' => $status,
            'exit_code' => $fails !== [] ? 1 : 0,
            'report' => $report,
        ];
    }
}
