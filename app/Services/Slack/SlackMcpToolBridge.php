<?php

namespace App\Services\Slack;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Request as McpRequest;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool as McpTool;
use ReflectionClass;

class SlackMcpToolBridge
{
    /**
     * @var array<string, class-string<McpTool>>|null
     */
    protected ?array $toolMap = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toolsForAssistant(): array
    {
        return collect($this->discoverTools())
            ->map(function (string $toolClass): array {
                /** @var McpTool $tool */
                $tool = app($toolClass);
                $payload = $tool->toArray();

                return [
                    'name' => $tool->name(),
                    'description' => $payload['description'] ?? '',
                    'input_schema' => $payload['inputSchema'] ?? ['type' => 'object', 'properties' => new \stdClass],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(string $toolName, array $arguments): array
    {
        $toolClass = $this->discoverTools()[$toolName] ?? null;

        if (! $toolClass) {
            return ['error' => "Slack control plane tool '{$toolName}' is not available."];
        }

        /** @var McpTool $tool */
        $tool = app($toolClass);
        $actingUser = $this->resolveActingUser();
        $previousUser = Auth::user();

        if ($actingUser) {
            Auth::setUser($actingUser);
        }

        try {
            $response = $tool->handle(new McpRequest($arguments));

            return $this->normalizeResponse($response);
        } catch (\Throwable $e) {
            return ['error' => $e->getMessage()];
        } finally {
            if ($previousUser) {
                Auth::setUser($previousUser);
            } elseif (method_exists(Auth::guard(), 'forgetUser')) {
                Auth::guard()->forgetUser();
            }
        }
    }

    /**
     * @return array<string, class-string<McpTool>>
     */
    protected function discoverTools(): array
    {
        if ($this->toolMap !== null) {
            return $this->toolMap;
        }

        $map = [];

        foreach (File::files(app_path('Mcp/Tools')) as $file) {
            $class = 'App\\Mcp\\Tools\\'.$file->getFilenameWithoutExtension();

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(McpTool::class)) {
                continue;
            }

            /** @var McpTool $instance */
            $instance = app($class);
            $name = $instance->name();

            if (! in_array($name, $this->allowedToolNames(), true)) {
                continue;
            }

            $map[$name] = $class;
        }

        ksort($map);

        return $this->toolMap = $map;
    }

    /**
     * @return array<int, string>
     */
    protected function allowedToolNames(): array
    {
        return [
            'attach-website-project-assets',
            'bulk-update-tasks',
            'create-client',
            'create-client-contact',
            'create-client-note',
            'create-invoice',
            'create-lead',
            'create-project',
            'create-rfp-learning-insight',
            'create-rfp-opportunity',
            'create-rfp-proposal',
            'create-task',
            'create-website-project',
            'decide-approval-request',
            'fetch-rfp-listing',
            'get-agent',
            'get-agent-run',
            'get-channel-operations-context',
            'get-client',
            'get-focus',
            'get-rfp-learning-insights',
            'get-integration-status',
            'get-project',
            'get-task',
            'get-website-project',
            'import-sow',
            'link-slack-context',
            'list-slack-watchlist',
            'list-agents',
            'list-approval-requests',
            'list-clients',
            'list-interaction-requests',
            'list-invoices',
            'list-leads',
            'list-projects',
            'list-tasks',
            'list-website-projects',
            'respond-to-interaction',
            'run-channel-integration-action',
            'manage-slack-watchlist',
            'search',
            'search-emails',
            'search-gmail-live',
            'search-google-drive',
            'search-rfp-opportunities',
            'trigger-agent',
            'trigger-rfp-scan',
            'trigger-compound-engineering',
            'trigger-website-build',
            'update-client',
            'update-invoice',
            'update-lead-stage',
            'update-project',
            'update-rfp-opportunity',
            'update-task',
            'update-website-project',
            'get-client-profitability',
            'get-invoice-velocity',
        ];
    }

    protected function resolveActingUser(): ?User
    {
        return Auth::user()
            ?? User::query()->where('role', 'admin')->first()
            ?? User::query()->first();
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeResponse(Response|ResponseFactory $response): array
    {
        if ($response instanceof ResponseFactory) {
            $structured = $response->getStructuredContent();
            if (is_array($structured)) {
                return $structured;
            }

            return [
                'text' => $response->responses()
                    ->map(function (Response $item): string {
                        $content = $item->content();

                        return method_exists($content, '__toString')
                            ? (string) $content
                            : (string) json_encode($content);
                    })
                    ->implode("\n"),
            ];
        }

        $content = $response->content();
        if (method_exists($content, '__toString')) {
            return ['text' => (string) $content];
        }

        return ['text' => (string) json_encode($content)];
    }
}
