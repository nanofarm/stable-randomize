<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayTaskSubmission extends Model
{
    protected $fillable = [
        'giveaway_id', 'user_id', 'task_id', 'status',
        'tickets_awarded', 'submitted_at', 'decided_at',
    ];

    protected $casts = [
        'user_id'         => 'integer',
        'task_id'         => 'integer',
        'tickets_awarded' => 'integer',
        'submitted_at'    => 'datetime',
        'decided_at'      => 'datetime',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }
}
