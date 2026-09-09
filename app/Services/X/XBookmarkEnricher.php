<?php

namespace App\Services\X;

use App\Models\XBookmark;
use App\Services\Transcription\WhisperService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Enriches X bookmarks with full content before AI analysis.
 *
 * The X API often returns truncated tweet text. This service:
 * 1. Fetches full tweet text via X's oEmbed API (no auth required)
 * 2. Fetches and summarizes URLs mentioned in tweets
 * 3. Detects and fetches thread context
 *
 * This ensures AI analysis has complete context, not just truncated snippets.
 */
class XBookmarkEnricher
{
    protected const OEMBED_URL = 'https://publish.twitter.com/oembed';

    /**
     * Enrich a bookmark with full content.
     *
     * @return array{full_text: string, url_summaries: array, thread_context: array|null, enrichment_source: string}
     */
    public function enrich(XBookmark $bookmark): array
    {
        $enrichedContent = [
            'full_text' => $bookmark->text,
            'url_summaries' => [],
            'thread_context' => null,
            'enrichment_source' => 'original',
        ];

        $oEmbedResult = $this->fetchOEmbed($bookmark);
        if ($oEmbedResult) {
            $enrichedContent['full_text'] = $oEmbedResult['text'];
            $enrichedContent['enrichment_source'] = 'oembed';
            $enrichedContent['oembed_html'] = $oEmbedResult['html'];
        }

        $urls = $bookmark->urls ?? [];
        if (! empty($urls)) {
            $enrichedContent['url_summaries'] = $this->summarizeUrls($urls);
        }

        if ($this->isReplyTweet($bookmark)) {
            $enrichedContent['thread_context'] = [
                'is_reply' => true,
                'note' => 'This tweet is a reply in a conversation',
            ];
        }

        if ($this->hasVideo($bookmark)) {
            $transcript = $this->transcribeVideo($bookmark);
            if ($transcript) {
                $enrichedContent['video_transcript'] = $transcript;
            }
        }

        return $enrichedContent;
    }

    protected function hasVideo(XBookmark $bookmark): bool
    {
        $media = $bookmark->media ?? [];

        return ! empty($media) || Str::contains($bookmark->text ?? '', 'pic.twitter.com');
    }

