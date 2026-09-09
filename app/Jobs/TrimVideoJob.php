<?php

namespace App\Jobs;

use App\Events\NotificationCreated;
use App\Models\Notification;
use App\Models\Video;
use App\Models\VideoVersion;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class TrimVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 600; // 10 minutes max

    public function __construct(
        public Video $video,
        public VideoVersion $version
    ) {}

    public function handle(): void
    {
        Log::info('TrimVideoJob started', [
            'video_id' => $this->video->id,
            'version_id' => $this->version->id,
            'trim_start' => $this->version->trim_start_seconds,
            'trim_end' => $this->version->trim_end_seconds,
        ]);

        // Update status to processing
        $this->version->update(['status' => VideoVersion::STATUS_PROCESSING]);

        $ffmpegPath = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
        $disk = $this->video->storage_disk;

        // Get source video path (use original storage path)
        $sourcePath = $this->video->original_storage_path ?? $this->video->storage_path;

        // Handle cloud storage - download to temp
        $isCloud = ! in_array($disk, ['local', 'public']);
        $tempInputPath = null;
        $tempOutputPath = null;

        try {
            if ($isCloud) {
                $tempInputPath = sys_get_temp_dir().'/trim_input_'.$this->video->uuid.'_'.uniqid().'.mp4';
                file_put_contents($tempInputPath, Storage::disk($disk)->get($sourcePath));
                $inputPath = $tempInputPath;
            } else {
                $inputPath = Storage::disk($disk)->path($sourcePath);
            }

            // Generate output path
            $extension = pathinfo($sourcePath, PATHINFO_EXTENSION) ?: 'mp4';
            $outputFilename = pathinfo($sourcePath, PATHINFO_FILENAME).'_v'.$this->version->version_number.'.'.$extension;
            $outputStoragePath = dirname($sourcePath).'/'.$outputFilename;

            if ($isCloud) {
                $tempOutputPath = sys_get_temp_dir().'/trim_output_'.$this->video->uuid.'_'.uniqid().'.'.$extension;
                $outputPath = $tempOutputPath;
            } else {
                $outputPath = Storage::disk($disk)->path($outputStoragePath);
            }

            // Build FFmpeg command for frame-accurate trimming with re-encoding
            $startSeconds = number_format($this->version->trim_start_seconds, 3, '.', '');
            $endSeconds = number_format($this->version->trim_end_seconds, 3, '.', '');

            $process = new Process([
                $ffmpegPath,
                '-i', $inputPath,
                '-ss', $startSeconds,
                '-to', $endSeconds,
                '-c:v', 'libx264',
                '-preset', 'fast',
                '-crf', '23',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-movflags', '+faststart',
                '-y',
                $outputPath,
            ]);
            $process->setTimeout(600);

            Log::info('Running FFmpeg trim command', [
                'command' => $process->getCommandLine(),
            ]);

            $process->run();

            if (! $process->isSuccessful()) {
                throw new \Exception('FFmpeg trim failed: '.$process->getErrorOutput());
            }

            // Upload to cloud if needed
            if ($isCloud) {
                Storage::disk($disk)->put($outputStoragePath, file_get_contents($outputPath));
            }

            // Get file info
            $fileSize = $isCloud
                ? Storage::disk($disk)->size($outputStoragePath)
                : filesize($outputPath);

            // Extract metadata from trimmed video
            $metadata = $this->extractMetadata($ffmpegPath, $isCloud ? $tempOutputPath : $outputPath);

            // Update version record
            $this->version->update([
                'storage_path' => $outputStoragePath,
                'file_size' => $fileSize,
                'width' => $metadata['width'] ?? $this->video->width,
                'height' => $metadata['height'] ?? $this->video->height,
                'duration' => $metadata['duration'] ?? $this->version->duration,
                'status' => VideoVersion::STATUS_READY,
                'metadata' => array_merge($this->version->metadata ?? [], [
                    'processed_at' => now()->toISOString(),
                    'ffmpeg_output' => substr($process->getOutput().$process->getErrorOutput(), -1000),
                ]),
            ]);

            // Activate this version as current
            $this->version->activate();

            // Create success notification
            $this->createSuccessNotification();

            Log::info('TrimVideoJob completed successfully', [
                'video_id' => $this->video->id,
                'version_id' => $this->version->id,
                'output_path' => $outputStoragePath,
            ]);

        } catch (\Exception $e) {
            Log::error('TrimVideoJob failed', [
                'video_id' => $this->video->id,
                'version_id' => $this->version->id,
                'error' => $e->getMessage(),
            ]);

            $this->version->update([
                'status' => VideoVersion::STATUS_FAILED,
                'metadata' => array_merge($this->version->metadata ?? [], [
                    'error' => $e->getMessage(),
                    'failed_at' => now()->toISOString(),
                ]),
            ]);

            throw $e;
        } finally {
            // Cleanup temp files
            if ($tempInputPath && file_exists($tempInputPath)) {
                @unlink($tempInputPath);
            }
            if ($tempOutputPath && file_exists($tempOutputPath)) {
                @unlink($tempOutputPath);
            }
        }
    }

    /**
     * Extract video metadata using ffprobe.
     */
    protected function extractMetadata(string $ffmpegPath, string $videoPath): array
    {
        $ffprobePath = str_replace('ffmpeg', 'ffprobe', $ffmpegPath);

        $process = new Process([
            $ffprobePath,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $videoPath,
        ]);
        $process->setTimeout(30);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        $data = json_decode($process->getOutput(), true);
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
        ];
    }

    /**
     * Create success notification.
     */
    protected function createSuccessNotification(): void
    {
        $notification = Notification::create([
            'user_id' => $this->video->user_id,
            'type' => 'video_trim_completed',
            'title' => 'Video trimming complete',
            'message' => "Version {$this->version->version_number} of \"{$this->video->title}\" is ready",
            'icon' => '✅',
            'severity' => 'success',
            'action_url' => route('videos.index').'?video='.$this->video->uuid,
            'action_label' => 'View Video',
            'metadata' => [
                'video_id' => $this->video->id,
                'video_uuid' => $this->video->uuid,
                'version_id' => $this->version->id,
                'version_number' => $this->version->version_number,
                'new_duration' => $this->version->duration,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('TrimVideoJob permanently failed', [
            'video_id' => $this->video->id,
            'version_id' => $this->version->id,
            'error' => $exception->getMessage(),
        ]);

        $this->version->update([
            'status' => VideoVersion::STATUS_FAILED,
        ]);

        // Create failure notification
        $notification = Notification::create([
            'user_id' => $this->video->user_id,
            'type' => 'video_trim_failed',
            'title' => 'Video trimming failed',
            'message' => "Failed to trim \"{$this->video->title}\": ".substr($exception->getMessage(), 0, 100),
            'icon' => '❌',
            'severity' => 'error',
            'action_url' => route('videos.index').'?video='.$this->video->uuid,
            'action_label' => 'View Video',
            'metadata' => [
                'video_id' => $this->video->id,
                'video_uuid' => $this->video->uuid,
                'version_id' => $this->version->id,
                'error' => $exception->getMessage(),
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }
}
