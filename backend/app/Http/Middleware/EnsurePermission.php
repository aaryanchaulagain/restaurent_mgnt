<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        $user = $request->user();

        if (! $user) {
            return ApiResponse::error('You do not have permission to access this resource.', 403);
        }

        // Super admins managing a specific restaurant via X-Restaurant-Id may operate the portal.
        if ($user->isSuperAdmin() && $request->attributes->has('restaurant_id')) {
            return $next($request);
        }

        if (! $user->hasPermission($permission)) {
            return ApiResponse::error('You do not have permission to access this resource.', 403);
        }

        return $next($request);
    }
}
