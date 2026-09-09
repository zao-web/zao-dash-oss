<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';

interface Client {
    id: number;
    name: string;
}

interface Invoice {
    id: number;
    number: string;
    subject: string | null;
    status: 'draft' | 'sent' | 'viewed' | 'partial' | 'paid' | 'overdue' | 'cancelled';
    is_recurring: boolean;
    subtotal: string;
    total: string;
    amount_paid: string;
    amount_due: string;
    issue_date: string;
    due_date: string;
    sent_at: string | null;
    paid_at: string | null;
    days_to_pay: number | null;
    days_overdue: number;
    client: Client;
}

interface RecurringClient {
    client_id: number;
    client_name: string;
    amount: number;
    next_date: string;
    payment_terms: string;
    auto_send: boolean;
    generated_this_month: boolean;
}

interface PaginatedInvoices {
    data: Invoice[];
    links: { url: string | null; label: string; active: boolean }[];
    current_page: number;
    last_page: number;
    total: number;
}

interface Summary {
    total_open: number;
    total_open_count: number;
    overdue_amount: number;
    overdue_count: number;
    sent_amount: number;
    sent_count: number;
    draft_amount: number;
    draft_count: number;
    total_paid_year: number;
}

interface ChartMonth {
    month: string;
    open: number;
    paid: number;
}

const props = defineProps<{
    invoices: PaginatedInvoices;
    summary: Summary;
    chartData: ChartMonth[];
    tableTotal: number;
    year: number;
    years: number[];
    view: 'open' | 'all';
    recurringClients: RecurringClient[];
}>();

const totalRecurringMonthly = props.recurringClients.reduce((sum, c) => sum + c.amount, 0);

const currentView = ref(props.view);

const setView = (view: 'open' | 'all') => {
    currentView.value = view;
    router.get('/invoices', { view, year: props.year }, { preserveState: true });
};

const changeYear = (delta: number) => {
    const newYear = props.year + delta;
    if (props.years.includes(newYear) || newYear === new Date().getFullYear()) {
        router.get('/invoices', { view: currentView.value, year: newYear }, { preserveState: true });
    }
};

const formatCurrency = (amount: number | string) => {
    const num = typeof amount === 'string' ? parseFloat(amount) : amount;
    return new Intl.NumberFormat('en-US', { style: 'currency', currency: 'USD' }).format(num);
};

const formatDate = (date: string) => {
    return new Date(date).toLocaleDateString('en-US', { month: '2-digit', day: '2-digit', year: 'numeric' });
};

// Calculate max value for chart scaling
const chartMax = computed(() => {
    const maxOpen = Math.max(...props.chartData.map(d => d.open));
    const maxPaid = Math.max(...props.chartData.map(d => d.paid));
    return Math.max(maxOpen, maxPaid, 100); // minimum scale of $100
});

// Calculate bar height percentage
const barHeight = (value: number) => {
    return Math.max((value / chartMax.value) * 100, 0);
};

