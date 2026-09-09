<?php

namespace App\Services\Video;

use App\Events\NotificationCreated;
use App\Jobs\TrimVideoJob;
use App\Models\Notification;
use App\Models\User;
use App\Models\Video;
use App\Models\VideoVersion;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class TrimVideoService
{
    /**
     * Queue a video trim operation.
     */
    public function trim(Video $video, float $startSeconds, float $endSeconds, User $user): VideoVersion
    {
        // Validate video can be trimmed
        if (! $video->canTrim()) {
            throw new \Exception('Video is not ready for trimming.');
        }

        // Validate trim points
        $originalDuration = $video->duration ?? 0;
        if ($startSeconds < 0 || $endSeconds <= $startSeconds || $endSeconds > $originalDuration) {
            throw new \Exception('Invalid trim points.');
        }

        // Ensure we have an initial version record
        if (! $video->has_versions) {
            $video->createInitialVersion();
            $video->update(['has_versions' => true]);
        }

        // Calculate new duration
        $newDuration = (int) ceil($endSeconds - $startSeconds);

        // Create pending version record
        $versionNumber = $video->getNextVersionNumber();
        $version = VideoVersion::create([
            'video_id' => $video->id,
            'version_number' => $versionNumber,
            'storage_path' => '', // Will be set by job
            'storage_disk' => $video->storage_disk,
            'duration' => $newDuration,
            'trim_start_seconds' => $startSeconds,
            'trim_end_seconds' => $endSeconds,
            'status' => VideoVersion::STATUS_PENDING,
            'is_current' => false,
            'created_by' => $user->id,
            'metadata' => [
                'original_duration' => $originalDuration,
                'requested_at' => now()->toISOString(),
            ],
        ]);

        // Create notification for trim started
        $this->createStartNotification($video, $version, $user, $newDuration);

        // Dispatch job
        TrimVideoJob::dispatch($video, $version);

        return $version;
    }

    /**
     * Activate a specific version.
     */
    public function activateVersion(Video $video, VideoVersion $version): void
    {
        if ($version->video_id !== $video->id) {
            throw new \Exception('Version does not belong to this video.');
        }

        if ($version->status !== VideoVersion::STATUS_READY) {
            throw new \Exception('Cannot activate a version that is not ready.');
        }

        $version->activate();

        Log::info('Video version activated', [
            'video_id' => $video->id,
            'version_id' => $version->id,
            'version_number' => $version->version_number,
        ]);
    }

    /**
     * Delete a version (cannot delete current or original).
     */
    public function deleteVersion(VideoVersion $version): void
    {
        if ($version->is_current) {
            throw new \Exception('Cannot delete the current active version.');
        }

        if ($version->is_original) {
            throw new \Exception('Cannot delete the original version.');
        }

        // Delete the file
        $version->deleteFile();

        // Delete the record
        $version->delete();

        Log::info('Video version deleted', [
            'video_id' => $version->video_id,
            'version_id' => $version->id,
            'version_number' => $version->version_number,
        ]);
    }

    /**
     * Generate timeline thumbnails for the trim editor.
     */
    public function generateThumbnails(Video $video, int $count = 10): array
    {
        $ffmpegPath = config('services.ffmpeg.ffmpeg_path', 'ffmpeg');
        $storagePath = $video->getActiveStoragePath();
        $disk = $video->getActiveStorageDisk();

        // Get video file path
        $isCloud = ! in_array($disk, ['local', 'public']);
        if ($isCloud) {
            $tempInput = sys_get_temp_dir().'/video_'.$video->uuid.'_'.uniqid().'.mp4';
            file_put_contents($tempInput, Storage::disk($disk)->get($storagePath));
            $videoPath = $tempInput;
        } else {
            $videoPath = Storage::disk($disk)->path($storagePath);
        }

        $duration = $video->duration ?? 60;
        $interval = max(1, (int) floor($duration / $count));

        $thumbnails = [];
        $tempDir = sys_get_temp_dir().'/video_thumbs_'.$video->uuid;
        @mkdir($tempDir, 0755, true);

        try {
            // Generate thumbnails using ffmpeg with Symfony Process
            $process = new Process([
                $ffmpegPath,
                '-i', $videoPath,
                '-vf', "fps=1/{$interval},scale=120:-1",
                '-vframes', (string) $count,
                $tempDir.'/thumb_%d.jpg',
            ]);
            $process->setTimeout(120);
            $process->run();

            // Collect thumbnail data as base64
            for ($i = 1; $i <= $count; $i++) {
                $thumbPath = $tempDir.'/thumb_'.$i.'.jpg';
                if (file_exists($thumbPath)) {
                    $thumbnails[] = [
                        'index' => $i,
                        'time' => ($i - 1) * $interval,
                        'data' => 'data:image/jpeg;base64,'.base64_encode(file_get_contents($thumbPath)),
                    ];
                    @unlink($thumbPath);
                }
            }
        } finally {
            // Cleanup
            @rmdir($tempDir);
            if ($isCloud && isset($tempInput)) {
                @unlink($tempInput);
            }
        }

        return $thumbnails;
    }

    /**
     * Create "trim started" notification.
     */
    protected function createStartNotification(Video $video, VideoVersion $version, User $user, int $newDuration): void
    {
        $formattedDuration = $this->formatDuration($newDuration);

        $notification = Notification::create([
            'user_id' => $user->id,
            'type' => 'video_trim_started',
            'title' => 'Video trimming started',
            'message' => "Trimming \"{$video->title}\" to {$formattedDuration}",
            'icon' => '✂️',
            'severity' => 'info',
            'action_url' => route('videos.index').'?video='.$video->uuid,
            'action_label' => 'View Video',
            'metadata' => [
                'video_id' => $video->id,
                'video_uuid' => $video->uuid,
                'version_id' => $version->id,
                'trim_start' => $version->trim_start_seconds,
                'trim_end' => $version->trim_end_seconds,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }

    /**
     * Format duration in seconds to human readable.
     */
    protected function formatDuration(int $seconds): string
    {
        $hours = floor($seconds / 3600);
        $minutes = floor(($seconds % 3600) / 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
