<?php
namespace App\Http\Middleware;

use App\Services\TelegramAuth;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyTelegramInitData
{
    public function handle(Request $request, Closure $next, string $mode = 'soft'): Response
    {
        $initData = $request->header('X-Telegram-Init-Data');
        $verified = TelegramAuth::verify($initData);

        if ($verified && !empty($verified['user']['id'])) {
            $request->attributes->set('tg_user', $verified['user']);
            $request->attributes->set('tg_user_id', (int) $verified['user']['id']);
            return $next($request);
        }

        $botTokenHeader = (string) $request->header('X-Randomize-Bot-Token', '');
        $botToken = (string) config('services.bot_sender.token');
        if ($botTokenHeader !== '' && $botToken !== '' && hash_equals($botToken, $botTokenHeader)) {
            if (!$this->isTrustedInternalRequest($request) || !$this->allowsInternalBotAuth($request)) {
                return response()->json([
                    'ok' => false,
                    'error' => 'unauthorized',
                    'message' => 'Internal bot auth is not allowed for this route',
                ], 401);
            }

            $id = $request->input('creator_id')
                ?? $request->input('owner_id')
                ?? $request->query('creator_id')
                ?? $request->query('owner_id');

            if ($id !== null && ctype_digit((string) $id)) {
                $trustedBotUser = [
                    '__bot' => true,
                    'id' => (int) $id,
                    'username' => (string) $request->input('username', ''),
                    'language_code' => (string) $request->input('language_code', ''),
                    'is_premium' => false,
                ];

                $request->attributes->set('tg_user', $trustedBotUser);
                $request->attributes->set('tg_user_id', (int) $id);
            } elseif ($request->isMethod('GET')) {
                $request->attributes->set('tg_user', ['__bot' => true]);
                $request->attributes->set('tg_user_id', null);
            } else {
                return response()->json([
                    'ok' => false,
                    'error' => 'unauthorized',
                    'message' => 'Internal bot auth requires creator_id or owner_id',
                ], 401);
            }

            return $next($request);
        }

        if ($mode === 'strict') {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid or missing Telegram initData',
            ], 401);
        }

        $request->attributes->set('tg_user', null);
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