// Get human-readable due status
const getDueStatus = (invoice: Invoice): { text: string; class: string } => {
    if (invoice.status === 'draft') {
        return { text: 'Not sent yet', class: 'due-draft' };
    }
    if (invoice.status === 'paid') {
        return { text: '', class: '' };
    }
    if (invoice.days_overdue > 0) {
        if (invoice.days_overdue === 1) {
            return { text: 'Due yesterday', class: 'due-late' };
        }
        return { text: `Due ${invoice.days_overdue} days ago`, class: 'due-late' };
    }
    // Calculate days until due
    const dueDate = new Date(invoice.due_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    dueDate.setHours(0, 0, 0, 0);
    const daysUntil = Math.ceil((dueDate.getTime() - today.getTime()) / (1000 * 60 * 60 * 24));

    if (daysUntil === 0) {
        return { text: 'Due today', class: 'due-today' };
    }
    if (daysUntil === 1) {
        return { text: 'Due tomorrow', class: 'due-soon' };
    }
    return { text: `Due in ${daysUntil} days`, class: 'due-normal' };
};

// Status badge styling
const deleteDraft = (invoice: Invoice, event: Event) => {
    event.stopPropagation();
    if (confirm(`Delete draft invoice ${invoice.number}?`)) {
        router.delete(`/invoices/${invoice.id}`, { preserveScroll: true });
    }
};

const statusBadge = (status: string): { label: string; class: string } => {
    const map: Record<string, { label: string; class: string }> = {
        draft: { label: 'Draft', class: 'badge-draft' },
        sent: { label: 'Sent', class: 'badge-sent' },
        viewed: { label: 'Viewed', class: 'badge-viewed' },
        partial: { label: 'Partial', class: 'badge-partial' },
        paid: { label: 'Paid', class: 'badge-paid' },
        overdue: { label: 'Late', class: 'badge-late' },
        cancelled: { label: 'Cancelled', class: 'badge-cancelled' },
    };
    return map[status] || { label: status, class: 'badge-default' };
};
</script>

<template>
    <AppLayout title="Invoices">
        <div class="invoices-page">
            <!-- Header -->
            <div class="page-header">
                <Link href="/invoices/create" class="btn-new-invoice">
                    + New invoice
                </Link>
                <Link href="/invoices/settings" class="btn btn-secondary settings-link">
                    Reminder Settings
                </Link>
                <div class="search-box">
                    <svg class="search-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                    <input type="text" placeholder="Search by invoice ID" class="search-input" />
                </div>
            </div>

            <!-- Summary + Chart Row -->
            <div class="summary-chart-row">
                <!-- Left: Summary Cards -->
                <div class="summary-section">
                    <div class="summary-card">
                        <div class="summary-label">Total open</div>
                        <div class="summary-amount">{{ formatCurrency(summary.total_open) }}</div>
                    </div>
                    <div class="summary-card muted">
                        <div class="summary-label">Total paid amount</div>
                        <div class="summary-amount">{{ formatCurrency(summary.total_paid_year) }}</div>
                        <div class="summary-note">For invoices issued in {{ year }}, excluding retainer deposits.</div>
                    </div>
                </div>

                <!-- Right: Chart -->
                <div class="chart-section">
                    <div class="chart-header">
                        <button
                            class="year-nav"
                            @click="changeYear(-1)"
                            :disabled="!years.includes(year - 1)"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M15 19l-7-7 7-7" />
                            </svg>
                        </button>
                        <button
                            class="year-nav"
                            @click="changeYear(1)"
                            :disabled="year >= new Date().getFullYear()"
                        >
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M9 5l7 7-7 7" />
                            </svg>
                        </button>
                        <span class="chart-title">Invoices issued in {{ year }}</span>
                        <div class="chart-legend">
                            <span class="legend-item"><span class="legend-dot open"></span> Open</span>
                            <span class="legend-item"><span class="legend-dot paid"></span> Paid</span>
                        </div>
                    </div>
                    <div class="chart-container">
                        <div class="chart-y-axis">
                            <span>{{ formatCurrency(chartMax) }}</span>
                            <span>{{ formatCurrency(chartMax * 0.75) }}</span>
                            <span>{{ formatCurrency(chartMax * 0.5) }}</span>
                            <span>{{ formatCurrency(chartMax * 0.25) }}</span>
                            <span>$0</span>
                        </div>
                        <div class="chart-bars">
                            <div v-for="month in chartData" :key="month.month" class="chart-bar-group">
                                <div class="bar-wrapper">
                                    <div
                                        class="bar bar-open"
                                        :style="{ height: barHeight(month.open) + '%' }"
                                        :title="`Open: ${formatCurrency(month.open)}`"
                                    ></div>
                                    <div
                                        class="bar bar-paid"
                                        :style="{ height: barHeight(month.paid) + '%' }"
                                        :title="`Paid: ${formatCurrency(month.paid)}`"
                                    ></div>
                                </div>
                                <span class="bar-label">{{ month.month }}</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upcoming Recurring Invoices -->
            <div v-if="recurringClients.length > 0" class="recurring-section">
                <div class="recurring-header">
                    <div class="recurring-title-row">
                        <svg class="recurring-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" />
                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
                        </svg>
                        <span class="recurring-title">Recurring Invoices</span>
                        <span class="recurring-total">{{ formatCurrency(totalRecurringMonthly) }}/mo</span>
                    </div>
                </div>
                <div class="recurring-list">
                    <div v-for="rc in recurringClients" :key="rc.client_id" class="recurring-row">
                        <div class="recurring-client">
                            <Link :href="`/clients/${rc.client_id}`" class="recurring-client-name">{{ rc.client_name }}</Link>
                            <span class="recurring-terms">{{ rc.payment_terms }}</span>
                        </div>
                        <div class="recurring-meta">
                            <span class="recurring-amount">{{ formatCurrency(rc.amount) }}</span>
                            <span class="recurring-next" :class="{ 'recurring-done': rc.generated_this_month }">
                                {{ rc.generated_this_month ? 'Generated' : `Next: ${rc.next_date}` }}
                            </span>
                            <span v-if="rc.auto_send" class="recurring-auto-badge">Auto-send</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button
                    :class="['tab', currentView === 'open' ? 'active' : '']"
                    @click="setView('open')"
                >
                    Open <span class="tab-count">{{ summary.total_open_count }}</span>
                </button>
                <button
                    :class="['tab', currentView === 'all' ? 'active' : '']"
                    @click="setView('all')"
                >
                    All invoices
                </button>
            </div>

            <!-- Invoice Table -->
            <div class="table-wrapper">
                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th class="col-status">Status</th>
                            <th class="col-due"></th>
                            <th class="col-date">Issue date</th>
                            <th class="col-id">ID</th>
                            <th class="col-client">Client</th>
                            <th class="col-balance text-right">Balance</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="invoice in invoices.data" :key="invoice.id" @click="router.visit(`/invoices/${invoice.id}`)" class="clickable-row">
                            <td class="col-status">
                                <div class="status-cell">
                                    <span :class="['badge', statusBadge(invoice.status).class]">
                                        {{ statusBadge(invoice.status).label }}
                                    </span>
                                    <span v-if="invoice.is_recurring" class="badge badge-recurring" title="Recurring invoice">
                                        <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="23 4 23 10 17 10" /><polyline points="1 20 1 14 7 14" />
                                            <path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15" />
                                        </svg>
                                    </span>
                                </div>
                            </td>
                            <td class="col-due">
                                <span :class="['due-text', getDueStatus(invoice).class]">
                                    {{ getDueStatus(invoice).text }}
                                </span>
                            </td>
                            <td class="col-date">{{ formatDate(invoice.issue_date) }}</td>
                            <td class="col-id">{{ invoice.number }}</td>
                            <td class="col-client">
                                <div class="client-info">
                                    <span class="client-name">{{ invoice.client?.name }}</span>
                                    <span v-if="invoice.subject" class="invoice-subject">{{ invoice.subject }}</span>
                                </div>
                            </td>
                            <td class="col-balance text-right">
                                <div class="balance-cell">
                                    <span class="balance-amount">{{ formatCurrency(invoice.amount_due) }}</span>
                                    <button
                                        v-if="invoice.status === 'draft'"
                                        class="delete-draft-btn"
                                        title="Delete draft"
                                        @click="deleteDraft(invoice, $event)"
                                    >
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                            <polyline points="3 6 5 6 21 6" /><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="invoices.data.length === 0">
                            <td colspan="6" class="empty-state">No invoices found</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="invoices.data.length > 0">
                        <tr>
                            <td colspan="5" class="total-label">Total</td>
                            <td class="col-balance text-right">
                                <span class="total-amount">{{ formatCurrency(tableTotal) }}</span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- Pagination -->
            <div v-if="invoices.last_page > 1" class="pagination">
                <template v-for="link in invoices.links" :key="link.label">
                    <component
                        :is="link.url ? Link : 'span'"
                        :href="link.url || undefined"
                        :class="['page-link', { active: link.active, disabled: !link.url }]"
                        v-html="link.label"
                    />
                </template>
            </div>
        </div>
    </AppLayout>
</template>

<style scoped>
.invoices-page {
    max-width: 1200px;
    margin: 0 auto;
    padding: 1.5rem;
}

/* Header */
.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 1.5rem;
}

