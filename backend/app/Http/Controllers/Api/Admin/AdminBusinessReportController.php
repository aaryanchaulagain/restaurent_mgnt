<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Services\Auth\AuditLogger;
use App\Services\Reporting\BusinessReportService;
use App\Services\Reporting\BranchReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminBusinessReportController extends Controller
{
    public function __construct(
        private readonly BusinessReportService $reports,
        private readonly BranchReportService $branchReports,
        private readonly AuditLogger $audit,
    ) {}

    public function summary(Request $request, Business $business)
    {
        try {
            $payload = $this->reports->businessSummary(
                $request->user(),
                $business,
                $request->only(['range', 'start', 'end']),
                true,
            );
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        $payload['viewer'] = 'super_admin';
        $payload['unassigned_historical'] = $this->reports->unassignedHistoricalTotals(
            $business,
            $request->only(['range', 'start', 'end']),
        );
        $this->audit->log(
            'admin.business_report.viewed',
            $request->user(),
            $business,
            null,
            null,
            null,
            [
                'business_public_id' => $business->public_id,
                'range' => $payload['meta']['range'] ?? null,
            ],
            $request,
        );

        return ApiResponse::success($payload);
    }

    public function branchSummary(Request $request, Business $business, Branch $branch)
    {
        if ((int) $branch->business_id !== (int) $business->id) {
            return ApiResponse::error('Branch not found.', 404, code: 'REPORT_BRANCH_BUSINESS_MISMATCH');
        }

        try {
            $payload = $this->reports->branchSummary(
                $request->user(),
                $business,
                $branch->loadMissing('restaurant'),
                $request->only(['range', 'start', 'end']),
                true,
            );
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        $payload['viewer'] = 'super_admin';
        $payload['inventory'] = $this->branchReports->inventoryReport($branch);
        $payload['configuration'] = [
            'has_coordinates' => $branch->latitude !== null && $branch->longitude !== null,
            'timezone' => $branch->timezone,
            'linked_restaurant_slug' => $branch->restaurant?->slug,
            'delivery_enabled' => (bool) $branch->restaurant?->restaurant_delivery_enabled,
            'pickup_enabled' => (bool) $branch->restaurant?->pickup_enabled,
        ];

        $this->audit->log(
            'admin.branch_report.viewed',
            $request->user(),
            $branch,
            null,
            null,
            $branch->restaurant_id,
            [
                'business_public_id' => $business->public_id,
                'branch_public_id' => $branch->public_id,
                'range' => $payload['meta']['range'] ?? null,
            ],
            $request,
        );

        return ApiResponse::success($payload);
    }

    private function validationError(ValidationException $e)
    {
        $errors = $e->errors();
        $code = $errors['code'][0] ?? 'REPORT_DATE_RANGE_INVALID';

        return ApiResponse::error(
            collect($errors)->flatten()->first() ?: 'Invalid report request.',
            422,
            $errors,
            code: is_string($code) ? $code : 'REPORT_DATE_RANGE_INVALID',
        );
    }
}
