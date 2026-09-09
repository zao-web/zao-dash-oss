<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import FormSelect from '@/Components/FormSelect.vue';
import { ref, computed, watch } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';

interface Client {
    id: number;
    name: string;
    default_tax_rate: string | null;
    default_hourly_rate: string | null;
    payment_terms: string | null;
}

interface Project {
    id: number;
    name: string;
    slug: string;
}

interface TimeEntry {
    id: number;
    date: string;
    hours: number;
    rate: number;
    amount: number;
    notes: string | null;
    project: string | null;
    task: string | null;
}

interface ManualLine {
    type: 'fixed' | 'expense' | 'discount';
    description: string;
    quantity: number;
    unit_price: number;
}

const props = defineProps<{
    clients: Client[];
    projects: Project[];
    unbilledTime: TimeEntry[];
    selectedClientId: number | null;
    selectedProjectId: number | null;
    groupModes: { value: string; label: string }[];
}>();

const form = useForm({
    client_id: props.selectedClientId?.toString() || null,
    project_id: props.selectedProjectId?.toString() || null,
    subject: '',
    notes: '',
    internal_notes: '',
    due_date: getDefaultDueDate(),
    tax_rate: 0,
    time_entry_ids: [] as number[],
    group_mode: 'individual',
    lines: [] as ManualLine[],
});

const localProjects = ref<Project[]>(props.projects);
const localUnbilledTime = ref<TimeEntry[]>(props.unbilledTime);
const isLoadingTime = ref(false);
const selectAll = ref(false);
const clientHourlyRate = ref<number>(0);

interface EffortEstimation {
    success: boolean;
    error?: string;
    total_estimated_hours: number;
    breakdown: Record<string, number>;
    since_date: string;
    repos: Array<{ repo: string; estimated_hours: number; commit_count: number }>;
}

const effortEstimation = ref<EffortEstimation | null>(null);
const isLoadingEffort = ref(false);
const effortAdded = ref(false);

function getDefaultDueDate(): string {
    const date = new Date();
    date.setDate(date.getDate() + 30);
    return date.toISOString().split('T')[0];
}

const clientOptions = computed(() => [
    { value: '', label: 'Select a client...' },
    ...props.clients.map(c => ({ value: c.id.toString(), label: c.name }))
]);

const projectOptions = computed(() => [
    { value: '', label: 'All projects' },
    ...localProjects.value.map(p => ({ value: p.id.toString(), label: p.name }))
]);

const groupModeOptions = computed(() =>
    props.groupModes.map(m => ({ value: m.value, label: m.label }))
);

const lineTypeOptions = [
    { value: 'fixed', label: 'Fixed Fee' },
    { value: 'expense', label: 'Expense' },
    { value: 'discount', label: 'Discount' },
];

// Watch client changes
watch(() => form.client_id, async (clientId) => {
    form.project_id = null;
    form.time_entry_ids = [];
    localUnbilledTime.value = [];
    selectAll.value = false;

    if (clientId) {
        // Load projects
        const res = await fetch(`/api/invoices/clients/${clientId}/projects`);
        localProjects.value = await res.json();

        // Set default tax rate and hourly rate
        const client = props.clients.find(c => c.id === Number(clientId));
        if (client?.default_tax_rate) {
            form.tax_rate = parseFloat(client.default_tax_rate);
        }
        clientHourlyRate.value = client?.default_hourly_rate ? parseFloat(client.default_hourly_rate) : 0;

        // Load unbilled time
        loadUnbilledTime();
    } else {
        localProjects.value = [];
        clientHourlyRate.value = 0;
    }
});

// Watch project changes
watch(() => form.project_id, () => {
    if (form.client_id) {
        form.time_entry_ids = [];
        selectAll.value = false;
        loadUnbilledTime();
        loadEffortEstimation();
    }
});

// Load effort estimation on mount if project is pre-selected
if (props.selectedProjectId && props.selectedClientId) {
    const client = props.clients.find(c => c.id === Number(props.selectedClientId));
    if (client?.default_hourly_rate) {
        clientHourlyRate.value = parseFloat(client.default_hourly_rate);
    }
    loadEffortEstimation();
}

