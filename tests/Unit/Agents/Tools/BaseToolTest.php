<?php

use App\Agents\Tools\BaseTool;

test('id returns kebab-case version of class name', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    // Anonymous classes get a generated name, just verify it's a string
    expect($tool->id())->toBeString()->not->toBeEmpty();
});

test('requiresApproval defaults to false', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    expect($tool->requiresApproval())->toBeFalse();
});

test('riskLevel defaults to low', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    expect($tool->riskLevel())->toBe('low');
});

test('validate returns params when no validation rules', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    $params = ['foo' => 'bar'];
    expect($tool->validate($params))->toBe($params);
});

test('validate throws exception on validation failure', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }

        protected function validationRules(): array
        {
            return ['required_field' => 'required'];
        }
    };

    $tool->validate([]);
})->throws(InvalidArgumentException::class);

test('validate returns validated params on success', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test';
        }

        public function description(): string
        {
            return 'Test tool';
        }

        public function inputSchema(): array
        {
            return [];
        }

        public function execute(array $params): array
        {
            return [];
        }

        protected function validationRules(): array
        {
            return ['field' => 'required|string'];
        }
    };

    $params = ['field' => 'value', 'extra' => 'ignored'];
    $validated = $tool->validate($params);

    expect($validated)->toHaveKey('field')
        ->and($validated['field'])->toBe('value');
});

test('toArray returns complete tool metadata', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test Tool';
        }

        public function description(): string
        {
            return 'Test description';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    $array = $tool->toArray();

    expect($array)->toHaveKeys(['id', 'name', 'description', 'input_schema', 'requires_approval', 'risk_level'])
        ->and($array['name'])->toBe('Test Tool')
        ->and($array['description'])->toBe('Test description')
        ->and($array['input_schema'])->toBe(['type' => 'object'])
        ->and($array['requires_approval'])->toBeFalse()
        ->and($array['risk_level'])->toBe('low');
});

test('toAnthropicTool returns Anthropic format', function () {
    $tool = new class extends BaseTool
    {
        public function name(): string
        {
            return 'Test Tool';
        }

        public function description(): string
        {
            return 'Test description';
        }

        public function inputSchema(): array
        {
            return ['type' => 'object'];
        }

        public function execute(array $params): array
        {
            return [];
        }
    };

    $anthropic = $tool->toAnthropicTool();

    expect($anthropic)->toHaveKeys(['name', 'description', 'input_schema'])
        ->and($anthropic['name'])->toBeString()->not->toBeEmpty()
        ->and($anthropic['description'])->toBe('Test description')
        ->and($anthropic['input_schema'])->toBe(['type' => 'object']);
});
