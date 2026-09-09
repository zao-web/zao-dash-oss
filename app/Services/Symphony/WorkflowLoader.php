<?php

namespace App\Services\Symphony;

use Illuminate\Support\Facades\File;

class WorkflowLoader
{
    /**
     * @return array{config: array<string, mixed>, prompt_template: string, path: string}
     */
    public function load(?string $path = null): array
    {
        $workflowPath = $this->resolvePath($path);

        if (! File::exists($workflowPath)) {
            throw new WorkflowException(
                reason: 'missing_workflow_file',
                message: "Workflow file not found at {$workflowPath}"
            );
        }

        $contents = File::get($workflowPath);

        [$config, $promptTemplate] = $this->parse($contents);

        return [
            'config' => $config,
            'prompt_template' => trim($promptTemplate),
            'path' => $workflowPath,
        ];
    }

    public function resolvePath(?string $path = null): string
    {
        if ($path) {
            return $path;
        }

        return base_path('WORKFLOW.md');
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     */
    protected function parse(string $contents): array
    {
        if (! str_starts_with($contents, "---\n") && ! str_starts_with($contents, "---\r\n")) {
            return [[], trim($contents)];
        }

        if (! preg_match('/\A---\r?\n(.*?)\r?\n---\r?\n?(.*)\z/s', $contents, $matches)) {
            throw new WorkflowException(
                reason: 'workflow_parse_error',
                message: 'Invalid WORKFLOW.md front matter delimiters.'
            );
        }

        try {
            $parsed = $this->parseYaml($matches[1]);
        } catch (\Throwable $exception) {
            throw new WorkflowException(
                reason: 'workflow_parse_error',
                message: 'Invalid YAML in WORKFLOW.md front matter.',
                previous: $exception
            );
        }

        if ($parsed === null) {
            $parsed = [];
        }

        if (! is_array($parsed) || array_is_list($parsed)) {
            throw new WorkflowException(
                reason: 'workflow_front_matter_not_a_map',
                message: 'WORKFLOW.md front matter must decode to a map/object.'
            );
        }

        return [$parsed, trim($matches[2])];
    }

    /**
     * Parse YAML front matter with graceful fallback when symfony/yaml isn't installed.
     */
    protected function parseYaml(string $yaml): mixed
    {
        if (\class_exists(\Symfony\Component\Yaml\Yaml::class)) {
            return \Symfony\Component\Yaml\Yaml::parse($yaml);
        }

        if (\function_exists('yaml_parse')) {
            $result = @\yaml_parse($yaml);
            if ($result === false) {
                throw new \RuntimeException('Failed to parse YAML with ext-yaml parser.');
            }

            return $result;
        }

        return $this->parseYamlSubset($yaml);
    }

    /**
     * Minimal YAML parser for project workflow front matter.
     * Supports nested maps/lists, inline lists, booleans, nulls, numbers, and block scalars.
     */
    protected function parseYamlSubset(string $yaml): mixed
    {
        $lines = preg_split('/\R/', $yaml) ?: [];
        $index = 0;

        return $this->parseNode($lines, $index, 0);
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function parseNode(array $lines, int &$index, int $indent): mixed
    {
        while ($index < count($lines)) {
            $line = $lines[$index];
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                $index++;

                continue;
            }

            $lineIndent = $this->lineIndent($line);
            if ($lineIndent < $indent) {
                return [];
            }

            $trimmed = substr($line, $lineIndent);

            return str_starts_with($trimmed, '- ')
                ? $this->parseList($lines, $index, $lineIndent)
                : $this->parseMap($lines, $index, $lineIndent);
        }

        return [];
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<string, mixed>
     */
    protected function parseMap(array $lines, int &$index, int $indent): array
    {
        $map = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                $index++;

                continue;
            }

            $lineIndent = $this->lineIndent($line);
            if ($lineIndent < $indent) {
                break;
            }

            if ($lineIndent > $indent) {
                throw new \RuntimeException('Invalid YAML indentation in map.');
            }

            $trimmed = substr($line, $indent);
            if (str_starts_with($trimmed, '- ')) {
                break;
            }

            if (! preg_match('/^([A-Za-z0-9_.-]+):(.*)$/', $trimmed, $matches)) {
                throw new \RuntimeException("Invalid YAML map entry: {$trimmed}");
            }

            $key = $matches[1];
            $rest = ltrim($matches[2]);
            $index++;

            if ($rest === '') {
                $map[$key] = $this->parseNode($lines, $index, $indent + 2);

                continue;
            }

            if ($rest === '|' || $rest === '>') {
                $map[$key] = $this->parseBlockScalar($lines, $index, $indent + 2, $rest === '>');

                continue;
            }

            $map[$key] = $this->parseScalar($rest);
        }

        return $map;
    }

    /**
     * @param  array<int, string>  $lines
     * @return array<int, mixed>
     */
    protected function parseList(array $lines, int &$index, int $indent): array
    {
        $list = [];

        while ($index < count($lines)) {
            $line = $lines[$index];

            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                $index++;

                continue;
            }

            $lineIndent = $this->lineIndent($line);
            if ($lineIndent < $indent) {
                break;
            }

            if ($lineIndent > $indent) {
                throw new \RuntimeException('Invalid YAML indentation in list.');
            }

            $trimmed = substr($line, $indent);
            if (! str_starts_with($trimmed, '- ')) {
                break;
            }

            $item = substr($trimmed, 2);
            $index++;

            if (trim($item) === '') {
                $list[] = $this->parseNode($lines, $index, $indent + 2);

                continue;
            }

            $list[] = $this->parseScalar(trim($item));
        }

        return $list;
    }

    /**
     * @param  array<int, string>  $lines
     */
    protected function parseBlockScalar(array $lines, int &$index, int $indent, bool $folded): string
    {
        $buffer = [];

        while ($index < count($lines)) {
            $line = $lines[$index];
            $lineIndent = $this->lineIndent($line);

            if (trim($line) !== '' && $lineIndent < $indent) {
                break;
            }

            if (trim($line) === '') {
                $buffer[] = '';
                $index++;

                continue;
            }

            $buffer[] = substr($line, $indent);
            $index++;
        }

        $text = implode("\n", $buffer);

        if ($folded) {
            return preg_replace("/\n+/", ' ', trim($text)) ?? trim($text);
        }

        return rtrim($text, "\n");
    }

    protected function parseScalar(string $value): mixed
    {
        $trimmed = trim($value);

        if ($trimmed === '' || $trimmed === 'null' || $trimmed === '~') {
            return null;
        }

        if (in_array(strtolower($trimmed), ['true', 'false'], true)) {
            return strtolower($trimmed) === 'true';
        }

        if (preg_match('/^-?\d+$/', $trimmed)) {
            return (int) $trimmed;
        }

        if (preg_match('/^-?\d+\.\d+$/', $trimmed)) {
            return (float) $trimmed;
        }

        if (preg_match('/^\[(.*)\]$/', $trimmed, $matches)) {
            $inner = trim($matches[1]);
            if ($inner === '') {
                return [];
            }

            $parts = str_getcsv($inner, ',', '"', '\\');

            return array_map(fn (string $item) => $this->parseScalar(trim($item)), $parts);
        }

        if (
            (str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'"))
        ) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    protected function lineIndent(string $line): int
    {
        preg_match('/^\s*/', $line, $matches);

        return isset($matches[0]) ? strlen($matches[0]) : 0;
    }
}