async function loadUnbilledTime() {
    if (!form.client_id) return;

    isLoadingTime.value = true;
    const params = new URLSearchParams({ client_id: form.client_id.toString() });
    if (form.project_id) {
        params.append('project_id', form.project_id.toString());
    }

    const res = await fetch(`/api/invoices/unbilled-time?${params}`);
    const data = await res.json();
    localUnbilledTime.value = data.entries;
    isLoadingTime.value = false;
}

async function loadEffortEstimation() {
    if (!form.project_id) {
        effortEstimation.value = null;
        return;
    }

    const project = localProjects.value.find(p => p.id === Number(form.project_id));
    if (!project || !project.slug) return;

    isLoadingEffort.value = true;
    effortAdded.value = false;

    try {
        const res = await fetch(`/api/integrations/github/projects/${project.id}/estimate-effort`);
        const data = await res.json();
        effortEstimation.value = data;

        if (data.success && data.total_estimated_hours > 0 && props.selectedProjectId) {
            addEffortAsLineItems();
        }
    } catch (e) {
        console.error('Failed to load effort estimation:', e);
        effortEstimation.value = null;
    } finally {
        isLoadingEffort.value = false;
    }
}

function addEffortAsLineItems() {
    if (!effortEstimation.value?.success || effortAdded.value) return;

    const breakdown = effortEstimation.value.breakdown;
    const rate = clientHourlyRate.value || 150;

    const typeLabels: Record<string, string> = {
        feature: 'Feature Development',
        bugfix: 'Bug Fixes',
        refactor: 'Code Refactoring',
        docs: 'Documentation',
        test: 'Testing',
        chore: 'Maintenance & Chores',
        other: 'Other Development',
    };

    for (const [type, hours] of Object.entries(breakdown)) {
        if (hours > 0) {
            form.lines.push({
                type: 'fixed',
                description: `${typeLabels[type] || type} (${effortEstimation.value.since_date})`,
                quantity: hours,
                unit_price: rate,
            });
        }
    }

    effortAdded.value = true;
}

// Toggle all entries
watch(selectAll, (val) => {
    if (val) {
        form.time_entry_ids = localUnbilledTime.value.map(e => e.id);
    } else {
        form.time_entry_ids = [];
    }
});

// Computed totals
const selectedTimeTotal = computed(() => {
    return localUnbilledTime.value
        .filter(e => form.time_entry_ids.includes(e.id))
        .reduce((sum, e) => sum + e.amount, 0);
});

const selectedTimeHours = computed(() => {
    return localUnbilledTime.value
        .filter(e => form.time_entry_ids.includes(e.id))
        .reduce((sum, e) => sum + e.hours, 0);
});

const manualLinesTotal = computed(() => {
    return form.lines.reduce((sum, line) => {
        const amount = line.quantity * line.unit_price;
        return line.type === 'discount' ? sum - Math.abs(amount) : sum + amount;
    }, 0);
});

const subtotal = computed(() => selectedTimeTotal.value + manualLinesTotal.value);
const taxAmount = computed(() => form.tax_rate > 0 ? subtotal.value * (form.tax_rate / 100) : 0);
const total = computed(() => subtotal.value + taxAmount.value);

const formatCurrency = (amount: number) => {
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(amount);
};

const formatPriceInput = (value: number | undefined): string => {
    if (value === undefined || value === null) return '';
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(value);
};

const parsePriceInput = (value: string): number => {
    const cleaned = value.replace(/[^0-9.-]/g, '');
    const parsed = parseFloat(cleaned);
    return isNaN(parsed) ? 0 : parsed;
};

// Manual lines
function addLine() {
    form.lines.push({
        type: 'fixed',
        description: '',
        quantity: 1,
        unit_price: clientHourlyRate.value || 0,
    });
}

function removeLine(index: number) {
    form.lines.splice(index, 1);
}

// Submit
function submit() {
    form.post('/invoices', {
        preserveScroll: true,
    });
}

const canSubmit = computed(() => {
    return form.client_id && (form.time_entry_ids.length > 0 || form.lines.length > 0);
});
</script>

