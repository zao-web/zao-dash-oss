<?php

use App\Services\AI\ClaudeCliService;
use App\Services\Google\DriveService;
use App\Services\SowParsingService;

beforeEach(function () {
    $this->service = new class(\Mockery::mock(ClaudeCliService::class), \Mockery::mock(DriveService::class)) extends SowParsingService
    {
        public function testPrepareContentForAi(string $combinedContent): string
        {
            return $this->prepareContentForAi($combinedContent);
        }
    };
});

it('normalizes extracted text before sending to ai', function () {
    config(['services.anthropic.sow_import_max_chars' => 1000]);

    $input = "Line 1\r\n\r\n\r\nLine\t\t2\x00\x07\rLine   3";
    $prepared = $this->service->testPrepareContentForAi($input);

    expect($prepared)->toBe("Line 1\n\nLine 2\nLine 3");
});

it('truncates oversized extracted text to configured max chars', function () {
    config(['services.anthropic.sow_import_max_chars' => 120]);

    $input = str_repeat('A', 100)."\nBudget: \$50,000\n".str_repeat('Z', 100);
    $prepared = $this->service->testPrepareContentForAi($input);

    expect(strlen($prepared))->toBeLessThanOrEqual(120);
    expect($prepared)->toContain('[... document content truncated for AI input size ...]');
    expect($prepared)->toStartWith(str_repeat('A', 20));
    expect($prepared)->toMatch('/Z{10,}$/');
});
