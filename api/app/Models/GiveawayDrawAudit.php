<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayDrawAudit extends Model
{
    protected $fillable = [
        'giveaway_id',
        'algorithm',
        'participant_snapshot_hash',
        'winner_snapshot_hash',
        'participant_snapshot',
        'winner_snapshot',
        'total_participants',
        'total_tickets',
        'draw_nonce',
        'signature',
        'result_token',
        'drawn_at',
    ];

    protected $casts = [
        'participant_snapshot' => 'array',
        'winner_snapshot' => 'array',
        'total_participants' => 'integer',
        'total_tickets' => 'integer',
        'drawn_at' => 'datetime',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }
}
