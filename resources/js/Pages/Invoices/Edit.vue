<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref, computed, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';

interface Client {
    id: number;
    name: string;
}

interface Project {
    id: number;
    name: string;
}

interface InvoiceLine {
    id?: number;
    type: 'time' | 'fixed' | 'expense' | 'discount';
    description: string;
    details: string | null;
    quantity: number;
    unit: string | null;
    unit_price: number;
    time_entry_id?: number | null;
    _delete?: boolean;
}

interface Invoice {
    id: number;
    number: string;
    subject: string | null;
    notes: string | null;
    internal_notes: string | null;
    status: string;
    subtotal: string;
    tax_rate: string;
    tax_amount: string;
    total: string;
    issue_date: string;
    due_date: string;
    payment_terms: string | null;
    po_number: string | null;
    recipient_email: string | null;
    paypal_invoice_id: string | null;
    client_id: number;
    project_id: number | null;
    client: Client;
    project: Project | null;
    lines: InvoiceLine[];
}

const props = defineProps<{
    invoice: Invoice;
    clients: Client[];
    projects: Project[];
    canEditAmounts: boolean;
    canNotifyClient: boolean;
    paypalRecipientEmail: string | null;
}>();

const form = useForm({
    client_id: props.invoice.client_id,
    project_id: props.invoice.project_id,
    subject: props.invoice.subject || '',
    notes: props.invoice.notes || '',
    internal_notes: props.invoice.internal_notes || '',
    issue_date: props.invoice.issue_date?.slice(0, 10) ?? '',
    due_date: props.invoice.due_date?.slice(0, 10) ?? '',
    payment_terms: props.invoice.payment_terms || 'Net 30',
    po_number: props.invoice.po_number || '',
    recipient_email: props.invoice.recipient_email || props.paypalRecipientEmail || '',
    tax_rate: parseFloat(props.invoice.tax_rate) || 0,
    lines: props.invoice.lines.map(line => ({
        id: line.id,
        type: line.type,
        description: line.description,
        details: line.details || '',
        quantity: typeof line.quantity === 'string' ? parseFloat(line.quantity) : line.quantity,
        unit: line.unit || '',
        unit_price: typeof line.unit_price === 'string' ? parseFloat(line.unit_price) : line.unit_price,
        time_entry_id: line.time_entry_id,
        _delete: false,
    })),
    notify_client: false,
    update_summary: '',
});

const availableProjects = ref<Project[]>(props.projects);

const loadProjects = async () => {
    if (!form.client_id) {
        availableProjects.value = [];
        return;
    }
    try {
        const response = await fetch(`/api/invoices/clients/${form.client_id}/projects`);
        availableProjects.value = await response.json();
    } catch (e) {
        availableProjects.value = [];
    }
};

watch(() => form.client_id, (newVal, oldVal) => {
    if (newVal !== oldVal) {
        form.project_id = null;
        loadProjects();
    }
});

const paymentTermsOptions = [
    { value: 'Due on Receipt', label: 'Due on Receipt' },
    { value: 'Net 15', label: 'Net 15' },
    { value: 'Net 30', label: 'Net 30' },
    { value: 'Net 45', label: 'Net 45' },
    { value: 'Net 60', label: 'Net 60' },
    { value: 'Net 90', label: 'Net 90' },
];

const lineTypeOptions = [
    { value: 'fixed', label: 'Fixed Fee' },
    { value: 'expense', label: 'Expense' },
    { value: 'discount', label: 'Discount' },
];

const clientOptions = computed(() =>
    props.clients.map(c => ({ value: c.id, label: c.name }))
);

const projectOptions = computed(() => [
    { value: '', label: 'No project' },
    ...availableProjects.value.map(p => ({ value: p.id, label: p.name }))
]);

const activeLines = computed(() =>
    form.lines.filter(l => !l._delete)
);

const subtotal = computed(() => {
    return activeLines.value.reduce((sum, line) => {
        const amount = line.quantity * line.unit_price;
        return line.type === 'discount' ? sum - Math.abs(amount) : sum + amount;
    }, 0);
});

const taxAmount = computed(() => {
    if (form.tax_rate <= 0) return 0;
    const taxableAmount = activeLines.value
        .filter(l => l.type !== 'discount')
        .reduce((sum, l) => sum + (l.quantity * l.unit_price), 0);
    return taxableAmount * (form.tax_rate / 100);
});

