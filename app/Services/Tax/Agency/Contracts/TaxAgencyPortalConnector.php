<?php

namespace App\Services\Tax\Agency\Contracts;

use App\Models\TaxAgencyConnection;

interface TaxAgencyPortalConnector
{
    public function agencyCode(): string;

    /**
     * @return array{
     *     balances: array<int, array<string, mixed>>,
     *     documents: array<int, array<string, mixed>>,
     *     summary: array<string, mixed>,
     * }
     */
    public function pull(TaxAgencyConnection $connection, int $year): array;
}
