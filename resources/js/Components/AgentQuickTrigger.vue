<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import FormTextarea from '@/Components/FormTextarea.vue';

interface Agent {
    id: number;
    name: string;
    slug: string;
    description: string;
    status: string;
    requires_approval: boolean;
}

interface Template {
    id: number;
    name: string;
    slug: string;
    description: string;
    category: string;
}

const agents = ref<Agent[]>([]);
const templates = ref<Template[]>([]);
const isLoading = ref(true);

// Trigger modal state
const showTriggerModal = ref(false);
const selectedAgent = ref<Agent | null>(null);
const triggerPrompt = ref('');
const isTriggering = ref(false);
const triggerResult = ref<{ success: boolean; message: string; run_id?: number } | null>(null);

// Dry run state
const isDryRunning = ref(false);
const dryRunResult = ref<{
    valid: boolean;
    execution_preview: {
        model: string;
        estimated_cost_usd: number;
        requires_approval: boolean;
        tools: string[];
    };
    warnings: string[];
} | null>(null);

// Load agents and templates
onMounted(async () => {
    const headers = { 'Accept': 'application/json' };
    try {
        const [agentsRes, templatesRes] = await Promise.all([
            fetch('/api/agents', { headers }),
            fetch('/api/agent-templates', { headers }),
        ]);

        if (agentsRes.ok) {
            agents.value = await agentsRes.json();
        }
        if (templatesRes.ok) {
            templates.value = await templatesRes.json();
        }
    } catch (e) {
        console.error('Failed to load agents/templates:', e);
    } finally {
        isLoading.value = false;
    }
});

const openTriggerModal = (agent: Agent) => {
    selectedAgent.value = agent;
    triggerPrompt.value = '';
    triggerResult.value = null;
    dryRunResult.value = null;
    showTriggerModal.value = true;
};

const performDryRun = async () => {
    if (!selectedAgent.value) return;

    isDryRunning.value = true;
    dryRunResult.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const response = await fetch(`/agents/${selectedAgent.value.id}/dry-run`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            body: JSON.stringify({
                prompt: triggerPrompt.value,
            }),
        });

        if (response.ok) {
            dryRunResult.value = await response.json();
        }
    } catch (e) {
        console.error('Dry run failed:', e);
    } finally {
        isDryRunning.value = false;
    }
};

const triggerAgent = async () => {
    if (!selectedAgent.value) return;

    isTriggering.value = true;
    triggerResult.value = null;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const response = await fetch(`/agents/${selectedAgent.value.id}/trigger`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            body: JSON.stringify({
                prompt: triggerPrompt.value,
            }),
        });

        const data = await response.json();

        if (response.ok) {
            triggerResult.value = {
                success: true,
                message: data.requires_approval
                    ? 'Agent requires approval. Check the Approvals queue.'
                    : 'Agent triggered successfully!',
                run_id: data.run_id,
            };

            // Refresh page after short delay to show new run
            setTimeout(() => {
                router.reload();
            }, 1500);
        } else {
            triggerResult.value = {
                success: false,
                message: data.message || 'Failed to trigger agent',
            };
        }
    } catch (e) {
        triggerResult.value = {
            success: false,
            message: 'Network error. Please try again.',
        };
    } finally {
        isTriggering.value = false;
    }
};

const activeAgents = () => agents.value.filter(a => a.status === 'active').slice(0, 4);
</script>

