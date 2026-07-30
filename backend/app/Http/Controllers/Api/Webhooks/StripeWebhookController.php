<?php

namespace App\Http\Controllers\Api\Webhooks;

use App\Domain\Payments\Services\StripeWebhookService;
use App\Http\Controllers\Controller;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class StripeWebhookController extends Controller
{
    public function __construct(
        private readonly StripeWebhookService $webhooks,
    ) {}

    public function __invoke(Request $request)
    {
        $payload = $request->getContent();
        $signature = (string) $request->header('Stripe-Signature', '');

        $event = $this->webhooks->handle($payload, $signature);

        return ApiResponse::success([
            'event_public_id' => $event->public_id,
            'processing_status' => $event->processing_status,
        ]);
    }
}
