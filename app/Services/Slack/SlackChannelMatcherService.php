<?php

namespace App\Services\Slack;

use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Project;
use App\Models\SlackChannel;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Intelligent channel-to-client matching service.
 *
 * Matches Slack channels to clients using multiple signals:
 * - Shared channel detection (strongest signal)
 * - Channel name matching against client/project names
 * - External participant email domain matching
 * - Historical message participant analysis
 */
class SlackChannelMatcherService
{
    /**
     * Minimum confidence score (0-100) to auto-link a channel.
     */
    private const AUTO_LINK_THRESHOLD = 70;

    /**
     * Attempt to match a channel to a client.
     *
     * @return array{client_id: int|null, confidence: int, signals: array, suggestions: array}
     */
    public function matchChannel(SlackChannel $channel): array
    {
        $signals = [];
        $scores = [];

        // Signal 1: Already linked (skip matching)
        if ($channel->client_id) {
            return [
                'client_id' => $channel->client_id,
                'confidence' => 100,
                'signals' => ['already_linked'],
                'suggestions' => [],
            ];
        }

        // Signal 2: Client has this channel set as their default (explicit association)
        $explicitClient = Client::where('slack_channel_id', $channel->id)->first();
        if ($explicitClient) {
            return [
                'client_id' => $explicitClient->id,
                'confidence' => 100,
                'signals' => ['explicit_client_setting'],
                'suggestions' => [],
            ];
        }

        // Signal 3: Shared channel (external users) - strongest signal
        if ($channel->is_shared) {
            $signals[] = 'shared_channel';
            $scores['shared'] = 40;
        }

        // Signal 3: Channel name matching
        $nameMatches = $this->matchByChannelName($channel);
        if (! empty($nameMatches)) {
            $signals[] = 'name_match';
            $scores['name'] = $nameMatches[0]['score'];
        }

        // Signal 4: External participant domain matching
        $domainMatches = $this->matchByParticipantDomains($channel);
        if (! empty($domainMatches)) {
            $signals[] = 'domain_match';
            $scores['domain'] = $domainMatches[0]['score'];
        }

        // Combine signals to find best match
        $allMatches = $this->consolidateMatches($nameMatches, $domainMatches);

        if (empty($allMatches)) {
            return [
                'client_id' => null,
                'confidence' => 0,
                'signals' => $signals,
                'suggestions' => [],
            ];
        }

        // Sort by total score
        usort($allMatches, fn ($a, $b) => $b['total_score'] <=> $a['total_score']);

        $bestMatch = $allMatches[0];
        $confidence = min(100, $bestMatch['total_score'] + ($channel->is_shared ? 20 : 0));

        return [
            'client_id' => $confidence >= self::AUTO_LINK_THRESHOLD ? $bestMatch['client_id'] : null,
            'confidence' => $confidence,
            'signals' => $signals,
            'suggestions' => array_slice($allMatches, 0, 3),
        ];
    }

    /**
     * Auto-link all unlinked channels.
     *
     * @return array{linked: int, suggestions: array}
     */
    public function autoLinkAllChannels(): array
    {
        $linked = 0;
        $suggestions = [];

        $unlinkedChannels = SlackChannel::whereNull('client_id')
            ->where('classification', '!=', 'internal')
            ->get();

        foreach ($unlinkedChannels as $channel) {
            $result = $this->matchChannel($channel);

            if ($result['client_id']) {
                // Enable monitoring on link. SyncSlackJob only pulls history
                // for channels with is_monitored=true, so linking a client
                // without flipping this leaves the channel's messages
                // un-ingested — and therefore invisible on retainer reports.
                $channel->update([
                    'client_id' => $result['client_id'],
                    'classification' => 'client',
                    'is_monitored' => true,
                    'monitoring_enabled' => true,
                ]);
                $linked++;

                Log::info('Auto-linked Slack channel to client', [
                    'channel' => $channel->name ?? $channel->channel_name,
                    'client_id' => $result['client_id'],
                    'confidence' => $result['confidence'],
                    'signals' => $result['signals'],
                ]);
            } elseif (! empty($result['suggestions'])) {
                $suggestions[] = [
                    'channel_id' => $channel->id,
                    'channel_name' => $channel->name ?? $channel->channel_name,
                    'suggestions' => $result['suggestions'],
                ];
            }
        }

        return [
            'linked' => $linked,
            'suggestions' => $suggestions,
        ];
    }