.btn-new-invoice {
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    padding: 0.5rem 1rem;
    background: var(--color-accent);
    color: white;
    font-weight: 500;
    font-size: 0.875rem;
    border-radius: 6px;
    transition: opacity 0.15s;
}

.btn-new-invoice:hover {
    opacity: 0.9;
}

.search-box {
    position: relative;
}

.search-icon {
    position: absolute;
    left: 0.75rem;
    top: 50%;
    transform: translateY(-50%);
    width: 16px;
    height: 16px;
    color: var(--color-text-tertiary);
}

.search-input {
    padding: 0.5rem 0.75rem 0.5rem 2.25rem;
    width: 220px;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
    border-radius: 6px;
    font-size: 0.875rem;
    color: var(--color-text-primary);
}

.search-input::placeholder {
    color: var(--color-text-tertiary);
}

/* Summary + Chart Row */
.summary-chart-row {
    display: grid;
    grid-template-columns: 220px 1fr;
    gap: 1.5rem;
    margin-bottom: 1.5rem;
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 12px;
    padding: 1.25rem;
}

.summary-section {
    display: flex;
    flex-direction: column;
    gap: 1rem;
    border-right: 1px solid var(--color-border-subtle);
    padding-right: 1.5rem;
}

.summary-card {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
}

.summary-card.muted {
    opacity: 0.7;
}

