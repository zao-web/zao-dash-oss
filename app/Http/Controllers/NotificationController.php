<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $notifications = Notification::forUser($request->user()?->id)
            ->undismissed()
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'message' => $n->message,
                'icon' => $n->icon,
                'severity' => $n->severity,
                'action_url' => $n->action_url,
                'action_label' => $n->action_label,
                'is_read' => ! $n->isUnread(),
                'created_at' => $n->created_at->diffForHumans(),
                'created_at_raw' => $n->created_at->toISOString(),
            ]);

        $unreadCount = Notification::forUser($request->user()?->id)
            ->undismissed()
            ->unread()
            ->count();

        return response()->json([
            'notifications' => $notifications,
            'unread_count' => $unreadCount,
        ]);
    }

    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::forUser($request->user()?->id)
            ->undismissed()
            ->unread()
            ->count();

        return response()->json(['count' => $count]);
    }

    public function markAsRead(Notification $notification): JsonResponse
    {
        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::forUser($request->user()?->id)
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['success' => true]);
    }

    public function dismiss(Notification $notification): JsonResponse
    {
        $notification->dismiss();

        return response()->json(['success' => true]);
    }

    public function dismissAll(Request $request): JsonResponse
    {
        Notification::forUser($request->user()?->id)
            ->undismissed()
            ->update(['dismissed_at' => now()]);

        return response()->json(['success' => true]);
    }
}
