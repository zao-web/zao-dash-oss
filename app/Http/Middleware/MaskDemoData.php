<?php

namespace App\Http\Middleware;

use App\Support\Demo\DemoDataMasker;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Anonymizes every Inertia response when demo mode is active.
 *
 * This is the single seam through which all page props pass: the initial HTML
 * load (Inertia embeds props in a `data-page` attribute) and every subsequent
 * XHR navigation (`X-Inertia` JSON). Masking here covers the entire dashboard
 * without touching any controller.
 *
 * Demo mode is owner-only and session-backed; see DemoModeController.
 */
class MaskDemoData
{
    public const SESSION_KEY = 'demo_mode';

    public const SEED_KEY = 'demo_mode_seed';

    public function handle(Request $request, Closure $next): Response
    {
        // Demo mode is read-only: the props shown on screen are anonymized, so a
        // write would persist fake values (or hit a real record by its unmasked id)
        // against production data. Block mutations before they reach a controller.
        if ($this->shouldMask($request) && $this->isBlockedWrite($request)) {
            return redirect()->back()->with('error', 'Demo mode is read-only — turn it off to make changes.');
        }

        $response = $next($request);

        if (! $this->shouldMask($request)) {
            return $response;
        }

        $masker = new DemoDataMasker($this->seed($request));

        if ($response instanceof JsonResponse && $request->headers->has('X-Inertia')) {
            return $this->maskJson($response, $masker);
        }

        if ($this->isInertiaHtml($response)) {
            return $this->maskHtml($response, $masker);
        }

        return $response;
    }

    private function shouldMask(Request $request): bool
    {
        $user = $request->user();

        return $user !== null
            && $user->role === 'owner'
            && (bool) $request->session()->get(self::SESSION_KEY, false);
    }

    /**
     * A mutating request that must be blocked while demo mode is active.
     * Turning demo mode off and signing out are always allowed.
     */
    private function isBlockedWrite(Request $request): bool
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        return ! $request->is('demo-mode/toggle', 'logout');
    }

    private function seed(Request $request): string
    {
        $seed = $request->session()->get(self::SEED_KEY);

        if (! is_string($seed) || $seed === '') {
            $seed = bin2hex(random_bytes(8));
            $request->session()->put(self::SEED_KEY, $seed);
        }

        return $seed;
    }

    private function maskJson(JsonResponse $response, DemoDataMasker $masker): JsonResponse
    {
        $page = $response->getData(true);

        if (is_array($page) && isset($page['props']) && is_array($page['props'])) {
            $page['props'] = $masker->maskProps($page['props']);
            $response->setData($page);
        }

        return $response;
    }

    private function isInertiaHtml(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains($contentType, 'text/html')
            && is_string($response->getContent())
            && str_contains($response->getContent(), 'data-page=');
    }

    private function maskHtml(Response $response, DemoDataMasker $masker): Response
    {
        $content = $response->getContent();

        if ($content === false) {
            return $response;
        }

        $pattern = '/(data-page=")(.*?)(")/s';

        $updated = preg_replace_callback($pattern, function (array $matches) use ($masker): string {
            $page = json_decode(htmlspecialchars_decode($matches[2], ENT_QUOTES), true);

            if (! is_array($page) || ! isset($page['props']) || ! is_array($page['props'])) {
                return $matches[0];
            }

            $page['props'] = $masker->maskProps($page['props']);

            $encoded = htmlspecialchars(
                json_encode($page, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ENT_QUOTES,
            );

            return $matches[1].$encoded.$matches[3];
        }, $content, 1);

        if (is_string($updated)) {
            $response->setContent($updated);
        }

        return $response;
    }
}