.summary-label {
    font-size: 0.8125rem;
    color: var(--color-text-secondary);
}

.summary-amount {
    font-size: 1.75rem;
    font-weight: 700;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.summary-note {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
    line-height: 1.4;
    margin-top: 0.25rem;
}

/* Chart Section */
.chart-section {
    display: flex;
    flex-direction: column;
}

.chart-header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    margin-bottom: 1rem;
}

.year-nav {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--color-border-default);
    border-radius: 4px;
    background: var(--color-bg-primary);
    color: var(--color-text-secondary);
    cursor: pointer;
    transition: all 0.15s;
}

.year-nav:hover:not(:disabled) {
    background: var(--color-bg-tertiary);
    color: var(--color-text-primary);
}

.year-nav:disabled {
    opacity: 0.3;
    cursor: not-allowed;
}

.year-nav svg {
    width: 14px;
    height: 14px;
}

.chart-title {
    font-weight: 600;
    color: var(--color-text-primary);
    margin-left: 0.5rem;
}

.chart-legend {
    margin-left: auto;
    display: flex;
    gap: 1rem;
}

.legend-item {
    display: flex;
    align-items: center;
    gap: 0.375rem;
    font-size: 0.75rem;
    color: var(--color-text-secondary);
}

.legend-dot {
    width: 10px;
    height: 10px;
    border-radius: 2px;
}

.legend-dot.open {
    background: rgba(156, 163, 175, 0.5);
}

.legend-dot.paid {
    background: var(--color-status-green);
}

.chart-container {
    display: flex;
    flex: 1;
    min-height: 140px;
}

.chart-y-axis {
    display: flex;
    flex-direction: column;
    justify-content: space-between;
    font-size: 0.625rem;
    color: var(--color-text-tertiary);
    padding-right: 0.5rem;
    text-align: right;
    min-width: 50px;
}

.chart-bars {
    flex: 1;
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    border-bottom: 1px solid var(--color-border-subtle);
    padding-bottom: 1.5rem;
}

.chart-bar-group {
    display: flex;
    flex-direction: column;
    align-items: center;
    flex: 1;
}

.bar-wrapper {
    display: flex;
    gap: 2px;
    align-items: flex-end;
    height: 100px;
}

.bar {
    width: 14px;
    border-radius: 2px 2px 0 0;
    transition: height 0.3s ease;
    min-height: 2px;
}

.bar-open {
    background: rgba(156, 163, 175, 0.4);
}

.bar-paid {
    background: var(--color-status-green);
}

