<?php

namespace App\Console\Commands;

use App\Mcp\Tools\AuditGeneratedTaxFormsTool;
use Illuminate\Console\Command;
use Laravel\Mcp\Request as McpRequest;

class AuditTaxForms extends Command
{
    protected $signature = 'tax:audit-forms {--year= : Tax year to audit (default: current year)}';

    protected $description = 'Run the comprehensive tax-forms audit (math + cross-form consistency) for a given year and print the result. Wraps the AuditGeneratedTaxFormsTool MCP tool so it is also runnable from CI / SSH / local CLI.';

    public function handle(AuditGeneratedTaxFormsTool $tool): int
    {
        $year = (int) ($this->option('year') ?? now()->year);

        $request = new McpRequest(['year' => $year]);
        $response = $tool->handle($request);

        // Laravel MCP responses expose ->content() / ->toArray() inconsistently across
        // versions — pull the structured data via the public API.
        $payload = method_exists($response, 'toArray') ? $response->toArray() : (array) $response;

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $errors = (int) ($payload['summary']['errors'] ?? 0);

        return $errors === 0 ? self::SUCCESS : self::FAILURE;
    }
}
