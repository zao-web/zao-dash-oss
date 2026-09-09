<?php

namespace App\Mcp\Tools;

use App\Agents\Tools\WordpressMediaUploadTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class WordpressMediaUploadMcpTool extends Tool
{
    protected string $name = 'wordpress-media-upload';

    protected string $title = 'Upload WordPress Media';

    protected string $description = 'Upload media to the connected WordPress site through Dash-stored credentials. Accepts file_path, file_url, or file_base64. Set dry_run true to handshake REST auth without uploading.';

    public function __construct(protected WordpressMediaUploadTool $uploader) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $validated = $request->validate([
            'file_path' => 'nullable|string',
            'file_url' => 'nullable|string',
            'file_base64' => 'nullable|string',
            'filename' => 'nullable|string',
            'alt_text' => 'nullable|string',
            'dry_run' => 'nullable|boolean',
        ]);

        $validated['dry_run'] = $request->boolean('dry_run');

        if (! $validated['dry_run'] && empty($validated['file_path']) && empty($validated['file_url']) && empty($validated['file_base64'])) {
            return Response::structured([
                'success' => false,
                'error' => 'Provide file_path, file_url, or file_base64, or set dry_run true.',
            ]);
        }

        return Response::structured($this->uploader->execute($validated));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'file_path' => $schema->string()->description('Absolute path under the Dash wordpress-media, public uploads, or temp directory'),
            'file_url' => $schema->string()->description('Public http(s) URL to download and upload. Private, link-local, and metadata addresses are rejected.'),
            'file_base64' => $schema->string()->description('Base64-encoded file contents'),
            'filename' => $schema->string()->description('Filename to store in WordPress (required for file_base64)'),
            'alt_text' => $schema->string()->description('Accessibility alt text for images'),
            'dry_run' => $schema->boolean()->description('Handshake REST auth and persist rest_url without uploading'),
        ];
    }
}
