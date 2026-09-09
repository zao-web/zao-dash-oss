<?php

namespace App\Listeners;

use App\Events\LeadAttributedToSeoPage;
use Illuminate\Support\Facades\Log;

class LogLeadAttribution
{
    /**
     * Handle the event.
     *
     * Logs lead attribution for analytics and debugging.
     * This provides visibility into the SEO → Lead attribution pipeline.
     */
    public function handle(LeadAttributedToSeoPage $event): void
    {
        Log::info('Lead attributed to SEO page', [
            'lead_id' => $event->lead->id,
            'lead_company' => $event->lead->company_name,
            'lead_source' => $event->lead->first_touch_source,
            'seo_page_id' => $event->seoPage->id,
            'seo_page_url' => $event->seoPage->page_url,
            'seo_page_keyword' => $event->seoPage->primary_keyword,
            'total_leads_on_page' => $event->seoPage->total_leads,
        ]);
    }
}
