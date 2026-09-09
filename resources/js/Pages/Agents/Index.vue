<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';
import ToolBrowser from '@/Components/ToolBrowser.vue';
import { Link, router } from '@inertiajs/vue3';
import { ref, reactive, computed, onMounted, watch } from 'vue';

interface SkillFile {
    slug: string;
    name: string;
    path: string;
    exists: boolean;
}

interface Agent {
    id: number;
    name: string;
    slug: string;
    description: string;
    status: 'active' | 'paused' | 'disabled';
    model: string;
    requires_approval: boolean;
    use_consortium: boolean;
    max_budget_usd: number;
    runs_count: number;
    completed_runs_count: number;
    total_cost: number;
    system_prompt?: string;
    skill_file?: string;
    tools?: string[];
    schedule?: string;
    trigger_config?: {
        trigger_type?: string;
        chain_from?: string;
        cron?: string;
    };
}

interface Stats {
    total: number;
    active: number;
    total_runs: number;
    total_cost: number;
}

const props = defineProps<{
    agents: Agent[];
    stats: Stats;
}>();

// Modal states
const showAgentModal = ref(false);
const showDeleteModal = ref(false);
const showToolBrowser = ref(false);
const editingAgent = ref<Agent | null>(null);
const agentToDelete = ref<Agent | null>(null);
const isSaving = ref(false);
const isDeleting = ref(false);

// Skills data
const skillFiles = ref<SkillFile[]>([]);
const loadingSkills = ref(false);

// Agent form
const agentForm = reactive({
    name: '',
    slug: '',
    description: '',
    status: 'paused' as Agent['status'],
    model: 'sonnet',
    requires_approval: true,
    use_consortium: false,
    max_budget_usd: '10',
    system_prompt: '',
    skill_file: '',
    tools: [] as string[],
    schedule: '',
    // Trigger config
    trigger_type: 'manual' as 'manual' | 'scheduled' | 'webhook' | 'chained',
    chain_from: '',
    cron_expression: '',
});

// Load skill files
onMounted(async () => {
    loadingSkills.value = true;
    try {
        const response = await fetch('/api/skills');
        if (response.ok) {
            const data = await response.json();
            skillFiles.value = data.skills || [];
        }
    } catch (e) {
        console.error('Failed to load skills:', e);
    } finally {
        loadingSkills.value = false;
    }
});

const statusOptions = [
    { value: 'active', label: 'Active' },
    { value: 'paused', label: 'Paused' },
    { value: 'disabled', label: 'Disabled' },
];

const modelOptions = [
    { value: 'opus', label: 'Claude Opus (Most Capable)' },
    { value: 'sonnet', label: 'Claude Sonnet (Balanced)' },
    { value: 'haiku', label: 'Claude Haiku (Fast)' },
];

const scheduleOptions = [
    { value: '', label: 'None (use trigger)' },
    { value: 'hourly', label: 'Every hour' },
    { value: 'daily', label: 'Daily' },
    { value: 'weekly', label: 'Weekly' },
];

const triggerOptions = [
    { value: 'manual', label: 'Manual' },
    { value: 'scheduled', label: 'Scheduled' },
    { value: 'webhook', label: 'Webhook' },
    { value: 'chained', label: 'Chained (after another agent)' },
];

// For chained trigger, get list of other agents
const chainableAgents = computed(() =>
    props.agents.filter(a => !editingAgent.value || a.id !== editingAgent.value.id)
);

// Skill file options for dropdown
const skillFileOptions = computed(() => [
    { value: '', label: 'Custom prompt (below)' },
    ...skillFiles.value.map(s => ({ value: s.path, label: s.name }))
]);

const modalTitle = computed(() => editingAgent.value ? 'Edit Agent' : 'New Agent');

const resetForm = () => {
    agentForm.name = '';
    agentForm.slug = '';
    agentForm.description = '';
    agentForm.status = 'paused';
    agentForm.model = 'sonnet';
    agentForm.requires_approval = true;
    agentForm.use_consortium = false;
    agentForm.max_budget_usd = '10';
    agentForm.system_prompt = '';
    agentForm.skill_file = '';
    agentForm.tools = [];
    agentForm.schedule = '';
    agentForm.trigger_type = 'manual';
    agentForm.chain_from = '';
    agentForm.cron_expression = '';
};

const generateSlug = () => {
    agentForm.slug = agentForm.name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/(^-|-$)/g, '');
};

