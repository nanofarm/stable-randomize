<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    private string $baseUrl;

    public function __construct()
    {
        $token = config('services.telegram.bot_token');
        $this->baseUrl = "https://api.telegram.org/bot{$token}";
    }

    public function checkSubscription(int|string $chatId, int $userId): array
    {
        try {
            $response = Http::timeout(5)->get("{$this->baseUrl}/getChatMember", ['chat_id' => $chatId, 'user_id' => $userId]);
            $data = $response->json();
            if (!($data['ok'] ?? false)) return ['is_member' => false, 'status' => 'error'];
            $status = $data['result']['status'] ?? 'left';
            $isMember = in_array($status, ['creator', 'administrator', 'member']);
            if ($status === 'restricted') $isMember = $data['result']['is_member'] ?? false;
            return ['is_member' => $isMember, 'status' => $status];
        } catch (\Exception $e) {
            return ['is_member' => false, 'status' => 'error'];
        }
    }

    public function checkMultipleSubscriptions(array $chatIds, int $userId): array
    {
        $allSubscribed = true; $notSubscribed = []; $errors = [];
        foreach ($chatIds as $chatId) {
            $check = $this->checkSubscription($chatId, $userId);
            if ($check['status'] === 'error') {
                $errors[] = $chatId;
                continue;
            }
            if (!$check['is_member']) { $allSubscribed = false; $notSubscribed[] = $chatId; }
        }
        return ['all_subscribed' => $allSubscribed, 'not_subscribed' => $notSubscribed, 'errors' => $errors];
    }

    public function getChat(int|string $chatId): ?array
    {
        try {
            $r = Http::timeout(5)->get("{$this->baseUrl}/getChat", ['chat_id' => $chatId]);
            $d = $r->json();
            return ($d['ok'] ?? false) ? $d['result'] : null;
        } catch (\Exception $e) { return null; }
    }

    public function getUserBirthdate(int $userId): ?array
    {
        try {
            $r = Http::timeout(5)->get("{$this->baseUrl}/getChat", ['chat_id' => $userId]);
            $d = $r->json();
            if ($d['ok'] ?? false) {
                $bd = $d['result']['birthdate'] ?? null;
                if ($bd) return ['day' => $bd['day'], 'month' => $bd['month'], 'year' => $bd['year'] ?? null];
            }
        } catch (\Exception $e) {}
        return null;
    }

    public function getInviteLink(int|string $chatId): ?string
    {
        try {
            $r = Http::timeout(5)->post("{$this->baseUrl}/createChatInviteLink", [
                'chat_id' => $chatId,
                'name' => 'Randomize Bot',
                'creates_join_request' => false,
            ]);
            $d = $r->json();
            return ($d['ok'] ?? false) ? ($d['result']['invite_link'] ?? null) : null;
        } catch (\Exception $e) { return null; }
    }

    public function getChatMemberCount(int|string $chatId): int
    {
        try {
            $r = Http::timeout(5)->get("{$this->baseUrl}/getChatMemberCount", ['chat_id' => $chatId]);
            $d = $r->json();
            return ($d['ok'] ?? false) ? ($d['result'] ?? 0) : 0;
        } catch (\Exception $e) { return 0; }
    }

    public function getBotUsername(): ?string
    {
        return \Illuminate\Support\Facades\Cache::remember('telegram:bot_username', 3600, function () {
            try {
                $r = Http::timeout(5)->get("{$this->baseUrl}/getMe")->json();
                return $r['result']['username'] ?? null;
            } catch (\Exception $e) { return null; }
        });
    }

    public function isBotAdmin(int|string $chatId): bool
    {
        try {
            $botId = $this->getBotId();
            if (!$botId) return false;
            $check = $this->checkSubscription($chatId, $botId);
            return in_array($check['status'], ['administrator', 'creator']);
        } catch (\Exception $e) { return false; }
    }

    
    public function getBotId(): ?int
    {
        return \Illuminate\Support\Facades\Cache::remember('telegram:bot_id', 3600, function () {
            try {
                $r = Http::timeout(5)->get("{$this->baseUrl}/getMe")->json();
                return isset($r['result']['id']) ? (int) $r['result']['id'] : null;
            } catch (\Exception $e) { return null; }
        });
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): ?int
    {
        $params = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
        $d = $this->callWithRetry('sendMessage', $params, 10);
        return ($d['ok'] ?? false) ? ($d['result']['message_id'] ?? null) : null;
    }

    public function sendPhoto(int $chatId, string $photoUrl, string $caption, ?array $replyMarkup = null): ?int
    {
        $p = ['chat_id' => $chatId, 'photo' => $photoUrl, 'caption' => $caption, 'parse_mode' => 'HTML'];
        if ($replyMarkup) $p['reply_markup'] = json_encode($replyMarkup);
        $d = $this->callWithRetry('sendPhoto', $p, 15);
        return ($d['ok'] ?? false) ? ($d['result']['message_id'] ?? null) : null;
    }

    public function editMessageText(int $chatId, int $messageId, string $text, ?array $replyMarkup = null): bool
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text, 'parse_mode' => 'HTML'];
        if ($replyMarkup) $params['reply_markup'] = json_encode($replyMarkup);
        $d = $this->callWithRetry('editMessageText', $params, 10);
        return (bool) ($d['ok'] ?? false);
    }

    public function editMessageReplyMarkup(int $chatId, int $messageId, array $replyMarkup): bool
    {
        $params = ['chat_id' => $chatId, 'message_id' => $messageId, 'reply_markup' => json_encode($replyMarkup)];
        $d = $this->callWithRetry('editMessageReplyMarkup', $params, 10);
        return (bool) ($d['ok'] ?? false);
    }

    
    public function sendDocument(int $chatId, string $fileContent, string $fileName, ?string $caption = null): ?int
    {
        try {
            $response = Http::timeout(15)
                ->attach('document', $fileContent, $fileName)
                ->post("{$this->baseUrl}/sendDocument", array_filter([
                    'chat_id' => $chatId,
                    'caption' => $caption,
                    'parse_mode' => 'HTML',
                ]));
            $data = $response->json() ?? [];
            return ($data['ok'] ?? false) ? ($data['result']['message_id'] ?? null) : null;
        } catch (\Throwable $e) {
            Log::warning("Telegram sendDocument exception: {$e->getMessage()}");
            return null;
        }
    }

    private function callWithRetry(string $method, array $params, int $timeout, int $maxRetries = 2): array
    {
        for ($attempt = 0; $attempt < $maxRetries; $attempt++) {
            try {
                $response = Http::timeout($timeout)->post("{$this->baseUrl}/{$method}", $params);
                $data = $response->json() ?? [];

                if ($data['ok'] ?? false) return $data;

                $code = (int) ($data['error_code'] ?? $response->status());
                if ($code === 429) {
                    $wait = (int) ($data['parameters']['retry_after'] ?? 1);
                    if ($wait > 60) {
                        Log::warning("Telegram {$method} flood_wait={$wait}s too long, skipping");
                        return $data;
                    }
                    Log::info("Telegram {$method} 429, sleeping {$wait}s");
                    sleep($wait + 1);
                    continue;
                }
                if ($code >= 500 && $attempt + 1 < $maxRetries) {
                    sleep(2);
                    continue;
                }
                return $data;
            } catch (\Throwable $e) {
                Log::warning("Telegram {$method} exception: {$e->getMessage()}");
                if ($attempt + 1 < $maxRetries) { sleep(1); continue; }
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }
        return ['ok' => false, 'error' => 'max retries'];
    }
}
