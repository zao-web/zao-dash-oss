<?php

use App\Services\AI\ClaudeCliService;

beforeEach(function () {
    $this->service = new class extends ClaudeCliService
    {
        public function testBuildEnv(): array
        {
            return $this->buildEnv();
        }

        public function testExtractCliDiagnostics(string $output): array
        {
            return $this->extractCliDiagnostics($output);
        }
    };
});

it('unsets claudecode in the cli environment', function () {
    config([
        'services.anthropic.oauth_token' => 'oauth-token',
        'services.anthropic.api_key' => 'api-key',
    ]);

    $env = $this->service->testBuildEnv();

    expect($env['CLAUDECODE'])->toBeFalse();
    expect($env['CLAUDE_CODE_OAUTH_TOKEN'])->toBe('oauth-token');
    expect($env['ANTHROPIC_API_KEY'])->toBe('');
});

it('extracts cli diagnostics from json output', function () {
    $output = json_encode([
        'result' => '{"ok":true}',
        'num_turns' => 11,
        'duration_api_ms' => 12345,
        'model' => 'sonnet',
    ]);

    $diagnostics = $this->service->testExtractCliDiagnostics($output);

    expect($diagnostics['json_envelope_detected'])->toBeTrue();
    expect($diagnostics['num_turns'])->toBe(11);
    expect($diagnostics['duration_api_ms'])->toBe(12345);
    expect($diagnostics['model'])->toBe('sonnet');
    expect($diagnostics['has_result'])->toBeTrue();
    expect($diagnostics['result_type'])->toBe('string');
});

it('handles non json cli output when extracting diagnostics', function () {
    $diagnostics = $this->service->testExtractCliDiagnostics('plain text output');

    expect($diagnostics)->toBe([
        'json_envelope_detected' => false,
    ]);
});