<template>
    <div class="card">
        <div class="card-header">
            <span class="card-title">Quick Actions</span>
        </div>

        <div class="card-body">
            <!-- Loading state -->
            <div v-if="isLoading" class="flex items-center justify-center py-8">
                <div class="animate-pulse flex items-center gap-2">
                    <div class="w-2 h-2 rounded-full bg-current opacity-50"></div>
                    <span class="text-caption">Loading agents...</span>
                </div>
            </div>

            <!-- Agent quick triggers -->
            <div v-else-if="activeAgents().length > 0" class="space-y-2">
                <button
                    v-for="agent in activeAgents()"
                    :key="agent.id"
                    class="quick-trigger-btn"
                    @click="openTriggerModal(agent)"
                >
                    <div class="flex items-center gap-3">
                        <div class="status-dot running"></div>
                        <div class="text-left">
                            <div class="trigger-name">{{ agent.name }}</div>
                            <div class="trigger-desc">{{ agent.description?.slice(0, 50) }}{{ agent.description?.length > 50 ? '...' : '' }}</div>
                        </div>
                    </div>
                    <svg class="trigger-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                    </svg>
                </button>
            </div>

            <!-- Empty state -->
            <div v-else class="py-6 text-center">
                <div class="avatar avatar-md avatar-muted mx-auto mb-3">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8.25 3v1.5M4.5 8.25H3m18 0h-1.5M4.5 12H3m18 0h-1.5m-15 3.75H3m18 0h-1.5M8.25 19.5V21M12 3v1.5m0 15V21m3.75-18v1.5m0 15V21m-9-1.5h10.5a2.25 2.25 0 002.25-2.25V6.75a2.25 2.25 0 00-2.25-2.25H6.75A2.25 2.25 0 004.5 6.75v10.5a2.25 2.25 0 002.25 2.25zm.75-12h9v9h-9v-9z" />
                    </svg>
                </div>
                <p class="text-body">No active agents</p>
                <p class="text-caption mt-1">Create an agent to get started</p>
            </div>
        </div>
    </div>

    <!-- Trigger Modal -->
    <Modal
        :show="showTriggerModal"
        :title="`Trigger ${selectedAgent?.name}`"
        size="md"
        @close="showTriggerModal = false"
    >
        <div v-if="selectedAgent" class="space-y-4">
            <div class="agent-info">
                <div class="flex items-center gap-2 mb-2">
                    <div class="status-dot running"></div>
                    <span class="text-mono">{{ selectedAgent.slug }}</span>
                </div>
                <p class="text-body">{{ selectedAgent.description }}</p>
            </div>

            <FormTextarea
                v-model="triggerPrompt"
                label="Prompt (optional)"
                placeholder="Provide specific instructions for this run..."
                :rows="4"
            />

            <div v-if="selectedAgent.requires_approval" class="approval-notice">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
                <span class="text-caption">This agent requires approval before actions</span>
            </div>

            <!-- Dry Run Result -->
            <div v-if="dryRunResult" class="dry-run-result">
                <div class="dry-run-header">
                    <span :class="['status-indicator', dryRunResult.valid ? 'valid' : 'invalid']">
                        {{ dryRunResult.valid ? 'Ready to Execute' : 'Cannot Execute' }}
                    </span>
                </div>

                <div class="dry-run-preview">
                    <div class="preview-item">
                        <span class="preview-label">Model</span>
                        <span class="preview-value">{{ dryRunResult.execution_preview?.model }}</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Est. Cost</span>
                        <span class="preview-value">${{ dryRunResult.execution_preview?.estimated_cost_usd }}</span>
                    </div>
                    <div class="preview-item">
                        <span class="preview-label">Approval</span>
                        <span class="preview-value">{{ dryRunResult.execution_preview?.requires_approval ? 'Required' : 'Auto' }}</span>
                    </div>
                </div>

                <div v-if="dryRunResult.warnings?.length" class="dry-run-warnings">
                    <div v-for="(warning, i) in dryRunResult.warnings" :key="i" class="warning-item">
                        {{ warning }}
                    </div>
                </div>
            </div>

            <!-- Result message -->
            <div
                v-if="triggerResult"
                :class="['result-message', triggerResult.success ? 'success' : 'error']"
            >
                {{ triggerResult.message }}
            </div>
        </div>

        <template #footer>
            <button class="btn btn-secondary" @click="showTriggerModal = false">
                Cancel
            </button>
            <button
                class="btn btn-secondary"
                :disabled="isDryRunning"
                @click="performDryRun"
            >
                {{ isDryRunning ? 'Testing...' : 'Test Run' }}
            </button>
            <button
                class="btn btn-primary"
                :disabled="isTriggering"
                @click="triggerAgent"
            >
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" />
                </svg>
                {{ isTriggering ? 'Triggering...' : 'Trigger Agent' }}
            </button>
        </template>
    </Modal>
</template>

<style scoped>
.quick-trigger-btn {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    padding: 0.75rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    cursor: pointer;
    transition: all 0.15s ease;
}

.quick-trigger-btn:hover {
    border-color: var(--color-border-default);
    background: var(--color-bg-secondary);
}

.trigger-name {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.trigger-desc {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.125rem;
}

.trigger-icon {
    width: 1.25rem;
    height: 1.25rem;
    color: var(--color-text-tertiary);
    flex-shrink: 0;
    transition: color 0.15s ease;
}

.quick-trigger-btn:hover .trigger-icon {
    color: var(--color-status-green);
}

.agent-info {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 8px;
    border: 1px solid var(--color-border-subtle);
}

.approval-notice {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.75rem;
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.2);
    border-radius: 8px;
    color: var(--color-status-green);
}

.result-message {
    padding: 0.75rem;
    border-radius: 8px;
    font-size: 0.875rem;
}

.result-message.success {
    background: rgba(34, 197, 94, 0.08);
    border: 1px solid rgba(34, 197, 94, 0.2);
    color: var(--color-status-green);
}

.result-message.error {
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    color: var(--color-status-red);
}

.dry-run-result {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
}

.dry-run-header {
    margin-bottom: 0.75rem;
}

.status-indicator {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    padding: 0.25rem 0.5rem;
    border-radius: 4px;
}

.status-indicator.valid {
    background: rgba(34, 197, 94, 0.12);
    color: var(--color-status-green);
}

.status-indicator.invalid {
    background: rgba(239, 68, 68, 0.12);
    color: var(--color-status-red);
}

.dry-run-preview {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 0.75rem;
}

.preview-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.preview-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.preview-value {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.dry-run-warnings {
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border-subtle);
}

.warning-item {
    font-size: 0.8125rem;
    color: var(--color-status-yellow);
    padding: 0.25rem 0;
}

.warning-item::before {
    content: '⚠ ';
}
</style>