const openNewAgent = () => {
    editingAgent.value = null;
    resetForm();
    showAgentModal.value = true;
};

const openEditAgent = (agent: Agent, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    editingAgent.value = agent;
    agentForm.name = agent.name;
    agentForm.slug = agent.slug;
    agentForm.description = agent.description || '';
    agentForm.status = agent.status;
    agentForm.model = agent.model;
    agentForm.requires_approval = agent.requires_approval;
    agentForm.use_consortium = agent.use_consortium ?? false;
    agentForm.max_budget_usd = agent.max_budget_usd?.toString() || '10';
    agentForm.system_prompt = agent.system_prompt || '';
    agentForm.skill_file = agent.skill_file || '';
    agentForm.tools = Array.isArray(agent.tools) ? agent.tools : [];
    agentForm.schedule = agent.schedule || '';
    // Load trigger config
    agentForm.trigger_type = (agent.trigger_config?.trigger_type as typeof agentForm.trigger_type) || 'manual';
    agentForm.chain_from = agent.trigger_config?.chain_from || '';
    agentForm.cron_expression = agent.trigger_config?.cron || '';
    showAgentModal.value = true;
};

const closeAgentModal = () => {
    showAgentModal.value = false;
    editingAgent.value = null;
    resetForm();
};

// Tool browser updates tools via v-model

const saveAgent = () => {
    isSaving.value = true;
    const data = { ...agentForm };

    if (editingAgent.value) {
        router.put(`/agents/${editingAgent.value.slug}`, data, {
            onSuccess: () => closeAgentModal(),
            onFinish: () => isSaving.value = false,
        });
    } else {
        router.post('/agents', data, {
            onSuccess: () => closeAgentModal(),
            onFinish: () => isSaving.value = false,
        });
    }
};

const toggleStatus = (agent: Agent, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    const newStatus = agent.status === 'active' ? 'paused' : 'active';
    router.post(`/agents/${agent.slug}/status`, { status: newStatus }, {
        preserveScroll: true,
    });
};

const openDeleteModal = (agent: Agent, e: Event) => {
    e.preventDefault();
    e.stopPropagation();
    agentToDelete.value = agent;
    showDeleteModal.value = true;
};

const confirmDelete = () => {
    if (!agentToDelete.value) return;
    isDeleting.value = true;
    router.delete(`/agents/${agentToDelete.value.slug}`, {
        onSuccess: () => {
            showDeleteModal.value = false;
            agentToDelete.value = null;
        },
        onFinish: () => isDeleting.value = false,
    });
};

const formatCurrency = (value: number | string) => {
    const num = Number(value) || 0;
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
    }).format(num);
};

const getStatusClass = (status: string) => {
    const classes: Record<string, string> = {
        active: 'running',
        paused: 'pending',
        disabled: 'failed',
    };
    return classes[status] || 'pending';
};

const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        active: 'badge-green',
        paused: 'badge-yellow',
        disabled: 'badge-red',
    };
    return badges[status] || 'badge-gray';
};

const getModelBadge = (model: string) => {
    const badges: Record<string, string> = {
        opus: 'badge-blue',
        sonnet: 'badge-gray',
        haiku: 'badge-gray',
    };
    return badges[model] || 'badge-gray';
};
</script>

