# PDF Generation with Tailwind CSS

Beautiful, pixel-perfect PDF generation using Tailwind CSS utility classes.

## Overview

The `TailwindPdf` service provides a simple, fluent API for generating PDFs from HTML templates with full Tailwind CSS support. Unlike traditional PDF libraries that only support limited CSS, this service uses Chrome/Puppeteer for rendering, giving you access to the entire Tailwind utility library.

## Quick Start

```php
use App\Services\Pdf\TailwindPdf;

// From a Blade view
TailwindPdf::view('pdf.invoice', ['invoice' => $invoice])
    ->save('invoices/123.pdf');

// From raw HTML
TailwindPdf::html('<div class="p-4 bg-blue-500 text-white font-bold">Hello!</div>')
    ->download('hello.pdf');
```

## Usage Examples

### Basic PDF Generation

```php
// Generate and save to storage
$path = TailwindPdf::view('pdf.report', $data)->save('reports/monthly.pdf');

// Generate and download
return TailwindPdf::view('pdf.invoice', $data)->download('invoice.pdf');

// Generate and stream (inline viewing)
return TailwindPdf::view('pdf.document', $data)->stream('document.pdf');

// Get raw PDF content
$pdfContent = TailwindPdf::view('pdf.report', $data)->content();
```

### Customizing Paper Format

```php
// Letter size (default)
TailwindPdf::html($html)->letter()->save('doc.pdf');

// A4 size
TailwindPdf::html($html)->a4()->save('doc.pdf');

// Custom format
TailwindPdf::html($html)->format('Legal')->save('doc.pdf');

// Landscape orientation
TailwindPdf::html($html)->a4()->landscape()->save('doc.pdf');
```

### Margins

```php
// Custom margins (top, right, bottom, left) in mm
TailwindPdf::html($html)->margins(10, 15, 10, 15)->save('doc.pdf');

// Uniform margins
TailwindPdf::html($html)->margin(20)->save('doc.pdf');

// No margins (let CSS handle it)
TailwindPdf::html($html)->noMargins()->save('doc.pdf');
```

### Branding & Colors

```php
// Add custom brand colors to Tailwind
TailwindPdf::html($html)
    ->colors([
        'brand' => '#2563eb',
        'accent' => '#f59e0b',
    ])
    ->save('doc.pdf');

// Shorthand for primary color
TailwindPdf::html($html)
    ->primaryColor('#2563eb')
    ->save('doc.pdf');
```

Then use in your template:
```html
<div class="bg-brand text-white">Branded content</div>
<div class="border-accent">Accented border</div>
```

### Render Quality

```php
// Higher device scale = crisper text (default is 2)
TailwindPdf::html($html)->deviceScale(3)->save('doc.pdf');

// Adjust render scale
TailwindPdf::html($html)->scale(1.0)->save('doc.pdf');
```

### Headers & Footers

```php
TailwindPdf::html($html)
    ->header('<div class="text-center text-gray-500 text-sm">Company Name</div>')
    ->footer('<div class="text-center text-gray-400 text-xs">Page <span class="pageNumber"></span> of <span class="totalPages"></span></div>')
    ->save('doc.pdf');
```

### Storage Options

```php
// Save to default storage
TailwindPdf::html($html)->save('pdfs/document.pdf');

// Save to specific disk
TailwindPdf::html($html)->saveTo('s3', 'pdfs/document.pdf');

// Save to absolute path
TailwindPdf::html($html)->save('/tmp/document.pdf');
```

## Creating PDF Templates

### Template Structure

PDF templates should be placed in `resources/views/pdf/`. Here's a basic structure:

```blade
{{-- resources/views/pdf/invoice.blade.php --}}

<div class="w-[8.5in] min-h-[11in] bg-white p-12">
    {{-- Header --}}
    <div class="flex justify-between items-start mb-8">
        <div>
            <img src="{{ $company['logo_url'] }}" class="h-12 max-w-48">
            <div class="text-sm text-gray-600">{{ $company['email'] }}</div>
        </div>
        <div class="text-right">
            <div class="text-4xl font-bold">INVOICE</div>
            <div class="text-gray-600">#{{ $invoice->number }}</div>
        </div>
    </div>

    {{-- Content --}}
    <div class="mt-8">
        {{-- ... --}}
    </div>
</div>
```

