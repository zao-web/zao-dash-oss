<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import { ref, reactive } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface Invoice {
    id: number;
    uuid: string;
    invoice_number: string;
    invoice_date: string;
    due_date: string | null;
    amount: number;
    currency: string;
    status: string;
    is_overdue: boolean;
}

interface Transfer {
    id: number;
    source_amount: number;
    source_currency: string;
    target_amount: number;
    target_currency: string;
    fee: number;
    status: string;
    payment_type: string;
    reference: string | null;
    created_at: string;
}

interface Contractor {
    id: number;
    uuid: string;
    name: string;
    email: string;
    phone: string | null;
    company_name: string | null;
    country_code: string;
    is_us_person: boolean;
    tax_id_last_four: string | null;
    tax_id_type: string | null;
    has_w9_on_file: boolean;
    w9_received_at: string | null;
    payment_type: 'recurring' | 'invoice';
    recurring_amount: number | null;
    recurring_currency: string | null;
    recurring_schedule: string | null;
    wise_recipient_id: string | null;
    status: string;
    onboarding_status: string;
    can_receive_payments: boolean;
    needs_w9: boolean;
    total_paid_this_year: number;
    created_at: string;
}

const props = defineProps<{
    contractor: Contractor;
    invoices: Invoice[];
    transfers: Transfer[];
}>();

const showW9Modal = ref(false);
const isUploading = ref(false);

const w9Form = reactive({
    w9_file: null as File | null,
    tax_id: '',
    tax_id_type: 'ssn',
});

const formatCurrency = (amount: number, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
};

const handleFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement;
    if (target.files?.length) {
        w9Form.w9_file = target.files[0];
    }
};

const submitW9 = () => {
    if (!w9Form.w9_file) return;

    isUploading.value = true;
    const formData = new FormData();
    formData.append('w9_file', w9Form.w9_file);
    formData.append('tax_id', w9Form.tax_id);
    formData.append('tax_id_type', w9Form.tax_id_type);

    router.post(`/contractors/${props.contractor.id}/w9`, formData, {
        onSuccess: () => {
            showW9Modal.value = false;
            w9Form.w9_file = null;
            w9Form.tax_id = '';
        },
        onFinish: () => {
            isUploading.value = false;
        },
    });
};

const activate = () => {
    router.post(`/contractors/${props.contractor.id}/activate`);
};

const suspend = () => {
    router.post(`/contractors/${props.contractor.id}/suspend`);
};

const invite = () => {
    router.post(`/contractors/${props.contractor.id}/invite`);
};
</script>

