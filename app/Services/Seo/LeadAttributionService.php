<?php

namespace App\Services\Seo;

use App\Events\LeadAttributedToSeoPage;
use App\Models\Lead;
use App\Models\SeoPage;
use Illuminate\Support\Facades\DB;

class LeadAttributionService
{
    public function attributeLeadFromForm(Lead $lead, array $attributionData): Lead
    {
        $lead->update([
            'first_touch_page_url' => $attributionData['first_touch_page_url'] ?? null,
            'first_touch_keyword' => $attributionData['first_touch_keyword'] ?? null,
            'first_touch_source' => $attributionData['first_touch_source'] ?? null,
            'first_touch_medium' => $attributionData['first_touch_medium'] ?? null,
            'first_touch_campaign' => $attributionData['first_touch_campaign'] ?? null,
            'last_touch_page_url' => $attributionData['last_touch_page_url'] ?? null,
            'pages_viewed' => $attributionData['pages_viewed'] ?? null,
            'time_on_site_seconds' => $attributionData['time_on_site_seconds'] ?? null,
            'max_scroll_depth' => $attributionData['max_scroll_depth'] ?? null,
            'ga4_client_id' => $attributionData['ga4_client_id'] ?? null,
            'ga4_session_id' => $attributionData['ga4_session_id'] ?? null,
        ]);

        $this->linkToSeoPage($lead);

        return $lead->fresh();
    }

    public function linkToSeoPage(Lead $lead): bool
    {
        if (! $lead->first_touch_page_url) {
            return false;
        }

        // Prevent double attribution
        if ($lead->seo_page_id !== null) {
            return false;
        }

        $seoPage = $this->findSeoPageByUrl($lead->first_touch_page_url);

        if (! $seoPage) {
            return false;
        }

        // Wrap in transaction to prevent race conditions and counter drift
        return DB::transaction(function () use ($lead, $seoPage) {
            $lead->update(['seo_page_id' => $seoPage->id]);
            $seoPage->increment('total_leads');

            event(new LeadAttributedToSeoPage($lead, $seoPage));

            return true;
        });
    }

    public function findSeoPageByUrl(string $url): ?SeoPage
    {
        $cleanUrl = $this->normalizeUrl($url);

        // Escape LIKE wildcards to prevent injection
        $path = str_replace(['%', '_'], ['\%', '\_'], parse_url($cleanUrl, PHP_URL_PATH) ?? '');

        return SeoPage::where('page_url', $cleanUrl)
            ->orWhere('page_url', 'like', '%'.$path)
            ->first();
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $path = rtrim($parsed['path'] ?? '', '/');

        return "{$scheme}://{$host}{$path}";
    }

    public function calculateLeadValue(Lead $lead): float
    {
        if ($lead->converted_at && $lead->deal_value) {
            return (float) $lead->deal_value;
        }

        return (float) ($lead->deal_value ?? 0) * (($lead->probability ?? 0) / 100);
    }

    public function getAttributionSummary(SeoPage $seoPage): array
    {
        // Use database aggregations instead of loading all leads into memory
        $stats = $seoPage->leads()
            ->selectRaw('
                COUNT(*) as total_leads,
                COALESCE(SUM(deal_value), 0) as total_value,
                SUM(CASE WHEN converted_at IS NOT NULL THEN 1 ELSE 0 END) as converted_leads,
                AVG(time_on_site_seconds) as avg_time_on_site,
                AVG(pages_viewed) as avg_pages_viewed
            ')
            ->first();

        $totalLeads = (int) $stats->total_leads;
        $convertedLeads = (int) $stats->converted_leads;

        // Get top sources with a separate efficient query
        $topSources = $seoPage->leads()
            ->select('first_touch_source')
            ->selectRaw('COUNT(*) as count')
            ->whereNotNull('first_touch_source')
            ->groupBy('first_touch_source')
            ->orderByDesc('count')
            ->limit(5)
            ->pluck('count', 'first_touch_source')
            ->toArray();

        return [
            'total_leads' => $totalLeads,
            'total_value' => (float) $stats->total_value,
            'converted_leads' => $convertedLeads,
            'conversion_rate' => $totalLeads > 0
                ? round($convertedLeads / $totalLeads * 100, 2)
                : 0,
            'avg_time_on_site' => $stats->avg_time_on_site ? (float) $stats->avg_time_on_site : null,
            'avg_pages_viewed' => $stats->avg_pages_viewed ? (float) $stats->avg_pages_viewed : null,
            'top_sources' => $topSources,
        ];
    }
}
