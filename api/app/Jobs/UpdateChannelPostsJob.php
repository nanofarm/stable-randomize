<?php

namespace App\Jobs;

use App\Models\Giveaway;
use App\Models\GiveawayMessage;
use App\Services\BotSenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class UpdateChannelPostsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;
    public int $tries = 2;

    public function __construct(public int $giveawayId) {}

    public function handle(BotSenderService $botSender): void
    {
        if (!Cache::add("upd_posts:{$this->giveawayId}", 1, 3)) return;

        $g = Giveaway::with(['channels', 'prizes'])->find($this->giveawayId);
        if (!$g) return;

        $messages = GiveawayMessage::where('giveaway_id', $g->id)->get();
        if ($messages->isEmpty()) return;

        $count = $g->participants()->count();

        $controller = app(\App\Http\Controllers\GiveawayController::class);
        $text = $controller->buildPostText($g, $count);

        $photo = null;
        if ($g->photo_file_id) {
            $photo = $g->photo_file_id;
        } elseif ($g->photo_path && str_starts_with($g->photo_path, 'tg:')) {
            $photo = substr($g->photo_path, 3);
        }

        foreach ($messages as $msg) {
            $ch = $g->channels->firstWhere('chat_id', $msg->chat_id);
            $button = $controller->buildPostButton($g, $ch ? (int) $ch->id : null, $count);
            try {
                $botSender->editPost((int) $msg->chat_id, (int) $msg->message_id, $text, $button, $photo);
            } catch (\Throwable $e) {
                Log::warning("UpdateChannelPostsJob: {$e->getMessage()}");
            }
        }
    }
}
