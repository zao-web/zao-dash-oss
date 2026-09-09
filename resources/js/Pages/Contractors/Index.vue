<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Modal from '@/Components/Modal.vue';
import FormInput from '@/Components/FormInput.vue';
import FormSelect from '@/Components/FormSelect.vue';
import FormToggle from '@/Components/FormToggle.vue';
import { ref, reactive, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface Contractor {
    id: number;
    uuid: string;
    name: string;
    email: string;
    company_name: string | null;
    country_code: string;
    is_us_person: boolean;
    payment_type: 'recurring' | 'invoice';
    recurring_amount: number | null;
    recurring_currency: string | null;
    recurring_schedule: string | null;
    status: string;
    onboarding_status: string;
    has_w9_on_file: boolean;
    invoices_count: number;
    transfers_count: number;
    total_paid_this_year: number;
    can_receive_payments: boolean;
    needs_w9: boolean;
    created_at: string;
}

interface Stats {
    total: number;
    active: number;
    pending_onboarding: number;
    needs_w9: number;
    recurring: number;
    total_paid_this_year: number;
}

const props = defineProps<{
    contractors: Contractor[];
    stats: Stats;
}>();

const showCreateModal = ref(false);
const isSaving = ref(false);
const filter = ref('all');

const createForm = reactive({
    name: '',
    email: '',
    phone: '',
    company_name: '',
    country_code: 'US',
    is_us_person: true,
    payment_type: 'invoice' as 'recurring' | 'invoice',
    recurring_amount: '',
    recurring_currency: 'USD',
    recurring_schedule: 'monthly',
});

const filteredContractors = computed(() => {
    if (filter.value === 'all') return props.contractors;
    if (filter.value === 'active') return props.contractors.filter(c => c.status === 'active');
    if (filter.value === 'pending') return props.contractors.filter(c => c.onboarding_status !== 'complete');
    if (filter.value === 'needs_w9') return props.contractors.filter(c => c.needs_w9);
    if (filter.value === 'recurring') return props.contractors.filter(c => c.payment_type === 'recurring');
    return props.contractors;
});

const countryOptions = [
    { value: 'US', label: 'United States' },
    { value: 'CA', label: 'Canada' },
    { value: 'GB', label: 'United Kingdom' },
    { value: 'PK', label: 'Pakistan' },
    { value: 'IN', label: 'India' },
    { value: 'PH', label: 'Philippines' },
    { value: 'AU', label: 'Australia' },
    { value: 'DE', label: 'Germany' },
];

const scheduleOptions = [
    { value: 'weekly', label: 'Weekly' },
    { value: 'biweekly', label: 'Bi-weekly' },
    { value: 'monthly', label: 'Monthly' },
];

const resetForm = () => {
    createForm.name = '';
    createForm.email = '';
    createForm.phone = '';
    createForm.company_name = '';
    createForm.country_code = 'US';
    createForm.is_us_person = true;
    createForm.payment_type = 'invoice';
    createForm.recurring_amount = '';
    createForm.recurring_currency = 'USD';
    createForm.recurring_schedule = 'monthly';
};

const submitCreate = () => {
    isSaving.value = true;
    router.post('/contractors', createForm, {
        onSuccess: () => {
            showCreateModal.value = false;
            resetForm();
        },
        onFinish: () => {
            isSaving.value = false;
        },
    });
};

const formatCurrency = (amount: number, currency = 'USD') => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency }).format(amount);
};
</script>

