<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import ChatInterface from '@/Components/SiteBuilder/ChatInterface.vue';
import { useSiteBuilderChat } from '@/composables/useSiteBuilderChat';

interface User {
    id: number;
    name: string;
}

interface Project {
    id: number;
    project_name: string;
    domain: string;
    brief: string;
    company_type: string;
    status: string;
    environment: string;
    target_hosting: string;
    staging_url?: string;
    production_url?: string;
    progress_data?: Record<string, any>;
    last_error?: string;
    estimated_completion?: string;
    created_at: string;
    updated_at: string;
    user: User;
}

const props = defineProps<{
    project: Project;
    progressPercentage: number;
    liveUrl?: string;
}>();

const {
    messages,
    isStreaming,
    currentStreamingContent,
    error,
    sendMessage,
    retryMessage,
    phases,
    overallProgress,
    isConnected,
    isComplete,
    hasFailed,
    buildState,
} = useSiteBuilderChat(props.project.id);

const sidebarCollapsed = ref(false);

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        created: 'badge-gray',
        research: 'badge-blue',
        wordpress_setup: 'badge-blue',
        content_generation: 'badge-yellow',
        content_sync: 'badge-yellow',
        qa: 'badge-purple',
        complete: 'badge-green',
        failed: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

const getEnvironmentBadge = (env: string) => {
    return env === 'production' ? 'badge-green' : 'badge-yellow';
};

const formatDate = (dateString: string) => {
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
};

const hostingLabel = computed(() => {
    const labels: Record<string, string> = {
        wordpress_com: 'WordPress.com',
        self_hosted: 'Self-Hosted',
        existing_site: 'Existing Site',
    };
    return labels[props.project.target_hosting] || props.project.target_hosting;
});

const currentLiveUrl = computed(() => {
    return buildState.value.stagingUrl || buildState.value.productionUrl || props.liveUrl;
});

const handleSendMessage = (message: string) => {
    sendMessage(message);
};

const handleRetryMessage = (messageId: string) => {
    retryMessage(messageId);
};

onMounted(() => {
    if (props.project.status !== 'created') {
        const statusToPhase: Record<string, string> = {
            research: 'research',
            wordpress_setup: 'wordpress_setup',
            content_generation: 'content_generation',
            content_sync: 'content_sync',
            qa: 'qa',
            complete: 'qa',
        };
        const currentPhaseId = statusToPhase[props.project.status];
        if (currentPhaseId) {
            phases.value.forEach((phase, index) => {
                const phaseOrder = ['research', 'wordpress_setup', 'content_generation', 'content_sync', 'qa'];
                const currentIndex = phaseOrder.indexOf(currentPhaseId);
                const phaseIndex = phaseOrder.indexOf(phase.id);
                
                if (phaseIndex < currentIndex) {
                    phase.status = 'completed';
                    phase.progress = 100;
                } else if (phaseIndex === currentIndex) {
                    phase.status = props.project.status === 'complete' ? 'completed' : 'active';
                    phase.progress = props.project.status === 'complete' ? 100 : props.progressPercentage;
                }
            });
        }
    }
});
</script>

