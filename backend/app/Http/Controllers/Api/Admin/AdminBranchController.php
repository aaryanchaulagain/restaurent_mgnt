<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Business\BranchResource;
use App\Models\Branch;
use App\Services\Branch\BranchStatusService;
use App\Support\ApiResponse;
use Illuminate\Http\Request;

class AdminBranchController extends Controller
{
    public function __construct(private readonly BranchStatusService $statuses) {}

    public function suspend(Request $request, Branch $branch)
    {
        $updated = $this->statuses->suspend($branch, $request->user(), $request);

        return ApiResponse::success([
            'branch' => new BranchResource($updated->load(['business', 'restaurant'])),
        ], message: 'Branch suspended.');
    }

    public function unsuspend(Request $request, Branch $branch)
    {
        $updated = $this->statuses->unsuspend($branch, $request->user(), $request);

        return ApiResponse::success([
            'branch' => new BranchResource($updated->load(['business', 'restaurant'])),
        ], message: 'Branch unsuspended (now paused).');
    }
}
