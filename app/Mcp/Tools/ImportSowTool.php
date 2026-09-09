<?php

namespace App\Mcp\Tools;

use App\Services\SowParsingService;
use App\Services\SowProvisioningService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class ImportSowTool extends Tool
{
    protected string $name = 'import-sow';

    protected string $title = 'Import Statement Of Work';

    protected string $description = 'Parse one or more SOW/proposal documents from Google Docs links or pasted text, then provision the client, project, milestones, tasks, billing schedule, and optional Slack channel link.';

    public function __construct(
        protected SowParsingService $sowParsingService,
        protected SowProvisioningService $sowProvisioningService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'google_doc_urls' => 'nullable|array|min:1',
            'google_doc_urls.*' => 'string',
            'content' => 'nullable|string',
            'additional_notes' => 'nullable|string',
            'create_invoices' => 'nullable|boolean',
            'preview_only' => 'nullable|boolean',
            'link_to_channel' => 'nullable|boolean',
            'workspace_id' => 'nullable|string',
            'channel_id' => 'nullable|string',
        ]);

        Log::info('[ImportSow] Tool invoked', [
            'has_urls' => ! empty($validated['google_doc_urls']),
            'url_count' => count($validated['google_doc_urls'] ?? []),
            'has_content' => $request->filled('content'),
            'has_additional_notes' => $request->filled('additional_notes'),
            'preview_only' => $validated['preview_only'] ?? false,
            'link_to_channel' => $validated['link_to_channel'] ?? false,
            'workspace_id' => $validated['workspace_id'] ?? null,
            'channel_id' => $validated['channel_id'] ?? null,
            'user' => Auth::user()?->id,
        ]);

        if (! $request->filled('content') && empty($validated['google_doc_urls'])) {
            Log::warning('[ImportSow] No content or URLs provided');

            return Response::text('Provide either google_doc_urls or content.');
        }

        $documents = [];

        foreach ((array) ($validated['google_doc_urls'] ?? []) as $index => $url) {
            $documents[] = [
                'type' => 'google_drive',
                'google_drive_url' => $url,
                'label' => 'SOW Document '.($index + 1),
            ];
        }

        if ($request->filled('content')) {
            $documents[] = [
                'type' => 'text',
                'content' => trim((string) $validated['content']),
                'label' => 'Pasted SOW Content',
            ];
        }

        if ($request->filled('additional_notes')) {
            $documents[] = [
                'type' => 'text',
                'content' => trim((string) $validated['additional_notes']),
                'label' => 'Operator Notes',
            ];
        }

        Log::info('[ImportSow] Parsing documents', [
            'document_count' => count($documents),
            'document_types' => array_column($documents, 'type'),
        ]);

        $parsed = $this->sowParsingService->parseDocuments($documents, Auth::user());

        if (! $parsed) {
            Log::warning('[ImportSow] Parsing returned null - no usable structure extracted', [
                'document_count' => count($documents),
            ]);

            return Response::text('Unable to extract a usable project structure from the supplied SOW documents. If this was a Google Doc, make sure the connected Google account can access it, or paste the SOW text directly.');
        }

        Log::info('[ImportSow] Parsing succeeded', [
            'client_name' => data_get($parsed, 'client.name'),
            'project_name' => data_get($parsed, 'project.name'),
            'milestone_count' => count($parsed['milestones'] ?? []),
            'invoice_count' => count($parsed['invoices'] ?? []),
        ]);

        $previewOnly = $validated['preview_only'] ?? false;

        if ($previewOnly) {
            Log::info('[ImportSow] Returning preview only');

            return Response::structured([
                'message' => 'SOW parsed successfully. Confirm to provision the client, project, and billing setup.',
                'preview_only' => true,
                'parsed' => [
                    'client_name' => data_get($parsed, 'client.name'),
                    'project_name' => data_get($parsed, 'project.name'),
                    'milestone_count' => count((array) ($parsed['milestones'] ?? [])),
                    'invoice_count' => count((array) ($parsed['invoices'] ?? [])),
                    'client' => $parsed['client'] ?? [],
                    'project' => $parsed['project'] ?? [],
                    'milestones' => $parsed['milestones'] ?? [],
                    'invoices' => $parsed['invoices'] ?? [],
                    'billing' => $parsed['billing'] ?? [],
                ],
            ]);
        }

        Log::info('[ImportSow] Provisioning project from parsed SOW');

        $provisioned = $this->sowProvisioningService->provision($parsed, [
            'create_invoices' => $validated['create_invoices'] ?? true,
            'workspace_id' => $validated['workspace_id'] ?? null,
            'channel_id' => $validated['channel_id'] ?? null,
            'link_to_channel' => $validated['link_to_channel'] ?? false,
        ]);

        return Response::structured([
            'message' => 'SOW imported and project scaffolded successfully.',
            'preview_only' => false,
            'parsed' => [
                'client_name' => data_get($parsed, 'client.name'),
                'project_name' => data_get($parsed, 'project.name'),
                'milestone_count' => count((array) ($parsed['milestones'] ?? [])),
                'invoice_count' => count((array) ($parsed['invoices'] ?? [])),
                'client' => $parsed['client'] ?? [],
                'project' => $parsed['project'] ?? [],
                'milestones' => $parsed['milestones'] ?? [],
                'invoices' => $parsed['invoices'] ?? [],
                'billing' => $parsed['billing'] ?? [],
            ],
            'provisioned' => $provisioned,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'google_doc_urls' => $schema->array()->items(
                $schema->string()->description('Google Docs or Google Drive document URL')
            )->description('One or more Google Doc/Drive URLs to parse'),
            'content' => $schema->string()->description('Optional pasted SOW text if links are not available'),
            'additional_notes' => $schema->string()->description('Optional operator notes to merge into the provisioning context'),
            'create_invoices' => $schema->boolean()->description('Whether to create draft invoices from the extracted billing schedule (default: true)'),
            'preview_only' => $schema->boolean()->description('Parse the SOW and return a preview without provisioning anything'),
            'link_to_channel' => $schema->boolean()->description('Whether to link the provided Slack workspace/channel context to the new client/project'),
            'workspace_id' => $schema->string()->description('Slack workspace/team ID when linking the current channel'),
            'channel_id' => $schema->string()->description('Slack channel ID when linking the current channel'),
        ];
    }
}
