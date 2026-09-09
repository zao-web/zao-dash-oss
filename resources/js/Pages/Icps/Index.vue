<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormToggle from '@/Components/FormToggle.vue';

interface IcpStats {
    total: number;
    new: number;
    qualified: number;
    converted: number;
}

interface Icp {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    industries: string[];
    company_sizes: string[];
    tech_stack: string[];
    avg_deal_value: number | null;
    is_active: boolean;
    prospects_count: number;
    stats: IcpStats;
}

const props = defineProps<{
    icps: Icp[];
}>();

const filters = reactive({
    search: '',
    status: 'all',
});

const showAddModal = ref(false);
const showEditModal = ref(false);
const showDeleteModal = ref(false);
const selectedIcp = ref<Icp | null>(null);
const isSubmitting = ref(false);

const icpForm = reactive({
    name: '',
    description: '',
    industries: '',
    company_sizes: '',
    tech_stack: '',
    buying_signals: '',
    pain_points: '',
    avg_deal_value: '',
    weight_industry: 25,
    weight_size: 20,
    weight_tech: 30,
    weight_signals: 25,
    is_active: true,
});

const filteredIcps = computed(() => {
    return props.icps.filter(icp => {
        if (filters.status === 'active' && !icp.is_active) return false;
        if (filters.status === 'inactive' && icp.is_active) return false;
        if (filters.search) {
            const search = filters.search.toLowerCase();
            return icp.name.toLowerCase().includes(search) ||
                   icp.industries.some(i => i.toLowerCase().includes(search)) ||
                   icp.tech_stack.some(t => t.toLowerCase().includes(search));
        }
        return true;
    });
});

const resetForm = () => {
    icpForm.name = '';
    icpForm.description = '';
    icpForm.industries = '';
    icpForm.company_sizes = '';
    icpForm.tech_stack = '';
    icpForm.buying_signals = '';
    icpForm.pain_points = '';
    icpForm.avg_deal_value = '';
    icpForm.weight_industry = 25;
    icpForm.weight_size = 20;
    icpForm.weight_tech = 30;
    icpForm.weight_signals = 25;
    icpForm.is_active = true;
};

const openAdd = () => {
    resetForm();
    showAddModal.value = true;
};

const openEdit = (icp: Icp) => {
    selectedIcp.value = icp;
    icpForm.name = icp.name;
    icpForm.description = icp.description || '';
    icpForm.industries = icp.industries.join(', ');
    icpForm.company_sizes = icp.company_sizes.join(', ');
    icpForm.tech_stack = icp.tech_stack.join(', ');
    icpForm.avg_deal_value = icp.avg_deal_value?.toString() || '';
    icpForm.is_active = icp.is_active;
    showEditModal.value = true;
};

const openDelete = (icp: Icp) => {
    selectedIcp.value = icp;
    showDeleteModal.value = true;
};

const parseCommaSeparated = (value: string): string[] => {
    return value.split(',').map(s => s.trim()).filter(Boolean);
};

