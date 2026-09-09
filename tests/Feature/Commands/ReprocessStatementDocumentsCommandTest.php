<?php

use App\Jobs\ProcessFinancialDocumentJob;
use App\Models\FinancialDocument;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

test('statement reprocess command queues matching statement documents and preserves import metadata', function () {
    Queue::fake();

    $user = User::factory()->create();

    $statement = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'document_type' => 'bank_statement',
        'processing_status' => 'completed',
        'processing_notes' => 'Old extraction.',
        'extraction_confidence' => 0.41,
        'needs_review' => true,
        'reviewed_at' => now(),
        'extracted_data' => [
            'tax_year' => 2025,
            'import_source' => 'financial_archive_upload',
            'archive_relative_path' => '2025/Statements/013125 WellsFargo (8724).pdf',
            'import_batch_id' => 'batch-123',
            'requested_scope' => 'business',
            'summary' => 'Old summary',
            'line_items' => [['transaction_date' => '2025-01-05']],
        ],
    ]);

    $otherDocument = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'document_type' => 'tax_return',
        'processing_status' => 'completed',
        'extracted_data' => [
            'tax_year' => 2025,
            'import_source' => 'financial_archive_upload',
        ],
    ]);

    $this->artisan('documents:reprocess-statements', [
        '--year' => 2025,
        '--source' => 'financial_archive_upload',
    ])
        ->expectsOutputToContain('Queued 1 statement document(s) for reprocessing.')
        ->assertExitCode(0);

    $statement->refresh();
    $otherDocument->refresh();

    expect($statement->processing_status)->toBe('pending')
        ->and($statement->processing_notes)->toBe('Queued for statement reprocessing with the latest parser.')
        ->and($statement->extraction_confidence)->toBeNull()
        ->and($statement->needs_review)->toBeTrue()
        ->and($statement->reviewed_at)->toBeNull()
        ->and($statement->extracted_data)->toBe([
            'tax_year' => 2025,
            'import_source' => 'financial_archive_upload',
            'archive_relative_path' => '2025/Statements/013125 WellsFargo (8724).pdf',
            'import_batch_id' => 'batch-123',
            'requested_scope' => 'business',
        ])
        ->and($otherDocument->processing_status)->toBe('completed');

    Queue::assertPushed(ProcessFinancialDocumentJob::class, 1);
});
