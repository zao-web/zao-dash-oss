<?php

it('replaces product display strings and leaves code identifiers', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root.'/docs', 0777, true);
    mkdir($root.'/vendor/pkg', 0777, true);

    file_put_contents($root.'/docs/guide.md', "Welcome to Zao Dash.\nAlso Zao Dashboard.\nClass ZaoDashServer stays.\n");
    file_put_contents($root.'/README.md', "ZAO DASH\n");
    file_put_contents($root.'/vendor/pkg/readme.md', 'Zao Dash inside vendor');

    $result = runRebrand($root, 'Agency Dash');

    expect($result['code'])->toBe(0);

    $guide = file_get_contents($root.'/docs/guide.md');
    expect($guide)->toBe("Welcome to Agency Dash.\nAlso Agency Dash.\nClass ZaoDashServer stays.\n")
        ->and(file_get_contents($root.'/README.md'))->toBe("AGENCY DASH\n")
        ->and(file_get_contents($root.'/vendor/pkg/readme.md'))->toBe('Zao Dash inside vendor');
});

it('is a no-op when the tree is already rebranded', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/README.md', "Agency Dash\n");

    $first = runRebrand($root, 'Agency Dash');
    $second = runRebrand($root, 'Agency Dash');

    expect($first['code'])->toBe(0)
        ->and($second['code'])->toBe(0)
        ->and($second['output'])->toContain('Updated 0 file(s)')
        ->and(file_get_contents($root.'/README.md'))->toBe("Agency Dash\n");
});

it('accepts a custom product name', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/note.md', 'Open Zao Dash');

    $result = runRebrand($root, 'Northstar OS');

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($root.'/note.md'))->toBe('Open Northstar OS');
});

it('refuses a name that still contains the source string', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root, 0777, true);

    $result = runRebrand($root, 'Zao Dash Clone');

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('must not contain');
});

it('does not write files on a dry run', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/note.md', 'Zao Dash');

    $process = proc_open(
        ['php', base_path('scripts/rebrand.php'), '--name=Agency Dash', '--root='.$root, '--dry-run'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($process);

    expect($code)->toBe(0)
        ->and($output)->toContain('would update')
        ->and(file_get_contents($root.'/note.md'))->toBe('Zao Dash');
});

/**
 * @return array{code: int, output: string}
 */
function runRebrand(string $root, string $name): array
{
    $process = proc_open(
        ['php', base_path('scripts/rebrand.php'), '--name='.$name, '--root='.$root],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );

    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'code' => proc_close($process),
        'output' => $output,
    ];
}
