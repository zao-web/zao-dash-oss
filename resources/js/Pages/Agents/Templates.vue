<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { ref, computed, reactive } from 'vue';
import { router } from '@inertiajs/vue3';

interface ConfigField {
    type: string;
    required: boolean;
    description: string;
}

interface Template {
    id: number;
    name: string;
    slug: string;
    description: string;
    category: string;
    default_model: string;
    default_budget_usd: string;
    default_requires_approval: boolean;
    default_tools: string[];
    system_prompt_template: string;
    config_schema: Record<string, ConfigField>;
    usage_count: number;
}

const props = defineProps<{
    templates: Template[];
    categories: string[];
}>();

// Filter state
const selectedCategory = ref<string>('all');
const searchQuery = ref('');

// Create modal state
const showCreateModal = ref(false);
const selectedTemplate = ref<Template | null>(null);
const isCreating = ref(false);
const createError = ref('');

const createForm = reactive({
    name: '',
    slug: '',
    description: '',
    config: {} as Record<string, string>,
    model: '',
    budget: '',
    requires_approval: true,
});

// Preview state
const showPreviewModal = ref(false);
const previewData = ref<{
    system_prompt: string;
    model: string;
    budget: string;
    tools: string[];
} | null>(null);

const filteredTemplates = computed(() => {
    let result = props.templates;

    if (selectedCategory.value !== 'all') {
        result = result.filter(t => t.category === selectedCategory.value);
    }

    if (searchQuery.value) {
        const query = searchQuery.value.toLowerCase();
        result = result.filter(t =>
            t.name.toLowerCase().includes(query) ||
            t.description?.toLowerCase().includes(query)
        );
    }

    return result;
});

const groupedTemplates = computed(() => {
    const groups: Record<string, Template[]> = {};
    for (const template of filteredTemplates.value) {
        if (!groups[template.category]) {
            groups[template.category] = [];
        }
        groups[template.category].push(template);
    }
    return groups;
});

const openCreateModal = (template: Template) => {
    selectedTemplate.value = template;
    createForm.name = '';
    createForm.slug = '';
    createForm.description = template.description || '';
    createForm.config = {};
    createForm.model = template.default_model;
    createForm.budget = template.default_budget_usd;
    createForm.requires_approval = template.default_requires_approval;
    createError.value = '';

    // Initialize config fields
    if (template.config_schema) {
        for (const key of Object.keys(template.config_schema)) {
            createForm.config[key] = '';
        }
    }

    showCreateModal.value = true;
};

const createAgent = async () => {
    if (!selectedTemplate.value) return;

    isCreating.value = true;
    createError.value = '';

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const response = await fetch(`/api/agent-templates/${selectedTemplate.value.id}/create-agent`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            body: JSON.stringify({
                name: createForm.name,
                slug: createForm.slug || undefined,
                description: createForm.description,
                config: {
                    ...createForm.config,
                    model: createForm.model,
                    max_budget_usd: parseFloat(createForm.budget),
                    requires_approval: createForm.requires_approval,
                },
            }),
        });

        const data = await response.json();

        if (response.ok) {
            showCreateModal.value = false;
            router.visit(`/agents/${data.agent.slug}`);
        } else {
            createError.value = data.message || 'Failed to create agent';
        }
    } catch (e) {
        createError.value = 'Network error. Please try again.';
    } finally {
        isCreating.value = false;
    }
};

const previewTemplate = async (template: Template) => {
    selectedTemplate.value = template;

    try {
        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

        const response = await fetch(`/api/agent-templates/${template.id}/preview`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-TOKEN': csrfToken || '',
            },
            body: JSON.stringify({ config: {} }),
        });

        if (response.ok) {
            const data = await response.json();
            previewData.value = data.preview;
            showPreviewModal.value = true;
        }
    } catch (e) {
        console.error('Preview failed:', e);
    }
};

const getCategoryIcon = (category: string) => {
    const icons: Record<string, string> = {
        productivity: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01',
        content: 'M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z',
        development: 'M10 20l4-16m4 4l4 4-4 4M6 16l-4-4 4-4',
        communication: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
        analytics: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
    };
    return icons[category] || 'M13 10V3L4 14h7v7l9-11h-7z';
};

const getModelBadgeClass = (model: string) => {
    const classes: Record<string, string> = {
        opus: 'badge-purple',
        sonnet: 'badge-blue',
        haiku: 'badge-green',
    };
    return classes[model] || 'badge-gray';
};
</script>

