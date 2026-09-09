<?php

use App\Models\FinancialDocument;
use App\Models\User;
use App\Services\Tax\EntityCloseoutPackageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('summarizes a generatable entity closeout package once closure support is on file', function () {
    $user = User::factory()->create();

    FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'document_type' => 'entity_closure_record',
        'file_name' => 'Beet dissolution certificate.pdf',
        'extracted_data' => [
            'tax_year' => 2025,
            'extracted_fields' => [
                'entity_name' => 'Beet',
            ],
        ],
    ]);

    $summary = app(EntityCloseoutPackageService::class)->summarize($user->id, 2025, [
        'final_return_entities' => ['Beet'],
        'dissolution_entities' => ['Beet'],
        'entity_lifecycle_document_requests' => [],
    ]);

    expect($summary['is_applicable'])->toBeTrue()
        ->and($summary['can_generate_package'])->toBeTrue()
        ->and($summary['has_current_package'])->toBeFalse()
        ->and($summary['closure_documents'])->toHaveCount(1)
        ->and($summary['package_status'])->toBe('not_generated')
        ->and($summary['next_action'])->toContain('Generate the final-return closeout package');
});

it('generates an entity closeout packet and records it as a financial document', function () {
    Storage::fake();

    $user = User::factory()->create();

    FinancialDocument::factory()->create([
        'user_id' => $user->id,
        'document_type' => 'entity_closure_record',
        'file_name' => '2025 Beet dissolution certificate.pdf',
        'extracted_data' => [
            'tax_year' => 2025,
            'extracted_fields' => [
                'entity_name' => 'Beet',
            ],
        ],
    ]);

    $path = app(EntityCloseoutPackageService::class)->generate($user->id, 2025, [
        'final_return_entities' => ['Beet'],
        'dissolution_entities' => ['Beet'],
        'entity_lifecycle_document_requests' => [],
    ], 'html');

    Storage::assertExists($path);
    expect($path)->toBe("tax-forms/{$user->id}/2025/entity-closeout-package.html")
        ->and(Storage::get($path))->toContain('Entity Closeout Package')
        ->and(Storage::get($path))->toContain('Beet');

    $document = FinancialDocument::query()
        ->where('user_id', $user->id)
        ->where('document_type', 'entity_closeout_package')
        ->first();

    expect($document)->not->toBeNull()
        ->and($document?->file_path)->toBe($path)
        ->and(data_get($document?->extracted_data, 'entity_names'))->toBe(['Beet']);
});
