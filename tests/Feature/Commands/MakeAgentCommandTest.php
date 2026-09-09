<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

use Illuminate\Support\Facades\File;

afterEach(function () {
    // Clean up any created files
    $testFiles = [
        app_path('Agents/Definitions/TestAgentAgent.php'),
        app_path('Agents/Definitions/SimpleAgent.php'),
        app_path('Agents/Definitions/ToolAgentAgent.php'),
        storage_path('app/skills/test-agent'),
        storage_path('app/skills/simple'),
        storage_path('app/skills/tool-agent'),
    ];

    foreach ($testFiles as $file) {
        if (is_dir($file)) {
            File::deleteDirectory($file);
        } elseif (file_exists($file)) {
            unlink($file);
        }
    }
});

test('command creates agent definition and skill file', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $skillPath = storage_path('app/skills/test-agent/SKILL.md');

    expect(file_exists($definitionPath))->toBeTrue();
    expect(file_exists($skillPath))->toBeTrue();
});

test('command shows next steps after creation', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->expectsOutput('Next steps:')
        ->expectsOutputToContain('Edit the SKILL.md prompt')
        ->expectsOutputToContain('Customize the agent definition')
        ->expectsOutputToContain('php artisan agents:sync')
        ->assertExitCode(0);
});

test('command creates agent with custom model', function () {
    $this->artisan('make:agent', [
        'name' => 'TestAgent',
        '--model' => 'opus',
    ])->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'model' => 'opus'");
});

test('command creates agent with tool', function () {
    $this->artisan('make:agent', [
        'name' => 'ToolAgent',
        '--tool' => 'search_database',
    ])
        ->expectsOutputToContain("uses the 'search_database' tool")
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/ToolAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("['search_database']");

    $skillPath = storage_path('app/skills/tool-agent/SKILL.md');
    $skillContent = file_get_contents($skillPath);

    expect($skillContent)->toContain('search_database');
});

test('command creates agent with approval required', function () {
    $this->artisan('make:agent', [
        'name' => 'TestAgent',
        '--approval' => true,
    ])->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'requires_approval' => true");
});

test('command creates agent with custom budget', function () {
    $this->artisan('make:agent', [
        'name' => 'TestAgent',
        '--budget' => '10.50',
    ])->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'max_budget_usd' => 10.50");
});

test('command creates agent with schedule', function () {
    $this->artisan('make:agent', [
        'name' => 'TestAgent',
        '--schedule' => '0 */6 * * *',
    ])->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'schedule' => '0 */6 * * *'");
});

test('command fails when agent already exists', function () {
    // Create agent first time
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    // Try to create again
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->expectsOutput('Agent definition already exists: '.app_path('Agents/Definitions/TestAgentAgent.php'))
        ->assertExitCode(1);
});

test('command fails with invalid agent name', function () {
    $this->artisan('make:agent', ['name' => '123Invalid'])
        ->expectsOutput('Agent name must start with a letter and contain only alphanumeric characters.')
        ->assertExitCode(1);

    expect(file_exists(app_path('Agents/Definitions/123InvalidAgent.php')))->toBeFalse();
});

test('command creates valid PHP class', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain('namespace App\Agents\Definitions;');
    expect($content)->toContain('class TestAgentAgent extends BaseAgentDefinition');
    expect($content)->toContain('public function metadata(): array');
    expect($content)->toContain('public function allowedTools(): array');
    expect($content)->toContain('public function systemPrompt(): string');
});

test('command creates valid SKILL.md file', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $skillPath = storage_path('app/skills/test-agent/SKILL.md');
    $content = file_get_contents($skillPath);

    expect($content)->toContain('# TestAgent Agent');
    expect($content)->toContain('## Responsibilities');
    expect($content)->toContain('## Output Format');
    expect($content)->toContain('## Guidelines');
});

test('command creates directories if they do not exist', function () {
    // Ensure directories don't exist
    if (is_dir(app_path('Agents/Definitions'))) {
        File::deleteDirectory(app_path('Agents/Definitions'));
    }

    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    expect(is_dir(app_path('Agents/Definitions')))->toBeTrue();
    expect(file_exists(app_path('Agents/Definitions/TestAgentAgent.php')))->toBeTrue();
});

test('command uses correct slug format', function () {
    $this->artisan('make:agent', ['name' => 'MyComplexAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/MyComplexAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'id' => 'my-complex-agent'");
    expect(is_dir(storage_path('app/skills/my-complex-agent')))->toBeTrue();
});

test('command sets default model to sonnet', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'model' => 'sonnet'");
});

test('command sets default approval to false', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'requires_approval' => false");
});

test('command sets default budget', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'max_budget_usd' => 5.00");
});

test('command creates agent without schedule by default', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("'schedule' => null");
});

test('command creates agent with empty tools array by default', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain('return [];');
});

test('command includes loadSkillPrompt call with correct slug', function () {
    $this->artisan('make:agent', ['name' => 'MyAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/MyAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain("\$this->loadSkillPrompt('my-agent')");
});

test('command creates skill file with tool section when tool provided', function () {
    $this->artisan('make:agent', [
        'name' => 'TestAgent',
        '--tool' => 'my_tool',
    ])->assertExitCode(0);

    $skillPath = storage_path('app/skills/test-agent/SKILL.md');
    $content = file_get_contents($skillPath);

    expect($content)->toContain('## Available Tools');
    expect($content)->toContain('my_tool');
});

test('command creates skill file without tool section when no tool', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $skillPath = storage_path('app/skills/test-agent/SKILL.md');
    $content = file_get_contents($skillPath);

    expect($content)->not->toContain('## Available Tools');
});

test('command includes best practice comments', function () {
    $this->artisan('make:agent', ['name' => 'TestAgent'])
        ->assertExitCode(0);

    $definitionPath = app_path('Agents/Definitions/TestAgentAgent.php');
    $content = file_get_contents($definitionPath);

    expect($content)->toContain('Following agentic workflow principles');
    expect($content)->toContain('Single responsibility');
    expect($content)->toContain('External prompts');
});
