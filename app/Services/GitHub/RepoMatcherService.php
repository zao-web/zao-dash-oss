<?php

namespace App\Services\GitHub;

use App\Models\Client;
use App\Models\GitHubRepo;
use App\Models\SlackMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class RepoMatcherService
{
    public function findMatchingRepos(SlackMessage $message, ?string $userEmail = null): Collection
    {
        $client = $message->client;
        if (! $client) {
            return collect();
        }

        $repos = $this->getClientRepos($client);
        if ($repos->isEmpty()) {
            return collect();
        }

        if ($repos->count() === 1) {
            return $repos;
        }

        return $this->rankRepos($repos, $message, $userEmail);
    }

    protected function getClientRepos(Client $client): Collection
    {
        $directRepos = GitHubRepo::where('client_id', $client->id)
            ->where('is_archived', false)
            ->get();

        $projectRepos = GitHubRepo::whereHas('project', fn ($q) => $q->where('client_id', $client->id))
            ->where('is_archived', false)
            ->get();

        return $directRepos->merge($projectRepos)->unique('id');
    }

    protected function rankRepos(Collection $repos, SlackMessage $message, ?string $userEmail): Collection
    {
        $scores = [];
        $content = strtolower($message->content.' '.($message->action_item_extracted ?? ''));
        $emailDomain = $userEmail ? $this->extractDomain($userEmail) : null;

        foreach ($repos as $repo) {
            $score = 0;
            $repoName = strtolower($repo->name);

            if ($emailDomain) {
                $domainBase = $this->extractDomainBase($emailDomain);
                if (Str::contains($repoName, $domainBase)) {
                    $score += 100;
                }
            }

            $repoWords = preg_split('/[-_]/', $repoName);
            foreach ($repoWords as $word) {
                if (strlen($word) > 2 && Str::contains($content, $word)) {
                    $score += 20;
                }
            }

            if ($repo->pushed_at) {
                $daysSinceUpdate = now()->diffInDays($repo->pushed_at);
                if ($daysSinceUpdate < 30) {
                    $score += 10;
                } elseif ($daysSinceUpdate < 90) {
                    $score += 5;
                }
            }

            if ($repo->monitoring_enabled) {
                $score += 5;
            }

            $scores[$repo->id] = $score;
        }

        return $repos->sortByDesc(fn ($repo) => $scores[$repo->id] ?? 0)->values();
    }

    protected function extractDomain(string $email): ?string
    {
        $parts = explode('@', $email);

        return $parts[1] ?? null;
    }

    protected function extractDomainBase(string $domain): string
    {
        $parts = explode('.', $domain);
        if (count($parts) >= 2) {
            return $parts[count($parts) - 2];
        }

        return $parts[0];
    }

    public function getBestMatch(SlackMessage $message, ?string $userEmail = null): ?GitHubRepo
    {
        $repos = $this->findMatchingRepos($message, $userEmail);

        return $repos->first();
    }
}