    /**
     * @return array{text: string, html: string}|null
     */
    protected function fetchOEmbed(XBookmark $bookmark): ?array
    {
        $tweetUrl = $bookmark->getTweetUrl();

        try {
            $response = Http::timeout(10)
                ->get(self::OEMBED_URL, [
                    'url' => $tweetUrl,
                    'omit_script' => true,
                    'hide_media' => false,
                    'hide_thread' => false,
                ]);

            if (! $response->successful()) {
                Log::warning('oEmbed fetch failed', [
                    'bookmark_id' => $bookmark->id,
                    'tweet_url' => $tweetUrl,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $data = $response->json();
            $html = $data['html'] ?? '';

            $fullText = $this->extractTextFromOEmbed($html);

            if ($fullText && strlen($fullText) > strlen($bookmark->text ?? '')) {
                Log::info('oEmbed provided fuller text', [
                    'bookmark_id' => $bookmark->id,
                    'original_length' => strlen($bookmark->text ?? ''),
                    'enriched_length' => strlen($fullText),
                ]);

                return [
                    'text' => $fullText,
                    'html' => $html,
                ];
            }

            return [
                'text' => $fullText ?: $bookmark->text,
                'html' => $html,
            ];

        } catch (\Exception $e) {
            Log::error('oEmbed fetch error', [
                'bookmark_id' => $bookmark->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function extractTextFromOEmbed(string $html): string
    {
        if (preg_match('/<p[^>]*>(.*?)<\/p>/s', $html, $matches)) {
            $text = $matches[1];
            $text = preg_replace('/<a[^>]*>([^<]*)<\/a>/', '$1', $text);
            $text = strip_tags($text);
            $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $text = preg_replace('/\s+/', ' ', $text);

            return trim($text);
        }

        return '';
    }

    /**
     * Fetch and summarize URLs mentioned in the tweet.
     *
     * @param  array<array{url: string, title: string|null, description: string|null}>  $urls
     * @return array<array{url: string, title: string|null, summary: string|null, fetched: bool}>
     */
    protected function summarizeUrls(array $urls): array
    {
        $summaries = [];

        foreach ($urls as $urlData) {
            $url = $urlData['url'] ?? null;
            if (! $url) {
                continue;
            }

            if ($this->isTwitterUrl($url)) {
                continue;
            }

            $summary = [
                'url' => $url,
                'title' => $urlData['title'] ?? null,
                'summary' => $urlData['description'] ?? null,
                'fetched' => false,
            ];

            if (! empty($summary['title']) && ! empty($summary['summary'])) {
                $summaries[] = $summary;

                continue;
            }

            try {
                $pageData = $this->fetchPageMetadata($url);
                if ($pageData) {
                    $summary['title'] = $summary['title'] ?: $pageData['title'];
                    $summary['summary'] = $summary['summary'] ?: $pageData['description'];
                    $summary['fetched'] = true;
                }
            } catch (\Exception $e) {
                Log::debug('Failed to fetch URL metadata', [
                    'url' => $url,
                    'error' => $e->getMessage(),
                ]);
            }

            $summaries[] = $summary;
        }

        return $summaries;
    }

    /**
     * Fetch basic metadata from a URL (title, description).
     *
     * @return array{title: string|null, description: string|null}|null
     */
    protected function fetchPageMetadata(string $url): ?array
    {
        try {
            $response = Http::timeout(5)
                ->withHeaders([
                    'User-Agent' => 'Mozilla/5.0 (compatible; ZaoDash/1.0)',
                ])
                ->get($url);

            if (! $response->successful()) {
                return null;
            }

            $html = $response->body();

            $title = null;
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $matches)) {
                $title = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $title = Str::limit($title, 200);
            }

            $description = null;
            if (preg_match('/<meta[^>]*name=["\']description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
                $description = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            } elseif (preg_match('/<meta[^>]*content=["\']([^"\']*)["\'][^>]*name=["\']description["\'][^>]*>/is', $html, $matches)) {
                $description = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }

            if (! $description) {
                if (preg_match('/<meta[^>]*property=["\']og:description["\'][^>]*content=["\']([^"\']*)["\'][^>]*>/is', $html, $matches)) {
                    $description = html_entity_decode(trim($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                }
            }

            if ($description) {
                $description = Str::limit($description, 500);
            }

            return [
                'title' => $title,
                'description' => $description,
            ];

        } catch (\Exception $e) {
            return null;
        }
    }

    protected function isTwitterUrl(string $url): bool
    {
        return Str::contains($url, ['twitter.com', 'x.com', 't.co']);
    }

    protected function isReplyTweet(XBookmark $bookmark): bool
    {
        return Str::startsWith($bookmark->text ?? '', '@');
    }

    /**
     * @return array{text: string, language: string}|null
     */
    protected function transcribeVideo(XBookmark $bookmark): ?array
    {
        if (! $this->isYtDlpAvailable()) {
            Log::debug('yt-dlp not available, skipping video transcription', [
                'bookmark_id' => $bookmark->id,
            ]);

            return null;
        }

        $whisper = app(WhisperService::class);
        if (! $whisper->isAvailable()) {
            Log::debug('Whisper not available, skipping video transcription', [
                'bookmark_id' => $bookmark->id,
            ]);

            return null;
        }

        try {
            $videoPath = $this->downloadVideo($bookmark);
            if (! $videoPath) {
                return null;
            }

            $audioPath = $this->extractAudio($videoPath);
            if (! $audioPath) {
                @unlink($videoPath);

                return null;
            }

            $result = $this->transcribeAudio($audioPath);

            @unlink($videoPath);
            @unlink($audioPath);

            if ($result) {
                Log::info('Video transcribed successfully', [
                    'bookmark_id' => $bookmark->id,
                    'text_length' => strlen($result['text']),
                ]);
            }

            return $result;

        } catch (\Exception $e) {
            Log::warning('Video transcription failed', [
                'bookmark_id' => $bookmark->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    protected function isYtDlpAvailable(): bool
    {
        return $this->getYtDlpPath() !== null;
    }

    protected function getYtDlpPath(): ?string
    {
        $paths = [
            'yt-dlp',
            getenv('HOME').'/bin/yt-dlp',
            '/usr/local/bin/yt-dlp',
        ];

        foreach ($paths as $path) {
            $process = new Process([$path, '--version']);
            $process->run();
            if ($process->isSuccessful()) {
                return $path;
            }
        }

        return null;
    }

    protected function downloadVideo(XBookmark $bookmark): ?string
    {
        $ytdlp = $this->getYtDlpPath();
        if (! $ytdlp) {
            return null;
        }

        $tweetUrl = $bookmark->getTweetUrl();
        $outputPath = sys_get_temp_dir().'/'.uniqid('xvideo_').'.mp4';

        $process = new Process([
            $ytdlp,
            '-f', 'best[ext=mp4]/best',
            '-o', $outputPath,
            '--no-playlist',
            '--socket-timeout', '30',
            $tweetUrl,
        ]);

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($outputPath)) {
            Log::debug('yt-dlp download failed', [
                'bookmark_id' => $bookmark->id,
                'error' => $process->getErrorOutput(),
            ]);

            return null;
        }

        return $outputPath;
    }

    protected function extractAudio(string $videoPath): ?string
    {
        $ffmpeg = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
        $audioPath = sys_get_temp_dir().'/'.uniqid('xaudio_').'.flac';

        $process = new Process([
            $ffmpeg,
            '-i', $videoPath,
            '-vn',
            '-ar', '16000',
            '-ac', '1',
            '-c:a', 'flac',
            '-y',
            $audioPath,
        ]);

        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful() || ! file_exists($audioPath)) {
            return null;
        }

        return $audioPath;
    }

    /**
     * @return array{text: string, language: string}|null
     */
    protected function transcribeAudio(string $audioPath): ?array
    {
        $whisper = app(WhisperService::class);

        $fileSize = filesize($audioPath);
        $endpoint = config('services.transcription.provider', 'openai') === 'groq'
            ? 'https://api.groq.com/openai/v1/audio/transcriptions'
            : 'https://api.openai.com/v1/audio/transcriptions';

        $model = config('services.transcription.provider', 'openai') === 'groq'
            ? config('services.transcription.model', 'whisper-large-v3-turbo')
            : 'whisper-1';

        $apiKey = config('services.transcription.provider', 'openai') === 'groq'
            ? config('services.groq.api_key')
            : config('services.openai.api_key');

        $response = Http::timeout(300)
            ->withToken($apiKey)
            ->attach('file', file_get_contents($audioPath), 'audio.flac')
            ->post($endpoint, [
                'model' => $model,
                'response_format' => 'json',
            ]);

        if (! $response->successful()) {
            Log::warning('Whisper API failed for bookmark', [
                'status' => $response->status(),
                'error' => $response->body(),
            ]);

            return null;
        }

        $data = $response->json();

        return [
            'text' => $data['text'] ?? '',
            'language' => $data['language'] ?? 'en',
        ];
    }

    /**
     * Batch enrich multiple bookmarks.
     *
     * @param  \Illuminate\Support\Collection<XBookmark>  $bookmarks
     * @return int Number of bookmarks enriched
     */
    public function enrichBatch($bookmarks, int $delayMs = 200): int
    {
        $enriched = 0;

        foreach ($bookmarks as $bookmark) {
            try {
                $content = $this->enrich($bookmark);
                $bookmark->markAsEnriched($content);
                $enriched++;

                Log::debug('Bookmark enriched', [
                    'bookmark_id' => $bookmark->id,
                    'source' => $content['enrichment_source'],
                    'url_summaries' => count($content['url_summaries']),
                ]);

                if ($delayMs > 0) {
                    usleep($delayMs * 1000);
                }

            } catch (\Exception $e) {
                Log::error('Bookmark enrichment failed', [
                    'bookmark_id' => $bookmark->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $enriched;
    }
}
