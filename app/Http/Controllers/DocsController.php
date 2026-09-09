<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Inertia\Inertia;

class DocsController extends Controller
{
    protected string $docsPath;

    public function __construct()
    {
        $this->docsPath = base_path('docs');
    }

    /**
     * Display documentation index.
     */
    public function index(Request $request, ?string $slug = null)
    {
        $docs = $this->getDocsList();
        $currentDoc = null;
        $content = null;

        if ($slug) {
            $currentDoc = $this->findDocBySlug($docs, $slug);
            if ($currentDoc) {
                $content = $this->getDocContent($currentDoc['path']);
            }
        } elseif (! empty($docs)) {
            // Default to first doc
            $currentDoc = $docs[0];
            $content = $this->getDocContent($currentDoc['path']);
        }

        return Inertia::render('Docs/Index', [
            'docs' => $docs,
            'currentDoc' => $currentDoc,
            'content' => $content,
        ]);
    }

    /**
     * Search documentation.
     */
    public function search(Request $request)
    {
        $query = $request->get('q', '');

        if (strlen($query) < 2) {
            return response()->json(['results' => []]);
        }

        $results = [];
        $docs = $this->getAllDocFiles();

        foreach ($docs as $doc) {
            $content = File::get($doc['path']);
            $matches = $this->searchInContent($content, $query);

            if (! empty($matches)) {
                $results[] = [
                    'slug' => $doc['slug'],
                    'title' => $doc['title'],
                    'category' => $doc['category'],
                    'matches' => array_slice($matches, 0, 3), // Limit matches
                ];
            }
        }

        // Sort by number of matches
        usort($results, fn ($a, $b) => count($b['matches']) - count($a['matches']));

        return response()->json(['results' => array_slice($results, 0, 10)]);
    }

    /**
     * Get list of all documentation files.
     */
    private function getDocsList(): array
    {
        $docs = [];

        if (! File::isDirectory($this->docsPath)) {
            return $docs;
        }

        // Get root level docs
        foreach (File::files($this->docsPath) as $file) {
            if ($file->getExtension() === 'md') {
                $docs[] = $this->parseDocFile($file->getPathname(), null);
            }
        }

        // Get docs from subdirectories
        foreach (File::directories($this->docsPath) as $dir) {
            $category = basename($dir);
            foreach (File::files($dir) as $file) {
                if ($file->getExtension() === 'md') {
                    $docs[] = $this->parseDocFile($file->getPathname(), $category);
                }
            }
        }

        // Sort by category then title
        usort($docs, function ($a, $b) {
            if ($a['category'] !== $b['category']) {
                // Root docs first (null category)
                if ($a['category'] === null) {
                    return -1;
                }
                if ($b['category'] === null) {
                    return 1;
                }

                return strcmp($a['category'], $b['category']);
            }

            return strcmp($a['title'], $b['title']);
        });

        return $docs;
    }

    /**
     * Parse a documentation file.
     */
    private function parseDocFile(string $path, ?string $category): array
    {
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $content = File::get($path);

        // Extract title from first H1
        $title = $filename;
        if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
            $title = trim($matches[1]);
        }

        // Extract description from first paragraph
        $description = '';
        if (preg_match('/^#.+\n\n(.+?)(?:\n\n|$)/s', $content, $matches)) {
            $description = Str::limit(trim($matches[1]), 150);
        }

        // Count sections (H2 headers)
        preg_match_all('/^##\s+.+$/m', $content, $sections);
        $sectionCount = count($sections[0]);

        // Estimate reading time (200 words per minute)
        $wordCount = str_word_count(strip_tags($content));
        $readingTime = max(1, ceil($wordCount / 200));

        return [
            'slug' => $category ? "{$category}/".Str::slug($filename) : Str::slug($filename),
            'title' => $title,
            'description' => $description,
            'category' => $category,
            'path' => $path,
            'sections' => $sectionCount,
            'reading_time' => $readingTime,
            'updated_at' => date('Y-m-d', File::lastModified($path)),
        ];
    }

    /**
     * Find a doc by its slug.
     */
    private function findDocBySlug(array $docs, string $slug): ?array
    {
        foreach ($docs as $doc) {
            if ($doc['slug'] === $slug) {
                return $doc;
            }
        }

        return null;
    }

    /**
     * Get doc content with table of contents.
     */
    private function getDocContent(string $path): array
    {
        $content = File::get($path);

        // Extract table of contents from H2 and H3 headers
        $toc = [];
        preg_match_all('/^(#{2,3})\s+(.+)$/m', $content, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $level = strlen($match[1]);
            $text = trim($match[2]);
            $anchor = Str::slug($text);

            $toc[] = [
                'level' => $level,
                'text' => $text,
                'anchor' => $anchor,
            ];
        }

        return [
            'markdown' => $content,
            'toc' => $toc,
        ];
    }

    /**
     * Get all doc files for searching.
     */
    private function getAllDocFiles(): array
    {
        $files = [];

        if (! File::isDirectory($this->docsPath)) {
            return $files;
        }

        foreach (File::allFiles($this->docsPath) as $file) {
            if ($file->getExtension() === 'md') {
                $relativePath = str_replace($this->docsPath.'/', '', $file->getPathname());
                $parts = explode('/', $relativePath);
                $category = count($parts) > 1 ? $parts[0] : null;
                $filename = pathinfo($file->getFilename(), PATHINFO_FILENAME);

                $content = File::get($file->getPathname());
                $title = $filename;
                if (preg_match('/^#\s+(.+)$/m', $content, $matches)) {
                    $title = trim($matches[1]);
                }

                $files[] = [
                    'path' => $file->getPathname(),
                    'slug' => $category ? "{$category}/".Str::slug($filename) : Str::slug($filename),
                    'title' => $title,
                    'category' => $category,
                ];
            }
        }

        return $files;
    }

    /**
     * Search within content and return context.
     */
    private function searchInContent(string $content, string $query): array
    {
        $matches = [];
        $query = strtolower($query);
        $lines = explode("\n", $content);

        foreach ($lines as $lineNum => $line) {
            if (stripos($line, $query) !== false) {
                // Get surrounding context
                $start = max(0, $lineNum - 1);
                $end = min(count($lines) - 1, $lineNum + 1);

                $context = implode("\n", array_slice($lines, $start, $end - $start + 1));

                // Highlight match
                $highlighted = preg_replace(
                    '/('.preg_quote($query, '/').')/i',
                    '**$1**',
                    $context
                );

                $matches[] = [
                    'line' => $lineNum + 1,
                    'context' => Str::limit($highlighted, 200),
                ];
            }
        }

        return $matches;
    }
}
