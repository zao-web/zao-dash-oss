<?php

use App\Models\GitHubRepo;

uses()->group('models');

test('has guarded attributes empty', function () {
    expect((new GitHubRepo)->getGuarded())->toBe([]);
});

test('casts is_private to boolean', function () {
    $repo = GitHubRepo::factory()->create(['is_private' => true]);

    expect($repo->is_private)->toBeTrue();
});

test('casts monitoring_enabled to boolean', function () {
    $repo = GitHubRepo::factory()->create(['monitoring_enabled' => true]);

    expect($repo->monitoring_enabled)->toBeTrue();
});

test('casts deployment_config to array', function () {
    $repo = GitHubRepo::factory()->create([
        'deployment_config' => ['key' => 'value'],
    ]);

    expect($repo->deployment_config)->toBeArray()
        ->and($repo->deployment_config)->toBe(['key' => 'value']);
});

test('belongs to installation relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->installation())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to client relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->client())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('belongs to project relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->project())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class);
});

test('has many issues relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->issues())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has many pullRequests relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->pullRequests())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('has one deploymentConfig relationship', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->deploymentConfig())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasOne::class);
});

test('openIssues relationship filters by state', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->openIssues())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('openPullRequests relationship filters by state', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo->openPullRequests())->toBeInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class);
});

test('getUrlAttribute returns correct GitHub URL', function () {
    $repo = GitHubRepo::factory()->create(['full_name' => 'owner/repository']);

    expect($repo->url)->toBe('https://github.com/owner/repository');
});

test('can be created via factory', function () {
    $repo = GitHubRepo::factory()->create();

    expect($repo)->toBeInstanceOf(GitHubRepo::class)
        ->and($repo->exists)->toBeTrue();
});
