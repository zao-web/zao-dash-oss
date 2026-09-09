<?php

namespace App\Mcp\Tools;

use App\Services\PersonalFinance\InvoiceVelocityService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class GetInvoiceVelocityTool extends Tool
{
    protected string $name = 'get-invoice-velocity';

    protected string $title = 'Get Invoice Velocity';

    protected string $description = 'Get invoice payment velocity metrics including Days Sales Outstanding (DSO) per client, overall DSO, and quick-pay discount analysis.';

    public function __construct(
        protected InvoiceVelocityService $velocityService,
    ) {}

    public function handle(Request $request): Response|ResponseFactory
    {
        $dsoByClient = $this->velocityService->getDsoByClient();
        $overallDso = $this->velocityService->getOverallDso();

        $result = [
            'overall_dso' => $overallDso,
            'clients' => $dsoByClient,
        ];

        // If quick-pay simulation params provided, include analysis
        $invoiceAmount = $request->get('invoice_amount');
        $discountPercent = $request->get('discount_percent');
        $highestDebtRate = $request->get('highest_debt_rate');

        if ($invoiceAmount && $discountPercent && $highestDebtRate) {
            $result['quick_pay_analysis'] = $this->velocityService->calculateQuickPayDiscount(
                (float) $invoiceAmount,
                (float) $discountPercent,
                $overallDso,
                (float) $highestDebtRate
            );
        }

        return Response::structured($result);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'invoice_amount' => $schema->number()
                ->description('Optional: Invoice amount for quick-pay discount simulation'),
            'discount_percent' => $schema->number()
                ->description('Optional: Discount percentage to offer (e.g. 2 for 2%)'),
            'highest_debt_rate' => $schema->number()
                ->description('Optional: Highest debt interest rate for comparison'),
        ];
    }
}
