<?php

namespace App\Services\Transcription;

use App\Models\Video;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class WhisperService
{
    protected string $apiKey;

    protected string $provider;

    public function __construct()
    {
        // Prefer OpenAI, fallback to Groq (both offer Whisper)
        $this->provider = config('services.transcription.provider', 'openai');
        $this->apiKey = match ($this->provider) {
            'groq' => config('services.groq.api_key', ''),
            default => config('services.openai.api_key', ''),
        };
    }

    /**
     * Check if transcription service is available.
     */
    public function isAvailable(): bool
    {
        return ! empty($this->apiKey);
    }

    /**
     * Transcribe a video file.
     *
     * @return array{text: string, segments: array, language: string}
     */
    public function transcribe(Video $video): array
    {
        $disk = Storage::disk($video->storage_disk);

        // Check if this is cloud storage by driver type (more reliable than disk name)
        $diskConfig = config("filesystems.disks.{$video->storage_disk}");
        $isCloudStorage = in_array($diskConfig['driver'] ?? 'local', ['s3', 'r2', 'do', 'sftp', 'ftp']);

        // For local storage, verify the path is absolute and file exists
        if (! $isCloudStorage) {
            $localPath = $disk->path($video->storage_path);

            // If path() returns relative path or file doesn't exist, treat as cloud storage
            if (! str_starts_with($localPath, '/') || ! file_exists($localPath)) {
                Log::info('Falling back to cloud download for video', [
                    'video_id' => $video->id,
                    'disk' => $video->storage_disk,
                    'path_returned' => $localPath,
                    'exists' => file_exists($localPath),
                ]);
                $isCloudStorage = true;
            }
        }

        $videoPath = $isCloudStorage
            ? $this->downloadTemporarily($disk, $video->storage_path)
            : $disk->path($video->storage_path);

        Log::info('Transcription video path resolved', [
            'video_id' => $video->id,
            'storage_disk' => $video->storage_disk,
            'storage_path' => $video->storage_path,
            'resolved_path' => $videoPath,
            'is_cloud' => $isCloudStorage,
        ]);

        try {
            // Extract audio from video
            $audioPath = $this->extractAudio($videoPath);

            // Transcribe the audio
            $result = $this->callWhisperApi($audioPath);

            // Clean up audio file
            if (file_exists($audioPath)) {
                unlink($audioPath);
            }

            return $result;
        } finally {
            // Clean up temporary video file if downloaded
            if ($isCloudStorage && file_exists($videoPath)) {
                unlink($videoPath);
            }
        }
    }

    /**
     * Download file temporarily for cloud storage.
     */
    protected function downloadTemporarily($disk, string $path): string
    {
        $tempPath = sys_get_temp_dir().'/'.basename($path);
        file_put_contents($tempPath, $disk->get($path));

        return $tempPath;
    }

    /**
     * Extract audio from video using FFmpeg.
     * Uses FLAC for lossless compression as recommended by Groq.
     */
    protected function extractAudio(string $videoPath): string
    {
        $ffmpeg = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
        $audioPath = sys_get_temp_dir().'/'.uniqid('audio_').'.flac';

        // Convert to 16kHz mono FLAC (optimal for Whisper per Groq docs)
        $process = new Process([
            $ffmpeg,
            '-i', $videoPath,
            '-vn',              // No video
            '-ar', '16000',     // 16kHz sample rate (Whisper downsamples to this anyway)
            '-ac', '1',         // Mono
            '-map', '0:a',      // First audio track only
            '-c:a', 'flac',     // FLAC codec (lossless, good compression)
            '-y',               // Overwrite
            $audioPath,
        ]);

        $process->setTimeout(300); // 5 minute timeout for long videos
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = $process->getErrorOutput();
            Log::error('FFmpeg audio extraction failed', [
                'video' => $videoPath,
                'error' => $errorOutput,
            ]);

            // Provide more context in the exception message
            $errorHint = '';
            if (str_contains($errorOutput, 'Invalid data found')) {
                $errorHint = ' (Invalid data found when processing input)';
            } elseif (str_contains($errorOutput, 'No such file')) {
                $errorHint = ' (No such file or directory)';
            } elseif (str_contains($errorOutput, 'does not contain any stream')) {
                $errorHint = ' (No audio stream found in video)';
            }

            throw new \RuntimeException('Failed to extract audio from video'.$errorHint);
        }

        return $audioPath;
    }

    /**
     * Call Whisper API (OpenAI or Groq).
     *
     * @return array{text: string, segments: array, words: array, language: string}
     */
    protected function callWhisperApi(string $audioPath): array
    {
        $fileSize = filesize($audioPath);

        // Use turbo model by default for Groq (3x cheaper, faster)
        // Set TRANSCRIPTION_MODEL=whisper-large-v3 for higher accuracy
        $groqModel = config('services.transcription.model', 'whisper-large-v3-turbo');

        $endpoint = match ($this->provider) {
            'groq' => 'https://api.groq.com/openai/v1/audio/transcriptions',
            default => 'https://api.openai.com/v1/audio/transcriptions',
        };

        $model = match ($this->provider) {
            'groq' => $groqModel,
            default => 'whisper-1',
        };

        Log::info('Calling Whisper API', [
            'provider' => $this->provider,
            'model' => $model,
            'audio_size' => $fileSize,
        ]);

        // OpenAI/Groq use multipart form data
        $response = Http::timeout(600)
            ->withToken($this->apiKey)
            ->attach('file', file_get_contents($audioPath), 'audio.flac')
            ->post($endpoint, [
                'model' => $model,
                'response_format' => 'verbose_json',
                'timestamp_granularities' => ['word', 'segment'], // Get both word and segment timestamps
                'temperature' => 0, // Recommended for transcription
            ]);

        if (! $response->successful()) {
            Log::error('Whisper API failed', [
                'provider' => $this->provider,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            throw new \RuntimeException("Whisper API request failed ({$this->provider}): ".$response->body());
        }

        $data = $response->json();

        return $this->parseTranscriptionResponse($data);
    }

    /**
     * Parse transcription response into standard format.
     *
     * @return array{text: string, segments: array, words: array, language: string}
     */
    protected function parseTranscriptionResponse(array $data): array
    {
        $segments = [];
        $words = [];

        // Parse segments (sentence-level timestamps)
        if (isset($data['segments'])) {
            $segments = collect($data['segments'])->map(fn ($seg) => [
                'start' => (float) ($seg['start'] ?? 0),
                'end' => (float) ($seg['end'] ?? 0),
                'text' => trim($seg['text'] ?? ''),
            ])->filter(fn ($s) => ! empty($s['text']))->values()->all();
        }

        // Parse words (word-level timestamps for precise navigation)
        if (isset($data['words'])) {
            $words = collect($data['words'])->map(fn ($w) => [
                'start' => (float) ($w['start'] ?? 0),
                'end' => (float) ($w['end'] ?? 0),
                'word' => trim($w['word'] ?? ''),
            ])->filter(fn ($w) => ! empty($w['word']))->values()->all();
        }

        return [
            'text' => $data['text'] ?? '',
            'segments' => $segments,
            'words' => $words,
            'language' => $data['language'] ?? 'en',
        ];
    }

    /**
     * Check FFmpeg availability using Symfony Process.
     */
    public function ffmpegAvailable(): bool
    {
        $ffmpeg = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
        $process = new Process(['which', $ffmpeg]);
        $process->run();

        return $process->isSuccessful();
    }
}