const submitAdd = () => {
    isSubmitting.value = true;
    router.post('/icps', {
        name: icpForm.name,
        description: icpForm.description || null,
        industries: parseCommaSeparated(icpForm.industries),
        company_sizes: parseCommaSeparated(icpForm.company_sizes),
        tech_stack: parseCommaSeparated(icpForm.tech_stack),
        buying_signals: parseCommaSeparated(icpForm.buying_signals),
        pain_points: parseCommaSeparated(icpForm.pain_points),
        avg_deal_value: icpForm.avg_deal_value ? parseFloat(icpForm.avg_deal_value) : null,
        weight_industry: icpForm.weight_industry,
        weight_size: icpForm.weight_size,
        weight_tech: icpForm.weight_tech,
        weight_signals: icpForm.weight_signals,
        is_active: icpForm.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showAddModal.value = false;
            resetForm();
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const submitEdit = () => {
    if (!selectedIcp.value) return;
    isSubmitting.value = true;
    router.put(`/icps/${selectedIcp.value.id}`, {
        name: icpForm.name,
        description: icpForm.description || null,
        industries: parseCommaSeparated(icpForm.industries),
        company_sizes: parseCommaSeparated(icpForm.company_sizes),
        tech_stack: parseCommaSeparated(icpForm.tech_stack),
        buying_signals: parseCommaSeparated(icpForm.buying_signals),
        pain_points: parseCommaSeparated(icpForm.pain_points),
        avg_deal_value: icpForm.avg_deal_value ? parseFloat(icpForm.avg_deal_value) : null,
        weight_industry: icpForm.weight_industry,
        weight_size: icpForm.weight_size,
        weight_tech: icpForm.weight_tech,
        weight_signals: icpForm.weight_signals,
        is_active: icpForm.is_active,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showEditModal.value = false;
            selectedIcp.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const confirmDelete = () => {
    if (!selectedIcp.value) return;
    isSubmitting.value = true;
    router.delete(`/icps/${selectedIcp.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            showDeleteModal.value = false;
            selectedIcp.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

const toggleActive = (icp: Icp) => {
    router.post(`/icps/${icp.id}/toggle`, {}, {
        preserveScroll: true,
    });
};

const formatCurrency = (value: number | null) => {
    if (!value) return '-';
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 0,
    }).format(value);
};
</script>

<template>
    <AppLayout title="ICPs">
        <div class="stats-grid">
            <div class="metric-card">
                <div class="metric-label">TOTAL ICPS</div>
                <div class="metric-value">{{ icps.length }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">ACTIVE</div>
                <div class="metric-value">{{ icps.filter(i => i.is_active).length }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">TOTAL PROSPECTS</div>
                <div class="metric-value">{{ icps.reduce((sum, i) => sum + i.prospects_count, 0) }}</div>
            </div>
        </div>

        <div class="toolbar">
            <div class="filters">
                <FormInput
                    v-model="filters.search"
                    placeholder="Search ICPs..."
                    class="search-input"
                />
            </div>
            <button class="btn btn-primary" @click="openAdd">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                Add ICP
            </button>
        </div>

        <div class="card">
            <div class="icp-list">
                <div
                    v-for="icp in filteredIcps"
                    :key="icp.id"
                    class="icp-card"
                    :class="{ inactive: !icp.is_active }"
                >
                    <div class="icp-header">
                        <div class="icp-title">
                            <h3>{{ icp.name }}</h3>
                            <span v-if="!icp.is_active" class="status-badge inactive">Inactive</span>
                        </div>
                        <div class="icp-actions">
                            <button class="action-btn" @click="toggleActive(icp)" :title="icp.is_active ? 'Deactivate' : 'Activate'">
                                <svg v-if="icp.is_active" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                                </svg>
                                <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </button>
                            <button class="action-btn" @click="openEdit(icp)" title="Edit">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125" />
                                </svg>
                            </button>
                            <button class="action-btn action-btn-danger" @click="openDelete(icp)" title="Delete">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                </svg>
                            </button>
                        </div>
                    </div>

                    <p v-if="icp.description" class="icp-description">{{ icp.description }}</p>

                    <div class="icp-meta">
                        <div class="meta-item">
                            <span class="meta-label">Industries</span>
                            <div class="tag-list">
                                <span v-for="industry in icp.industries" :key="industry" class="tag">{{ industry }}</span>
                                <span v-if="icp.industries.length === 0" class="no-data">None specified</span>
                            </div>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Tech Stack</span>
                            <div class="tag-list">
                                <span v-for="tech in icp.tech_stack" :key="tech" class="tag tag-tech">{{ tech }}</span>
                                <span v-if="icp.tech_stack.length === 0" class="no-data">None specified</span>
                            </div>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Avg Deal</span>
                            <span class="meta-value">{{ formatCurrency(icp.avg_deal_value) }}</span>
                        </div>
                        <div class="meta-item">
                            <span class="meta-label">Prospects</span>
                            <span class="meta-value">{{ icp.prospects_count }}</span>
                        </div>
                    </div>

                    <div v-if="icp.prospects_count > 0" class="prospect-stats">
                        <div class="stat-bar">
                            <div class="stat-segment new" :style="{ width: `${(icp.stats.new / icp.prospects_count) * 100}%` }"></div>
                            <div class="stat-segment qualified" :style="{ width: `${(icp.stats.qualified / icp.prospects_count) * 100}%` }"></div>
                            <div class="stat-segment converted" :style="{ width: `${(icp.stats.converted / icp.prospects_count) * 100}%` }"></div>
                        </div>
                        <div class="stat-legend">
                            <span class="legend-item"><span class="dot new"></span>New: {{ icp.stats.new }}</span>
                            <span class="legend-item"><span class="dot qualified"></span>Qualified: {{ icp.stats.qualified }}</span>
                            <span class="legend-item"><span class="dot converted"></span>Converted: {{ icp.stats.converted }}</span>
                        </div>
                    </div>
                </div>

                <div v-if="filteredIcps.length === 0" class="empty-state">
                    <div class="empty-icon">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                        </svg>
                    </div>
                    <p class="empty-title">{{ filters.search ? 'No ICPs match your search' : 'No ICPs defined' }}</p>
                    <p class="empty-subtitle">{{ filters.search ? 'Try a different search term' : 'Create your first Ideal Customer Profile to start targeting prospects' }}</p>
                    <button v-if="!filters.search" class="btn btn-primary mt-4" @click="openAdd">
                        Create ICP
                    </button>
                </div>
            </div>
        </div>

        <Modal :show="showAddModal" size="md" @close="showAddModal = false">
            <template #header>
                <h2 class="modal-title">Create ICP</h2>
            </template>

            <form @submit.prevent="submitAdd">
                <div class="form-section">
                    <h4>Basic Info</h4>
                    <FormInput v-model="icpForm.name" label="Name" placeholder="e.g., Enterprise SaaS" required />
                    <FormTextarea v-model="icpForm.description" label="Description" placeholder="Describe this ideal customer type..." :rows="2" />
                </div>

                <div class="form-section">
                    <h4>Target Criteria</h4>
                    <FormInput v-model="icpForm.industries" label="Industries" placeholder="SaaS, E-commerce, FinTech (comma separated)" />
                    <FormInput v-model="icpForm.company_sizes" label="Company Sizes" placeholder="11-50, 51-200, 201-500 (comma separated)" />
                    <FormInput v-model="icpForm.tech_stack" label="Tech Stack" placeholder="WordPress, React, Laravel (comma separated)" />
                    <FormInput v-model="icpForm.buying_signals" label="Buying Signals" placeholder="recent_funding, hiring_developers (comma separated)" />
                    <FormInput v-model="icpForm.pain_points" label="Pain Points" placeholder="Slow website, Poor SEO (comma separated)" />
                </div>

                <div class="form-section">
                    <h4>Deal Info</h4>
                    <FormInput v-model="icpForm.avg_deal_value" label="Average Deal Value" type="number" placeholder="25000" />
                    <FormToggle v-model="icpForm.is_active" label="Active" description="Active ICPs are used for prospect scoring" />
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showAddModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSubmitting || !icpForm.name || !icpForm.industries"
                        @click="submitAdd"
                    >
                        {{ isSubmitting ? 'Creating...' : 'Create ICP' }}
                    </button>
                </div>
            </template>
        </Modal>

        <Modal :show="showEditModal" size="md" @close="showEditModal = false">
            <template #header>
                <h2 class="modal-title">Edit ICP</h2>
            </template>

            <form @submit.prevent="submitEdit">
                <div class="form-section">
                    <h4>Basic Info</h4>
                    <FormInput v-model="icpForm.name" label="Name" required />
                    <FormTextarea v-model="icpForm.description" label="Description" :rows="2" />
                </div>

                <div class="form-section">
                    <h4>Target Criteria</h4>
                    <FormInput v-model="icpForm.industries" label="Industries" />
                    <FormInput v-model="icpForm.company_sizes" label="Company Sizes" />
                    <FormInput v-model="icpForm.tech_stack" label="Tech Stack" />
                    <FormInput v-model="icpForm.buying_signals" label="Buying Signals" />
                    <FormInput v-model="icpForm.pain_points" label="Pain Points" />
                </div>

                <div class="form-section">
                    <h4>Deal Info</h4>
                    <FormInput v-model="icpForm.avg_deal_value" label="Average Deal Value" type="number" />
                    <FormToggle v-model="icpForm.is_active" label="Active" />
                </div>
            </form>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showEditModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="isSubmitting || !icpForm.name"
                        @click="submitEdit"
                    >
                        {{ isSubmitting ? 'Saving...' : 'Save Changes' }}
                    </button>
                </div>
            </template>
        </Modal>

        <Modal :show="showDeleteModal" size="sm" @close="showDeleteModal = false">
            <template #header>
                <h2 class="modal-title">Delete ICP</h2>
            </template>

            <div class="delete-content">
                <div class="delete-icon">
                    <svg class="w-12 h-12" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                </div>
                <p class="delete-description">Delete <strong>{{ selectedIcp?.name }}</strong>?</p>
                <p class="delete-warning-text">This will remove the ICP and unlink all associated prospects.</p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDeleteModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isSubmitting"
                        @click="confirmDelete"
                    >
                        {{ isSubmitting ? 'Deleting...' : 'Delete ICP' }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.metric-card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 1rem 1.25rem;
}

.metric-label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.25rem;
}

.metric-value {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
}

.filters {
    display: flex;
    gap: 0.75rem;
    flex: 1;
}

.filters :deep(.form-group) {
    margin-bottom: 0;
}

.card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
    padding: 1rem;
}

.icp-list {
    display: grid;
    gap: 1rem;
}

.icp-card {
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    padding: 1.25rem;
    transition: all 0.15s ease;
}

.icp-card:hover {
    border-color: var(--color-border-default);
}

.icp-card.inactive {
    opacity: 0.6;
}

.icp-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 0.75rem;
}

.icp-title {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.icp-title h3 {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin: 0;
}

.status-badge {
    font-size: 0.6875rem;
    font-weight: 500;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
}

.status-badge.inactive {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.icp-actions {
    display: flex;
    gap: 0.25rem;
}

.action-btn {
    width: 32px;
    height: 32px;
    border-radius: 6px;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.15s ease;
}

.action-btn:hover {
    background: var(--color-bg-surface);
    color: var(--color-text-primary);
}

.action-btn-danger:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.icp-description {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
    line-height: 1.5;
}

.icp-meta {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 1rem;
}

.meta-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.meta-label {
    font-size: 0.6875rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
}

.meta-value {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.tag-list {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
}

.tag {
    font-size: 0.6875rem;
    padding: 0.125rem 0.375rem;
    border-radius: 4px;
    background: rgba(139, 92, 246, 0.15);
    color: var(--color-accent);
}

.tag-tech {
    background: rgba(59, 130, 246, 0.15);
    color: var(--color-status-blue);
}

.no-data {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    font-style: italic;
}

.prospect-stats {
    margin-top: 1rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-subtle);
}

.stat-bar {
    height: 6px;
    background: var(--color-bg-surface);
    border-radius: 3px;
    display: flex;
    overflow: hidden;
    margin-bottom: 0.5rem;
}

.stat-segment {
    height: 100%;
}

.stat-segment.new {
    background: var(--color-status-blue);
}

.stat-segment.qualified {
    background: var(--color-status-yellow);
}

.stat-segment.converted {
    background: var(--color-status-green);
}

.stat-legend {
    display: flex;
    gap: 1rem;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.25rem;
}

.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}

.dot.new { background: var(--color-status-blue); }
.dot.qualified { background: var(--color-status-yellow); }
.dot.converted { background: var(--color-status-green); }

.empty-state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 3rem;
}

.empty-icon {
    width: 56px;
    height: 56px;
    border-radius: 12px;
    background: var(--color-bg-elevated);
    display: flex;
    align-items: center;
    justify-content: center;
    color: var(--color-text-tertiary);
    margin-bottom: 1rem;
}

.empty-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.empty-subtitle {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
    text-align: center;
}

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

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}

.delete-content {
    text-align: center;
    padding: 1rem 0;
}

.delete-icon {
    color: var(--color-status-red);
    margin-bottom: 1rem;
    display: flex;
    justify-content: center;
}

.delete-description {
    font-size: 0.9375rem;
    color: var(--color-text-primary);
    margin-bottom: 0.5rem;
}

.delete-warning-text {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

.btn {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
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

.mt-4 {
    margin-top: 1rem;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }

    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .icp-meta {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>
