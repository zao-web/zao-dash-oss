<?php

namespace App\Services;

use App\Models\User;
use App\Models\WebsiteProject;
use App\Models\WebsiteProjectAsset;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WebsiteProjectAssetService
{
    protected string $disk = 'local';

    protected string $basePath = 'website-project-assets';

    public function __construct()
    {
        $this->disk = config('filesystems.default', 'local');
    }

    public function uploadFile(
        WebsiteProject $project,
        UploadedFile $file,
        ?string $category = null,
        ?string $description = null,
        ?User $uploadedBy = null
    ): WebsiteProjectAsset {
        $originalFilename = $file->getClientOriginalName();
        $mimeType = $file->getMimeType() ?? 'application/octet-stream';
        $size = $file->getSize();
        $type = WebsiteProjectAsset::determineType($mimeType);

        $extension = $file->getClientOriginalExtension();
        $filename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME))
            .'-'.Str::random(8)
            .'.'.$extension;

        $path = "{$this->basePath}/{$project->id}/{$type}/{$filename}";

        Storage::disk($this->disk)->put($path, file_get_contents($file->getRealPath()));

        $metadata = $this->extractMetadata($file, $type);

        $autoCategory = $category ?? $this->inferCategory($originalFilename, $type, $mimeType, $metadata);

        return WebsiteProjectAsset::create([
            'website_project_id' => $project->id,
            'uploaded_by_user_id' => $uploadedBy?->id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'disk' => $this->disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'type' => $type,
            'category' => $autoCategory,
            'description' => $description,
            'metadata' => $metadata,
            'status' => WebsiteProjectAsset::STATUS_READY,
        ]);
    }

    public function uploadFromPath(
        WebsiteProject $project,
        string $filePath,
        ?string $category = null,
        ?string $description = null,
        ?User $uploadedBy = null
    ): WebsiteProjectAsset {
        if (! file_exists($filePath)) {
            throw new \InvalidArgumentException("File not found: {$filePath}");
        }

        $originalFilename = basename($filePath);
        $mimeType = mime_content_type($filePath) ?: 'application/octet-stream';
        $size = filesize($filePath);
        $type = WebsiteProjectAsset::determineType($mimeType);

        $extension = pathinfo($filePath, PATHINFO_EXTENSION);
        $filename = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME))
            .'-'.Str::random(8)
            .'.'.$extension;

        $path = "{$this->basePath}/{$project->id}/{$type}/{$filename}";

        Storage::disk($this->disk)->put($path, file_get_contents($filePath));

        $metadata = $this->extractMetadataFromPath($filePath, $type, $mimeType);

        $autoCategory = $category ?? $this->inferCategory($originalFilename, $type, $mimeType, $metadata);

        return WebsiteProjectAsset::create([
            'website_project_id' => $project->id,
            'uploaded_by_user_id' => $uploadedBy?->id,
            'filename' => $filename,
            'original_filename' => $originalFilename,
            'disk' => $this->disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'type' => $type,
            'category' => $autoCategory,
            'description' => $description,
            'metadata' => $metadata,
            'status' => WebsiteProjectAsset::STATUS_READY,
        ]);
    }

    public function uploadFromBase64(
        WebsiteProject $project,
        string $base64Content,
        string $filename,
        string $mimeType,
        ?string $category = null,
        ?string $description = null,
        ?User $uploadedBy = null
    ): WebsiteProjectAsset {
        $content = base64_decode($base64Content);
        if ($content === false) {
            throw new \InvalidArgumentException('Invalid base64 content');
        }

        $size = strlen($content);
        $type = WebsiteProjectAsset::determineType($mimeType);

        $extension = pathinfo($filename, PATHINFO_EXTENSION);
        $uniqueFilename = Str::slug(pathinfo($filename, PATHINFO_FILENAME))
            .'-'.Str::random(8)
            .'.'.$extension;

        $path = "{$this->basePath}/{$project->id}/{$type}/{$uniqueFilename}";

        Storage::disk($this->disk)->put($path, $content);

        return WebsiteProjectAsset::create([
            'website_project_id' => $project->id,
            'uploaded_by_user_id' => $uploadedBy?->id,
            'filename' => $uniqueFilename,
            'original_filename' => $filename,
            'disk' => $this->disk,
            'path' => $path,
            'mime_type' => $mimeType,
            'size' => $size,
            'type' => $type,
            'category' => $category,
            'description' => $description,
            'metadata' => [],
            'status' => WebsiteProjectAsset::STATUS_READY,
        ]);
    }

    public function uploadFromUrl(
        WebsiteProject $project,
        string $url,
        ?string $category = null,
        ?string $description = null,
        ?User $uploadedBy = null
    ): WebsiteProjectAsset {
        $tempPath = tempnam(sys_get_temp_dir(), 'wpa_');

        $ch = curl_init($url);
        $fp = fopen($tempPath, 'wb');

        curl_setopt_array($ch, [
            CURLOPT_FILE => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $success = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (! $success || $httpCode !== 200) {
            @unlink($tempPath);
            throw new \RuntimeException("Failed to download file from URL: {$url}");
        }

        try {
            $parsedUrl = parse_url($url);
            $originalFilename = basename($parsedUrl['path'] ?? 'downloaded-file');

            $asset = $this->uploadFromPath(
                $project,
                $tempPath,
                $category,
                $description ?? "Downloaded from: {$url}",
                $uploadedBy
            );

            $asset->update(['original_filename' => $originalFilename]);

            return $asset;
        } finally {
            @unlink($tempPath);
        }
    }

    public function delete(WebsiteProjectAsset $asset): bool
    {
        Storage::disk($asset->disk)->delete($asset->path);

        return $asset->delete();
    }

    public function getAssetsForAgent(WebsiteProject $project): array
    {
        $assets = $project->assets()->ready()->get();

        return [
            'images' => $assets->where('type', WebsiteProjectAsset::TYPE_IMAGE)->map->toArrayForAgent()->values()->all(),
            'documents' => $assets->where('type', WebsiteProjectAsset::TYPE_DOCUMENT)->map->toArrayForAgent()->values()->all(),
            'videos' => $assets->where('type', WebsiteProjectAsset::TYPE_VIDEO)->map->toArrayForAgent()->values()->all(),
            'audio' => $assets->where('type', WebsiteProjectAsset::TYPE_AUDIO)->map->toArrayForAgent()->values()->all(),
            'other' => $assets->where('type', WebsiteProjectAsset::TYPE_OTHER)->map->toArrayForAgent()->values()->all(),
            'by_category' => $assets->groupBy('category')->map(fn ($items) => $items->map->toArrayForAgent()->values()->all())->all(),
            'total_count' => $assets->count(),
            'total_size' => $assets->sum('size'),
        ];
    }

    protected function extractMetadata(UploadedFile $file, string $type): array
    {
        $metadata = [];

        if ($type === WebsiteProjectAsset::TYPE_IMAGE) {
            $imageInfo = @getimagesize($file->getRealPath());
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['aspect_ratio'] = $imageInfo[0] / max(1, $imageInfo[1]);
            }
        }

        return $metadata;
    }

    protected function inferCategory(string $filename, string $type, string $mimeType, array $metadata): ?string
    {
        $lowerFilename = strtolower($filename);

        if ($mimeType === 'application/pdf') {
            if (preg_match('/brief|requirements|spec|scope|proposal/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_BRIEF;
            }

            return WebsiteProjectAsset::CATEGORY_CONTENT;
        }

        if ($type === WebsiteProjectAsset::TYPE_IMAGE) {
            if (preg_match('/logo|brand|mark/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_LOGO;
            }

            if (preg_match('/icon|favicon|ico/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_ICON;
            }

            if (preg_match('/hero|banner|header|cover/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_HERO;
            }

            if (preg_match('/background|bg|pattern|texture/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_BACKGROUND;
            }

            $width = $metadata['width'] ?? 0;
            $height = $metadata['height'] ?? 0;
            $aspectRatio = $metadata['aspect_ratio'] ?? 1;

            if ($width <= 256 && $height <= 256) {
                return WebsiteProjectAsset::CATEGORY_ICON;
            }

            if ($width >= 1200 && $aspectRatio > 1.5) {
                return WebsiteProjectAsset::CATEGORY_HERO;
            }

            return WebsiteProjectAsset::CATEGORY_PHOTO;
        }

        if ($type === WebsiteProjectAsset::TYPE_DOCUMENT) {
            if (preg_match('/brief|requirements|spec|scope|proposal/i', $lowerFilename)) {
                return WebsiteProjectAsset::CATEGORY_BRIEF;
            }

            return WebsiteProjectAsset::CATEGORY_CONTENT;
        }

        return null;
    }

    protected function extractMetadataFromPath(string $filePath, string $type, string $mimeType): array
    {
        $metadata = [];

        if ($type === WebsiteProjectAsset::TYPE_IMAGE) {
            $imageInfo = @getimagesize($filePath);
            if ($imageInfo) {
                $metadata['width'] = $imageInfo[0];
                $metadata['height'] = $imageInfo[1];
                $metadata['aspect_ratio'] = $imageInfo[0] / max(1, $imageInfo[1]);
            }
        }

        if ($mimeType === 'application/pdf' && class_exists('\Smalot\PdfParser\Parser')) {
            try {
                $parser = new \Smalot\PdfParser\Parser;
                $pdf = $parser->parseFile($filePath);
                $metadata['page_count'] = count($pdf->getPages());
            } catch (\Exception) {
            }
        }

        return $metadata;
    }
}
