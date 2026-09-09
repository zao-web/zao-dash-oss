<?php

use App\Agents\Tools\WpUpdatePostTool;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('requires post_id in the agent input schema', function () {
    expect((new WpUpdatePostTool)->inputSchema()['required'] ?? [])
        ->toContain('post_id');
});

it('calls WordPressMcpService::updatePost', function () {
    $site = WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'username' => 'justin',
        'application_password' => 'dash-stored-password',
        'is_primary' => true,
    ]);

    $this->mock(WordPressMcpService::class, function ($mock) use ($site) {
        $mock->shouldReceive('defaultSite')->once()->andReturn($site);
        $mock->shouldReceive('updatePost')
            ->once()
            ->withArgs(function ($passedSite, int $postId, array $data) use ($site): bool {
                return $passedSite->is($site)
                    && $postId === 53642
                    && $data['title'] === 'Voice rewrite'
                    && $data['content'] === '<p>Updated body.</p>';
            })
            ->andReturn([
                'id' => 53642,
                'link' => 'https://example.com/?p=53642',
                'status' => 'draft',
            ]);
    });

    $result = (new WpUpdatePostTool)->execute([
        'post_id' => 53642,
        'title' => 'Voice rewrite',
        'content' => '<p>Updated body.</p>',
    ]);

    expect($result)
        ->toMatchArray([
            'success' => true,
            'post_id' => 53642,
            'post_url' => 'https://example.com/?p=53642',
            'status' => 'draft',
        ])
        ->and($result['message'])->toContain('PUT /wp/v2/posts/53642');
});

it('puts the wordpress rest post endpoint', function () {
    WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'username' => 'justin',
        'application_password' => 'dash-stored-password',
        'is_primary' => true,
    ]);

    Http::fake([
        'example.com/wp-json/wp/v2/posts/53642' => Http::response([
            'id' => 53642,
            'link' => 'https://example.com/?p=53642',
            'status' => 'draft',
        ], 200),
    ]);

    $result = (new WpUpdatePostTool)->execute([
        'post_id' => 53642,
        'content' => '<p>Updated body.</p>',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($result['post_id'])->toBe(53642);

    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://example.com/wp-json/wp/v2/posts/53642'
        && ($request->data()['content'] ?? null) === '<p>Updated body.</p>');
});

it('returns an error when no wordpress site is configured', function () {
    Http::fake();

    $result = (new WpUpdatePostTool)->execute([
        'post_id' => 53642,
        'title' => 'Voice rewrite',
    ]);

    expect($result)
        ->toMatchArray([
            'success' => false,
            'error' => 'No WordPress site configured. Go to Settings → Integrations to connect your WordPress site.',
        ]);

    Http::assertNothingSent();
});

it('returns an error when no fields are provided to update', function () {
    WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'is_primary' => true,
    ]);

    Http::fake();

    $result = (new WpUpdatePostTool)->execute([
        'post_id' => 53642,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('Provide at least one field to update');

    Http::assertNothingSent();
});
