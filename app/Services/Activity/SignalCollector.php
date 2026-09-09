<?php

namespace App\Services\Activity;

use App\Models\Client;
use App\Services\Activity\Signals\EmailSignalCollector;
use App\Services\Activity\Signals\GitHubSignalCollector;
use App\Services\Activity\Signals\InternalTaskSignalCollector;
use App\Services\Activity\Signals\SlackSignalCollector;
use Carbon\Carbon;

class SignalCollector
{
    public const WINDOW_DAYS = 60;

    public function __construct(
        protected SlackSignalCollector $slack,
        protected EmailSignalCollector $email,
        protected GitHubSignalCollector $github,
        protected InternalTaskSignalCollector $tasks,
    ) {}

    /**
     * Collect every activity signal we can see for the client across all
     * four sources within the rolling window. Returned as a flat array
     * sorted by occurrence so the synthesis prompt receives chronological
     * context.
     *
     * @return array{
     *   client_id: int,
     *   client_name: string,
     *   window_days: int,
     *   since: string,
     *   signals: array<int, array{source_type:string, external_id:string, permalink:?string, occurred_at:\Carbon\Carbon, actor:?string, content:string, meta:array<string,mixed>}>
     * }
     */
    public function collect(Client $client, ?Carbon $since = null): array
    {
        $since ??= now()->subDays(self::WINDOW_DAYS);

        $signals = [
            ...$this->slack->collect($client, $since),
            ...$this->email->collect($client, $since),
            ...$this->github->collect($client, $since),
            ...$this->tasks->collect($client, $since),
        ];

        usort(
            $signals,
            fn (array $a, array $b) => $a['occurred_at']->timestamp <=> $b['occurred_at']->timestamp,
        );

        return [
            'client_id' => $client->id,
            'client_name' => $client->name,
            'window_days' => self::WINDOW_DAYS,
            'since' => $since->toDateString(),
            'signals' => $signals,
        ];
    }
}
