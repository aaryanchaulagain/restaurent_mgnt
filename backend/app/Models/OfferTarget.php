<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfferTarget extends Model
{
    public $timestamps = false;

    protected $fillable = ['offer_id', 'target_type', 'target_id'];

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
