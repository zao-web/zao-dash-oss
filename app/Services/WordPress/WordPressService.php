<?php

namespace App\Services\WordPress;

use App\Models\WordPressSite;

/**
 * Alias for WordPressMcpService for backwards compatibility.
 */
class WordPressService extends WordPressMcpService
{
    /**
     * Get media item by ID.
     */
    public function getMedia(WordPressSite $site, int $mediaId): array
    {
        $response = $this->client($site)->get(
            rtrim($site->url, '/')."/wp-json/wp/v2/media/{$mediaId}"
        );

        if (! $response->successful()) {
            throw new \Exception('Failed to get media: '.$response->body());
        }

        return $response->json();
    }
}
