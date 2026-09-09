<?php

namespace App\Services\Video;

use App\Models\Task;
use App\Models\Video;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VideoService
{
    /**
     * Upload a video file and create the Video record.
     */
    public function upload(
        UploadedFile $file,
        int $userId,
        ?string $title = null,
        ?int $projectId = null,
        ?int $clientId = null,
        ?int $taskId = null,
        ?string $folderPath = null,
        ?UploadedFile $thumbnailFile = null
    ): Video {
        $originalFilename = $file->getClientOriginalName();
        $title = $title ?? pathinfo($originalFilename, PATHINFO_FILENAME);

        // Generate storage path
        $storagePath = sprintf(
            'videos/%d/%s/%s',
            $userId,
            now()->format('Y/m'),
            Str::uuid().'.'.$file->getClientOriginalExtension()
        );

        // Store the file
        $disk = config('filesystems.default', 'local');
        Storage::disk($disk)->put($storagePath, file_get_contents($file->getRealPath()));

        // Store thumbnail if provided (from browser)
        $thumbnailPath = null;
        if ($thumbnailFile) {
            $thumbnailPath = sprintf(
                'videos/%d/%s/thumbnails/%s.jpg',
                $userId,
                now()->format('Y/m'),
                Str::uuid()
            );
            Storage::disk($disk)->put($thumbnailPath, file_get_contents($thumbnailFile->getRealPath()));
        }

        // Auto-generate folder path from task context if not provided
        if (! $folderPath && $taskId) {
            $task = Task::with(['project.client'])->find($taskId);
            if ($task) {
                $folderPath = $this->buildFolderPathFromTask($task);
                $title = $task->title;
            }
        }

        // Create the video record
        $video = Video::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $userId,
            'project_id' => $projectId,
            'client_id' => $clientId,
            'task_id' => $taskId,
            'title' => $title,
            'folder_path' => $folderPath,
            'original_filename' => $originalFilename,
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'thumbnail_path' => $thumbnailPath,
            'file_size' => $file->getSize(),
            'mime_type' => $file->getMimeType(),
            'share_token' => Str::random(32),
            'status' => 'processing',
        ]);

        // Dispatch processing job
        dispatch(new \App\Jobs\ProcessVideoJob($video));

        return $video;
    }

    /**
     * Build folder path from task relationships.
     */
    protected function buildFolderPathFromTask(Task $task): ?string
    {
        $parts = [];

        if ($task->project?->client) {
            $parts[] = $task->project->client->name;
        }

        if ($task->project) {
            $parts[] = $task->project->name;
        }

        return empty($parts) ? null : implode('/', $parts);
    }

    /**
     * Upload video from base64 content (for Chrome extension).
     */
    public function uploadFromBase64(
        string $videoContent,
        int $userId,
        string $filename,
        string $mimeType,
        ?int $taskId = null,
        ?string $thumbnailContent = null
    ): Video {
        $title = pathinfo($filename, PATHINFO_FILENAME);

        // Generate storage path
        $extension = pathinfo($filename, PATHINFO_EXTENSION) ?: 'webm';
        $storagePath = sprintf(
            'videos/%d/%s/%s.%s',
            $userId,
            now()->format('Y/m'),
            Str::uuid(),
            $extension
        );

        // Store the video
        $disk = config('filesystems.default', 'local');
        Storage::disk($disk)->put($storagePath, $videoContent);

        // Store thumbnail if provided
        $thumbnailPath = null;
        if ($thumbnailContent) {
            $thumbnailPath = sprintf(
                'videos/%d/%s/thumbnails/%s.jpg',
                $userId,
                now()->format('Y/m'),
                Str::uuid()
            );
            Storage::disk($disk)->put($thumbnailPath, $thumbnailContent);
        }

        // Auto-generate folder path from task
        $folderPath = null;
        if ($taskId) {
            $task = Task::with(['project.client'])->find($taskId);
            if ($task) {
                $folderPath = $this->buildFolderPathFromTask($task);
                $title = $task->title;
            }
        }

        // Create the video record
        $video = Video::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $userId,
            'task_id' => $taskId,
            'title' => $title,
            'folder_path' => $folderPath,
            'original_filename' => $filename,
            'storage_path' => $storagePath,
            'storage_disk' => $disk,
            'thumbnail_path' => $thumbnailPath,
            'file_size' => strlen($videoContent),
            'mime_type' => $mimeType,
            'share_token' => Str::random(32),
            'status' => 'processing',
        ]);

        // Dispatch processing job
        dispatch(new \App\Jobs\ProcessVideoJob($video));

        return $video;
    }

    /**
     * Initialize a chunked upload session.
     */
    public function initChunkedUpload(
        int $userId,
        string $filename,
        int $totalSize,
        int $totalChunks
    ): array {
        $uploadId = Str::uuid()->toString();
        $tempPath = "temp/uploads/{$uploadId}";

        // Store upload metadata in cache
        cache()->put("video_upload:{$uploadId}", [
            'user_id' => $userId,
            'filename' => $filename,
            'total_size' => $totalSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => [],
            'temp_path' => $tempPath,
            'started_at' => now()->toISOString(),
        ], now()->addHours(24));

        return [
            'upload_id' => $uploadId,
            'chunk_size' => 5 * 1024 * 1024, // 5MB chunks
        ];
    }

    /**
     * Process a single chunk of a chunked upload.
     */
    public function processChunk(
        string $uploadId,
        int $chunkIndex,
        string $chunkData
    ): array {
        $metadata = cache()->get("video_upload:{$uploadId}");

        if (! $metadata) {
            throw new \Exception('Upload session not found or expired');
        }

        // Store chunk
        $chunkPath = "{$metadata['temp_path']}/chunk_{$chunkIndex}";
        Storage::disk('local')->put($chunkPath, $chunkData);

        // Update received chunks
        $metadata['received_chunks'][] = $chunkIndex;
        cache()->put("video_upload:{$uploadId}", $metadata, now()->addHours(24));

        $isComplete = count($metadata['received_chunks']) >= $metadata['total_chunks'];

        return [
            'chunk_index' => $chunkIndex,
            'received' => count($metadata['received_chunks']),
            'total' => $metadata['total_chunks'],
            'complete' => $isComplete,
        ];
    }

    /**
     * Finalize a chunked upload by combining chunks.
     */
    public function finalizeChunkedUpload(
        string $uploadId,
        ?string $title = null,
        ?int $projectId = null,
        ?int $clientId = null,
        ?int $taskId = null,
        ?string $thumbnailContent = null
    ): Video {
        $metadata = cache()->get("video_upload:{$uploadId}");

        if (! $metadata) {
            throw new \Exception('Upload session not found or expired');
        }

        // Combine chunks into final file
        $finalPath = sprintf(
            'videos/%d/%s/%s',
            $metadata['user_id'],
            now()->format('Y/m'),
            Str::uuid().'.'.pathinfo($metadata['filename'], PATHINFO_EXTENSION)
        );

        $disk = config('filesystems.default', 'local');

        // Stream chunks into a temp file to avoid loading the entire video into memory
        $tmpFile = tmpfile();
        sort($metadata['received_chunks']);
        foreach ($metadata['received_chunks'] as $index) {
            $chunkPath = "{$metadata['temp_path']}/chunk_{$index}";
            $chunkStream = Storage::disk('local')->readStream($chunkPath);
            if ($chunkStream) {
                stream_copy_to_stream($chunkStream, $tmpFile);
                fclose($chunkStream);
            }
        }

        rewind($tmpFile);
        Storage::disk($disk)->put($finalPath, $tmpFile);
        fclose($tmpFile);

        // Clean up temp chunks
        Storage::disk('local')->deleteDirectory($metadata['temp_path']);
        cache()->forget("video_upload:{$uploadId}");

        // Auto-generate folder path from task
        $folderPath = null;
        $autoTitle = $title ?? pathinfo($metadata['filename'], PATHINFO_FILENAME);

        if ($taskId) {
            $task = Task::with(['project.client'])->find($taskId);
            if ($task) {
                $folderPath = $this->buildFolderPathFromTask($task);
                $autoTitle = $title ?? $task->title;
            }
        }

        // Store thumbnail if provided
        $thumbnailPath = null;
        if ($thumbnailContent) {
            $thumbnailPath = sprintf(
                'videos/%d/%s/thumbnails/%s.jpg',
                $metadata['user_id'],
                now()->format('Y/m'),
                Str::uuid()
            );
            Storage::disk($disk)->put($thumbnailPath, $thumbnailContent);
        }

        // Create video record
        $video = Video::create([
            'uuid' => Str::uuid()->toString(),
            'user_id' => $metadata['user_id'],
            'project_id' => $projectId,
            'client_id' => $clientId,
            'task_id' => $taskId,
            'title' => $autoTitle,
            'folder_path' => $folderPath,
            'original_filename' => $metadata['filename'],
            'storage_path' => $finalPath,
            'storage_disk' => $disk,
            'thumbnail_path' => $thumbnailPath,
            'file_size' => $metadata['total_size'],
            'mime_type' => $this->getMimeType($metadata['filename']),
            'share_token' => Str::random(32),
            'status' => 'processing',
        ]);

        dispatch(new \App\Jobs\ProcessVideoJob($video));

        return $video;
    }

    /**
     * Get video stream response for playback.
     */
    public function stream(Video $video): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $disk = Storage::disk($video->storage_disk);
        $path = $video->storage_path;

        if (! $disk->exists($path)) {
            abort(404, 'Video not found');
        }

        $size = $disk->size($path);
        $mimeType = $video->mime_type;

        return response()->stream(function () use ($disk, $path) {
            $stream = $disk->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $mimeType,
            'Content-Length' => $size,
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Get video stream with range support for seeking.
     */
    public function streamWithRange(Video $video, ?string $range = null): \Symfony\Component\HttpFoundation\Response
    {
        $disk = Storage::disk($video->storage_disk);
        $path = $video->storage_path;

        if (! $disk->exists($path)) {
            abort(404, 'Video not found');
        }

        $size = $disk->size($path);
        $mimeType = $video->mime_type;

        // No range requested - return full file
        if (! $range) {
            return $this->stream($video);
        }

        // Parse range header
        preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $size - 1;

        $length = $end - $start + 1;

        return response()->stream(function () use ($disk, $path, $start, $length) {
            $stream = $disk->readStream($path);
            fseek($stream, $start);
            $remaining = $length;
            $chunkSize = 1024 * 512;
            while ($remaining > 0 && ! feof($stream)) {
                $read = fread($stream, min($chunkSize, $remaining));
                if ($read === false) {
                    break;
                }
                echo $read;
                flush();
                $remaining -= strlen($read);
            }
            fclose($stream);
        }, 206, [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Generate a temporary pre-signed URL for direct streaming/download from cloud storage.
     * Returns null when the disk doesn't support temporary URLs (e.g. local driver in dev).
     */
    public function temporaryUrl(string $path, string $diskName, int $minutes = 60, bool $download = false): ?string
    {
        try {
            $options = [];
            if ($download) {
                $filename = basename($path);
                $options['ResponseContentDisposition'] = "attachment; filename=\"{$filename}\"";
            }

            return Storage::disk($diskName)->temporaryUrl($path, now()->addMinutes($minutes), $options);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get video stream with range support using explicit path parameters.
     * Used for serving specific versions of a video.
     *
     * When the disk supports temporary URLs (S3/R2 in production), redirects
     * directly to cloud storage — no PHP proxying, no buffering artifacts.
     */
    public function streamWithRangePath(string $path, string $diskName, string $mimeType, ?string $range = null): \Symfony\Component\HttpFoundation\Response
    {
        // Redirect to pre-signed URL when available — browser streams direct from S3/R2
        $signedUrl = $this->temporaryUrl($path, $diskName, 60);
        if ($signedUrl) {
            return redirect($signedUrl, 302);
        }

        $disk = Storage::disk($diskName);

        if (! $disk->exists($path)) {
            abort(404, 'Video not found');
        }

        $size = $disk->size($path);

        // No range requested - stream full file
        if (! $range) {
            return response()->stream(function () use ($disk, $path) {
                $stream = $disk->readStream($path);
                fpassthru($stream);
                fclose($stream);
            }, 200, [
                'Content-Type' => $mimeType,
                'Content-Length' => $size,
                'Accept-Ranges' => 'bytes',
            ]);
        }

        // Parse range header
        preg_match('/bytes=(\d+)-(\d*)/', $range, $matches);
        $start = (int) $matches[1];
        $end = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : $size - 1;

        $length = $end - $start + 1;

        return response()->stream(function () use ($disk, $path, $start, $length) {
            $stream = $disk->readStream($path);
            fseek($stream, $start);
            $remaining = $length;
            $chunkSize = 1024 * 512;
            while ($remaining > 0 && ! feof($stream)) {
                $read = fread($stream, min($chunkSize, $remaining));
                if ($read === false) {
                    break;
                }
                echo $read;
                flush();
                $remaining -= strlen($read);
            }
            fclose($stream);
        }, 206, [
            'Content-Type' => $mimeType,
            'Content-Length' => $length,
            'Content-Range' => "bytes {$start}-{$end}/{$size}",
            'Accept-Ranges' => 'bytes',
        ]);
    }

    /**
     * Generate a shareable URL for a video.
     */
    public function getShareUrl(Video $video): string
    {
        return url("/v/{$video->share_token}");
    }

    /**
     * Regenerate share token for a video.
     */
    public function regenerateShareToken(Video $video): Video
    {
        $video->update([
            'share_token' => Str::random(32),
        ]);

        return $video->fresh();
    }

    /**
     * Set password protection on a video.
     */
    public function setPassword(Video $video, ?string $password): Video
    {
        $video->update([
            'password_hash' => $password ? bcrypt($password) : null,
        ]);

        return $video->fresh();
    }

    /**
     * Verify video password.
     */
    public function verifyPassword(Video $video, string $password): bool
    {
        if (! $video->password_hash) {
            return true;
        }

        return password_verify($password, $video->password_hash);
    }

    /**
     * Delete a video and its associated files.
     */
    public function delete(Video $video): bool
    {
        // Delete video file
        Storage::disk($video->storage_disk)->delete($video->storage_path);

        // Delete thumbnail if exists
        if ($video->thumbnail_path) {
            Storage::disk($video->storage_disk)->delete($video->thumbnail_path);
        }

        return $video->delete();
    }

    /**
     * Get videos organized by folder structure.
     */
    public function getByFolders(int $userId): array
    {
        $videos = Video::where('user_id', $userId)
            ->orderBy('folder_path')
            ->orderBy('created_at', 'desc')
            ->get();

        $folders = [];
        $root = [];

        foreach ($videos as $video) {
            if ($video->folder_path) {
                $path = $video->folder_path;
                if (! isset($folders[$path])) {
                    $folders[$path] = [];
                }
                $folders[$path][] = $video;
            } else {
                $root[] = $video;
            }
        }

        return [
            'folders' => $folders,
            'root' => $root,
        ];
    }

    /**
     * Get MIME type from filename.
     */
    protected function getMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mov' => 'video/quicktime',
            'avi' => 'video/x-msvideo',
            'mkv' => 'video/x-matroska',
            default => 'video/mp4',
        };
    }
}
