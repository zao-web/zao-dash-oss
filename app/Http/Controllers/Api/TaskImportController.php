<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskComment;
use App\Services\AI\ClaudeCliService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use PhpOffice\PhpSpreadsheet\IOFactory;

class TaskImportController extends Controller
{
    /**
     * Parse a document (SOW, brief) and extract tasks using AI.
     */
    public function parseDocument(Request $request, Project $project)
    {
        $request->validate([
            'content' => 'required_without:url|string',
            'url' => 'required_without:content|url',
            'source_type' => 'required|in:paste,file,google_doc',
        ]);

        $content = $request->input('content');

        // If URL provided, fetch the content
        if ($request->input('url')) {
            $content = $this->fetchDocumentContent($request->input('url'));
            if (! $content) {
                return response()->json(['error' => 'Could not fetch document content'], 422);
            }
        }

        // Extract tasks using Claude
        $tasks = $this->extractTasksWithAI($content, $project);

        return response()->json([
            'tasks' => $tasks,
            'source' => $request->input('source_type'),
            'project' => [
                'id' => $project->id,
                'name' => $project->name,
            ],
        ]);
    }

    /**
     * Import selected tasks into the project.
     */
    public function importTasks(Request $request, Project $project)
    {
        $request->validate([
            'tasks' => 'required|array',
            'tasks.*.title' => 'required|string',
            'tasks.*.description' => 'nullable|string',
            'tasks.*.priority' => 'nullable|in:low,medium,high,urgent',
            'tasks.*.estimated_hours' => 'nullable|numeric',
            'tasks.*.status' => 'nullable|in:pending,in_progress,review,completed',
            'tasks.*.extra_data' => 'nullable|array',
            'milestone_id' => 'nullable|exists:milestones,id',
            'source' => 'nullable|in:sow_import,csv_import,excel_import,notion',
        ]);

        $imported = [];
        $milestoneId = $request->input('milestone_id');
        $source = $request->input('source', 'sow_import');

        foreach ($request->input('tasks') as $taskData) {
            $task = Task::create([
                'project_id' => $project->id,
                'milestone_id' => $milestoneId,
                'title' => $taskData['title'],
                'description' => $taskData['description'] ?? null,
                'status' => $taskData['status'] ?? 'pending',
                'priority' => $taskData['priority'] ?? 'medium',
                'estimated_hours' => $taskData['estimated_hours'] ?? null,
                'source' => $source,
                'metadata' => [
                    'imported_at' => now()->toIso8601String(),
                    'ai_generated' => true,
                    'context' => $taskData['context'] ?? null,
                ],
            ]);

            // Create a system comment with unmapped spreadsheet columns
            $extraData = $taskData['extra_data'] ?? [];
            if (! empty($extraData)) {
                $lines = ['**Imported spreadsheet data:**'];
                foreach ($extraData as $column => $value) {
                    $lines[] = "- **{$column}:** {$value}";
                }

                TaskComment::create([
                    'task_id' => $task->id,
                    'user_id' => $request->user()?->id,
                    'type' => TaskComment::TYPE_SYSTEM,
                    'content' => implode("\n", $lines),
                ]);
            }

            $imported[] = [
                'id' => $task->id,
                'title' => $task->title,
            ];
        }

        return response()->json([
            'message' => 'Imported '.count($imported).' tasks',
            'tasks' => $imported,
        ]);
    }

