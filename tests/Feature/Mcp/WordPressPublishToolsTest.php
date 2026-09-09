<?php

use App\Mcp\Servers\ZaoDashServer;
use App\Mcp\Tools\WordpressMediaUploadMcpTool;
use App\Mcp\Tools\WpCreatePostMcpTool;
use App\Mcp\Tools\WpUpdatePostMcpTool;
use App\Models\User;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Mcp\Server\Methods\ListTools;
use Laravel\Mcp\Server\Transport\FakeTransporter;
use Laravel\Mcp\Server\Transport\JsonRpcRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create(['role' => 'admin']);
    $this->site = WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'rest_url' => null,
        'username' => 'justin',
        'application_password' => 'dash-stored-password',
        'mcp_enabled' => true,
        'is_primary' => false,
    ]);
});

test('zao dash server registers wordpress publish tools', function () {
    $tools = (new ReflectionClass(ZaoDashServer::class))
        ->getProperty('tools')
        ->getDefaultValue();

    expect($tools)
        ->toContain(WpCreatePostMcpTool::class)
        ->toContain(WpUpdatePostMcpTool::class)
        ->toContain(WordpressMediaUploadMcpTool::class);
});

test('zao dash tools/list includes wp-update-post with updatePost schema', function () {
    $server = new ZaoDashServer(new FakeTransporter);
    $response = app(ListTools::class)->handle(
        JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]),
        $server->createContext(),
    );

    $listed = collect($response->toArray()['result']['tools'] ?? []);
    $tool = $listed->firstWhere('name', 'wp-update-post');

    expect($listed->pluck('name')->all())->toContain('wp-update-post')
        ->and($tool['description'])->toContain('updatePost')
        ->and($tool['inputSchema']['required'] ?? [])->toContain('post_id');
});

test('wp-update-post schema requires post_id and names the tool', function () {
    $descriptor = app(WpUpdatePostMcpTool::class)->toArray();

    expect($descriptor['name'])->toBe('wp-update-post')
        ->and($descriptor['description'])->toContain('updatePost')
        ->and($descriptor['inputSchema']['required'] ?? [])->toContain('post_id');
});

test('wp-update-post requires a post_id', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(WpUpdatePostMcpTool::class, [
        'title' => 'No id',
    ]);

    $response->assertHasErrors();
});

test('wp-update-post puts through WordPressMcpService::updatePost', function () {
    Http::fake([
        'example.com/wp-json/wp/v2/posts/53642' => Http::response([
            'id' => 53642,
            'link' => 'https://example.com/?p=53642',
            'status' => 'draft',
            'title' => ['rendered' => 'Voice rewrite'],
        ], 200),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WpUpdatePostMcpTool::class, [
        'post_id' => 53642,
        'title' => 'Voice rewrite',
        'content' => '<p>Updated body.</p>',
        'status' => 'draft',
    ]);

    $response->assertOk();
    $response->assertSee('53642');
    $response->assertSee('draft');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->method() === 'PUT'
            && $request->url() === 'https://example.com/wp-json/wp/v2/posts/53642'
            && ($data['title'] ?? null) === 'Voice rewrite'
            && ($data['content'] ?? null) === '<p>Updated body.</p>'
            && ($data['status'] ?? null) === 'draft';
    });
});

test('wp-update-post fails clearly when no wordpress site is connected', function () {
    $this->site->delete();

    $response = ZaoDashServer::actingAs($this->user)->tool(WpUpdatePostMcpTool::class, [
        'post_id' => 53642,
        'title' => 'Voice rewrite',
    ]);

    $response->assertOk();
    $response->assertSee('No WordPress site configured');
});

test('wp-update-post requires at least one field to change', function () {
    Http::fake();

    $response = ZaoDashServer::actingAs($this->user)->tool(WpUpdatePostMcpTool::class, [
        'post_id' => 53642,
    ]);

    $response->assertOk();
    $response->assertSee('Provide at least one field to update');

    Http::assertNothingSent();
});

