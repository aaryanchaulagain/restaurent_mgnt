<?php

namespace App\Http\Controllers\Api\Business;

use App\Exceptions\BranchInvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CreateBranchRequest;
use App\Http\Requests\Branch\UpdateBranchRequest;
use App\Http\Resources\Business\BranchInvitationResource;
use App\Http\Resources\Business\BranchResource;
use App\Http\Resources\Business\BusinessResource;
use App\Models\Branch;
use App\Models\BranchUser;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Services\Branch\BranchInvitationService;
use App\Services\Branch\BranchPermissionService;
use App\Services\Branch\BranchProvisionService;
use App\Services\Branch\BranchStaffService;
use App\Services\Branch\BranchStatusService;
use App\Support\ApiResponse;
use App\Support\BusinessRoles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class BusinessBranchController extends Controller
{
    public function __construct(
        private readonly BranchProvisionService $provision,
        private readonly BranchStatusService $statuses,
        private readonly BranchStaffService $staff,
        private readonly BranchInvitationService $invitations,
        private readonly BranchPermissionService $permissions,
    ) {}

    public function listBusinesses(Request $request)
    {
        $user = $request->user();
        $query = Business::query()->withCount('branches')->orderBy('name');

        if (! $user->isSuperAdmin()) {
            $ids = BusinessUser::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('business_id');
            $query->whereIn('id', $ids);
        }

        return ApiResponse::success([
            'businesses' => BusinessResource::collection($query->get()),
        ]);
    }

    public function showBusiness(Request $request, Business $business)
    {
        $this->authorize('view', $business);
        $business->loadCount('branches');

        return ApiResponse::success([
            'business' => new BusinessResource($business),
        ]);
    }

    public function context(Request $request)
    {
        $user = $request->user();
        $businesses = $this->accessibleBusinesses($user);

        $branches = Branch::query()
            ->with(['business', 'restaurant'])
            ->withCount('branchUsers')
            ->whereIn('business_id', $businesses->pluck('id'))
            ->when(! $user->isSuperAdmin() && ! $this->isBusinessManagerAnywhere($user), function ($q) use ($user) {
                $q->whereIn('id', BranchUser::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->pluck('branch_id'));
            })
            ->orderBy('business_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(function (Branch $branch) {
                $branch->manager_count = BranchUser::query()
                    ->where('branch_id', $branch->id)
                    ->where('status', 'active')
                    ->where('role', BusinessRoles::BRANCH_MANAGER)
                    ->count();

                return $branch;
            });

        $canAggregate = $user->isSuperAdmin() || $this->isBusinessManagerAnywhere($user);

        $authorization = null;
        $branchHeader = $request->header('X-Branch-Id');
        if ($branchHeader) {
            $activeBranch = $branches->firstWhere('public_id', $branchHeader)
                ?? Branch::query()->where('public_id', $branchHeader)->first();
            if ($activeBranch && ($user->isSuperAdmin() || $user->canAccessBranch($activeBranch->id))) {
                $authorization = $this->authorizationPayload($user, $activeBranch);
            }
        } elseif ($branches->count() === 1) {
            $authorization = $this->authorizationPayload($user, $branches->first());
        }

        return ApiResponse::success([
            'can_aggregate' => $canAggregate,
            'businesses' => BusinessResource::collection($businesses),
            'branches' => BranchResource::collection($branches),
            'authorization' => $authorization,
        ]);
    }

    public function index(Request $request, Business $business)
    {
        $this->authorize('view', $business);
        $user = $request->user();

        $query = Branch::query()
            ->where('business_id', $business->id)
            ->with(['business', 'restaurant'])
            ->withCount('branchUsers')
            ->orderBy('sort_order')
            ->orderBy('name');

        if (! $user->isSuperAdmin() && ! $this->isBusinessManager($user, $business)) {
            $query->whereIn('id', BranchUser::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('branch_id'));
        }

        $branches = $query->get()->map(function (Branch $branch) {
            $branch->manager_count = BranchUser::query()
                ->where('branch_id', $branch->id)
                ->where('status', 'active')
                ->where('role', BusinessRoles::BRANCH_MANAGER)
                ->count();

            return $branch;
        });

        return ApiResponse::success([
            'business' => new BusinessResource($business),
            'branches' => BranchResource::collection($branches),
            'counts' => [
                'total' => $branches->count(),
                'active' => $branches->where('status', 'active')->count(),
                'paused' => $branches->where('status', 'paused')->count(),
                'inactive' => $branches->where('status', 'inactive')->count(),
                'suspended' => $branches->where('status', 'suspended')->count(),
                'draft' => $branches->where('status', 'draft')->count(),
            ],
        ]);
    }

    public function store(CreateBranchRequest $request, Business $business)
    {
        $this->authorize('manageBranches', $business);
        $validated = $request->validated();
        $inviteManager = (bool) ($validated['invite_manager'] ?? false);

        try {
            $payload = DB::transaction(function () use ($business, $validated, $inviteManager, $request) {
                $result = $this->provision->create($business, $validated, $request->user(), $request);
                $invitationPayload = null;

                if ($inviteManager) {
                    $created = $this->invitations->create($result['branch'], [
                        'email' => $validated['manager_email'],
                        'full_name' => $validated['manager_full_name'] ?? null,
                        'phone' => $validated['manager_phone'] ?? null,
                        'role' => $validated['manager_role'] ?? BusinessRoles::BRANCH_MANAGER,
                    ], $request->user(), $request);

                    $this->invitations->dispatchNotification(
                        $created['invitation'],
                        $created['plain_token'],
                    );

                    $invitationPayload = $created['invitation']->fresh(['invitedBy']);
                }

                return [
                    'branch' => $result['branch'],
                    'restaurant' => $result['restaurant'],
                    'invitation' => $invitationPayload,
                ];
            });
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        return ApiResponse::success([
            'branch' => new BranchResource($payload['branch']->load(['business', 'restaurant'])),
            'restaurant_public_id' => $payload['restaurant']->public_id,
            'invitation' => $payload['invitation']
                ? new BranchInvitationResource($payload['invitation'])
                : null,
        ], message: $payload['invitation']
            ? 'Branch created. Manager invitation sent.'
            : 'Branch created. Configure menus, hours, delivery, and payments before activating.', status: 201);
    }

    public function show(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('view', $branch);
        $branch->load(['business', 'restaurant'])->loadCount('branchUsers');
        $branch->manager_count = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->where('role', BusinessRoles::BRANCH_MANAGER)
            ->count();

        return ApiResponse::success([
            'branch' => new BranchResource($branch),
        ]);
    }

    public function update(UpdateBranchRequest $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('update', $branch);
        $updated = $this->provision->update($branch, $request->validated(), $request->user(), $request);

        return ApiResponse::success([
            'branch' => new BranchResource($updated->load(['business', 'restaurant'])),
        ], message: 'Branch updated.');
    }

    public function pause(Request $request, Business $business, Branch $branch)
    {
        return $this->statusAction($request, $business, $branch, 'pause');
    }

    public function activate(Request $request, Business $business, Branch $branch)
    {
        return $this->statusAction($request, $business, $branch, 'activate');
    }

    public function deactivate(Request $request, Business $business, Branch $branch)
    {
        return $this->statusAction($request, $business, $branch, 'deactivate');
    }

    public function businessUsers(Request $request, Business $business)
    {
        $this->authorize('manageStaff', $business);
        $users = BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('status', 'active')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (BusinessUser $row) => [
                'user_id' => $row->user_id,
                'email' => $row->user?->email,
                'name' => $row->user?->name,
                'role' => $row->role,
                'joined_at' => $row->joined_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['users' => $users]);
    }

    public function storeBusinessUser(Request $request, Business $business)
    {
        $this->authorize('manageStaff', $business);
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(BusinessRoles::businessAssignable())],
        ]);

        try {
            $result = $this->staff->assignBusinessUser($business, $data, $request->user(), $request);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Unable to assign staff.',
                422,
                $e->errors(),
                code: 'BRANCH_INVITATION_REQUIRED',
            );
        }

        return ApiResponse::success([
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
                'name' => $result['user']->name,
                'role' => $result['assignment']->role,
            ],
        ], message: 'Existing user assigned. New users must be invited.', status: 201);
    }

    public function destroyBusinessUser(Request $request, Business $business, int $userId)
    {
        $this->authorize('manageStaff', $business);
        $user = \App\Models\User::query()->findOrFail($userId);
        try {
            $this->staff->removeBusinessUser($business, $user, $request->user(), $request);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                $e->getMessage(),
                422,
                $e->errors(),
                code: 'LAST_BUSINESS_OWNER_REQUIRED',
            );
        }

        return ApiResponse::success(message: 'Business user removed.');
    }

    public function branchUsers(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('view', $branch);

        $users = BranchUser::query()
            ->where('branch_id', $branch->id)
            ->where('status', 'active')
            ->with('user')
            ->orderBy('id')
            ->get()
            ->map(fn (BranchUser $row) => [
                'user_id' => $row->user_id,
                'email' => $row->user?->email,
                'name' => $row->user?->name,
                'role' => $row->role,
                'joined_at' => $row->joined_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['users' => $users]);
    }

    public function storeBranchUser(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('manageStaff', $branch);
        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:80'],
            'last_name' => ['nullable', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:40'],
            'role' => ['required', Rule::in(BusinessRoles::branchLevel())],
        ]);

        try {
            $result = $this->staff->assign($branch, $data, $request->user(), $request);
        } catch (ValidationException $e) {
            return ApiResponse::error(
                $e->getMessage() ?: 'Unable to assign staff.',
                422,
                $e->errors(),
                code: 'BRANCH_INVITATION_REQUIRED',
            );
        }

        return ApiResponse::success([
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
                'name' => $result['user']->name,
                'role' => $result['assignment']->role,
            ],
        ], message: 'Existing user assigned to branch. New users must be invited.', status: 201);
    }

    public function updateBranchUser(Request $request, Business $business, Branch $branch, int $userId)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('manageStaff', $branch);
        $data = $request->validate([
            'role' => ['required', Rule::in(BusinessRoles::branchLevel())],
        ]);
        $user = \App\Models\User::query()->findOrFail($userId);
        $result = $this->staff->assign($branch, [
            'email' => $user->email,
            'role' => $data['role'],
        ], $request->user(), $request);

        return ApiResponse::success([
            'user' => [
                'id' => $result['user']->id,
                'email' => $result['user']->email,
                'name' => $result['user']->name,
                'role' => $result['assignment']->role,
            ],
        ], message: 'Branch staff role updated.');
    }

    public function destroyBranchUser(Request $request, Business $business, Branch $branch, int $userId)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('manageStaff', $branch);
        $user = \App\Models\User::query()->findOrFail($userId);
        try {
            $this->staff->remove($branch, $user, $request->user(), $request);
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        return ApiResponse::success(message: 'Branch staff removed.');
    }

    private function statusAction(Request $request, Business $business, Branch $branch, string $action)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('update', $branch);
        $updated = $this->statuses->{$action}($branch, $request->user(), $request);

        return ApiResponse::success([
            'branch' => new BranchResource($updated->load(['business', 'restaurant'])),
        ], message: 'Branch status updated.');
    }

    private function assertBranchBelongs(Business $business, Branch $branch): void
    {
        if ((int) $branch->business_id !== (int) $business->id) {
            abort(response()->json([
                'success' => false,
                'code' => 'BRANCH_BUSINESS_MISMATCH',
                'message' => 'Branch does not belong to this business.',
                'data' => null,
                'meta' => null,
                'errors' => null,
            ], 404));
        }
    }

    private function accessibleBusinesses($user)
    {
        $query = Business::query()->withCount('branches')->orderBy('name');
        if (! $user->isSuperAdmin()) {
            $ids = BusinessUser::query()
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->pluck('business_id');
            $branchBusinessIds = Branch::query()
                ->whereIn('id', BranchUser::query()
                    ->where('user_id', $user->id)
                    ->where('status', 'active')
                    ->pluck('branch_id'))
                ->pluck('business_id');
            $query->whereIn('id', $ids->merge($branchBusinessIds)->unique());
        }

        return $query->get();
    }

    private function isBusinessManager($user, Business $business): bool
    {
        return BusinessUser::query()
            ->where('business_id', $business->id)
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists();
    }

    private function isBusinessManagerAnywhere($user): bool
    {
        return BusinessUser::query()
            ->where('user_id', $user->id)
            ->where('status', 'active')
            ->whereIn('role', BusinessRoles::businessManagers())
            ->exists();
    }

    /**
     * @return array{business: array{public_id: string, business_type: string}, branch: array{public_id: string, name: string}, role: string|null, permissions: list<string>}
     */
    private function authorizationPayload($user, Branch $branch): array
    {
        $branch->loadMissing('business');

        return [
            'business' => [
                'public_id' => $branch->business?->public_id,
                'business_type' => $branch->business?->business_type ?? 'restaurant',
            ],
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
            ],
            'role' => $this->permissions->roleFor($user, $branch),
            'permissions' => $this->permissions->permissionsFor($user, $branch),
        ];
    }
}
