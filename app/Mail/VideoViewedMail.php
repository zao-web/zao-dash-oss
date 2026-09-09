<?php

namespace App\Mail;

use App\Models\Video;
use App\Models\VideoView;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class VideoViewedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Video $video,
        public VideoView $videoView
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Someone viewed your video: {$this->video->title}",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.video-viewed',
            with: [
                'video' => $this->video,
                'view' => $this->videoView,
                'videoUrl' => route('videos.index').'?video='.$this->video->uuid,
            ],
        );
    }
}
