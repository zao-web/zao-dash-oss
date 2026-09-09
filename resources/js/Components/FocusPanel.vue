<script setup lang="ts">
import { ref, computed, onMounted } from 'vue';
import { Link } from '@inertiajs/vue3';

interface FocusItem {
    type: string;
    priority: 'critical' | 'high' | 'medium';
    title: string;
    description: string;
    agent: string | null;
    action_url: string;
    action_label: string;
    created_at: string;
    metadata: Record<string, unknown>;
}

interface Briefing {
    greeting: string;
    summary: {
        total_items: number;
        critical: number;
        high: number;
        medium: number;
    };
    recommendations: string[];
}

const loading = ref(true);
const loadingMore = ref(false);
const collapsed = ref(true);
const items = ref<FocusItem[]>([]);
const briefing = ref<Briefing | null>(null);
const totalItems = ref(0);
const hasMore = ref(false);
const currentOffset = ref(0);
const expandedHealthItem = ref<number | null>(null);

const fetchFocusData = async (offset = 0, append = false) => {
    try {
        if (append) {
            loadingMore.value = true;
        }
        const response = await fetch(`/api/capabilities/focus?offset=${offset}&limit=10`);
        const data = await response.json();
        briefing.value = data.briefing;
        totalItems.value = data.total;
        hasMore.value = data.has_more;
        currentOffset.value = offset + (data.items?.length || 0);

        if (append) {
            items.value = [...items.value, ...(data.items || [])];
        } else {
            items.value = data.items || [];
        }
    } catch (error) {
        console.error('Failed to load focus data:', error);
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
};

const loadMore = () => {
    if (!loadingMore.value && hasMore.value) {
        fetchFocusData(currentOffset.value, true);
    }
};

const dismissItem = async (item: FocusItem, event: Event) => {
    event.preventDefault();
    event.stopPropagation();

    const id = item.metadata?.client_id || item.metadata?.task_id || item.metadata?.pr_id || item.metadata?.notification_id || item.metadata?.lead_id;

    try {
        await fetch('/api/capabilities/focus/dismiss', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
            },
            body: JSON.stringify({ type: item.type, id }),
        });

        items.value = items.value.filter(i => i !== item);
        if (briefing.value) {
            briefing.value.summary.total_items--;
        }
    } catch (error) {
        console.error('Failed to dismiss item:', error);
    }
};

const toggleHealthDetails = (clientId: number, event: Event) => {
    event.preventDefault();
    event.stopPropagation();
    expandedHealthItem.value = expandedHealthItem.value === clientId ? null : clientId;
};

const canDismiss = (type: string) => {
    return ['client_health', 'lead_followup', 'pr_review', 'slack_action_item'].includes(type);
};

onMounted(() => {
    fetchFocusData();
});

const priorityClass = (priority: string) => {
    switch (priority) {
        case 'critical': return 'priority-critical';
        case 'high': return 'priority-high';
        case 'medium': return 'priority-medium';
        default: return 'priority-low';
    }
};

const typeIcon = (type: string) => {
    const icons: Record<string, string> = {
        approval: 'M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        content_approval: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        pr_review: 'M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5',
        overdue_invoice: 'M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z',
        lead_followup: 'M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z',
        client_health: 'M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z',
        task_decision: 'M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25z',
        slack_action_item: 'M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z',
    };
    return icons[type] || 'M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z';
};

const isExternalUrl = (url: string | null) => url?.startsWith('http') ?? false;

const hasItems = computed(() => items.value.length > 0);
const criticalCount = computed(() => briefing.value?.summary.critical || 0);
const highCount = computed(() => briefing.value?.summary.high || 0);
</script>