<template>
    <AppLayout title="Create Invoice">
        <div class="create-invoice-page">
            <div class="page-header">
                <div class="header-row">
                    <Link href="/invoices" class="back-link">
                        <svg class="back-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
                        </svg>
                    </Link>
                    <h1 class="page-title">Create Invoice</h1>
                </div>
            </div>

            <form @submit.prevent="submit" class="invoice-form">
                <div class="form-grid">
                    <!-- Left Column: Configuration -->
                    <div class="config-column">
                        <!-- Client & Project -->
                        <div class="card">
                            <h3 class="card-title">Client & Project</h3>
                            <div class="form-group">
                                <label class="form-label">Client *</label>
                                <FormSelect
                                    v-model="form.client_id"
                                    :options="clientOptions"
                                    placeholder="Select client..."
                                />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Project</label>
                                <FormSelect
                                    v-model="form.project_id"
                                    :options="projectOptions"
                                    placeholder="All projects"
                                    :disabled="!form.client_id"
                                />
                            </div>
                        </div>

                        <!-- Invoice Details -->
                        <div class="card">
                            <h3 class="card-title">Invoice Details</h3>
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
                                    <label class="form-label">Due Date *</label>
                                    <input
                                        type="date"
                                        v-model="form.due_date"
                                        class="form-input"
                                        required
                                    />
                                </div>
                                <div class="form-group">
                                    <label class="form-label">Tax Rate (%)</label>
                                    <input
                                        type="number"
                                        v-model.number="form.tax_rate"
                                        class="form-input"
                                        min="0"
                                        max="100"
                                        step="0.1"
                                    />
                                </div>
                            </div>
                        </div>

                        <!-- Notes -->
                        <div class="card">
                            <h3 class="card-title">Notes</h3>
                            <div class="form-group">
                                <label class="form-label">Client Notes</label>
                                <textarea
                                    v-model="form.notes"
                                    class="form-textarea"
                                    rows="3"
                                    placeholder="Shown on invoice..."
                                />
                            </div>
                            <div class="form-group">
                                <label class="form-label">Internal Notes</label>
                                <textarea
                                    v-model="form.internal_notes"
                                    class="form-textarea"
                                    rows="2"
                                    placeholder="Private notes..."
                                />
                            </div>
                        </div>

                        <!-- Totals -->
                        <div class="card totals-card">
                            <dl class="totals-list">
                                <div class="total-item">
                                    <dt>Time Entries</dt>
                                    <dd>{{ formatCurrency(selectedTimeTotal) }}</dd>
                                </div>
                                <div class="total-item">
                                    <dt>Manual Lines</dt>
                                    <dd>{{ formatCurrency(manualLinesTotal) }}</dd>
                                </div>
                                <div class="total-item">
                                    <dt>Subtotal</dt>
                                    <dd>{{ formatCurrency(subtotal) }}</dd>
                                </div>
                                <div v-if="form.tax_rate > 0" class="total-item">
                                    <dt>Tax ({{ form.tax_rate }}%)</dt>
                                    <dd>{{ formatCurrency(taxAmount) }}</dd>
                                </div>
                                <div class="total-item total-grand">
                                    <dt>Total</dt>
                                    <dd>{{ formatCurrency(total) }}</dd>
                                </div>
                            </dl>
                            <button
                                type="submit"
                                class="btn btn-primary btn-full"
                                :disabled="!canSubmit || form.processing"
                            >
                                {{ form.processing ? 'Creating...' : 'Create Invoice' }}
                            </button>
                        </div>
                    </div>

                    <!-- Right Column: Line Items -->
                    <div class="lines-column">
                        <!-- Unbilled Time -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Unbilled Time</h3>
                                <FormSelect
                                    v-if="form.time_entry_ids.length > 0"
                                    v-model="form.group_mode"
                                    :options="groupModeOptions"
                                    class="group-select"
                                />
                            </div>

                            <div v-if="!form.client_id" class="empty-state">
                                Select a client to see unbilled time
                            </div>
                            <div v-else-if="isLoadingTime" class="loading-state">
                                Loading...
                            </div>
                            <div v-else-if="localUnbilledTime.length === 0" class="empty-state">
                                No unbilled time entries found
                            </div>
                            <div v-else class="time-entries">
                                <div class="select-all">
                                    <label class="checkbox-label">
                                        <input type="checkbox" v-model="selectAll" class="checkbox" />
                                        <span>Select all ({{ localUnbilledTime.length }} entries, {{ selectedTimeHours.toFixed(1) }}h selected)</span>
                                    </label>
                                </div>
                                <div class="entries-list">
                                    <label
                                        v-for="entry in localUnbilledTime"
                                        :key="entry.id"
                                        class="entry-row"
                                        :class="{ selected: form.time_entry_ids.includes(entry.id) }"
                                    >
                                        <input
                                            type="checkbox"
                                            :value="entry.id"
                                            v-model="form.time_entry_ids"
                                            class="checkbox"
                                        />
                                        <div class="entry-info">
                                            <div class="entry-main">
                                                <span class="entry-date">{{ entry.date }}</span>
                                                <span v-if="entry.project" class="entry-project">{{ entry.project }}</span>
                                            </div>
                                            <div v-if="entry.notes || entry.task" class="entry-notes">
                                                {{ entry.task || entry.notes }}
                                            </div>
                                        </div>
                                        <div class="entry-numbers">
                                            <div class="entry-hours">{{ Number(entry.hours).toFixed(2) }}h @ {{ formatCurrency(entry.rate) }}</div>
                                            <div class="entry-amount">{{ formatCurrency(entry.amount) }}</div>
                                        </div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- Effort Estimation -->
                        <div v-if="form.project_id && !effortAdded" class="card">
                            <div class="card-header">
                                <h3 class="card-title">Effort Estimation</h3>
                            </div>

                            <div v-if="isLoadingEffort" class="loading-state">
                                <span class="loading-spinner"></span>
                                Analyzing commits...
                            </div>
                            <div v-else-if="!effortEstimation" class="empty-state">
                                No GitHub repos linked to this project
                            </div>
                            <div v-else-if="!effortEstimation.success" class="empty-state">
                                {{ effortEstimation.error || 'Unable to estimate effort' }}
                            </div>
                            <div v-else-if="effortEstimation.total_estimated_hours === 0" class="empty-state">
                                No commits found since {{ effortEstimation.since_date }}
                            </div>
                            <div v-else class="effort-content">
                                <div class="effort-summary">
                                    <div class="effort-total">
                                        <span class="effort-hours">{{ effortEstimation.total_estimated_hours }}h</span>
                                        <span class="effort-label">estimated from commits</span>
                                    </div>
                                    <div class="effort-since">Since {{ effortEstimation.since_date }}</div>
                                </div>
                                <div class="effort-breakdown">
                                    <div
                                        v-for="(hours, type) in effortEstimation.breakdown"
                                        :key="type"
                                        v-show="hours > 0"
                                        class="breakdown-item"
                                    >
                                        <span class="breakdown-type">{{ type }}</span>
                                        <span class="breakdown-hours">{{ hours }}h</span>
                                    </div>
                                </div>
                                <button
                                    v-if="!effortAdded"
                                    type="button"
                                    @click="addEffortAsLineItems"
                                    class="btn btn-sm btn-primary effort-add-btn"
                                >
                                    Add as Line Items
                                </button>
                                <div v-else class="effort-added-notice">
                                    Added to line items below
                                </div>
                            </div>
                        </div>

                        <!-- Manual Lines -->
                        <div class="card">
                            <div class="card-header">
                                <h3 class="card-title">Additional Line Items</h3>
                                <button type="button" @click="addLine" class="btn btn-sm btn-secondary">
                                    + Add Line
                                </button>
                            </div>

                            <div v-if="form.lines.length === 0" class="empty-state">
                                Add fixed fees, expenses, or discounts
                            </div>
                            <div v-else class="manual-lines">
                                <!-- Column Headers -->
                                <div class="line-headers">
                                    <span class="line-header line-type-header">Type</span>
                                    <span class="line-header line-desc-header">Description</span>
                                    <span class="line-header line-qty-header">Qty</span>
                                    <span class="line-header line-price-header">Price</span>
                                    <span class="line-header line-total-header">Total</span>
                                    <span class="line-header line-action-header"></span>
                                </div>
                                <div
                                    v-for="(line, index) in form.lines"
                                    :key="index"
                                    class="manual-line"
                                >
                                    <div class="line-row">
                                        <FormSelect
                                            v-model="line.type"
                                            :options="lineTypeOptions"
                                            class="line-type"
                                        />
                                        <input
                                            type="text"
                                            v-model="line.description"
                                            class="form-input line-desc"
                                            placeholder="Description"
                                        />
                                        <input
                                            type="number"
                                            v-model.number="line.quantity"
                                            class="form-input line-qty"
                                            min="0"
                                            step="0.01"
                                        />
                                        <input
                                            type="text"
                                            :value="formatPriceInput(line.unit_price)"
                                            @focus="($event.target as HTMLInputElement).value = line.unit_price?.toString() || ''"
                                            @blur="line.unit_price = parsePriceInput(($event.target as HTMLInputElement).value)"
                                            class="form-input line-price"
                                            inputmode="decimal"
                                        />
                                        <div class="line-total">
                                            {{ formatCurrency(line.quantity * line.unit_price) }}
                                        </div>
                                        <button
                                            type="button"
                                            @click="removeLine(index)"
                                            class="line-remove"
                                        >
                                            &times;
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </AppLayout>
</template>