<template>
    <AppLayout title="Contractors">
        <div class="contractors-page">
            <!-- Header -->
            <div class="page-header">
                <div>
                    <h1 class="page-title">Contractors</h1>
                    <p class="page-subtitle">Manage contractor payments and onboarding</p>
                </div>
                <button @click="showCreateModal = true" class="btn btn-primary">
                    Add Contractor
                </button>
            </div>

            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-value">{{ stats.total }}</div>
                    <div class="stat-label">Total</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-green">{{ stats.active }}</div>
                    <div class="stat-label">Active</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-yellow">{{ stats.pending_onboarding }}</div>
                    <div class="stat-label">Pending Setup</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-orange">{{ stats.needs_w9 }}</div>
                    <div class="stat-label">Needs W-9</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-purple">{{ stats.recurring }}</div>
                    <div class="stat-label">Recurring</div>
                </div>
                <div class="stat-card">
                    <div class="stat-value status-accent">{{ formatCurrency(stats.total_paid_this_year) }}</div>
                    <div class="stat-label">Paid YTD</div>
                </div>
            </div>

            <!-- Filters -->
            <div class="filter-bar">
                <button
                    v-for="f in [
                        { key: 'all', label: 'All' },
                        { key: 'active', label: 'Active' },
                        { key: 'pending', label: 'Pending Setup' },
                        { key: 'needs_w9', label: 'Needs W-9' },
                        { key: 'recurring', label: 'Recurring' },
                    ]"
                    :key="f.key"
                    @click="filter = f.key"
                    :class="['filter-btn', filter === f.key ? 'active' : '']"
                >
                    {{ f.label }}
                </button>
            </div>

            <!-- Contractors Table -->
            <div class="table-card">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Contractor</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Onboarding</th>
                            <th>Paid YTD</th>
                            <th class="text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="contractor in filteredContractors" :key="contractor.id">
                            <td>
                                <div class="contractor-info">
                                    <div>
                                        <div class="contractor-name">{{ contractor.name }}</div>
                                        <div class="contractor-email">{{ contractor.email }}</div>
                                        <div v-if="contractor.company_name" class="contractor-company">{{ contractor.company_name }}</div>
                                    </div>
                                    <span v-if="contractor.needs_w9" class="badge badge-orange">W-9 Needed</span>
                                </div>
                            </td>
                            <td>
                                <div v-if="contractor.payment_type === 'recurring'" class="payment-type">
                                    <span class="recurring-amount">{{ formatCurrency(contractor.recurring_amount || 0) }}</span>
                                    <span class="recurring-schedule">/{{ contractor.recurring_schedule }}</span>
                                </div>
                                <div v-else class="payment-type-invoice">Invoice-based</div>
                            </td>
                            <td>
                                <span :class="['badge', `badge-status-${contractor.status}`]">
                                    {{ contractor.status }}
                                </span>
                            </td>
                            <td>
                                <span :class="['badge', `badge-onboarding-${contractor.onboarding_status}`]">
                                    {{ contractor.onboarding_status.replace('_', ' ') }}
                                </span>
                            </td>
                            <td class="paid-ytd">
                                {{ formatCurrency(contractor.total_paid_this_year) }}
                            </td>
                            <td class="text-right">
                                <Link :href="`/contractors/${contractor.id}`" class="view-link">
                                    View
                                </Link>
                            </td>
                        </tr>
                        <tr v-if="filteredContractors.length === 0">
                            <td colspan="6" class="empty-state">
                                No contractors found
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Create Modal -->
        <Modal :show="showCreateModal" @close="showCreateModal = false" max-width="lg">
            <div class="modal-content">
                <h3 class="modal-title">Add Contractor</h3>
                <form @submit.prevent="submitCreate" class="modal-form">
                    <div class="form-grid">
                        <FormInput v-model="createForm.name" label="Name" required />
                        <FormInput v-model="createForm.email" label="Email" type="email" required />
                    </div>
                    <div class="form-grid">
                        <FormInput v-model="createForm.phone" label="Phone" />
                        <FormInput v-model="createForm.company_name" label="Company Name" />
                    </div>
                    <div class="form-grid">
                        <FormSelect v-model="createForm.country_code" label="Country" :options="countryOptions" />
                        <FormToggle v-model="createForm.is_us_person" label="US Person (for tax purposes)" />
                    </div>
                    <FormSelect
                        v-model="createForm.payment_type"
                        label="Payment Type"
                        :options="[
                            { value: 'invoice', label: 'Invoice-based' },
                            { value: 'recurring', label: 'Recurring' },
                        ]"
                    />
                    <div v-if="createForm.payment_type === 'recurring'" class="form-grid-3">
                        <FormInput v-model="createForm.recurring_amount" label="Amount" type="number" step="0.01" />
                        <FormSelect
                            v-model="createForm.recurring_currency"
                            label="Currency"
                            :options="[
                                { value: 'USD', label: 'USD' },
                                { value: 'EUR', label: 'EUR' },
                                { value: 'GBP', label: 'GBP' },
                            ]"
                        />
                        <FormSelect v-model="createForm.recurring_schedule" label="Schedule" :options="scheduleOptions" />
                    </div>
                    <div class="modal-actions">
                        <button type="button" @click="showCreateModal = false" class="btn btn-secondary">
                            Cancel
                        </button>
                        <button type="submit" :disabled="isSaving" class="btn btn-primary">
                            {{ isSaving ? 'Creating...' : 'Create Contractor' }}
                        </button>
                    </div>
                </form>
            </div>
        </Modal>
    </AppLayout>
