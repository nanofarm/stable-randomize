<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\GiveawayMessage;
use Illuminate\Support\Str;
use App\Models\Prize;

class Giveaway extends Model
{
    protected $fillable = [
        'public_id', 'title', 'description', 'prize',
        'winners_count', 'creator_id', 'creator_name',
        'status', 'end_date', 'start_date', 'photo_path', 'photo_file_id',
        'nickname_condition', 'nickname_bonus_multiplier', 'referral_tickets',
        'tasks',
    ];

    protected $casts = [
        'creator_id'    => 'integer',
        'winners_count' => 'integer',
        'end_date'      => 'datetime',
        'start_date'    => 'datetime',
        'nickname_bonus_multiplier' => 'integer',
        'referral_tickets' => 'integer',
        'tasks'         => 'array',
    ];

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function winners(): HasMany
    {
        return $this->hasMany(Participant::class)->where('is_winner', true)->orderBy('winner_place');
    }

    public function prizes(): HasMany
    {
        return $this->hasMany(Prize::class)->orderBy('place');
    }

    public function postedMessages(): HasMany
    {
        return $this->hasMany(GiveawayMessage::class);
    }

    public function channels(): BelongsToMany
    {
        return $this->belongsToMany(Channel::class, 'giveaway_channel')->withPivot('publish');
    }

    public function taskSubmissions(): HasMany
    {
        return $this->hasMany(GiveawayTaskSubmission::class);
    }

    public function drawAudit(): HasOne
    {
        return $this->hasOne(GiveawayDrawAudit::class);
    }

    public function getParticipantCountAttribute(): int
    {
        return $this->participants_count ?? $this->participants()->count();
    }

    protected static function booted(): void
    {
        static::creating(function (Giveaway $g) {
            if (empty($g->public_id)) {
                $g->public_id = Str::random(8);
            }
        });
    }

    public function scopeActive($q)   { return $q->where('status', 'active'); }
    public function scopeFinished($q) { return $q->where('status', 'finished'); }

    public function drawWinners(): array
    {
        $snapshot = $this->participants()
            ->orderBy('id')
            ->get(['id', 'user_id', 'user_name', 'tickets'])
            ->map(fn ($participant) => [
                'participant_id' => (int) $participant->id,
                'user_id' => (int) $participant->user_id,
                'user_name' => (string) $participant->user_name,
                'tickets' => max(1, (int) ($participant->tickets ?? 1)),
            ])
            ->values()
            ->all();

        return $this->drawWinnersFromSnapshot($snapshot);
    }

    public function drawWinnersFromSnapshot(array $snapshot): array
    {
        if (empty($snapshot)) {
            return [];
        }

        $count = min(max(1, (int) $this->winners_count ?: 1), count($snapshot));
        $winners = [];
        $alive = array_values(array_map(function (array $participant) {
            return [
                'participant_id' => (int) ($participant['participant_id'] ?? 0),
                'user_id' => (int) ($participant['user_id'] ?? 0),
                'user_name' => (string) ($participant['user_name'] ?? ''),
                'tickets' => max(1, (int) ($participant['tickets'] ?? 1)),
            ];
        }, $snapshot));

        for ($place = 1; $place <= $count && !empty($alive); $place++) {
            $totalWeight = 0;
            $cumulative = [];
            foreach ($alive as $entry) {
                $totalWeight += $entry['tickets'];
                $cumulative[] = [
                    'participant' => $entry,
                    'cumWeight' => $totalWeight,
                ];
            }

            if ($totalWeight <= 0) {
                break;
            }

            $rand = random_int(1, $totalWeight);
            $lo = 0;
            $hi = count($cumulative) - 1;
            while ($lo < $hi) {
                $mid = intdiv($lo + $hi, 2);
                if ($cumulative[$mid]['cumWeight'] < $rand) {
                    $lo = $mid + 1;
                } else {
                    $hi = $mid;
                }
            }

            $winner = $cumulative[$lo]['participant'];
            $winner['winner_place'] = $place;
            $winners[] = $winner;

            $alive = array_values(array_filter(
                $alive,
                fn (array $entry) => $entry['participant_id'] !== $winner['participant_id']
            ));
        }

        Participant::where('giveaway_id', $this->id)
            ->where('is_winner', true)
            ->update([
                'is_winner' => false,
                'winner_place' => null,
            ]);

        foreach ($winners as $winner) {
            Participant::where('id', $winner['participant_id'])->update([
                'is_winner' => true,
                'winner_place' => $winner['winner_place'],
            ]);
        }

        $this->update(['status' => 'finished']);

        return $winners;
    }
}
