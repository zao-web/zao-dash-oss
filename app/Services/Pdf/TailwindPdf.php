<?php

namespace App\Services\Pdf;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use RuntimeException;
use Spatie\Browsershot\Browsershot;

/**
 * TailwindPdf - Beautiful PDF generation with full Tailwind CSS support.
 *
 * This service generates pixel-perfect PDFs from HTML templates using Tailwind CSS.
 * It uses Browsershot (Chrome/Puppeteer) for rendering with the Tailwind Play CDN
 * for automatic CSS compilation.
 *
 * Chrome is required and guaranteed to be available via the deploy/chrome.sh script
 * which installs Chrome headless shell on Laravel Cloud deployments.
 *
 * Usage:
 *   // From a Blade view
 *   TailwindPdf::view('pdf.invoice', ['invoice' => $invoice])->save('invoices/123.pdf');
 *
 *   // From raw HTML
 *   TailwindPdf::html($html)->save('reports/report.pdf');
 *
 *   // Get binary content
 *   $content = TailwindPdf::view('pdf.report', $data)->content();
 *
 *   // Stream to browser
 *   return TailwindPdf::view('pdf.invoice', $data)->stream('invoice.pdf');
 */
class TailwindPdf
{
    protected string $html;

    protected string $format = 'Letter';

    protected array $margins = [0, 0, 0, 0]; // top, right, bottom, left

    protected bool $showBackground = true;

    protected float $scale = 1.0;

    protected int $deviceScaleFactor = 2;

    protected ?string $chromePath = null;

    protected array $tailwindConfig = [];

    protected bool $landscape = false;

    protected ?string $headerHtml = null;

    protected ?string $footerHtml = null;

    /**
     * Create a new TailwindPdf instance from a Blade view.
     */
    public static function view(string $view, array $data = []): self
    {
        $html = View::make($view, $data)->render();

        return new self($html);
    }

    /**
     * Create a new TailwindPdf instance from raw HTML.
     */
    public static function html(string $html): self
    {
        return new self($html);
    }

    public function __construct(string $html)
    {
        $this->html = $this->wrapWithTailwind($html);
        $this->chromePath = $this->findChromePath();
    }

    /**
     * Wrap HTML with Tailwind CSS Play CDN for automatic CSS compilation.
     */
    protected function wrapWithTailwind(string $html): string
    {
        // If the HTML already has a full document structure, inject Tailwind into it
        if (str_contains($html, '<!DOCTYPE') || str_contains($html, '<html')) {
            // Check if Tailwind is already included
            if (str_contains($html, 'cdn.tailwindcss.com') || str_contains($html, 'tailwindcss')) {
                return $html;
            }

            // Inject Tailwind Play CDN into the head
            $tailwindScript = $this->getTailwindScript();

            // Insert before </head> if possible
            if (str_contains($html, '</head>')) {
                return str_replace('</head>', $tailwindScript.'</head>', $html);
            }

            // Otherwise insert after <head>
            if (preg_match('/<head[^>]*>/i', $html, $matches)) {
                return str_replace($matches[0], $matches[0].$tailwindScript, $html);
            }
        }

        // Wrap partial HTML in a full document
        return $this->createDocument($html);
    }

    /**
     * Get the Tailwind CDN script with optional configuration.
     */
    protected function getTailwindScript(): string
    {
        $script = '<script src="https://cdn.tailwindcss.com"></script>';

        if (! empty($this->tailwindConfig)) {
            $config = json_encode($this->tailwindConfig, JSON_UNESCAPED_SLASHES);
            $script .= "\n<script>tailwind.config = {$config}</script>";
        }

        // Add base PDF styles
        $script .= '
<style type="text/tailwindcss">
    @layer base {
        /* PDF-optimized base styles */
        @page {
            margin: 0;
        }
        html {
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }
        body {
            font-family: ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }
    }
    @layer utilities {
        /* Page break utilities */
        .page-break-before { page-break-before: always; }
        .page-break-after { page-break-after: always; }
        .page-break-inside-avoid { page-break-inside: avoid; }
        .break-before-page { break-before: page; }
        .break-after-page { break-after: page; }
        .break-inside-avoid { break-inside: avoid; }
    }
</style>';

        return $script;
    }

    /**
     * Create a full HTML document from partial content.
     */
    protected function createDocument(string $content): string
    {
        $tailwindScript = $this->getTailwindScript();

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {$tailwindScript}
</head>
<body class="bg-white">
    {$content}
</body>
</html>
HTML;
    }

    /**
     * Set paper format (Letter, A4, Legal, etc.).
     */
    public function format(string $format): self
    {
        $this->format = $format;

        return $this;
    }

    /**
     * Set page to A4 format.
     */
    public function a4(): self
    {
        return $this->format('A4');
    }

