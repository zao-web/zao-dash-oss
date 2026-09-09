<?php

use App\Agents\Tools\WordpressMediaUploadTool;
use App\Models\WordPressSite;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->site = WordPressSite::factory()->create([
        'name' => 'Zao.is',
        'url' => 'https://example.com',
        'rest_url' => 'https://example.com/wp-json',
        'username' => 'justin',
        'application_password' => 'dash-stored-password',
        'mcp_enabled' => true,
        'is_primary' => true,
    ]);

    $this->tool = new WordpressMediaUploadTool;
});

it('requires approval for uploads like wp-create-post', function () {
    expect($this->tool->requiresApproval())->toBeTrue();
});

it('rejects file_path outside the Dash media directories', function () {
    $secret = storage_path('app/exfil.env');
    file_put_contents($secret, 'APP_KEY=stolen-from-env');

    Http::fake();

    $result = $this->tool->execute([
        'file_path' => $secret,
        'filename' => 'stolen.env',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('file_path must stay under the Dash');

    Http::assertNothingSent();
});

it('rejects file_path that realpath escapes via parent segments', function () {
    $mediaRoot = storage_path('app/wordpress-media');
    if (! is_dir($mediaRoot)) {
        mkdir($mediaRoot, 0755, true);
    }

    $secret = storage_path('app/exfil.env');
    file_put_contents($secret, 'APP_KEY=stolen-from-env');

    Http::fake();

    $result = $this->tool->execute([
        'file_path' => $mediaRoot.'/../exfil.env',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('file_path must stay under the Dash');

    Http::assertNothingSent();
});

it('rejects a symlink whose realpath leaves the Dash media root', function () {
    $mediaRoot = storage_path('app/wordpress-media');
    if (! is_dir($mediaRoot)) {
        mkdir($mediaRoot, 0755, true);
    }

    $secret = storage_path('app/exfil.env');
    file_put_contents($secret, 'APP_KEY=stolen-from-env');
    $link = $mediaRoot.'/linked.env';
    if (is_link($link) || is_file($link)) {
        unlink($link);
    }
    symlink($secret, $link);

    Http::fake();

    $result = $this->tool->execute([
        'file_path' => $link,
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('file_path must stay under the Dash');

    Http::assertNothingSent();
});

it('uploads a file that stays under the Dash wordpress-media directory', function () {
    $mediaRoot = storage_path('app/wordpress-media');
    if (! is_dir($mediaRoot)) {
        mkdir($mediaRoot, 0755, true);
    }

    $path = $mediaRoot.'/hero.png';
    file_put_contents($path, 'fake-png-bytes');

    Http::fake([
        'example.com/wp-json/wp/v2/media*' => Http::response([
            'id' => 88,
            'source_url' => 'https://example.com/wp-content/uploads/hero.png',
        ], 201),
    ]);

    $result = $this->tool->execute([
        'file_path' => $path,
        'filename' => 'hero.png',
    ]);

    expect($result)
        ->toMatchArray([
            'success' => true,
            'media_id' => 88,
        ]);
});

it('rejects file_url targeting loopback, link-local metadata, and private IPs', function (string $url) {
    Http::fake();

    $result = $this->tool->execute([
        'file_url' => $url,
        'filename' => 'stolen.bin',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('private, link-local, or reserved');

    Http::assertNothingSent();
})->with([
    'loopback' => 'http://127.0.0.1/latest/meta-data/',
    'ipv6-loopback' => 'http://[::1]/secret',
    'link-local-metadata' => 'http://169.254.169.254/latest/meta-data/',
    'rfc1918' => 'http://10.0.0.8/keys',
    'mapped-loopback' => 'http://[::ffff:127.0.0.1]/secret',
]);

it('rejects ipv4-translated and nat64 embeddings of loopback and metadata', function (string $url) {
    Http::fake();

    $result = $this->tool->execute([
        'file_url' => $url,
        'filename' => 'stolen.bin',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('private, link-local, or reserved');

    Http::assertNothingSent();
})->with([
    'translated-loopback-hex' => 'http://[::ffff:0:7f00:1]/secret',
    'translated-loopback-dotted' => 'http://[::ffff:0:127.0.0.1]/secret',
    'translated-metadata-hex' => 'http://[::ffff:0:a9fe:a9fe]/latest/meta-data/',
    'translated-metadata-dotted' => 'http://[::ffff:0:169.254.169.254]/latest/meta-data/',
    'nat64-loopback' => 'http://[64:ff9b::7f00:1]/secret',
    'nat64-metadata' => 'http://[64:ff9b::a9fe:a9fe]/latest/meta-data/',
]);

it('rejects dual-stack public A plus translated or nat64 loopback AAAA', function (array $ips) {
    $tool = new WordpressMediaUploadTool(fn (string $host): array => $ips);

    Http::fake();

    $result = $tool->execute([
        'file_url' => 'http://rebinder.example/hero.png',
        'filename' => 'hero.png',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('private, link-local, or reserved');

    Http::assertNothingSent();
})->with([
    'translated-aaaa' => [['1.1.1.1', '::ffff:0:7f00:1']],
    'nat64-aaaa' => [['1.1.1.1', '64:ff9b::a9fe:a9fe']],
]);

it('does not follow redirects onto private addresses', function () {
    Http::fake([
        'http://1.1.1.1/hero.png' => Http::response('', 302, [
            'Location' => 'http://127.0.0.1/secret.png',
        ]),
        'http://127.0.0.1/*' => Http::response('stolen-bytes', 200),
    ]);

    $result = $this->tool->execute([
        'file_url' => 'http://1.1.1.1/hero.png',
        'filename' => 'hero.png',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('private, link-local, or reserved');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1'));
});

it('rejects oversized file_url downloads', function () {
    Http::fake([
        'http://1.1.1.1/huge.png' => Http::response('x', 200, [
            'Content-Length' => (string) (WordpressMediaUploadTool::MAX_DOWNLOAD_BYTES + 1),
        ]),
    ]);

    $result = $this->tool->execute([
        'file_url' => 'http://1.1.1.1/huge.png',
        'filename' => 'huge.png',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('10MB');

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/wp/v2/media'));
});

it('rejects non-http file_url schemes', function () {
    Http::fake();

    $result = $this->tool->execute([
        'file_url' => 'file:///etc/passwd',
        'filename' => 'passwd',
    ]);

    expect($result['success'])->toBeFalse()
        ->and($result['error'])->toContain('http(s)');

    Http::assertNothingSent();
});

it('handshakes on dry_run without reading a forbidden path', function () {
    $secret = storage_path('app/exfil.env');
    file_put_contents($secret, 'APP_KEY=stolen-from-env');

    Http::fake([
        'https://example.com/wp-json/wp/v2/users/me' => Http::response(['id' => 7, 'name' => 'justin'], 200),
        'https://example.com/wp-json*' => Http::response(['namespaces' => ['wp/v2']], 200),
    ]);

    $result = $this->tool->execute([
        'file_path' => $secret,
        'dry_run' => true,
    ]);

    expect($result)
        ->toMatchArray([
            'success' => true,
            'dry_run' => true,
            'published' => false,
        ]);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), '/wp-json/wp/v2/media'));
});

it('pins curl to the validated public ip so a hostname that rebinds to loopback or metadata is not reached', function (string $rebindIp) {
    $lookups = [];

    $resolver = function (string $host) use (&$lookups, $rebindIp): array {
        $lookups[] = $host;

        return count($lookups) === 1 ? ['1.1.1.1'] : [$rebindIp];
    };

    $tool = new class($resolver) extends WordpressMediaUploadTool
    {
        public array $pinnedEntries = [];

        public function __construct(\Closure $hostResolver)
        {
            parent::__construct($hostResolver);
        }

        protected function httpGetPinned(string $url, array $ips): \Illuminate\Http\Client\Response
        {
            $this->pinnedEntries = $this->curlResolveEntries($url, $ips);

            return parent::httpGetPinned($url, $ips);
        }
    };

    Http::fake([
        'http://rebinder.example/*' => Http::response('png-from-pinned-public-ip', 200),
        'http://127.0.0.1/*' => Http::response('STOLEN-LOOPBACK', 200),
        'http://169.254.169.254/*' => Http::response('STOLEN-METADATA', 200),
        'example.com/wp-json/wp/v2/media*' => Http::response([
            'id' => 91,
            'source_url' => 'https://example.com/wp-content/uploads/hero.png',
        ], 201),
    ]);

    $result = $tool->execute([
        'file_url' => 'http://rebinder.example/hero.png',
        'filename' => 'hero.png',
    ]);

    expect($result['success'])->toBeTrue()
        ->and($lookups)->toBe(['rebinder.example'])
        ->and($tool->pinnedEntries)->toBe(['rebinder.example:80:1.1.1.1']);

    Http::assertSent(fn ($request) => $request->url() === 'http://rebinder.example/hero.png');
    Http::assertNotSent(fn ($request) => str_contains($request->url(), '127.0.0.1')
        || str_contains($request->url(), '169.254.169.254'));
    Http::assertNotSent(fn ($request) => str_contains((string) $request->body(), 'STOLEN'));
})->with([
    'loopback' => '127.0.0.1',
    'metadata' => '169.254.169.254',
]);
