<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class XCredential extends Model
{
    use HasFactory;

    protected $table = 'x_credentials';

    public const TYPE_PERSONAL = 'personal';

    public const TYPE_COMPANY = 'company';

    protected $fillable = [
        'user_id',
        'x_user_id',
        'username',
        'account_type', // personal or company
        'name',
        'profile_image_url',
        'description',
        'verified',
        'followers_count',
        'following_count',
        'tweet_count',
        'access_token',
        'refresh_token',
        'token_expires_at',
        'scopes',
        'is_active',
        'last_synced_at',
        'rate_limit_reset_at',
        'initial_sync_complete',
        'sync_pagination_token',
    ];

    protected $casts = [
        'token_expires_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'rate_limit_reset_at' => 'datetime',
        'scopes' => 'array',
        'is_active' => 'boolean',
        'verified' => 'boolean',
        'followers_count' => 'integer',
        'following_count' => 'integer',
        'tweet_count' => 'integer',
        'initial_sync_complete' => 'boolean',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function bookmarks(): HasMany
    {
        return $this->hasMany(XBookmark::class);
    }

    public function isTokenExpired(): bool
    {
        return $this->token_expires_at && $this->token_expires_at->isPast();
    }
}
