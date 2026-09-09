<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\WebsiteProject;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsiteBuilderDownloadAssetsTool extends BaseTool
{
    protected const MAX_FILE_SIZE_MB = 100;

    protected const MAX_FILE_SIZE_BYTES = self::MAX_FILE_SIZE_MB * 1024 * 1024;

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Download Assets';
    }

    public function description(): string
    {
        return 'Download files from URLs (including Google Drive, Dropbox, and direct links) and store them locally for processing. Handles public shared links and converts them to direct download URLs automatically.';
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function riskLevel(): string
    {
        return 'low';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'project_id' => [
                    'type' => 'integer',
                    'description' => 'Website project ID to associate downloaded files with',
                ],
                'urls' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Array of URLs to download. Supports Google Drive shared links, Dropbox links, and direct URLs.',
                ],
                'folder' => [
                    'type' => 'string',
                    'description' => 'Optional subfolder to organize downloads (default: project slug)',
                ],
            ],
            'required' => ['project_id', 'urls'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'urls' => 'required|array|min:1|max:50',
            'urls.*' => 'required|string|url',
            'folder' => 'nullable|string|max:100',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $urls = $params['urls'];
        $folder = $params['folder'] ?? $project->slug;
        $basePath = "website-builder/{$folder}";

        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($urls as $url) {
            try {
                $downloadUrl = $this->resolveDownloadUrl($url);
                $result = $this->downloadFile($downloadUrl, $basePath, $url);
                $results[] = $result;
                $successCount++;
            } catch (\Exception $e) {
                $results[] = [
                    'success' => false,
                    'original_url' => $url,
                    'error' => $e->getMessage(),
                ];
                $failureCount++;
            }
        }

        $this->updateProjectAssets($project, $results);

        return [
            'success' => $failureCount === 0,
            'total' => count($urls),
            'successful' => $successCount,
            'failed' => $failureCount,
            'base_path' => $basePath,
            'files' => $results,
            'message' => "Downloaded {$successCount} of ".count($urls).' files.',
        ];
    }

    protected function resolveDownloadUrl(string $url): string
    {
        if ($this->isGoogleDriveUrl($url)) {
            return $this->convertGoogleDriveUrl($url);
        }

        if ($this->isDropboxUrl($url)) {
            return $this->convertDropboxUrl($url);
        }

        if ($this->isOneDriveUrl($url)) {
            return $this->convertOneDriveUrl($url);
        }

        return $url;
    }

    protected function isGoogleDriveUrl(string $url): bool
    {
        return str_contains($url, 'drive.google.com') || str_contains($url, 'docs.google.com');
    }

    protected function convertGoogleDriveUrl(string $url): string
    {
        if (preg_match('/\/file\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return "https://drive.google.com/uc?export=download&id={$matches[1]}";
        }

        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $matches)) {
            return "https://drive.google.com/uc?export=download&id={$matches[1]}";
        }

        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
            throw new \Exception('Google Drive folder links are not supported. Please share individual file links.');
        }

        throw new \Exception('Unable to parse Google Drive URL. Please use a direct file sharing link.');
    }

    protected function isDropboxUrl(string $url): bool
    {
        return str_contains($url, 'dropbox.com');
    }

    protected function convertDropboxUrl(string $url): string
    {
        $url = preg_replace('/[?&]dl=0/', '', $url);
        $url = preg_replace('/[?&]dl=1/', '', $url);

        if (str_contains($url, '?')) {
            return $url.'&dl=1';
        }

        return $url.'?dl=1';
    }

    protected function isOneDriveUrl(string $url): bool
    {
        return str_contains($url, '1drv.ms') || str_contains($url, 'onedrive.live.com');
    }

    protected function convertOneDriveUrl(string $url): string
    {
        if (str_contains($url, '1drv.ms')) {
            $response = Http::withOptions(['allow_redirects' => false])->get($url);
            $location = $response->header('Location');

            if ($location) {
                $url = $location;
            }
        }

        return str_replace('redir?', 'download?', $url);
    }

    protected function downloadFile(string $url, string $basePath, string $originalUrl): array
    {
        $response = Http::timeout(120)
            ->withOptions([
                'allow_redirects' => true,
                'max_redirects' => 5,
            ])
            ->get($url);

        if (! $response->successful()) {
            throw new \Exception('Download failed: HTTP '.$response->status());
        }

        $body = $response->body();

        if (strlen($body) > self::MAX_FILE_SIZE_BYTES) {
            throw new \Exception('File too large (max '.self::MAX_FILE_SIZE_MB.'MB)');
        }

        $filename = $this->extractFilename($response, $url, $originalUrl);
        $mimeType = $this->detectMimeType($body, $filename);

        $storagePath = "{$basePath}/{$filename}";
        $counter = 1;
        while (Storage::disk('local')->exists($storagePath)) {
            $pathInfo = pathinfo($filename);
            $storagePath = "{$basePath}/{$pathInfo['filename']}_{$counter}.{$pathInfo['extension']}";
            $counter++;
        }

        Storage::disk('local')->put($storagePath, $body);

        return [
            'success' => true,
            'original_url' => $originalUrl,
            'filename' => basename($storagePath),
            'path' => Storage::disk('local')->path($storagePath),
            'storage_path' => $storagePath,
            'size' => strlen($body),
            'size_human' => $this->formatBytes(strlen($body)),
            'mime_type' => $mimeType,
        ];
    }

    protected function extractFilename($response, string $url, string $originalUrl): string
    {
        $contentDisposition = $response->header('Content-Disposition');
        if ($contentDisposition && preg_match('/filename[^;=\n]*=(([\'"]).*?\2|[^;\n]*)/', $contentDisposition, $matches)) {
            $filename = trim($matches[1], " \t\n\r\0\x0B\"'");
            if ($filename) {
                return $this->sanitizeFilename($filename);
            }
        }

        $urlPath = parse_url($originalUrl, PHP_URL_PATH);
        $filename = basename($urlPath);
        if ($filename && str_contains($filename, '.') && strlen($filename) < 255) {
            return $this->sanitizeFilename($filename);
        }

        $extension = $this->guessExtensionFromMimeType($response->header('Content-Type') ?? '');

        return 'download_'.Str::random(8).'.'.$extension;
    }

    protected function sanitizeFilename(string $filename): string
    {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);
        $filename = preg_replace('/_+/', '_', $filename);

        return substr($filename, 0, 200);
    }

    protected function detectMimeType(string $content, string $filename): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->buffer($content);

        if ($mimeType && $mimeType !== 'application/octet-stream') {
            return $mimeType;
        }

        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'doc' => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xls' => 'application/vnd.ms-excel',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            default => 'application/octet-stream',
        };
    }

    protected function guessExtensionFromMimeType(string $mimeType): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/svg+xml' => 'svg',
            'application/pdf' => 'pdf',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            'text/html' => 'html',
            'application/json' => 'json',
            'video/mp4' => 'mp4',
            'audio/mpeg' => 'mp3',
        ];

        foreach ($map as $mime => $ext) {
            if (str_contains($mimeType, $mime)) {
                return $ext;
            }
        }

        return 'bin';
    }

    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, 2).' '.$units[$i];
    }

    protected function updateProjectAssets(WebsiteProject $project, array $results): void
    {
        $assets = $project->downloaded_assets ?? [];

        foreach ($results as $result) {
            if ($result['success']) {
                $assets[] = [
                    'filename' => $result['filename'],
                    'path' => $result['path'],
                    'storage_path' => $result['storage_path'],
                    'original_url' => $result['original_url'],
                    'mime_type' => $result['mime_type'],
                    'size' => $result['size'],
                    'downloaded_at' => now()->toIso8601String(),
                ];
            }
        }

        $project->update(['downloaded_assets' => $assets]);
    }
}
