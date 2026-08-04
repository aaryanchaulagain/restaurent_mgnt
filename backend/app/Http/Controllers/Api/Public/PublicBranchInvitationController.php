<?php

namespace App\Http\Controllers\Api\Public;

use App\Exceptions\BranchInvitationException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Branch\AcceptBranchInvitationRequest;
use App\Http\Resources\UserResource;
use App\Services\Auth\AuthService;
use App\Services\Branch\BranchInvitationService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PublicBranchInvitationController extends Controller
{
    public function __construct(
        private readonly BranchInvitationService $invitations,
        private readonly AuthService $authService,
    ) {}

    public function show(string $token)
    {
        try {
            $preview = $this->invitations->previewByToken($token);
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        }

        $invitation = $preview['invitation'];

        return ApiResponse::success([
            'existing_user' => $preview['existing_user'],
            'invitation' => [
                'public_id' => $invitation->public_id,
                'email' => $invitation->email,
                'full_name' => $invitation->full_name,
                'role' => $invitation->role,
                'status' => $invitation->status,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'branch' => [
                    'public_id' => $invitation->branch?->public_id,
                    'name' => $invitation->branch?->name,
                ],
                'business' => [
                    'public_id' => $invitation->business?->public_id,
                    'name' => $invitation->business?->name,
                ],
            ],
        ]);
    }

    public function accept(AcceptBranchInvitationRequest $request, string $token)
    {
        $authenticated = Auth::guard('web')->user()
            ?? Auth::guard('sanctum')->user()
            ?? $request->user();

        try {
            $result = $this->invitations->acceptByToken(
                $token,
                $request->validated(),
                $authenticated,
                $request,
            );
        } catch (BranchInvitationException $e) {
            return ApiResponse::error($e->getMessage(), $e->httpStatus, code: $e->errorCode);
        } catch (ValidationException $e) {
            return ApiResponse::error('Validation failed.', 422, $e->errors());
        }

        $user = $result['user'];
        if (! Auth::guard('web')->check() || (int) Auth::guard('web')->id() !== (int) $user->id) {
            $this->authService->completeLogin($user, false, $request);
            $user = $user->fresh(['roles.permissions', 'restaurantUsers.restaurant', 'branchUsers', 'mfaMethod']);
        }

        $branch = $result['branch'];

        return ApiResponse::success([
            'user' => new UserResource($user),
            'branch' => [
                'public_id' => $branch->public_id,
                'name' => $branch->name,
                'business_public_id' => $branch->business?->public_id,
                'restaurant_public_id' => $branch->restaurant?->public_id,
            ],
        ], message: 'Invitation accepted.');
    }
}
