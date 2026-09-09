<?php

namespace App\Agents\Tools;

use App\Services\WordPress\WordPressMcpService;
use Illuminate\Support\Facades\Http;

/**
 * Upload a file to the connected WordPress media library using Dash-stored credentials.
 */
class WordpressMediaUploadTool extends BaseTool
{
    public const MAX_DOWNLOAD_BYTES = 10 * 1024 * 1024;

    private const MAX_REDIRECTS = 3;

    /**
     * @param  \Closure(string): list<string>|null  $hostResolver
     */
    public function __construct(protected ?\Closure $hostResolver = null) {}

    public function category(): string
    {
        return 'content';
    }

    public function id(): string
    {
        return 'wordpress-media-upload';
    }

    public function name(): string
    {
        return 'Upload WordPress Media';
    }

    public function description(): string
    {
        return 'Upload an image or file to the connected WordPress media library using the Dash WordPress integration. Accepts a Dash media-directory path, URL, or base64 payload. Use dry_run to handshake REST auth without uploading.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'file_path' => [
                    'type' => 'string',
                    'description' => 'Absolute path under the Dash wordpress-media, public uploads, or temp directory. Paths outside that root are rejected.',
                ],
                'file_url' => [
                    'type' => 'string',
                    'description' => 'Public http(s) URL to download and upload. Private, link-local, and metadata addresses are rejected.',
                ],
                'file_base64' => [
                    'type' => 'string',
                    'description' => 'Base64-encoded file contents when the caller cannot share a Dash path',
                ],
                'filename' => [
                    'type' => 'string',
                    'description' => 'Filename to store in WordPress (required for file_base64)',
                ],
                'alt_text' => [
                    'type' => 'string',
                    'description' => 'Accessibility alt text for images',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'Handshake REST auth and persist rest_url without uploading',
                    'default' => false,
                ],
            ],
        ];
    }

    public function requiresApproval(): bool
    {
        return true;
    }

    public function riskLevel(): string
    {
        return 'medium';
    }

    public function execute(array $params): array
    {
        $wpService = app(WordPressMcpService::class);
        $site = $wpService->defaultSite();

        if (! $site) {
            return [
                'success' => false,
                'error' => 'No WordPress site configured. Go to Settings → Integrations to connect your WordPress site.',
            ];
        }

        if (! empty($params['dry_run'])) {
            try {
                return $wpService->handshake($site);
            } catch (\Exception $e) {
                return [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $createdTemp = false;
        $filePath = null;

        try {
            [$filePath, $createdTemp, $filename] = $this->resolveUploadSource($params);
            $media = $wpService->uploadMedia($site, $filePath, $filename, $params['alt_text'] ?? null);

            return [
                'success' => true,
                'media_id' => $media['id'] ?? null,
                'media_url' => $media['source_url'] ?? null,
                'wordpress_site' => $site->name,
                'message' => 'Media uploaded to WordPress.',
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        } finally {
            if ($createdTemp && $filePath && is_file($filePath)) {
                unlink($filePath);
            }
        }
    }

    /**
     * @return array{0: string, 1: bool, 2: string}
     */
    protected function resolveUploadSource(array $params): array
    {
        if (! empty($params['file_path'])) {
            $path = $this->assertPathIsAllowed((string) $params['file_path']);

            return [$path, false, $params['filename'] ?? basename($path)];
        }

        if (! empty($params['file_url'])) {
            $body = $this->downloadPublicUrl((string) $params['file_url']);
            $path = $this->writeTempFile($body);

            return [$path, true, $params['filename'] ?? basename(parse_url((string) $params['file_url'], PHP_URL_PATH) ?: 'upload.bin')];
        }

        if (! empty($params['file_base64'])) {
            if (empty($params['filename'])) {
                throw new \InvalidArgumentException('filename is required when uploading file_base64.');
            }

            $bytes = base64_decode($params['file_base64'], true);

            if ($bytes === false) {
                throw new \InvalidArgumentException('file_base64 is not valid base64.');
            }

            if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
                throw new \InvalidArgumentException('file_base64 exceeds the 10MB size limit.');
            }

            $path = $this->writeTempFile($bytes);

            return [$path, true, $params['filename']];
        }

        throw new \InvalidArgumentException('Provide file_path, file_url, or file_base64.');
    }

    /**
     * @return list<string>
     */
    public function allowedMediaRoots(): array
    {
        $roots = [
            storage_path('app/wordpress-media'),
            storage_path('app/public'),
            storage_path('app/temp'),
        ];

        $resolved = [];

        foreach ($roots as $root) {
            if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
                continue;
            }

            $real = realpath($root);

            if ($real !== false) {
                $resolved[] = $real;
            }
        }

        return $resolved;
    }

    protected function assertPathIsAllowed(string $path): string
    {
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath) || ! is_readable($realPath)) {
            throw new \InvalidArgumentException('Media file is not readable inside the Dash media directory.');
        }

        $normalizedPath = $this->normalizePath($realPath);

        foreach ($this->allowedMediaRoots() as $root) {
            $normalizedRoot = $this->normalizePath($root);

            if (str_starts_with($normalizedPath, $normalizedRoot.'/')) {
                return $realPath;
            }
        }

        throw new \InvalidArgumentException('file_path must stay under the Dash wordpress-media, public uploads, or temp directory.');
    }

    protected function downloadPublicUrl(string $url): string
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $ips = $this->validatedPublicIps($current);
            $response = $this->httpGetPinned($current, $ips);

            if (in_array($response->status(), [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');

                if (! is_string($location) || $location === '') {
                    throw new \InvalidArgumentException('file_url redirect is missing a Location header.');
                }

                $current = $this->resolveRedirectUrl($current, $location);

                continue;
            }

            if (! $response->successful()) {
                throw new \RuntimeException('Failed to download media from URL: '.$response->status());
            }

            $lengthHeader = $response->header('Content-Length');

            if (is_numeric($lengthHeader) && (int) $lengthHeader > self::MAX_DOWNLOAD_BYTES) {
                throw new \InvalidArgumentException('file_url download exceeds the 10MB size limit.');
            }

            $body = $response->body();

            if (strlen($body) > self::MAX_DOWNLOAD_BYTES) {
                throw new \InvalidArgumentException('file_url download exceeds the 10MB size limit.');
            }

            return $body;
        }

        throw new \InvalidArgumentException('file_url exceeded the redirect limit.');
    }

    /**
     * @return list<string>
     */
    protected function validatedPublicIps(string $url): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('file_url must be an http(s) URL.');
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('file_url must be an http(s) URL.');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new \InvalidArgumentException('file_url must not include credentials.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $ips = $this->resolveHostIps($host);

        foreach ($ips as $ip) {
            $this->assertIpIsPublic($ip);
        }

        return $ips;
    }

    /**
     * Fetch $url while connecting to already-validated IPs (curl CURLOPT_RESOLVE).
     * Keeps the original host for Host / SNI so a later DNS rebind cannot retarget the TCP connection.
     *
     * @param  list<string>  $ips
     */
    protected function httpGetPinned(string $url, array $ips): \Illuminate\Http\Client\Response
    {
        return Http::timeout(30)
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [
                    CURLOPT_RESOLVE => $this->curlResolveEntries($url, $ips),
                ],
                'progress' => function ($downloadTotal, $downloadedBytes): void {
                    if ($downloadedBytes > self::MAX_DOWNLOAD_BYTES) {
                        throw new \RuntimeException('file_url download exceeds the 10MB size limit.');
                    }
                },
            ])
            ->get($url);
    }

    /**
     * @param  list<string>  $ips
     * @return list<string>
     */
    public function curlResolveEntries(string $url, array $ips): array
    {
        $parts = parse_url($url);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('file_url must be an http(s) URL.');
        }

        $host = strtolower(trim($parts['host'], '[]'));
        $port = $parts['port'] ?? (strtolower((string) $parts['scheme']) === 'https' ? 443 : 80);
        $addresses = [];

        foreach ($ips as $ip) {
            $ip = $this->canonicalIp($ip);
            $addresses[] = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '['.$ip.']' : $ip;
        }

        return [$host.':'.$port.':'.implode(',', $addresses)];
    }

    /**
     * @return list<string>
     */
    protected function resolveHostIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$this->canonicalIp($host)];
        }

        if (preg_match('/^\\d+\\.\\d+\\.\\d+\\.\\d+$/', $host) === 1) {
            throw new \InvalidArgumentException('file_url host is not a valid public IP address.');
        }

        if ($this->hostResolver !== null) {
            $resolved = ($this->hostResolver)($host);

            if (! is_array($resolved) || $resolved === []) {
                throw new \InvalidArgumentException('Could not resolve file_url host to a public address.');
            }

            $ips = array_values(array_unique(array_map(fn (string $ip): string => $this->canonicalIp($ip), $resolved)));

            if ($ips === []) {
                throw new \InvalidArgumentException('Could not resolve file_url host to a public address.');
            }

            return $ips;
        }

        $ips = [];
        $ipv4 = @gethostbynamel($host);

        if (is_array($ipv4)) {
            $ips = array_merge($ips, $ipv4);
        }

        $ipv6 = @dns_get_record($host, DNS_AAAA);

        if (is_array($ipv6)) {
            foreach ($ipv6 as $record) {
                if (! empty($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        $ips = array_values(array_unique(array_map(fn (string $ip): string => $this->canonicalIp($ip), $ips)));

        if ($ips === []) {
            throw new \InvalidArgumentException('Could not resolve file_url host to a public address.');
        }

        return $ips;
    }

    protected function assertIpIsPublic(string $ip): void
    {
        $ip = $this->canonicalIp($ip);

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new \InvalidArgumentException('file_url must not target a private, link-local, or reserved address.');
        }
    }

    protected function canonicalIp(string $ip): string
    {
        $ip = strtolower(trim($ip, '[]'));
        $embedded = $this->embeddedIpv4($ip);

        return $embedded ?? $ip;
    }

    /**
     * Unwrap IPv4-mapped (::ffff:0:0/96), IPv4-translated (::ffff:0:0:0/96),
     * and NAT64 well-known prefix (64:ff9b::/96) embeddings so private v4
     * (loopback, link-local, RFC1918) cannot pass as IPv6.
     */
    protected function embeddedIpv4(string $ip): ?string
    {
        $packed = inet_pton($ip);

        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        $ipv4Mapped = str_repeat("\x00", 10)."\xff\xff";
        $ipv4Translated = str_repeat("\x00", 8)."\xff\xff\x00\x00";
        $nat64WellKnown = "\x00\x64\xff\x9b".str_repeat("\x00", 8);

        if (str_starts_with($packed, $ipv4Mapped)
            || str_starts_with($packed, $ipv4Translated)
            || str_starts_with($packed, $nat64WellKnown)) {
            $v4 = inet_ntop(substr($packed, 12, 4));

            return $v4 !== false ? $v4 : null;
        }

        return null;
    }

    protected function resolveRedirectUrl(string $current, string $location): string
    {
        $location = trim($location);

        if (str_starts_with($location, '//')) {
            $scheme = parse_url($current, PHP_URL_SCHEME) ?: 'https';

            return $scheme.':'.$location;
        }

        if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
            return $location;
        }

        $parts = parse_url($current);

        if (! is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new \InvalidArgumentException('file_url redirect could not be resolved.');
        }

        $origin = $parts['scheme'].'://'.$parts['host'];

        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        throw new \InvalidArgumentException('file_url redirect must be an absolute http(s) URL or absolute path.');
    }

    protected function writeTempFile(string $bytes): string
    {
        $root = storage_path('app/wordpress-media');

        if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
            throw new \RuntimeException('WordPress media directory is not available.');
        }

        $path = tempnam($root, 'wpupload');

        if ($path === false) {
            throw new \RuntimeException('Could not create a temporary media file.');
        }

        file_put_contents($path, $bytes);

        return $path;
    }

    protected function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }
}