    /**
     * Import tasks from CSV file.
     */
    public function importCsv(Request $request, Project $project)
    {
        $request->validate([
            'file' => 'required|file|mimes:csv,txt',
            'title_column' => 'required|string',
            'status_column' => 'nullable|string',
            'description_column' => 'nullable|string',
            'priority_column' => 'nullable|string',
            'assignee_filter' => 'nullable|string',
            'status_map' => 'nullable|array',
        ]);

        $file = $request->file('file');
        $handle = fopen($file->getRealPath(), 'r');
        $headers = fgetcsv($handle);

        if (! $headers) {
            return response()->json(['error' => 'Could not read CSV headers'], 422);
        }

        // Remove BOM if present
        $headers[0] = preg_replace('/^\xEF\xBB\xBF/', '', $headers[0]);

        $colMap = array_flip($headers);
        $titleCol = $request->input('title_column');
        $statusCol = $request->input('status_column');
        $descCol = $request->input('description_column');
        $priorityCol = $request->input('priority_column');
        $assigneeFilter = $request->input('assignee_filter');

        if (! isset($colMap[$titleCol])) {
            fclose($handle);

            return response()->json([
                'error' => "Title column '{$titleCol}' not found",
                'available_columns' => $headers,
            ], 422);
        }

        $statusMap = array_merge([
            'Not started' => 'pending',
            'To Do' => 'pending',
            'In progress' => 'in_progress',
            'In Progress' => 'in_progress',
            'In Review' => 'review',
            'Review' => 'review',
            'Done' => 'completed',
            'Completed' => 'completed',
        ], $request->input('status_map', []));

        $priorityMap = [
            'High' => 'high',
            'Medium' => 'medium',
            'Low' => 'low',
            'Urgent' => 'urgent',
        ];

        $tasks = [];
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $title = trim($row[$colMap[$titleCol]] ?? '');

            if (empty($title) || str_starts_with($title, 'http')) {
                $skipped++;

                continue;
            }

            // Filter by assignee if specified
            if ($assigneeFilter && isset($colMap['Assignee'])) {
                $assignee = $row[$colMap['Assignee']] ?? '';
                $filterNames = array_map('trim', explode(',', $assigneeFilter));
                $matchFound = false;
                foreach ($filterNames as $name) {
                    if (stripos($assignee, $name) !== false) {
                        $matchFound = true;
                        break;
                    }
                }
                if (! $matchFound) {
                    $skipped++;

                    continue;
                }
            }

            $rawStatus = isset($colMap[$statusCol]) ? trim($row[$colMap[$statusCol]] ?? '') : '';
            $rawPriority = isset($colMap[$priorityCol]) ? trim($row[$colMap[$priorityCol]] ?? '') : '';

            $tasks[] = [
                'title' => $title,
                'description' => isset($colMap[$descCol]) ? trim($row[$colMap[$descCol]] ?? '') : null,
                'status' => $statusMap[$rawStatus] ?? 'pending',
                'priority' => $priorityMap[$rawPriority] ?? 'medium',
                'original_status' => $rawStatus,
                'assignee' => isset($colMap['Assignee']) ? $row[$colMap['Assignee']] : null,
            ];
        }

        fclose($handle);

