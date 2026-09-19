<?php

use App\Support\ApiResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'merchant' => \App\Http\Middleware\Merchant::class,
            'device' => \App\Http\Middleware\RequireDeviceId::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $isV1 = fn (Request $request) => $request->is('api/v1/*');

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $isV1($request) || $request->expectsJson());

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($isV1) {
            if ($isV1($request)) {
                return ApiResponse::error('auth.token_invalid', 'Please sign in again', 401);
            }
        });

        $exceptions->render(function (ValidationException $e, Request $request) use ($isV1) {
            if ($isV1($request)) {
                return ApiResponse::error('validation.failed', 'Please check the form', 422, $e->errors());
            }
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($isV1) {
            if ($isV1($request)) {
                $retryAfter = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                return ApiResponse::error('rate_limited', 'Too many requests. Slow down.', 429, ['retry_after' => $retryAfter]);
            }
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($isV1) {
            if ($isV1($request)) {
                return ApiResponse::error('not_found', 'Not found', 404);
            }
        });
    })->create();
