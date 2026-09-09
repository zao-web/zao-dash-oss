#!/usr/bin/env php
<?php

declare(strict_types=1);

$options = getopt('', ['name:', 'dry-run', 'root:', 'help']);
$positional = array_values(array_filter(
    $argv,
    static fn (string $arg): bool => ! str_starts_with($arg, '-'),
));

if (isset($options['help'])) {
    fwrite(STDOUT, "Usage: php scripts/rebrand.php [name] [--name=NAME] [--dry-run] [--root=PATH]\n");
    fwrite(STDOUT, "Default name: Agency Dash\n");
    exit(0);
}

$name = $options['name'] ?? ($positional[1] ?? 'Agency Dash');
$name = trim((string) $name);
$dryRun = array_key_exists('dry-run', $options);
$root = $options['root'] ?? dirname(__DIR__);

if ($name === '' || str_contains($name, "\n") || str_contains($name, "\r")) {
    fwrite(STDERR, "Name must be a single non-empty line.\n");
    exit(1);
}

if (str_contains($name, 'Zao Dash') || str_contains($name, 'Zao Dashboard')) {
    fwrite(STDERR, "Name must not contain the source product string.\n");
    exit(1);
}

$replacements = displayStringReplacements($name);

$skipDirs = [
    '.git',
    'vendor',
    'node_modules',
    'storage',
    'bootstrap/cache',
    'public/build',
];

$extensions = [
    'php', 'vue', 'ts', 'tsx', 'js', 'mjs', 'cjs', 'json', 'md', 'css',
    'html', 'yml', 'yaml', 'xml', 'txt', 'stub', 'env', 'example', 'blade.php',
];

$self = realpath(__FILE__) ?: __FILE__;
$changedFiles = 0;
$changedStrings = 0;

$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
);

foreach ($iterator as $file) {
    if (! $file instanceof SplFileInfo || ! $file->isFile()) {
        continue;
    }

    $path = $file->getPathname();
    $relative = ltrim(str_replace('\\', '/', substr($path, strlen(rtrim($root, '/')))), '/');

    if ($path === $self || shouldSkip($relative, $skipDirs)) {
        continue;
    }

    if (! hasAllowedExtension($file->getFilename(), $extensions) && $file->getFilename() !== '.env.example') {
        continue;
    }

    $original = file_get_contents($path);
    if ($original === false || $original === '') {
        continue;
    }

    if (str_contains($original, "\0")) {
        continue;
    }

    $updated = applyReplacements($original, $replacements);
    if ($updated === $original) {
        continue;
    }

    $changedFiles++;
    $changedStrings += countChanges($original, $updated);

    if (! $dryRun) {
        file_put_contents($path, $updated);
    }

    fwrite(STDOUT, ($dryRun ? 'would update ' : 'updated ').$relative."\n");
}

$verb = $dryRun ? 'Would update' : 'Updated';
fwrite(STDOUT, "{$verb} {$changedFiles} file(s), {$changedStrings} replacement(s). Name: {$name}\n");
exit(0);

/**
 * @return list<array{0: string, 1: string}>
 */
function displayStringReplacements(string $name): array
{
    $upper = mb_strtoupper($name);

    return [
        ['Zao Dashboard', $name],
        ['Zao Dash', $name],
        ['ZAO DASH', $upper],
    ];
}

/**
 * @param  list<array{0: string, 1: string}>  $replacements
 */
function applyReplacements(string $contents, array $replacements): string
{
    $updated = $contents;

    foreach ($replacements as [$from, $to]) {
        $updated = str_replace($from, $to, $updated);
    }

    return $updated;
}

/**
 * @param  list<string>  $skipDirs
 */
function shouldSkip(string $relative, array $skipDirs): bool
{
    foreach ($skipDirs as $dir) {
        if ($relative === $dir || str_starts_with($relative, $dir.'/')) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<string>  $extensions
 */
function hasAllowedExtension(string $filename, array $extensions): bool
{
    foreach ($extensions as $extension) {
        if (str_ends_with($filename, '.'.$extension) || $filename === $extension) {
            return true;
        }
    }

    return false;
}

function countChanges(string $before, string $after): int
{
    if ($before === $after) {
        return 0;
    }

    return 1;
}
