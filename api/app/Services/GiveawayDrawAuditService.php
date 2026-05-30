<?php

namespace App\Services;

use App\Models\Giveaway;
use App\Models\GiveawayDrawAudit;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GiveawayDrawAuditService
{
    public function buildParticipantSnapshot(Giveaway $giveaway): array
    {
        return $giveaway->participants()
            ->orderBy('id')
            ->get(['id', 'user_id', 'user_name', 'username', 'tickets', 'nickname_bonus', 'created_at', 'account_created_at'])
            ->map(fn ($participant) => [
                'participant_id' => (int) $participant->id,
                'user_id' => (int) $participant->user_id,
                'user_name' => (string) $participant->user_name,
                'username' => (string) ($participant->username ?? ''),
                'tickets' => max(1, (int) ($participant->tickets ?? 1)),
                'nickname_bonus' => (bool) $participant->nickname_bonus,
                'joined_at' => $participant->created_at?->toISOString(),
                'account_created_at' => $participant->account_created_at?->toISOString(),
            ])
            ->values()
            ->all();
    }

    public function createAudit(Giveaway $giveaway, array $participantSnapshot, array $winnerSnapshot): GiveawayDrawAudit
    {
        $drawnAt = now()->startOfSecond();
        $participantHash = hash('sha256', $this->canonicalJson($participantSnapshot));
        $winnerHash = hash('sha256', $this->canonicalJson($winnerSnapshot));
        $totalTickets = array_sum(array_map(fn (array $row) => max(1, (int) ($row['tickets'] ?? 1)), $participantSnapshot));
        $nonce = Str::uuid()->toString();

        $payload = [
            'algorithm' => 'weighted_random_v2',
            'drawn_at' => $drawnAt->toISOString(),
            'draw_nonce' => $nonce,
            'giveaway_id' => (int) $giveaway->id,
            'giveaway_public_id' => (string) $giveaway->public_id,
            'participant_snapshot_hash' => $participantHash,
            'total_participants' => count($participantSnapshot),
            'total_tickets' => $totalTickets,
            'winner_snapshot_hash' => $winnerHash,
        ];

        $signature = hash_hmac('sha256', $this->canonicalJson($payload), $this->signingSecret());
        $resultToken = hash('sha256', $participantHash . '|' . $winnerHash . '|' . $nonce);

        return GiveawayDrawAudit::create([
            'giveaway_id' => $giveaway->id,
            'algorithm' => $payload['algorithm'],
            'participant_snapshot_hash' => $participantHash,
            'winner_snapshot_hash' => $winnerHash,
            'participant_snapshot' => $participantSnapshot,
            'winner_snapshot' => $winnerSnapshot,
            'total_participants' => $payload['total_participants'],
            'total_tickets' => $payload['total_tickets'],
            'draw_nonce' => $nonce,
            'signature' => $signature,
            'result_token' => $resultToken,
            'drawn_at' => $drawnAt,
        ]);
    }

    public function verify(Giveaway $giveaway): array
    {
        $audit = $giveaway->drawAudit;
        if (!$audit) {
            return [
                'verified' => false,
                'reason' => $giveaway->winners()->exists() ? 'winners_without_audit' : 'audit_missing',
            ];
        }

        $participantSnapshot = is_array($audit->participant_snapshot) ? $audit->participant_snapshot : [];
        $winnerSnapshot = is_array($audit->winner_snapshot) ? $audit->winner_snapshot : [];

        $recomputedParticipantHash = hash('sha256', $this->canonicalJson($participantSnapshot));
        $recomputedWinnerHash = hash('sha256', $this->canonicalJson($winnerSnapshot));
        $payload = [
            'algorithm' => (string) $audit->algorithm,
            'drawn_at' => $audit->drawn_at?->toISOString(),
            'draw_nonce' => (string) $audit->draw_nonce,
            'giveaway_id' => (int) $giveaway->id,
            'giveaway_public_id' => (string) $giveaway->public_id,
            'participant_snapshot_hash' => $recomputedParticipantHash,
            'total_participants' => count($participantSnapshot),
            'total_tickets' => array_sum(array_map(fn (array $row) => max(1, (int) ($row['tickets'] ?? 1)), $participantSnapshot)),
            'winner_snapshot_hash' => $recomputedWinnerHash,
        ];
        $expectedSignature = hash_hmac('sha256', $this->canonicalJson($payload), $this->signingSecret());

        $dbWinners = $giveaway->winners()
            ->orderBy('winner_place')
            ->get(['id', 'user_id', 'user_name', 'tickets', 'winner_place'])
            ->map(fn ($winner) => [
                'participant_id' => (int) $winner->id,
                'user_id' => (int) $winner->user_id,
                'user_name' => (string) $winner->user_name,
                'tickets' => max(1, (int) ($winner->tickets ?? 1)),
                'winner_place' => (int) $winner->winner_place,
            ])
            ->values()
            ->all();

        $winnerDbHash = hash('sha256', $this->canonicalJson($dbWinners));

        $checks = [
            'status_is_finished' => $giveaway->status === 'finished',
            'participant_snapshot_hash_matches' => hash_equals((string) $audit->participant_snapshot_hash, $recomputedParticipantHash),
            'winner_snapshot_hash_matches' => hash_equals((string) $audit->winner_snapshot_hash, $recomputedWinnerHash),
            'signature_matches' => hash_equals((string) $audit->signature, $expectedSignature),
            'winner_db_matches_audit' => hash_equals($recomputedWinnerHash, $winnerDbHash),
        ];

        return [
            'verified' => !in_array(false, $checks, true),
            'checks' => $checks,
            'audit' => $this->publicSummary($audit),
        ];
    }

    public function publicSummary(GiveawayDrawAudit $audit): array
    {
        return [
            'algorithm' => (string) $audit->algorithm,
            'drawn_at' => $audit->drawn_at?->toISOString(),
            'participant_snapshot_hash' => (string) $audit->participant_snapshot_hash,
            'winner_snapshot_hash' => (string) $audit->winner_snapshot_hash,
            'total_participants' => (int) $audit->total_participants,
            'total_tickets' => (int) $audit->total_tickets,
            'result_token' => (string) $audit->result_token,
            'signature' => (string) $audit->signature,
            'winners' => Arr::map(is_array($audit->winner_snapshot) ? $audit->winner_snapshot : [], fn (array $winner) => [
                'user_id' => (int) ($winner['user_id'] ?? 0),
                'user_name' => (string) ($winner['user_name'] ?? ''),
                'winner_place' => (int) ($winner['winner_place'] ?? 0),
            ]),
        ];
    }

    public function isAuditVerified(Giveaway $giveaway): bool
    {
        return ($this->verify($giveaway)['verified'] ?? false) === true;
    }

    private function signingSecret(): string
    {
        $secret = (string) config('services.giveaway_audit.secret');
        if ($secret === '') {
            throw new \RuntimeException('GIVEAWAY_AUDIT_SECRET is not configured');
        }

        return str_starts_with($secret, 'base64:') ? base64_decode(substr($secret, 7), true) ?: $secret : $secret;
    }

    private function canonicalJson(array $data): string
    {
        return json_encode($this->normalize($data), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    private function normalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value instanceof Carbon ? $value->toISOString() : $value;
        }

        if (array_is_list($value)) {
            return array_map(fn ($item) => $this->normalize($item), $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = $this->normalize($item);
        }

        return $value;
    }
}
