<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class SkillController extends Controller
{
    protected string $basePath;

    public function __construct()
    {
        $this->basePath = storage_path('app/skills');
    }

    /**
     * List all skill files/folders.
     */
    public function index(): JsonResponse
    {
        if (! File::isDirectory($this->basePath)) {
            return response()->json(['skills' => [], 'count' => 0]);
        }

        $skills = collect(File::directories($this->basePath))
            ->map(function ($dir) {
                $name = basename($dir);
                $skillFile = $dir.'/SKILL.md';
                $hasSkillFile = File::exists($skillFile);

                return [
                    'slug' => $name,
                    'name' => Str::title(str_replace('-', ' ', $name)),
                    'path' => $name.'/SKILL.md',
                    'exists' => $hasSkillFile,
                    'size' => $hasSkillFile ? File::size($skillFile) : 0,
                    'updated_at' => $hasSkillFile ? date('Y-m-d H:i:s', File::lastModified($skillFile)) : null,
                ];
            })
            ->sortBy('name')
            ->values();

        // Also include root-level .md files (like TONE.md)
        $rootFiles = collect(File::files($this->basePath))
            ->filter(fn ($file) => Str::endsWith($file->getFilename(), '.md'))
            ->map(fn ($file) => [
                'slug' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                'name' => pathinfo($file->getFilename(), PATHINFO_FILENAME),
                'path' => $file->getFilename(),
                'exists' => true,
                'size' => $file->getSize(),
                'updated_at' => date('Y-m-d H:i:s', $file->getMTime()),
                'is_root' => true,
            ])
            ->values();

        return response()->json([
            'skills' => $skills,
            'root_files' => $rootFiles,
            'count' => $skills->count(),
        ]);
    }

    /**
     * Get a skill file's content.
     */
    public function show(string $path): JsonResponse
    {
        $fullPath = $this->resolvePath($path);

        if (! $fullPath || ! File::exists($fullPath)) {
            return response()->json(['error' => 'Skill file not found'], 404);
        }

        return response()->json([
            'path' => $path,
            'content' => File::get($fullPath),
            'size' => File::size($fullPath),
            'updated_at' => date('Y-m-d H:i:s', File::lastModified($fullPath)),
        ]);
    }

    /**
     * Update a skill file's content.
     */
    public function update(Request $request, string $path): JsonResponse
    {
        $validated = $request->validate([
            'content' => 'required|string',
        ]);

        $fullPath = $this->resolvePath($path);

        if (! $fullPath) {
            return response()->json(['error' => 'Invalid path'], 400);
        }

        // Ensure directory exists
        $dir = dirname($fullPath);
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        File::put($fullPath, $validated['content']);

        return response()->json([
            'path' => $path,
            'message' => 'Skill file updated',
            'size' => File::size($fullPath),
            'updated_at' => date('Y-m-d H:i:s', File::lastModified($fullPath)),
        ]);
    }

    /**
     * Create a new skill folder with SKILL.md.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => 'required|string|max:100|regex:/^[a-z0-9-]+$/',
            'content' => 'nullable|string',
        ]);

        $slug = $validated['slug'];
        $dir = $this->basePath.'/'.$slug;
        $skillFile = $dir.'/SKILL.md';

        if (File::exists($skillFile)) {
            return response()->json(['error' => 'Skill already exists'], 409);
        }

        // Create directory and file
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $content = $validated['content'] ?? $this->defaultSkillContent($slug);
        File::put($skillFile, $content);

        return response()->json([
            'slug' => $slug,
            'path' => $slug.'/SKILL.md',
            'message' => 'Skill created',
        ], 201);
    }

    /**
     * Delete a skill file or folder.
     */
    public function destroy(string $path): JsonResponse
    {
        $fullPath = $this->resolvePath($path);

        if (! $fullPath || ! File::exists($fullPath)) {
            return response()->json(['error' => 'Skill file not found'], 404);
        }

        File::delete($fullPath);

        // If the directory is now empty, remove it too
        $dir = dirname($fullPath);
        if (File::isDirectory($dir) && count(File::files($dir)) === 0) {
            File::deleteDirectory($dir);
        }

        return response()->json(['message' => 'Skill deleted']);
    }

    /**
     * Resolve and validate a path to prevent directory traversal.
     */
    protected function resolvePath(string $path): ?string
    {
        // Normalize the path
        $path = str_replace(['..', '\\'], ['', '/'], $path);
        $fullPath = $this->basePath.'/'.ltrim($path, '/');

        // Ensure it's within the base path
        $realBase = realpath($this->basePath);
        $realPath = realpath(dirname($fullPath)).'/'.basename($fullPath);

        if (! $realBase || ! str_starts_with($realPath, $realBase)) {
            return null;
        }

        return $fullPath;
    }

    /**
     * Default content for new skill files.
     */
    protected function defaultSkillContent(string $slug): string
    {
        $name = Str::title(str_replace('-', ' ', $slug));

        return <<<MD
# {$name} Agent

## Role
You are a specialized AI assistant for {$name} tasks.

## Capabilities
- [List capabilities here]

## Guidelines
- [Add guidelines here]

## Output Format
[Describe expected output format]
MD;
    }
}
