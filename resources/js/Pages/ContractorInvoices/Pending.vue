<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface Invoice {
    id: number;
    uuid: string;
    invoice_number: string;
    contractor: {
        id: number;
        name: string;
        company_name: string | null;
        is_us_person: boolean;
        has_w9_on_file: boolean;
    };
    invoice_date: string;
    due_date: string | null;
    amount: number;
    currency: string;
    description: string;
    line_items: any[] | null;
    attachments: any[] | null;
    status: string;
    is_overdue: boolean;
    created_at: string;
}

const props = defineProps<{
    invoices: Invoice[];
    stats: {
        submitted: number;
        approved: number;
        total_pending_amount: number;
    };
}>();

const showRejectModal = ref(false);
const selectedInvoice = ref<Invoice | null>(null);
const rejectionReason = ref('');
const isProcessing = ref(false);

const formatCurrency = (amount: number, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
};

const approve = (invoice: Invoice) => {
    isProcessing.value = true;
    router.post(`/contractor-invoices/${invoice.id}/approve`, {}, {
        onFinish: () => {
            isProcessing.value = false;
        },
    });
};

const openRejectModal = (invoice: Invoice) => {
    selectedInvoice.value = invoice;
    rejectionReason.value = '';
    showRejectModal.value = true;
};

const reject = () => {
    if (!selectedInvoice.value || !rejectionReason.value) return;

    isProcessing.value = true;
    router.post(`/contractor-invoices/${selectedInvoice.value.id}/reject`, {
        reason: rejectionReason.value,
    }, {
        onSuccess: () => {
            showRejectModal.value = false;
            selectedInvoice.value = null;
        },
        onFinish: () => {
            isProcessing.value = false;
        },
    });
};

const pay = (invoice: Invoice) => {
    if (confirm(`Pay ${formatCurrency(invoice.amount)} to ${invoice.contractor.name}? This will create an approval request for the wire transfer.`)) {
        isProcessing.value = true;
        router.post(`/contractor-invoices/${invoice.id}/pay`, {}, {
            onFinish: () => {
                isProcessing.value = false;
            },
        });
    }
};
</script>

