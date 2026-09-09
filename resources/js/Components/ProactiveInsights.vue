<script setup lang="ts">
import { ref, onMounted } from 'vue';

interface Insight {
    type: string;
    title: string;
    subtitle: string;
    action: 'upsell' | 'landing' | 'case_study' | 'followup' | 'review';
    action_label: string;
    agent_slug?: string;
    priority: number;
    metadata: Record<string, unknown>;
}

const emit = defineEmits<{
    (e: 'action', insight: Insight): void;
}>();

const insights = ref<Insight[]>([]);
const loading = ref(true);
const error = ref<string | null>(null);

const fetchInsights = async () => {
    loading.value = true;
    error.value = null;
    try {
        const response = await fetch('/api/insights?limit=6');
        const data = await response.json();
        insights.value = data.insights || [];
    } catch (e) {
        console.error('Failed to load insights:', e);
        error.value = 'Failed to load insights';
    } finally {
        loading.value = false;
    }
};

onMounted(() => {
    fetchInsights();
});

const getActionIcon = (action: string) => {
    const icons: Record<string, string> = {
        upsell: 'M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
        landing: 'M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418',
        case_study: 'M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z',
        followup: 'M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75',
        review: 'M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z M15 12a3 3 0 11-6 0 3 3 0 016 0z',
    };
    return icons[action] || icons.review;
};

const getTypeColor = (type: string) => {
    if (type.includes('risk') || type.includes('at_risk')) return 'var(--color-status-red)';
    if (type.includes('hot') || type.includes('opportunity')) return 'var(--color-status-green)';
    if (type.includes('stale')) return 'var(--color-status-yellow)';
    return 'var(--color-accent)';
};

const handleAction = (insight: Insight) => {
    emit('action', insight);
};
</script>

<template>
    <div class="proactive-insights">
        <div class="section-header">
            <svg class="section-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
            </svg>
            <span class="section-title">Proactive Insights</span>
            <button
                v-if="!loading"
                @click="fetchInsights"
                class="refresh-btn"
                title="Refresh insights"
            >
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                </svg>
            </button>
        </div>

        <!-- Loading state -->
        <div v-if="loading" class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div v-for="i in 3" :key="i" class="insight-card skeleton">
                <div class="skeleton-line w-3/4 h-4 mb-2"></div>
                <div class="skeleton-line w-1/2 h-3 mb-4"></div>
                <div class="skeleton-line w-1/3 h-3"></div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-else-if="insights.length === 0" class="empty-state">
            <div class="empty-icon">
                <svg fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z" />
                </svg>
            </div>
            <p class="empty-text">No insights detected</p>
            <p class="empty-subtext">Insights will appear as patterns emerge from your data</p>
        </div>

        <!-- Insights grid -->
        <div v-else class="grid grid-cols-1 gap-4 md:grid-cols-3">
            <div
                v-for="insight in insights"
                :key="`${insight.type}-${insight.title}`"
                class="insight-card"
            >
                <div class="insight-header">
                    <div
                        class="insight-icon"
                        :style="{ background: `${getTypeColor(insight.type)}15` }"
                    >
                        <svg
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                            :style="{ color: getTypeColor(insight.type) }"
                        >
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getActionIcon(insight.action)" />
                        </svg>
                    </div>
                </div>
                <p class="insight-title">{{ insight.title }}</p>
                <p class="insight-subtitle">{{ insight.subtitle }}</p>
                <div class="insight-footer">
                    <button
                        class="insight-action"
                        @click="handleAction(insight)"
                    >
                        {{ insight.action_label }} →
                    </button>
                    <span v-if="insight.agent_slug" class="agent-badge">
                        {{ insight.agent_slug }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.proactive-insights {
    margin-top: 1.5rem;
}

.section-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.section-icon {
    width: 20px;
    height: 20px;
    color: var(--color-text-tertiary);
}

.section-title {
    font-size: 0.875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.refresh-btn {
    margin-left: auto;
    padding: 0.375rem;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    cursor: pointer;
    transition: all 0.15s ease;
}

.refresh-btn:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.refresh-btn svg {
    width: 16px;
    height: 16px;
}

.insight-card {
    padding: 1.25rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    transition: all 0.15s ease;
}

.insight-card:hover {
    border-color: var(--color-border-default);
    transform: translateY(-1px);
}

.insight-card.skeleton {
    pointer-events: none;
}

.skeleton-line {
    background: var(--color-bg-tertiary);
    border-radius: 4px;
    animation: pulse 1.5s ease-in-out infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 0.5; }
    50% { opacity: 1; }
}

.insight-header {
    margin-bottom: 0.75rem;
}

.insight-icon {
    width: 32px;
    height: 32px;
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
}

.insight-icon svg {
    width: 18px;
    height: 18px;
}

.insight-title {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.insight-subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin-bottom: 0.75rem;
}

.insight-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.5rem;
}

.insight-action {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-accent);
    background: none;
    border: none;
    padding: 0;
    cursor: pointer;
    transition: opacity 0.15s ease;
}

.insight-action:hover {
    opacity: 0.8;
}

.agent-badge {
    font-size: 0.6875rem;
    font-family: var(--font-mono);
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
}

.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
    background: var(--color-bg-secondary);
    border: 1px dashed var(--color-border-subtle);
    border-radius: 12px;
}

.empty-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 1rem;
    border-radius: 50%;
    background: var(--color-bg-tertiary);
    display: flex;
    align-items: center;
    justify-content: center;
}

.empty-icon svg {
    width: 24px;
    height: 24px;
    color: var(--color-text-tertiary);
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
</style>
