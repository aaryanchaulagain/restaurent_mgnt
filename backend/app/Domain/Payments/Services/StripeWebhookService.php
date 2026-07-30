<?php

namespace App\Domain\Payments\Services;

use App\Domain\Payments\Enums\WebhookProcessingStatus;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Models\PaymentWebhookEvent;
use App\Support\PaymentErrorResponse;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Throwable;
use UnexpectedValueException;

class StripeWebhookService
{
    public function __construct(
        private readonly StripeEventProcessor $processor,
    ) {}

    public function handle(string $payload, string $signatureHeader): PaymentWebhookEvent
    {
        $secret = config('payments.stripe.webhook_secret');
        if (! is_string($secret) || $secret === '') {
            throw new PaymentException(
                'PAYMENT_CONFIGURATION_MISSING',
                PaymentErrorResponse::messageForCode('PAYMENT_CONFIGURATION_MISSING'),
                503,
            );
        }

        try {
            /** @var Event $event */
            $event = \Stripe\Webhook::constructEvent($payload, $signatureHeader, $secret);
        } catch (UnexpectedValueException|SignatureVerificationException $e) {
            Log::warning('stripe.webhook.invalid_signature', [
                'error' => $e->getMessage(),
            ]);

            throw new PaymentException(
                'INVALID_WEBHOOK_SIGNATURE',
                PaymentErrorResponse::messageForCode('INVALID_WEBHOOK_SIGNATURE'),
                400,
            );
        }

        $webhookEvent = $this->persistReceipt($event);

        if (in_array($webhookEvent->processing_status, [
            WebhookProcessingStatus::Processed->value,
            WebhookProcessingStatus::Ignored->value,
            WebhookProcessingStatus::Processing->value,
        ], true)) {
            return $webhookEvent;
        }

        return $this->processStoredEvent($webhookEvent, $event);
    }

    public function retry(PaymentWebhookEvent $webhookEvent): PaymentWebhookEvent
    {
        $webhookEvent = PaymentWebhookEvent::query()->findOrFail($webhookEvent->id);

        if (in_array($webhookEvent->processing_status, [
            WebhookProcessingStatus::Processed->value,
            WebhookProcessingStatus::Ignored->value,
        ], true)) {
            return $webhookEvent;
        }

        $event = Event::constructFrom([
            'id' => $webhookEvent->external_event_id,
            'type' => $webhookEvent->event_type,
            'livemode' => $webhookEvent->livemode,
            'api_version' => $webhookEvent->api_version,
            'data' => [
                'object' => $webhookEvent->sanitized_payload['object'] ?? [],
            ],
        ]);

        return $this->processStoredEvent($webhookEvent, $event);
    }

    private function persistReceipt(Event $event): PaymentWebhookEvent
    {
        try {
            return DB::transaction(function () use ($event) {
                $existing = PaymentWebhookEvent::query()
                    ->where('provider', 'stripe')
                    ->where('external_event_id', $event->id)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return $existing;
                }

                return PaymentWebhookEvent::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'provider' => 'stripe',
                    'external_event_id' => $event->id,
                    'event_type' => $event->type,
                    'payload_hash' => hash('sha256', $event->id.'|'.$event->type.'|'.($event->created ?? '')),
                    'livemode' => (bool) ($event->livemode ?? false),
                    'api_version' => $event->api_version ?? null,
                    'processing_status' => WebhookProcessingStatus::Received->value,
                    'processing_attempts' => 0,
                    'received_at' => now(),
                    'sanitized_payload' => $this->sanitizePayload($event),
                ]);
            });
        } catch (QueryException $e) {
            $existing = PaymentWebhookEvent::query()
                ->where('provider', 'stripe')
                ->where('external_event_id', $event->id)
                ->first();

            if ($existing) {
                return $existing;
            }

            throw $e;
        }
    }

    private function processStoredEvent(PaymentWebhookEvent $webhookEvent, Event $event): PaymentWebhookEvent
    {
        $webhookEvent->fill([
            'processing_status' => WebhookProcessingStatus::Processing->value,
            'processing_attempts' => (int) $webhookEvent->processing_attempts + 1,
            'last_error' => null,
        ])->save();

        try {
            $this->processor->process($event, $webhookEvent);

            $webhookEvent->refresh();

            if ($webhookEvent->processing_status === WebhookProcessingStatus::Processing->value) {
                $webhookEvent->fill([
                    'processing_status' => WebhookProcessingStatus::Processed->value,
                    'processed_at' => now(),
                    'failed_at' => null,
                ])->save();
            }

            return $webhookEvent->fresh();
        } catch (Throwable $e) {
            Log::error('stripe.webhook.processing_failed', [
                'event_id' => $webhookEvent->external_event_id,
                'event_type' => $webhookEvent->event_type,
                'error' => $e->getMessage(),
            ]);

            $webhookEvent->fill([
                'processing_status' => WebhookProcessingStatus::Failed->value,
                'failed_at' => now(),
                'last_error' => mb_substr($e->getMessage(), 0, 1000),
            ])->save();

            if ($e instanceof PaymentException) {
                throw $e;
            }

            throw new PaymentException(
                'WEBHOOK_PROCESSING_FAILED',
                PaymentErrorResponse::messageForCode('WEBHOOK_PROCESSING_FAILED'),
                500,
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizePayload(Event $event): array
    {
        $raw = $event->toArray();
        $object = $raw['data']['object'] ?? [];
        if (! is_array($object)) {
            $object = [];
        }

        return [
            'id' => $event->id,
            'type' => $event->type,
            'created' => $event->created ?? null,
            'object' => $this->stripSecrets($object),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function stripSecrets(array $data): array
    {
        $blocked = [
            'client_secret',
            'secret',
            'password',
            'number',
            'cvc',
            'routing_number',
            'account_number',
        ];

        foreach ($data as $key => $value) {
            if (in_array(strtolower((string) $key), $blocked, true)) {
                unset($data[$key]);
                continue;
            }
            if (is_array($value)) {
                $data[$key] = $this->stripSecrets($value);
            }
        }

        return $data;
    }
}
