<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RestaurantMedia extends Model
{
    protected $table = 'restaurant_media';

    protected $fillable = [
        'public_id', 'restaurant_id', 'type', 'storage_path', 'thumbnail_path',
        'original_name', 'mime_type', 'size_bytes', 'width', 'height',
        'alt_text', 'sort_order', 'is_active', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }
}
