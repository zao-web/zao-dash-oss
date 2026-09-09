<?php

namespace App\Services\Tax\Agency;

use App\Models\TaxAgencyConnection;

class IrsOnlineAccountConnector extends BridgeBackedTaxAgencyConnector
{
    public function agencyCode(): string
    {
        return TaxAgencyConnection::AGENCY_IRS;
    }
}
