<?php

namespace App\Http\Controllers\Api;

use App\Events\VideoCommentAdded;
use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\VideoComment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VideoCommentController extends Controller
{
    /**
     * Get comments for a video (authenticated users see all, public sees approved only).
     */
    public function index(Video $video): JsonResponse
    {
        $user = Auth::user();
        $isOwner = $user && $video->user_id === $user->id;

        $query = $video->comments()
            ->topLevel()
            ->with(['user:id,name,email', 'replies.user:id,name,email'])
            ->orderBy('timestamp_seconds')
            ->orderBy('created_at');

        // Non-owners only see approved comments
        if (! $isOwner) {
            $query->approved();
        }

        $comments = $query->get()->map(fn ($comment) => $this->formatComment($comment, $isOwner));

        return response()->json([
            'comments' => $comments,
            'can_moderate' => $isOwner,
        ]);
    }

    /**
     * Get pending comments for moderation (owner only).
     */
    public function pending(Video $video): JsonResponse
    {
        $user = Auth::user();
        if (! $user || $video->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $comments = $video->pendingComments()
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($comment) => $this->formatComment($comment, true));

        return response()->json(['comments' => $comments]);
    }

    /**
     * Store a new comment (authenticated or public).
     */
    public function store(Request $request, Video $video): JsonResponse
    {
        $user = Auth::user();
        $isOwner = $user && $video->user_id === $user->id;

        $rules = [
            'content' => 'required|string|max:5000',
            'timestamp_seconds' => 'nullable|integer|min:0',
            'parent_id' => 'nullable|exists:video_comments,id',
            'type' => 'nullable|in:comment,marker,reaction',
        ];

        // Public commenters need name/email
        if (! $user) {
            $rules['viewer_name'] = 'required|string|max:100';
            $rules['viewer_email'] = 'nullable|email|max:255';
        }

        $validated = $request->validate($rules);

        // Format timestamp if provided
        $timestampFormatted = null;
        if (isset($validated['timestamp_seconds'])) {
            $timestampFormatted = $this->formatTimestamp($validated['timestamp_seconds']);
        }

        $comment = VideoComment::create([
            'video_id' => $video->id,
            'user_id' => $user?->id,
            'parent_id' => $validated['parent_id'] ?? null,
            'content' => $validated['content'],
            'timestamp_seconds' => $validated['timestamp_seconds'] ?? null,
            'timestamp_formatted' => $timestampFormatted,
            'viewer_name' => $validated['viewer_name'] ?? null,
            'viewer_email' => $validated['viewer_email'] ?? null,
            'type' => $validated['type'] ?? VideoComment::TYPE_COMMENT,
            // Auto-approve for authenticated users or video owner
            'is_approved' => $user !== null,
            'approved_by' => $user ? $user->id : null,
            'approved_at' => $user ? now() : null,
        ]);

        $comment->load('user:id,name,email');

        // Broadcast to video owner
        event(new VideoCommentAdded($video->fresh(), $comment));

        return response()->json([
            'comment' => $this->formatComment($comment, $isOwner),
            'message' => $user
                ? 'Comment added successfully'
                : 'Comment submitted for approval',
        ], 201);
    }

    /**
     * Approve a pending comment (owner only).
     */
    public function approve(Request $request, Video $video, VideoComment $comment): JsonResponse
    {
        $user = Auth::user();
        if (! $user || $video->user_id !== $user->id) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($comment->video_id !== $video->id) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        $comment->approve($user);

        return response()->json([
            'comment' => $this->formatComment($comment->fresh(), true),
            'message' => 'Comment approved',
        ]);
    }

    /**
     * Reject/delete a comment (owner only).
     */
    public function destroy(Request $request, Video $video, VideoComment $comment): JsonResponse
    {
        $user = Auth::user();
        $isOwner = $user && $video->user_id === $user->id;
        $isAuthor = $user && $comment->user_id === $user->id;

        if (! $isOwner && ! $isAuthor) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        if ($comment->video_id !== $video->id) {
            return response()->json(['error' => 'Comment not found'], 404);
        }

        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    /**
     * Get timeline markers for video scrubber.
     */
    public function markers(Video $video): JsonResponse
    {
        $user = Auth::user();
        $isOwner = $user && $video->user_id === $user->id;

        $query = $video->comments()
            ->whereNotNull('timestamp_seconds')
            ->orderBy('timestamp_seconds');

        if (! $isOwner) {
            $query->approved();
        }

        $markers = $query->get(['id', 'timestamp_seconds', 'timestamp_formatted', 'type', 'content'])
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
     * Format comment for API response.
     */
    protected function formatComment(VideoComment $comment, bool $showModeration = false): array
    {
        $data = [
            'id' => $comment->id,
            'content' => $comment->content,
            'timestamp_seconds' => $comment->timestamp_seconds,
            'timestamp_formatted' => $comment->timestamp_formatted,
            'type' => $comment->type,
            'commenter_name' => $comment->commenter_name,
            'is_authenticated' => $comment->is_authenticated,
            'created_at' => $comment->created_at->toIso8601String(),
            'created_at_human' => $comment->created_at->diffForHumans(),
        ];

        if ($comment->user) {
            $data['user'] = [
                'id' => $comment->user->id,
                'name' => $comment->user->name,
            ];
        }

        if ($showModeration) {
            $data['is_approved'] = $comment->is_approved;
            $data['approved_at'] = $comment->approved_at?->toIso8601String();
            $data['viewer_email'] = $comment->viewer_email;
        }

        // Include replies if loaded
        if ($comment->relationLoaded('replies')) {
            $data['replies'] = $comment->replies->map(
                fn ($reply) => $this->formatComment($reply, $showModeration)
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
