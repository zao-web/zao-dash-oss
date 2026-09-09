<?php

use App\Services\Pdf\TailwindPdf;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
});

test('creates pdf from raw html', function () {
    $html = '<div class="p-4 bg-blue-500 text-white">Hello World</div>';

    $pdf = TailwindPdf::html($html);

    expect($pdf)->toBeInstanceOf(TailwindPdf::class);
    expect($pdf->getHtml())->toContain('cdn.tailwindcss.com');
    expect($pdf->getHtml())->toContain('Hello World');
    expect($pdf->getHtml())->toContain('bg-blue-500');
});

test('creates pdf from blade view', function () {
    // Create a test view
    $viewContent = '<div class="text-red-500">Test Content</div>';
    file_put_contents(resource_path('views/test-pdf.blade.php'), $viewContent);

    $pdf = TailwindPdf::view('test-pdf');

    expect($pdf)->toBeInstanceOf(TailwindPdf::class);
    expect($pdf->getHtml())->toContain('Test Content');
    expect($pdf->getHtml())->toContain('cdn.tailwindcss.com');

    // Cleanup
    @unlink(resource_path('views/test-pdf.blade.php'));
});

test('wraps partial html in full document', function () {
    $html = '<div class="p-4">Content</div>';

    $pdf = TailwindPdf::html($html);
    $output = $pdf->getHtml();

    expect($output)->toContain('<!DOCTYPE html>');
    expect($output)->toContain('<html');
    expect($output)->toContain('<head>');
    expect($output)->toContain('<body');
    expect($output)->toContain('cdn.tailwindcss.com');
});

test('injects tailwind into existing html document', function () {
    $html = '<!DOCTYPE html><html><head><title>Test</title></head><body><div>Content</div></body></html>';

    $pdf = TailwindPdf::html($html);
    $output = $pdf->getHtml();

    expect($output)->toContain('cdn.tailwindcss.com');
    expect($output)->toContain('<title>Test</title>');
    // Should only have one html tag
    expect(substr_count($output, '<html'))->toBe(1);
});

test('does not double-inject tailwind', function () {
    $html = '<!DOCTYPE html><html><head><script src="https://cdn.tailwindcss.com"></script></head><body>Content</body></html>';

    $pdf = TailwindPdf::html($html);
    $output = $pdf->getHtml();

    // Should only have one tailwindcss script
    expect(substr_count($output, 'cdn.tailwindcss.com'))->toBe(1);
});

test('includes pdf-optimized base styles', function () {
    $html = '<div>Test</div>';

    $pdf = TailwindPdf::html($html);
    $output = $pdf->getHtml();

    expect($output)->toContain('page-break-before');
    expect($output)->toContain('print-color-adjust');
});

test('can set paper format', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    expect($pdf->format('A4'))->toBe($pdf);
    expect($pdf->a4())->toBe($pdf);
    expect($pdf->letter())->toBe($pdf);
});

test('can set orientation', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    expect($pdf->landscape())->toBe($pdf);
    expect($pdf->portrait())->toBe($pdf);
});

test('can set margins', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    expect($pdf->margins(10, 10, 10, 10))->toBe($pdf);
    expect($pdf->margin(15))->toBe($pdf);
    expect($pdf->noMargins())->toBe($pdf);
});

test('can set device scale', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    expect($pdf->deviceScale(3))->toBe($pdf);
    expect($pdf->scale(1.5))->toBe($pdf);
});

test('can add custom colors', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    $pdf->colors(['brand' => '#ff0000']);
    $output = $pdf->getHtml();

    expect($output)->toContain('tailwind.config');
    expect($output)->toContain('brand');
});

test('can add primary color', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    $pdf->primaryColor('#2563eb');
    $output = $pdf->getHtml();

    expect($output)->toContain('primary');
    expect($output)->toContain('#2563eb');
});

test('can set header and footer', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    expect($pdf->header('<div>Header</div>'))->toBe($pdf);
    expect($pdf->footer('<div>Footer</div>'))->toBe($pdf);
});

test('throws exception when chrome is not available', function () {
    // Create a mock that returns null for chrome path
    $pdf = new class('<div>Test</div>') extends TailwindPdf {
        public function __construct(string $html)
        {
            // Call parent constructor then override chrome path to null
            parent::__construct($html);
            // Use reflection to set chromePath to null
            $reflection = new ReflectionClass(TailwindPdf::class);
            $property = $reflection->getProperty('chromePath');
            $property->setAccessible(true);
            $property->setValue($this, null);
        }
    };

    expect(fn () => $pdf->content())->toThrow(RuntimeException::class, 'Chrome is required');
});

test('exposes chrome path for debugging', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    // getChromePath returns string or null
    $path = $pdf->getChromePath();
    expect($path)->toBeString()->or()->toBeNull();
});

test('fluent interface is chainable', function () {
    $pdf = TailwindPdf::html('<div>Test</div>')
        ->format('A4')
        ->landscape()
        ->margins(10, 10, 10, 10)
        ->deviceScale(2)
        ->primaryColor('#ff0000')
        ->header('<div>Header</div>')
        ->footer('<div>Footer</div>');

    expect($pdf)->toBeInstanceOf(TailwindPdf::class);
});

test('generates pdf when chrome is available', function () {
    $pdf = TailwindPdf::html('<div class="p-4 bg-blue-500 text-white">Test PDF</div>');

    // Skip test if Chrome is not available in this environment
    if ($pdf->getChromePath() === null) {
        $this->markTestSkipped('Chrome is not available in this test environment');
    }

    $content = $pdf->content();

    // PDF files start with %PDF
    expect($content)->toStartWith('%PDF');
});

test('saves pdf to storage when chrome is available', function () {
    $pdf = TailwindPdf::html('<div class="p-4">Test</div>');

    // Skip test if Chrome is not available in this environment
    if ($pdf->getChromePath() === null) {
        $this->markTestSkipped('Chrome is not available in this test environment');
    }

    $path = $pdf->save('test/document.pdf');

    expect($path)->toBe('test/document.pdf');
    Storage::assertExists('test/document.pdf');
});

test('download returns proper response when chrome is available', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    // Skip test if Chrome is not available in this environment
    if ($pdf->getChromePath() === null) {
        $this->markTestSkipped('Chrome is not available in this test environment');
    }

    $response = $pdf->download('test.pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('attachment');
    expect($response->headers->get('Content-Disposition'))->toContain('test.pdf');
});

test('stream returns proper response when chrome is available', function () {
    $pdf = TailwindPdf::html('<div>Test</div>');

    // Skip test if Chrome is not available in this environment
    if ($pdf->getChromePath() === null) {
        $this->markTestSkipped('Chrome is not available in this test environment');
    }

    $response = $pdf->stream('test.pdf');

    expect($response->headers->get('Content-Type'))->toBe('application/pdf');
    expect($response->headers->get('Content-Disposition'))->toContain('inline');
});
