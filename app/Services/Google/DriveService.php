<?php

namespace App\Services\Google;

use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DriveService
{
    private const BASE_URL = 'https://www.googleapis.com/drive/v3';

    public function __construct(
        private GoogleOAuthService $oauth
    ) {}

    public function searchFiles(User $user, string $query, array $params = []): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $defaults = [
            'q' => $query,
            'fields' => 'files(id,name,mimeType,webViewLink,modifiedTime,description)',
            'pageSize' => 50,
        ];

        $response = Http::withToken($token)
            ->get(self::BASE_URL.'/files', array_merge($defaults, $params));

        if (! $response->successful()) {
            throw new \Exception('Failed to search files: '.$response->body());
        }

        return $response->json();
    }

    public function getFile(User $user, string $fileId): array
    {
        $token = $this->oauth->getValidAccessToken($user);

        $response = Http::withToken($token)
            ->get(self::BASE_URL."/files/{$fileId}", [
                'fields' => 'id,name,mimeType,webViewLink,modifiedTime,description',
            ]);

        if (! $response->successful()) {
            throw new \Exception('Failed to get file: '.$response->body());
        }

        return $response->json();
    }

    public function getFileContent(User $user, string $fileId): ?string
    {
        Log::info('[GoogleDrive] getFileContent called', [
            'file_id' => $fileId,
            'user_id' => $user->id,
        ]);

        try {
            $token = $this->oauth->getValidAccessToken($user);
            Log::info('[GoogleDrive] Access token obtained', [
                'file_id' => $fileId,
                'token_present' => ! empty($token),
                'token_length' => $token ? strlen($token) : 0,
            ]);
        } catch (\Exception $e) {
            Log::error('[GoogleDrive] Failed to get access token', [
                'file_id' => $fileId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        // For Google Docs, export as plain text
        $file = $this->getFile($user, $fileId);
        $mimeType = $file['mimeType'] ?? '';

        Log::info('[GoogleDrive] File metadata retrieved', [
            'file_id' => $fileId,
            'mime_type' => $mimeType,
            'file_name' => $file['name'] ?? 'unknown',
            'is_google_app' => str_starts_with($mimeType, 'application/vnd.google-apps'),
        ]);

        if (str_starts_with($mimeType, 'application/vnd.google-apps')) {
            $response = Http::withToken($token)
                ->get(self::BASE_URL."/files/{$fileId}/export", [
                    'mimeType' => 'text/plain',
                ]);
        } else {
            $response = Http::withToken($token)
                ->get(self::BASE_URL."/files/{$fileId}?alt=media");
        }

        Log::info('[GoogleDrive] Content API response', [
            'file_id' => $fileId,
            'status' => $response->status(),
            'successful' => $response->successful(),
            'body_length' => strlen($response->body()),
        ]);

        if (! $response->successful()) {
            Log::warning('[GoogleDrive] Content API returned non-success status', [
                'file_id' => $fileId,
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 500),
            ]);

            return null;
        }

        return $response->body();
    }

    public function discoverDocuments(User $user): array
    {
        $keywords = ['MSA', 'Statement of Work', 'SOW', 'Agreement', 'Contract', 'NDA', 'Proposal'];
        $discovered = [];

        foreach ($keywords as $keyword) {
            $query = "name contains '{$keyword}' or fullText contains '{$keyword}'";
            $results = $this->searchFiles($user, $query);

            foreach ($results['files'] ?? [] as $file) {
                if (! isset($discovered[$file['id']])) {
                    $discovered[$file['id']] = $file;
                }
            }
        }

        return array_values($discovered);
    }

    public function indexDocument(User $user, array $file): Document
    {
        $content = $this->getFileContent($user, $file['id']);
        $excerpt = $content ? $this->sanitizeUtf8(substr($content, 0, 1000)) : null;

        // Detect document type from name
        $name = strtolower($file['name']);
        $documentType = match (true) {
            str_contains($name, 'msa') || str_contains($name, 'master service') => 'msa',
            str_contains($name, 'sow') || str_contains($name, 'statement of work') => 'sow',
            str_contains($name, 'proposal') => 'proposal',
            str_contains($name, 'nda') || str_contains($name, 'non-disclosure') => 'nda',
            str_contains($name, 'contract') || str_contains($name, 'agreement') => 'contract',
            default => 'other',
        };

        $extractedClientName = $this->sanitizeUtf8($this->extractClientName($file['name']));

        return Document::updateOrCreate(
            ['google_drive_id' => $file['id']],
            [
                'filename' => $this->sanitizeUtf8($file['name']),
                'mime_type' => $file['mimeType'] ?? null,
                'document_type' => $documentType,
                'extracted_client_name' => $extractedClientName,
                'content_excerpt' => $excerpt,
                'web_view_link' => $file['webViewLink'] ?? null,
                'google_modified_at' => isset($file['modifiedTime'])
                    ? now()->parse($file['modifiedTime'])
                    : null,
                'indexed_at' => now(),
            ]
        );
    }

    private function extractClientName(string $filename): ?string
    {
        $patterns = [
            '/^(.+?)\s*[-_]\s*(?:MSA|SOW|Agreement|Contract|NDA|Proposal)/i',
            '/^(?:MSA|SOW|Agreement|Contract|NDA|Proposal)\s*[-_]\s*(.+?)(?:\s*\d{4})?\./',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $filename, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    private function sanitizeUtf8(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }

        $cleaned = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $cleaned);

        if (! mb_check_encoding($cleaned, 'UTF-8')) {
            $cleaned = iconv('UTF-8', 'UTF-8//IGNORE', $text);
        }

        return $cleaned;
    }

    public function getUnlinkedDocuments(): \Illuminate\Database\Eloquent\Collection
    {
        return Document::whereNull('client_id')
            ->whereNotNull('extracted_client_name')
            ->orderBy('indexed_at', 'desc')
            ->get();
    }
}
