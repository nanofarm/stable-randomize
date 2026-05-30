<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GiveawayMessage extends Model
{
    protected $fillable = ['giveaway_id', 'chat_id', 'message_id'];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }
}
