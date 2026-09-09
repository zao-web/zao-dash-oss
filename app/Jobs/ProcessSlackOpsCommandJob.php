<?php

namespace App\Jobs;

use App\Models\SlackThreadContext;
use App\Services\Slack\SlackBotResponseService;
use App\Services\Slack\SlackControlPlaneService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ProcessSlackOpsCommandJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 180;

    public function __construct(
        public int $contextId,
        public string $prompt,
        public string $responseUrl,
    ) {
        $this->onQueue('slack-control-plane');
    }

    public function handle(
        SlackControlPlaneService $controlPlane,
        SlackBotResponseService $responseService
    ): void {
        $context = SlackThreadContext::find($this->contextId);

        if (! $context) {
            $this->postSlackResponse([
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => '❌ Slack control plane context was not found.',
            ]);

            return;
        }

        $result = $controlPlane->handle($context, $this->prompt);

        if ($result['success'] ?? false) {
            $message = $result['message'] ?? 'Done.';

            $this->postSlackResponse([
                'response_type' => 'ephemeral',
                'replace_original' => true,
                'text' => $message,
                'blocks' => [
                    $responseService->section($message),
                ],
            ]);

            return;
        }

        $error = $result['error'] ?? 'Unknown Slack control plane error.';

        $this->postSlackResponse([
            'response_type' => 'ephemeral',
            'replace_original' => true,
            'text' => "❌ {$error}",
            'blocks' => [
                $responseService->section("❌ {$error}"),
            ],
        ]);
    }

    protected function postSlackResponse(array $payload): void
    {
        try {
            Http::post($this->responseUrl, $payload);
        } catch (\Throwable $e) {
            Log::warning('Failed to post Slack ops response', [
                'response_url' => $this->responseUrl,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
