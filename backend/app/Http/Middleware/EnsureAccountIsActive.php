<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        if ($user->isSuspended()) {
            return ApiResponse::error('This account has been suspended.', 403);
        }

        if ($user->isLocked()) {
            return ApiResponse::error('This account is temporarily locked.', 423);
        }

        return $next($request);
    }
}
