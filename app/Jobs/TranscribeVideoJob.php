<?php

namespace App\Jobs;

use App\Events\TranscriptionCompleted;
use App\Models\Video;
use App\Services\Transcription\WhisperService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TranscribeVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 120; // 2 minutes between retries (for model loading)

    public int $timeout = 900; // 15 minutes max

    public function __construct(
        public Video $video
    ) {}

    public function handle(WhisperService $whisperService): void
    {
        // Skip if already transcribed
        if ($this->video->transcript_status === Video::TRANSCRIPT_COMPLETED) {
            Log::info('Video already transcribed', ['video_id' => $this->video->id]);

            return;
        }

        // Skip if service not available
        if (! $whisperService->isAvailable()) {
            Log::warning('Whisper service not configured', ['video_id' => $this->video->id]);
            $this->video->update(['transcript_status' => Video::TRANSCRIPT_FAILED]);

            return;
        }

        // Skip if FFmpeg not available
        if (! $whisperService->ffmpegAvailable()) {
            Log::warning('FFmpeg not available for transcription', ['video_id' => $this->video->id]);
            $this->video->update(['transcript_status' => Video::TRANSCRIPT_FAILED]);

            return;
        }

        try {
            // Mark as processing
            $this->video->update(['transcript_status' => Video::TRANSCRIPT_PROCESSING]);

            Log::info('Starting transcription', ['video_id' => $this->video->id]);

            // Transcribe
            $result = $whisperService->transcribe($this->video);

            // Update video with transcript
            $this->video->update([
                'transcript' => $result['text'],
                'transcript_segments' => $result['segments'],
                'transcript_words' => $result['words'] ?? [],
                'transcript_language' => $result['language'],
                'transcript_status' => Video::TRANSCRIPT_COMPLETED,
            ]);

            Log::info('Transcription completed', [
                'video_id' => $this->video->id,
                'language' => $result['language'],
                'segments' => count($result['segments']),
                'text_length' => strlen($result['text']),
            ]);

            // Broadcast completion event
            event(new TranscriptionCompleted($this->video->fresh()));

            // Dispatch AI analysis job if transcript available
            if (! empty($result['text'])) {
                AnalyzeVideoContentJob::dispatch($this->video->fresh());
            }

        } catch (\Exception $e) {
            Log::error('Transcription failed', [
                'video_id' => $this->video->id,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Check if it's a retryable error (model loading)
            if (str_contains($e->getMessage(), 'Model is loading')) {
                $this->release(120); // Retry in 2 minutes

                return;
            }

            // Mark as failed on last attempt
            if ($this->attempts() >= $this->tries) {
                $this->video->update(['transcript_status' => Video::TRANSCRIPT_FAILED]);
            }

            throw $e;
        }
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        // Get a user-friendly error message
        $userMessage = $this->getUserFriendlyError($exception);

        $this->video->update([
            'transcript_status' => Video::TRANSCRIPT_FAILED,
            'processing_error' => $userMessage,
        ]);

        Log::error('TranscribeVideoJob failed permanently', [
            'video_id' => $this->video->id,
            'error' => $exception->getMessage(),
            'user_message' => $userMessage,
        ]);
    }

    /**
     * Get a user-friendly error message for transcription failures.
     */
    protected function getUserFriendlyError(\Throwable $e): string
    {
        $message = $e->getMessage();

        // FFmpeg errors
        if (str_contains($message, 'Failed to extract audio')) {
            if (str_contains($message, 'Invalid data found')) {
                return 'The video file appears to be corrupted or in an unsupported format. Please try uploading a different recording.';
            }
            if (str_contains($message, 'No such file')) {
                return 'The video file could not be found. Please try uploading again.';
            }

            return 'Could not extract audio from the video. The file may be corrupted or missing an audio track.';
        }

        // Whisper API errors
        if (str_contains($message, 'Whisper API')) {
            if (str_contains($message, 'rate limit') || str_contains($message, '429')) {
                return 'Transcription service is temporarily busy. Please try again in a few minutes.';
            }
            if (str_contains($message, 'too large') || str_contains($message, 'file size')) {
                return 'The video is too long for transcription. Try splitting it into smaller segments.';
            }

            return 'Transcription service error. Please try again later.';
        }

        // Service availability
        if (str_contains($message, 'not configured') || str_contains($message, 'not available')) {
            return 'Transcription service is not currently available.';
        }

        // Default
        return 'Transcription failed. Please try again or contact support if the issue persists.';
    }
}