.bar-label {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    margin-top: 0.5rem;
}

/* Recurring Section */
.recurring-section {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 10px;
    margin-bottom: 1.5rem;
    overflow: hidden;
}

.recurring-header {
    padding: 0.75rem 1rem;
    border-bottom: 1px solid var(--color-border-subtle);
    background: var(--color-bg-tertiary);
}

.recurring-title-row {
    display: flex;
    align-items: center;
    gap: 0.5rem;
}

.recurring-icon {
    color: var(--color-text-tertiary);
    flex-shrink: 0;
}

.recurring-title {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
}

.recurring-total {
    margin-left: auto;
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-secondary);
    font-variant-numeric: tabular-nums;
}

.recurring-list {
    padding: 0;
}

.recurring-row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.625rem 1rem;
    border-bottom: 1px solid var(--color-border-subtle);
    gap: 1rem;
}

.recurring-row:last-child {
    border-bottom: none;
}

.recurring-client {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-width: 0;
}

.recurring-client-name {
    font-size: 0.8125rem;
    font-weight: 500;
    color: var(--color-text-primary);
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}

.recurring-client-name:hover {
    color: var(--color-accent);
}

.recurring-terms {
    font-size: 0.6875rem;
    color: var(--color-text-tertiary);
    white-space: nowrap;
}

.recurring-meta {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    flex-shrink: 0;
}

.recurring-amount {
    font-size: 0.8125rem;
    font-weight: 600;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
    min-width: 70px;
    text-align: right;
}

.recurring-next {
    font-size: 0.75rem;
    color: var(--color-text-secondary);
    min-width: 100px;
}

.recurring-done {
    color: var(--color-status-green);
    font-weight: 500;
}

.recurring-auto-badge {
    font-size: 0.625rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    padding: 0.125rem 0.375rem;
    border-radius: 3px;
    background: rgba(139, 92, 246, 0.1);
    color: var(--color-accent);
    white-space: nowrap;
}

/* Status Cell */
.status-cell {
    display: flex;
    align-items: center;
    gap: 0.375rem;
}

.badge-recurring {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    padding: 0.125rem;
    background: rgba(139, 92, 246, 0.1);
    color: var(--color-accent);
    border-radius: 3px;
}

/* Tabs */
.tabs {
    display: flex;
    gap: 0.25rem;
    margin-bottom: 0.5rem;
    border-bottom: 1px solid var(--color-border-subtle);
}

.tab {
    padding: 0.75rem 1rem;
    font-size: 0.875rem;
    font-weight: 500;
    color: var(--color-text-secondary);
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -1px;
    cursor: pointer;
    transition: all 0.15s;
}

.tab:hover {
    color: var(--color-text-primary);
}

.tab.active {
    color: var(--color-text-primary);
    border-bottom-color: var(--color-text-primary);
}

.tab-count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 20px;
    height: 18px;
    padding: 0 6px;
    background: var(--color-bg-tertiary);
    border-radius: 9px;
    font-size: 0.75rem;
    margin-left: 0.375rem;
}

.tab.active .tab-count {
    background: var(--color-text-primary);
    color: var(--color-bg-primary);
}

/* Table */
.table-wrapper {
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-subtle);
    border-radius: 8px;
    overflow: hidden;
}

.invoice-table {
    width: 100%;
    border-collapse: collapse;
}

.invoice-table th {
    padding: 0.75rem 1rem;
    text-align: left;
    font-size: 0.75rem;
    font-weight: 500;
    color: var(--color-text-tertiary);
    background: var(--color-bg-tertiary);
    border-bottom: 1px solid var(--color-border-subtle);
}

.invoice-table td {
    padding: 0.875rem 1rem;
    border-bottom: 1px solid var(--color-border-subtle);
    vertical-align: middle;
}

.clickable-row {
    cursor: pointer;
    transition: background 0.1s;
}

.clickable-row:hover {
    background: var(--color-bg-tertiary);
}

.col-status { width: 80px; }
.col-due { width: 140px; }
.col-date { width: 110px; }
.col-id { width: 70px; }
.col-client { }
.col-balance { width: 120px; }