<template>
    <div class="focus-panel" :class="{ collapsed }">
        <!-- Header -->
        <div class="focus-header" @click="collapsed = !collapsed">
            <div class="focus-title-row">
                <div class="focus-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                    </svg>
                </div>
                <div class="focus-title-content">
                    <h2 class="focus-title">
                        <template v-if="loading">Loading focus items...</template>
                        <template v-else-if="!hasItems">You're all caught up!</template>
                        <template v-else>{{ briefing?.summary.total_items }} items need your attention</template>
                    </h2>
                    <p class="focus-subtitle" v-if="!loading && hasItems">
                        <span v-if="criticalCount" class="priority-tag critical">{{ criticalCount }} critical</span>
                        <span v-if="highCount" class="priority-tag high">{{ highCount }} high</span>
                    </p>
                </div>
            </div>
            <button class="collapse-btn" :aria-label="collapsed ? 'Expand' : 'Collapse'">
                <svg :class="{ rotated: !collapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
            </button>
        </div>

        <!-- Content -->
        <div class="focus-content" v-show="!collapsed">
            <!-- Loading state -->
            <div v-if="loading" class="focus-loading">
                <div class="skeleton-item" v-for="i in 3" :key="i">
                    <div class="skeleton-icon"></div>
                    <div class="skeleton-text">
                        <div class="skeleton-line w-3/4"></div>
                        <div class="skeleton-line w-1/2"></div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else-if="!hasItems" class="focus-empty">
                <div class="empty-icon">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                    </svg>
                </div>
                <p class="empty-text">No items require your attention right now.</p>
                <p class="empty-subtext">Great time to focus on strategic work!</p>
            </div>

            <!-- Items list -->
            <div v-else class="focus-items">
                <template v-for="item in items" :key="`${item.type}-${item.metadata?.approval_id || item.metadata?.client_id || item.title}`">
                    <!-- Slack action items with dual actions -->
                    <div
                        v-if="item.type === 'slack_action_item' && item.metadata?.create_project_url"
                        class="focus-item focus-item-multi"
                        :class="priorityClass(item.priority)"
                    >
                        <div class="item-priority-indicator" :class="priorityClass(item.priority)"></div>

                        <div class="item-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="typeIcon(item.type)" />
                            </svg>
                        </div>

                        <div class="item-content">
                            <div class="item-title">{{ item.title }}</div>
                            <div class="item-description">
                                {{ item.description }}
                            </div>
                        </div>

                        <div class="item-actions-multi">
                            <button
                                class="dismiss-btn"
                                @click="dismissItem(item, $event)"
                                title="Dismiss"
                            >
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <a
                                v-if="item.metadata?.permalink"
                                :href="item.metadata.permalink as string"
                                target="_blank"
                                class="item-action item-action-secondary"
                                @click.stop
                            >
                                <span class="action-label">Slack</span>
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                </svg>
                            </a>
                            <Link
                                :href="item.metadata.create_project_url as string"
                                class="item-action item-action-primary"
                                @click.stop
                            >
                                <span class="action-label">Create Project</span>
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                            </Link>
                        </div>
                    </div>

                    <!-- Client health items with expandable details -->
                    <div
                        v-else-if="item.type === 'client_health'"
                        class="focus-item focus-item-expandable"
                        :class="priorityClass(item.priority)"
                    >
                        <div class="item-priority-indicator" :class="priorityClass(item.priority)"></div>

                        <div class="item-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="typeIcon(item.type)" />
                            </svg>
                        </div>

                        <div class="item-content">
                            <div class="item-title">{{ item.title }}</div>
                            <div class="item-description">{{ item.description }}</div>

                            <!-- Expanded details -->
                            <div v-if="expandedHealthItem === item.metadata?.client_id" class="health-details">
                                <div class="health-factors">
                                    <strong>Issues:</strong>
                                    <ul>
                                        <li v-for="factor in (item.metadata?.factors as string[])" :key="factor">{{ factor }}</li>
                                    </ul>
                                </div>
                                <div class="health-suggestions">
                                    <strong>Suggestions:</strong>
                                    <ul>
                                        <li v-for="suggestion in (item.metadata?.suggestions as string[])" :key="suggestion">{{ suggestion }}</li>
                                    </ul>
                                </div>
                            </div>
                        </div>

                        <div class="item-actions-multi">
                            <button
                                class="dismiss-btn"
                                @click="dismissItem(item, $event)"
                                title="Dismiss for 7 days"
                            >
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <button
                                class="item-action item-action-secondary"
                                @click="toggleHealthDetails(item.metadata?.client_id as number, $event)"
                            >
                                <span class="action-label">{{ expandedHealthItem === item.metadata?.client_id ? 'Less' : 'Details' }}</span>
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" :class="{ rotated: expandedHealthItem === item.metadata?.client_id }">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                </svg>
                            </button>
                            <Link
                                :href="item.action_url"
                                class="item-action item-action-primary"
                                @click.stop
                            >
                                <span class="action-label">{{ item.action_label }}</span>
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </Link>
                        </div>
                    </div>

                    <!-- Standard single-action items -->
                    <div
                        v-else
                        class="focus-item"
                        :class="priorityClass(item.priority)"
                    >
                        <div class="item-priority-indicator" :class="priorityClass(item.priority)"></div>

                        <div class="item-icon">
                            <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="typeIcon(item.type)" />
                            </svg>
                        </div>

                        <div class="item-content">
                            <div class="item-title">{{ item.title }}</div>
                            <div class="item-description">
                                {{ item.description }}
                                <span v-if="item.agent" class="item-agent">via {{ item.agent }}</span>
                            </div>
                        </div>

                        <div class="item-actions-multi">
                            <button
                                v-if="canDismiss(item.type)"
                                class="dismiss-btn"
                                @click="dismissItem(item, $event)"
                                title="Dismiss"
                            >
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                            <component
                                :is="isExternalUrl(item.action_url) ? 'a' : Link"
                                :href="item.action_url"
                                :target="isExternalUrl(item.action_url) ? '_blank' : undefined"
                                class="item-action item-action-primary"
                                @click.stop
                            >
                                <span class="action-label">{{ item.action_label }}</span>
                                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </component>
                        </div>
                    </div>
                </template>

                <!-- Load More button -->
                <div v-if="hasMore" class="load-more-container">
                    <button
                        class="load-more-btn"
                        @click="loadMore"
                        :disabled="loadingMore"
                    >
                        <span v-if="loadingMore">Loading...</span>
                        <span v-else>Load More ({{ totalItems - items.length }} remaining)</span>
                    </button>
                </div>
            </div>

            <!-- Recommendations -->
            <div v-if="!loading && briefing?.recommendations?.length" class="focus-recommendations">
                <div class="recommendation" v-for="(rec, i) in briefing.recommendations" :key="i">
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18" />
                    </svg>
                    <span>{{ rec }}</span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.focus-panel {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 10px;
    margin-bottom: 1rem;
    overflow: hidden;
}

