<?php

use App\Jobs\ProcessFinancialDocumentJob;
use App\Models\FinancialDocument;
use App\Models\User;
use App\Services\AI\ClaudeCliService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('preserves imported metadata when AI analysis completes', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('documents/financial/test-image.jpg', 'image-bytes');

    $document = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'file_path' => 'documents/financial/test-image.jpg',
        'file_name' => '2025-owner-w2.jpg',
        'mime_type' => 'image/jpeg',
        'document_type' => 'w2_packet',
        'extracted_data' => [
            'tax_year' => 2025,
            'import_source' => 'financial_archive_upload',
            'archive_relative_path' => 'Payroll/2025/2025-owner-w2.jpg',
        ],
    ]);

    $claude = \Mockery::mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn([
        'content' => json_encode([
            'document_type' => 'other',
            'confidence' => 0.91,
            'summary' => 'Owner payroll support.',
            'extracted_fields' => [
                'notice_type' => null,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    (new ProcessFinancialDocumentJob($document->id))->handle($claude, app(\App\Services\PersonalFinance\StatementLineItemParserService::class));

    $document->refresh();

    expect($document->document_type)->toBe('w2_packet')
        ->and(data_get($document->extracted_data, 'tax_year'))->toBe(2025)
        ->and(data_get($document->extracted_data, 'import_source'))->toBe('financial_archive_upload')
        ->and(data_get($document->extracted_data, 'archive_relative_path'))->toBe('Payroll/2025/2025-owner-w2.jpg')
        ->and(data_get($document->extracted_data, 'summary'))->toBe('Owner payroll support.')
        ->and($document->processing_status)->toBe('completed');
});

it('accepts tax-specific document classifications from AI analysis', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('documents/financial/2025-k1.jpg', 'image-bytes');

    $document = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'file_path' => 'documents/financial/2025-k1.jpg',
        'file_name' => '2025 Schedule K-1.jpg',
        'mime_type' => 'image/jpeg',
        'document_type' => 'other',
    ]);

    $claude = \Mockery::mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn([
        'content' => json_encode([
            'document_type' => 'k1_package',
            'confidence' => 0.88,
            'summary' => 'Shareholder Schedule K-1 support for 2025.',
            'extracted_fields' => [
                'tax_year' => 2025,
                'entity_name' => 'Zao Web Design LLC',
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    (new ProcessFinancialDocumentJob($document->id))->handle($claude, app(\App\Services\PersonalFinance\StatementLineItemParserService::class));

    $document->refresh();

    expect($document->document_type)->toBe('k1_package')
        ->and(data_get($document->extracted_data, 'tax_year'))->toBe(2025)
        ->and(data_get($document->extracted_data, 'extracted_fields.entity_name'))->toBe('Zao Web Design LLC')
        ->and($document->processing_status)->toBe('completed');
});

it('preserves rich prior-year tax return fields from AI extraction', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('documents/financial/2024-return.pdf', '%PDF-mock');

    $document = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'file_path' => 'documents/financial/2024-return.pdf',
        'file_name' => '2024 Owner User 1040 OR-40.pdf',
        'mime_type' => 'application/pdf',
        'document_type' => 'tax_return',
    ]);

    $job = new class($document->id) extends ProcessFinancialDocumentJob
    {
        protected function extractText(FinancialDocument $document): string
        {
            return 'mock return packet text';
        }
    };

    $claude = \Mockery::mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn([
        'content' => json_encode([
            'document_type' => 'tax_return',
            'confidence' => 0.95,
            'summary' => 'Personal federal and Oregon return packet.',
            'extracted_fields' => [
                'tax_year' => 2024,
                'form_types' => ['1040', 'OR-40'],
                'adjusted_gross_income' => 182500,
                'federal_income_tax' => 28200,
                'oregon_income_tax' => 11800,
                'federal_overpayment_applied' => 1800,
                'capital_loss_carryforward' => 3000,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $job->handle($claude, app(\App\Services\PersonalFinance\StatementLineItemParserService::class));

    $document->refresh();

    expect($document->document_type)->toBe('tax_return')
        ->and(data_get($document->extracted_data, 'tax_year'))->toBe(2024)
        ->and(data_get($document->extracted_data, 'extracted_fields.form_types'))->toBe(['1040', 'OR-40'])
        ->and(data_get($document->extracted_data, 'extracted_fields.adjusted_gross_income'))->toBe(182500)
        ->and(data_get($document->extracted_data, 'extracted_fields.federal_overpayment_applied'))->toBe(1800)
        ->and($document->processing_status)->toBe('completed');
});

it('extracts statement line items alongside statement metadata', function () {
    Storage::fake('local');

    $user = User::factory()->create();
    Storage::disk('local')->put('documents/financial/2025-statement.pdf', '%PDF-mock');

    $document = FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'file_path' => 'documents/financial/2025-statement.pdf',
        'file_name' => '2025-01 Business Checking 1234.pdf',
        'mime_type' => 'application/pdf',
        'document_type' => 'bank_statement',
    ]);

    $job = new class($document->id) extends ProcessFinancialDocumentJob
    {
        protected function extractText(FinancialDocument $document): string
        {
            return <<<'TEXT'
01/05 ACH CREDIT CLIENT PAYMENT 10000.00 12500.00
01/06 ONLINE PAYMENT TO FEATURE.COM SOFTWARE SUBSCRIPTION 172.51 12327.49
TEXT;
        }
    };

    $claude = \Mockery::mock(ClaudeCliService::class);
    $claude->shouldReceive('message')->once()->andReturn([
        'content' => json_encode([
            'document_type' => 'bank_statement',
            'confidence' => 0.94,
            'summary' => 'Business checking statement.',
            'extracted_fields' => [
                'tax_year' => 2025,
                'institution' => 'Chase',
                'account_last4' => '1234',
                'period_start' => '2025-01-01',
                'period_end' => '2025-01-31',
                'opening_balance' => 2500,
                'ending_balance' => 12327.49,
                'total_deposits' => 10000,
                'total_withdrawals' => 172.51,
            ],
        ], JSON_THROW_ON_ERROR),
    ]);

    $job->handle($claude, app(\App\Services\PersonalFinance\StatementLineItemParserService::class));

    $document->refresh();

    expect($document->document_type)->toBe('bank_statement')
        ->and(data_get($document->extracted_data, 'line_items'))->toHaveCount(2)
        ->and(data_get($document->extracted_data, 'line_item_summary.parsed_count'))->toBe(2)
        ->and(data_get($document->extracted_data, 'line_item_summary.parsed_deposits'))->toEqual(10000.0)
        ->and(data_get($document->extracted_data, 'line_item_summary.parsed_withdrawals'))->toBe(172.51)
        ->and(data_get($document->extracted_data, 'extracted_fields.period_start'))->toBe('2025-01-01')
        ->and(data_get($document->extracted_data, 'extracted_fields.period_end'))->toBe('2025-01-31')
        ->and((float) $document->extraction_confidence)->toBe(0.94)
        ->and($document->needs_review)->toBeFalse();
});