</template>

<style scoped>
.contractors-page {
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

.btn-primary:hover {
    opacity: 0.9;
}

.btn-secondary {
    color: var(--color-text-secondary);
}

.btn-secondary:hover {
    background: var(--color-bg-tertiary);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
    margin-bottom: 1.5rem;
}

@media (min-width: 768px) {
    .stats-grid {
        grid-template-columns: repeat(3, 1fr);
    }
}

@media (min-width: 1024px) {
    .stats-grid {
        grid-template-columns: repeat(6, 1fr);
    }
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
    color: var(--color-text-primary);
}

.stat-value.status-green { color: var(--color-status-green); }
.stat-value.status-yellow { color: var(--color-status-yellow); }
.stat-value.status-orange { color: #f97316; }
.stat-value.status-purple { color: #a855f7; }
.stat-value.status-accent { color: var(--color-accent); }

.stat-label {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.filter-bar {
    display: flex;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.filter-btn {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 9999px;
    transition: all 0.15s;
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.filter-btn:hover {
    background: var(--color-bg-elevated);
}

.filter-btn.active {
    background: var(--color-accent);
    color: white;
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

.contractor-info {
    display: flex;
    align-items: center;
}

.contractor-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.contractor-email {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.contractor-company {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.badge {
    display: inline-block;
    padding: 0.125rem 0.5rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 9999px;
}

.badge-orange {
    background: rgba(249, 115, 22, 0.15);
    color: #f97316;
    margin-left: 0.5rem;
}

.badge-status-active {
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

.badge-status-terminated {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.badge-onboarding-complete {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.badge-onboarding-invited {
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-accent);
}

.badge-onboarding-info_submitted {
    background: rgba(245, 158, 11, 0.15);
    color: var(--color-status-yellow);
}

.badge-onboarding-bank_verified {
    background: rgba(139, 92, 246, 0.15);
    color: #a855f7;
}

.payment-type {
    font-size: 0.875rem;
}

.recurring-amount {
    color: #a855f7;
}

.recurring-schedule {
    color: var(--color-text-secondary);
}

.payment-type-invoice {
    font-size: 0.875rem;
    color: var(--color-text-secondary);
}

.paid-ytd {
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.view-link {
    color: var(--color-accent);
}

.view-link:hover {
    opacity: 0.8;
}

.empty-state {
    padding: 2rem 1.5rem;
    text-align: center;
    color: var(--color-text-secondary);
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

.modal-form {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 1rem;
}

.form-grid-3 {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 1rem;
}

.modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 0.75rem;
    padding-top: 1rem;
}
</style>