@media (min-width: 768px) {
    .focus-panel {
        border-radius: 12px;
        margin-bottom: 1.5rem;
    }
}

.focus-panel.collapsed {
    margin-bottom: 0.75rem;
}

@media (min-width: 768px) {
    .focus-panel.collapsed {
        margin-bottom: 1rem;
    }
}

.focus-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.75rem 1rem;
    cursor: pointer;
    transition: background 0.15s ease;
}

@media (min-width: 768px) {
    .focus-header {
        padding: 1rem 1.25rem;
    }
}

.focus-header:hover {
    background: var(--color-bg-tertiary);
}

.focus-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

@media (min-width: 768px) {
    .focus-title-row {
        gap: 0.75rem;
    }
}

.focus-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.15), rgba(139, 92, 246, 0.15));
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

@media (min-width: 768px) {
    .focus-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
    }
}

.focus-icon svg {
    width: 16px;
    height: 16px;
    color: var(--color-accent);
}

@media (min-width: 768px) {
    .focus-icon svg {
        width: 20px;
        height: 20px;
    }
}

.focus-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

@media (min-width: 768px) {
    .focus-title {
        font-size: 1rem;
    }
}

.focus-subtitle {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-top: 0.25rem;
}

.priority-tag {
    font-size: 0.75rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
}

.priority-tag.critical {
    background: rgba(239, 68, 68, 0.15);
    color: #ef4444;
}

.priority-tag.high {
    background: rgba(249, 115, 22, 0.15);
    color: #f97316;
}

.collapse-btn {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.collapse-btn:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.collapse-btn svg {
    width: 16px;
    height: 16px;
    transition: transform 0.2s ease;
}

.collapse-btn svg.rotated {
    transform: rotate(180deg);
}

.focus-content {
    border-top: 1px solid var(--color-border-subtle);
}

/* Loading state */
.focus-loading {
    padding: 1rem 1.25rem;
}

.skeleton-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.75rem 0;
}

.skeleton-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--color-bg-tertiary);
    animation: pulse 1.5s ease-in-out infinite;
}

.skeleton-text {
    flex: 1;
}

.skeleton-line {
    height: 12px;
    border-radius: 4px;
    background: var(--color-bg-tertiary);
    animation: pulse 1.5s ease-in-out infinite;
}

