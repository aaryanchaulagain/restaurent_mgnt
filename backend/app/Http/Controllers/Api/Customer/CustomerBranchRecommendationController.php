<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Location\BranchRecommendationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class CustomerBranchRecommendationController extends Controller
{
    public function __construct(private readonly BranchRecommendationService $recommendations) {}

    public function store(Request $request, string $businessSlug)
    {
        $data = $request->validate([
            'fulfilment' => ['required', Rule::in(['delivery', 'pickup', 'restaurant_delivery'])],
            'address_public_id' => ['nullable', 'uuid'],
            'postcode' => ['nullable', 'string', 'max:12'],
            'city' => ['nullable', 'string', 'max:120'],
            'suburb' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:80'],
            'country' => ['nullable', 'string', 'max:2'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'business_id' => ['prohibited'],
            'branch_id' => ['prohibited'],
            'restaurant_id' => ['prohibited'],
            'customer_id' => ['prohibited'],
            'distance_km' => ['prohibited'],
            'eligible' => ['prohibited'],
        ]);

        try {
            $payload = $this->recommendations->recommend($businessSlug, $data, $request->user());
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $code = $errors['code'][0] ?? null;

            return ApiResponse::error(
                collect($errors)->flatten()->first() ?: 'Unable to recommend branches.',
                422,
                $errors,
                code: is_string($code) ? $code : null,
            );
        } catch (NotFoundHttpException $e) {
            $message = $e->getMessage() ?: 'Not found.';
            $code = str_contains(strtolower($message), 'address') ? 'ADDRESS_NOT_FOUND' : 'BUSINESS_NOT_AVAILABLE';

            return ApiResponse::error($message, 404, code: $code);
        } catch (AccessDeniedHttpException $e) {
            return ApiResponse::error($e->getMessage() ?: 'Forbidden.', 403, code: 'ADDRESS_ACCESS_DENIED');
        }

        return ApiResponse::success($payload);
    }
}