    /**
     * Set page to Letter format.
     */
    public function letter(): self
    {
        return $this->format('Letter');
    }

    /**
     * Set page to landscape orientation.
     */
    public function landscape(): self
    {
        $this->landscape = true;

        return $this;
    }

    /**
     * Set page to portrait orientation.
     */
    public function portrait(): self
    {
        $this->landscape = false;

        return $this;
    }

    /**
     * Set page margins in millimeters (top, right, bottom, left).
     */
    public function margins(int $top, int $right, int $bottom, int $left): self
    {
        $this->margins = [$top, $right, $bottom, $left];

        return $this;
    }

    /**
     * Set uniform margins on all sides.
     */
    public function margin(int $mm): self
    {
        return $this->margins($mm, $mm, $mm, $mm);
    }

    /**
     * Set no margins (let CSS handle it).
     */
    public function noMargins(): self
    {
        return $this->margins(0, 0, 0, 0);
    }

    /**
     * Set device scale factor (higher = crisper text, default 2).
     */
    public function deviceScale(int $factor): self
    {
        $this->deviceScaleFactor = $factor;

        return $this;
    }

    /**
     * Set render scale (0.1 to 2.0).
     */
    public function scale(float $scale): self
    {
        $this->scale = $scale;

        return $this;
    }

    /**
     * Configure Tailwind (colors, fonts, etc.).
     *
     * @param  array  $config  Tailwind config object
     */
    public function tailwindConfig(array $config): self
    {
        $this->tailwindConfig = $config;
        // Re-wrap the HTML with new config
        $this->html = $this->wrapWithTailwind(
            $this->extractBodyContent($this->html)
        );

        return $this;
    }

    /**
     * Add custom theme colors to Tailwind.
     */
    public function colors(array $colors): self
    {
        $this->tailwindConfig['theme'] = $this->tailwindConfig['theme'] ?? [];
        $this->tailwindConfig['theme']['extend'] = $this->tailwindConfig['theme']['extend'] ?? [];
        $this->tailwindConfig['theme']['extend']['colors'] = array_merge(
            $this->tailwindConfig['theme']['extend']['colors'] ?? [],
            $colors
        );

        return $this->tailwindConfig($this->tailwindConfig);
    }

    /**
     * Add a primary brand color.
     */
    public function primaryColor(string $color): self
    {
        return $this->colors(['primary' => $color]);
    }

    /**
     * Extract body content from a full HTML document.
     */
    protected function extractBodyContent(string $html): string
    {
        if (preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $matches)) {
            return trim($matches[1]);
        }

