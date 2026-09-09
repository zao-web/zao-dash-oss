<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Stevebauman\Location\Facades\Location;

class VideoView extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'video_id',
        'viewer_email',
        'viewer_ip',
        'viewer_fingerprint',
        'user_agent',
        'watch_duration',
        'watch_percentage',
        'completed',
        'referrer',
        'country',
        'region',
        'city',
        'device_type',
        'started_at',
        'last_ping_at',
    ];

    protected $casts = [
        'watch_duration' => 'integer',
        'watch_percentage' => 'integer',
        'completed' => 'boolean',
        'started_at' => 'datetime',
        'last_ping_at' => 'datetime',
    ];

    // Relationships

    public function video(): BelongsTo
    {
        return $this->belongsTo(Video::class);
    }

    // Scopes

    public function scopeCompleted($query)
    {
        return $query->where('completed', true);
    }

    public function scopeForVideo($query, int $videoId)
    {
        return $query->where('video_id', $videoId);
    }

    public function scopeByEmail($query, string $email)
    {
        return $query->where('viewer_email', $email);
    }

    public function scopeRecent($query, int $days = 30)
    {
        return $query->where('started_at', '>=', now()->subDays($days));
    }

    // Methods

    /**
     * Update watch progress from a ping.
     */
    public function updateProgress(int $watchDuration, int $videoDuration): void
    {
        $this->watch_duration = $watchDuration;
        $this->watch_percentage = $videoDuration > 0
            ? min(100, (int) round(($watchDuration / $videoDuration) * 100))
            : 0;
        $this->completed = $this->watch_percentage >= 90;
        $this->last_ping_at = now();
        $this->save();
    }

    /**
     * Parse device type from user agent.
     */
    public static function parseDeviceType(?string $userAgent): string
    {
        if (! $userAgent) {
            return 'unknown';
        }

        $userAgent = strtolower($userAgent);

        if (preg_match('/mobile|android|iphone|ipad|ipod|blackberry|windows phone/i', $userAgent)) {
            if (preg_match('/tablet|ipad/i', $userAgent)) {
                return 'tablet';
            }

            return 'mobile';
        }

        return 'desktop';
    }

    /**
     * Create a new view record for a video.
     */
    public static function recordView(
        Video $video,
        string $ip,
        ?string $email = null,
        ?string $userAgent = null,
        ?string $referrer = null,
        ?string $fingerprint = null
    ): self {
        // Check if this is a new unique viewer BEFORE creating the view
        $isNewUniqueViewer = ! self::where('video_id', $video->id)
            ->where('viewer_ip', $ip)
            ->exists();

        // Get location from IP
        $location = self::getLocationFromIp($ip);

        $view = self::create([
            'video_id' => $video->id,
            'viewer_email' => $email,
            'viewer_ip' => $ip,
            'viewer_fingerprint' => $fingerprint,
            'user_agent' => $userAgent,
            'referrer' => $referrer,
            'device_type' => self::parseDeviceType($userAgent),
            'country' => $location['country'] ?? null,
            'region' => $location['region'] ?? null,
            'city' => $location['city'] ?? null,
            'started_at' => now(),
        ]);

        // Increment video view count
        $video->incrementViewCount($isNewUniqueViewer);

        return $view;
    }

    /**
     * Get location details from IP address.
     */
    protected static function getLocationFromIp(string $ip): array
    {
        try {
            $position = Location::get($ip);

            if ($position) {
                return [
                    'country' => $position->countryName,
                    'region' => $position->regionName,
                    'city' => $position->cityName,
                ];
            }
        } catch (\Exception $e) {
            // Silently fail - location is optional
            \Illuminate\Support\Facades\Log::debug('IP location lookup failed', [
                'ip' => $ip,
                'error' => $e->getMessage(),
            ]);
        }

        return [];
    }
}
