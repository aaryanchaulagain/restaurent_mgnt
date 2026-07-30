<?php

namespace App\Http\Middleware;

use App\Support\ApiResponse;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Symfony\Component\HttpFoundation\Response;

class EnsureMfaSatisfied
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isSuperAdmin()
            && config('suvakamana.require_super_admin_mfa')
            && $user->hasConfirmedMfa()
            && ! Session::get('mfa.verified')) {
            return ApiResponse::error(
                message: 'Multi-factor authentication is required.',
                status: 403,
                code: 'mfa_required',
            );
        }

        if (config('suvakamana.require_super_admin_mfa')
            && Session::get('mfa.pending_user_id')
            && ! $request->is('api/auth/mfa/*')
            && ! $request->is('api/auth/logout')) {
            return ApiResponse::error(
                message: 'Multi-factor authentication is required.',
                status: 403,
                code: 'mfa_required',
            );
        }

        return $next($request);
    }
}
