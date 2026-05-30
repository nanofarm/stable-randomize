<?php

namespace App\Jobs;

use App\Models\Giveaway;
use App\Models\Participant;
use App\Services\TelegramService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class BroadcastJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 1;

    public function __construct(
        public int $giveawayId,
        public string $text
    ) {}

    public function handle(TelegramService $telegram): void
    {
        $g = Giveaway::find($this->giveawayId);
        if (!$g) return;

        $sent = 0; $failed = 0;
        Participant::where('giveaway_id', $this->giveawayId)
            ->select(['id', 'user_id'])
            ->chunkById(200, function ($chunk) use ($telegram, &$sent, &$failed) {
                foreach ($chunk as $p) {
                    try {
                        $r = $telegram->sendMessage((int) $p->user_id, $this->text);
                        if ($r) $sent++; else $failed++;
                    } catch (\Throwable $e) {
                        $failed++;
                        Log::warning("BroadcastJob: {$e->getMessage()}");
                    }
                    usleep(40000);
                }
            });

        Log::info("BroadcastJob done: giveaway={$this->giveawayId} sent={$sent} failed={$failed}");
    }
}
