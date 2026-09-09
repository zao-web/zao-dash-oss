<?php

namespace App\Jobs;

use App\Events\VideoProcessingCompleted;
use App\Models\Video;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class ProcessVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Video $video
    ) {}

    public function handle(): void
    {
        try {
            $disk = Storage::disk($this->video->storage_disk);

            // Check if file exists on the storage disk (works for both local and S3)
            if (! $disk->exists($this->video->storage_path)) {
                throw new \Exception("Video file not found: {$this->video->storage_path}");
            }

            // Check if this is cloud storage by driver type (more reliable than disk name)
            $diskConfig = config("filesystems.disks.{$this->video->storage_disk}");
            $isCloudStorage = in_array($diskConfig['driver'] ?? 'local', ['s3', 'r2', 'do', 'sftp', 'ftp']);

            // Get a local file path for ffprobe processing
            $path = null;
            $tempPath = null;

            if ($isCloudStorage) {
                // For cloud storage, download to a temp file for ffprobe
                if ($this->ffmpegAvailable()) {
                    $tempPath = $this->downloadFromCloud($disk);
                    $path = $tempPath;
                }
            } else {
                $localPath = $disk->path($this->video->storage_path);
                if (str_starts_with($localPath, '/') && file_exists($localPath)) {
                    $path = $localPath;
                } else {
                    Log::info('Local path not accessible, skipping FFmpeg processing', [
                        'video_id' => $this->video->id,
                        'disk' => $this->video->storage_disk,
                        'path_returned' => $localPath,
                    ]);
                }
            }

            $updateData = ['status' => 'ready'];

            try {
                // Extract video metadata using ffprobe
                if ($path && $this->ffmpegAvailable()) {
                    $metadata = $this->extractMetadata($path);
                    $updateData['duration'] = $metadata['duration'] ?? null;
                    $updateData['width'] = $metadata['width'] ?? null;
                    $updateData['height'] = $metadata['height'] ?? null;
                    $updateData['recording_metadata'] = $metadata;
                }

                // Generate thumbnail only if not already set
                if ($path && ! $this->video->thumbnail_path && $this->ffmpegAvailable()) {
                    $thumbnailPath = $this->generateThumbnail($path);
                    if ($thumbnailPath) {
                        if ($isCloudStorage) {
                            // Upload thumbnail to cloud storage
                            $thumbRelPath = dirname($this->video->storage_path).'/thumbnails/'.pathinfo($this->video->storage_path, PATHINFO_FILENAME).'.jpg';
                            $disk->put($thumbRelPath, file_get_contents($thumbnailPath));
                            @unlink($thumbnailPath);
                            $updateData['thumbnail_path'] = $thumbRelPath;
                        } else {
                            $updateData['thumbnail_path'] = $thumbnailPath;
                        }
                    }
                }
            } finally {
                // Clean up temp file
                if ($tempPath && file_exists($tempPath)) {
                    @unlink($tempPath);
                }
            }

            $this->video->update($updateData);

            // Broadcast completion event
            event(new VideoProcessingCompleted($this->video->fresh()));

            Log::info('Video processed successfully', [
                'video_id' => $this->video->id,
                'ffmpeg_available' => $this->ffmpegAvailable(),
            ]);

            // Dispatch transcription job if a Whisper API is configured
            if ($this->transcriptionAvailable()) {
                $this->video->update(['transcript_status' => Video::TRANSCRIPT_PENDING]);
                TranscribeVideoJob::dispatch($this->video->fresh())->delay(now()->addSeconds(5));
                Log::info('Transcription job dispatched', ['video_id' => $this->video->id]);
            }

        } catch (\Exception $e) {
            Log::error('Video processing failed', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
            ]);

            // Still mark as ready if file exists - don't fail just because FFmpeg is missing
            $disk = Storage::disk($this->video->storage_disk);
            if ($disk->exists($this->video->storage_path)) {
                $this->video->update(['status' => 'ready']);
                event(new VideoProcessingCompleted($this->video->fresh()));
                Log::info('Video marked ready despite processing error', ['video_id' => $this->video->id]);

                // Still try transcription even if FFmpeg failed
                if ($this->transcriptionAvailable()) {
                    $this->video->update(['transcript_status' => Video::TRANSCRIPT_PENDING]);
                    TranscribeVideoJob::dispatch($this->video->fresh())->delay(now()->addSeconds(5));
                }

                return;
            }

            $this->video->update([
                'status' => 'failed',
                'processing_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Check if FFmpeg tools are available using Symfony Process.
     */
    protected function ffmpegAvailable(): bool
    {
        static $available = null;

        if ($available === null) {
            $ffprobe = config('services.ffmpeg.ffprobe_path', 'ffprobe');
            $process = new Process(['which', $ffprobe]);
            $process->run();
            $available = $process->isSuccessful();
        }

        return $available;
    }

    /**
     * Check if transcription service is configured (OpenAI or Groq).
     */
    protected function transcriptionAvailable(): bool
    {
        $provider = config('services.transcription.provider', 'openai');

        return match ($provider) {
            'groq' => ! empty(config('services.groq.api_key')),
            default => ! empty(config('services.openai.api_key')),
        };
    }

    /**
     * Extract video metadata using ffprobe via Symfony Process.
     */
    protected function extractMetadata(string $path): array
    {
        $ffprobe = config('services.ffmpeg.ffprobe_path', 'ffprobe');

        $process = new Process([
            $ffprobe,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $path,
        ]);

        $process->run();

        if (! $process->isSuccessful()) {
            Log::warning('ffprobe failed for video', [
                'video_id' => $this->video->id,
                'error' => $process->getErrorOutput(),
            ]);

            return [];
        }

        $output = $process->getOutput();
        $data = json_decode($output, true);

        if (! $data) {
            return [];
        }

        $videoStream = collect($data['streams'] ?? [])
            ->firstWhere('codec_type', 'video');

        return [
            'duration' => isset($data['format']['duration'])
                ? (int) round((float) $data['format']['duration'])
                : null,
            'width' => $videoStream['width'] ?? null,
            'height' => $videoStream['height'] ?? null,
            'codec' => $videoStream['codec_name'] ?? null,
            'fps' => $this->parseFps($videoStream['r_frame_rate'] ?? null),
            'bitrate' => isset($data['format']['bit_rate'])
                ? (int) $data['format']['bit_rate']
                : null,
        ];
    }

    /**
     * Parse frame rate from ffprobe format.
     */
    protected function parseFps(?string $frameRate): ?float
    {
        if (! $frameRate || ! str_contains($frameRate, '/')) {
            return null;
        }

        [$num, $den] = explode('/', $frameRate);

        if ((int) $den === 0) {
            return null;
        }

        return round((int) $num / (int) $den, 2);
    }

    /**
     * Generate thumbnail from video using Symfony Process.
     */
    protected function generateThumbnail(string $path): ?string
    {
        $ffmpeg = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');

        $thumbnailFilename = pathinfo($this->video->storage_path, PATHINFO_FILENAME).'.jpg';
        $thumbnailPath = dirname($this->video->storage_path).'/thumbnails/'.$thumbnailFilename;

        $disk = Storage::disk($this->video->storage_disk);
        $absoluteThumbnailPath = $disk->path($thumbnailPath);

        // Ensure directory exists
        $thumbnailDir = dirname($absoluteThumbnailPath);
        if (! is_dir($thumbnailDir)) {
            mkdir($thumbnailDir, 0755, true);
        }

        // Generate thumbnail from first frame (most reliable for short recordings)
        $process = new Process([
            $ffmpeg,
            '-y',
            '-i', $path,
            '-vframes', '1',
            '-vf', 'scale=640:-1,format=yuvj420p',  // Convert to full-range YUV for JPEG
            '-update', '1',  // Required for single image output
            $absoluteThumbnailPath,
        ]);

        $process->run();

        if (file_exists($absoluteThumbnailPath)) {
            return $thumbnailPath;
        }

        Log::warning('Thumbnail generation failed', [
            'video_id' => $this->video->id,
            'error' => $process->getErrorOutput(),
        ]);

        return null;
    }

    /**
     * Download video from cloud storage to a temporary file.
     */
    protected function downloadFromCloud(\Illuminate\Contracts\Filesystem\Filesystem $disk): ?string
    {
        try {
            $extension = pathinfo($this->video->storage_path, PATHINFO_EXTENSION) ?: 'webm';
            $tempPath = sys_get_temp_dir().'/video_'.uniqid().'.'.$extension;

            $stream = $disk->readStream($this->video->storage_path);
            if (! $stream) {
                Log::warning('Could not read stream from cloud storage', [
                    'video_id' => $this->video->id,
                ]);

                return null;
            }

            $tempFile = fopen($tempPath, 'w');
            stream_copy_to_stream($stream, $tempFile);
            fclose($tempFile);
            fclose($stream);

            return $tempPath;
        } catch (\Exception $e) {
            Log::warning('Failed to download video from cloud storage', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
            ]);

            if (isset($tempPath) && file_exists($tempPath)) {
                @unlink($tempPath);
            }

            return null;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        $this->video->update([
            'status' => 'failed',
            'processing_error' => $exception->getMessage(),
        ]);

        Log::error('ProcessVideoJob failed permanently', [
            'video_id' => $this->video->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