### Page Breaks

Use these utility classes for page control:

```html
<!-- Force page break before -->
<div class="break-before-page">New page content</div>

<!-- Force page break after -->
<div class="break-after-page">Content before break</div>

<!-- Prevent element from breaking across pages -->
<div class="break-inside-avoid">Keep together</div>

<!-- Legacy classes (also supported) -->
<div class="page-break-before">...</div>
<div class="page-break-after">...</div>
<div class="page-break-inside-avoid">...</div>
```

### Multi-Page Documents

```blade
{{-- Page 1 --}}
<div class="w-[8.5in] min-h-[11in] bg-white p-10 break-after-page">
    {{-- Page 1 content --}}
</div>

{{-- Page 2 --}}
<div class="w-[8.5in] min-h-[11in] bg-white p-10">
    {{-- Page 2 content --}}
</div>
```

### Using Dynamic Colors

Instead of hardcoding colors, pass them via variables:

```blade
@php
    $primaryColor = $settings->primary_color ?? '#2563eb';
@endphp

{{-- Use inline styles for dynamic colors --}}
<div class="font-bold" style="color: {{ $primaryColor }}">
    Branded Text
</div>

<div class="p-4" style="background-color: {{ $primaryColor }}">
    Branded Background
</div>

<div class="border-l-4" style="border-color: {{ $primaryColor }}">
    Accent Border
</div>
```

Or use the `colors()` method with CSS variables:

```php
TailwindPdf::view('pdf.report', $data)
    ->colors(['primary' => $settings->primary_color])
    ->save('report.pdf');
```

```blade
<div class="bg-primary text-white">Uses custom primary color</div>
```

## Best Practices

### 1. Use Fixed Widths for Consistent Layout

```html
<!-- Good: Fixed width matches paper size -->
<div class="w-[8.5in] min-h-[11in]">...</div>

<!-- For A4 -->
<div class="w-[210mm] min-h-[297mm]">...</div>
```

### 2. Use Print-Safe Colors

Background colors print correctly with the `showBackground` option (enabled by default). For guaranteed printing, use:

```html
<div class="bg-gray-100 print:bg-gray-100">...</div>
```

### 3. Tables for Complex Layouts

For layouts that need to align perfectly (invoices, reports), tables often work better than flexbox/grid in PDFs:

```html
<table class="w-full">
    <tr>
        <td class="w-1/2 align-top">Left column</td>
        <td class="w-1/2 align-top">Right column</td>
    </tr>
</table>
```

### 4. Font Considerations

The service waits for fonts to load, so web fonts work. For best results:

```html
<!-- System fonts (fast, reliable) -->
<div class="font-sans">Uses system sans-serif</div>
<div class="font-mono">Uses system monospace</div>

<!-- Web fonts (include in template) -->
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<div style="font-family: Inter, sans-serif">Custom font</div>
```

### 5. Images

Images should use absolute URLs:

```blade
{{-- Good --}}
<img src="{{ asset('images/logo.png') }}">
<img src="https://example.com/logo.png">
<img src="{{ $company['logo_url'] }}">

{{-- Works with base64 --}}
<img src="data:image/png;base64,{{ $base64Image }}">
```

## Existing Templates

The application includes these ready-to-use templates:

| Template | Location | Usage |
|----------|----------|-------|
| Invoice | `resources/views/pdf/invoice.blade.php` | Professional invoices with payment options |
| Client Report | `resources/views/pdf/client-report.blade.php` | Monthly progress reports |

## Chrome Requirement

**Chrome is required** for PDF generation. The service will throw a `RuntimeException` if Chrome is not available.

Chrome is guaranteed to be available in production via the `deploy/chrome.sh` script which:
- Installs Chrome headless shell on x86_64 systems
- Uses Puppeteer to install Chrome on ARM64 systems
- **Fails deployment** if Chrome cannot be installed