<template>
    <AppLayout title="Agents">
        <!-- Header with New Agent button -->
        <div class="flex items-center justify-between mb-6">
            <div></div>
            <button class="btn btn-primary" @click="openNewAgent">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                New Agent
            </button>
        </div>

        <!-- Stats -->
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4 mb-6">
            <div class="metric-card">
                <div class="metric-label">TOTAL AGENTS</div>
                <div class="metric-value">{{ stats.total }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE</div>
                <div class="flex items-baseline gap-2">
                    <span class="metric-value">{{ stats.active }}</span>
                    <div class="status-dot running"></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL RUNS</div>
                <div class="metric-value">{{ stats.total_runs }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL COST</div>
                <div class="metric-value">{{ formatCurrency(stats.total_cost) }}</div>
            </div>
        </div>

        <!-- Agents Grid -->
        <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Link
                v-for="agent in agents"
                :key="agent.id"
                :href="`/agents/${agent.slug}`"
                class="card agent-card cursor-pointer transition-all hover:border-[var(--color-border-strong)] block"
            >
                <div class="p-5">
                    <!-- Header -->
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center gap-3">
                            <button
                                class="status-toggle"
                                :class="{ active: agent.status === 'active' }"
                                @click="toggleStatus(agent, $event)"
                                title="Toggle status"
                            >
                                <div :class="['status-dot', getStatusClass(agent.status)]"></div>
                            </button>
                            <div>
                                <div class="text-heading">{{ agent.name }}</div>
                                <div class="text-mono text-caption">{{ agent.slug }}</div>
                            </div>
                        </div>
                        <div class="flex items-center gap-2">
                            <span :class="['badge', getModelBadge(agent.model)]">{{ agent.model }}</span>
                            <span :class="['badge', getStatusBadge(agent.status)]">{{ agent.status }}</span>
                            <div class="card-actions">
                                <button class="action-btn" @click="openEditAgent(agent, $event)" title="Edit">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                    </svg>
                                </button>
                                <button class="action-btn action-btn-danger" @click="openDeleteModal(agent, $event)" title="Delete">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Description -->
                    <p class="text-body mb-4">{{ agent.description }}</p>

                    <!-- Config -->
                    <div class="flex items-center gap-4 mb-4">
                        <div class="flex items-center gap-2">
                            <svg class="h-4 w-4" style="color: var(--color-text-tertiary)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span class="text-caption">Max {{ formatCurrency(agent.max_budget_usd) }}/run</span>
                        </div>
                        <div v-if="agent.requires_approval" class="flex items-center gap-2">
                            <svg class="h-4 w-4" style="color: var(--color-status-yellow)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                            </svg>
                            <span class="text-caption">Requires approval</span>
                        </div>
                    </div>

                    <!-- Stats -->
                    <div class="flex items-center justify-between pt-4" style="border-top: 1px solid var(--color-border-subtle)">
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ agent.runs_count }}</span>
                            <span class="text-caption ml-1">runs</span>
                        </div>
                        <div>
                            <span class="text-mono" style="color: var(--color-status-green)">{{ agent.completed_runs_count }}</span>
                            <span class="text-caption ml-1">completed</span>
                        </div>
                        <div>
                            <span class="text-mono" style="color: var(--color-text-primary)">{{ formatCurrency(agent.total_cost) }}</span>
                            <span class="text-caption ml-1">spent</span>
                        </div>
                    </div>
                </div>
            </Link>
        </div>

        <div v-if="agents.length === 0" class="card p-8 text-center">
            <p class="text-body">No agents configured</p>
            <p class="text-caption mt-1">Create your first agent to automate tasks</p>
            <button class="btn btn-primary mt-4" @click="openNewAgent">Create Agent</button>
        </div>

        <!-- New/Edit Agent Modal -->
        <Modal :show="showAgentModal" size="lg" @close="closeAgentModal">
            <template #header>
                <h2 class="modal-title">{{ modalTitle }}</h2>
            </template>

            <form @submit.prevent="saveAgent">
                <!-- Basic Info -->
                <div class="form-section">
                    <h3 class="form-section-title">Basic Information</h3>
                    <div class="form-grid">
                        <FormInput
                            v-model="agentForm.name"
                            label="Agent Name"
                            placeholder="Content Writer"
                            required
                            @blur="!editingAgent && generateSlug()"
                        />
                        <FormInput
                            v-model="agentForm.slug"
                            label="Slug"
                            placeholder="content-writer"
                            :disabled="!!editingAgent"
                            hint="Used in URLs and API calls"
                        />
                    </div>
                    <FormTextarea
                        v-model="agentForm.description"
                        label="Description"
                        placeholder="What does this agent do?"
                        :rows="2"
                    />
                </div>

                <!-- Model & Performance -->
                <div class="form-section">
                    <h3 class="form-section-title">Model & Performance</h3>
                    <div class="form-grid">
                        <FormSelect
                            v-model="agentForm.model"
                            label="AI Model"
                            :options="modelOptions"
                        />
                        <FormSelect
                            v-model="agentForm.status"
                            label="Status"
                            :options="statusOptions"
                        />
                    </div>
                    <div class="form-grid">
                        <FormInput
                            v-model="agentForm.max_budget_usd"
                            label="Max Budget per Run"
                            placeholder="10"
                            type="number"
                            prefix="$"
                            hint="Maximum cost allowed per execution"
                        />
                        <FormSelect
                            v-model="agentForm.schedule"
                            label="Schedule"
                            :options="scheduleOptions"
                        />
                    </div>
                </div>

                <!-- Safety & Approval -->
                <div class="form-section">
                    <h3 class="form-section-title">Safety & Approval</h3>
                    <FormToggle
                        v-model="agentForm.requires_approval"
                        label="Require Approval"
                        description="Agent actions must be approved before execution"
                    />
                    <FormToggle
                        v-model="agentForm.use_consortium"
                        label="Multi-model Consortium"
                        description="Use multiple AI models and consolidate outputs for high-stakes decisions"
                    />
                </div>

                <!-- Trigger Configuration -->
                <div class="form-section">
                    <h3 class="form-section-title">Trigger Configuration</h3>
                    <div class="form-grid">
                        <FormSelect
                            v-model="agentForm.trigger_type"
                            label="Trigger Type"
                            :options="triggerOptions"
                        />
                        <FormSelect
                            v-if="agentForm.trigger_type === 'scheduled'"
                            v-model="agentForm.schedule"
                            label="Schedule Preset"
                            :options="scheduleOptions"
                        />
                        <FormSelect
                            v-if="agentForm.trigger_type === 'chained'"
                            v-model="agentForm.chain_from"
                            label="Run After Agent"
                            :options="[
                                { value: '', label: 'Select agent...' },
                                ...chainableAgents.map(a => ({ value: a.slug, label: a.name }))
                            ]"
                        />
                    </div>
                    <FormInput
                        v-if="agentForm.trigger_type === 'scheduled' && agentForm.schedule === ''"
                        v-model="agentForm.cron_expression"
                        label="Custom Cron Expression"
                        placeholder="0 9 * * 1-5"
                        hint="e.g., '0 9 * * 1-5' for weekdays at 9am"
                    />
                    <p v-if="agentForm.trigger_type === 'webhook'" class="text-caption mt-2">
                        Webhook URL will be generated after creating the agent.
                    </p>
                </div>

                <!-- Tools -->
                <div class="form-section">
                    <h3 class="form-section-title">Available Tools</h3>
                    <div class="tools-summary">
                        <div class="flex items-center justify-between">
                            <span class="text-body">{{ agentForm.tools.length }} tool{{ agentForm.tools.length !== 1 ? 's' : '' }} selected</span>
                            <button
                                type="button"
                                class="btn btn-secondary btn-sm"
                                @click="showToolBrowser = true"
                            >
                                Browse Tools
                            </button>
                        </div>
                        <div v-if="agentForm.tools.length > 0" class="selected-tools mt-2">
                            <span
                                v-for="toolId in agentForm.tools.slice(0, 5)"
                                :key="toolId"
                                class="tool-chip"
                            >
                                {{ toolId }}
                            </span>
                            <span v-if="agentForm.tools.length > 5" class="text-caption">
                                +{{ agentForm.tools.length - 5 }} more
                            </span>
                        </div>
                    </div>
                </div>

                <!-- System Prompt / Skill File -->
                <div class="form-section">
                    <h3 class="form-section-title">System Prompt</h3>
                    <FormSelect
                        v-model="agentForm.skill_file"
                        label="Prompt Source"
                        :options="skillFileOptions"
                        hint="Use a SKILL.md file for version-controlled prompts"
                    />
                    <FormTextarea
                        v-if="!agentForm.skill_file"
                        v-model="agentForm.system_prompt"
                        placeholder="You are a helpful assistant that..."
                        :rows="4"
                        class="mt-3"
                        hint="Custom instructions for the agent's behavior"
                    />
                    <p v-else class="text-caption mt-2">
                        Prompt will be loaded from: <code class="text-mono">storage/app/skills/{{ agentForm.skill_file }}</code>
                    </p>
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="closeAgentModal">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSaving || !agentForm.name || !agentForm.slug"
                        @click="saveAgent"
                    >
                        {{ isSaving ? 'Saving...' : (editingAgent ? 'Update Agent' : 'Create Agent') }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Delete Confirmation Modal -->
        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete Agent</h2>
            </template>

            <div class="delete-warning">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p>Delete <strong>{{ agentToDelete?.name }}</strong>?</p>
                <p class="text-caption mt-2">
                    This agent has completed {{ agentToDelete?.completed_runs_count || 0 }} runs. This action cannot be undone.
                </p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isDeleting"
                        @click="confirmDelete"
                    >
                        {{ isDeleting ? 'Deleting...' : 'Delete Agent' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Tool Browser Modal -->
        <ToolBrowser
            v-model="agentForm.tools"
            :show="showToolBrowser"
            @close="showToolBrowser = false"
        />
    </AppLayout>
</template>

<style scoped>
.agents-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.agents-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
    gap: 1rem;
}

.agent-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 1.25rem;
    cursor: pointer;
    transition: all 0.15s ease;
    position: relative;
}

.agent-card:hover {
    border-color: var(--color-border-hover);
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.agent-card.inactive {
    opacity: 0.6;
}

.agent-card.inactive:hover {
    opacity: 0.8;
}

.card-header {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.agent-icon {
    width: 40px;
    height: 40px;
    background: var(--color-bg-elevated);
    border-radius: 8px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.25rem;
    flex-shrink: 0;
}

.agent-info {
    flex: 1;
    min-width: 0;
}

.agent-name {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.125rem;
}

.agent-model {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.status-toggle {
    padding: 0.25rem 0.5rem;
    font-size: 0.6875rem;
    font-weight: 500;
    border-radius: 4px;
    border: none;
    cursor: pointer;
    transition: all 0.15s ease;
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.status-toggle.active {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.status-toggle.active:hover {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.status-toggle.paused {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.status-toggle.paused:hover {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.agent-description {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: 1rem;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
}

.agent-stats {
    display: flex;
    gap: 1rem;
    padding-top: 0.75rem;
    border-top: 1px solid var(--color-border-subtle);
}

.stat {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.stat-value {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.stat-label {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.025em;
}

.card-actions {
    position: absolute;
    top: 0.75rem;
    right: 0.75rem;
    display: flex;
    gap: 0.25rem;
    opacity: 0;
    transition: opacity 0.15s ease;
}

.agent-card:hover .card-actions {
    opacity: 1;
}

.action-btn {
    padding: 0.375rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s ease;
    display: flex;
    align-items: center;
    justify-content: center;
}

.action-btn:hover {
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
    border-color: var(--color-border-hover);
}

.action-btn.danger:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
    border-color: rgba(239, 68, 68, 0.3);
}

/* Modal Styles */
.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.form-section {
    margin-bottom: 1.5rem;
}

.form-section:last-child {
    margin-bottom: 0;
}

.form-section h4 {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 1rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.form-row.three-col {
    grid-template-columns: repeat(3, 1fr);
}

/* Tools Grid */
.tools-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 0.5rem;
}

.tool-option {
    padding: 0.625rem 0.75rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    transition: all 0.15s ease;
}

.tool-option:hover {
    border-color: var(--color-border-hover);
}

.tool-option :deep(.checkbox-group) {
    margin-bottom: 0;
}

.tool-option :deep(.checkbox-label) {
    font-size: 0.8125rem;
}

.tool-option :deep(.checkbox-description) {
    font-size: 0.75rem;
}

/* System Prompt */
.prompt-textarea {
    width: 100%;
    min-height: 120px;
    padding: 0.75rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    color: var(--color-text-primary);
    font-family: 'SF Mono', 'Monaco', 'Menlo', monospace;
    font-size: 0.8125rem;
    line-height: 1.5;
    resize: vertical;
    transition: border-color 0.15s ease;
}

.prompt-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

.prompt-textarea::placeholder {
    color: var(--color-text-tertiary);
}

/* Modal Actions */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

/* Delete Modal */
.delete-warning {
    text-align: center;
    padding: 1rem 0;
}

.delete-icon {
    color: var(--color-status-red);
    margin-bottom: 1rem;
    display: flex;
    justify-content: center;
}

.delete-warning p {
    color: var(--color-text-primary);
    font-size: 0.9375rem;
}

.delete-warning .text-caption {
    color: var(--color-text-tertiary);
    font-size: 0.8125rem;
}

/* Buttons */
.btn {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    border: none;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    background: var(--color-accent-hover);
}

.btn-secondary {
    background: var(--color-bg-elevated);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-secondary:hover:not(:disabled) {
    background: var(--color-bg-surface);
    border-color: var(--color-border-hover);
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
}

.btn-danger:hover:not(:disabled) {
    background: #dc2626;
}

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
}

/* Tools Summary */
.tools-summary {
    padding: 1rem;
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
}

.selected-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.tool-chip {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.5rem;
    background: var(--color-accent-subtle, rgba(59, 130, 246, 0.1));
    color: var(--color-accent);
    font-size: 0.75rem;
    border-radius: 4px;
}

/* Responsive */
@media (max-width: 768px) {
    .form-row,
    .form-row.three-col {
        grid-template-columns: 1fr;
    }

    .tools-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