<template>
    <AppLayout title="Agent Templates">
        <!-- Header -->
        <div class="flex items-center justify-between mb-6">
            <div>
                <h1 class="text-heading text-xl">Agent Templates</h1>
                <p class="text-caption mt-1">Pre-built configurations for creating agents quickly</p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex items-center gap-4 mb-6">
            <div class="flex-1 max-w-md">
                <input
                    v-model="searchQuery"
                    type="text"
                    placeholder="Search templates..."
                    class="form-input w-full"
                />
            </div>

            <div class="flex items-center gap-2">
                <button
                    :class="['filter-btn', selectedCategory === 'all' && 'active']"
                    @click="selectedCategory = 'all'"
                >
                    All
                </button>
                <button
                    v-for="category in categories"
                    :key="category"
                    :class="['filter-btn', selectedCategory === category && 'active']"
                    @click="selectedCategory = category"
                >
                    {{ category }}
                </button>
            </div>
        </div>

        <!-- Templates Grid -->
        <div v-for="(templates, category) in groupedTemplates" :key="category" class="mb-8">
            <div class="flex items-center gap-2 mb-4">
                <svg class="h-5 w-5 text-caption" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" :d="getCategoryIcon(category)" />
                </svg>
                <h2 class="text-heading capitalize">{{ category }}</h2>
                <span class="text-caption">({{ templates.length }})</span>
            </div>

            <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-3">
                <div
                    v-for="template in templates"
                    :key="template.id"
                    class="template-card"
                >
                    <div class="template-header">
                        <h3 class="template-name">{{ template.name }}</h3>
                        <span :class="['badge', getModelBadgeClass(template.default_model)]">
                            {{ template.default_model }}
                        </span>
                    </div>

                    <p class="template-description">{{ template.description }}</p>

                    <div class="template-meta">
                        <div class="meta-item">
                            <span class="meta-label">Budget</span>
                            <span class="meta-value">${{ template.default_budget_usd }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Approval</span>
                            <span class="meta-value">{{ template.default_requires_approval ? 'Required' : 'Auto' }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Used</span>
                            <span class="meta-value">{{ template.usage_count }}x</span>
                        </div>
                    </div>

                    <div v-if="template.default_tools?.length" class="template-tools">
                        <span
                            v-for="tool in template.default_tools"
                            :key="tool"
                            class="tool-tag"
                        >
                            {{ tool }}
                        </span>
                    </div>

                    <div class="template-actions">
                        <button
                            class="btn btn-secondary btn-sm"
                            @click="previewTemplate(template)"
                        >
                            Preview
                        </button>
                        <button
                            class="btn btn-primary btn-sm"
                            @click="openCreateModal(template)"
                        >
                            Use Template
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty State -->
        <div v-if="Object.keys(groupedTemplates).length === 0" class="empty-state">
            <div class="avatar avatar-lg avatar-muted mx-auto mb-4">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                </svg>
            </div>
            <p class="text-body">No templates found</p>
            <p class="text-caption mt-1">Try adjusting your search or filter</p>
        </div>

        <!-- Create Agent Modal -->
        <Modal
            :show="showCreateModal"
            :title="`Create Agent from ${selectedTemplate?.name}`"
            size="lg"
            @close="showCreateModal = false"
        >
            <div v-if="selectedTemplate" class="space-y-4">
                <FormInput
                    v-model="createForm.name"
                    label="Agent Name"
                    placeholder="My Custom Agent"
                    required
                />

                <FormInput
                    v-model="createForm.slug"
                    label="Slug (optional)"
                    placeholder="my-custom-agent"
                    hint="Leave blank to auto-generate from name"
                />

                <FormTextarea
                    v-model="createForm.description"
                    label="Description"
                    :rows="2"
                />

                <!-- Config fields from schema -->
                <div v-if="selectedTemplate.config_schema && Object.keys(selectedTemplate.config_schema).length > 0">
                    <h4 class="text-heading text-sm mb-3">Configuration</h4>

                    <div class="space-y-3">
                        <div v-for="(field, key) in selectedTemplate.config_schema" :key="key">
                            <FormInput
                                v-model="createForm.config[key]"
                                :label="key.replace(/_/g, ' ')"
                                :placeholder="field.description"
                                :required="field.required"
                            />
                        </div>
                    </div>
                </div>

                <!-- Model & Budget -->
                <div class="grid grid-cols-2 gap-4">
                    <FormSelect
                        v-model="createForm.model"
                        label="Model"
                        :options="[
                            { value: 'opus', label: 'Opus (Most capable)' },
                            { value: 'sonnet', label: 'Sonnet (Balanced)' },
                            { value: 'haiku', label: 'Haiku (Fastest)' },
                        ]"
                    />

                    <FormInput
                        v-model="createForm.budget"
                        label="Max Budget (USD)"
                        type="number"
                        step="0.01"
                        min="0"
                    />
                </div>

                <FormToggle
                    v-model="createForm.requires_approval"
                    label="Requires Approval"
                    description="Agent actions will need manual approval before execution"
                />

                <div v-if="createError" class="error-message">
                    {{ createError }}
                </div>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showCreateModal = false">
                    Cancel
                </button>
                <button
                    class="btn btn-primary"
                    :disabled="isCreating || !createForm.name"
                    @click="createAgent"
                >
                    {{ isCreating ? 'Creating...' : 'Create Agent' }}
                </button>
            </template>
        </Modal>

        <!-- Preview Modal -->
        <Modal
            :show="showPreviewModal"
            :title="`Preview: ${selectedTemplate?.name}`"
            size="lg"
            @close="showPreviewModal = false"
        >
            <div v-if="previewData" class="space-y-4">
                <div class="preview-section">
                    <h4 class="preview-label">Model & Budget</h4>
                    <div class="flex items-center gap-4">
                        <span :class="['badge', getModelBadgeClass(previewData.model)]">
                            {{ previewData.model }}
                        </span>
                        <span class="text-body">${{ previewData.budget }} max</span>
                    </div>
                </div>

                <div v-if="previewData.tools?.length" class="preview-section">
                    <h4 class="preview-label">Tools</h4>
                    <div class="flex flex-wrap gap-2">
                        <span v-for="tool in previewData.tools" :key="tool" class="tool-tag">
                            {{ tool }}
                        </span>
                    </div>
                </div>

                <div class="preview-section">
                    <h4 class="preview-label">System Prompt</h4>
                    <pre class="prompt-preview">{{ previewData.system_prompt }}</pre>
                </div>
            </div>

            <template #footer>
                <button class="btn btn-secondary" @click="showPreviewModal = false">
                    Close
                </button>
                <button
                    class="btn btn-primary"
                    @click="showPreviewModal = false; openCreateModal(selectedTemplate!)"
                >
                    Use This Template
                </button>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.filter-btn {
    padding: 0.5rem 1rem;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 6px;
    cursor: pointer;
    transition: all 0.15s ease;
    text-transform: capitalize;
}

.filter-btn:hover {
    border-color: var(--color-border-default);
}

.filter-btn.active {
    background: var(--color-accent-primary);
    border-color: var(--color-accent-primary);
    color: white;
}

.template-card {
    padding: 1.25rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    transition: all 0.15s ease;
}

.template-card:hover {
    border-color: var(--color-border-default);
}

.template-header {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    margin-bottom: 0.75rem;
}

.template-name {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.template-description {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
    line-height: 1.5;
}

.template-meta {
    display: flex;
    gap: 1.5rem;
    margin-bottom: 1rem;
    padding-bottom: 1rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.meta-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.meta-label {
    font-size: 0.6875rem;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
}

.meta-value {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.template-tools {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.tool-tag {
    padding: 0.25rem 0.5rem;
    font-size: 0.75rem;
    font-family: var(--font-mono);
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
    border-radius: 4px;
}

.template-actions {
    display: flex;
    gap: 0.75rem;
}

.template-actions .btn {
    flex: 1;
}

.empty-state {
    padding: 4rem 2rem;
    text-align: center;
}

.error-message {
    padding: 0.75rem;
    background: rgba(239, 68, 68, 0.08);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
    color: var(--color-status-red);
    font-size: 0.875rem;
}

.preview-section {
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.preview-section:first-child {
    padding-top: 0;
    border-top: none;
}

.preview-label {
    font-size: 0.75rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--color-text-tertiary);
    margin-bottom: 0.75rem;
}

.prompt-preview {
    padding: 1rem;
    background: var(--color-bg-primary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    font-family: var(--font-mono);
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    white-space: pre-wrap;
    word-break: break-word;
    max-height: 300px;
    overflow-y: auto;
}

.badge-purple {
    background: rgba(168, 85, 247, 0.12);
    color: rgb(168, 85, 247);
}
</style>
