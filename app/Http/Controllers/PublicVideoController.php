<?php

namespace App\Http\Controllers;

use App\Events\VideoCommentAdded;
use App\Events\VideoViewed;
use App\Models\Video;
use App\Models\VideoComment;
use App\Models\VideoView;
use App\Services\Video\VideoService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class PublicVideoController extends Controller
{
    public function __construct(
        protected VideoService $videoService
    ) {}

    /**
     * Show public video player page.
     */
    public function show(Request $request, string $shareToken)
    {
        $video = Video::where('share_token', $shareToken)
            ->whereIn('status', ['ready', 'processing']) // Allow viewing while processing
            ->firstOrFail();

        // Check if expired
        if ($video->share_expires_at && $video->share_expires_at->isPast()) {
            abort(410, 'This video link has expired');
        }

        // Check if public
        if (! $video->is_public) {
            abort(403, 'This video is not publicly available');
        }

        // Check password protection
        $needsPassword = (bool) $video->password_hash;
        $passwordVerified = $request->session()->get("video_auth:{$video->id}", false);

        if ($needsPassword && ! $passwordVerified) {
            return Inertia::render('Videos/PasswordPrompt', [
                'video' => [
                    'uuid' => $video->uuid,
                    'title' => $video->title,
                    'share_token' => $video->share_token,
                ],
            ]);
        }

        // Share video with Blade view for OG meta tags
        view()->share('video', $video);

        // Check if current user is the video owner
        $user = $request->user();
        $isOwner = $user && $user->id === $video->user_id;

        // Use custom Blade view with OG meta tags for public videos
        return Inertia::render('Videos/Player', [
            'video' => [
                'uuid' => $video->uuid,
                'title' => $video->title,
                'description' => $video->description,
                'duration' => $video->duration,
                'width' => $video->width,
                'height' => $video->height,
                'status' => $video->status,
                'thumbnail_url' => $video->thumbnail_path
                    ? "/v/{$shareToken}/thumbnail"
                    : null,
                'stream_url' => "/v/{$shareToken}/stream",
                'download_url' => "/v/{$shareToken}/download",
                'owner' => $video->user->name ?? 'Unknown',
                'created_at' => $video->created_at->toISOString(),
                'formatted_recorded_at' => $video->formatted_recorded_at,
                'relative_time' => $video->relative_time,
                'absolute_time' => $video->absolute_time,
                'has_transcript' => $video->has_transcript,
                'transcript_status' => $video->transcript_status,
                'transcript' => $video->has_transcript ? $video->transcript : null,
                'transcript_segments' => $video->has_transcript ? $video->transcript_segments : null,
                'ai_summary' => $video->ai_summary,
                'has_versions' => $video->has_versions,
            ],
            'is_owner' => $isOwner,
        ])->rootView('video-share');
    }

    /**
     * Verify video password.
     */
    public function verifyPassword(Request $request, string $shareToken)
    {
        $request->validate([
            'password' => 'required|string',
        ]);

        $video = Video::where('share_token', $shareToken)->firstOrFail();

        if ($this->videoService->verifyPassword($video, $request->input('password'))) {
            $request->session()->put("video_auth:{$video->id}", true);

            return redirect("/v/{$shareToken}");
        }

        return back()->withErrors(['password' => 'Incorrect password']);
    }

    /**
     * Stream video content with range support.
     */
    public function stream(Request $request, string $shareToken)
    {
        $video = Video::with('currentVersion')
            ->where('share_token', $shareToken)
            ->whereIn('status', ['ready', 'processing']) // Allow streaming while processing
            ->firstOrFail();

        // Validate access
        if (! $video->is_public) {
            abort(403);
        }

        if ($video->share_expires_at && $video->share_expires_at->isPast()) {
            abort(410);
        }

        if ($video->password_hash) {
            $verified = $request->session()->get("video_auth:{$video->id}", false);
            if (! $verified) {
                abort(403);
            }
        }

        // Use active version's storage path if versions exist
        return $this->videoService->streamWithRangePath(
            $video->getActiveStoragePath(),
            $video->getActiveStorageDisk(),
            $video->mime_type,
            $request->header('Range')
        );
    }

    /**
     * Download the video file directly to the user's device.
     * Generates a pre-signed download URL on S3/R2, or streams via PHP on local.
     */
    public function download(string $shareToken): \Illuminate\Http\RedirectResponse|\Symfony\Component\HttpFoundation\StreamedResponse
    {
        $video = Video::where('share_token', $shareToken)
            ->where('status', 'ready')
            ->firstOrFail();

        if (! $video->is_public) {
            abort(403);
        }

        if ($video->share_expires_at && $video->share_expires_at->isPast()) {
            abort(410);
        }

        $path = $video->getActiveStoragePath();
        $disk = $video->getActiveStorageDisk();
        $filename = $video->title
            ? \Illuminate\Support\Str::slug($video->title).'.'.pathinfo($path, PATHINFO_EXTENSION)
            : basename($path);

        // Try pre-signed download URL (S3/R2) — fastest, bypasses PHP entirely
        $downloadUrl = $this->videoService->temporaryUrl($path, $disk, 60, download: true);
        if ($downloadUrl) {
            return redirect($downloadUrl, 302);
        }

        // Fallback: stream through PHP for local disk
        return response()->streamDownload(function () use ($path, $disk) {
            $stream = \Illuminate\Support\Facades\Storage::disk($disk)->readStream($path);
            fpassthru($stream);
            fclose($stream);
        }, $filename, ['Content-Type' => $video->mime_type]);
    }

    /**
     * Get video thumbnail.
     */
    public function thumbnail(string $shareToken)
    {
        $video = Video::where('share_token', $shareToken)
            ->whereNotNull('thumbnail_path')
            ->firstOrFail();

        if (! $video->is_public) {
            abort(403);
        }

        $disk = \Illuminate\Support\Facades\Storage::disk($video->storage_disk);

        if (! $disk->exists($video->thumbnail_path)) {
            abort(404);
        }

        return response($disk->get($video->thumbnail_path), 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }

    /**
     * Record view start (called from player).
     */
    public function recordView(Request $request, string $shareToken)
    {
        $video = Video::where('share_token', $shareToken)->firstOrFail();

        // Skip recording if authenticated user is the video owner
        $user = $request->user();
        if ($user && $user->id === $video->user_id) {
            return response()->json(['view_id' => null, 'skipped' => true]);
        }

        $view = VideoView::recordView(
            video: $video,
            ip: $request->ip(),
            email: $request->input('email'),
            userAgent: $request->userAgent(),
            referrer: $request->header('Referer'),
            fingerprint: $request->input('fingerprint'),
        );

        // Broadcast view event to video owner
        event(new VideoViewed($video->fresh(), $view));

        return response()->json([
            'view_id' => $view->id,
        ]);
    }

    /**
     * Update view progress (ping from player).
     */
    public function pingView(Request $request, string $shareToken)
    {
        $request->validate([
            'view_id' => 'required|integer',
            'watch_duration' => 'required|integer|min:0',
        ]);

        $video = Video::where('share_token', $shareToken)->firstOrFail();

        $view = VideoView::where('id', $request->input('view_id'))
            ->where('video_id', $video->id)
            ->firstOrFail();

        $view->updateProgress(
            watchDuration: $request->input('watch_duration'),
            videoDuration: $video->duration ?? 0
        );

        return response()->json(['ok' => true]);
    }

    /**
     * Show minimal embed player for iframe embedding.
     */
    public function embed(Request $request, string $shareToken)
    {
        $video = Video::where('share_token', $shareToken)
            ->where('status', 'ready')
            ->firstOrFail();

        if (! $video->is_public) {
            abort(403, 'Video not available');
        }

        if ($video->share_expires_at && $video->share_expires_at->isPast()) {
            abort(410, 'Video link expired');
        }

        // Password-protected videos can't be embedded
        if ($video->password_hash) {
            abort(403, 'Password-protected videos cannot be embedded');
        }

        return view('video-embed', [
            'video' => $video,
            'autoplay' => $request->boolean('autoplay', false),
            'muted' => $request->boolean('muted', false),
            'loop' => $request->boolean('loop', false),
        ]);
    }

    /**
     * Get approved comments for public video.
     */
    public function comments(string $shareToken)
    {
        $video = $this->findPublicVideo($shareToken);

        $comments = $video->approvedComments()
            ->with(['user:id,name', 'replies' => fn ($q) => $q->approved()->with('user:id,name')])
            ->get()
            ->map(fn ($comment) => $this->formatPublicComment($comment));

        return response()->json(['comments' => $comments]);
    }

    /**
     * Get comment timeline markers for public video.
     */
    public function commentMarkers(string $shareToken)
    {
        $video = $this->findPublicVideo($shareToken);

        $markers = $video->comments()
            ->approved()
            ->whereNotNull('timestamp_seconds')
            ->orderBy('timestamp_seconds')
            ->get(['id', 'timestamp_seconds', 'timestamp_formatted', 'type', 'content'])
            ->map(fn ($c) => [
                'id' => $c->id,
                'seconds' => $c->timestamp_seconds,
                'formatted' => $c->timestamp_formatted,
                'type' => $c->type,
                'preview' => \Illuminate\Support\Str::limit($c->content, 50),
            ]);

        return response()->json(['markers' => $markers]);
    }

    /**
     * Store public comment (requires moderation).
     */
    public function storeComment(Request $request, string $shareToken)
    {
        $video = $this->findPublicVideo($shareToken);

        $validated = $request->validate([
            'content' => 'required|string|max:5000',
            'timestamp_seconds' => 'nullable|integer|min:0',
            'viewer_name' => 'required|string|max:100',
            'viewer_email' => 'nullable|email|max:255',
        ]);

        $timestampFormatted = null;
        if (isset($validated['timestamp_seconds'])) {
            $timestampFormatted = $this->formatTimestamp($validated['timestamp_seconds']);
        }

        $comment = VideoComment::create([
            'video_id' => $video->id,
            'content' => $validated['content'],
            'timestamp_seconds' => $validated['timestamp_seconds'] ?? null,
            'timestamp_formatted' => $timestampFormatted,
            'viewer_name' => $validated['viewer_name'],
            'viewer_email' => $validated['viewer_email'] ?? null,
            'type' => VideoComment::TYPE_COMMENT,
            'is_approved' => false, // Requires moderation
        ]);

        // Notify video owner
        event(new VideoCommentAdded($video->fresh(), $comment));

        return response()->json([
            'message' => 'Comment submitted for approval',
            'pending' => true,
        ], 201);
    }

    /**
     * Find public video by share token with access checks.
     */
    protected function findPublicVideo(string $shareToken): Video
    {
        $video = Video::where('share_token', $shareToken)
            ->where('status', 'ready')
            ->firstOrFail();

        if (! $video->is_public) {
            abort(403, 'Video not available');
        }

        if ($video->share_expires_at && $video->share_expires_at->isPast()) {
            abort(410, 'Video link expired');
        }

        return $video;
    }

    /**
     * Format comment for public API response.
     */
    protected function formatPublicComment(VideoComment $comment): array
    {
        $data = [
            'id' => $comment->id,
            'content' => $comment->content,
            'timestamp_seconds' => $comment->timestamp_seconds,
            'timestamp_formatted' => $comment->timestamp_formatted,
            'commenter_name' => $comment->commenter_name,
            'created_at_human' => $comment->created_at->diffForHumans(),
        ];

        if ($comment->relationLoaded('replies')) {
            $data['replies'] = $comment->replies->map(
                fn ($reply) => $this->formatPublicComment($reply)
            );
        }

        return $data;
    }

    /**
     * Format seconds to timestamp string.
     */
    protected function formatTimestamp(int $seconds): string
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
