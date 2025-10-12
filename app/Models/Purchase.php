<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Purchase extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'product_type',
        'product_name',
        'credits_granted',
        'price',
        'currency',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'price' => 'decimal:2',
    ];

    /**
     * Get the user that made this purchase
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(AiMusicUser::class, 'user_id');
    }

    /**
     * Scope to filter by product type
     */
    public function scopeSubscriptions($query)
    {
        return $query->where('product_type', 'subscription');
    }

    /**
     * Scope to filter by credit packs
     */
    public function scopeCreditPacks($query)
    {
        return $query->where('product_type', 'credit_pack');
    }
}
