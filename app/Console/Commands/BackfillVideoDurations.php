<?php

namespace App\Console\Commands;

use App\Models\Video;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

class BackfillVideoDurations extends Command
{
    protected $signature = 'app:backfill-video-durations {--video= : Specific video UUID to process}';

    protected $description = 'Backfill missing duration/dimensions for videos stored on cloud storage';

    public function handle(): int
    {
        $query = Video::where('status', 'ready')
            ->where(function ($q) {
                $q->whereNull('duration')->orWhere('duration', 0);
            });

        if ($uuid = $this->option('video')) {
            $query->where('uuid', $uuid);
        }

        $videos = $query->get();

        if ($videos->isEmpty()) {
            $this->info('No videos with missing duration found.');

            return self::SUCCESS;
        }

        $this->info("Found {$videos->count()} video(s) with missing duration.");

        $ffprobe = config('services.ffmpeg.ffprobe_path', 'ffprobe');
        $ffprobeCheck = new Process(['which', $ffprobe]);
        $ffprobeCheck->run();

        if (! $ffprobeCheck->isSuccessful()) {
            $this->error('ffprobe is not available. Cannot extract metadata.');

            return self::FAILURE;
        }

        $bar = $this->output->createProgressBar($videos->count());
        $bar->start();

        $updated = 0;
        foreach ($videos as $video) {
            try {
                $disk = Storage::disk($video->storage_disk);

                if (! $disk->exists($video->storage_path)) {
                    $this->newLine();
                    $this->warn("  Skipping {$video->uuid}: file not found");
                    $bar->advance();

                    continue;
                }

                // Download to temp file
                $extension = pathinfo($video->storage_path, PATHINFO_EXTENSION) ?: 'webm';
                $tempPath = sys_get_temp_dir().'/video_backfill_'.uniqid().'.'.$extension;

                $stream = $disk->readStream($video->storage_path);
                $tempFile = fopen($tempPath, 'w');
                stream_copy_to_stream($stream, $tempFile);
                fclose($tempFile);
                fclose($stream);

                // Run ffprobe
                $process = new Process([
                    $ffprobe, '-v', 'quiet', '-print_format', 'json',
                    '-show_format', '-show_streams', $tempPath,
                ]);
                $process->setTimeout(120);
                $process->run();

                @unlink($tempPath);

                if (! $process->isSuccessful()) {
                    $this->newLine();
                    $this->warn("  ffprobe failed for {$video->uuid}");
                    $bar->advance();

                    continue;
                }

                $data = json_decode($process->getOutput(), true);
                if (! $data) {
                    $bar->advance();

                    continue;
                }

                $videoStream = collect($data['streams'] ?? [])->firstWhere('codec_type', 'video');
                $updateData = [];

                if (isset($data['format']['duration'])) {
                    $updateData['duration'] = (int) round((float) $data['format']['duration']);
                }
                if (isset($videoStream['width'])) {
                    $updateData['width'] = $videoStream['width'];
                }
                if (isset($videoStream['height'])) {
                    $updateData['height'] = $videoStream['height'];
                }

                if (! empty($updateData)) {
                    $video->update($updateData);
                    $updated++;
                }
            } catch (\Exception $e) {
                $this->newLine();
                $this->warn("  Error processing {$video->uuid}: {$e->getMessage()}");
                Log::warning('Backfill video duration failed', [
                    'video_id' => $video->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);
        $this->info("Updated {$updated} of {$videos->count()} videos.");

        return self::SUCCESS;
    }
}