        return response()->json([
            'tasks' => $tasks,
            'skipped' => $skipped,
            'columns' => $headers,
        ]);
    }

    /**
     * Get sheet names from an uploaded Excel file.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExcelSheets(Request $request, Project $project)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());

        $sheetNames = $spreadsheet->getSheetNames();

        // Get headers from first sheet for initial column mapping
        $firstSheet = $spreadsheet->getSheet(0);
        $headers = [];
        $highestColumn = $firstSheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $firstSheet->getCell([$col, 1])->getValue();
            if ($cellValue !== null && trim((string) $cellValue) !== '') {
                $headers[] = trim((string) $cellValue);
            }
        }

        return response()->json([
            'sheets' => $sheetNames,
            'headers' => $headers,
        ]);
    }

    /**
     * Get headers for a specific sheet in an uploaded Excel file.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getExcelSheetHeaders(Request $request, Project $project)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'sheet_name' => 'required|string',
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());

        $sheetName = $request->input('sheet_name');
        $sheet = $spreadsheet->getSheetByName($sheetName);

        if (! $sheet) {
            return response()->json(['error' => "Sheet '{$sheetName}' not found"], 422);
        }

        $headers = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $sheet->getCell([$col, 1])->getValue();
            if ($cellValue !== null && trim((string) $cellValue) !== '') {
                $headers[] = trim((string) $cellValue);
            }
        }

        return response()->json(['headers' => $headers]);
    }

    /**
     * Import tasks from an Excel file with column mapping and hyperlink extraction.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function importExcel(Request $request, Project $project)
    {
        $request->validate([
            'file' => 'required|file|mimes:xlsx,xls',
            'sheet_name' => 'nullable|string',
            'title_column' => 'required|string',
            'status_column' => 'nullable|string',
            'description_column' => 'nullable|string',
            'priority_column' => 'nullable|string',
            'notes_column' => 'nullable|string',
            'assignee_filter' => 'nullable|string',
        ]);

        $file = $request->file('file');
        $spreadsheet = IOFactory::load($file->getRealPath());

        $sheetName = $request->input('sheet_name');
        $sheet = $sheetName ? $spreadsheet->getSheetByName($sheetName) : $spreadsheet->getSheet(0);

        if (! $sheet) {
            return response()->json(['error' => "Sheet '{$sheetName}' not found"], 422);
        }

        // Read headers from row 1
        $headers = [];
        $highestColumn = $sheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);

        for ($col = 1; $col <= $highestColumnIndex; $col++) {
            $cellValue = $sheet->getCell([$col, 1])->getValue();
            if ($cellValue !== null) {
                $headers[$col] = trim((string) $cellValue);
            }
        }

        $colMap = array_flip($headers);
        $titleCol = $request->input('title_column');
        $statusCol = $request->input('status_column');
        $descCol = $request->input('description_column');
        $priorityCol = $request->input('priority_column');
        $notesCol = $request->input('notes_column');
        $assigneeFilter = $request->input('assignee_filter');

        // Identify mapped columns so we can collect unmapped ones as extra data
        $mappedColumns = array_filter([$titleCol, $statusCol, $descCol, $priorityCol, $notesCol, 'Assignee']);

        if (! isset($colMap[$titleCol])) {
            return response()->json([
                'error' => "Title column '{$titleCol}' not found",
                'available_columns' => array_values($headers),
            ], 422);
        }

        $statusMap = [
            'Not started' => 'pending',
            'To Do' => 'pending',
            'Open' => 'pending',
            'In progress' => 'in_progress',
            'In Progress' => 'in_progress',
            'In Review' => 'review',
            'Review' => 'review',
            'Done' => 'completed',
            'Completed' => 'completed',
            'Complete' => 'completed',
            'Fixed' => 'completed',
            'Resolved' => 'completed',
        ];

        $priorityMap = [
            'High' => 'high',
            'Medium' => 'medium',
            'Low' => 'low',
            'Urgent' => 'urgent',
            'Critical' => 'urgent',
        ];

        $tasks = [];
        $skipped = 0;
        $highestRow = $sheet->getHighestRow();

        for ($row = 2; $row <= $highestRow; $row++) {
            $titleColIndex = $colMap[$titleCol];
            $titleCell = $sheet->getCell([$titleColIndex, $row]);
            $title = trim((string) ($titleCell->getValue() ?? ''));

            if (empty($title) || str_starts_with($title, 'http')) {
                $skipped++;

                continue;
            }

            // Filter by assignee if specified
            if ($assigneeFilter && isset($colMap['Assignee'])) {
                $assignee = (string) ($sheet->getCell([$colMap['Assignee'], $row])->getValue() ?? '');
                $filterNames = array_map('trim', explode(',', $assigneeFilter));
                $matchFound = false;
                foreach ($filterNames as $name) {
                    if (stripos($assignee, $name) !== false) {
                        $matchFound = true;
                        break;
                    }
                }
                if (! $matchFound) {
                    $skipped++;

                    continue;
                }
            }

            // Get status and priority
            $rawStatus = '';
            if ($statusCol && isset($colMap[$statusCol])) {
                $rawStatus = trim((string) ($sheet->getCell([$colMap[$statusCol], $row])->getValue() ?? ''));
            }

            $rawPriority = '';
            if ($priorityCol && isset($colMap[$priorityCol])) {
                $rawPriority = trim((string) ($sheet->getCell([$colMap[$priorityCol], $row])->getValue() ?? ''));
            }

            // Get description and notes
            $description = '';
            if ($descCol && isset($colMap[$descCol])) {
                $description = trim((string) ($sheet->getCell([$colMap[$descCol], $row])->getValue() ?? ''));
            }
            if ($notesCol && isset($colMap[$notesCol])) {
                $notesCell = $sheet->getCell([$colMap[$notesCol], $row]);
                $notes = trim((string) ($notesCell->getValue() ?? ''));
                // If cell has a hyperlink but no display text, use the URL
                if ($notes === '' && $notesCell->getHyperlink() && $notesCell->getHyperlink()->getUrl()) {
                    $notes = $notesCell->getHyperlink()->getUrl();
                }
                if ($notes) {
                    $description = $description ? $description."\n\n".$notes : $notes;
                }
            }

            // Extract hyperlinks from all cells in this row
            $links = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cell = $sheet->getCell([$col, $row]);
                $hyperlink = $cell->getHyperlink();
                if ($hyperlink && $hyperlink->getUrl()) {
                    $url = $hyperlink->getUrl();
                    $colHeader = $headers[$col] ?? "Column {$col}";
                    $links[] = [
                        'url' => $url,
                        'column' => $colHeader,
                    ];
                }
            }

            // Append hyperlinks to description as markdown
            if (! empty($links)) {
                $linkLines = [];
                foreach ($links as $link) {
                    $linkLines[] = "**{$link['column']}:** [{$link['url']}]({$link['url']})";
                }
                $linkSection = implode("\n", $linkLines);
                $description = $description ? $description."\n\n---\n".$linkSection : $linkSection;
            }

            // Build metadata from extracted links
            $metadata = [];
            foreach ($links as $link) {
                $url = $link['url'];
                if (str_contains($url, 'clickup.com')) {
                    $metadata['clickup_url'] = $url;
                } elseif (str_contains($url, 'example-client.com') || str_contains($url, 'website')) {
                    $metadata['website_url'] = $url;
                } else {
                    $metadata['external_url'] = $url;
                }
            }

            // Collect unmapped columns as extra data
            $extraData = [];
            foreach ($headers as $colIndex => $headerName) {
                if (in_array($headerName, $mappedColumns, true)) {
                    continue;
                }
                $cellValue = trim((string) ($sheet->getCell([$colIndex, $row])->getValue() ?? ''));
                if ($cellValue !== '') {
                    $extraData[$headerName] = $cellValue;
                }
            }

            $tasks[] = [
                'title' => $title,
                'description' => $description ?: null,
                'status' => $statusMap[$rawStatus] ?? 'pending',
                'priority' => $priorityMap[$rawPriority] ?? 'medium',
                'original_status' => $rawStatus,
                'assignee' => isset($colMap['Assignee']) ? (string) ($sheet->getCell([$colMap['Assignee'], $row])->getValue() ?? '') : null,
                'metadata' => $metadata ?: null,
                'extra_data' => $extraData ?: null,
            ];
        }

        return response()->json([
            'tasks' => $tasks,
            'skipped' => $skipped,
            'columns' => array_values($headers),
        ]);
    }

    protected function fetchDocumentContent(string $url): ?string
    {
        // Handle Google Docs
        if (str_contains($url, 'docs.google.com')) {
            // Extract doc ID and convert to export URL
            if (preg_match('/\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
                $docId = $matches[1];
                $exportUrl = "https://docs.google.com/document/d/{$docId}/export?format=txt";

                $response = Http::get($exportUrl);
                if ($response->successful()) {
                    return $response->body();
                }
            }
        }

        // Generic URL fetch
        $response = Http::get($url);
        if ($response->successful()) {
            $html = $response->body();

            // Strip HTML tags for plain text
            return strip_tags($html);
        }

        return null;
    }

    protected function extractTasksWithAI(string $content, Project $project): array
    {
        $cli = new ClaudeCliService;

        $systemPrompt = <<<'PROMPT'
You are a project manager extracting actionable tasks from project documents (SOW, briefs, requirements).

For each task provide:
- title: Clear, concise task title (action-oriented, starts with verb)
- description: Brief description with context and acceptance criteria
- priority: urgent, high, medium, or low
- estimated_hours: Extract from document if mentioned, otherwise estimate
- context: Which section/requirement this relates to

Focus on development, design, content, QA, and DevOps tasks.
Skip vague items, administrative overhead, or completed items.
PROMPT;

        $userPrompt = "Extract actionable tasks from this project document for \"{$project->name}\":\n\n{$content}\n\nReturn a JSON array of task objects.";

        $tasks = $cli->messageJson($userPrompt, $systemPrompt, 'sonnet', 180);

        if (! $tasks || ! is_array($tasks)) {
            return [];
        }

        return $tasks;
    }
}
