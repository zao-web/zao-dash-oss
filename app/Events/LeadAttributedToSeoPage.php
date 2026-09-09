<?php

namespace App\Events;

use App\Models\Lead;
use App\Models\SeoPage;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class LeadAttributedToSeoPage
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Lead $lead,
        public SeoPage $seoPage,
    ) {}
}
