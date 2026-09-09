<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WebsiteBuilderUploadMediaTool extends BaseTool
{
    public function __construct(
        protected WordPressMcpService $wpService
    ) {}

    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Upload Media';
    }

    public function description(): string
    {
        return 'Upload media files (images, PDFs, documents) to a WordPress site\'s media library. Supports uploading from URLs or local file paths. Returns the WordPress media URL for use in page content.';
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
                    'description' => 'Website project ID',
                ],
                'source' => [
                    'type' => 'string',
                    'description' => 'URL or local file path to upload. For URLs, the file will be downloaded first.',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Optional filename for the uploaded file. If not provided, will be derived from source.',
                ],
                'alt_text' => [
                    'type' => 'string',
                    'description' => 'Alt text for the media (important for accessibility and SEO)',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Title for the media item in WordPress',
                ],
                'caption' => [
                    'type' => 'string',
                    'description' => 'Caption for the media item',
                ],
            ],
            'required' => ['project_id', 'source'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'project_id' => 'required|integer|exists:website_projects,id',
            'source' => 'required|string',
            'filename' => 'nullable|string|max:255',
            'alt_text' => 'nullable|string|max:500',
            'title' => 'nullable|string|max:255',
            'caption' => 'nullable|string|max:1000',
        ];
    }

    public function execute(array $params): array
    {
        $project = WebsiteProject::find($params['project_id']);

        if (! $project) {
            return ['success' => false, 'error' => 'Project not found'];
        }

        $wpSite = $this->resolveWordPressSite($project);

        if (! $wpSite) {
            return [
                'success' => false,
                'error' => 'No WordPress site connected to this project. The site must be deployed and have REST API credentials configured.',
            ];
        }

        $source = $params['source'];

        try {
            if ($this->isUrl($source)) {
                $localPath = $this->downloadFromUrl($source);
                $filename = $params['filename'] ?? $this->extractFilenameFromUrl($source);
            } else {
                if (! file_exists($source)) {
                    return ['success' => false, 'error' => "File not found: {$source}"];
                }
                $localPath = $source;
                $filename = $params['filename'] ?? basename($source);
            }

            $mimeType = mime_content_type($localPath);
            if (! $this->isAllowedMimeType($mimeType)) {
                @unlink($localPath);

                return [
                    'success' => false,
                    'error' => "File type not allowed: {$mimeType}. Allowed types: images (jpg, png, gif, webp, svg), documents (pdf, doc, docx), and common media files.",
                ];
            }

            $result = $this->wpService->uploadMedia(
                $wpSite,
                $localPath,
                $filename,
                $params['alt_text'] ?? null
            );

            if (isset($result['id']) && (isset($params['title']) || isset($params['caption']))) {
                $this->updateMediaMeta($wpSite, $result['id'], $params);
            }

            if ($this->isUrl($source) && file_exists($localPath)) {
                @unlink($localPath);
            }

            $this->registerMediaInProject($project, $result);

            return [
                'success' => true,
                'media_id' => $result['id'] ?? null,
                'url' => $result['source_url'] ?? $result['guid']['rendered'] ?? null,
                'filename' => $filename,
                'mime_type' => $result['mime_type'] ?? $mimeType,
                'title' => $result['title']['rendered'] ?? $filename,
                'alt_text' => $result['alt_text'] ?? $params['alt_text'] ?? '',
                'sizes' => $this->extractSizes($result),
                'edit_url' => rtrim($wpSite->url, '/').'/wp-admin/post.php?post='.($result['id'] ?? '').'&action=edit',
                'message' => 'Media uploaded successfully. URL: '.($result['source_url'] ?? $result['guid']['rendered'] ?? 'unknown'),
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'suggestion' => $this->getSuggestionForError($e->getMessage()),
            ];
        }
    }

    protected function isUrl(string $source): bool
    {
        return Str::startsWith($source, ['http://', 'https://']);
    }

    protected function downloadFromUrl(string $url): string
    {
        $response = Http::timeout(60)->get($url);

        if (! $response->successful()) {
            throw new \Exception('Failed to download file from URL: HTTP '.$response->status());
        }

        $tempPath = sys_get_temp_dir().'/'.Str::uuid().'_'.basename(parse_url($url, PHP_URL_PATH));
        file_put_contents($tempPath, $response->body());

        return $tempPath;
    }

    protected function extractFilenameFromUrl(string $url): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        $filename = basename($path);

        if (! $filename || ! str_contains($filename, '.')) {
            $filename = 'media_'.Str::random(8).'.jpg';
        }

        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '_', $filename);

        return $filename;
    }

    protected function isAllowedMimeType(string $mimeType): bool
    {
        $allowedMimeTypes = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/svg+xml',
            'image/bmp',
            'image/tiff',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/vnd.ms-powerpoint',
            'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'video/mp4',
            'video/webm',
            'video/ogg',
            'text/plain',
            'text/csv',
        ];

        return in_array($mimeType, $allowedMimeTypes);
    }

    protected function updateMediaMeta(WordPressSite $site, int $mediaId, array $params): void
    {
        $data = [];

        if (isset($params['title'])) {
            $data['title'] = $params['title'];
        }

        if (isset($params['caption'])) {
            $data['caption'] = $params['caption'];
        }

        if (! empty($data)) {
            Http::withHeaders([
                'Authorization' => $site->auth_header,
                'Content-Type' => 'application/json',
            ])->post(
                rtrim($site->url, '/')."/wp-json/wp/v2/media/{$mediaId}",
                $data
            );
        }
    }

    protected function extractSizes(array $result): array
    {
        $sizes = [];

        if (isset($result['media_details']['sizes'])) {
            foreach ($result['media_details']['sizes'] as $size => $info) {
                $sizes[$size] = [
                    'url' => $info['source_url'] ?? null,
                    'width' => $info['width'] ?? null,
                    'height' => $info['height'] ?? null,
                ];
            }
        }

        return $sizes;
    }

    protected function registerMediaInProject(WebsiteProject $project, array $mediaResult): void
    {
        $media = $project->uploaded_media ?? [];
        $media[] = [
            'id' => $mediaResult['id'] ?? null,
            'url' => $mediaResult['source_url'] ?? $mediaResult['guid']['rendered'] ?? null,
            'filename' => $mediaResult['title']['rendered'] ?? 'unknown',
            'mime_type' => $mediaResult['mime_type'] ?? null,
            'uploaded_at' => now()->toIso8601String(),
        ];

        $project->update(['uploaded_media' => $media]);
    }

    protected function resolveWordPressSite(WebsiteProject $project): ?WordPressSite
    {
        $spinupSite = SpinupWpSite::where('website_project_id', $project->id)
            ->where('status', SpinupWpSite::STATUS_DEPLOYED)
            ->first();

        if ($spinupSite && $spinupSite->wordpressSite) {
            return $spinupSite->wordpressSite;
        }

        if ($spinupSite && $spinupSite->wp_admin_user && $spinupSite->wp_admin_password) {
            return new WordPressSite([
                'url' => $spinupSite->url,
                'username' => $spinupSite->wp_admin_user,
                'application_password' => $spinupSite->wp_admin_password,
            ]);
        }

        if ($project->staging_url) {
            return WordPressSite::where('url', 'like', '%'.parse_url($project->staging_url, PHP_URL_HOST).'%')
                ->first();
        }

        return null;
    }

    protected function getSuggestionForError(string $error): string
    {
        if (str_contains($error, '401') || str_contains($error, 'Unauthorized')) {
            return 'The WordPress credentials may be invalid or expired. Check that the application password is correct.';
        }

        if (str_contains($error, '413') || str_contains($error, 'too large')) {
            return 'The file is too large for upload. WordPress has a default limit of 2MB-50MB depending on server configuration.';
        }

        if (str_contains($error, 'mime') || str_contains($error, 'type')) {
            return 'The file type is not allowed. WordPress restricts certain file types for security.';
        }

        if (str_contains($error, 'download')) {
            return 'Failed to download the file from the URL. Check that the URL is accessible and returns a valid file.';
        }

        return 'Check that the WordPress site is accessible and media uploads are enabled.';
    }
}