.skeleton-line + .skeleton-line {
    margin-top: 0.5rem;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

/* Empty state */
.focus-empty {
    padding: 2rem 1.25rem;
    text-align: center;
}

.empty-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    background: rgba(34, 197, 94, 0.12);
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-icon svg {
    width: 24px;
    height: 24px;
    color: #22c55e;
}

.empty-text {
    font-size: 0.9375rem;
    color: var(--color-text-primary);
    margin: 0;
}

.empty-subtext {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}

/* Items list */
.focus-items {
    max-height: 400px;
    overflow-y: auto;
}

.focus-item {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1.25rem;
    text-decoration: none;
    color: inherit;
    border-bottom: 1px solid var(--color-border-subtle);
    transition: background 0.15s ease;
    position: relative;
}

.focus-item:last-child {
    border-bottom: none;
}

.focus-item:hover {
    background: var(--color-bg-tertiary);
}

.item-priority-indicator {
    position: absolute;
    left: 0;
    top: 0;
    bottom: 0;
    width: 3px;
}

.item-priority-indicator.priority-critical {
    background: #ef4444;
}

.item-priority-indicator.priority-high {
    background: #f97316;
}

.item-priority-indicator.priority-medium {
    background: #eab308;
}

.item-icon {
    width: 36px;
    height: 36px;
    border-radius: 8px;
    background: var(--color-bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}

.item-icon svg {
    width: 18px;
    height: 18px;
    color: var(--color-text-secondary);
}

.focus-item.priority-critical .item-icon {
    background: rgba(239, 68, 68, 0.12);
}

.focus-item.priority-critical .item-icon svg {
    color: #ef4444;
}

.focus-item.priority-high .item-icon {
    background: rgba(249, 115, 22, 0.12);
}

.focus-item.priority-high .item-icon svg {
    color: #f97316;
}

.item-content {
    flex: 1;
    min-width: 0;
}

.item-title {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.item-description {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin-top: 0.125rem;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.item-agent {
    color: var(--color-accent);
    font-weight: 500;
}

.item-action {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    padding: 0.375rem 0.75rem;
    border-radius: 6px;
    background: var(--color-bg-tertiary);
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.focus-item:hover .item-action {
    background: var(--color-accent);
    color: white;
}

.item-action svg {
    width: 12px;
    height: 12px;
}

/* Multi-action items (e.g., Slack action items with Create Project button) */
.focus-item-multi {
    cursor: default;
}

.item-actions-multi {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-shrink: 0;
}

.item-action-secondary {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    text-decoration: none;
}

.item-action-secondary:hover {
    background: var(--color-bg-quaternary, var(--color-bg-tertiary));
    color: var(--color-text-primary);
}

.item-action-primary {
    background: var(--color-accent);
    color: white;
    text-decoration: none;
}

.item-action-primary:hover {
    background: var(--color-accent-hover, var(--color-accent));
    filter: brightness(1.1);
}

/* Recommendations */
.focus-recommendations {
    padding: 0.75rem 1.25rem;
    background: var(--color-bg-tertiary);
    border-top: 1px solid var(--color-border-subtle);
}

.recommendation {
    display: flex;
    align-items: flex-start;
    gap: 0.5rem;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    padding: 0.25rem 0;
}

.recommendation svg {
    width: 14px;
    height: 14px;
    color: var(--color-accent);
    flex-shrink: 0;
    margin-top: 2px;
}

/* Dismiss button */
.dismiss-btn {
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
    flex-shrink: 0;
}

.dismiss-btn:hover {
    background: rgba(239, 68, 68, 0.1);
    color: #ef4444;
}

.dismiss-btn svg {
    width: 14px;
    height: 14px;
}

/* Health details expandable section */
.focus-item-expandable {
    flex-wrap: wrap;
}

.health-details {
    width: 100%;
    margin-top: 0.75rem;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 6px;
    font-size: 0.8125rem;
}

.health-factors,
.health-suggestions {
    margin-bottom: 0.5rem;
}

.health-factors:last-child,
.health-suggestions:last-child {
    margin-bottom: 0;
}

.health-details strong {
    color: var(--color-text-secondary);
    font-weight: 600;
}

.health-details ul {
    margin: 0.25rem 0 0 1rem;
    padding: 0;
}

.health-details li {
    color: var(--color-text-tertiary);
    margin-bottom: 0.125rem;
}

.health-suggestions li {
    color: var(--color-accent);
}

.item-action-secondary svg.rotated {
    transform: rotate(180deg);
}

/* Load More button */
.load-more-container {
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--color-border-subtle);
}

.load-more-btn {
    width: 100%;
    padding: 0.625rem 1rem;
    border-radius: 6px;
    border: 1px solid var(--color-border-subtle);
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    font-size: 0.8125rem;
    font-weight: 500;
    cursor: pointer;
    transition: all 0.15s ease;
}

.load-more-btn:hover:not(:disabled) {
    background: var(--color-bg-quaternary, var(--color-bg-secondary));
    border-color: var(--color-accent);
    color: var(--color-accent);
}

.load-more-btn:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Scrollbar */
.focus-items::-webkit-scrollbar {
    width: 6px;
}

.focus-items::-webkit-scrollbar-track {
    background: transparent;
}

.focus-items::-webkit-scrollbar-thumb {
    background: var(--color-border-default);
    border-radius: 3px;
}

.focus-items::-webkit-scrollbar-thumb:hover {
    background: var(--color-border-subtle);
}
</style>
