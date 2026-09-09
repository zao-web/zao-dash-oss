<?php

namespace App\Mcp\Tools;

use App\Models\Document;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class SearchGoogleDriveTool extends Tool
{
    protected string $name = 'search-google-drive';

    protected string $title = 'Search Google Drive';

    protected string $description = 'Search Google Drive for proposals, case studies, SOWs, contracts, and other documents. Can search by document type, client name, or keywords.';

    public function handle(Request $request): Response|ResponseFactory
    {
        $query = $request->get('query');
        $documentType = $request->get('document_type');
        $clientName = $request->get('client_name');
        $limit = $request->get('limit', 10);

        $builder = Document::query()
            ->orderBy('google_modified_at', 'desc');

        if ($documentType) {
            $builder->where('document_type', $documentType);
        }

        if ($clientName) {
            $builder->where(function ($q) use ($clientName) {
                $q->where('extracted_client_name', 'like', "%{$clientName}%")
                    ->orWhereHas('client', fn ($cq) => $cq->where('name', 'like', "%{$clientName}%"));
            });
        }

        if ($query) {
            $builder->where(function ($q) use ($query) {
                $q->where('filename', 'like', "%{$query}%")
                    ->orWhere('content_excerpt', 'like', "%{$query}%")
                    ->orWhere('extracted_client_name', 'like', "%{$query}%");
            });
        }

        $documents = $builder->limit($limit)->get()->map(fn (Document $d) => [
            'id' => $d->id,
            'filename' => $d->filename,
            'document_type' => $d->document_type,
            'document_type_label' => $d->getDocumentTypeLabel(),
            'client_name' => $d->extracted_client_name ?? $d->client?->name,
            'client_id' => $d->client_id,
            'project_id' => $d->project_id,
            'content_excerpt' => $d->content_excerpt,
            'effective_date' => $d->effective_date?->format('Y-m-d'),
            'contract_value' => $d->contract_value,
            'google_modified_at' => $d->google_modified_at?->format('Y-m-d H:i'),
            'google_drive_url' => $d->google_drive_url,
        ]);

        return Response::structured([
            'documents' => $documents,
            'total' => $documents->count(),
            'filters_applied' => array_filter([
                'query' => $query,
                'document_type' => $documentType,
                'client_name' => $clientName,
            ]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('Search by filename, content excerpt, or client name'),
            'document_type' => $schema->string()
                ->enum(['proposal', 'sow', 'msa', 'contract', 'nda'])
                ->description('Filter by document type'),
            'client_name' => $schema->string()->description('Filter by client name'),
            'limit' => $schema->integer()->description('Maximum results to return (default: 10)'),
        ];
    }
}
