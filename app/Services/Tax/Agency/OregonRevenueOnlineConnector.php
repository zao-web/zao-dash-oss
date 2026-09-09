<?php

namespace App\Services\Tax\Agency;

use App\Models\TaxAgencyConnection;

class OregonRevenueOnlineConnector extends BridgeBackedTaxAgencyConnector
{
    public function agencyCode(): string
    {
        return TaxAgencyConnection::AGENCY_OREGON_DOR;
    }
}
