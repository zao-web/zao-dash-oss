<?php

namespace App\Services\Tax\Agency;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class TaxPortalFlowCaptureAnalyzer
{
    /**
     * @param  array<string, mixed>  $capture
     * @return array{
     *     agency: string,
     *     agency_label: string|null,
     *     start_url: string|null,
     *     capture_window: array{started_at: string|null, stopped_at: string|null, duration_seconds: int|null},
     *     hosts: array<int, string>,
     *     auth: array{
     *         identity_provider: string|null,
     *         password_step_count: int,
     *         otp_step_count: int,
     *         form_page_count: int,
     *         requires_mfa: bool
     *     },
     *     request_summary: array{
     *         total: int,
     *         completed: int,
     *         redirected: int,
     *         errored: int
     *     },
     *     surfaces: array{
     *         balances: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *         payments: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *         transcripts: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *         notices: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *         downloads: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *         auth: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     *     },
     *     bridge_blueprint: array{
     *         recommended_worker: string,
     *         session_strategy: string,
     *         start_url: string|null,
     *         login_hosts: array<int, string>,
     *         identity_provider: string|null,
     *         requires_mfa: bool,
     *         surfaces: array{
     *             balances: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *             payments: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *             transcripts: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *             notices: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *             downloads: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *             auth: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     *         }
     *     },
     *     risks: array<int, string>,
     *     next_actions: array<int, string>,
     * }
     */
    public function analyze(array $capture): array
    {
        $navigation = $this->records($capture['navigation'] ?? []);
        $events = $this->records($capture['events'] ?? []);
        $requests = $this->records($capture['requests'] ?? []);
        $catalogue = $this->pathCatalogue($navigation, $events, $requests);
        $surfaces = $this->surfaceSummary($catalogue);
        $hosts = $this->hosts($catalogue);
        $pageContexts = array_values(array_filter($events, fn (array $event): bool => ($event['type'] ?? null) === 'page_context'));
        $passwordStepCount = count(array_filter($pageContexts, fn (array $event): bool => (int) ($event['password_input_count'] ?? 0) > 0));
        $otpStepCount = count(array_filter($pageContexts, fn (array $event): bool => (int) ($event['otp_input_count'] ?? 0) > 0));
        $formPageCount = count(array_filter($pageContexts, fn (array $event): bool => (int) ($event['form_count'] ?? 0) > 0));
        $identityProvider = $this->identityProvider($hosts);
        $requiresMfa = $otpStepCount > 0 || ! empty($surfaces['auth']);

        return [
            'agency' => $this->stringValue($capture['agency'] ?? null) ?? 'unknown',
            'agency_label' => $this->stringValue($capture['agency_label'] ?? null),
            'start_url' => $this->stringValue($capture['started_url'] ?? null),
            'capture_window' => [
                'started_at' => $this->stringValue($capture['started_at'] ?? null),
                'stopped_at' => $this->stringValue($capture['stopped_at'] ?? null),
                'duration_seconds' => $this->durationSeconds(
                    $this->stringValue($capture['started_at'] ?? null),
                    $this->stringValue($capture['stopped_at'] ?? null),
                ),
            ],
            'hosts' => $hosts,
            'auth' => [
                'identity_provider' => $identityProvider,
                'password_step_count' => $passwordStepCount,
                'otp_step_count' => $otpStepCount,
                'form_page_count' => $formPageCount,
                'requires_mfa' => $requiresMfa,
            ],
            'request_summary' => [
                'total' => count($requests),
                'completed' => count(array_filter($requests, fn (array $request): bool => ($request['status'] ?? null) === 'completed')),
                'redirected' => count(array_filter($requests, fn (array $request): bool => ($request['status'] ?? null) === 'redirected')),
                'errored' => count(array_filter($requests, fn (array $request): bool => ($request['status'] ?? null) === 'error')),
            ],
            'surfaces' => $surfaces,
            'bridge_blueprint' => [
                'recommended_worker' => 'self-hosted-playwright',
                'session_strategy' => 'delegated-vault-credentials-with-session-refresh',
                'start_url' => $this->stringValue($capture['started_url'] ?? null),
                'login_hosts' => $hosts,
                'identity_provider' => $identityProvider,
                'requires_mfa' => $requiresMfa,
                'surfaces' => $surfaces,
            ],
            'risks' => $this->risks($surfaces, $requiresMfa),
            'next_actions' => $this->nextActions($surfaces, $requiresMfa, $identityProvider),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function records(mixed $records): array
    {
        if (! is_array($records)) {
            return [];
        }

        return array_values(array_filter($records, fn (mixed $record): bool => is_array($record)));
    }

    /**
     * @param  array<int, array<string, mixed>>  $navigation
     * @param  array<int, array<string, mixed>>  $events
     * @param  array<int, array<string, mixed>>  $requests
     * @return array<string, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     */
    protected function pathCatalogue(array $navigation, array $events, array $requests): array
    {
        $catalogue = [];

        foreach ($navigation as $record) {
            $this->addPathHit($catalogue, $this->stringValue($record['path'] ?? null), $this->stringValue($record['host'] ?? null), 'navigation');
        }

        foreach ($events as $record) {
            $this->addPathHit(
                $catalogue,
                $this->stringValue($record['page_path'] ?? null) ?? $this->pathFromUrl($this->stringValue($record['page_url'] ?? null)),
                $this->stringValue($record['page_host'] ?? null),
                'event',
            );
        }

        foreach ($requests as $record) {
            $this->addPathHit($catalogue, $this->stringValue($record['path'] ?? null), $this->stringValue($record['host'] ?? null), 'request');
            $this->addPathHit($catalogue, $this->stringValue($record['redirect_path'] ?? null), $this->stringValue($record['redirect_host'] ?? null), 'redirect');
        }

        return $catalogue;
    }

    /**
     * @param  array<string, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>  $catalogue
     */
    protected function addPathHit(array &$catalogue, ?string $path, ?string $host, string $source): void
    {
        if ($path === null || $path === '') {
            return;
        }

        $key = ($host ?? 'unknown').'|'.$path;

        if (! array_key_exists($key, $catalogue)) {
            $catalogue[$key] = [
                'path' => $path,
                'hosts' => [],
                'sources' => [],
                'count' => 0,
            ];
        }

        if ($host !== null && ! in_array($host, $catalogue[$key]['hosts'], true)) {
            $catalogue[$key]['hosts'][] = $host;
        }

        if (! in_array($source, $catalogue[$key]['sources'], true)) {
            $catalogue[$key]['sources'][] = $source;
        }

        $catalogue[$key]['count']++;
    }

    protected function pathFromUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        $path = parse_url($url, PHP_URL_PATH);

        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * @param  array<string, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>  $catalogue
     * @return array<int, string>
     */
    protected function hosts(array $catalogue): array
    {
        $hosts = [];

        foreach ($catalogue as $entry) {
            foreach ($entry['hosts'] as $host) {
                if (! in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }
        }

        sort($hosts);

        return $hosts;
    }

    /**
     * @param  array<string, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>  $catalogue
     * @return array{
     *     balances: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     payments: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     transcripts: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     notices: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     downloads: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     auth: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     * }
     */
    protected function surfaceSummary(array $catalogue): array
    {
        return [
            'balances' => $this->matchingPaths($catalogue, ['balance', 'balances', 'account-balance', 'amount-due', 'liability', 'due']),
            'payments' => $this->matchingPaths($catalogue, ['payment', 'payments', 'pay-history', 'payment-history', 'estimated-tax', 'eftps']),
            'transcripts' => $this->matchingPaths($catalogue, ['transcript', 'record-of-account', 'account-transcript', 'return-transcript']),
            'notices' => $this->matchingPaths($catalogue, ['notice', 'notices', 'letter', 'letters', 'correspondence', 'cp']),
            'downloads' => $this->matchingPaths($catalogue, ['download', '.pdf', 'document', 'documents', 'file']),
            'auth' => $this->matchingPaths($catalogue, ['login', 'signin', 'auth', 'verify', 'challenge', 'otp', 'mfa', 'idme', 'id.me']),
        ];
    }

    /**
     * @param  array<string, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>  $catalogue
     * @param  array<int, string>  $keywords
     * @return array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     */
    protected function matchingPaths(array $catalogue, array $keywords): array
    {
        $matches = array_values(array_filter($catalogue, function (array $entry) use ($keywords): bool {
            return $this->containsKeyword($entry['path'], $keywords);
        }));

        usort($matches, function (array $left, array $right): int {
            if ($left['count'] === $right['count']) {
                return strcmp($left['path'], $right['path']);
            }

            return $right['count'] <=> $left['count'];
        });

        return $matches;
    }

    /**
     * @param  array<int, string>  $keywords
     */
    protected function containsKeyword(string $subject, array $keywords): bool
    {
        return Str::contains(Str::lower($subject), array_map(static fn (string $keyword): string => Str::lower($keyword), $keywords));
    }

    /**
     * @param  array<int, string>  $hosts
     */
    protected function identityProvider(array $hosts): ?string
    {
        foreach ($hosts as $host) {
            if (Str::contains($host, 'id.me')) {
                return 'id.me';
            }

            if (Str::contains($host, 'login.gov')) {
                return 'login.gov';
            }
        }

        return null;
    }

    protected function durationSeconds(?string $startedAt, ?string $stoppedAt): ?int
    {
        if ($startedAt === null || $stoppedAt === null) {
            return null;
        }

        try {
            $start = Carbon::parse($startedAt);
            $stop = Carbon::parse($stoppedAt);

            return max(0, $start->diffInSeconds($stop));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array{
     *     balances: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     payments: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     transcripts: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     notices: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     downloads: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     auth: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     * }  $surfaces
     * @return array<int, string>
     */
    protected function risks(array $surfaces, bool $requiresMfa): array
    {
        $risks = [];

        if ($surfaces['balances'] === []) {
            $risks[] = 'No balance or amount-due surface was captured yet.';
        }

        if ($surfaces['payments'] === []) {
            $risks[] = 'No payment-history surface was captured yet.';
        }

        if ($surfaces['transcripts'] === [] && $surfaces['downloads'] === []) {
            $risks[] = 'No transcript or downloadable document surface was captured yet.';
        }

        if ($requiresMfa) {
            $risks[] = 'MFA appears to be required, so the bridge worker needs an owner-safe refresh strategy.';
        }

        return $risks;
    }

    /**
     * @param  array{
     *     balances: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     payments: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     transcripts: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     notices: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     downloads: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>,
     *     auth: array<int, array{path: string, hosts: array<int, string>, sources: array<int, string>, count: int}>
     * }  $surfaces
     * @return array<int, string>
     */
    protected function nextActions(array $surfaces, bool $requiresMfa, ?string $identityProvider): array
    {
        $actions = [
            'Build the self-hosted Playwright worker against the captured login hosts and start URL.',
            'Provision delegated vault credentials once the final username/password entry points are confirmed.',
        ];

        if ($identityProvider !== null) {
            $actions[] = "Model {$identityProvider} as the identity-provider step in the worker.";
        }

        if ($requiresMfa) {
            $actions[] = 'Implement MFA-aware session refresh so unattended sync can recover without exposing secrets in the app.';
        }

        if ($surfaces['balances'] !== []) {
            $actions[] = 'Use the captured balance surfaces to implement the first balance/amount-due extraction routine.';
        }

        if ($surfaces['payments'] !== []) {
            $actions[] = 'Use the captured payment-history surfaces to reconcile portal payments against the internal ledger.';
        }

        if ($surfaces['transcripts'] !== [] || $surfaces['downloads'] !== []) {
            $actions[] = 'Use the captured transcript/download surfaces to fetch notices, transcripts, and filing evidence automatically.';
        }

        return $actions;
    }

    protected function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
