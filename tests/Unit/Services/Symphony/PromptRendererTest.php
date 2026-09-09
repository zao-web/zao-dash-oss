<?php

use App\Services\Symphony\PromptRenderer;
use App\Services\Symphony\WorkflowException;

it('renders known variables in strict mode', function () {
    $renderer = new PromptRenderer;

    $rendered = $renderer->render(
        'Issue {{ issue.identifier }} / Attempt {{ attempt }}',
        [
            'issue' => ['identifier' => 'TASK-12'],
            'attempt' => 2,
        ]
    );

    expect($rendered)->toBe('Issue TASK-12 / Attempt 2');
});

it('fails when a variable is missing', function () {
    $renderer = new PromptRenderer;

    try {
        $renderer->render('Unknown {{ issue.missing }}', ['issue' => ['identifier' => 'TASK-9']]);
        $this->fail('Expected template_render_error.');
    } catch (WorkflowException $exception) {
        expect($exception->reason)->toBe('template_render_error');
    }
});

it('fails on unknown filters', function () {
    $renderer = new PromptRenderer;

    expect(fn () => $renderer->render('Issue {{ issue.identifier | upcase }}', [
        'issue' => ['identifier' => 'TASK-1'],
    ]))->toThrow(WorkflowException::class, 'Unknown filter');
});