test('wp-create-post dry run handshakes rest auth and persists rest_url', function () {
    Http::fake([
        'https://example.com/wp-json/wp/v2/users/me' => Http::response([
            'id' => 7,
            'name' => 'justin',
            'slug' => 'justin',
        ], 200),
        'https://example.com/wp-json*' => Http::response([
            'namespaces' => ['wp/v2'],
        ], 200),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WpCreatePostMcpTool::class, [
        'dry_run' => true,
    ]);

    $response->assertOk();
    $response->assertSee('justin');

    expect($this->site->fresh()->rest_url)->toBe('https://example.com/wp-json');

    Http::assertNotSent(function ($request) {
        return $request->method() === 'POST'
            && str_contains($request->url(), '/wp-json/wp/v2/posts');
    });
});

test('wp-create-post schema does not require title or content', function () {
    $required = app(WpCreatePostMcpTool::class)->toArray()['inputSchema']['required'] ?? [];

    expect($required)
        ->not->toContain('title')
        ->not->toContain('content');
});

test('wp-create-post still requires title and content when not a dry run', function () {
    $response = ZaoDashServer::actingAs($this->user)->tool(WpCreatePostMcpTool::class, []);

    $response->assertHasErrors();
});

test('wp-create-post creates a draft through the existing wordpress service', function () {
    Http::fake([
        'example.com/wp-json/wp/v2/posts' => Http::response([
            'id' => 321,
            'link' => 'https://example.com/?p=321',
            'status' => 'draft',
        ], 201),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WpCreatePostMcpTool::class, [
        'title' => 'Draft handshake post',
        'content' => '<p>Draft only.</p>',
        'status' => 'draft',
    ]);

    $response->assertOk();
    $response->assertSee('321');
    $response->assertSee('draft');

    Http::assertSent(function ($request) {
        $data = $request->data();

        return $request->method() === 'POST'
            && str_contains($request->url(), '/wp-json/wp/v2/posts')
            && ($data['status'] ?? null) === 'draft'
            && ($data['title'] ?? null) === 'Draft handshake post';
    });
});

test('wordpress-media-upload dry run handshakes without uploading', function () {
    Http::fake([
        'https://example.com/wp-json/wp/v2/users/me' => Http::response([
            'id' => 7,
            'name' => 'justin',
        ], 200),
        'https://example.com/wp-json*' => Http::response(['namespaces' => ['wp/v2']], 200),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WordpressMediaUploadMcpTool::class, [
        'filename' => 'hero.png',
        'file_base64' => base64_encode('not-a-real-image'),
        'dry_run' => true,
    ]);

    $response->assertOk();
    $response->assertSee('justin');

    expect($this->site->fresh()->rest_url)->toBe('https://example.com/wp-json');

    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/wp-json/wp/v2/media');
    });
});

test('wordpress-media-upload rejects file_path outside dash media directories', function () {
    $secret = storage_path('app/exfil.env');
    file_put_contents($secret, 'APP_KEY=stolen-from-env');

    Http::fake();

    $response = ZaoDashServer::actingAs($this->user)->tool(WordpressMediaUploadMcpTool::class, [
        'file_path' => $secret,
        'filename' => 'stolen.env',
    ]);

    $response->assertOk();
    $response->assertSee('file_path must stay under the Dash');

    Http::assertNothingSent();
});

test('wordpress-media-upload rejects private and metadata file_url targets', function () {
    Http::fake();

    $response = ZaoDashServer::actingAs($this->user)->tool(WordpressMediaUploadMcpTool::class, [
        'file_url' => 'http://169.254.169.254/latest/meta-data/',
        'filename' => 'meta.bin',
    ]);

    $response->assertOk();
    $response->assertSee('private, link-local, or reserved');

    Http::assertNothingSent();
});

test('wordpress-media-upload does not follow redirects onto private addresses', function () {
    Http::fake([
        'http://1.1.1.1/hero.png' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/secret.png',
        ]),
        'http://127.0.0.1/*' => Http::response('stolen-bytes', 200),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WordpressMediaUploadMcpTool::class, [
        'file_url' => 'http://1.1.1.1/hero.png',
        'filename' => 'hero.png',
    ]);

    $response->assertOk();
    $response->assertSee('private, link-local, or reserved');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

test('wordpress-media-upload sends a file through the stored dash credentials', function () {
    Http::fake([
        'example.com/wp-json/wp/v2/media*' => Http::response([
            'id' => 88,
            'source_url' => 'https://example.com/wp-content/uploads/hero.png',
        ], 201),
    ]);

    $response = ZaoDashServer::actingAs($this->user)->tool(WordpressMediaUploadMcpTool::class, [
        'filename' => 'hero.png',
        'file_base64' => base64_encode('fake-png-bytes'),
        'alt_text' => 'Hero alt',
    ]);

    $response->assertOk();
    $response->assertSee('88');

    Http::assertSent(function ($request) {
        $contentType = $request->header('Content-Type')[0] ?? '';

        return str_contains($request->url(), '/wp-json/wp/v2/media')
            && $request->method() === 'POST'
            && ! str_contains($contentType, 'application/json');
    });
});

test('wp-create-post fails clearly when no wordpress site is connected', function () {
    $this->site->delete();

    $response = ZaoDashServer::actingAs($this->user)->tool(WpCreatePostMcpTool::class, [
        'dry_run' => true,
    ]);

    $response->assertOk();
    $response->assertSee('No WordPress site configured');
});
