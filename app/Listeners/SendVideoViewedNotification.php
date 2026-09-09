<?php

namespace App\Listeners;

use App\Events\VideoViewed;
use App\Mail\VideoViewedMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendVideoViewedNotification implements ShouldQueue
{
    public function handle(VideoViewed $event): void
    {
        $video = $event->video;
        $owner = $video->user;

        if (! $owner?->email) {
            return;
        }

        // Skip if viewer email matches owner (backup check for owner views)
        if ($event->view->viewer_email && $event->view->viewer_email === $owner->email) {
            return;
        }

        Mail::to($owner->email)->send(
            new VideoViewedMail($video, $event->view)
        );
    }
}
