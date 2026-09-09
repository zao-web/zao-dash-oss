<?php

namespace App\Listeners;

use App\Events\ActionItemsExtracted;
use App\Events\NotificationCreated;
use App\Models\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;

class PersistActionItemsNotification implements ShouldQueue
{
    public function handle(ActionItemsExtracted $event): void
    {
        $video = $event->video;
        $count = count($event->actionItems);

        if ($count === 0) {
            return;
        }

        $notification = Notification::create([
            'user_id' => $video->user_id,
            'type' => 'action_items_extracted',
            'title' => "{$count} action item".($count === 1 ? '' : 's').' found',
            'message' => "Extracted from: {$video->title}",
            'icon' => '📋',
            'severity' => 'info',
            'action_url' => route('videos.index').'?video='.$video->uuid,
            'action_label' => 'View Video',
            'metadata' => [
                'video_id' => $video->id,
                'video_uuid' => $video->uuid,
                'action_items_count' => $count,
            ],
        ]);

        broadcast(new NotificationCreated($notification))->toOthers();
    }
}
