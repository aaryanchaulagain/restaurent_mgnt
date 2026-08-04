<?php

use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRestaurantAccess;
use App\Http\Middleware\EnsureRole;
use App\Exceptions\OrderApiException;
use App\Domain\Payments\Exceptions\PaymentException;
use App\Support\ApiResponse;
use App\Support\OrderErrorResponse;
use App\Support\PaymentErrorResponse;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();

        $middleware->validateCsrfTokens(except: [
            'api/v1/webhooks/stripe',
        ]);

        $middleware->alias([
            'account.active' => EnsureAccountIsActive::class,
            'verified.api' => EnsureEmailIsVerified::class,
            'role' => EnsureRole::class,
            'permission' => EnsurePermission::class,
            'mfa' => EnsureMfaSatisfied::class,
            'restaurant.access' => EnsureRestaurantAccess::class,
        ]);

        // Tenant context must bind before module permission checks.
        $middleware->priority([
            \Illuminate\Auth\Middleware\Authenticate::class,
            EnsureRole::class,
            EnsureRestaurantAccess::class,
            EnsurePermission::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('orders:expire-unaccepted')->everyMinute();
        $schedule->command('payments:expire-pending')->everyMinute();
        $schedule->command('payments:reconcile')->everyFifteenMinutes();
        $schedule->command('payments:retry-webhooks')->everyFiveMinutes();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $code = OrderErrorResponse::extractCodeFromValidation($e);

                return ApiResponse::error(
                    message: $code ? OrderErrorResponse::messageForCode($code) : 'Validation failed.',
                    status: 422,
                    errors: $e->errors(),
                    code: $code,
                );
            }

            if ($e instanceof OrderApiException) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                );
            }

            if ($e instanceof \App\Exceptions\BranchInvitationException) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                );
            }

            if ($e instanceof \App\Exceptions\ModulePermissionException) {
                return ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                );
            }

            if ($e instanceof PaymentException) {
                return ApiResponse::error(
                    message: $e->getMessage() ?: PaymentErrorResponse::messageForCode($e->errorCode),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                );
            }

            if ($e instanceof AuthenticationException) {
                return ApiResponse::error(
                    message: 'Unauthenticated.',
                    status: 401,
                );
            }

            if ($e instanceof AuthorizationException) {
                return ApiResponse::error(
                    message: 'You do not have permission to access this resource.',
                    status: 403,
                );
            }

            if ($e instanceof HttpExceptionInterface) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.';

                return ApiResponse::error(
                    message: $message,
                    status: $e->getStatusCode(),
                );
            }

            report($e);

            return ApiResponse::error(
                message: 'An unexpected error occurred.',
                status: 500,
            );
        });
    })->create();