This ensures pixel-perfect Tailwind CSS rendering with full support for:
- Grid and Flexbox layouts
- Modern CSS features
- Web fonts
- Gradients and shadows
- All Tailwind v4 utilities

## Infrastructure

### Laravel Cloud (Production)

Chrome is automatically installed via `deploy/chrome.sh`. The service auto-detects:
- `~/bin/chrome/chrome-headless-shell`
- `~/bin/chrome/chrome`

### Local Development

Ensure Chrome or Chromium is installed:

```bash
# macOS (Chrome is usually already installed)
# Or install Chromium:
brew install chromium

# Ubuntu/Debian
sudo apt install chromium-browser

# The service auto-detects common paths:
# - /usr/bin/chromium-browser
# - /usr/bin/chromium
# - /usr/bin/google-chrome
# - /Applications/Google Chrome.app/Contents/MacOS/Google Chrome
```

### Docker

The service automatically enables `--no-sandbox` and other container-safe flags when it detects Docker (via `/.dockerenv` or `LARAVEL_CLOUD` env var).

## Testing

Run PDF tests:

```bash
php artisan test --filter=TailwindPdf
```

Tests verify:
- HTML wrapping and Tailwind injection
- Configuration options (format, margins, colors)
- Fluent interface chainability
- Response generation (download/stream)

## Troubleshooting

### Chrome not found error

If you see `RuntimeException: Chrome is required for PDF generation but was not found`:

1. **In production**: Ensure `deploy/chrome.sh` ran successfully during deployment
2. **Locally**: Install Chrome or Chromium (see Infrastructure section above)
3. **Debug**: Check what path was detected:
   ```php
   $pdf = TailwindPdf::html('<div>Test</div>');
   dd($pdf->getChromePath()); // Should show path or null
   ```

### PDF is blank or missing styles

1. Ensure the template uses absolute URLs for assets
2. Check that Tailwind classes are valid
3. The service waits for network idle and fonts, but complex pages may need more time

### Fonts not rendering

The service waits for `document.fonts.ready`. If using custom fonts:
1. Ensure font URLs are accessible
2. Use `@font-face` with absolute URLs
3. Consider embedding fonts as base64

### Page breaks not working

Use the proper utility classes:
```html
<div class="break-before-page">...</div>
<div class="break-inside-avoid">...</div>
```

### Colors not printing

The `showBackground` option is enabled by default. If colors still don't print:
```html
<div style="-webkit-print-color-adjust: exact; print-color-adjust: exact;">
    Content with guaranteed color printing
</div>
```

## API Reference

### Static Constructors

| Method | Description |
|--------|-------------|
| `TailwindPdf::view($view, $data)` | Create from Blade view |
| `TailwindPdf::html($html)` | Create from raw HTML |

### Configuration Methods

| Method | Description |
|--------|-------------|
| `format($format)` | Set paper format (Letter, A4, Legal, etc.) |
| `a4()` | Shorthand for A4 format |
| `letter()` | Shorthand for Letter format |
| `landscape()` | Set landscape orientation |
| `portrait()` | Set portrait orientation |
| `margins($top, $right, $bottom, $left)` | Set margins in mm |
| `margin($mm)` | Set uniform margins |
| `noMargins()` | Remove all margins |
| `deviceScale($factor)` | Set device scale factor (default: 2) |
| `scale($scale)` | Set render scale (0.1-2.0) |
| `colors($colors)` | Add custom Tailwind colors |
| `primaryColor($color)` | Add primary brand color |
| `tailwindConfig($config)` | Set full Tailwind config |
| `header($html)` | Set header HTML |
| `footer($html)` | Set footer HTML |

### Output Methods

| Method | Description |
|--------|-------------|
| `content()` | Get PDF as string |
| `save($path)` | Save to storage path |
| `saveTo($disk, $path)` | Save to specific disk |
| `download($filename)` | Return download response |
| `stream($filename)` | Return inline view response |

### Utility Methods

| Method | Description |
|--------|-------------|
| `getHtml()` | Get the rendered HTML |
| `getChromePath()` | Get the detected Chrome path (for debugging) |
