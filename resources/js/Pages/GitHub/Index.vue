<script setup lang="ts">
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/Button.vue';

interface GitHubRepo {
    id: number;
    repo_id: number;
    full_name: string;
    name: string;
    owner: string;
    is_private: boolean;
    default_branch: string;
    monitoring_enabled: boolean;
    open_issues_count: number;
    open_prs_count: number;
    last_synced_at: string | null;
}

interface GitHubIssue {
    id: number;
    issue_number: number;
    title: string;
    state: string;
    labels: string[];
    repo: { full_name: string };
    author: string;
    created_at: string;
    html_url: string;
}

interface GitHubPR {
    id: number;
    pr_number: number;
    title: string;
    state: string;
    repo: { full_name: string };
    author: string;
    created_at: string;
    html_url: string;
    is_draft: boolean;
    mergeable: boolean | null;
}

interface Installation {
    id: number;
    account_login: string;
    account_type: string;
    repos_count: number;
}

const props = defineProps<{
    installations: Installation[];
    repos: GitHubRepo[];
    issues: GitHubIssue[];
    pullRequests: GitHubPR[];
    stats: {
        total_repos: number;
        open_issues: number;
        open_prs: number;
    };
}>();

const activeTab = ref<'repos' | 'issues' | 'prs'>('repos');
const syncing = ref(false);

const syncAll = async () => {
    syncing.value = true;
    try {
        await fetch('/api/integrations/github/sync', {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
        });
        router.reload();
    } finally {
        syncing.value = false;
    }
};

const syncRepoIssues = async (repoId: number) => {
    await fetch(`/api/integrations/github/repos/${repoId}/sync-issues`, {
        method: 'POST',
        headers: {
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
        },
    });
    router.reload();
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};
</script>

