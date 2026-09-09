<?php

use App\Services\Symphony\WorkflowException;
use App\Services\Symphony\WorkflowLoader;

it('loads workflow config and prompt template from front matter', function () {
    $path = sys_get_temp_dir().'/workflow-loader-test-'.uniqid().'.md';

    file_put_contents($path, <<<'MD'
---
tracker:
  kind: kanban_tasks
agent:
  max_concurrent_agents: 3
---
Issue {{ issue.identifier }}
MD);

    $loader = new WorkflowLoader;
    $workflow = $loader->load($path);

    expect($workflow['config']['tracker']['kind'])->toBe('kanban_tasks')
        ->and($workflow['config']['agent']['max_concurrent_agents'])->toBe(3)
        ->and($workflow['prompt_template'])->toContain('Issue {{ issue.identifier }}');

    @unlink($path);
});

it('throws a typed exception when workflow file is missing', function () {
    $loader = new WorkflowLoader;

    expect(fn () => $loader->load('/tmp/definitely-missing-workflow.md'))
        ->toThrow(WorkflowException::class, 'Workflow file not found');
});

it('throws a typed exception when front matter is not a map', function () {
    $path = sys_get_temp_dir().'/workflow-loader-test-'.uniqid().'.md';
    file_put_contents($path, <<<'MD'
---
- invalid
- yaml
---
Prompt body
MD);

    $loader = new WorkflowLoader;

    try {
        $loader->load($path);
        $this->fail('Expected workflow exception was not thrown.');
    } catch (WorkflowException $exception) {
        expect($exception->reason)->toBe('workflow_front_matter_not_a_map');
    } finally {
        @unlink($path);
    }
});