<style scoped>
.create-invoice-page {
    max-width: 90rem;
    margin: 0 auto;
    padding: 2rem 1rem;
}

.page-header {
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

.form-grid {
    display: grid;
    grid-template-columns: 1fr;
    gap: 1.5rem;
}

@media (min-width: 1024px) {
    .form-grid {
        grid-template-columns: 340px 1fr;
    }
}

.config-column, .lines-column {
    display: flex;
    flex-direction: column;
    gap: 1.5rem;
}

.card {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 0.5rem;
    padding: 1.25rem;
}

.card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.75rem;
}

.card-title {
    font-size: 0.875rem;
    font-weight: 600;
    color: var(--color-text-primary);
    margin-bottom: 1rem;
}

.card-header .card-title {
    margin-bottom: 0;
}

.form-group {
    margin-bottom: 1rem;
}

.form-group:last-child {
    margin-bottom: 0;
}

.form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;
}

.form-label {
    display: block;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    margin-bottom: 0.375rem;
}

.form-input, .form-textarea {
    width: 100%;
    padding: 0.5rem 0.75rem;
    font-size: 0.875rem;
    border-radius: 0.375rem;
    border: 1px solid var(--color-border-default);
    background: var(--color-bg-primary);
    color: var(--color-text-primary);
}

