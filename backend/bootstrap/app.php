<?php

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAccountIsActive;
use App\Http\Middleware\EnsureEmailIsVerified;
use App\Http\Middleware\EnsureMfaSatisfied;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureRestaurantAccess;
use App\Http\Middleware\EnsureRole;
use App\Http\Middleware\TrustProxiesFromConfig;
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

        $middleware->prepend(TrustProxiesFromConfig::class);
        $middleware->append(AssignRequestId::class);

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
        $schedule->command('inventory:release-expired-reservations')->everyMinute();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
            'temporary_password',
            'token',
            'invitation_token',
            'secret',
            'client_secret',
            'stripe-signature',
        ]);

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            $requestId = (string) $request->attributes->get('request_id', '');
            $withId = function ($response) use ($requestId) {
                if ($requestId !== '') {
                    $response->headers->set('X-Request-Id', $requestId);
                    $payload = $response->getData(true);
                    if (is_array($payload)) {
                        $payload['request_id'] = $requestId;
                        $response->setData($payload);
                    }
                }

                return $response;
            };

            if ($e instanceof ValidationException) {
                $code = OrderErrorResponse::extractCodeFromValidation($e);

                return $withId(ApiResponse::error(
                    message: $code ? OrderErrorResponse::messageForCode($code) : 'Validation failed.',
                    status: 422,
                    errors: $e->errors(),
                    code: $code,
                ));
            }

            if ($e instanceof OrderApiException) {
                return $withId(ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                ));
            }

            if ($e instanceof \App\Exceptions\BranchInvitationException) {
                return $withId(ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                ));
            }

            if ($e instanceof \App\Exceptions\ModulePermissionException) {
                return $withId(ApiResponse::error(
                    message: $e->getMessage(),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                ));
            }

            if ($e instanceof PaymentException) {
                return $withId(ApiResponse::error(
                    message: $e->getMessage() ?: PaymentErrorResponse::messageForCode($e->errorCode),
                    status: $e->httpStatus,
                    code: $e->errorCode,
                ));
            }

            if ($e instanceof AuthenticationException) {
                return $withId(ApiResponse::error(
                    message: 'Unauthenticated.',
                    status: 401,
                ));
            }

            if ($e instanceof AuthorizationException) {
                return $withId(ApiResponse::error(
                    message: 'You do not have permission to access this resource.',
                    status: 403,
                ));
            }

            if ($e instanceof HttpExceptionInterface) {
                $message = $e->getMessage() !== '' ? $e->getMessage() : 'HTTP error.';

                return $withId(ApiResponse::error(
                    message: $message,
                    status: $e->getStatusCode(),
                ));
            }

            report($e);

            return $withId(ApiResponse::error(
                message: 'An unexpected error occurred.',
                status: 500,
            ));
        });
    })->create();