/* Status Badges */
.badge {
    display: inline-flex;
    align-items: center;
    padding: 0.25rem 0.625rem;
    font-size: 0.75rem;
    font-weight: 500;
    border-radius: 4px;
}

.badge-draft {
    background: var(--color-bg-tertiary);
    color: var(--color-text-secondary);
}

.badge-sent {
    background: rgba(34, 197, 94, 0.12);
    color: var(--color-status-green);
}

.badge-viewed {
    background: rgba(139, 92, 246, 0.12);
    color: #a855f7;
}

.badge-partial {
    background: rgba(245, 158, 11, 0.12);
    color: #f59e0b;
}

.badge-paid {
    background: rgba(34, 197, 94, 0.12);
    color: var(--color-status-green);
}

.badge-late {
    background: rgba(239, 68, 68, 0.12);
    color: var(--color-status-red);
}

.badge-cancelled {
    background: rgba(107, 114, 128, 0.12);
    color: #6b7280;
}

/* Due Status */
.due-text {
    font-size: 0.8125rem;
}

.due-draft {
    color: var(--color-text-tertiary);
}

.due-late {
    color: var(--color-status-red);
}

.due-today {
    color: var(--color-status-yellow);
}

.due-soon {
    color: var(--color-text-secondary);
}

.due-normal {
    color: var(--color-text-secondary);
}

/* Client Info */
.client-info {
    display: flex;
    flex-direction: column;
    gap: 0.125rem;
}

.client-name {
    font-weight: 500;
    color: var(--color-text-primary);
}

.invoice-subject {
    font-size: 0.75rem;
    color: var(--color-text-tertiary);
}

/* Balance */
.balance-cell {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 0.5rem;
}

.delete-draft-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 6px;
    border: none;
    background: none;
    color: var(--color-text-quaternary);
    cursor: pointer;
    opacity: 0;
    transition: all 0.15s;
}

.clickable-row:hover .delete-draft-btn {
    opacity: 1;
}

.delete-draft-btn:hover {
    color: var(--color-status-red);
    background: rgba(239, 68, 68, 0.1);
}

.balance-amount {
    font-weight: 600;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

/* Table Footer */
.invoice-table tfoot td {
    background: var(--color-bg-tertiary);
    border-top: 1px solid var(--color-border-default);
    border-bottom: none;
}

.total-label {
    font-weight: 600;
    color: var(--color-text-primary);
    text-align: right;
    padding-right: 1rem;
}

.total-amount {
    font-weight: 700;
    font-size: 1rem;
    color: var(--color-text-primary);
    font-variant-numeric: tabular-nums;
}

.text-right {
    text-align: right;
}

.empty-state {
    padding: 3rem 1.5rem;
    text-align: center;
    color: var(--color-text-secondary);
}

/* Pagination */
.pagination {
    display: flex;
    justify-content: center;
    gap: 0.25rem;
    margin-top: 1.5rem;
}

.page-link {
    padding: 0.5rem 0.75rem;
    border-radius: 0.375rem;
    font-size: 0.875rem;
    color: var(--color-text-secondary);
    background: var(--color-bg-secondary);
    border: 1px solid var(--color-border-default);
}

.page-link:hover:not(.disabled) {
    background: var(--color-bg-tertiary);
}

.page-link.active {
    background: var(--color-accent);
    color: white;
    border-color: var(--color-accent);
}

.page-link.disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Responsive */
@media (max-width: 900px) {
    .summary-chart-row {
        grid-template-columns: 1fr;
    }

    .summary-section {
        flex-direction: row;
        border-right: none;
        border-bottom: 1px solid var(--color-border-subtle);
        padding-right: 0;
        padding-bottom: 1rem;
    }

    .summary-card {
        flex: 1;
    }
}

@media (max-width: 640px) {
    .page-header {
        flex-direction: column;
        gap: 1rem;
        align-items: stretch;
    }

    .search-input {
        width: 100%;
    }

    .col-date, .col-id {
        display: none;
    }
}
</style>
