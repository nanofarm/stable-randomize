<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Prize extends Model
{
    protected $fillable = ['giveaway_id', 'place', 'title'];

    protected $casts = ['place' => 'integer'];

    public function giveaway(): BelongsTo
    {
        return $this->belongsTo(Giveaway::class);
    }
}
