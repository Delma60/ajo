<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemAlert extends Model
{
    /** @use HasFactory<\Database\Factories\SystemAlertFactory> */
    use HasFactory;
    protected $fillable = [
        'type',
        'category',
        'title',
        'body',
        'meta',
        'resolved_at',
        'resolved_by',
        'is_read',
    ];

    protected $casts = [
        'meta'        => 'array',
        'is_read'     => 'boolean',
        'resolved_at' => 'datetime',
    ];

    public const TYPE_INFO     = 'info';
    public const TYPE_WARNING  = 'warning';
    public const TYPE_CRITICAL = 'critical';
    public const TYPE_SUCCESS  = 'success';
 
    // category constants
    public const CAT_PAYMENT  = 'payment';
    public const CAT_USER     = 'user';
    public const CAT_GROUP    = 'group';
    public const CAT_SYSTEM   = 'system';
    public const CAT_SECURITY = 'security';
 
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
 
    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
 
    public function scopeOfType($query, string $type)
    {
        return $query->where('type', $type);
    }
 
    public function scopeOfCategory($query, string $category)
    {
        return $query->where('category', $category);
    }
 
    public function isResolved(): bool
    {
        return !is_null($this->resolved_at);
    }
 
    /**
     * Convenience factory: create a system alert.
     */
    public static function raise(
        string $type,
        string $category,
        string $title,
        string $body,
        array $meta = []
    ): self {
        return self::create(compact('type', 'category', 'title', 'body', 'meta'));
    }
}
