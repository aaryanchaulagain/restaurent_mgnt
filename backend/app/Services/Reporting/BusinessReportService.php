<?php

namespace App\Services\Reporting;

use App\Models\Branch;
use App\Models\Business;
use App\Models\User;
use App\Services\Branch\BranchPermissionService;
use App\Support\BranchStatuses;
use App\Support\BusinessTypes;
use Illuminate\Support\Collection;

class BusinessReportService
{
    public function __construct(
        private readonly BranchReportService $branchReports,
        private readonly ReportingTimezoneResolver $timezones,
        private readonly BranchPermissionService $permissions,
    ) {}

    /**
     * @param  array{range?: string, start?: string, end?: string}  $rangeInput
     * @return array<string, mixed>
     */
    public function businessSummary(User $user, Business $business, array $rangeInput, bool $includeFinance): array
    {
        $timezone = $this->timezones->forBusiness($business);
        $range = ReportDateRange::fromRequest($rangeInput, $timezone);
        $branches = $this->authorizedBranchesForBusiness($user, $business);

        $aggregate = $this->branchReports->summarizeBranches($branches, $range, $includeFinance);

        return [
            'meta' => $range->meta(),
            'business' => [
                'public_id' => $business->public_id,
                'name' => $business->name,
                'business_type' => BusinessTypes::normalize($business->business_type),
            ],
            'branch_counts' => [
                'total' => $branches->count(),
                'active' => $branches->where('status', BranchStatuses::ACTIVE)->count(),
                'temporarily_closed' => $branches->where('status', BranchStatuses::PAUSED)->count(),
            ],
            'summary' => $aggregate['summary'],
            'branches' => $aggregate['branches'],
            'order_status_breakdown' => $aggregate['order_status_breakdown'],
            'fulfilment_breakdown' => $aggregate['fulfilment_breakdown'],
            'payment_breakdown' => $includeFinance ? $aggregate['payment_breakdown'] : null,
        ];
    }

    /**
     * @param  array{range?: string, start?: string, end?: string}  $rangeInput
     * @return array<string, mixed>
     */
    public function branchSummary(User $user, Business $business, Branch $branch, array $rangeInput, bool $includeFinance): array
    {
        if ((int) $branch->business_id !== (int) $business->id) {
            abort(404, 'Branch not found.');
        }

        $timezone = $this->timezones->forBranch($branch);
        $range = ReportDateRange::fromRequest($rangeInput, $timezone);
        $aggregate = $this->branchReports->summarizeBranches(collect([$branch]), $range, $includeFinance);
        $branchRow = $aggregate['branches'][0] ?? null;

        return [
            'meta' => $range->meta(),
            'business' => [
                'public_id' => $business->public_id,
                'name' => $business->name,
                'business_type' => BusinessTypes::normalize($business->business_type),
            ],
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'status' => $branch->status,
                'accepting_orders' => (bool) $branch->accepting_orders,
                'restaurant_slug' => $branch->restaurant?->slug,
            ],
            'summary' => $aggregate['summary'],
            'metrics' => $branchRow,
            'order_status_breakdown' => $aggregate['order_status_breakdown'],
            'fulfilment_breakdown' => $aggregate['fulfilment_breakdown'],
            'payment_breakdown' => $includeFinance ? $aggregate['payment_breakdown'] : null,
        ];
    }

    /** @return Collection<int, Branch> */
    public function authorizedBranchesForBusiness(User $user, Business $business): Collection
    {
        $query = Branch::query()
            ->where('business_id', $business->id)
            ->with(['restaurant' => fn ($q) => $q->withTrashed()])
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! $user->isSuperAdmin()) {
            $allowed = $this->permissions->authorizedBranchIds($user);
            $query->whereIn('id', $allowed);
        }

        return $query->get()->filter(fn (Branch $b) => $b->restaurant_id !== null)->values();
    }

    /**
     * Soft-deleted restaurants remain attributable via restaurant_id in branch aggregates.
     * Physically missing restaurants cannot be attributed to a business without snapshot evidence.
     *
     * @param  array{range?: string, start?: string, end?: string}  $rangeInput
     * @return array{unassigned_historical_orders: int, unassigned_gross_order_value_cents: int, unassigned_paid_amount_cents: int, note: string}
     */
    public function unassignedHistoricalTotals(Business $business, array $rangeInput): array
    {
        // Validate range for consistent API behaviour; values are not attributed without evidence.
        $timezone = $this->timezones->forBusiness($business);
        ReportDateRange::fromRequest($rangeInput, $timezone);

        return [
            'unassigned_historical_orders' => 0,
            'unassigned_gross_order_value_cents' => 0,
            'unassigned_paid_amount_cents' => 0,
            'note' => 'Physically missing restaurants cannot be attributed to a business without snapshot evidence. Soft-deleted partner orders remain in branch aggregates via restaurant_id.',
        ];
    }
}
