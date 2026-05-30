<?php

namespace App\Console\Commands;

use App\Models\Giveaway;
use App\Models\GiveawayMessage;
use App\Services\BotSenderService;
use App\Services\GiveawayDrawAuditService;
use App\Services\TelegramService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FinishExpiredGiveaways extends Command
{
    protected $signature = 'giveaways:finish-expired';
    protected $description = 'Auto-finish giveaways past their end_date';

    public function handle(TelegramService $telegram, BotSenderService $botSender): void
    {
        $expired = Giveaway::where('status', 'active')
            ->whereNotNull('end_date')
            ->where('end_date', '<=', now())
            ->get();

        foreach ($expired as $giveaway) {
            try {
                $safeTitle = htmlspecialchars($giveaway->title);

                if ($giveaway->participants()->count() === 0) {
                    $giveaway->update(['status' => 'finished']);
                    $messages = GiveawayMessage::where('giveaway_id', $giveaway->id)->get();
                    $text = "🎰 <b>{$safeTitle}</b> — ЗАВЕРШЁН\n\n";
                    $text .= "😔 Участников: 0\nРозыгрыш завершён без победителей.";
                    foreach ($messages as $msg) {
                        $botSender->editPost((int) $msg->chat_id, (int) $msg->message_id, $text);
                    }
                    $telegram->sendMessage(
                        (int) $giveaway->creator_id,
                        "⏰ Розыгрыш <b>{$safeTitle}</b> завершён автоматически.\n\n😔 Участников не было."
                    );
                    $this->info("Finished (no participants): {$giveaway->public_id}");
                    continue;
                }

                $auditService = app(GiveawayDrawAuditService::class);
                $drawResult = DB::transaction(function () use ($giveaway, $auditService) {
                    $locked = Giveaway::where('id', $giveaway->id)->lockForUpdate()->first();
                    if (!$locked || $locked->status === 'finished') {
                        return ['__error' => 'already_finished'];
                    }

                    $existingWinners = $locked->winners()->get();
                    if ($existingWinners->isNotEmpty()) {
                        $locked->load('winners', 'drawAudit');
                        if ($auditService->isAuditVerified($locked)) {
                            $locked->update(['status' => 'finished']);

                            return [
                                'winners' => $locked->winners->map(fn ($winner) => [
                                    'participant_id' => (int) $winner->id,
                                    'user_id' => (int) $winner->user_id,
                                    'user_name' => (string) $winner->user_name,
                                    'tickets' => max(1, (int) ($winner->tickets ?? 1)),
                                    'winner_place' => (int) $winner->winner_place,
                                ])->values()->all(),
                                'reused_verified_winners' => true,
                            ];
                        }

                        $locked->participants()
                            ->where('is_winner', true)
                            ->update([
                                'is_winner' => false,
                                'winner_place' => null,
                            ]);
                    }

                    $participantSnapshot = $auditService->buildParticipantSnapshot($locked);
                    if (empty($participantSnapshot)) {
                        return ['__error' => 'no_participants'];
                    }

                    $winners = $locked->drawWinnersFromSnapshot($participantSnapshot);
                    $auditService->createAudit($locked, $participantSnapshot, $winners);

                    return [
                        'winners' => $winners,
                        'reused_verified_winners' => false,
                    ];
                });
                if (!is_array($drawResult) || isset($drawResult['__error'])) {
                    $this->info("Skipped (already finished): {$giveaway->public_id}");
                    continue;
                }

                $winners = $drawResult['winners'];
                if (($drawResult['reused_verified_winners'] ?? false) === true) {
                    $this->info("Reused verified winners: {$giveaway->public_id}");
                }
                $winnerNames = collect($winners)->map(function ($w) {
                    $medal = match((int) ($w['winner_place'] ?? 0)) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => '' };
                    $name = htmlspecialchars($w['user_name']);
                    return $medal ? "{$medal} {$name}" : $name;
                })->join("\n");

                $safePrize = $giveaway->prize ? htmlspecialchars($giveaway->prize) : '';
                foreach ($winners as $w) {
                    try {
                        $place = (int) ($w['winner_place'] ?? 0);
                        $placeText = $place ? " ({$place} место)" : '';
                        $telegram->sendMessage(
                            (int) $w['user_id'],
                            "🎉 Вы победили в розыгрыше <b>{$safeTitle}</b>{$placeText}!\n\n"
                            . ($safePrize ? "🎁 Приз: {$safePrize}" : "")
                        );
                    } catch (\Exception $e) {
                    }
                }

                $messages = GiveawayMessage::where('giveaway_id', $giveaway->id)->get();
                $count = $giveaway->participants()->count();
                $text = "🏆 <b>{$safeTitle}</b> — ЗАВЕРШЁН\n\n";
                if ($safePrize) $text .= "🎁 Приз: <b>{$safePrize}</b>\n\n";
                $text .= "👥 Участников: {$count}\n\n";
                $text .= "🎉 <b>Победители:</b>\n{$winnerNames}";

                $cronAudit = $giveaway->fresh()->drawAudit;
                if ($cronAudit) {
                    $text .= "\n\n✅ <b>Verified</b> • <code>" . substr($cronAudit->result_token, 0, 12) . "...</code>";
                }

                foreach ($messages as $msg) {
                    $botSender->editPost((int) $msg->chat_id, (int) $msg->message_id, $text);
                }

                try {
                    $audit = $giveaway->fresh()->drawAudit;
                    $auditChannelId = config('services.telegram.audit_channel_id');
                    if ($audit && $auditChannelId) {
                        $cronWinners = is_array($audit->winner_snapshot) ? $audit->winner_snapshot : [];
                        $auditText = "🏆 <b>{$safeTitle}</b> — ИТОГИ\n\n";
                        $auditText .= "👥 Участников: {$audit->total_participants}\n";
                        $auditText .= "🎫 Билетов: {$audit->total_tickets}\n\n";
                        if (!empty($cronWinners)) {
                            $auditText .= "🎉 <b>Победители:</b>\n";
                            foreach ($cronWinners as $cw) {
                                $cwPlace = (int) ($cw['winner_place'] ?? 0);
                                $cwMedal = match($cwPlace) { 1 => '🥇', 2 => '🥈', 3 => '🥉', default => "#{$cwPlace}" };
                                $cwName = htmlspecialchars($cw['user_name'] ?? '');
                                $cwUid = (int) ($cw['user_id'] ?? 0);
                                $cwLink = $cwUid ? "<a href=\"tg://user?id={$cwUid}\">{$cwName}</a>" : $cwName;
                                $auditText .= "{$cwMedal} {$cwLink}\n";
                            }
                            $auditText .= "\n";
                        }
                        $auditText .= "🕐 " . $audit->drawn_at?->format('d.m.Y H:i:s') . " UTC\n";
                        $auditText .= "✅ <b>Verified</b> • <code>" . substr($audit->result_token, 0, 12) . "...</code>";
                        $telegram->sendMessage((int) $auditChannelId, $auditText);
                    }
                } catch (\Throwable $e) {
                }

                try {
                    $cronAuditFresh = $giveaway->fresh()->drawAudit;
                    if ($cronAuditFresh) {
                        $participants = is_array($cronAuditFresh->participant_snapshot) ? $cronAuditFresh->participant_snapshot : [];
                        if (!empty($participants)) {
                            $csvWinners = is_array($cronAuditFresh->winner_snapshot) ? $cronAuditFresh->winner_snapshot : [];
                            $csvWinnerIds = [];
                            foreach ($csvWinners as $csvW) {
                                $csvWinnerIds[(int) ($csvW['participant_id'] ?? 0)] = (int) ($csvW['winner_place'] ?? 0);
                            }
                            $hasAccountCreatedAt = false;
                            foreach ($participants as $participantRow) {
                                if (is_array($participantRow) && array_key_exists('account_created_at', $participantRow)) {
                                    $hasAccountCreatedAt = true;
                                    break;
                                }
                            }

                            $csv = "\xEF\xBB\xBF";
                            $csv .= $hasAccountCreatedAt
                                ? "№;Имя;User ID;Билеты;Победитель;Место;Аккаунт создан\n"
                                : "№;Имя;User ID;Билеты;Победитель;Место\n";

                            foreach ($participants as $pi => $pp) {
                                $ppName = str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) ($pp['user_name'] ?? ''));
                                $ppUid = (int) ($pp['user_id'] ?? 0);
                                $ppTickets = (int) ($pp['tickets'] ?? 1);
                                $ppPid = (int) ($pp['participant_id'] ?? 0);
                                $ppIsWinner = isset($csvWinnerIds[$ppPid]) ? 'Да' : 'Нет';
                                $ppPlace = $csvWinnerIds[$ppPid] ?? '';

                                $accountCreatedAt = '';
                                if ($hasAccountCreatedAt) {
                                    $rawAccountCreatedAt = $pp['account_created_at'] ?? null;
                                    if ($rawAccountCreatedAt instanceof \DateTimeInterface) {
                                        $accountCreatedAt = $rawAccountCreatedAt->format('Y-m-d H:i:s');
                                    } elseif ($rawAccountCreatedAt !== null && $rawAccountCreatedAt !== '') {
                                        try {
                                            $accountCreatedAt = \Illuminate\Support\Carbon::parse((string) $rawAccountCreatedAt)->format('Y-m-d H:i:s');
                                        } catch (\Throwable $e) {
                                            $accountCreatedAt = str_replace(["\r", "\n", ';'], [' ', ' ', ','], (string) $rawAccountCreatedAt);
                                        }
                                    }
                                }

                                $csv .= ($pi + 1) . ";{$ppName};{$ppUid};{$ppTickets};{$ppIsWinner};{$ppPlace}" . ($hasAccountCreatedAt ? ";{$accountCreatedAt}" : '') . "\n";
                            }
                            $csvTitle = preg_replace('/[^a-zA-Zа-яА-Я0-9_\- ]/u', '', $giveaway->title) ?: 'giveaway';
                            $csvFileName = "participants_{$csvTitle}_{$giveaway->public_id}.csv";
                            $total = count($participants);
                            $telegram->sendDocument(
                                (int) $giveaway->creator_id,
                                $csv,
                                $csvFileName,
                                "📋 Участники: <b>{$safeTitle}</b>\n👥 Всего: {$total}"
                            );
                        }
                    }
                } catch (\Throwable $e) {
                }

                $this->info("Finished: {$giveaway->public_id} - winners: {$winnerNames}");
            } catch (\Exception $e) {
                $this->error("Error finishing {$giveaway->public_id}: {$e->getMessage()}");
            }
        }

        if ($expired->isEmpty()) {
            $this->info("No expired giveaways");
        }
    }
}
