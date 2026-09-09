<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Services\Video\VideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class VideoController extends Controller
{
    public function __construct(
        protected VideoService $videoService
    ) {}

    /**
     * List user's videos with optional folder filtering.
     */
    public function index(Request $request): JsonResponse
    {
        Log::info('VideoController@index called', [
            'user_id' => Auth::id(),
            'folder' => $request->input('folder'),
            'project_id' => $request->input('project_id'),
            'client_id' => $request->input('client_id'),
            'per_page' => $request->input('per_page', 20),
        ]);

        $query = Video::where('user_id', Auth::id())
            ->with(['project:id,name', 'client:id,name', 'task:id,title'])
            ->orderBy('created_at', 'desc');

        if ($folder = $request->input('folder')) {
            $query->where('folder_path', $folder);
        }

        if ($projectId = $request->input('project_id')) {
            $query->where('project_id', $projectId);
        }

        if ($clientId = $request->input('client_id')) {
            $query->where('client_id', $clientId);
        }

        $videos = $query->paginate($request->input('per_page', 20));

        Log::info('VideoController@index completed', [
            'videos_count' => $videos->count(),
            'total' => $videos->total(),
        ]);

        return response()->json($videos);
    }

    /**
     * Get videos organized by folder structure.
     */
    public function folders(): JsonResponse
    {
        Log::info('VideoController@folders called', ['user_id' => Auth::id()]);

        $data = $this->videoService->getByFolders(Auth::id());

        Log::info('VideoController@folders completed', [
            'folders_count' => count($data),
        ]);

        return response()->json($data);
    }

    /**
     * Upload a video file (single request).
     */
    public function upload(Request $request): JsonResponse
    {
        // Debug: Log what we're receiving (safely)
        $videoFile = $request->file('video');
        Log::info('VideoController@upload started', [
            'user_id' => Auth::id(),
            'has_video' => $request->hasFile('video'),
            'video_valid' => $videoFile?->isValid(),
            'video_size' => $videoFile?->getSize(),
            'video_path' => $videoFile?->getPathname(),
            'video_error' => $videoFile?->getError(),
            'video_mime' => $videoFile?->getMimeType(),
            'video_original_name' => $videoFile?->getClientOriginalName(),
            'all_files' => array_keys($request->allFiles()),
            'content_type' => $request->header('Content-Type'),
            'content_length' => $request->header('Content-Length'),
            'max_file_uploads' => ini_get('max_file_uploads'),
            'upload_max_filesize' => ini_get('upload_max_filesize'),
            'post_max_size' => ini_get('post_max_size'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
        ]);

        try {
            $request->validate([
                'video' => 'required|file|max:512000', // 500MB - mime check relaxed for browser blobs
                'thumbnail' => 'nullable|file|max:5120', // 5MB for thumbnail
                'title' => 'nullable|string|max:255',
                'project_id' => 'nullable|exists:projects,id',
                'client_id' => 'nullable|exists:clients,id',
                'task_id' => 'nullable|exists:tasks,id',
                'folder_path' => 'nullable|string|max:500',
            ]);

            Log::info('VideoController@upload validation passed');

            $video = $this->videoService->upload(
                file: $request->file('video'),
                userId: Auth::id(),
                title: $request->input('title'),
                projectId: $request->input('project_id'),
                clientId: $request->input('client_id'),
                taskId: $request->input('task_id'),
                folderPath: $request->input('folder_path'),
                thumbnailFile: $request->file('thumbnail'),
            );

            Log::info('VideoController@upload completed successfully', [
                'video_id' => $video->id,
                'video_size' => $video->file_size,
                'video_duration' => $video->duration,
            ]);

            return response()->json([
                'video' => $video,
                'share_url' => $this->videoService->getShareUrl($video),
            ], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('VideoController@upload validation failed', [
                'errors' => $e->errors(),
                'video_size' => $videoFile?->getSize(),
                'video_error' => $videoFile?->getError(),
            ]);
            throw $e;
        } catch (\Exception $e) {
            Log::error('VideoController@upload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'video_size' => $videoFile?->getSize(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
            throw $e;
        }
    }

    /**
     * Upload a video file as base64 (for Chrome extension service workers).
     */
    public function uploadBase64(Request $request): JsonResponse
    {
        Log::info('VideoController@uploadBase64 started', [
            'user_id' => Auth::id(),
            'filename' => $request->input('filename'),
            'mime_type' => $request->input('mime_type'),
            'task_id' => $request->input('task_id'),
            'video_data_length' => strlen($request->input('video', '')),
            'thumbnail_data_length' => strlen($request->input('thumbnail', '')),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $request->validate([
                'video' => 'required|string', // base64 data URL
                'thumbnail' => 'nullable|string',
                'filename' => 'required|string|max:255',
                'mime_type' => 'required|string|max:100',
                'task_id' => 'nullable|exists:tasks,id',
            ]);

            Log::info('VideoController@uploadBase64 validation passed');

            // Decode base64 video
            $videoData = $request->input('video');
            if (preg_match('/^data:([^;]+);base64,(.+)$/', $videoData, $matches)) {
                $videoContent = base64_decode($matches[2]);
                Log::info('VideoController@uploadBase64 video decoded', [
                    'decoded_size' => strlen($videoContent),
                    'memory_usage' => memory_get_usage(true),
                ]);
            } else {
                Log::error('VideoController@uploadBase64 invalid video data format');

                return response()->json(['message' => 'Invalid video data format'], 422);
            }

            // Decode thumbnail if present
            $thumbnailContent = null;
            if ($thumbnailData = $request->input('thumbnail')) {
                if (preg_match('/^data:([^;]+);base64,(.+)$/', $thumbnailData, $matches)) {
                    $thumbnailContent = base64_decode($matches[2]);
                    Log::info('VideoController@uploadBase64 thumbnail decoded', [
                        'thumbnail_size' => strlen($thumbnailContent),
                    ]);
                }
            }

            $video = $this->videoService->uploadFromBase64(
                videoContent: $videoContent,
                userId: Auth::id(),
                filename: $request->input('filename'),
                mimeType: $request->input('mime_type'),
                taskId: $request->input('task_id'),
                thumbnailContent: $thumbnailContent,
            );

            Log::info('VideoController@uploadBase64 completed successfully', [
                'video_id' => $video->id,
                'video_size' => $video->file_size,
            ]);

            return response()->json([
                'video' => $video,
                'share_url' => $this->videoService->getShareUrl($video),
            ], 201);

        } catch (\Exception $e) {
            Log::error('VideoController@uploadBase64 failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
            throw $e;
        }
    }

    /**
     * Initialize chunked upload session (for large files from extension).
     */
    public function initUpload(Request $request): JsonResponse
    {
        Log::info('VideoController@initUpload started', [
            'user_id' => Auth::id(),
            'filename' => $request->input('filename'),
            'total_size' => $request->input('total_size'),
            'total_chunks' => $request->input('total_chunks'),
            'all_input' => $request->all(),
        ]);

        try {
            $request->validate([
                'filename' => 'required|string|max:255',
                'total_size' => 'required|integer|min:1',
                'total_chunks' => 'required|integer|min:1',
            ]);

            Log::info('VideoController@initUpload validation passed');

            $result = $this->videoService->initChunkedUpload(
                userId: Auth::id(),
                filename: $request->input('filename'),
                totalSize: $request->input('total_size'),
                totalChunks: $request->input('total_chunks'),
            );

            Log::info('VideoController@initUpload completed successfully', [
                'upload_id' => $result['upload_id'] ?? 'unknown',
            ]);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('VideoController@initUpload failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Upload a single chunk.
     */
    public function uploadChunk(Request $request): JsonResponse
    {
        $uploadId = $request->input('upload_id');
        $chunkIndex = $request->input('chunk_index');

        Log::info('VideoController@uploadChunk started', [
            'upload_id' => $uploadId,
            'chunk_index' => $chunkIndex,
            'has_chunk_file' => $request->hasFile('chunk'),
            'chunk_input_type' => gettype($request->input('chunk')),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $request->validate([
                'upload_id' => 'required|string',
                'chunk_index' => 'required|integer|min:0',
                'chunk' => 'required',
            ]);

            $chunkData = $request->input('chunk');

            // Handle base64 encoded chunk from extension
            if (is_string($chunkData) && preg_match('/^data:/', $chunkData)) {
                $chunkData = base64_decode(preg_replace('/^data:[^;]+;base64,/', '', $chunkData));
                Log::info('VideoController@uploadChunk decoded base64 chunk', [
                    'chunk_size' => strlen($chunkData),
                    'memory_usage' => memory_get_usage(true),
                ]);
            } elseif ($request->hasFile('chunk')) {
                $chunkFile = $request->file('chunk');
                $chunkData = file_get_contents($chunkFile->getRealPath());
                Log::info('VideoController@uploadChunk read file chunk', [
                    'chunk_size' => strlen($chunkData),
                    'file_size' => $chunkFile->getSize(),
                    'memory_usage' => memory_get_usage(true),
                ]);
            }

            $result = $this->videoService->processChunk(
                uploadId: $uploadId,
                chunkIndex: $chunkIndex,
                chunkData: $chunkData,
            );

            Log::info('VideoController@uploadChunk completed successfully', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'result' => $result,
            ]);

            return response()->json($result);

        } catch (\Exception $e) {
            Log::error('VideoController@uploadChunk failed', [
                'upload_id' => $uploadId,
                'chunk_index' => $chunkIndex,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
            throw $e;
        }
    }

    /**
     * Finalize chunked upload.
     */
    public function finalizeUpload(Request $request): JsonResponse
    {
        $uploadId = $request->input('upload_id');

        Log::info('VideoController@finalizeUpload started', [
            'upload_id' => $uploadId,
            'title' => $request->input('title'),
            'project_id' => $request->input('project_id'),
            'client_id' => $request->input('client_id'),
            'task_id' => $request->input('task_id'),
            'has_thumbnail_file' => $request->hasFile('thumbnail'),
            'has_thumbnail_input' => ! empty($request->input('thumbnail')),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $request->validate([
                'upload_id' => 'required|string',
                'title' => 'nullable|string|max:255',
                'project_id' => 'nullable|exists:projects,id',
                'client_id' => 'nullable|exists:clients,id',
                'task_id' => 'nullable|exists:tasks,id',
                'thumbnail' => 'nullable', // Can be file or base64 string
            ]);

            Log::info('VideoController@finalizeUpload validation passed');

            // Handle thumbnail - can be file upload or base64 string
            $thumbnailContent = null;
            if ($request->hasFile('thumbnail')) {
                $thumbnailFile = $request->file('thumbnail');
                $thumbnailContent = file_get_contents($thumbnailFile->getRealPath());
                Log::info('VideoController@finalizeUpload thumbnail from file', [
                    'thumbnail_size' => strlen($thumbnailContent),
                ]);
            } elseif ($thumbnailData = $request->input('thumbnail')) {
                if (is_string($thumbnailData) && preg_match('/^data:([^;]+);base64,(.+)$/', $thumbnailData, $matches)) {
                    $thumbnailContent = base64_decode($matches[2]);
                    Log::info('VideoController@finalizeUpload thumbnail from base64', [
                        'thumbnail_size' => strlen($thumbnailContent),
                    ]);
                }
            }

            $video = $this->videoService->finalizeChunkedUpload(
                uploadId: $uploadId,
                title: $request->input('title'),
                projectId: $request->input('project_id'),
                clientId: $request->input('client_id'),
                taskId: $request->input('task_id'),
                thumbnailContent: $thumbnailContent,
            );

            Log::info('VideoController@finalizeUpload completed successfully', [
                'video_id' => $video->id,
                'video_size' => $video->file_size,
                'video_duration' => $video->duration,
            ]);

            return response()->json([
                'video' => $video,
                'share_url' => $this->videoService->getShareUrl($video),
            ], 201);

        } catch (\Exception $e) {
            Log::error('VideoController@finalizeUpload failed', [
                'upload_id' => $uploadId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
                'memory_peak' => memory_get_peak_usage(true),
            ]);
            throw $e;
        }
    }

    /**
     * Get single video details.
     */
    public function show(Video $video): JsonResponse
    {
        Log::info('VideoController@show called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
        ]);

        $this->authorize('view', $video);

        $video->load(['project:id,name', 'client:id,name', 'task:id,title']);

        return response()->json([
            'video' => $video,
            'share_url' => $this->videoService->getShareUrl($video),
            'stats' => [
                'views' => $video->view_count,
                'unique_views' => $video->unique_view_count,
                'avg_watch_percentage' => $video->views()->avg('watch_percentage') ?? 0,
                'completion_rate' => $video->view_count > 0
                    ? round($video->views()->completed()->count() / $video->view_count * 100)
                    : 0,
            ],
        ]);
    }

    /**
     * Update video metadata.
     */
    public function update(Request $request, Video $video): JsonResponse
    {
        Log::info('VideoController@update called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
            'updates' => $request->only(['title', 'description', 'folder_path', 'is_public', 'share_expires_at']),
        ]);

        $this->authorize('update', $video);

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'description' => 'nullable|string|max:5000',
            'folder_path' => 'nullable|string|max:500',
            'is_public' => 'sometimes|boolean',
            'share_expires_at' => 'nullable|date|after:now',
            'ai_suggested_title' => 'nullable|string|max:255',
            'client_id' => 'nullable|exists:clients,id',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $video->update($request->only([
            'title', 'description', 'folder_path', 'is_public', 'share_expires_at', 'ai_suggested_title', 'client_id', 'project_id',
        ]));

        Log::info('VideoController@update completed', ['video_id' => $video->id]);

        return response()->json(['video' => $video->fresh()]);
    }

    /**
     * Regenerate share token.
     */
    public function regenerateToken(Video $video): JsonResponse
    {
        Log::info('VideoController@regenerateToken called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
        ]);

        $this->authorize('update', $video);

        $video = $this->videoService->regenerateShareToken($video);

        Log::info('VideoController@regenerateToken completed', ['video_id' => $video->id]);

        return response()->json([
            'video' => $video,
            'share_url' => $this->videoService->getShareUrl($video),
        ]);
    }

    /**
     * Set/remove password protection.
     */
    public function setPassword(Request $request, Video $video): JsonResponse
    {
        Log::info('VideoController@setPassword called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
            'has_password' => ! empty($request->input('password')),
        ]);

        $this->authorize('update', $video);

        $request->validate([
            'password' => 'nullable|string|min:4|max:100',
        ]);

        $video = $this->videoService->setPassword($video, $request->input('password'));

        Log::info('VideoController@setPassword completed', [
            'video_id' => $video->id,
            'password_protected' => (bool) $video->password_hash,
        ]);

        return response()->json([
            'video' => $video,
            'password_protected' => (bool) $video->password_hash,
        ]);
    }

    /**
     * Delete video.
     */
    public function destroy(Video $video): JsonResponse
    {
        Log::info('VideoController@destroy called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
        ]);

        $this->authorize('delete', $video);

        $this->videoService->delete($video);

        Log::info('VideoController@destroy completed', ['video_id' => $video->id]);

        return response()->json(['message' => 'Video deleted']);
    }

    /**
     * Get video analytics.
     */
    public function analytics(Video $video): JsonResponse
    {
        Log::info('VideoController@analytics called', [
            'video_id' => $video->id,
            'user_id' => Auth::id(),
        ]);

        $this->authorize('view', $video);

        $views = $video->views()
            ->selectRaw('DATE(started_at) as date, COUNT(*) as views, AVG(watch_percentage) as avg_watch')
            ->groupBy('date')
            ->orderBy('date', 'desc')
            ->limit(30)
            ->get();

        $devices = $video->views()
            ->selectRaw('device_type, COUNT(*) as count')
            ->groupBy('device_type')
            ->get()
            ->pluck('count', 'device_type');

        $topReferrers = $video->views()
            ->selectRaw('referrer, COUNT(*) as count')
            ->whereNotNull('referrer')
            ->groupBy('referrer')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return response()->json([
            'daily_views' => $views,
            'devices' => $devices,
            'top_referrers' => $topReferrers,
            'total_watch_time' => $video->views()->sum('watch_duration'),
            'avg_watch_percentage' => $video->views()->avg('watch_percentage') ?? 0,
        ]);
    }

    /**
     * Get detailed viewer list for a video (owner only).
     */
    public function views(Video $video): JsonResponse
    {
        $this->authorize('view', $video);

        $views = $video->views()
            ->orderBy('started_at', 'desc')
            ->limit(100)
            ->get()
            ->map(fn ($view) => [
                'id' => $view->id,
                'viewer_ip' => $view->viewer_ip,
                'viewer_email' => $view->viewer_email,
                'device_type' => $view->device_type,
                'country' => $view->country,
                'region' => $view->region,
                'city' => $view->city,
                'watch_duration' => $view->watch_duration,
                'watch_percentage' => $view->watch_percentage,
                'completed' => $view->completed,
                'referrer' => $view->referrer,
                'started_at' => $view->started_at?->toISOString(),
                'started_at_human' => $view->started_at?->diffForHumans(),
                'user_agent' => $view->user_agent,
            ]);

        return response()->json(['views' => $views]);
    }

    /**
     * Get user's tasks for linking videos (for extension dropdown).
     */
    public function tasks(Request $request): JsonResponse
    {
        Log::info('VideoController@tasks called', ['user_id' => Auth::id()]);

        $tasks = \App\Models\Task::with(['project:id,name', 'project.client:id,name'])
            ->where('status', '!=', 'completed')
            ->orderBy('updated_at', 'desc')
            ->limit(50)
            ->get(['id', 'title', 'project_id']);

        Log::info('VideoController@tasks completed', ['tasks_count' => $tasks->count()]);

        return response()->json(['tasks' => $tasks]);
    }
}
