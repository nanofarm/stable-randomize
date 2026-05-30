<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Channel extends Model
{
    protected $fillable = [
        'owner_id',
        'chat_id',
        'title',
        'username',
        'type',
        'member_count',
        'bot_is_admin',
        'invite_link',
    ];

    protected $casts = [
        'owner_id'     => 'integer',
        'chat_id'      => 'integer',
        'member_count' => 'integer',
        'bot_is_admin' => 'boolean',
    ];

    public function giveaways(): BelongsToMany
    {
        return $this->belongsToMany(Giveaway::class);
    }
}
