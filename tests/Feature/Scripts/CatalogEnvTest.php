<?php

it('catalogs every example and config environment variable without values', function () {
    $process = proc_open(
        ['php', base_path('scripts/catalog-env.php'), '--stdout'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    expect($code)->toBe(0, $error)
        ->and($output)->toContain('`APP_NAME`')
        ->and($output)->toContain('`AGENT_INTERNAL_TOKEN`')
        ->and($output)->toContain('`ZAO_DASH_MCP_TOKEN`')
        ->and($output)->toContain('`PLAID_CLIENT_ID`')
        ->and($output)->toContain('stub')
        ->and($output)->not->toContain('sk-')
        ->and($output)->not->toContain('xoxb-')
        ->and($output)->not->toContain('amazonaws.com')
        ->and($output)->not->toContain('https://');

    expect($output)->toMatch('/\| `SQS_PREFIX` \| configure \| Read by `?config\/queue\.php/');
    expect($output)->toMatch('/\| `HOME` \| process \|/');

    $exampleKeys = exampleKeys();
    foreach ($exampleKeys as $key) {
        expect($output)->toContain('`'.$key.'`');
    }

    preg_match('/Catalog size: (\d+)\./', $output, $size);
    preg_match_all('/^\| `([A-Z][A-Z0-9_]*)` \|/m', $output, $rows);

    expect((int) $size[1])->toBe(count($rows[1]))
        ->and(count($rows[1]))->toBeGreaterThan(count($exampleKeys));
});

/**
 * @return list<string>
 */
function exampleKeys(): array
{
    $keys = [];

    foreach (file(base_path('.env.example'), FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if (preg_match('/^\s*#?\s*([A-Z][A-Z0-9_]*)=/', $line, $match) === 1) {
            $keys[] = $match[1];
        }
    }

    return array_values(array_unique($keys));
}
