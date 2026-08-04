<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class HealthLiveController extends Controller
{
    /** Process liveness — no dependency checks. */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
        ])->header('X-Request-Id', (string) request()->attributes->get('request_id', ''));
    }
}