        return $html;
    }

    /**
     * Set header HTML (appears on every page).
     */
    public function header(string $html): self
    {
        $this->headerHtml = $html;

        return $this;
    }

    /**
     * Set footer HTML (appears on every page).
     */
    public function footer(string $html): self
    {
        $this->footerHtml = $html;

        return $this;
    }

    /**
     * Generate the PDF content as a string.
     *
     * @throws RuntimeException If Chrome is not available
     */
    public function content(): string
    {
        // Final verification - re-check the Chrome path works right before using it
        if ($this->chromePath && ! $this->verifyBinaryArchitecture($this->chromePath)) {
            logger()->error('Chrome path failed final verification, clearing', [
                'path' => $this->chromePath,
                'arch' => php_uname('m'),
            ]);
            $this->chromePath = null;
        }

        // Use local Chrome if available
        if ($this->chromePath) {
            return $this->generateWithBrowsershot();
        }

        // Fallback to Browserless.io API (remote Chrome)
        $browserlessToken = config('services.browserless.api_key');
        if ($browserlessToken) {
            return $this->generateWithBrowserless($browserlessToken);
        }

        // Fallback to Gotenberg if configured
        $gotenbergUrl = config('services.gotenberg.url');
        if ($gotenbergUrl) {
            return $this->generateWithGotenberg($gotenbergUrl);
        }

        throw new RuntimeException(
            'PDF generation requires either local Chrome, BROWSERLESS_API_KEY, or GOTENBERG_URL. '.
            'Set one of these in your environment. Browserless.io has a free tier at https://www.browserless.io/'
        );
    }

    /**
     * Generate PDF using Browserless.io remote Chrome API.
     */
    protected function generateWithBrowserless(string $apiKey): string
    {
        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("https://chrome.browserless.io/pdf?token={$apiKey}", [
                'html' => $this->html,
                'options' => [
                    'format' => $this->format,
                    'printBackground' => $this->showBackground,
                    'scale' => $this->scale,
                    'landscape' => $this->landscape,
                    'margin' => [
                        'top' => ($this->margins[0] ?? 0).'mm',
                        'right' => ($this->margins[1] ?? 0).'mm',
                        'bottom' => ($this->margins[2] ?? 0).'mm',
                        'left' => ($this->margins[3] ?? 0).'mm',
                    ],
                ],
                'gotoOptions' => [
                    'waitUntil' => 'networkidle0',
                    'timeout' => 30000,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Browserless PDF generation failed: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Generate PDF using Gotenberg (self-hosted or cloud).
     */
    protected function generateWithGotenberg(string $baseUrl): string
    {
        $response = \Illuminate\Support\Facades\Http::timeout(60)
            ->attach('files', $this->html, 'index.html')
            ->post("{$baseUrl}/forms/chromium/convert/html", [
                'paperWidth' => 8.5,
                'paperHeight' => 11,
                'marginTop' => ($this->margins[0] ?? 0) / 25.4,
                'marginBottom' => ($this->margins[2] ?? 0) / 25.4,
                'marginLeft' => ($this->margins[3] ?? 0) / 25.4,
                'marginRight' => ($this->margins[1] ?? 0) / 25.4,
                'printBackground' => true,
                'waitDelay' => '1s',
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('Gotenberg PDF generation failed: '.$response->body());
        }

        return $response->body();
    }

    /**
     * Save the PDF to a file path (local or storage).
     */
    public function save(string $path): string
    {
        $content = $this->content();

        // If path doesn't start with /, assume it's a storage path
        if (! str_starts_with($path, '/')) {
            Storage::put($path, $content);

            return $path;
        }

        // Ensure directory exists
        $dir = dirname($path);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, $content);

        return $path;
    }

    /**
     * Save to a storage disk.
     */
    public function saveTo(string $disk, string $path): string
    {
        Storage::disk($disk)->put($path, $this->content());

        return $path;
    }

    /**
     * Stream the PDF to the browser for download.
     */
    public function download(string $filename = 'document.pdf'): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->content(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Stream the PDF to the browser for inline viewing.
     */
    public function stream(string $filename = 'document.pdf'): \Symfony\Component\HttpFoundation\Response
    {
        return response($this->content(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }

    /**
     * Generate PDF using Browsershot (Chrome).
     */
    protected function generateWithBrowsershot(): string
    {
        $browsershot = Browsershot::html($this->html)
            ->format($this->format)
            ->margins(...$this->margins)
            ->showBackground($this->showBackground)
            ->scale($this->scale)
            ->deviceScaleFactor($this->deviceScaleFactor)
            ->landscape($this->landscape)
            ->waitUntilNetworkIdle()
            ->timeout(30)
            // Wait for Tailwind to compile (the CDN compiles on page load)
            ->setDelay(500);

        // Only set Chrome path if we have a verified working one
        if ($this->chromePath) {
            $browsershot->setChromePath($this->chromePath);
        }

        // Set node_modules path for puppeteer
        foreach ($this->getNodeModulesPaths() as $path) {
            if (is_dir($path) && is_dir($path.'/puppeteer')) {
                $browsershot->setNodeModulePath($path);
                break;
            }
        }

        // Add container-safe flags
        if ($this->isContainerized()) {
            $browsershot->noSandbox()
                ->addChromiumArguments([
                    'disable-gpu',
                    'disable-dev-shm-usage',
                    'disable-setuid-sandbox',
                    'font-render-hinting=none',
                ]);
        }

        // Add header/footer if set
        if ($this->headerHtml) {
            $browsershot->headerHtml($this->headerHtml);
        }
        if ($this->footerHtml) {
            $browsershot->footerHtml($this->footerHtml);
        }

        return $browsershot->pdf();
    }

    /**
     * Find Chrome executable path.
     */
    protected function findChromePath(): ?string
    {
        $debug = [];

        // Check custom install location first (Laravel Cloud deploy script)
        $homeDir = getenv('HOME') ?: '/home/clouduser';
        $customPaths = [
            "{$homeDir}/bin/chrome/chrome-headless-shell",
            "{$homeDir}/bin/chrome/chrome",
            '/var/www/bin/chrome/chrome-headless-shell',
            '/var/www/bin/chrome/chrome',
        ];

        foreach ($customPaths as $path) {
            $exists = file_exists($path);
            $executable = $exists && is_executable($path);
            $debug[$path] = ['exists' => $exists, 'executable' => $executable];

            if ($exists && $executable) {
                $verified = $this->verifyBinaryArchitecture($path);
                $debug[$path]['verified'] = $verified;
                if ($verified) {
                    logger()->info('Chrome found at custom path', ['path' => $path]);

                    return $path;
                }
            }
        }

        // Search Puppeteer and Playwright cache directories for Chrome binary
        $browserCaches = [
            "{$homeDir}/bin/.cache/puppeteer",
            '/var/www/bin/.cache/puppeteer',
            "{$homeDir}/.cache/puppeteer",
            "{$homeDir}/bin/.cache/playwright",
            '/var/www/bin/.cache/playwright',
            "{$homeDir}/.cache/ms-playwright",
        ];

        foreach ($browserCaches as $cacheDir) {
            $debug["cache:{$cacheDir}"] = ['is_dir' => is_dir($cacheDir)];
            if (! is_dir($cacheDir)) {
                continue;
            }

            // Find Chrome binary in cache (handles various directory structures)
            // Prefer headless-shell paths (fewer system dependencies)
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($cacheDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST
            );

            $candidates = [];
            foreach ($iterator as $file) {
                if (! $file->isFile() || ! $file->isExecutable()) {
                    continue;
                }

                $filename = $file->getFilename();
                if ($filename === 'chrome' || $filename === 'chromium' || $filename === 'chrome-headless-shell') {
                    $path = $file->getPathname();
                    // Prioritize headless paths
                    $priority = str_contains($path, 'headless') ? 0 : 1;
                    $candidates[] = ['path' => $path, 'priority' => $priority];
                }
            }

            // Sort by priority (headless first)
            usort($candidates, fn ($a, $b) => $a['priority'] <=> $b['priority']);

            foreach ($candidates as $candidate) {
                $path = $candidate['path'];
                $verified = $this->verifyBinaryArchitecture($path);
                $debug["found:{$path}"] = ['verified' => $verified];
                if ($verified) {
                    logger()->info('Chrome found in browser cache', ['path' => $path]);

                    return $path;
                }
            }
        }

        // Log debug info if Chrome not found
        logger()->warning('Chrome not found, search debug info', [
            'home' => $homeDir,
            'arch' => php_uname('m'),
            'paths' => $debug,
        ]);

        // Check standard system paths
        $systemPaths = [
            '/usr/bin/chromium-browser',
            '/usr/bin/chromium',
            '/usr/bin/google-chrome',
            '/usr/bin/google-chrome-stable',
            '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        ];

        foreach ($systemPaths as $path) {
            if (file_exists($path)) {
                return $path;
            }
        }

        // Check via which command
        $commands = ['chromium-browser', 'chromium', 'google-chrome', 'chrome'];
        foreach ($commands as $cmd) {
            $result = trim((string) shell_exec("which {$cmd} 2>/dev/null"));
            if (! empty($result) && file_exists($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Verify a binary is executable and works on this system.
     * Uses --version test instead of file command for reliability.
     */
    protected function verifyBinaryArchitecture(string $path): bool
    {
        if (! file_exists($path)) {
            return false;
        }

        // Resolve symlinks to get actual binary path
        $realPath = realpath($path);
        if (! $realPath || ! is_executable($realPath)) {
            return false;
        }

        // Try running the binary with --version to verify it actually works
        $escapedPath = escapeshellarg($realPath);

        // Use exec() instead of shell_exec() to properly capture exit code
        $output = [];
        $exitCode = 0;
        exec("{$escapedPath} --version 2>&1", $output, $exitCode);
        $outputStr = implode("\n", $output);

        // Check for architecture mismatch errors
        $hasArchError = str_contains($outputStr, 'ELF')
            || str_contains($outputStr, 'not found')
            || str_contains($outputStr, 'cannot execute')
            || str_contains($outputStr, 'Exec format error');

        // Success: exit code 0 and no architecture errors
        if ($exitCode === 0 && ! $hasArchError) {
            return true;
        }

        // Log for debugging
        if (function_exists('logger')) {
            logger()->warning('Chrome binary verification failed', [
                'path' => $path,
                'real_path' => $realPath,
                'exit_code' => $exitCode,
                'output' => $outputStr,
                'system_arch' => php_uname('m'),
            ]);
        }

        return false;
    }

    /**
     * Get possible node_modules paths.
     */
    protected function getNodeModulesPaths(): array
    {
        $homeDir = getenv('HOME') ?: '/home/clouduser';

        return [
            base_path('node_modules'),
            "{$homeDir}/bin/puppeteer/node_modules",
            '/home/clouduser/bin/puppeteer/node_modules',
            '/var/www/bin/puppeteer/node_modules',
        ];
    }

    /**
     * Check if running in a containerized environment.
     */
    protected function isContainerized(): bool
    {
        return getenv('LARAVEL_CLOUD') || file_exists('/.dockerenv');
    }

    /**
     * Get the raw HTML that will be rendered.
     */
    public function getHtml(): string
    {
        return $this->html;
    }

    /**
     * Get the detected Chrome path (for debugging).
     */
    public function getChromePath(): ?string
    {
        return $this->chromePath;
    }
}
