<?php

namespace App\Agents\Tools\WebsiteBuilder;

use App\Agents\Tools\BaseTool;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class WebsiteBuilderExtractContentTool extends BaseTool
{
    public function category(): string
    {
        return 'website-builder';
    }

    public function name(): string
    {
        return 'Extract Content';
    }

    public function description(): string
    {
        return 'Extract text content from documents (PDF, Word, text files) or URLs. Useful for migrating existing content to a new website or analyzing documents for content to include.';
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
                'source' => [
                    'type' => 'string',
                    'description' => 'URL or local file path to extract content from',
                ],
                'format' => [
                    'type' => 'string',
                    'enum' => ['text', 'markdown', 'html'],
                    'description' => 'Output format for extracted content (default: text)',
                ],
                'max_length' => [
                    'type' => 'integer',
                    'description' => 'Maximum characters to return (default: 50000)',
                ],
            ],
            'required' => ['source'],
        ];
    }

    protected function validationRules(): array
    {
        return [
            'source' => 'required|string',
            'format' => 'nullable|string|in:text,markdown,html',
            'max_length' => 'nullable|integer|min:100|max:500000',
        ];
    }

    public function execute(array $params): array
    {
        $source = $params['source'];
        $format = $params['format'] ?? 'text';
        $maxLength = $params['max_length'] ?? 50000;

        try {
            if ($this->isUrl($source)) {
                $result = $this->extractFromUrl($source, $format);
            } else {
                if (! file_exists($source)) {
                    return ['success' => false, 'error' => "File not found: {$source}"];
                }
                $result = $this->extractFromFile($source, $format);
            }

            if (strlen($result['content']) > $maxLength) {
                $result['content'] = substr($result['content'], 0, $maxLength);
                $result['truncated'] = true;
            }

            return array_merge(['success' => true], $result);
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function isUrl(string $source): bool
    {
        return Str::startsWith($source, ['http://', 'https://']);
    }

    protected function extractFromUrl(string $url, string $format): array
    {
        $parsedUrl = parse_url($url);
        $path = $parsedUrl['path'] ?? '';
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($extension, ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'])) {
            $tempFile = $this->downloadToTemp($url);
            $result = $this->extractFromFile($tempFile, $format);
            @unlink($tempFile);

            return $result;
        }

        $response = Http::timeout(30)->get($url);

        if (! $response->successful()) {
            throw new \Exception('Failed to fetch URL: HTTP '.$response->status());
        }

        $contentType = $response->header('Content-Type') ?? '';
        $body = $response->body();

        if (str_contains($contentType, 'text/html')) {
            return $this->extractFromHtml($body, $format, $url);
        }

        if (str_contains($contentType, 'text/plain')) {
            return [
                'content' => $body,
                'format' => 'text',
                'source_type' => 'text',
                'word_count' => str_word_count($body),
            ];
        }

        if (str_contains($contentType, 'application/json')) {
            return [
                'content' => json_encode(json_decode($body), JSON_PRETTY_PRINT),
                'format' => 'json',
                'source_type' => 'json',
            ];
        }

        return [
            'content' => $body,
            'format' => 'text',
            'source_type' => 'unknown',
            'word_count' => str_word_count($body),
        ];
    }

    protected function extractFromFile(string $filePath, string $format): array
    {
        $mimeType = mime_content_type($filePath);
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        return match (true) {
            $mimeType === 'application/pdf' || $extension === 'pdf' => $this->extractFromPdf($filePath, $format),
            in_array($mimeType, [
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ]) || in_array($extension, ['doc', 'docx']) => $this->extractFromWord($filePath, $format),
            str_starts_with($mimeType, 'text/') || in_array($extension, ['txt', 'md', 'csv', 'json']) => $this->extractFromTextFile($filePath, $format),
            $mimeType === 'text/html' || $extension === 'html' => $this->extractFromHtml(file_get_contents($filePath), $format),
            default => throw new \Exception("Unsupported file type: {$mimeType} ({$extension})"),
        };
    }

    protected function extractFromPdf(string $filePath, string $format): array
    {
        if ($this->commandExists('pdftotext')) {
            $outputFile = sys_get_temp_dir().'/'.Str::uuid().'.txt';
            $result = Process::run(['pdftotext', '-layout', $filePath, $outputFile]);

            if ($result->successful() && file_exists($outputFile)) {
                $content = file_get_contents($outputFile);
                @unlink($outputFile);

                return [
                    'content' => $this->formatContent($content, $format),
                    'format' => $format,
                    'source_type' => 'pdf',
                    'word_count' => str_word_count($content),
                    'extraction_method' => 'pdftotext',
                ];
            }
        }

        $content = $this->extractPdfTextNative($filePath);

        return [
            'content' => $this->formatContent($content, $format),
            'format' => $format,
            'source_type' => 'pdf',
            'word_count' => str_word_count($content),
            'extraction_method' => 'native',
        ];
    }

    protected function extractPdfTextNative(string $filePath): string
    {
        $content = file_get_contents($filePath);
        $text = '';

        if (preg_match_all('/stream\s*(.+?)\s*endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $decoded = @gzuncompress($stream);
                if ($decoded) {
                    if (preg_match_all('/\(([^)]+)\)/', $decoded, $textMatches)) {
                        $text .= implode(' ', $textMatches[1]).' ';
                    }
                    if (preg_match_all('/\[([^\]]+)\]\s*TJ/', $decoded, $tjMatches)) {
                        foreach ($tjMatches[1] as $tj) {
                            if (preg_match_all('/\(([^)]+)\)/', $tj, $innerText)) {
                                $text .= implode('', $innerText[1]);
                            }
                        }
                    }
                }
            }
        }

        if (empty(trim($text))) {
            if (preg_match_all('/BT\s*(.+?)\s*ET/s', $content, $btMatches)) {
                foreach ($btMatches[1] as $bt) {
                    if (preg_match_all('/\(([^)]+)\)/', $bt, $textInBt)) {
                        $text .= implode(' ', $textInBt[1]).' ';
                    }
                }
            }
        }

        return trim($text) ?: '[PDF text extraction limited - document may contain scanned images or complex formatting]';
    }

    protected function extractFromWord(string $filePath, string $format): array
    {
        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'docx') {
            $content = $this->extractDocxText($filePath);
        } else {
            $content = $this->extractDocText($filePath);
        }

        return [
            'content' => $this->formatContent($content, $format),
            'format' => $format,
            'source_type' => 'word',
            'word_count' => str_word_count($content),
        ];
    }

    protected function extractDocxText(string $filePath): string
    {
        $zip = new \ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new \Exception('Unable to open DOCX file');
        }

        $content = $zip->getFromName('word/document.xml');
        $zip->close();

        if (! $content) {
            throw new \Exception('Unable to read DOCX content');
        }

        $content = str_replace('</w:p>', "\n", $content);
        $content = str_replace('</w:tr>', "\n", $content);
        $content = strip_tags($content);

        return html_entity_decode(trim($content));
    }

    protected function extractDocText(string $filePath): string
    {
        $content = file_get_contents($filePath);
        $text = '';

        if (preg_match_all('/[\x20-\x7E]{4,}/', $content, $matches)) {
            $text = implode(' ', $matches[0]);
        }

        return trim($text) ?: '[Unable to extract text from legacy .doc format]';
    }

    protected function extractFromTextFile(string $filePath, string $format): array
    {
        $content = file_get_contents($filePath);

        return [
            'content' => $this->formatContent($content, $format),
            'format' => $format,
            'source_type' => 'text',
            'word_count' => str_word_count($content),
        ];
    }

    protected function extractFromHtml(string $html, string $format, ?string $sourceUrl = null): array
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML($html, LIBXML_NOERROR);

        $xpath = new \DOMXPath($dom);

        foreach ($xpath->query('//script|//style|//noscript|//header|//footer|//nav|//aside') as $node) {
            $node->parentNode->removeChild($node);
        }

        $title = '';
        $titleNodes = $xpath->query('//title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        $mainContent = '';
        $contentSelectors = ['//main', '//article', '//*[contains(@class, "content")]', '//body'];

        foreach ($contentSelectors as $selector) {
            $nodes = $xpath->query($selector);
            if ($nodes->length > 0) {
                $mainContent = $nodes->item(0)->textContent;
                break;
            }
        }

        $mainContent = preg_replace('/\s+/', ' ', $mainContent);
        $mainContent = trim($mainContent);

        if ($format === 'markdown' || $format === 'html') {
            $mainContent = $this->htmlToMarkdown($html);
        }

        return [
            'content' => $mainContent,
            'format' => $format,
            'source_type' => 'html',
            'title' => $title,
            'source_url' => $sourceUrl,
            'word_count' => str_word_count($mainContent),
        ];
    }

    protected function htmlToMarkdown(string $html): string
    {
        $html = preg_replace('/<h1[^>]*>(.*?)<\/h1>/si', "# $1\n\n", $html);
        $html = preg_replace('/<h2[^>]*>(.*?)<\/h2>/si', "## $1\n\n", $html);
        $html = preg_replace('/<h3[^>]*>(.*?)<\/h3>/si', "### $1\n\n", $html);
        $html = preg_replace('/<h4[^>]*>(.*?)<\/h4>/si', "#### $1\n\n", $html);
        $html = preg_replace('/<p[^>]*>(.*?)<\/p>/si', "$1\n\n", $html);
        $html = preg_replace('/<br\s*\/?>/i', "\n", $html);
        $html = preg_replace('/<strong[^>]*>(.*?)<\/strong>/si', '**$1**', $html);
        $html = preg_replace('/<b[^>]*>(.*?)<\/b>/si', '**$1**', $html);
        $html = preg_replace('/<em[^>]*>(.*?)<\/em>/si', '*$1*', $html);
        $html = preg_replace('/<i[^>]*>(.*?)<\/i>/si', '*$1*', $html);
        $html = preg_replace('/<a[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/si', '[$2]($1)', $html);
        $html = preg_replace('/<li[^>]*>(.*?)<\/li>/si', "- $1\n", $html);

        $html = strip_tags($html);
        $html = html_entity_decode($html);
        $html = preg_replace('/\n{3,}/', "\n\n", $html);

        return trim($html);
    }

    protected function formatContent(string $content, string $format): string
    {
        if ($format === 'markdown') {
            $content = preg_replace('/\n{3,}/', "\n\n", $content);
        }

        return trim($content);
    }

    protected function downloadToTemp(string $url): string
    {
        $response = Http::timeout(60)->get($url);

        if (! $response->successful()) {
            throw new \Exception('Failed to download file: HTTP '.$response->status());
        }

        $extension = pathinfo(parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION) ?: 'bin';
        $tempFile = sys_get_temp_dir().'/'.Str::uuid().'.'.$extension;
        file_put_contents($tempFile, $response->body());

        return $tempFile;
    }

    protected function commandExists(string $command): bool
    {
        $result = Process::run(['which', $command]);

        return $result->successful();
    }
}