<template>
    <AppLayout title="GitHub">
        <div class="space-y-6">
            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold" style="color: var(--color-text-primary)">GitHub</h1>
                    <p class="text-sm mt-1" style="color: var(--color-text-tertiary)">
                        {{ stats.total_repos }} repos · {{ stats.open_issues }} open issues · {{ stats.open_prs }} open PRs
                    </p>
                </div>
                <Button @click="syncAll" :loading="syncing" variant="primary">
                    Sync All
                </Button>
            </div>

            <!-- Installations Summary -->
            <div v-if="installations.length" class="flex gap-3 flex-wrap">
                <div v-for="inst in installations" :key="inst.id"
                     class="px-3 py-2 rounded-lg text-sm"
                     style="background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle)">
                    <span class="font-medium" style="color: var(--color-text-primary)">{{ inst.account_login }}</span>
                    <span style="color: var(--color-text-tertiary)"> · {{ inst.repos_count }} repos</span>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b" style="border-color: var(--color-border-subtle)">
                <nav class="flex gap-6">
                    <button
                        @click="activeTab = 'repos'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'repos' ? 'border-current' : 'border-transparent'"
                        :style="{ color: activeTab === 'repos' ? 'var(--color-accent)' : 'var(--color-text-tertiary)' }"
                    >
                        Repositories ({{ repos.length }})
                    </button>
                    <button
                        @click="activeTab = 'issues'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'issues' ? 'border-current' : 'border-transparent'"
                        :style="{ color: activeTab === 'issues' ? 'var(--color-accent)' : 'var(--color-text-tertiary)' }"
                    >
                        Issues ({{ issues.length }})
                    </button>
                    <button
                        @click="activeTab = 'prs'"
                        class="pb-3 text-sm font-medium border-b-2 transition-colors"
                        :class="activeTab === 'prs' ? 'border-current' : 'border-transparent'"
                        :style="{ color: activeTab === 'prs' ? 'var(--color-accent)' : 'var(--color-text-tertiary)' }"
                    >
                        Pull Requests ({{ pullRequests.length }})
                    </button>
                </nav>
            </div>

            <!-- Repos Tab -->
            <div v-if="activeTab === 'repos'" class="space-y-3">
                <div v-for="repo in repos" :key="repo.id"
                     class="p-4 rounded-lg"
                     style="background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle)">
                    <div class="flex items-center justify-between">
                        <div>
                            <a :href="`https://github.com/${repo.full_name}`" target="_blank"
                               class="font-medium hover:underline" style="color: var(--color-accent)">
                                {{ repo.full_name }}
                            </a>
                            <div class="flex items-center gap-3 mt-1 text-xs" style="color: var(--color-text-tertiary)">
                                <span v-if="repo.is_private" class="px-1.5 py-0.5 rounded" style="background: var(--color-bg-tertiary)">Private</span>
                                <span>{{ repo.default_branch }}</span>
                                <span v-if="repo.last_synced_at">Synced {{ formatDate(repo.last_synced_at) }}</span>
                            </div>
                        </div>
                        <div class="flex items-center gap-4">
                            <div class="text-sm" style="color: var(--color-text-secondary)">
                                <span v-if="repo.open_issues_count">{{ repo.open_issues_count }} issues</span>
                                <span v-if="repo.open_issues_count && repo.open_prs_count"> · </span>
                                <span v-if="repo.open_prs_count">{{ repo.open_prs_count }} PRs</span>
                            </div>
                            <Button size="sm" variant="ghost" @click="syncRepoIssues(repo.id)">Sync</Button>
                        </div>
                    </div>
                </div>
                <div v-if="!repos.length" class="text-center py-12" style="color: var(--color-text-tertiary)">
                    No repositories synced yet. Click "Sync All" to fetch repos.
                </div>
            </div>

            <!-- Issues Tab -->
            <div v-if="activeTab === 'issues'" class="space-y-3">
                <div v-for="issue in issues" :key="issue.id"
                     class="p-4 rounded-lg"
                     style="background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle)">
                    <div class="flex items-start justify-between">
                        <div>
                            <a :href="issue.html_url" target="_blank"
                               class="font-medium hover:underline" style="color: var(--color-text-primary)">
                                {{ issue.title }}
                            </a>
                            <div class="flex items-center gap-2 mt-1 text-xs" style="color: var(--color-text-tertiary)">
                                <span>{{ issue.repo.full_name }}#{{ issue.issue_number }}</span>
                                <span>by {{ issue.author }}</span>
                                <span>{{ formatDate(issue.created_at) }}</span>
                            </div>
                            <div v-if="issue.labels.length" class="flex gap-1 mt-2">
                                <span v-for="label in issue.labels" :key="label"
                                      class="px-1.5 py-0.5 rounded text-xs"
                                      style="background: var(--color-bg-tertiary); color: var(--color-text-secondary)">
                                    {{ label }}
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                <div v-if="!issues.length" class="text-center py-12" style="color: var(--color-text-tertiary)">
                    No open issues found.
                </div>
            </div>

            <!-- PRs Tab -->
            <div v-if="activeTab === 'prs'" class="space-y-3">
                <div v-for="pr in pullRequests" :key="pr.id"
                     class="p-4 rounded-lg"
                     style="background: var(--color-bg-secondary); border: 1px solid var(--color-border-subtle)">
                    <div class="flex items-start justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <a :href="pr.html_url" target="_blank"
                                   class="font-medium hover:underline" style="color: var(--color-text-primary)">
                                    {{ pr.title }}
                                </a>
                                <span v-if="pr.is_draft" class="px-1.5 py-0.5 rounded text-xs"
                                      style="background: var(--color-bg-tertiary); color: var(--color-text-tertiary)">
                                    Draft
                                </span>
                            </div>
                            <div class="flex items-center gap-2 mt-1 text-xs" style="color: var(--color-text-tertiary)">
                                <span>{{ pr.repo.full_name }}#{{ pr.pr_number }}</span>
                                <span>by {{ pr.author }}</span>
                                <span>{{ formatDate(pr.created_at) }}</span>
                            </div>
                        </div>
                        <div class="flex gap-2">
                            <span v-if="pr.mergeable === true" class="px-2 py-1 rounded text-xs"
                                  style="background: rgba(34, 197, 94, 0.1); color: rgb(34, 197, 94)">
                                Ready to merge
                            </span>
                            <span v-else-if="pr.mergeable === false" class="px-2 py-1 rounded text-xs"
                                  style="background: rgba(239, 68, 68, 0.1); color: rgb(239, 68, 68)">
                                Conflicts
                            </span>
                        </div>
                    </div>
                </div>
                <div v-if="!pullRequests.length" class="text-center py-12" style="color: var(--color-text-tertiary)">
                    No open pull requests found.
                </div>
            </div>
        </div>
    </AppLayout>
</template>
