<?php

namespace App\Listeners;

use App\Events\VideoCommentAdded;
use App\Mail\VideoCommentMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

class SendVideoCommentNotification implements ShouldQueue
{
    public function handle(VideoCommentAdded $event): void
    {
        $video = $event->video;
        $owner = $video->user;

        if (! $owner?->email) {
            return;
        }

        // Don't notify if the owner is commenting on their own video
        if ($event->comment->user_id === $owner->id) {
            return;
        }

        Mail::to($owner->email)->send(
            new VideoCommentMail($video, $event->comment)
        );
    }
}
