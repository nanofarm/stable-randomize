<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Participant extends Model
{
    protected $fillable = [
        'giveaway_id', 'user_id', 'user_name', 'username', 'is_winner', 'winner_place',
        'language_code', 'is_premium', 'gender', 'birthdate', 'age',
        'ip_address', 'country', 'city',
        'referred_by', 'source', 'source_channel_id',
        'tickets', 'nickname_bonus',
        'user_agent', 'account_created_at',
    ];

    protected $casts = [
        'user_id' => 'integer', 'is_winner' => 'boolean', 'winner_place' => 'integer',
        'is_premium' => 'boolean',
        'referred_by' => 'integer', 'source_channel_id' => 'integer',
        'tickets' => 'integer', 'nickname_bonus' => 'boolean',
        'age' => 'integer', 'birthdate' => 'date', 'account_created_at' => 'datetime',
    ];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }
}
