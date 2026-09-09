<?php

namespace App\Jobs;

use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Services\LaravelCloudService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreatePreviewEnvironment implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $backoff = 30;

    public function __construct(
        public int $taskId,
        public string $branch,
        public ?string $prUrl = null,
        public ?string $prNumber = null
    ) {}

    public function handle(LaravelCloudService $cloud): void
    {
        if (! $cloud->isConfigured()) {
            Log::info('Laravel Cloud not configured, skipping preview environment', [
                'task_id' => $this->taskId,
            ]);

            return;
        }

        $task = Task::find($this->taskId);
        if (! $task) {
            return;
        }

        $name = 'preview-'.Str::slug(Str::limit($task->title, 30, ''));

        try {
            $result = $cloud->createPreviewEnvironment($this->branch, $name);

            $previewUrl = $result['vanity_domain']
                ? 'https://'.$result['vanity_domain']
                : null;

            $metadata = $task->metadata ?? [];
            $metadata['cloud_environment_id'] = $result['environment_id'];
            $metadata['preview_url'] = $previewUrl;
            $metadata['preview_branch'] = $this->branch;
            $metadata['preview_status'] = $result['status'];
            $metadata['pr_url'] = $this->prUrl;

            $task->update(['metadata' => $metadata]);

            // Trigger a deploy so the environment builds
            if ($result['environment_id']) {
                $deploy = $cloud->deploy($result['environment_id']);
                $metadata['deployment_id'] = $deploy['deployment_id'];
                $task->update(['metadata' => $metadata]);
            }

            // Add a system comment with the preview link
            $commentParts = ["Preview environment created for branch `{$this->branch}`."];

            if ($previewUrl) {
                $commentParts[] = "\n\n**Preview URL:** [{$previewUrl}]({$previewUrl})";
            }

            if ($this->prUrl) {
                $commentParts[] = "\n**Pull Request:** [{$this->prUrl}]({$this->prUrl})";
            }

            $commentParts[] = "\n\n**Testing Instructions:**";
            $commentParts[] = '1. Visit the preview URL above';
            $commentParts[] = '2. Verify the changes match the task requirements';
            $commentParts[] = '3. Check for visual regressions and functionality';
            $commentParts[] = '4. Update the task status when review is complete';

            TaskComment::create([
                'task_id' => $task->id,
                'user_id' => null,
                'type' => TaskComment::TYPE_SYSTEM,
                'content' => implode("\n", $commentParts),
                'metadata' => [
                    'preview_url' => $previewUrl,
                    'cloud_environment_id' => $result['environment_id'],
                    'branch' => $this->branch,
                    'pr_url' => $this->prUrl,
                ],
            ]);

            TaskActivity::logPreviewCreated($task, $previewUrl, $this->branch);

            Log::info('Preview environment created', [
                'task_id' => $this->taskId,
                'environment_id' => $result['environment_id'],
                'preview_url' => $previewUrl,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create preview environment', [
                'task_id' => $this->taskId,
                'branch' => $this->branch,
                'error' => $e->getMessage(),
            ]);

            TaskComment::create([
                'task_id' => $task->id,
                'user_id' => null,
                'type' => TaskComment::TYPE_SYSTEM,
                'content' => "Failed to create preview environment for branch `{$this->branch}`.\n\n**Error:** {$e->getMessage()}",
                'metadata' => [
                    'branch' => $this->branch,
                    'error' => $e->getMessage(),
                ],
            ]);

            throw $e;
        }
    }
}
