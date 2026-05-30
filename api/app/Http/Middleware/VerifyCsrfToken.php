<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyCsrfToken
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
            return $next($request);
        }

        $botTokenHeader = (string) $request->header('X-Randomize-Bot-Token', '');
        $botToken = (string) config('services.bot_sender.token', config('services.telegram.bot_token'));
        if ($botTokenHeader !== '' && $botToken !== '' && hash_equals($botToken, $botTokenHeader)) {
            if ($this->isTrustedInternalRequest($request) && $this->allowsInternalBotAuth($request)) {
                return $next($request);
            }

            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Internal bot auth is not allowed for this route',
            ], 401);
        }

        if (!$request->hasSession()) {
            return response()->json([
                'ok' => false,
                'error' => 'session_required',
                'message' => 'Перезайди в приложение',
            ], 419);
        }

        $token = $request->header('X-CSRF-Token');
        $sessionToken = $request->session()->token();

        if (!$token || !is_string($token) || !hash_equals($sessionToken, $token)) {
            return response()->json([
                'ok' => false,
                'error' => 'csrf_mismatch',
                'message' => 'Перезайди в приложение',
            ], 419);
        }

        return $next($request);
    }

    private function allowsInternalBotAuth(Request $request): bool
    {
        $routeUri = (string) ($request->route()?->uri() ?? '');

        return !in_array($routeUri, [
            'giveaway/join',
            'giveaway/check-subscription',
        ], true);
    }

    private function isTrustedInternalRequest(Request $request): bool
    {
        $ip = trim((string) ($request->server('REMOTE_ADDR') ?? $request->ip() ?? ''));
        if ($ip === '127.0.0.1' || $ip === '::1') {
            return true;
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
