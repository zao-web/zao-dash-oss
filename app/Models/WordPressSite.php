<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Crypt;

class WordPressSite extends Model
{
    use HasFactory;

    protected $table = 'wordpress_sites';

    protected $guarded = [];

    protected $casts = [
        'mcp_enabled' => 'boolean',
        'is_primary' => 'boolean',
        'capabilities' => 'array',
        'categories' => 'array',
        'tags' => 'array',
        'last_connected_at' => 'datetime',
        'sync_started_at' => 'datetime',
        'sync_completed_at' => 'datetime',
        'last_synced_at' => 'datetime',
    ];

    protected $hidden = [
        'application_password',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(WordPressPost::class, 'wordpress_site_id');
    }

    public function contentSuggestions(): HasMany
    {
        return $this->hasMany(ContentSuggestion::class, 'wordpress_site_id');
    }

    public function setApplicationPasswordAttribute($value): void
    {
        $this->attributes['application_password'] = Crypt::encryptString($value);
    }

    public function getApplicationPasswordAttribute($value): ?string
    {
        return $value ? Crypt::decryptString($value) : null;
    }

    public function getMcpEndpointAttribute(): string
    {
        return $this->rest_url ?: rtrim($this->url, '/').'/wp-json/mcp/mcp-adapter-default-server';
    }

    public function getAuthHeaderAttribute(): string
    {
        return 'Basic '.base64_encode($this->username.':'.$this->application_password);
    }

    public function scopePrimary($query)
    {
        return $query->where('is_primary', true);
    }
}
