<?php
namespace App\Services;

class TelegramAuth
{
    
    public static function verify(?string $initData, ?string $botToken = null, int $maxAgeSec = 86400): ?array
    {
        if (empty($initData)) return null;
        $botToken = $botToken ?: (string) config('services.telegram.bot_token');
        if (empty($botToken)) return null;

        parse_str($initData, $data);
        if (empty($data['hash'])) return null;

        $hash = $data['hash'];
        unset($data['hash']);

        ksort($data);
        $pairs = [];
        foreach ($data as $k => $v) {
            $pairs[] = "{$k}={$v}";
        }
        $dataCheckString = implode("\n", $pairs);

        $secretKey = hash_hmac('sha256', $botToken, 'WebAppData', true);
        $computedHash = hash_hmac('sha256', $dataCheckString, $secretKey);

        if (!hash_equals($computedHash, $hash)) return null;

        $authDate = (int) ($data['auth_date'] ?? 0);
        if ($authDate <= 0 || (time() - $authDate) > $maxAgeSec) return null;

        $user = null;
        if (!empty($data['user'])) {
            $decoded = json_decode($data['user'], true);
            if (is_array($decoded) && !empty($decoded['id'])) {
                $user = $decoded;
            }
        }

        return [
            'user' => $user,
            'auth_date' => $authDate,
            'raw' => $data,
        ];
    }
}