.form-input:focus, .form-textarea:focus {
    outline: none;
    border-color: var(--color-accent);
}

.form-textarea {
    resize: vertical;
}

.totals-card {
    position: sticky;
    top: 1rem;
}

.totals-list {
    margin-bottom: 1rem;
}

.total-item {
    display: flex;
    justify-content: space-between;
    padding: 0.5rem 0;
    font-size: 0.875rem;
}

.total-item dt {
    color: var(--color-text-secondary);
}

.total-item dd {
    font-weight: 500;
    color: var(--color-text-primary);
}

.total-grand {
    border-top: 1px solid var(--color-border-default);
    margin-top: 0.5rem;
    padding-top: 0.75rem;
}

.total-grand dt, .total-grand dd {
    font-size: 1rem;
    font-weight: 600;
}

.total-grand dd {
    color: var(--color-accent);
}

.btn {
    padding: 0.5rem 1rem;
    border-radius: 0.375rem;
    font-weight: 500;
    font-size: 0.875rem;
    transition: all 0.15s;
    cursor: pointer;
}

.btn-primary {
    background: var(--color-accent);
    color: white;
    border: none;
}

.btn-primary:hover:not(:disabled) {
    opacity: 0.9;
}

.btn-primary:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.btn-secondary {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
    border: 1px solid var(--color-border-default);
}

.btn-sm {
    padding: 0.25rem 0.75rem;
    font-size: 0.75rem;
}

.btn-full {
    width: 100%;
}

.empty-state, .loading-state {
    padding: 2rem;
    text-align: center;
    color: var(--color-text-tertiary);
    font-size: 0.875rem;
}

.group-select {
    width: 160px;
}

.select-all {
    padding: 0.75rem;
    border-bottom: 1px solid var(--color-border-subtle);
    background: var(--color-bg-tertiary);
}

.checkbox-label {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    font-size: 0.875rem;
    cursor: pointer;
}

