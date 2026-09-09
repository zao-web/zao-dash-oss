<script setup lang="ts">
import { ref, reactive, computed, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormTextarea from '@/Components/FormTextarea.vue';
import FormCheckbox from '@/Components/FormCheckbox.vue';

interface Agent {
    id: number;
    name: string;
    slug: string;
}

interface ApprovalRequest {
    id: number;
    action_type: string;
    description: string;
    risk_level: 'low' | 'medium' | 'high' | 'critical';
    status: 'pending' | 'approved' | 'rejected' | 'expired';
    agent: Agent | null;
    run_id: number | null;
    payload_preview: string | null;
    decided_by: string | null;
    decision_note: string | null;
    expires_at: string | null;
    expires_at_raw: string | null;
    decided_at: string | null;
    created_at: string;
    is_expiring_soon: boolean;
}

interface PaginatedData {
    data: ApprovalRequest[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: Array<{ url: string | null; label: string; active: boolean }>;
}

interface Stats {
    pending: number;
    high_risk_pending: number;
    approved_today: number;
    rejected_today: number;
    expired: number;
    expiring_soon: number;
}

interface Filters {
    status: string | null;
    risk_level: string | null;
    agent_id: number | null;
    action_type: string | null;
}

const props = defineProps<{
    approvals: PaginatedData;
    stats: Stats;
    filters: Filters;
    agents: Agent[];
    action_types: string[];
}>();

// Local filter state synced with server
const localFilters = reactive({
    status: props.filters.status ?? 'pending',
    risk_level: props.filters.risk_level || '',
    agent_id: props.filters.agent_id || '',
    action_type: props.filters.action_type || '',
});

// Selected for batch actions
const selectedIds = ref<number[]>([]);
const selectAll = ref(false);

// Modal states
const showDetailModal = ref(false);
const showApproveModal = ref(false);
const showRejectModal = ref(false);
const showBatchModal = ref(false);

const selectedApproval = ref<ApprovalRequest | null>(null);
const approvalComment = ref('');
const rejectReason = ref('');
const batchAction = ref<'approve' | 'reject'>('approve');

const isSubmitting = ref(false);

// Apply filters via server-side request
const applyFilters = () => {
    router.get('/approvals', {
        status: localFilters.status || undefined,
        risk_level: localFilters.risk_level || undefined,
        agent_id: localFilters.agent_id || undefined,
        action_type: localFilters.action_type || undefined,
    }, { preserveState: true, preserveScroll: true });
};

// Debounced filter application
let filterTimeout: ReturnType<typeof setTimeout>;
const debouncedApply = () => {
    clearTimeout(filterTimeout);
    filterTimeout = setTimeout(applyFilters, 300);
};

// Approval data from pagination
const approvalList = computed(() => props.approvals.data);

// Pending approvals for batch actions
const pendingApprovals = computed(() => approvalList.value.filter(a => a.status === 'pending'));

// Toggle select all
const toggleSelectAll = () => {
    if (selectAll.value) {
        selectedIds.value = pendingApprovals.value.map(a => a.id);
    } else {
        selectedIds.value = [];
    }
};

// Toggle single selection
const toggleSelect = (id: number) => {
    const index = selectedIds.value.indexOf(id);
    if (index > -1) {
        selectedIds.value.splice(index, 1);
    } else {
        selectedIds.value.push(id);
    }
    selectAll.value = selectedIds.value.length === pendingApprovals.value.length && pendingApprovals.value.length > 0;
};

// Open detail modal
const openDetail = (approval: ApprovalRequest) => {
    selectedApproval.value = approval;
    showDetailModal.value = true;
};

// Open approve modal
const openApprove = (approval: ApprovalRequest) => {
    selectedApproval.value = approval;
    approvalComment.value = '';
    showApproveModal.value = true;
};

// Open reject modal
const openReject = (approval: ApprovalRequest) => {
    selectedApproval.value = approval;
    rejectReason.value = '';
    showRejectModal.value = true;
};

// Open batch action modal
const openBatch = (action: 'approve' | 'reject') => {
    batchAction.value = action;
    approvalComment.value = '';
    rejectReason.value = '';
    showBatchModal.value = true;
};

// Submit approval
const submitApprove = () => {
    if (!selectedApproval.value) return;
    isSubmitting.value = true;
    router.post(`/approvals/${selectedApproval.value.id}/approve`, {
        comment: approvalComment.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showApproveModal.value = false;
            showDetailModal.value = false;
            selectedApproval.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Submit rejection
const submitReject = () => {
    if (!selectedApproval.value || !rejectReason.value.trim()) return;
    isSubmitting.value = true;
    router.post(`/approvals/${selectedApproval.value.id}/reject`, {
        reason: rejectReason.value,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            showRejectModal.value = false;
            showDetailModal.value = false;
            selectedApproval.value = null;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Submit batch action
const submitBatch = () => {
    if (selectedIds.value.length === 0) return;
    if (batchAction.value === 'reject' && !rejectReason.value.trim()) return;

    isSubmitting.value = true;
    const endpoint = batchAction.value === 'approve' ? '/approvals/batch-approve' : '/approvals/batch-reject';
    const payload = batchAction.value === 'approve'
        ? { ids: selectedIds.value, comment: approvalComment.value }
        : { ids: selectedIds.value, reason: rejectReason.value };

    router.post(endpoint, payload, {
        preserveScroll: true,
        onSuccess: () => {
            showBatchModal.value = false;
            selectedIds.value = [];
            selectAll.value = false;
        },
        onFinish: () => {
            isSubmitting.value = false;
        },
    });
};

// Quick approve (single click)
const quickApprove = (approval: ApprovalRequest) => {
    router.post(`/approvals/${approval.id}/approve`, {
        comment: '',
    }, {
        preserveScroll: true,
    });
};

// Risk badge
const getRiskBadge = (risk: string) => {
    const badges: Record<string, string> = {
        low: 'badge-green',
        medium: 'badge-yellow',
        high: 'badge-red',
        critical: 'badge-red',
    };
    return badges[risk] || 'badge-yellow';
};

// Status badge
const getStatusBadge = (status: string) => {
    const badges: Record<string, string> = {
        pending: 'badge-yellow',
        approved: 'badge-green',
        rejected: 'badge-red',
        expired: 'badge-gray',
    };
    return badges[status] || 'badge-gray';
};

// Risk description
const getRiskDescription = (risk: string) => {
    const descriptions: Record<string, string> = {
        low: 'This action has minimal risk and can likely be auto-approved.',
        medium: 'This action may have some impact. Review before approving.',
        high: 'This action could have significant impact. Review carefully.',
        critical: 'This action could have irreversible effects. Verify all details.',
    };
    return descriptions[risk] || '';
};

// Time remaining until expiration
const getTimeRemaining = (expiresAt: string | null) => {
    if (!expiresAt) return 'Never expires';
    const now = new Date();
    const expires = new Date(expiresAt);
    const diff = expires.getTime() - now.getTime();
    if (diff <= 0) return 'Expired';
    const hours = Math.floor(diff / (1000 * 60 * 60));
    const minutes = Math.floor((diff % (1000 * 60 * 60)) / (1000 * 60));
    if (hours > 24) return `${Math.floor(hours / 24)}d remaining`;
    if (hours > 0) return `${hours}h ${minutes}m remaining`;
    return `${minutes}m remaining`;
};
</script>

<template>
    <AppLayout title="Approvals">
        <!-- Stats -->
        <div class="stats-grid">
            <div class="metric-card">
                <div class="metric-label">PENDING</div>
                <div class="metric-row">
                    <span class="metric-value">{{ stats.pending }}</span>
                    <div v-if="stats.pending > 0" class="status-dot pending"></div>
                </div>
                <div v-if="stats.high_risk_pending > 0" class="metric-sub text-red">
                    {{ stats.high_risk_pending }} high risk
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">EXPIRING SOON</div>
                <div class="metric-row">
                    <span class="metric-value" :class="{ 'text-amber': stats.expiring_soon > 0 }">{{ stats.expiring_soon }}</span>
                    <div v-if="stats.expiring_soon > 0" class="status-dot expiring"></div>
                </div>
            </div>
            <div class="metric-card">
                <div class="metric-label">APPROVED TODAY</div>
                <div class="metric-value text-green">{{ stats.approved_today }}</div>
            </div>
            <div class="metric-card">
                <div class="metric-label">REJECTED TODAY</div>
                <div class="metric-value text-red">{{ stats.rejected_today }}</div>
            </div>
        </div>

        <!-- Filters & Batch Actions Bar -->
        <div class="toolbar">
            <div class="filters">
                <FormSelect
                    v-model="localFilters.status"
                    @update:model-value="applyFilters"
                    :options="[
                        { value: 'all', label: 'All Status' },
                        { value: 'pending', label: 'Pending' },
                        { value: 'approved', label: 'Approved' },
                        { value: 'rejected', label: 'Rejected' },
                        { value: 'expired', label: 'Expired' },
                    ]"
                />
                <FormSelect
                    v-model="localFilters.risk_level"
                    @update:model-value="applyFilters"
                    :options="[
                        { value: '', label: 'All Risk' },
                        { value: 'low', label: 'Low Risk' },
                        { value: 'medium', label: 'Medium Risk' },
                        { value: 'high', label: 'High Risk' },
                        { value: 'critical', label: 'Critical' },
                    ]"
                />
                <FormSelect
                    v-model="localFilters.agent_id"
                    @update:model-value="applyFilters"
                    :options="[{ value: '', label: 'All Agents' }, ...agents.map(a => ({ value: a.id, label: a.name }))]"
                />
                <FormSelect
                    v-if="action_types.length > 0"
                    v-model="localFilters.action_type"
                    @update:model-value="applyFilters"
                    :options="[{ value: '', label: 'All Types' }, ...action_types.map(t => ({ value: t, label: t }))]"
                />
            </div>
            <div v-if="selectedIds.length > 0" class="batch-actions">
                <span class="selected-count">{{ selectedIds.length }} selected</span>
                <button class="btn btn-success btn-sm" @click="openBatch('approve')">
                    Approve Selected
                </button>
                <button class="btn btn-secondary btn-sm" @click="openBatch('reject')">
                    Reject Selected
                </button>
            </div>
        </div>

        <!-- Approvals List -->
        <div class="card">
            <div class="card-header">
                <div class="header-left">
                    <div v-if="pendingApprovals.length > 0" class="select-all">
                        <FormCheckbox
                            v-model="selectAll"
                            label="Select All"
                            @update:model-value="toggleSelectAll"
                        />
                    </div>
                    <span class="card-title">Approval Requests</span>
                    <span class="count-badge">{{ approvals.total }}</span>
                </div>
            </div>

            <div class="card-body">
                <div
                    v-for="approval in approvalList"
                    :key="approval.id"
                    class="approval-item"
                    :class="{ selected: selectedIds.includes(approval.id), 'expiring-soon': approval.is_expiring_soon }"
                    @click="openDetail(approval)"
                >
                    <div class="item-checkbox" v-if="approval.status === 'pending'" @click.stop>
                        <FormCheckbox
                            :model-value="selectedIds.includes(approval.id)"
                            @update:model-value="toggleSelect(approval.id)"
                        />
                    </div>

                    <div class="item-content">
                        <div class="item-header">
                            <div class="item-badges">
                                <span class="approval-type">{{ approval.action_type }}</span>
                                <span :class="['badge', getRiskBadge(approval.risk_level)]">{{ approval.risk_level }}</span>
                                <span :class="['badge', getStatusBadge(approval.status)]">{{ approval.status }}</span>
                                <span v-if="approval.is_expiring_soon && approval.status === 'pending'" class="badge badge-amber">
                                    expiring soon
                                </span>
                            </div>
                            <div class="item-meta">
                                <span v-if="approval.status === 'pending'" class="expiry" :class="{ urgent: approval.is_expiring_soon }">
                                    {{ approval.expires_at || 'No expiry' }}
                                </span>
                                <span class="timestamp">{{ approval.created_at }}</span>
                            </div>
                        </div>

                        <p class="approval-summary">{{ approval.description }}</p>

                        <div class="item-footer">
                            <span v-if="approval.agent" class="agent-info">
                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                                </svg>
                                {{ approval.agent.name }}
                            </span>
                            <span v-if="approval.decided_by" class="decided-info">
                                {{ approval.status === 'approved' ? 'Approved' : 'Rejected' }} by {{ approval.decided_by }} · {{ approval.decided_at }}
                            </span>
                        </div>

                        <!-- Preview -->
                        <div v-if="approval.payload_preview" class="payload-preview">
                            <code>{{ approval.payload_preview }}</code>
                        </div>

                        <!-- Decision note for processed approvals -->
                        <div v-if="approval.decision_note && approval.status !== 'pending'" class="decision-note">
                            <strong>Note:</strong> {{ approval.decision_note }}
                        </div>

                        <div v-if="approval.status === 'pending'" class="approval-actions" @click.stop>
                            <button
                                v-if="approval.risk_level === 'low'"
                                class="btn btn-success"
                                @click="quickApprove(approval)"
                            >
                                Quick Approve
                            </button>
                            <button class="btn btn-success" @click="openApprove(approval)">
                                Approve
                            </button>
                            <button class="btn btn-secondary" @click="openReject(approval)">
                                Reject
                            </button>
                        </div>
                    </div>
                </div>

                <div v-if="approvalList.length === 0" class="empty-state">
                    <div class="empty-icon">
                        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    </div>
                    <p class="empty-title">All caught up!</p>
                    <p class="empty-description">No approval requests match your filters</p>
                </div>
            </div>

            <!-- Pagination -->
            <div v-if="approvals.last_page > 1" class="card-footer pagination">
                <div class="pagination-info">
                    Showing {{ (approvals.current_page - 1) * approvals.per_page + 1 }}–{{ Math.min(approvals.current_page * approvals.per_page, approvals.total) }} of {{ approvals.total }}
                </div>
                <div class="pagination-links">
                    <Link
                        v-for="link in approvals.links"
                        :key="link.label"
                        :href="link.url || ''"
                        class="pagination-link"
                        :class="{ active: link.active, disabled: !link.url }"
                        v-html="link.label"
                        preserve-scroll
                    />
                </div>
            </div>
        </div>

        <!-- Detail Modal -->
        <Modal :show="showDetailModal" size="lg" @close="showDetailModal = false">
            <template #header>
                <div class="detail-header">
                    <h2 class="modal-title">Approval Request</h2>
                    <div class="header-badges">
                        <span :class="['badge', getRiskBadge(selectedApproval?.risk_level || 'low')]">
                            {{ selectedApproval?.risk_level }}
                        </span>
                        <span :class="['badge', getStatusBadge(selectedApproval?.status || 'pending')]">
                            {{ selectedApproval?.status }}
                        </span>
                    </div>
                </div>
            </template>

            <div v-if="selectedApproval" class="detail-content">
                <!-- Risk Warning -->
                <div v-if="selectedApproval.risk_level === 'high' || selectedApproval.risk_level === 'critical'" class="risk-warning">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <span>{{ getRiskDescription(selectedApproval.risk_level) }}</span>
                </div>

                <!-- Action Info -->
                <div class="detail-section">
                    <h4>Action Details</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Type</span>
                            <span class="detail-value">{{ selectedApproval.action_type }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Agent</span>
                            <span class="detail-value">{{ selectedApproval.agent?.name || 'Unknown' }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Requested</span>
                            <span class="detail-value">{{ selectedApproval.created_at }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Expires</span>
                            <span class="detail-value" :class="{ urgent: selectedApproval.is_expiring_soon }">
                                {{ selectedApproval.expires_at || 'No expiry' }}
                            </span>
                        </div>
                    </div>
                </div>

                <!-- Decision Info (for processed approvals) -->
                <div v-if="selectedApproval.status !== 'pending'" class="detail-section">
                    <h4>Decision</h4>
                    <div class="detail-grid">
                        <div class="detail-item">
                            <span class="detail-label">Decided By</span>
                            <span class="detail-value">{{ selectedApproval.decided_by || 'System' }}</span>
                        </div>
                        <div class="detail-item">
                            <span class="detail-label">Decided At</span>
                            <span class="detail-value">{{ selectedApproval.decided_at || 'N/A' }}</span>
                        </div>
                    </div>
                    <div v-if="selectedApproval.decision_note" class="decision-note-detail">
                        <span class="detail-label">Note</span>
                        <p>{{ selectedApproval.decision_note }}</p>
                    </div>
                </div>

                <!-- Description -->
                <div class="detail-section">
                    <h4>Description</h4>
                    <p class="description-text">{{ selectedApproval.description }}</p>
                </div>

                <!-- Payload Preview -->
                <div v-if="selectedApproval.payload_preview" class="detail-section">
                    <h4>Payload Preview</h4>
                    <div class="payload-full">
                        <pre>{{ selectedApproval.payload_preview }}</pre>
                    </div>
                </div>

                <!-- View Full Details Link -->
                <div v-if="selectedApproval.run_id" class="detail-section">
                    <Link :href="`/approvals/${selectedApproval.id}`" class="view-full-link">
                        View full approval details →
                    </Link>
                </div>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showDetailModal = false">Close</button>
                    <template v-if="selectedApproval?.status === 'pending'">
                        <button type="button" class="btn btn-secondary" @click="openReject(selectedApproval!)">Reject</button>
                        <button type="button" class="btn btn-success" @click="openApprove(selectedApproval!)">Approve</button>
                    </template>
                </div>
            </template>
        </Modal>

        <!-- Approve Modal -->
        <Modal :show="showApproveModal" size="sm" @close="showApproveModal = false">
            <template #header>
                <h2 class="modal-title">Approve Request</h2>
            </template>

            <div class="approve-content">
                <p class="approve-description">
                    Approve this <strong>{{ selectedApproval?.action_type }}</strong> request from <strong>{{ selectedApproval?.agent?.name || 'Unknown Agent' }}</strong>?
                </p>

                <FormTextarea
                    v-model="approvalComment"
                    label="Comment (optional)"
                    placeholder="Add a note about this approval..."
                    :rows="3"
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showApproveModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-success"
                        :disabled="isSubmitting"
                        @click="submitApprove"
                    >
                        {{ isSubmitting ? 'Approving...' : 'Approve' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Reject Modal -->
        <Modal :show="showRejectModal" size="sm" @close="showRejectModal = false">
            <template #header>
                <h2 class="modal-title">Reject Request</h2>
            </template>

            <div class="reject-content">
                <p class="reject-description">
                    Reject this <strong>{{ selectedApproval?.action_type }}</strong> request from <strong>{{ selectedApproval?.agent?.name || 'Unknown Agent' }}</strong>?
                </p>

                <FormTextarea
                    v-model="rejectReason"
                    label="Reason"
                    placeholder="Explain why this request is being rejected..."
                    :rows="3"
                    required
                />
                <p class="help-text">The agent will use this feedback to improve future requests.</p>
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showRejectModal = false">Cancel</button>
                    <button
                        type="button"
                        class="btn btn-danger"
                        :disabled="isSubmitting || !rejectReason.trim()"
                        @click="submitReject"
                    >
                        {{ isSubmitting ? 'Rejecting...' : 'Reject' }}
                    </button>
                </div>
            </template>
        </Modal>

        <!-- Batch Action Modal -->
        <Modal :show="showBatchModal" size="sm" @close="showBatchModal = false">
            <template #header>
                <h2 class="modal-title">{{ batchAction === 'approve' ? 'Batch Approve' : 'Batch Reject' }}</h2>
            </template>

            <div class="batch-content">
                <p class="batch-description">
                    {{ batchAction === 'approve' ? 'Approve' : 'Reject' }} <strong>{{ selectedIds.length }}</strong> selected requests?
                </p>

                <div class="selected-preview">
                    <div v-for="id in selectedIds.slice(0, 5)" :key="id" class="selected-item">
                        {{ approvalList.find(a => a.id === id)?.action_type }} - {{ approvalList.find(a => a.id === id)?.agent?.name || 'Unknown' }}
                    </div>
                    <div v-if="selectedIds.length > 5" class="more-items">
                        +{{ selectedIds.length - 5 }} more
                    </div>
                </div>

                <FormTextarea
                    v-if="batchAction === 'approve'"
                    v-model="approvalComment"
                    label="Comment (optional)"
                    placeholder="Add a note about these approvals..."
                    :rows="2"
                />
                <FormTextarea
                    v-else
                    v-model="rejectReason"
                    label="Reason"
                    placeholder="Explain why these requests are being rejected..."
                    :rows="2"
                    required
                />
            </div>

            <template #footer>
                <div class="modal-actions">
                    <button type="button" class="btn btn-secondary" @click="showBatchModal = false">Cancel</button>
                    <button
                        v-if="batchAction === 'approve'"
                        type="button"
                        class="btn btn-success"
                        :disabled="isSubmitting"
                        @click="submitBatch"
                    >
                        {{ isSubmitting ? 'Approving...' : `Approve ${selectedIds.length}` }}
                    </button>
                    <button
                        v-else
                        type="button"
                        class="btn btn-danger"
                        :disabled="isSubmitting || !rejectReason.trim()"
                        @click="submitBatch"
                    >
                        {{ isSubmitting ? 'Rejecting...' : `Reject ${selectedIds.length}` }}
                    </button>
                </div>
            </template>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.stats-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
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

.metric-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.text-green {
    color: var(--color-status-green);
}

.text-red {
    color: var(--color-status-red);
}

.status-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    animation: pulse 2s infinite;
}

.status-dot.pending {
    background: var(--color-status-yellow);
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.5; }
}

/* Toolbar */
.toolbar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 1rem;
    margin-bottom: 1rem;
    flex-wrap: wrap;
}

.filters {
    display: flex;
    gap: 0.75rem;
    flex-wrap: wrap;
    flex: 1;
}

.filters :deep(.form-group) {
    margin-bottom: 0;
}

.search-input :deep(input) {
    min-width: 200px;
}

.batch-actions {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.selected-count {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    font-weight: 500;
}

/* Card */
.card {
    background: var(--color-bg-surface);
    border: 1px solid var(--color-border-default);
    border-radius: 8px;
}

.card-header {
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.header-left {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.select-all {
    display: flex;
    align-items: center;
}

.select-all :deep(.checkbox-group) {
    margin-bottom: 0;
}

.select-all :deep(.checkbox-label) {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}

.card-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.count-badge {
    font-size: 0.75rem;
    padding: 0.125rem 0.5rem;
    background: var(--color-bg-elevated);
    border-radius: 10px;
    color: var(--color-text-tertiary);
}

.card-body {
    padding: 0;
}

/* Approval Item */
.approval-item {
    display: flex;
    gap: 1rem;
    padding: 1rem 1.25rem;
    border-bottom: 1px solid var(--color-border-subtle);
    cursor: pointer;
    transition: background 0.15s ease;
}

.approval-item:last-child {
    border-bottom: none;
}

.approval-item:hover {
    background: var(--color-bg-elevated);
}

.approval-item.selected {
    background: rgba(139, 92, 246, 0.05);
}

.item-checkbox {
    display: flex;
    align-items: flex-start;
    padding-top: 0.25rem;
}

.item-checkbox :deep(.checkbox-group) {
    margin-bottom: 0;
}

.item-content {
    flex: 1;
    min-width: 0;
}

.item-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 1rem;
    margin-bottom: 0.5rem;
}

.item-badges {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex-wrap: wrap;
}

.approval-type {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.badge {
    font-size: 0.6875rem;
    padding: 0.125rem 0.5rem;
    border-radius: 4px;
    font-weight: 500;
    text-transform: capitalize;
}

.badge-green {
    background: rgba(34, 197, 94, 0.15);
    color: var(--color-status-green);
}

.badge-yellow {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.badge-red {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.badge-gray {
    background: var(--color-bg-elevated);
    color: var(--color-text-tertiary);
}

.item-meta {
    display: flex;
    flex-direction: column;
    align-items: flex-end;
    gap: 0.25rem;
}

.expiry {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.expiry.urgent {
    color: var(--color-status-red);
    font-weight: 500;
}

.timestamp {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.approval-summary {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.5;
    margin-bottom: 0.5rem;
}

.item-footer {
    display: flex;
    align-items: center;
    gap: 1rem;
    margin-bottom: 0.75rem;
}

.agent-info,
.project-info {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.payload-preview {
    background: var(--color-bg-elevated);
    border-radius: 6px;
    padding: 0.75rem;
    margin-bottom: 0.75rem;
    overflow-x: auto;
}

.payload-preview code {
    font-size: 0.75rem;
    font-family: 'SF Mono', monospace;
    color: var(--color-text-secondary);
    white-space: pre;
}

.approval-actions {
    display: flex;
    gap: 0.5rem;
}

/* Empty State */
.empty-state {
    padding: 3rem;
    text-align: center;
}

.empty-icon {
    width: 48px;
    height: 48px;
    margin: 0 auto 1rem;
    display: flex;
    align-items: center;
    justify-content: center;
    background: rgba(34, 197, 94, 0.1);
    border-radius: 12px;
    color: var(--color-status-green);
}

.empty-title {
    font-size: 0.9375rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 0.25rem;
}

.empty-description {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

/* Detail Modal */
.detail-header {
    display: flex;
    align-items: center;
    gap: 1rem;
}

.header-badges {
    display: flex;
    gap: 0.5rem;
}

.modal-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.detail-content {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.risk-warning {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.875rem 1rem;
    background: rgba(239, 68, 68, 0.1);
    border: 1px solid rgba(239, 68, 68, 0.2);
    border-radius: 8px;
    color: var(--color-status-red);
    font-size: 0.8125rem;
}

.detail-section h4 {
    font-size: 0.75rem;
    font-weight: 600;
    color: var(--color-text-tertiary);
    text-transform: uppercase;
    letter-spacing: 0.05em;
    margin-bottom: 0.75rem;
}

.detail-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.detail-item {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.detail-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.detail-value {
    font-size: 0.875rem;
    color: var(--color-text-primary);
    font-weight: 500;
}

.detail-value.urgent {
    color: var(--color-status-red);
}

.description-text,
.context-text {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    line-height: 1.6;
}

.payload-full {
    background: var(--color-bg-elevated);
    border-radius: 8px;
    padding: 1rem;
    overflow-x: auto;
    max-height: 300px;
}

.payload-full pre {
    font-size: 0.75rem;
    font-family: 'SF Mono', monospace;
    color: var(--color-text-secondary);
    margin: 0;
}

.history-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.history-item {
    display: flex;
    gap: 0.75rem;
}

.history-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    margin-top: 0.375rem;
    flex-shrink: 0;
}

.history-dot.approved {
    background: var(--color-status-green);
}

.history-dot.rejected {
    background: var(--color-status-red);
}

.history-dot.created {
    background: var(--color-accent);
}

.history-content {
    flex: 1;
}

.history-action {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
    text-transform: capitalize;
}

.history-meta {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-left: 0.5rem;
}

.history-comment {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

/* Approve/Reject Modal */
.approve-content,
.reject-content,
.batch-content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.approve-description,
.reject-description,
.batch-description {
    font-size: 0.9375rem;
    color: var(--color-text-primary);
}

.help-text {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: -0.5rem;
}

.selected-preview {
    background: var(--color-bg-elevated);
    border-radius: 6px;
    padding: 0.75rem;
}

.selected-item {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    padding: 0.25rem 0;
}

.more-items {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    padding-top: 0.5rem;
    border-top: 1px solid var(--color-border-subtle);
    margin-top: 0.5rem;
}

/* Modal Actions */
.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
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

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-success {
    background: var(--color-status-green);
    color: white;
}

.btn-success:hover:not(:disabled) {
    background: #16a34a;
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

/* Responsive */
@media (max-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(2, 1fr);
    }

    .toolbar {
        flex-direction: column;
        align-items: stretch;
    }

    .filters {
        flex-direction: column;
    }

    .batch-actions {
        justify-content: flex-end;
    }

    .item-header {
        flex-direction: column;
        gap: 0.5rem;
    }

    .item-meta {
        align-items: flex-start;
        flex-direction: row;
        gap: 1rem;
    }

    .detail-grid {
        grid-template-columns: 1fr;
    }
}

/* New styles for enhanced UI */
.metric-sub {
    font-size: 0.6875rem;
    margin-top: 0.25rem;
}

.text-amber {
    color: var(--color-status-yellow);
}

.status-dot.expiring {
    background: var(--color-status-yellow);
}

.badge-amber {
    background: rgba(234, 179, 8, 0.15);
    color: var(--color-status-yellow);
}

.approval-item.expiring-soon {
    border-left: 3px solid var(--color-status-yellow);
}

.decided-info {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.decision-note {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    background: var(--color-bg-elevated);
    padding: 0.5rem 0.75rem;
    border-radius: 6px;
    margin-top: 0.5rem;
}

.decision-note-detail {
    margin-top: 0.75rem;
}

.decision-note-detail p {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

.view-full-link {
    color: var(--color-accent);
    font-size: 0.875rem;
    text-decoration: none;
}

.view-full-link:hover {
    text-decoration: underline;
}

/* Pagination */
.card-footer.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 0.75rem 1.25rem;
    border-top: 1px solid var(--color-border-subtle);
}

.pagination-info {
    font-size: 0.8125rem;
    color: var(--color-text-tertiary);
}

.pagination-links {
    display: flex;
    gap: 0.25rem;
}

.pagination-link {
    padding: 0.375rem 0.75rem;
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
    background: var(--color-bg-elevated);
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    text-decoration: none;
    transition: all 0.15s ease;
}

.pagination-link:hover:not(.disabled):not(.active) {
    background: var(--color-bg-surface);
    border-color: var(--color-border-hover);
}

.pagination-link.active {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.pagination-link.disabled {
    opacity: 0.5;
    pointer-events: none;
}
</style>
