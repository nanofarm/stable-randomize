<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tg.auth' => \App\Http\Middleware\VerifyTelegramInitData::class,
            'csrf' => \App\Http\Middleware\VerifyCsrfToken::class,
        ]);
        // Глобальный rate limiter 'api' зарегистрирован в AppServiceProvider.
        // Per-route throttles дополнительно ограничивают /join, /broadcast и т.д.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // API всегда отвечает JSON — никаких HTML stack trace в ответах.
        $exceptions->shouldRenderJsonWhen(function (Request $request, \Throwable $e) {
            return $request->is('api/*') || $request->expectsJson();
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*')) return null;

            $status = match (true) {
                $e instanceof \Illuminate\Validation\ValidationException => 422,
                $e instanceof \Illuminate\Auth\AuthenticationException => 401,
                $e instanceof \Illuminate\Auth\Access\AuthorizationException => 403,
                $e instanceof \Symfony\Component\HttpKernel\Exception\NotFoundHttpException => 404,
                $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface => $e->getStatusCode(),
                default => 500,
            };

            // Логируем 5xx для диагностики, 4xx — не шумим
            if ($status >= 500) {
                Log::error("API error: {$e->getMessage()}", [
                    'url' => $request->fullUrl(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }

            $payload = [
                'ok' => false,
                'error' => $status >= 500 ? 'internal_error' : ($e->getMessage() ?: 'error'),
            ];
            // Debug info только в dev-режиме
            if (config('app.debug')) {
                $payload['debug'] = ['message' => $e->getMessage(), 'file' => $e->getFile() . ':' . $e->getLine(), 'trace' => $e->getTraceAsString()];
            }
            if ($e instanceof \Illuminate\Validation\ValidationException) {
                $payload['errors'] = $e->errors();
                $payload['error'] = collect($e->errors())->flatten()->first() ?: 'validation';
            }

            return response()->json($payload, $status);
        });
    })->create();
