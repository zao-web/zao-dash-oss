<?php

it('replaces product display strings and leaves code identifiers', function () {
    $root = appLookingRoot();
    mkdir($root.'/docs', 0777, true);
    mkdir($root.'/vendor/pkg', 0777, true);

    file_put_contents($root.'/docs/guide.md', "Welcome to Zao Dash.\nAlso Zao Dashboard.\nClass ZaoDashServer stays.\n");
    file_put_contents($root.'/README.md', "ZAO DASH\n");
    file_put_contents($root.'/vendor/pkg/readme.md', 'Zao Dash inside vendor');

    $result = runRebrand($root, 'Agency Dash', write: true);

    expect($result['code'])->toBe(0);

    $guide = file_get_contents($root.'/docs/guide.md');
    expect($guide)->toBe("Welcome to Agency Dash.\nAlso Agency Dash.\nClass ZaoDashServer stays.\n")
        ->and(file_get_contents($root.'/README.md'))->toBe("AGENCY DASH\n")
        ->and(file_get_contents($root.'/vendor/pkg/readme.md'))->toBe('Zao Dash inside vendor');
});

it('is a no-op when the tree is already rebranded', function () {
    $root = appLookingRoot();
    file_put_contents($root.'/README.md', "Agency Dash\n");

    $first = runRebrand($root, 'Agency Dash', write: true);
    $second = runRebrand($root, 'Agency Dash', write: true);

    expect($first['code'])->toBe(0)
        ->and($second['code'])->toBe(0)
        ->and($second['output'])->toContain('Updated 0 file(s)')
        ->and(file_get_contents($root.'/README.md'))->toBe("Agency Dash\n");
});

it('accepts a custom product name', function () {
    $root = appLookingRoot();
    file_put_contents($root.'/note.md', 'Open Zao Dash');

    $result = runRebrand($root, 'Northstar OS', write: true);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($root.'/note.md'))->toBe('Open Northstar OS');
});

it('refuses a name that still contains the source string', function () {
    $root = appLookingRoot();

    $result = runRebrand($root, 'Zao Dash Clone', write: true);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('must not contain');
});

it('refuses a name that would break quoted literals', function (string $name) {
    $root = appLookingRoot();
    file_put_contents($root.'/note.md', 'Zao Dash');

    $result = runRebrand($root, $name, write: true);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('quotes, backslashes, or backticks')
        ->and(file_get_contents($root.'/note.md'))->toBe('Zao Dash');
})->with([
    'apostrophe' => "O'Brien Dash",
    'double quote' => 'North "Star"',
    'backslash' => 'North\\Star',
    'backtick' => 'North`Star',
]);

it('does not write files unless --write is passed', function () {
    $root = appLookingRoot();
    file_put_contents($root.'/note.md', 'Zao Dash');

    $result = runRebrand($root, 'Agency Dash');

    expect($result['code'])->toBe(0)
        ->and($result['output'])->toContain('would update')
        ->and(file_get_contents($root.'/note.md'))->toBe('Zao Dash');
});

it('refuses a root that is not this application', function () {
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root, 0777, true);
    file_put_contents($root.'/note.md', 'Zao Dash');

    $result = runRebrand($root, 'Agency Dash', write: true);

    expect($result['code'])->toBe(1)
        ->and($result['output'])->toContain('composer.json and scripts/rebrand.php')
        ->and(file_get_contents($root.'/note.md'))->toBe('Zao Dash');
});

it('leaves the rebrand instructions file unchanged', function () {
    $root = appLookingRoot();
    mkdir($root.'/docs', 0777, true);
    $needles = "Replaces `Zao Dash` and `Zao Dashboard`.\n";
    file_put_contents($root.'/docs/rebrand.md', $needles);
    file_put_contents($root.'/note.md', 'Zao Dash and Zao Dash');

    $result = runRebrand($root, 'Agency Dash', write: true);

    expect($result['code'])->toBe(0)
        ->and(file_get_contents($root.'/docs/rebrand.md'))->toBe($needles)
        ->and(file_get_contents($root.'/note.md'))->toBe('Agency Dash and Agency Dash')
        ->and($result['output'])->toContain('2 replacement(s)');
});

function appLookingRoot(): string
{
    $root = sys_get_temp_dir().'/rebrand-'.uniqid();
    mkdir($root.'/scripts', 0777, true);
    file_put_contents($root.'/composer.json', '{"name":"example/app"}');
    file_put_contents($root.'/scripts/rebrand.php', "<?php\n");

    return $root;
}

/**
 * @return array{code: int, output: string}
 */
function runRebrand(string $root, string $name, bool $write = false): array
{
    $command = ['php', base_path('scripts/rebrand.php'), '--name='.$name, '--root='.$root];
    if ($write) {
        $command[] = '--write';
    }

    $process = proc_open(
        $command,
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