<template>
    <AppLayout :title="contractor.name">
        <div class="contractor-page">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <div class="header-row">
                        <Link href="/contractors" class="back-link">
                            <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                            </svg>
                        </Link>
                        <h1 class="page-title">{{ contractor.name }}</h1>
                        <span :class="['badge', `badge-status-${contractor.status}`]">
                            {{ contractor.status }}
                        </span>
                    </div>
                    <p class="contractor-email">{{ contractor.email }}</p>
                </div>
                <div class="header-actions">
                    <button
                        v-if="contractor.status === 'pending' && contractor.onboarding_status === 'complete'"
                        @click="activate"
                        class="btn btn-success"
                    >
                        Activate
                    </button>
                    <button
                        v-if="contractor.status === 'active'"
                        @click="suspend"
                        class="btn btn-danger"
                    >
                        Suspend
                    </button>
                    <button
                        v-if="!contractor.can_receive_payments"
                        @click="invite"
                        class="btn btn-primary"
                    >
                        Send Invite
                    </button>
                </div>
            </div>

            <div class="content-grid">
                <!-- Info Panel -->
                <div class="info-panel">
                    <!-- Basic Info -->
                    <div class="card">
                        <h3 class="card-title">Details</h3>
                        <dl class="detail-list">
                            <div v-if="contractor.company_name" class="detail-item">
                                <dt>Company</dt>
                                <dd>{{ contractor.company_name }}</dd>
                            </div>
                            <div v-if="contractor.phone" class="detail-item">
                                <dt>Phone</dt>
                                <dd>{{ contractor.phone }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>Country</dt>
                                <dd>{{ contractor.country_code }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>US Person</dt>
                                <dd>{{ contractor.is_us_person ? 'Yes' : 'No' }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>Since</dt>
                                <dd>{{ contractor.created_at }}</dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Payment Info -->
                    <div class="card">
                        <h3 class="card-title">Payment</h3>
                        <dl class="detail-list">
                            <div class="detail-item">
                                <dt>Type</dt>
                                <dd class="capitalize">{{ contractor.payment_type }}</dd>
                            </div>
                            <div v-if="contractor.payment_type === 'recurring'" class="detail-item">
                                <dt>Amount</dt>
                                <dd>
                                    {{ formatCurrency(contractor.recurring_amount || 0, contractor.recurring_currency || 'USD') }} / {{ contractor.recurring_schedule }}
                                </dd>
                            </div>
                            <div class="detail-item">
                                <dt>Paid This Year</dt>
                                <dd class="paid-ytd">{{ formatCurrency(contractor.total_paid_this_year) }}</dd>
                            </div>
                            <div class="detail-item">
                                <dt>Can Receive Payments</dt>
                                <dd>
                                    <span v-if="contractor.can_receive_payments" class="status-yes">Yes</span>
                                    <span v-else class="status-no">No - Setup incomplete</span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <!-- Tax Info -->
                    <div class="card">
                        <div class="card-header">
                            <h3 class="card-title">Tax Info</h3>
                            <button
                                v-if="contractor.is_us_person && !contractor.has_w9_on_file"
                                @click="showW9Modal = true"
                                class="upload-link"
                            >
                                Upload W-9
                            </button>
                        </div>
                        <dl class="detail-list">
                            <div class="detail-item">
                                <dt>W-9 Status</dt>
                                <dd>
                                    <span v-if="contractor.has_w9_on_file" class="status-yes">On file ({{ contractor.w9_received_at }})</span>
                                    <span v-else-if="contractor.is_us_person" class="status-warning">Required - Not received</span>
                                    <span v-else class="status-neutral">Not required (non-US)</span>
                                </dd>
                            </div>
                            <div v-if="contractor.tax_id_last_four" class="detail-item">
                                <dt>Tax ID</dt>
                                <dd>{{ contractor.tax_id_type?.toUpperCase() }} ending in {{ contractor.tax_id_last_four }}</dd>
                            </div>
                        </dl>
                    </div>
                </div>

                <!-- Main Content -->
                <div class="main-content">
                    <!-- Invoices -->
                    <div class="card">
                        <div class="table-header">
                            <h3 class="card-title">Invoices</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Amount</th>
                                        <th>Due</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="invoice in invoices" :key="invoice.id">
                                        <td class="text-primary">{{ invoice.invoice_number || `INV-${invoice.id}` }}</td>
                                        <td class="text-primary">{{ formatCurrency(invoice.amount, invoice.currency) }}</td>
                                        <td>
                                            <span :class="invoice.is_overdue ? 'text-danger' : 'text-secondary'">
                                                {{ invoice.due_date || '-' }}
                                                <span v-if="invoice.is_overdue" class="overdue-label">(overdue)</span>
                                            </span>
                                        </td>
                                        <td>
                                            <span :class="['badge', `badge-invoice-${invoice.status}`]">
                                                {{ invoice.status }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="invoices.length === 0">
                                        <td colspan="4" class="empty-state">No invoices</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>

                    <!-- Payment History -->
                    <div class="card">
                        <div class="table-header">
                            <h3 class="card-title">Payment History</h3>
                        </div>
                        <div class="table-wrapper">
                            <table class="data-table">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Amount</th>
                                        <th>Type</th>
                                        <th>Reference</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr v-for="transfer in transfers" :key="transfer.id">
                                        <td class="text-primary">{{ transfer.created_at }}</td>
                                        <td class="text-primary">
                                            {{ formatCurrency(transfer.target_amount, transfer.target_currency) }}
                                            <span v-if="transfer.source_currency !== transfer.target_currency" class="text-secondary text-sm">
                                                ({{ formatCurrency(transfer.source_amount, transfer.source_currency) }})
                                            </span>
                                        </td>
                                        <td class="text-secondary capitalize">{{ transfer.payment_type }}</td>
                                        <td class="text-secondary">{{ transfer.reference || '-' }}</td>
                                        <td>
                                            <span class="badge badge-status-completed">
                                                {{ transfer.status }}
                                            </span>
                                        </td>
                                    </tr>
                                    <tr v-if="transfers.length === 0">
                                        <td colspan="5" class="empty-state">No payments yet</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- W-9 Upload Modal -->
        <Modal :show="showW9Modal" @close="showW9Modal = false">
            <div class="modal-content">
                <h3 class="modal-title">Upload W-9</h3>
                <form @submit.prevent="submitW9" class="modal-form">
                    <div class="form-group">
                        <label class="form-label">W-9 PDF</label>
                        <input type="file" accept=".pdf" @change="handleFileChange" class="file-input" required />
                    </div>
                    <div class="form-grid">
                        <FormInput v-model="w9Form.tax_id" label="Tax ID (SSN/EIN)" required />
                        <div class="form-group">
                            <label class="form-label">Type</label>
                            <select v-model="w9Form.tax_id_type" class="form-select">
                                <option value="ssn">SSN</option>
                                <option value="ein">EIN</option>
                            </select>
                        </div>
                    </div>
                    <div class="modal-actions">
                        <button type="button" @click="showW9Modal = false" class="btn btn-secondary">Cancel</button>
                        <button type="submit" :disabled="isUploading" class="btn btn-primary">
                            {{ isUploading ? 'Uploading...' : 'Upload' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.contractor-page {
    max-width: 80rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1.5rem;
}

.header-row {
    display: flex;
    align-items: center;
    gap: 0.75rem;
}

.back-link {
    color: var(--color-text-secondary);
}

.back-link:hover {
    color: var(--color-text-primary);
}

.back-icon {
    width: 1.25rem;
    height: 1.25rem;
}

.page-title {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.contractor-email {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
}

.header-actions {
    display: flex;
    gap: 0.5rem;
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.5rem;
    font-weight: 500;
    transition: all 0.15s;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-success {
    background: var(--color-status-green);
    color: white;
}

.btn-danger {
    background: var(--color-status-red);
    color: white;
}

.btn-secondary {
    color: var(--color-text-secondary);
}

.btn-secondary:hover {
    background: var(--color-bg-tertiary);
}

.btn:disabled {
    opacity: 0.5;
}

.content-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 1024px) {
    .content-grid {
        grid-template-columns: 1fr 2fr;
    }
}

.info-panel {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.main-content {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    padding: 1.5rem;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.card-title {
    font-size: 1.125rem;
    font-weight: 500;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.card-header .card-title {
    margin-bottom: 0;
}

.upload-link {
    font-size: 0.875rem;
    color: var(--color-accent);
}

.upload-link:hover {
    opacity: 0.8;
}

.detail-list {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.detail-item dt {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.detail-item dd {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.paid-ytd {
    font-size: 1.5rem !important;
    font-weight: 700 !important;
    color: var(--color-accent) !important;
}

.status-yes {
    color: var(--color-status-green);
}

.status-no {
    color: var(--color-status-red);
}

.status-warning {
    color: #f97316;
}

.status-neutral {
    color: var(--color-text-secondary);
}

.capitalize {
    text-transform: capitalize;
}

.table-header {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.table-header .card-title {
    margin-bottom: 0;
}

.card:has(.table-header) {
    padding: 0;
}

.table-wrapper {
    overflow-x: auto;
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
}

.data-table td {
    padding: 1rem 1.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.text-primary {
    color: var(--color-text-primary);
}

.text-secondary {
    color: var(--color-text-secondary);
}

.text-danger {
    color: var(--color-status-red);
}

.text-sm {
    font-size: 0.75rem;
}

.overdue-label {
    font-size: 0.75rem;
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
}

.badge-status-active,
.badge-status-completed {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.badge-status-pending {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-status-yellow);
}

.badge-status-suspended {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.badge-invoice-draft {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.badge-invoice-submitted {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-accent);
}

.badge-invoice-approved {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.badge-invoice-rejected {
    background: rgba(239, 68, 68, 0.15);
    color: var(--color-status-red);
}

.badge-invoice-paid {
    background: rgba(139, 92, 246, 0.15);
    color: #a855f7;
}

.empty-state {
    padding: 2rem 1.5rem;
    text-align: center;
    color: var(--color-text-secondary);
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

.modal-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-group {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.form-label {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.file-input {
    width: 100%;
}

.form-select {
    width: 100%;
    padding: 0.5rem;
    border-radius: 0.5rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 1rem;
}
</style>
