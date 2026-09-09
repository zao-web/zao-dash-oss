<?php

namespace App\Http\Controllers;

use App\Agents\ToolRegistry;
use App\Jobs\AnalyzeOlliePagesJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;

class OllieController extends Controller
{
    public function __construct(
        protected ToolRegistry $toolRegistry
    ) {}

    /**
     * Site builder wizard.
     */
    public function builder()
    {
        // Get existing projects
        $projectsPath = 'ollie-projects';
        $projects = [];

        if (Storage::disk('local')->exists($projectsPath)) {
            $dirs = Storage::disk('local')->directories($projectsPath);
            foreach ($dirs as $dir) {
                $manifestPath = "{$dir}/manifest.json";
                if (Storage::disk('local')->exists($manifestPath)) {
                    $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);
                    $projects[] = $manifest;
                }
            }
        }

        // Get patterns from tool
        $patternsTool = $this->toolRegistry->get('ollie-list-patterns');
        $patternsResult = $patternsTool ? $patternsTool->execute([]) : ['patterns' => []];

        return Inertia::render('Ollie/SiteBuilder', [
            'projects' => $projects,
            'patterns' => $patternsResult['patterns'] ?? [],
        ]);
    }

    /**
     * Create a new project.
     */
    public function createProject(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:new,migration,redesign',
            'environment' => 'nullable|in:staging,production',
            'client_id' => 'nullable|integer|exists:clients,id',
        ]);

        $result = $this->toolRegistry->execute('ollie-create-project', $validated);

        return response()->json($result);
    }

    /**
     * Parse a brief document.
     */
    public function parseBrief(Request $request)
    {
        $validated = $request->validate([
            'content' => 'required_without:file|string',
            'file' => 'required_without:content|file|mimes:pdf,txt,doc,docx|max:10240',
            'url' => 'nullable|url',
        ]);

        // Handle file upload
        if ($request->hasFile('file')) {
            $file = $request->file('file');
            $content = file_get_contents($file->getRealPath());
            // For PDFs, we'd need a PDF parser - for now, just pass the path
            $validated['content'] = $content;
            $validated['source_type'] = 'pdf';
        } else {
            $validated['source_type'] = 'text';
        }

        $result = $this->toolRegistry->execute('ollie-parse-brief', $validated);

        return response()->json($result);
    }

    /**
     * Analyze an existing site for migration.
     */
    public function analyzeSite(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'depth' => 'nullable|in:shallow,deep',
            'extract_content' => 'nullable|boolean',
        ]);

        $result = $this->toolRegistry->execute('ollie-analyze-site', $validated);

        return response()->json($result);
    }

    /**
     * Analyze multiple pages in background for pattern detection.
     */
    public function analyzePages(Request $request)
    {
        $validated = $request->validate([
            'base_url' => 'required|url',
            'pages' => 'required|array|min:1|max:50',
            'pages.*.url' => 'nullable|url',
            'pages.*.path' => 'nullable|string',
            'pages.*.name' => 'nullable|string',
        ]);

        $batchId = Str::uuid()->toString();

        AnalyzeOlliePagesJob::dispatch(
            $batchId,
            $validated['pages'],
            $validated['base_url'],
            $request->user()?->id
        );

        return response()->json([
            'batch_id' => $batchId,
            'status' => 'processing',
            'message' => 'Page analysis started',
            'total_pages' => count($validated['pages']),
        ]);
    }

    /**
     * Get page analysis results.
     */
    public function getPageAnalysis(string $batchId)
    {
        $results = Cache::get("ollie_page_analysis:{$batchId}");
        $progress = Cache::get("ollie_page_analysis_progress:{$batchId}");

        if (! $results && ! $progress) {
            return response()->json(['error' => 'Analysis not found or expired'], 404);
        }

        return response()->json([
            'batch_id' => $batchId,
            'status' => $results ? 'completed' : 'processing',
            'progress' => $progress,
            'results' => $results,
        ]);
    }

    /**
     * Analyze a single page synchronously.
     */
    public function analyzePage(Request $request)
    {
        $validated = $request->validate([
            'url' => 'required|url',
            'page_name' => 'nullable|string|max:255',
        ]);

        $result = $this->toolRegistry->execute('ollie-analyze-page', $validated);

        return response()->json($result);
    }

    /**
     * Analyze a GitHub repository for code migration.
     */
    public function analyzeRepo(Request $request)
    {
        $validated = $request->validate([
            'repo_url' => 'required|url|regex:/github\.com/',
            'branch' => 'nullable|string|max:100',
            'depth' => 'nullable|in:quick,standard,deep',
            'focus_paths' => 'nullable|array',
            'focus_paths.*' => 'string',
        ]);

        $result = $this->toolRegistry->execute('ollie-analyze-repo', $validated);

        return response()->json($result);
    }

    /**
     * Generate theme.json from design config.
     */
    public function generateThemeJson(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'colors' => 'required|array',
            'colors.primary' => 'required|string',
            'colors.secondary' => 'nullable|string',
            'colors.accent' => 'nullable|string',
            'typography' => 'nullable|array',
            'base_style' => 'nullable|in:default,agency,creator,startup,studio',
        ]);

        $result = $this->toolRegistry->execute('ollie-generate-theme-json', $validated);

        return response()->json($result);
    }

    /**
     * List available patterns.
     */
    public function patterns(Request $request)
    {
        $category = $request->get('category', 'all');
        $search = $request->get('search');

        $result = $this->toolRegistry->execute('ollie-list-patterns', [
            'category' => $category,
            'search' => $search,
        ]);

        return response()->json($result);
    }

    /**
     * Compose a page from patterns.
     */
    public function composePage(Request $request)
    {
        $validated = $request->validate([
            'project_id' => 'required|string',
            'page_title' => 'required|string|max:255',
            'page_slug' => 'nullable|string|max:255',
            'patterns' => 'required|array|min:1',
            'patterns.*' => 'string',
            'template' => 'nullable|in:page-no-title,page-with-sidebar,default',
        ]);

        $result = $this->toolRegistry->execute('ollie-compose-page', $validated);

        return response()->json($result);
    }

    /**
     * Get project status.
     */
    public function projectStatus(string $projectId)
    {
        $manifestPath = "ollie-projects/{$projectId}/manifest.json";

        if (! Storage::disk('local')->exists($manifestPath)) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $manifest = json_decode(Storage::disk('local')->get($manifestPath), true);

        // Get page files
        $pagesPath = "ollie-projects/{$projectId}/pages";
        $pages = [];
        if (Storage::disk('local')->exists($pagesPath)) {
            $pageFiles = Storage::disk('local')->files($pagesPath);
            foreach ($pageFiles as $file) {
                if (str_ends_with($file, '.json')) {
                    $pages[] = json_decode(Storage::disk('local')->get($file), true);
                }
            }
        }

        return response()->json([
            'project' => $manifest,
            'pages' => $pages,
        ]);
    }

    /**
     * Download project as ZIP.
     */
    public function downloadProject(string $projectId)
    {
        $basePath = storage_path("app/ollie-projects/{$projectId}");

        if (! is_dir($basePath)) {
            return response()->json(['error' => 'Project not found'], 404);
        }

        $zipPath = storage_path("app/ollie-projects/{$projectId}.zip");

        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($basePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (! $file->isDir()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($basePath) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }

        $zip->close();

        return response()->download($zipPath)->deleteFileAfterSend(true);
    }
}