<template>
    <AppLayout :title="project.project_name">
        <div class="site-builder-show">
            <!-- Header -->
            <header class="page-header">
                <div class="header-left">
                    <Link href="/site-builder" class="back-button">
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M15 18l-6-6 6-6"/>
                        </svg>
                    </Link>
                    <div class="header-info">
                        <div class="header-title-row">
                            <h1 class="page-title">{{ project.project_name }}</h1>
                            <span :class="['badge', getStatusBadge(buildState.status || project.status)]">
                                {{ (buildState.status || project.status).replace('_', ' ') }}
                            </span>
                            <span :class="['badge', getEnvironmentBadge(project.environment)]">
                                {{ project.environment }}
                            </span>
                        </div>
                        <p class="page-subtitle">{{ project.domain }}</p>
                    </div>
                </div>
                <div class="header-actions">
                    <a
                        v-if="currentLiveUrl"
                        :href="currentLiveUrl"
                        target="_blank"
                        class="btn btn-secondary"
                    >
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/>
                            <polyline points="15 3 21 3 21 9"/>
                            <line x1="10" y1="14" x2="21" y2="3"/>
                        </svg>
                        Preview Site
                    </a>
                    <button class="btn btn-ghost" @click="sidebarCollapsed = !sidebarCollapsed">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <rect x="3" y="3" width="18" height="18" rx="2" ry="2"/>
                            <line x1="9" y1="3" x2="9" y2="21"/>
                        </svg>
                    </button>
                </div>
            </header>

            <!-- Main Content -->
            <div class="main-content" :class="{ 'sidebar-collapsed': sidebarCollapsed }">
                <!-- Chat Panel (Primary) -->
                <div class="chat-panel">
                    <ChatInterface
                        :messages="messages"
                        :is-streaming="isStreaming"
                        :current-streaming-content="currentStreamingContent"
                        :phases="phases"
                        :overall-progress="overallProgress"
                        :is-connected="isConnected"
                        :error="error"
                        @send="handleSendMessage"
                        @retry="handleRetryMessage"
                    />
                </div>

                <!-- Sidebar (Details) -->
                <aside class="details-sidebar" :class="{ collapsed: sidebarCollapsed }">
                    <!-- Quick Stats -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Quick Stats</h3>
                        <div class="stats-grid">
                            <div class="stat-item">
                                <span class="stat-value">{{ overallProgress }}%</span>
                                <span class="stat-label">Progress</span>
                            </div>
                            <div class="stat-item">
                                <span class="stat-value">{{ phases.filter(p => p.status === 'completed').length }}/{{ phases.length }}</span>
                                <span class="stat-label">Phases</span>
                            </div>
                        </div>
                    </div>

                    <!-- Project Details -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Project Details</h3>
                        <div class="detail-list">
                            <div class="detail-item">
                                <span class="detail-label">Domain</span>
                                <span class="detail-value">{{ project.domain }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Type</span>
                                <span class="detail-value capitalize">{{ project.company_type }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Hosting</span>
                                <span class="detail-value">{{ hostingLabel }}</span>
                            </div>
                            <div class="detail-item">
                                <span class="detail-label">Created</span>
                                <span class="detail-value">{{ formatDate(project.created_at) }}</span>
                            </div>
                            <div v-if="project.estimated_completion" class="detail-item">
                                <span class="detail-label">Est. Completion</span>
                                <span class="detail-value">{{ formatDate(project.estimated_completion) }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Brief -->
                    <div class="sidebar-section">
                        <h3 class="sidebar-title">Brief</h3>
                        <p class="brief-text">{{ project.brief }}</p>
                    </div>

                    <!-- Live URL -->
                    <div v-if="currentLiveUrl" class="sidebar-section">
                        <h3 class="sidebar-title">Live Site</h3>
                        <a :href="currentLiveUrl" target="_blank" class="live-url-link">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="2" y1="12" x2="22" y2="12"/>
                                <path d="M12 2a15.3 15.3 0 014 10 15.3 15.3 0 01-4 10 15.3 15.3 0 01-4-10 15.3 15.3 0 014-10z"/>
                            </svg>
                            {{ currentLiveUrl }}
                        </a>
                    </div>

                    <!-- Error State -->
                    <div v-if="project.last_error || hasFailed" class="sidebar-section error-section">
                        <h3 class="sidebar-title error-title">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm1 15h-2v-2h2v2zm0-4h-2V7h2v6z"/>
                            </svg>
                            Error
                        </h3>
                        <p class="error-text">{{ project.last_error || 'Build failed. Check the chat for details.' }}</p>
                    </div>
                </aside>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.site-builder-show {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 64px);
    overflow: hidden;
}

/* Header */
.page-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 1rem 1.5rem;
    background: var(--color-bg-secondary);
    border-bottom: 1px solid var(--color-border-subtle);
    flex-shrink: 0;
}

.header-left {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.back-button {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 36px;
    height: 36px;
    border-radius: 8px;
    color: var(--color-text-secondary);
    transition: all 0.15s ease;
}

.back-button:hover {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.header-info {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.header-title-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.page-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.page-subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    margin: 0;
}

.header-actions {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

/* Main Content */
.main-content {
    display: grid;
    grid-template-columns: 1fr 320px;
    flex: 1;
    overflow: hidden;
    transition: grid-template-columns 0.3s ease;
}

.main-content.sidebar-collapsed {
    grid-template-columns: 1fr 0;
}

/* Chat Panel */
.chat-panel {
    overflow: hidden;
    border-right: 1px solid var(--color-border-subtle);
}

/* Sidebar */
.details-sidebar {
    display: flex;
    flex-direction: column;
    gap: 0;
    background: var(--color-bg-secondary);
    overflow-y: auto;
    overflow-x: hidden;
    transition: all 0.3s ease;
}

.details-sidebar.collapsed {
    width: 0;
    opacity: 0;
    pointer-events: none;
}

.sidebar-section {
    padding: 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.sidebar-section:last-child {
    border-bottom: none;
}

.sidebar-title {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin: 0 0 0.875rem 0;
}

/* Stats Grid */
.stats-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.75rem;
}

.stat-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
}

.stat-value {
    font-size: 1.25rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.03em;
}

/* Detail List */
.detail-list {
    display: flex;
    flex-direction: column;
    gap: 0.625rem;
}

.detail-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.detail-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.detail-value {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.capitalize {
    text-transform: capitalize;
}

/* Brief */
.brief-text {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin: 0;
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

/* Live URL */
.live-url-link {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    font-size: 0.75rem;
    color: var(--color-accent);
    text-decoration: none;
    transition: all 0.15s ease;
    word-break: break-all;
}

.live-url-link:hover {
    background: var(--color-bg-elevated);
}

/* Error Section */
.error-section {
    background: rgba(239, 68, 68, 0.05);
}

.error-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    color: var(--color-status-red);
}

.error-text {
    font-size: 0.8125rem;
    color: var(--color-status-red);
    line-height: 1.5;
    margin: 0;
}

/* Responsive */
@media (max-width: 1024px) {
    .main-content {
        grid-template-columns: 1fr;
    }
    
    .details-sidebar {
        position: fixed;
        right: 0;
        top: 64px;
        bottom: 0;
        width: 320px;
        z-index: 50;
        transform: translateX(100%);
        box-shadow: -4px 0 20px rgba(0, 0, 0, 0.2);
    }
    
    .details-sidebar:not(.collapsed) {
        transform: translateX(0);
    }
    
    .chat-panel {
        border-right: none;
    }
}

@media (max-width: 640px) {
    .page-header {
        padding: 0.75rem 1rem;
    }
    
    .header-title-row {
        flex-wrap: wrap;
        gap: 0.5rem;
    }
    
    .page-title {
        font-size: 1rem;
    }
    
    .details-sidebar {
        width: 100%;
    }
}

/* Scrollbar */
.details-sidebar::-webkit-scrollbar {
    width: 4px;
}

.details-sidebar::-webkit-scrollbar-track {
    background: transparent;
}

.details-sidebar::-webkit-scrollbar-thumb {
    background: var(--color-border-strong);
    border-radius: 2px;
}
</style>
