<?php

namespace App\Services\Invoicing;

use App\Models\Invoice;
use App\Services\PayPal\PayPalService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;
use Spatie\Browsershot\Browsershot;

class PdfInvoiceGenerator
{
    protected string $tempDir;

    protected bool $useBrowsershot;

    protected ?string $chromePath = null;

    public function __construct(
        protected PayPalService $paypalService,
    ) {
        $this->tempDir = storage_path('app/temp/invoices');

        if (! is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0755, true);
        }

        // Check if Chrome/Chromium is available for Browsershot
        $this->chromePath = $this->findChromePath();
        $this->useBrowsershot = $this->chromePath !== null;
    }

    /**
     * Find the Chrome/Chromium executable path.
     */
    protected function findChromePath(): ?string
    {
        // Check custom install location first (Laravel Cloud deploy script)
        $homeDir = getenv('HOME') ?: '/home/clouduser';
        $customPaths = [
            "{$homeDir}/bin/chrome/chrome-headless-shell",
            "{$homeDir}/bin/chrome/chrome",
        ];

        foreach ($customPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                Log::debug('Found Chrome at custom path', ['path' => $path]);

                return $path;
            }
        }

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
            $result = trim(shell_exec("which {$cmd} 2>/dev/null") ?? '');
            if (! empty($result) && file_exists($result)) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Generate a PDF invoice and return the file path.
     */
    public function generate(Invoice $invoice): string
    {
        $html = $this->renderHtml($invoice);

        return $this->generatePdf($html, $invoice);
    }

    /**
     * Generate and store the PDF, returning the storage path.
     */
    public function generateAndStore(Invoice $invoice): string
    {
        $tempPath = $this->generate($invoice);
        $storagePath = "invoices/{$invoice->number}.pdf";

        Storage::put($storagePath, file_get_contents($tempPath));
        @unlink($tempPath);

        return $storagePath;
    }

    /**
     * Render the invoice HTML from Blade template.
     */
    public function renderHtml(Invoice $invoice): string
    {
        $invoice->load(['client', 'project', 'lines.project']);

        $client = $invoice->client;

        // Get dynamic PayPal payment URL (works for both sandbox and production)
        $paymentUrl = null;
        if ($invoice->amount_due > 0 && $this->paypalService->isConfigured()) {
            // Auto-create PayPal invoice if it doesn't exist (same as publicView)
            if (! $invoice->paypal_invoice_id) {
                try {
                    Log::info('Creating PayPal invoice for PDF generation', [
                        'invoice_id' => $invoice->id,
                        'invoice_number' => $invoice->number,
                    ]);
                    $this->paypalService->createInvoice($invoice);
                    $invoice->refresh();
                } catch (\Exception $e) {
                    Log::warning('Failed to auto-create PayPal invoice for PDF', [
                        'invoice_id' => $invoice->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            if ($invoice->paypal_invoice_id) {
                Log::info('Getting PayPal payment link for PDF', [
                    'invoice_id' => $invoice->id,
                    'paypal_invoice_id' => $invoice->paypal_invoice_id,
                ]);
                $paymentUrl = $this->paypalService->getPaymentLink($invoice);
                Log::info('PayPal payment link result', [
                    'invoice_id' => $invoice->id,
                    'payment_url' => $paymentUrl,
                ]);
            }
        } else {
            Log::debug('Skipping PayPal for PDF', [
                'invoice_id' => $invoice->id,
                'amount_due' => $invoice->amount_due,
                'paypal_configured' => $this->paypalService->isConfigured(),
            ]);
        }

        return View::make('invoices.show', [
            'invoice' => $invoice,
            'client' => $client,
            'lines' => $invoice->lines,
            'paymentUrl' => $paymentUrl,
            'isPdf' => true,
            'branding' => [
                'primary_color' => $client->brand_color ?? '#2563eb',
                'logo_url' => $client->logo_url,
                'footer' => $client->invoice_footer,
            ],
            'company' => [
                'name' => config('app.company_name', 'Zao'),
                'address' => config('app.company_address', ''),
                'email' => config('app.company_email', 'billing@example.com'),
                'phone' => config('app.company_phone', ''),
                'logo_url' => config('app.company_logo_url', ''),
            ],
        ])->render();
    }

    /**
     * Convert HTML to PDF.
     */
    protected function generatePdf(string $html, Invoice $invoice): string
    {
        $pdfPath = "{$this->tempDir}/{$invoice->number}.pdf";

        // Try Browsershot first if Chrome is available (higher quality)
        if ($this->useBrowsershot && $this->chromePath) {
            try {
                $browsershot = Browsershot::html($html)
                    ->setChromePath($this->chromePath)
                    ->format('Letter')
                    ->margins(0, 0, 0, 0) // Zero margins - let CSS handle it
                    ->showBackground()
                    ->scale(1.0)
                    ->deviceScaleFactor(2) // Higher resolution for crisp text
                    ->waitUntilNetworkIdle()
                    ->waitForFunction('document.fonts.ready'); // Wait for fonts to load

                // Set node_modules path explicitly so Browsershot can find puppeteer
                // Check multiple locations: app node_modules, then deploy script's puppeteer
                $possiblePaths = [
                    base_path('node_modules'),
                    getenv('HOME').'/bin/puppeteer/node_modules',
                    '/home/clouduser/bin/puppeteer/node_modules',
                    '/var/www/bin/puppeteer/node_modules',
                ];

                foreach ($possiblePaths as $path) {
                    if (is_dir($path) && is_dir($path.'/puppeteer')) {
                        $browsershot->setNodeModulePath($path);
                        Log::debug('Using node_modules path for Browsershot', ['path' => $path]);
                        break;
                    }
                }

                // Add no-sandbox for containerized environments
                if (getenv('LARAVEL_CLOUD') || file_exists('/.dockerenv')) {
                    $browsershot->noSandbox()
                        ->addChromiumArguments([
                            'disable-gpu',
                            'disable-dev-shm-usage',
                            'disable-setuid-sandbox',
                            'font-render-hinting=none', // Better font rendering
                        ]);
                }

                $browsershot->save($pdfPath);

                Log::info('PDF generated with Browsershot', [
                    'invoice' => $invoice->number,
                    'chrome' => $this->chromePath,
                ]);

                return $pdfPath;
            } catch (\Exception $e) {
                Log::warning('Browsershot PDF generation failed, falling back to dompdf', [
                    'invoice' => $invoice->number,
                    'error' => $e->getMessage(),
                    'chrome_path' => $this->chromePath,
                ]);
            }
        }

        // Use dompdf as fallback (or primary when Chrome isn't available)
        try {
            $pdf = Pdf::loadHTML($html)
                ->setPaper('letter')
                ->setOption('isRemoteEnabled', true)
                ->setOption('isHtml5ParserEnabled', true);

            $pdf->save($pdfPath);

            return $pdfPath;
        } catch (\Exception $e) {
            Log::error('PDF generation failed with both Browsershot and dompdf', [
                'invoice' => $invoice->number,
                'error' => $e->getMessage(),
            ]);

            // Last resort: save as HTML
            $htmlPath = "{$this->tempDir}/{$invoice->number}.html";
            file_put_contents($htmlPath, $html);

            return $htmlPath;
        }
    }

    /**
     * Get the expected PDF path for an invoice.
     */
    public function getPdfPath(Invoice $invoice): ?string
    {
        $storagePath = "invoices/{$invoice->number}.pdf";

        if (Storage::exists($storagePath)) {
            return Storage::path($storagePath);
        }

        return null;
    }

    /**
     * Delete the PDF for an invoice.
     */
    public function deletePdf(Invoice $invoice): bool
    {
        $storagePath = "invoices/{$invoice->number}.pdf";

        if (Storage::exists($storagePath)) {
            return Storage::delete($storagePath);
        }

        return false;
    }
}