.checkbox {
    width: 1rem;
    height: 1rem;
    accent-color: var(--color-accent);
}

.entries-list {
    max-height: 400px;
    overflow-y: auto;
}

.entry-row {
    display: flex;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 0.75rem;
    border-bottom: 1px solid var(--color-border-subtle);
    cursor: pointer;
    transition: background 0.1s;
}

.entry-row:hover {
    background: var(--color-bg-tertiary);
}

.entry-row.selected {
    background: rgba(99, 102, 241, 0.1);
}

.entry-info {
    flex: 1;
    min-width: 0;
}

.entry-main {
    display: flex;
    gap: 0.5rem;
    font-size: 0.875rem;
}

.entry-date {
    color: var(--color-text-primary);
    font-weight: 500;
}

.entry-project {
    color: var(--color-text-tertiary);
}

.entry-notes {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    margin-top: 0.25rem;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.entry-numbers {
    text-align: right;
    flex-shrink: 0;
}

.entry-hours {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.entry-amount {
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-primary);
}

.manual-lines {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;
}

.line-headers {
    display: flex;
    gap: 0.5rem;
    padding: 0 0.5rem 0.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
    margin-bottom: 0.25rem;
}

.line-header {
    font-size: 0.6875rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    color: var(--color-text-tertiary);
}

.line-type-header { width: 140px; flex-shrink: 0; }
.line-desc-header { flex: 1; }
.line-qty-header { width: 90px; flex-shrink: 0; text-align: right; padding-right: 0.5rem; }
.line-price-header { width: 100px; flex-shrink: 0; text-align: right; padding-right: 0.5rem; }
.line-total-header { width: 100px; flex-shrink: 0; text-align: right; }
.line-action-header { width: 28px; flex-shrink: 0; }

.manual-line {
    padding: 0.5rem;
    background: var(--color-bg-tertiary);
    border-radius: 0.375rem;
}

.line-row {
    display: flex;
    gap: 0.5rem;
    align-items: center;
}

.line-type {
    width: 140px;
    flex-shrink: 0;
    margin-bottom: 0;
}

.line-type :deep(.select-trigger) {
    height: 38px;
    padding-top: 0;
    padding-bottom: 0;
}

.line-desc {
    flex: 1;
    min-width: 0;
    height: 38px;
    padding: 0 0.75rem;
}

.line-qty {
    width: 90px;
    flex-shrink: 0;
    text-align: right;
    padding: 0 0.5rem;
    height: 38px;
}

.line-price {
    width: 100px;
    height: 38px;
    flex-shrink: 0;
    text-align: right;
    padding: 0 0.5rem;
}

.line-total {
    width: 100px;
    text-align: right;
    font-weight: 600;
    font-size: 0.875rem;
    flex-shrink: 0;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.line-remove {
    width: 28px;
    height: 28px;
    border-radius: 0.25rem;
    border: none;
    background: transparent;
    color: var(--color-text-tertiary);
    font-size: 1.25rem;
    cursor: pointer;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
}

.line-remove:hover {
    background: rgba(239, 68, 68, 0.1);
    color: var(--color-status-red);
}

.effort-content {
    display: flex;
    flex-direction: column;
    gap: 1rem;
}

.effort-summary {
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.effort-total {
    display: flex;
    align-items: baseline;
    gap: 0.5rem;
}

.effort-hours {
    font-size: 1.5rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.effort-label {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.effort-since {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

.effort-breakdown {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
}

.breakdown-item {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.375rem 0.75rem;
    background: var(--color-bg-tertiary);
    border-radius: 0.25rem;
    font-size: 0.75rem;
}

.breakdown-type {
    color: var(--color-text-secondary);
    text-transform: capitalize;
}

.breakdown-hours {
    font-weight: 600;
    color: var(--color-text-primary);
}

.effort-add-btn {
    align-self: flex-start;
}

.effort-added-notice {
    font-size: 0.75rem;
    color: var(--color-status-green);
    font-weight: 500;
}

.loading-state {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
}

.loading-spinner {
    display: inline-block;
    width: 14px;
    height: 14px;
    border: 2px solid var(--color-border-default);
    border-radius: 50%;
    border-top-color: var(--color-accent);
    animation: spin 0.8s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>
