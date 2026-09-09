<?php

namespace App\Mcp\Tools;

use App\Services\GravityForms\GravityFormsService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GfListFormsTool extends Tool
{
    protected string $name = 'gf-list-forms';

    protected string $title = 'List Gravity Forms';

    protected string $description = 'List all Gravity Forms on the Zao website.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $service = app(GravityFormsService::class);

        if (! $service->isConfigured()) {
            return Response::structured([
                'success' => false,
                'error' => 'Gravity Forms API is not configured.',
            ]);
        }

        try {
            $forms = $service->listForms();

            $result = array_map(fn ($form) => [
                'id' => $form['id'],
                'title' => $form['title'],
                'is_active' => ($form['is_active'] ?? '1') === '1',
                'field_count' => count($form['fields'] ?? []),
            ], $forms);

            return Response::structured([
                'success' => true,
                'forms' => $result,
                'total' => count($result),
            ]);
        } catch (\Exception $e) {
            return Response::structured([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