<template>
    <AppLayout title="Pending Invoices">
        <div class="pending-page">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Contractor Invoices</h1>
                    <p class="page-subtitle">Review and approve invoices for payment</p>
                </div>
                <Link href="/contractors" class="view-link">
                    View All Contractors
                </Link>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value status-blue">{{ stats.submitted }}</div>
                    <div class="stat-label">Awaiting Approval</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-green">{{ stats.approved }}</div>
                    <div class="stat-label">Approved (Ready to Pay)</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-accent">{{ formatCurrency(stats.total_pending_amount) }}</div>
                    <div class="stat-label">Total Pending</div>
                </div>
            </div>

            <!-- Invoices Table -->
            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Contractor</th>
                            <th>Invoice</th>
                            <th>Amount</th>
                            <th>Due</th>
                            <th>Status</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices" :key="invoice.id">
                            <td>
                                <Link :href="`/contractors/${invoice.contractor.id}`" class="contractor-link">
                                    <div class="contractor-name">{{ invoice.contractor.name }}</div>
                                    <div v-if="invoice.contractor.company_name" class="contractor-company">{{ invoice.contractor.company_name }}</div>
                                </Link>
                            </td>
                            <td>
                                <div class="invoice-number">{{ invoice.invoice_number || `INV-${invoice.id}` }}</div>
                                <div class="invoice-desc">{{ invoice.description?.slice(0, 50) }}...</div>
                            </td>
                            <td class="invoice-amount">
                                {{ formatCurrency(invoice.amount, invoice.currency) }}
                            </td>
                            <td>
                                <span :class="invoice.is_overdue ? 'due-overdue' : 'due-normal'">
                                    {{ invoice.due_date || '-' }}
                                    <span v-if="invoice.is_overdue" class="overdue-label">OVERDUE</span>
                                </span>
                            </td>
                            <td>
                                <span v-if="invoice.status === 'submitted'" class="badge badge-submitted">
                                    Awaiting Approval
                                </span>
                                <span v-else-if="invoice.status === 'approved'" class="badge badge-approved">
                                    Approved
                                </span>
                            </td>
                            <td class="actions-cell">
                                <template v-if="invoice.status === 'submitted'">
                                    <button
                                        @click="approve(invoice)"
                                        :disabled="isProcessing"
                                        class="btn btn-approve"
                                    >
                                        Approve
                                    </button>
                                    <button
                                        @click="openRejectModal(invoice)"
                                        :disabled="isProcessing"
                                        class="btn btn-reject"
                                    >
                                        Reject
                                    </button>
                                </template>
                                <template v-else-if="invoice.status === 'approved'">
                                    <button
                                        @click="pay(invoice)"
                                        :disabled="isProcessing"
                                        class="btn btn-pay"
                                    >
                                        Pay Now
                                    </button>
                                </template>
                            </td>
                        </tr>
                        <tr v-if="invoices.length === 0">
                            <td colspan="6" class="empty-state">
                                <div class="empty-title">No pending invoices</div>
                                <div class="empty-subtitle">All contractor invoices have been processed</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Reject Modal -->
        <Modal :show="showRejectModal" @close="showRejectModal = false">
            <div class="modal-content">
                <h3 class="modal-title">Reject Invoice</h3>
                <p class="modal-subtitle">
                    Rejecting invoice <strong>{{ selectedInvoice?.invoice_number }}</strong> from <strong>{{ selectedInvoice?.contractor?.name }}</strong>
                </p>
                <div class="form-group">
                    <label class="form-label">Reason for rejection</label>
                    <textarea
                        v-model="rejectionReason"
                        rows="3"
                        class="form-textarea"
                        placeholder="Explain why this invoice is being rejected..."
                        required
                    ></textarea>
                </div>
                <div class="modal-actions">
                    <button @click="showRejectModal = false" class="btn btn-secondary">Cancel</button>
                    <button
                        @click="reject"
                        :disabled="!rejectionReason || isProcessing"
                        class="btn btn-reject"
                    >
                        {{ isProcessing ? 'Rejecting...' : 'Reject Invoice' }}
                    </button>
                </div>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.pending-page {
    max-width: 80rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.page-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.view-link {
    font-size: 0.875rem;
    color: var(--color-accent);
}

.view-link:hover {
    opacity: 0.8;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

.stat-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    padding: 1rem;
}

.stat-value {
    font-size: 1.5rem;
    font-weight: 700;
}

.stat-value.status-blue { color: var(--color-accent); }
.stat-value.status-green { color: var(--color-status-green); }
.stat-value.status-accent { color: var(--color-accent); }

.stat-label {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.table-card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    overflow: hidden;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    padding: 0.75rem 1.5rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 500;
    text-transform: uppercase;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.data-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.data-table tbody tr:hover {
    background: var(--color-bg-tertiary);
}

.contractor-link {
    display: block;
}

.contractor-link:hover .contractor-name {
    color: var(--color-accent);
}

.contractor-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.contractor-company {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.invoice-number {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.invoice-desc {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.invoice-amount {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.due-normal {
    color: var(--color-text-secondary);
}

.due-overdue {
    color: var(--color-status-red);
    font-weight: 500;
}

.overdue-label {
    display: block;
    font-size: 0.75rem;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
}

.badge-submitted {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-accent);
}

.badge-approved {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.actions-cell {
    text-align: right;
    white-space: nowrap;
}

.btn {
    padding: 0.25rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.25rem;
    font-weight: 500;
    transition: all 0.15s;
    margin-left: 0.5rem;
}

.btn:disabled {
    opacity: 0.5;
}

.btn-approve {
    background: var(--color-status-green);
    color: white;
}

.btn-approve:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-reject {
    background: var(--color-status-red);
    color: white;
}

.btn-reject:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-pay {
    background: var(--color-accent);
    color: white;
}

.btn-pay:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-secondary {
    color: var(--color-text-secondary);
    padding: 0.5rem 1rem;
}

.btn-secondary:hover {
    background: var(--color-bg-tertiary);
}

.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
}

.empty-title {
    font-size: 1.125rem;
    color: var(--color-text-secondary);
    margin-bottom: 0.5rem;
}

.empty-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-tertiary);
}

.text-right {
    text-align: right;
}

/* Modal */
.modal-content {
    padding: 1.5rem;
}

.modal-title {
    font-size: 1.125rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.modal-subtitle {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-bottom: 1rem;
}

.form-group {
    margin-bottom: 1rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.25rem;
}

.form-textarea {
    width: 100%;
    padding: 0.5rem;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    resize: vertical;
}

.form-textarea::placeholder {
    color: var(--color-text-tertiary);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
}
</style>
