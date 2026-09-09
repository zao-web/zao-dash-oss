<?php

namespace App\Services\Symphony;

use Illuminate\Support\Arr;

class PromptRenderer
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function render(string $template, array $context): string
    {
        if (substr_count($template, '{{') !== substr_count($template, '}}')) {
            throw new WorkflowException(
                reason: 'template_parse_error',
                message: 'Template has unbalanced interpolation delimiters.'
            );
        }

        return preg_replace_callback('/\{\{\s*(.*?)\s*\}\}/', function (array $matches) use ($context): string {
            $expression = trim($matches[1]);

            if ($expression === '') {
                throw new WorkflowException(
                    reason: 'template_parse_error',
                    message: 'Template interpolation cannot be empty.'
                );
            }

            if (str_contains($expression, '|')) {
                [$variable, $filter] = array_map('trim', explode('|', $expression, 2));

                throw new WorkflowException(
                    reason: 'template_render_error',
                    message: "Unknown filter '{$filter}' for variable '{$variable}'."
                );
            }

            if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(\.[a-zA-Z0-9_]+)*$/', $expression)) {
                throw new WorkflowException(
                    reason: 'template_parse_error',
                    message: "Unsupported template expression '{$expression}'."
                );
            }

            if (! Arr::has($context, $expression)) {
                throw new WorkflowException(
                    reason: 'template_render_error',
                    message: "Unknown template variable '{$expression}'."
                );
            }

            $value = Arr::get($context, $expression);

            if ($value === null) {
                return '';
            }

            if (is_scalar($value)) {
                return (string) $value;
            }

            return json_encode($value, JSON_PRETTY_PRINT) ?: '';
        }, $template) ?? $template;
    }
}