const total = computed(() => subtotal.value + taxAmount.value);

const addLine = () => {
    form.lines.push({
        type: 'fixed',
        description: '',
        details: '',
        quantity: 1,
        unit: '',
        unit_price: 0,
        _delete: false,
    });
};

const removeLine = (index: number) => {
    const line = form.lines[index];
    if (line.id) {
        // Mark for deletion on server
        line._delete = true;
    } else {
        // Just remove from array
        form.lines.splice(index, 1);
    }
};

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const submit = () => {
    // The backend expects `delete` on lines; `_delete` is the local UI flag.
    form.transform((data) => ({
        ...data,
        lines: data.lines.map(({ _delete, ...line }) => ({ ...line, delete: _delete })),
    })).put(`/invoices/${props.invoice.id}`, {
        onSuccess: () => {
            // Redirect handled by controller
        },
    });
};
</script>

<template>
    <AppLayout :title="`Edit Invoice #${invoice.number}`">
        <div class="edit-page">
            <form @submit.prevent="submit">
                <!-- Header -->
                <div class="page-header">
                    <div>
                        <div class="header-row">
                            <Link :href="`/invoices/${invoice.id}`" class="back-link">
                                <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                                </svg>
                            </Link>
                            <h1 class="page-title">Edit Invoice #{{ invoice.number }}</h1>
                        </div>
                    </div>
                    <div class="header-actions">
                        <Link :href="`/invoices/${invoice.id}`" class="btn btn-secondary">
                            Cancel
                        </Link>
                        <button type="submit" :disabled="form.processing" class="btn btn-primary">
                            {{ form.processing ? 'Saving...' : 'Save Changes' }}
                        </button>
                    </div>
                </div>

                <div class="form-grid">
                    <!-- Left Column: Details -->
                    <div class="form-section">
                        <h2 class="section-title">Invoice Details</h2>

                        <div class="form-group">
                            <label class="form-label">Client</label>
                            <FormSelect
                                v-model="form.client_id"
                                :options="clientOptions"
                                :disabled="!canEditAmounts"
                                placeholder="Select client..."
                            />
                            <p v-if="form.errors.client_id" class="form-error">{{ form.errors.client_id }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Project (Optional)</label>
                            <FormSelect
                                v-model="form.project_id"
                                :options="projectOptions"
                                :disabled="!canEditAmounts || !form.client_id"
                                placeholder="Select project..."
                            />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Subject</label>
                            <input
                                type="text"
                                v-model="form.subject"
                                class="form-input"
                                placeholder="e.g., December Services"
                            />
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Issue Date</label>
                                <input
                                    type="date"
                                    v-model="form.issue_date"
                                    class="form-input"
                                />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Due Date</label>
                                <input
                                    type="date"
                                    v-model="form.due_date"
                                    class="form-input"
                                />
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label class="form-label">Payment Terms</label>
                                <FormSelect
                                    v-model="form.payment_terms"
                                    :options="paymentTermsOptions"
                                />
                            </div>
                            <div class="form-group">
                                <label class="form-label">PO Number</label>
                                <input
                                    type="text"
                                    v-model="form.po_number"
                                    class="form-input"
                                    placeholder="Optional"
                                />
                            </div>
                        </div>

                        <div v-if="invoice.paypal_invoice_id" class="form-group">
                            <label class="form-label">PayPal Recipient Email</label>
                            <input
                                type="email"
                                v-model="form.recipient_email"
                                class="form-input"
                                placeholder="recipient@example.com"
                            />
                            <p class="form-hint">
                                Email used for the PayPal invoice recipient. Defaults to the client's billing email.
                            </p>
                            <p v-if="form.errors.recipient_email" class="form-error">{{ form.errors.recipient_email }}</p>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Tax Rate (%)</label>
                            <input
                                type="number"
                                v-model.number="form.tax_rate"
                                class="form-input"
                                min="0"
                                max="100"
                                step="0.01"
                                :disabled="!canEditAmounts"
                            />
                        </div>

                        <div class="form-group">
                            <label class="form-label">Notes (visible to client)</label>
                            <textarea
                                v-model="form.notes"
                                class="form-textarea"
                                rows="3"
                                placeholder="Thank you for your business!"
                            ></textarea>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Internal Notes (not visible to client)</label>
                            <textarea
                                v-model="form.internal_notes"
                                class="form-textarea"
                                rows="3"
                                placeholder="Notes for internal reference..."
                            ></textarea>
                        </div>

                        <!-- Client Notification Section (only for sent invoices) -->
                        <div v-if="canNotifyClient" class="notify-section">
                            <div class="notify-header">
                                <h3 class="notify-title">Client Notification</h3>
                            </div>
                            <div class="notify-content">
                                <label class="checkbox-label">
                                    <input
                                        type="checkbox"
                                        v-model="form.notify_client"
                                        class="checkbox-input"
                                    />
                                    <span class="checkbox-text">
                                        Notify client of changes
                                    </span>
                                </label>
                                <p class="notify-hint">
                                    Send an email with the updated invoice and a link to view it online.
                                </p>

                                <div v-if="form.notify_client" class="form-group notify-summary">
                                    <label class="form-label">What changed? (optional)</label>
                                    <textarea
                                        v-model="form.update_summary"
                                        class="form-textarea"
                                        rows="2"
                                        placeholder="e.g., Applied 5 retainer hours, reducing the amount due"
                                    ></textarea>
                                    <p class="form-hint">
                                        This will be included in the email to help the client understand the update.
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: Line Items -->
                    <div class="form-section">
                        <div class="section-header">
                            <h2 class="section-title">Line Items</h2>
                            <button
                                v-if="canEditAmounts"
                                type="button"
                                @click="addLine"
                                class="btn btn-sm btn-secondary"
                            >
                                Add Line
                            </button>
                        </div>

                        <div v-if="activeLines.length === 0" class="empty-lines">
                            No line items. Click "Add Line" to add items.
                        </div>

                        <div v-else class="lines-list">
                            <div
                                v-for="(line, index) in form.lines"
                                :key="index"
                                v-show="!line._delete"
                                class="line-item"
                            >
                                <div class="line-header">
                                    <span v-if="line.time_entry_id" class="line-badge time">Time Entry</span>
                                    <span v-else-if="line.id" class="line-badge">{{ line.type }}</span>
                                    <FormSelect
                                        v-else
                                        v-model="line.type"
                                        :options="lineTypeOptions"
                                        class="line-type-select"
                                        :disabled="!canEditAmounts"
                                    />
                                    <button
                                        v-if="canEditAmounts && !line.time_entry_id"
                                        type="button"
                                        @click="removeLine(index)"
                                        class="remove-line-btn"
                                        title="Remove line"
                                    >
                                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24" class="remove-icon">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>

                                <div class="line-fields">
                                    <div class="line-description">
                                        <input
                                            type="text"
                                            v-model="line.description"
                                            class="form-input"
                                            placeholder="Description"
                                            :disabled="!canEditAmounts"
                                        />
                                        <input
                                            type="text"
                                            v-model="line.details"
                                            class="form-input details-input"
                                            placeholder="Details (optional)"
                                        />
                                    </div>
                                    <div class="line-numbers">
                                        <div class="number-field">
                                            <label class="mini-label">Qty</label>
                                            <input
                                                type="number"
                                                v-model.number="line.quantity"
                                                class="form-input"
                                                step="0.01"
                                                min="0"
                                                :disabled="!canEditAmounts"
                                            />
                                        </div>
                                        <div class="number-field">
                                            <label class="mini-label">Rate</label>
                                            <input
                                                type="number"
                                                v-model.number="line.unit_price"
                                                class="form-input"
                                                step="0.01"
                                                :disabled="!canEditAmounts"
                                            />
                                        </div>
                                        <div class="line-total">
                                            <label class="mini-label">Amount</label>
                                            <span :class="['amount', line.type === 'discount' ? 'discount' : '']">
                                                {{ formatCurrency(line.type === 'discount' ? -Math.abs(line.quantity * line.unit_price) : line.quantity * line.unit_price) }}
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- Totals -->
                        <div class="totals-section">
                            <div class="total-row">
                                <span class="total-label">Subtotal</span>
                                <span class="total-value">{{ formatCurrency(subtotal) }}</span>
                            </div>
                            <div v-if="form.tax_rate > 0" class="total-row">
                                <span class="total-label">Tax ({{ form.tax_rate }}%)</span>
                                <span class="total-value">{{ formatCurrency(taxAmount) }}</span>
                            </div>
                            <div class="total-row total-final">
                                <span class="total-label">Total</span>
                                <span class="total-value">{{ formatCurrency(total) }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.edit-page {
    max-width: 80rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 2rem;
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

.btn-sm {
    padding: 0.375rem 0.75rem;
    font-size: 0.875rem;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
}

.btn-primary:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-secondary {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-secondary:hover {
    background: var(--color-bg-elevated);
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 2rem;
}

@media (min-width: 1024px) {
    .form-grid {
        grid-template-columns: 400px 1fr;
    }
}

.form-section {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    padding: 1.5rem;
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1rem;
}

.section-title {
    font-size: 1rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.section-header .section-title {
    margin-bottom: 0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-label {
    display: block;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.375rem;
}

.form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
}

.form-input:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-input:disabled {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
    cursor: not-allowed;
}

.form-textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
    resize: vertical;
}

.form-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-error {
    font-size: 0.75rem;
    color: var(--color-status-red);
    margin-top: 0.25rem;
}

.empty-lines {
    padding: 2rem;
    text-align: center;
    color: var(--color-text-secondary);
    background: var(--color-bg-tertiary);
    border-radius: 0.375rem;
}

.lines-list {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.line-item {
    padding: 1rem;
    background: var(--color-bg-tertiary);
    border-radius: 0.5rem;
    border: 1px solid var(--color-border-subtle);
}

.line-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 0.75rem;
}

.line-badge {
    font-size: 0.625rem;
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    text-transform: uppercase;
    font-weight: 600;
    background: rgba(99, 102, 241, 0.15);
    color: var(--color-accent);
}

.line-badge.time {
    background: rgba(16, 185, 129, 0.15);
    color: var(--color-status-green);
}

.line-type-select {
    width: 120px;
}

.remove-line-btn {
    margin-left: auto;
    padding: 0.25rem;
    color: var(--color-text-tertiary);
    border-radius: 0.25rem;
}

.remove-line-btn:hover {
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.1);
}

.remove-icon {
    width: 1rem;
    height: 1rem;
}

.line-fields {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.line-description {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.details-input {
    font-size: 0.75rem;
}

.line-numbers {
    display: flex;
    gap: 0.75rem;
    align-items: flex-end;
}

.number-field {
    flex: 1;
}

.mini-label {
    display: block;
    font-size: 0.625rem;
    text-transform: uppercase;
    color: var(--color-text-tertiary);
    margin-bottom: 0.25rem;
}

.line-total {
    flex: 1;
    text-align: right;
}

.line-total .amount {
    display: block;
    font-weight: 600;
    color: var(--color-text-primary);
}

.line-total .amount.discount {
    color: var(--color-status-red);
}

.totals-section {
    margin-top: 1.5rem;
    padding-top: 1rem;
    border-top: 1px solid var(--color-border-default);
}

.total-row {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
}

.total-label {
    color: var(--color-text-secondary);
}

.total-value {
    font-weight: 500;
    color: var(--color-text-primary);
}

.total-final {
    border-top: 1px solid var(--color-border-default);
    margin-top: 0.5rem;
    padding-top: 0.75rem;
}

.total-final .total-label,
.total-final .total-value {
    font-size: 1.125rem;
    font-weight: 700;
}

.total-final .total-value {
    color: var(--color-accent);
}

/* Notify Section Styles */
.notify-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid var(--color-border-default);
}

.notify-header {
    margin-bottom: 0.75rem;
}

.notify-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.notify-content {
    background: var(--color-bg-tertiary);
    border-radius: 0.5rem;
    padding: 1rem;
    border: 1px solid var(--color-border-subtle);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    cursor: pointer;
}

.checkbox-input {
    width: 1rem;
    height: 1rem;
    accent-color: var(--color-accent);
}

.checkbox-text {
    font-weight: 500;
    color: var(--color-text-primary);
}

.notify-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.375rem;
    margin-left: 1.5rem;
}

.notify-summary {
    margin-top: 1rem;
    margin-bottom: 0;
}

.form-hint {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    margin-top: 0.25rem;
}
</style>