    /**
     * Match channel by name against client/project names.
     */
    protected function matchByChannelName(SlackChannel $channel): array
    {
        $channelName = strtolower($channel->name ?? $channel->channel_name ?? '');
        $channelSlug = Str::slug($channelName);
        $matches = [];

        // Skip generic channel names
        $genericNames = ['general', 'random', 'announcements', 'team', 'internal', 'dev', 'development'];
        if (in_array($channelName, $genericNames)) {
            return [];
        }

        $clients = Client::whereNull('deleted_at')->get();

        foreach ($clients as $client) {
            $clientSlug = Str::slug($client->name);
            $score = 0;

            // Exact match
            if ($channelSlug === $clientSlug) {
                $score = 90;
            }
            // Channel contains client name
            elseif (str_contains($channelSlug, $clientSlug) && strlen($clientSlug) >= 3) {
                $score = 70;
            }
            // Client name contains channel name
            elseif (str_contains($clientSlug, $channelSlug) && strlen($channelSlug) >= 3) {
                $score = 60;
            }
            // Distinctive-token matching. Strips generic corporate words
            // (LLC, Inc, Media, Group…) so a channel named "example-client" still
            // matches client "Example Client LLC" on the shared root "locum" —
            // the kind of match plain slug containment misses and that was
            // silently dropping client channels off retainer reports.
            else {
                $clientTokens = $this->distinctiveTokens($clientSlug);
                $channelTokens = $this->distinctiveTokens($channelSlug);
                $overlap = count(array_intersect($channelTokens, $clientTokens));

                if ($overlap >= 2) {
                    $score = 50;
                } elseif ($overlap === 1 && count($clientTokens) <= 2) {
                    $score = 40;
                }

                // Root-token containment: a distinctive client token (>=5
                // chars) that the channel slug starts with — or that starts
                // with the channel slug — scores high enough to auto-link;
                // a token merely embedded mid-slug scores lower. The length
                // floor keeps short generic fragments from false-matching.
                foreach ($clientTokens as $token) {
                    if (strlen($token) < 5) {
                        continue;
                    }
                    if (str_starts_with($channelSlug, $token) || str_starts_with($token, $channelSlug)) {
                        $score = max($score, 70);
                    } elseif (str_contains($channelSlug, $token)) {
                        $score = max($score, 60);
                    }
                }
            }

            if ($score > 0) {
                $matches[] = [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'score' => $score,
                    'match_type' => 'name',
                ];
            }
        }

        // Also check projects
        $projects = Project::with('client')
            ->whereNull('deleted_at')
            ->whereNotNull('client_id')
            ->get();

        foreach ($projects as $project) {
            $projectSlug = Str::slug($project->name);
            $score = 0;

            if ($channelSlug === $projectSlug) {
                $score = 85;
            } elseif (str_contains($channelSlug, $projectSlug) && strlen($projectSlug) >= 3) {
                $score = 65;
            }

            if ($score > 0 && $project->client) {
                // Check if we already have a higher score for this client
                $existing = collect($matches)->firstWhere('client_id', $project->client_id);
                if (! $existing || $existing['score'] < $score) {
                    $matches = collect($matches)
                        ->reject(fn ($m) => $m['client_id'] === $project->client_id)
                        ->push([
                            'client_id' => $project->client_id,
                            'client_name' => $project->client->name,
                            'score' => $score,
                            'match_type' => 'project_name',
                            'project_name' => $project->name,
                        ])
                        ->toArray();
                }
            }
        }

        usort($matches, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_slice($matches, 0, 5);
    }

    /**
     * Split a slug into distinctive tokens, dropping generic corporate
     * suffixes and filler words that would otherwise create false matches
     * or drag a real match's score to zero. "locum-media-llc" reduces to
     * ["locum"]; "pgri-zao-retainer" reduces to ["pgri"].
     *
     * @return array<int, string>
     */
    protected function distinctiveTokens(string $slug): array
    {
        $stopwords = [
            'llc', 'inc', 'co', 'corp', 'ltd', 'the', 'group', 'media',
            'agency', 'studio', 'studios', 'labs', 'partners', 'company',
            'retainer', 'project', 'zao',
        ];

        return array_values(array_filter(
            explode('-', $slug),
            fn ($w) => strlen($w) >= 3 && ! in_array($w, $stopwords, true),
        ));
    }

    /**
     * Match channel by external participant email domains.
     */
    protected function matchByParticipantDomains(SlackChannel $channel): array
    {
        // Get external user emails from messages
        $externalEmails = $channel->messages()
            ->where('user_is_external', true)
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->toArray();

        if (empty($externalEmails)) {
            return [];
        }

        // Get unique domains from client contacts
        $contacts = ClientContact::whereNotNull('email')
            ->whereHas('client', fn ($q) => $q->whereNull('deleted_at'))
            ->with('client')
            ->get();

        $domainToClients = [];
        foreach ($contacts as $contact) {
            $domain = $this->extractDomain($contact->email);
            if ($domain && ! $this->isGenericDomain($domain)) {
                if (! isset($domainToClients[$domain])) {
                    $domainToClients[$domain] = [];
                }
                $domainToClients[$domain][$contact->client_id] = $contact->client;
            }
        }

        // We'd need to resolve the Slack user IDs to emails
        // For now, use a simpler approach - check if any messages have client_id set from email matching
        $clientCounts = $channel->messages()
            ->where('user_is_external', true)
            ->whereNotNull('client_id')
            ->selectRaw('client_id, count(*) as msg_count')
            ->groupBy('client_id')
            ->orderByDesc('msg_count')
            ->get();

        $matches = [];
        foreach ($clientCounts as $row) {
            $client = Client::find($row->client_id);
            if ($client) {
                $score = min(80, 30 + ($row->msg_count * 5));
                $matches[] = [
                    'client_id' => $client->id,
                    'client_name' => $client->name,
                    'score' => $score,
                    'match_type' => 'participant_messages',
                    'message_count' => $row->msg_count,
                ];
            }
        }

        return $matches;
    }

    /**
     * Consolidate matches from different sources.
     */
    protected function consolidateMatches(array $nameMatches, array $domainMatches): array
    {
        $consolidated = [];

        foreach ($nameMatches as $match) {
            $key = $match['client_id'];
            if (! isset($consolidated[$key])) {
                $consolidated[$key] = [
                    'client_id' => $match['client_id'],
                    'client_name' => $match['client_name'],
                    'name_score' => 0,
                    'domain_score' => 0,
                    'total_score' => 0,
                ];
            }
            $consolidated[$key]['name_score'] = max($consolidated[$key]['name_score'], $match['score']);
        }

        foreach ($domainMatches as $match) {
            $key = $match['client_id'];
            if (! isset($consolidated[$key])) {
                $consolidated[$key] = [
                    'client_id' => $match['client_id'],
                    'client_name' => $match['client_name'],
                    'name_score' => 0,
                    'domain_score' => 0,
                    'total_score' => 0,
                ];
            }
            $consolidated[$key]['domain_score'] = max($consolidated[$key]['domain_score'], $match['score']);
        }

        // Calculate total scores with bonus for multiple signals
        foreach ($consolidated as &$match) {
            $match['total_score'] = $match['name_score'] + ($match['domain_score'] * 0.3);
            if ($match['name_score'] > 0 && $match['domain_score'] > 0) {
                $match['total_score'] += 15; // Bonus for corroborating signals
            }
        }

        return array_values($consolidated);
    }

    /**
     * Extract domain from email.
     */
    protected function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? null;
    }

    /**
     * Check if domain is generic (gmail, outlook, etc).
     */
    protected function isGenericDomain(string $domain): bool
    {
        $generic = [
            'gmail.com', 'yahoo.com', 'hotmail.com', 'outlook.com',
            'icloud.com', 'aol.com', 'protonmail.com', 'mail.com',
        ];

        return in_array(strtolower($domain), $generic);
    }
}
