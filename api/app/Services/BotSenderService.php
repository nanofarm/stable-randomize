<?php
namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BotSenderService
{
    private string $botUrl;
    private string $botToken;

    public function __construct()
    {
        $this->botUrl = rtrim((string) config('services.bot_sender.url', 'http://bot:8080'), '/');
        $this->botToken = (string) config('services.bot_sender.token', config('services.telegram.bot_token'));
    }

    private function http(int $timeout = 15): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout($timeout)->withHeaders([
            'X-Randomize-Bot-Token' => $this->botToken,
        ]);
    }

    public function sendPost(int $chatId, string $text, ?array $replyMarkup = null, ?string $photoUrl = null): ?int
    {
        $payload = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($photoUrl) {
            $payload['photo_url'] = $photoUrl;
        }

        try {
            $r = $this->http()->post("{$this->botUrl}/send-post", $payload)->json();
            if ($r['ok'] ?? false) {
                return $r['message_id'] ?? null;
            }
            Log::warning('BotSender sendPost failed: ' . ($r['error'] ?? 'unknown'));
        } catch (\Throwable $e) {
            Log::warning("BotSender sendPost exception: {$e->getMessage()}");
        }
        return null;
    }

    public function editPost(int $chatId, int $messageId, string $text, ?array $replyMarkup = null, ?string $photo = null): bool
    {
        $payload = ['chat_id' => $chatId, 'message_id' => $messageId, 'text' => $text];
        if ($replyMarkup) {
            $payload['reply_markup'] = $replyMarkup;
        }
        if ($photo) {
            $payload['photo'] = $photo;
        }

        try {
            $r = $this->http()->post("{$this->botUrl}/edit-post", $payload)->json();
            if ($r['ok'] ?? false) {
                return true;
            }
            Log::warning('BotSender editPost failed: ' . ($r['error'] ?? 'unknown'));
        } catch (\Throwable $e) {
            Log::warning("BotSender editPost exception: {$e->getMessage()}");
        }
        return false;
    }

    public function uploadPhoto(string $filePath, int $userId): ?string
    {
        if (!file_exists($filePath)) {
            Log::error("BotSender uploadPhoto: File not found at {$filePath}");
            return null;
        }

        try {
            $contents = file_get_contents($filePath);
            if ($contents === false) {
                Log::error("BotSender uploadPhoto: Cannot read file at {$filePath}");
                return null;
            }

            $response = $this->http(30)
                ->attach('photo', $contents, basename($filePath))
                ->post("{$this->botUrl}/upload-photo", [
                    'user_id' => $userId,
                ]);

            $r = $response->json();

            if ($response->successful() && ($r['ok'] ?? false)) {
                Log::info('BotSender uploadPhoto success, file_id: ' . ($r['file_id'] ?? 'none'));
                return $r['file_id'] ?? null;
            }

            Log::warning('BotSender uploadPhoto failed. Status: ' . $response->status() . ', Response: ' . json_encode($r));
        } catch (\Throwable $e) {
            Log::error("BotSender uploadPhoto exception: {$e->getMessage()}");
        }
        return null;
    }
}
