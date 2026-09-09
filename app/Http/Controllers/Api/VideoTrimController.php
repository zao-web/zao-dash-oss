<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\VideoVersion;
use App\Services\Video\TrimVideoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VideoTrimController extends Controller
{
    public function __construct(
        protected TrimVideoService $trimService
    ) {}

    /**
     * Get timeline thumbnails for the trim editor.
     */
    public function thumbnails(Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        if (! $video->canTrim()) {
            return response()->json(['error' => 'Video is not ready for trimming'], 400);
        }

        try {
            $thumbnails = $this->trimService->generateThumbnails($video);

            return response()->json([
                'thumbnails' => $thumbnails,
                'duration' => $video->duration,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Failed to generate thumbnails: '.$e->getMessage()], 500);
        }
    }

    /**
     * Queue a trim operation.
     */
    public function trim(Request $request, Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        $validated = $request->validate([
            'start_seconds' => 'required|numeric|min:0',
            'end_seconds' => 'required|numeric|gt:start_seconds',
        ]);

        if (! $video->canTrim()) {
            return response()->json(['error' => 'Video is not ready for trimming'], 400);
        }

        try {
            $version = $this->trimService->trim(
                $video,
                (float) $validated['start_seconds'],
                (float) $validated['end_seconds'],
                $request->user()
            );

            return response()->json([
                'message' => 'Trim operation queued',
                'version' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'status' => $version->status,
                    'duration' => $version->duration,
                    'trim_range' => $version->trim_range,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Get preview parameters (for client-side preview).
     */
    public function preview(Request $request, Video $video): JsonResponse
    {
        $this->authorize('update', $video);

        $validated = $request->validate([
            'start_seconds' => 'required|numeric|min:0',
            'end_seconds' => 'required|numeric|gt:start_seconds',
        ]);

        // Return media fragment URL for HTML5 video preview
        $streamUrl = $video->stream_url;
        $start = (float) $validated['start_seconds'];
        $end = (float) $validated['end_seconds'];

        return response()->json([
            'preview_url' => "{$streamUrl}#t={$start},{$end}",
            'start_seconds' => $start,
            'end_seconds' => $end,
            'duration' => $end - $start,
        ]);
    }

    /**
     * List all versions for a video.
     */
    public function versions(Video $video): JsonResponse
    {
        $this->authorize('view', $video);

        $versions = $video->versions()
            ->orderBy('version_number', 'desc')
            ->get()
            ->map(fn (VideoVersion $v) => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status,
                'is_current' => $v->is_current,
                'is_original' => $v->is_original,
                'duration' => $v->duration,
                'formatted_duration' => $v->formatted_duration,
                'trim_range' => $v->trim_range,
                'trim_start_seconds' => $v->trim_start_seconds,
                'trim_end_seconds' => $v->trim_end_seconds,
                'file_size' => $v->file_size,
                'formatted_file_size' => $v->formatted_file_size,
                'created_at' => $v->created_at?->toISOString(),
                'created_at_human' => $v->created_at?->diffForHumans(),
            ]);

        return response()->json([
            'versions' => $versions,
            'current_version' => $video->current_version,
            'has_versions' => $video->has_versions,
        ]);
    }

    /**
     * Activate a specific version.
     */
    public function activateVersion(Request $request, Video $video, VideoVersion $version): JsonResponse
    {
        $this->authorize('update', $video);

        if ($version->video_id !== $video->id) {
            return response()->json(['error' => 'Version does not belong to this video'], 404);
        }

        try {
            $this->trimService->activateVersion($video, $version);

            return response()->json([
                'message' => 'Version activated',
                'current_version' => $version->version_number,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Delete a version.
     */
    public function deleteVersion(Request $request, Video $video, VideoVersion $version): JsonResponse
    {
        $this->authorize('update', $video);

        if ($version->video_id !== $video->id) {
            return response()->json(['error' => 'Version does not belong to this video'], 404);
        }

        try {
            $this->trimService->deleteVersion($version);

            return response()->json([
                'message' => 'Version deleted',
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }
    }

    /**
     * Stream a specific version.
     */
    public function streamVersion(Request $request, Video $video, VideoVersion $version)
    {
        $this->authorize('view', $video);

        if ($version->video_id !== $video->id) {
            abort(404);
        }

        if ($version->status !== VideoVersion::STATUS_READY) {
            abort(404, 'Version is not ready');
        }

        $videoService = app(\App\Services\Video\VideoService::class);

        return $videoService->streamWithRange(
            $request,
            $version->storage_path,
            $version->storage_disk
        );
    }
}
