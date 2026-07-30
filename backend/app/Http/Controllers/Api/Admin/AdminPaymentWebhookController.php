<?php

namespace App\Http\Controllers\Api\Admin;

use App\Domain\Payments\Services\StripeWebhookService;
use App\Http\Controllers\Controller;
use App\Models\PaymentWebhookEvent;
use App\Services\Auth\AuditLogger;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminPaymentWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookService $webhooks,
        private readonly AuditLogger $audit,
    ) {}

    public function retry(Request $request, string $eventPublicId)
    {
        $event = PaymentWebhookEvent::query()
            ->where('public_id', $eventPublicId)
            ->firstOrFail();

        $result = $this->webhooks->retry($event);

        $this->audit->log(
            action: 'payment.webhook_retried',
            actor: $request->user(),
            auditable: $result,
            newValues: [
                'event_public_id' => $result->public_id,
                'processing_status' => $result->processing_status,
                'processing_attempts' => $result->processing_attempts,
            ],
        );

        return ApiResponse::success([
            'event' => [
                'public_id' => $result->public_id,
                'event_type' => $result->event_type,
                'processing_status' => $result->processing_status,
                'processing_attempts' => $result->processing_attempts,
                'last_error' => $result->last_error,
                'processed_at' => $result->processed_at?->toIso8601String(),
            ],
        ]);
    }
}
