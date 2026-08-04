<?php

namespace App\Http\Controllers\Api\Business;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Services\Branch\BranchPermissionService;
use App\Services\Reporting\BusinessReportService;
use App\Services\Reporting\BranchReportService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BusinessReportController extends Controller
{
    public function __construct(
        private readonly BusinessReportService $reports,
        private readonly BranchReportService $branchReports,
        private readonly BranchPermissionService $branchPermissions,
    ) {}

    public function summary(Request $request, Business $business)
    {
        $this->authorize('viewReports', $business);
        $includeFinance = $request->user()->can('viewFinance', $business);

        try {
            $payload = $this->reports->businessSummary(
                $request->user(),
                $business,
                $request->only(['range', 'start', 'end']),
                $includeFinance,
            );
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        return ApiResponse::success($payload);
    }

    public function branches(Request $request, Business $business)
    {
        return $this->summary($request, $business);
    }

    public function branchSummary(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('viewReports', $branch);

        $includeFinance = $this->canViewBranchFinance($request->user(), $business, $branch);

        try {
            $payload = $this->reports->branchSummary(
                $request->user(),
                $business,
                $branch->loadMissing('restaurant'),
                $request->only(['range', 'start', 'end']),
                $includeFinance,
            );
        } catch (ValidationException $e) {
            return $this->validationError($e);
        }

        return ApiResponse::success($payload);
    }

    public function branchInventory(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('viewReports', $branch);

        return ApiResponse::success($this->branchReports->inventoryReport($branch));
    }

    public function branchOrders(Request $request, Business $business, Branch $branch)
    {
        // Order detail lists remain on existing restaurant order APIs.
        return $this->branchSummary($request, $business, $branch);
    }

    public function branchPayments(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('viewReports', $branch);
        if (! $this->canViewBranchFinance($request->user(), $business, $branch)) {
            return ApiResponse::error(
                'You do not have permission to view finance reports.',
                403,
                code: 'REPORT_FINANCE_PERMISSION_DENIED',
            );
        }

        return $this->branchSummary($request, $business, $branch);
    }

    private function canViewBranchFinance(User $user, Business $business, Branch $branch): bool
    {
        if ($user->can('viewFinance', $business)) {
            return true;
        }

        return $this->branchPermissions->allows($user, $branch, 'view_branch_finance_summary')
            || $this->branchPermissions->allows($user, $branch, 'view_payment_reports');
    }

    private function assertBranchBelongs(Business $business, Branch $branch): void
    {
        if ((int) $branch->business_id !== (int) $business->id) {
            abort(404, 'Branch not found.');
        }
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
