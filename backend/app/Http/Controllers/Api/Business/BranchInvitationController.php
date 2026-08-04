<?php

namespace App\Http\Controllers\Api\Business;

use App\Exceptions\BranchInvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\CreateBranchInvitationRequest;
use App\Http\Resources\Business\BranchInvitationResource;
use App\Models\Branch;
use App\Models\BranchInvitation;
use App\Models\Business;
use App\Services\Branch\BranchInvitationService;
use App\Support\ApiResponse;
use App\Support\BranchInvitationStatuses;
use Illuminate\Http\Request;

class BranchInvitationController extends Controller
{
    public function __construct(
        private readonly BranchInvitationService $invitations,
    ) {}

    public function index(Request $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('manageStaff', $branch);

        $status = $request->query('status');
        $query = BranchInvitation::query()
            ->where('branch_id', $branch->id)
            ->where('business_id', $business->id)
            ->with(['invitedBy'])
            ->orderByDesc('id');

        if (is_string($status) && in_array($status, BranchInvitationStatuses::all(), true)) {
            $query->where('status', $status);
        }

        return ApiResponse::success([
            'invitations' => BranchInvitationResource::collection($query->get()),
        ]);
    }

    public function store(CreateBranchInvitationRequest $request, Business $business, Branch $branch)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->authorize('manageStaff', $branch);

        try {
            $invitation = $this->invitations->invite(
                $branch,
                $request->validated(),
                $request->user(),
                $request,
            );
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        return ApiResponse::success([
            'invitation' => new BranchInvitationResource($invitation),
        ], message: 'Invitation sent.', status: 201);
    }

    public function resend(Request $request, Business $business, Branch $branch, BranchInvitation $invitation)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->assertInvitationBelongs($business, $branch, $invitation);
        $this->authorize('manageStaff', $branch);

        try {
            $updated = $this->invitations->resend($invitation, $request->user(), $request);
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        return ApiResponse::success([
            'invitation' => new BranchInvitationResource($updated),
        ], message: 'Invitation resent.');
    }

    public function revoke(Request $request, Business $business, Branch $branch, BranchInvitation $invitation)
    {
        $this->assertBranchBelongs($business, $branch);
        $this->assertInvitationBelongs($business, $branch, $invitation);
        $this->authorize('manageStaff', $branch);

        try {
            $updated = $this->invitations->revoke($invitation, $request->user(), $request);
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        return ApiResponse::success([
            'invitation' => new BranchInvitationResource($updated),
        ], message: 'Invitation revoked.');
    }

    private function assertBranchBelongs(Business $business, Branch $branch): void
    {
        if ((int) $branch->business_id !== (int) $business->id) {
            abort(ApiResponse::error(
                'Branch does not belong to this business.',
                404,
                code: 'BRANCH_BUSINESS_MISMATCH',
            ));
        }
    }

    private function assertInvitationBelongs(Business $business, Branch $branch, BranchInvitation $invitation): void
    {
        if ((int) $invitation->branch_id !== (int) $branch->id
            || (int) $invitation->business_id !== (int) $business->id) {
            abort(ApiResponse::error(
                'Invitation not found for this branch.',
                404,
                code: 'BRANCH_INVITATION_NOT_FOUND',
            ));
        }
    }
}
