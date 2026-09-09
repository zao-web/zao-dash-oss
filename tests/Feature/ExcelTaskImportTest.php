<?php

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

uses(RefreshDatabase::class);

function createTestExcelFile(array $sheets = []): UploadedFile
{
    $spreadsheet = new Spreadsheet;

    foreach ($sheets as $index => $sheetData) {
        if ($index === 0) {
            $sheet = $spreadsheet->getActiveSheet();
        } else {
            $sheet = $spreadsheet->createSheet();
        }
        $sheet->setTitle($sheetData['name']);

        // Write headers
        foreach ($sheetData['headers'] as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        // Write rows
        foreach ($sheetData['rows'] as $rowIndex => $row) {
            foreach ($row as $col => $cellData) {
                $colIndex = $col + 1;
                $rowNum = $rowIndex + 2;

                if (is_array($cellData)) {
                    $sheet->setCellValue([$colIndex, $rowNum], $cellData['value']);
                    if (isset($cellData['url'])) {
                        $sheet->getCell([$colIndex, $rowNum])->getHyperlink()->setUrl($cellData['url']);
                    }
                } else {
                    $sheet->setCellValue([$colIndex, $rowNum], $cellData);
                }
            }
        }
    }

    $tempFile = tempnam(sys_get_temp_dir(), 'test_excel_').'.xlsx';
    $writer = new Xlsx($spreadsheet);
    $writer->save($tempFile);

    return new UploadedFile($tempFile, 'test.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

it('returns sheet names from an uploaded Excel file', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        ['name' => 'Bugs', 'headers' => ['Task', 'Status', 'Priority'], 'rows' => [['Fix login', 'Open', 'High']]],
        ['name' => 'Features', 'headers' => ['Title', 'Status'], 'rows' => [['Add search', 'To Do']]],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/excel-sheets",
        ['file' => $file]
    );

    $response->assertSuccessful();
    $data = $response->json();
    expect($data['sheets'])->toBe(['Bugs', 'Features']);
    expect($data['headers'])->toBe(['Task', 'Status', 'Priority']);
});

it('returns headers for a specific sheet', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        ['name' => 'Sheet1', 'headers' => ['Task', 'Status'], 'rows' => []],
        ['name' => 'Sheet2', 'headers' => ['Title', 'Notes', 'Priority'], 'rows' => []],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/excel-sheet-headers",
        ['file' => $file, 'sheet_name' => 'Sheet2']
    );

    $response->assertSuccessful();
    expect($response->json('headers'))->toBe(['Title', 'Notes', 'Priority']);
});

it('imports tasks from an Excel file with column mapping', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        [
            'name' => 'Tasks',
            'headers' => ['Task Name', 'Status', 'Priority', 'Description'],
            'rows' => [
                ['Fix login bug', 'In Progress', 'High', 'Login page throws error'],
                ['Add search', 'To Do', 'Medium', 'Full text search'],
                ['Update docs', 'Done', 'Low', 'API documentation'],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Task Name',
            'status_column' => 'Status',
            'priority_column' => 'Priority',
            'description_column' => 'Description',
        ]
    );

    $response->assertSuccessful();
    $tasks = $response->json('tasks');
    expect($tasks)->toHaveCount(3);
    expect($tasks[0]['title'])->toBe('Fix login bug');
    expect($tasks[0]['status'])->toBe('in_progress');
    expect($tasks[0]['priority'])->toBe('high');
    expect($tasks[1]['status'])->toBe('pending');
    expect($tasks[2]['status'])->toBe('completed');
});

it('extracts hyperlinks from Excel cells into task descriptions', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        [
            'name' => 'Tasks',
            'headers' => ['Task Name', 'Website', 'ClickUp'],
            'rows' => [
                [
                    'Fix broken page',
                    ['value' => 'example-client.com/page', 'url' => 'https://www.example-client.com/page'],
                    ['value' => 'CU-123', 'url' => 'https://app.clickup.com/t/123'],
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Task Name',
        ]
    );

    $response->assertSuccessful();
    $task = $response->json('tasks.0');
    expect($task['description'])->toContain('example-client.com/page');
    expect($task['description'])->toContain('clickup.com/t/123');
    expect($task['metadata']['website_url'])->toBe('https://www.example-client.com/page');
    expect($task['metadata']['clickup_url'])->toBe('https://app.clickup.com/t/123');
});

it('skips empty rows and rows starting with http', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        [
            'name' => 'Tasks',
            'headers' => ['Task Name', 'Status'],
            'rows' => [
                ['Fix login', 'Open'],
                ['', 'Open'],
                ['https://example.com', 'Open'],
                ['Valid task', 'Open'],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Task Name',
        ]
    );

    $response->assertSuccessful();
    expect($response->json('tasks'))->toHaveCount(2);
    expect($response->json('skipped'))->toBe(2);
});

it('rejects non-Excel files', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = UploadedFile::fake()->create('data.csv', 100, 'text/csv');

    $response = $this->actingAs($user)->postJson(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Task',
        ]
    );

    $response->assertUnprocessable();
});

it('returns error when title column not found', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        [
            'name' => 'Tasks',
            'headers' => ['Name', 'Status'],
            'rows' => [['Task 1', 'Open']],
        ],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Nonexistent Column',
        ]
    );

    $response->assertStatus(422);
    expect($response->json('error'))->toContain('not found');
});

it('extracts hyperlink URL when notes column cell has no display text', function () {
    $user = User::factory()->create(['role' => 'owner']);
    $project = Project::factory()->create();

    $file = createTestExcelFile([
        [
            'name' => 'Tasks',
            'headers' => ['Task Name', 'Website Link'],
            'rows' => [
                [
                    'Build homepage',
                    ['value' => '', 'url' => 'https://example.com/homepage'],
                ],
                [
                    'Fix footer',
                    ['value' => 'https://example.com/footer', 'url' => 'https://example.com/footer'],
                ],
            ],
        ],
    ]);

    $response = $this->actingAs($user)->post(
        "/api/projects/{$project->id}/tasks/import-excel",
        [
            'file' => $file,
            'title_column' => 'Task Name',
            'notes_column' => 'Website Link',
        ]
    );

    $response->assertSuccessful();
    $tasks = $response->json('tasks');
    // First task: cell value was empty but hyperlink URL should be used
    expect($tasks[0]['description'])->toContain('https://example.com/homepage');
    // Second task: cell value had display text, should use that
    expect($tasks[1]['description'])->toContain('https://example.com/footer');
});
