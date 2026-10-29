<?php

use App\Exceptions\EmptyCartException;
use App\Exceptions\InsufficientStockException;
use App\Exceptions\InvalidOrderTransitionException;
use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\SecurityHeaders;
use App\Support\ApiError;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use PHPOpenSourceSaver\JWTAuth\Exceptions\JWTException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'admin' => EnsureUserIsAdmin::class,
        ]);

        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // The project is backend-only: every response, including validation
        // failures and unauthenticated requests, is rendered as JSON.
        $exceptions->shouldRenderJsonWhen(fn () => true);

        // Domain exceptions stay HTTP-agnostic; their status codes live here.
        $exceptions->map(
            InsufficientStockException::class,
            fn (InsufficientStockException $e): HttpException => new ConflictHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            EmptyCartException::class,
            fn (EmptyCartException $e): HttpException => new UnprocessableEntityHttpException($e->getMessage(), $e),
        );
        $exceptions->map(
            InvalidOrderTransitionException::class,
            fn (InvalidOrderTransitionException $e): HttpException => new UnprocessableEntityHttpException($e->getMessage(), $e),
        );

        $exceptions->map(
            JWTException::class,
            fn (JWTException $e): HttpException => new UnauthorizedHttpException('Bearer', 'The token is invalid or can no longer be refreshed.', $e),
        );

        $exceptions->respond(
            fn (Response $response): Response => $response instanceof JsonResponse
                ? ApiError::reshape($response, (bool) config('app.debug'))
                : $response,
        );
    })->create();
