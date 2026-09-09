<?php

namespace App\Mail;

use App\Models\Video;
use App\Models\VideoComment;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoCommentMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Video $video,
        public VideoComment $comment
    ) {}

    public function envelope(): Envelope
    {
        $action = $this->comment->is_approved ? 'commented on' : 'left a comment pending approval on';

        return new Envelope(
            subject: "{$this->comment->commenter_name} {$action} your video",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.video-comment',
            with: [
                'video' => $this->video,
                'comment' => $this->comment,
                'videoUrl' => route('videos.index').'?video='.$this->video->uuid,
            ],
        );
    }
}
