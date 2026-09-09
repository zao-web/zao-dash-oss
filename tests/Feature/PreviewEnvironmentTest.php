<?php

use App\Jobs\CreatePreviewEnvironment;
use App\Models\Task;
use App\Models\TaskActivity;
use App\Models\TaskComment;
use App\Services\LaravelCloudService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('creates preview environment and stores metadata on task', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(true);
    $cloud->shouldReceive('createPreviewEnvironment')
        ->with('feature/login', Mockery::type('string'))
        ->once()
        ->andReturn([
            'environment_id' => 'env-123',
            'vanity_domain' => 'preview-login.cloud.test',
            'status' => 'provisioning',
        ]);
    $cloud->shouldReceive('deploy')
        ->with('env-123')
        ->once()
        ->andReturn([
            'deployment_id' => 'deploy-456',
            'status' => 'pending',
        ]);

    $job = new CreatePreviewEnvironment(
        $task->id,
        'feature/login',
        'https://github.com/org/repo/pull/42',
        '42'
    );

    $job->handle($cloud);

    $task->refresh();

    expect($task->metadata['cloud_environment_id'])->toBe('env-123')
        ->and($task->metadata['preview_url'])->toBe('https://preview-login.cloud.test')
        ->and($task->metadata['preview_branch'])->toBe('feature/login')
        ->and($task->metadata['pr_url'])->toBe('https://github.com/org/repo/pull/42')
        ->and($task->metadata['deployment_id'])->toBe('deploy-456');
});

it('adds system comment with preview url and testing instructions', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(true);
    $cloud->shouldReceive('createPreviewEnvironment')->andReturn([
        'environment_id' => 'env-789',
        'vanity_domain' => 'preview-test.cloud.test',
        'status' => 'provisioning',
    ]);
    $cloud->shouldReceive('deploy')->andReturn([
        'deployment_id' => 'deploy-101',
        'status' => 'pending',
    ]);

    $job = new CreatePreviewEnvironment($task->id, 'feature/test', null, null);
    $job->handle($cloud);

    $comment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_SYSTEM)
        ->latest()
        ->first();

    expect($comment)->not->toBeNull()
        ->and($comment->content)->toContain('Preview environment created')
        ->and($comment->content)->toContain('https://preview-test.cloud.test')
        ->and($comment->content)->toContain('Testing Instructions')
        ->and($comment->metadata['cloud_environment_id'])->toBe('env-789');
});

it('creates task activity for preview environment', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(true);
    $cloud->shouldReceive('createPreviewEnvironment')->andReturn([
        'environment_id' => 'env-abc',
        'vanity_domain' => 'preview-abc.cloud.test',
        'status' => 'provisioning',
    ]);
    $cloud->shouldReceive('deploy')->andReturn([
        'deployment_id' => 'deploy-def',
        'status' => 'pending',
    ]);

    $job = new CreatePreviewEnvironment($task->id, 'feature/abc');
    $job->handle($cloud);

    $activity = TaskActivity::where('task_id', $task->id)
        ->where('type', TaskActivity::TYPE_PREVIEW_CREATED)
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->metadata['preview_url'])->toBe('https://preview-abc.cloud.test')
        ->and($activity->metadata['branch'])->toBe('feature/abc');
});

it('skips preview creation when cloud service is not configured', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(false);
    $cloud->shouldNotReceive('createPreviewEnvironment');

    $job = new CreatePreviewEnvironment($task->id, 'feature/test');
    $job->handle($cloud);

    $task->refresh();

    expect($task->metadata)->not->toHaveKey('cloud_environment_id');
});

it('adds error comment when preview creation fails', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(true);
    $cloud->shouldReceive('createPreviewEnvironment')
        ->andThrow(new RuntimeException('API rate limit exceeded'));

    $job = new CreatePreviewEnvironment($task->id, 'feature/broken');

    try {
        $job->handle($cloud);
    } catch (RuntimeException) {
        // expected
    }

    $errorComment = TaskComment::where('task_id', $task->id)
        ->where('type', TaskComment::TYPE_SYSTEM)
        ->latest()
        ->first();

    expect($errorComment)->not->toBeNull()
        ->and($errorComment->content)->toContain('Failed to create preview environment')
        ->and($errorComment->content)->toContain('API rate limit exceeded');
});

it('handles missing vanity domain gracefully', function () {
    $task = Task::factory()->inProgress()->create();

    $cloud = Mockery::mock(LaravelCloudService::class);
    $cloud->shouldReceive('isConfigured')->andReturn(true);
    $cloud->shouldReceive('createPreviewEnvironment')->andReturn([
        'environment_id' => 'env-no-domain',
        'vanity_domain' => null,
        'status' => 'provisioning',
    ]);
    $cloud->shouldReceive('deploy')->andReturn([
        'deployment_id' => 'deploy-xyz',
        'status' => 'pending',
    ]);

    $job = new CreatePreviewEnvironment($task->id, 'feature/no-domain');
    $job->handle($cloud);

    $task->refresh();

    expect($task->metadata['cloud_environment_id'])->toBe('env-no-domain')
        ->and($task->metadata['preview_url'])->toBeNull();
});

// LaravelCloudService unit tests
it('creates a preview environment via API', function () {
    Http::fake([
        'cloud.laravel.com/api/applications/app-123/environments' => Http::response([
            'data' => [
                'id' => 'env-001',
                'attributes' => [
                    'vanity_domain' => 'my-preview.cloud.laravel.com',
                    'status' => 'provisioning',
                ],
            ],
        ], 201),
    ]);

    $service = new LaravelCloudService('test-token', 'app-123');
    $result = $service->createPreviewEnvironment('feature/test', 'preview-test');

    expect($result['environment_id'])->toBe('env-001')
        ->and($result['vanity_domain'])->toBe('my-preview.cloud.laravel.com')
        ->and($result['status'])->toBe('provisioning');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://cloud.laravel.com/api/applications/app-123/environments'
            && $request->method() === 'POST'
            && $request['branch'] === 'feature/test';
    });
});

it('deploys an environment via API', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-001/deployments' => Http::response([
            'data' => [
                'id' => 'deploy-001',
                'attributes' => [
                    'status' => 'pending',
                ],
            ],
        ], 201),
    ]);

    $service = new LaravelCloudService('test-token', 'app-123');
    $result = $service->deploy('env-001');

    expect($result['deployment_id'])->toBe('deploy-001')
        ->and($result['status'])->toBe('pending');
});

it('deletes an environment via API', function () {
    Http::fake([
        'cloud.laravel.com/api/environments/env-001' => Http::response(null, 204),
    ]);

    $service = new LaravelCloudService('test-token', 'app-123');
    $result = $service->deleteEnvironment('env-001');

    expect($result)->toBeTrue();
});

it('throws exception when API call fails', function () {
    Http::fake([
        'cloud.laravel.com/api/applications/app-123/environments' => Http::response([
            'errors' => [['detail' => 'Invalid branch']],
        ], 422),
    ]);

    $service = new LaravelCloudService('test-token', 'app-123');
    $service->createPreviewEnvironment('nonexistent', 'preview');
})->throws(RuntimeException::class, 'Invalid branch');

it('reports configured status correctly', function () {
    $configured = new LaravelCloudService('token', 'app-id');
    $notConfigured = new LaravelCloudService(null, null);

    expect($configured->isConfigured())->toBeTrue()
        ->and($notConfigured->isConfigured())->toBeFalse();
});
