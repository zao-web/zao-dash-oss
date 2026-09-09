# Public snapshot of Zao Dash

This file ships in the public `zao-dash-oss` tree. It records what the export removed so operators are not surprised by missing Life or tax routes.

## Strip list

- Owner PII in seeders and config defaults (names and agency mailboxes).
- Production-flavored env defaults (`PLAID_ENVIRONMENT=production`, `WISE_ENVIRONMENT=production`, staging hosts, mailbox usernames).
- Private Slack channel and user IDs in tests and docs. Replaced with `C00EXAMPLE*` / `U00EXAMPLE*` placeholders.
- `.mcp.json` production Cloud hostnames. Replaced with `http://localhost:8000`.
- Internal agent scratch (`.agent`, `.playwright-mcp`, `.claude`, `todos`).
- Local homedir paths.
- Agency production hosts, internal calendar domains, live Meta ad-account IDs, and the private GitHub repo default.
- Gravity Forms live form-id field maps.
- Life dashboard Vue pages and `LifeDashboardController` HTTP routes.
- Tax calibration Vue, official tax-form PDFs, tax-form download, and tax-agency bridge HTTP routes.
- Plaid webhook route and Plaid/Teller/tax schedule entries.
- Household and CPA/tax MCP tools. Finance MCP keeps client profitability and invoice DSO.
- CPA, daily-life, personal-finance, and tax-resolution agents plus their skill files.
- Household tool names on the Slack MCP allowlist and the CFO agent.
- Tax-bridge worker, Dockerfile, and Chrome recorder.
- Life and tax office docs that only served the private household product.
- Operator scratch such as bookmark-scrape notes.

## Stubs

- `ZaoFinanceServer` remains registered at `/mcp/zao-finance` with two agency analytics tools (`get-client-profitability`, `get-invoice-velocity`).
- Personal-account, tax-profile, and related Eloquent models/migrations may still exist so `php artisan migrate` does not fork from the private schema. They have no HTTP routes in this snapshot.
- `App\Services\PersonalFinance` and `App\Services\Tax` classes remain, including invoice-velocity code that still lives under the PersonalFinance namespace. Tax-form Artisan commands (`DownloadOfficialTaxForms`, `DumpTaxFormFields`, `CheckTaxFormDependencies`, `ConvertTaxFormTemplates`, and related tax console commands) also remain so leftover class references and migrate stay coherent. They are not registered on HTTP or MCP in this snapshot.
- `.env.example` still lists Plaid/Teller keys so older operator notes parse. Those integrations are not routed.

## What stayed

Clients, projects, tasks, retainers, invoices, leads, agents, website builder, WordPress MCP, Slack/GitHub/Google agency integrations, and the MCP framework.

## License

MIT on this repository's source. Third-party Composer packages keep their own licenses. TCPDF is LGPL. That is not a conflict with MIT on our code.
